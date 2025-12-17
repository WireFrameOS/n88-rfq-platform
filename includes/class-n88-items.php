<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Items Endpoints
 * 
 * Milestone 1.1: Item creation and update endpoints.
 */
class N88_Items {

    /**
     * Allowed item statuses
     * 
     * @var array
     */
    private static $allowed_item_statuses = array(
        'draft',
        'active',
        'archived',
    );

    /**
     * Allowed item types
     * 
     * @var array
     */
    private static $allowed_item_types = array(
        'furniture',
        'lighting',
        'accessory',
        'art',
        'other',
    );

    /**
     * Allowed fields for item updates (strict whitelist)
     * 
     * @var array Field name => array('sanitizer' => callback, 'max_length' => int)
     */
    private static $allowed_update_fields = array(
        'title' => array(
            'sanitizer' => 'sanitize_text_field',
            'max_length' => 500,
        ),
        'description' => array(
            'sanitizer' => 'sanitize_textarea_field',
            'max_length' => null, // TEXT field, no limit
        ),
        'status' => array(
            'sanitizer' => 'sanitize_text_field',
            'max_length' => 50,
            'whitelist' => null, // Uses $allowed_item_statuses
        ),
        'item_type' => array(
            'sanitizer' => 'sanitize_text_field',
            'max_length' => 100,
            'whitelist' => null, // Uses $allowed_item_types
        ),
        // Phase 1.2: Intelligence fields
        'sourcing_type' => array(
            'sanitizer' => 'sanitize_text_field',
            'max_length' => 50,
            'whitelist' => null, // Validated via N88_Intelligence
        ),
        'dimension_width' => array(
            'sanitizer' => 'floatval',
            'max_length' => null,
        ),
        'dimension_depth' => array(
            'sanitizer' => 'floatval',
            'max_length' => null,
        ),
        'dimension_height' => array(
            'sanitizer' => 'floatval',
            'max_length' => null,
        ),
        'dimension_units_original' => array(
            'sanitizer' => 'sanitize_text_field',
            'max_length' => 20,
            'whitelist' => null, // Validated via N88_Intelligence
        ),
    );

    public function __construct() {
        // Register AJAX endpoints (logged-in users only)
        add_action( 'wp_ajax_n88_create_item', array( $this, 'ajax_create_item' ) );
        add_action( 'wp_ajax_n88_update_item', array( $this, 'ajax_update_item' ) );
    }

    /**
     * AJAX: Create Item
     * 
     * Creates a new item owned by the current user.
     */
    public function ajax_create_item() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to create items.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Sanitize and validate inputs
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
        $item_type = isset( $_POST['item_type'] ) ? sanitize_text_field( wp_unslash( $_POST['item_type'] ) ) : 'furniture';
        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'draft';

        // Validate required fields
        if ( empty( $title ) ) {
            wp_send_json_error( array( 'message' => 'Title is required.' ), 400 );
        }

        // Validate title length (max 500 chars per schema)
        if ( strlen( $title ) > 500 ) {
            wp_send_json_error( array( 'message' => 'Title exceeds maximum length of 500 characters.' ), 400 );
        }

        // Validate item_type against whitelist
        if ( ! in_array( $item_type, self::$allowed_item_types, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid item type.' ), 400 );
        }

        // Validate status against whitelist
        if ( ! in_array( $status, self::$allowed_item_statuses, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid status.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_items';
        $now = current_time( 'mysql' );

        // Insert item
        $inserted = $wpdb->insert(
            $table,
            array(
                'owner_user_id' => $user_id,
                'title'         => $title,
                'description'  => $description,
                'item_type'    => $item_type,
                'status'       => $status,
                'version'      => 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Failed to create item.' ), 500 );
        }

        $item_id = $wpdb->insert_id;

        // Log event
        n88_log_event(
            'item_created',
            'item',
            array(
                'object_id' => $item_id,
                'item_id'   => $item_id,
                'payload_json' => array(
                    'title'      => $title,
                    'item_type'  => $item_type,
                    'status'     => $status,
                ),
            )
        );

        // Ensure designer profile exists (lazy creation)
        $this->ensure_designer_profile( $user_id );

        wp_send_json_success( array(
            'item_id' => $item_id,
            'message' => 'Item created successfully.',
        ) );
    }

    /**
     * AJAX: Update Item (core fields + intelligence)
     * 
     * Phase 1.2: Updates core fields and applies intelligence (normalization, CBM, timeline derivation).
     * Unknown payload fields are rejected with 400.
     */
    public function ajax_update_item() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to update items.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Sanitize and validate item_id
        $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
        if ( $item_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid item ID.' ), 400 );
        }

        // Ownership validation (owner OR admin)
        $item = N88_Authorization::get_item_for_user( $item_id, $user_id );
        if ( ! $item ) {
            wp_send_json_error( array( 'message' => 'Item not found or access denied.' ), 403 );
        }

        // STRICT ALLOWED-FIELDS WHITELIST: Reject any unknown fields
        $allowed_field_names = array_keys( self::$allowed_update_fields );
        $incoming_fields = array_keys( $_POST );
        
        // Remove system fields (item_id, nonce) from check
        $system_fields = array( 'item_id', 'nonce', 'action' );
        $incoming_fields = array_diff( $incoming_fields, $system_fields );
        
        // Check for unknown fields
        $unknown_fields = array_diff( $incoming_fields, $allowed_field_names );
        if ( ! empty( $unknown_fields ) ) {
            wp_send_json_error( array(
                'message' => 'Unknown fields not allowed: ' . implode( ', ', $unknown_fields ),
                'unknown_fields' => array_values( $unknown_fields ),
            ), 400 );
        }

        // Get current values for edit history and intelligence calculations
        $old_title = $item->title;
        $old_description = $item->description;
        $old_status = $item->status;
        $old_item_type = $item->item_type;
        $old_sourcing_type = isset( $item->sourcing_type ) ? $item->sourcing_type : null;
        $old_dimension_width_cm = isset( $item->dimension_width_cm ) ? $item->dimension_width_cm : null;
        $old_dimension_depth_cm = isset( $item->dimension_depth_cm ) ? $item->dimension_depth_cm : null;
        $old_dimension_height_cm = isset( $item->dimension_height_cm ) ? $item->dimension_height_cm : null;
        $old_dimension_width_original = isset( $item->dimension_width_original ) ? $item->dimension_width_original : null;
        $old_dimension_depth_original = isset( $item->dimension_depth_original ) ? $item->dimension_depth_original : null;
        $old_dimension_height_original = isset( $item->dimension_height_original ) ? $item->dimension_height_original : null;
        $old_dimension_units_original = isset( $item->dimension_units_original ) ? $item->dimension_units_original : null;
        $old_cbm = isset( $item->cbm ) ? $item->cbm : null;
        $old_timeline_type = isset( $item->timeline_type ) ? $item->timeline_type : null;

        // Build update array with strict validation
        $update_data = array();
        $update_format = array();
        $changed_fields = array();
        $intelligence_events = array();

        // Track dimension changes for recalculation
        $dimension_changed = false;
        $unit_changed = false;
        $sourcing_type_changed = false;

        // Process each allowed field
        foreach ( self::$allowed_update_fields as $field_name => $field_config ) {
            if ( ! isset( $_POST[ $field_name ] ) ) {
                continue; // Field not provided, skip
            }

            $raw_value = wp_unslash( $_POST[ $field_name ] );
            
            // Apply sanitizer
            $sanitizer = $field_config['sanitizer'];
            if ( function_exists( $sanitizer ) ) {
                $sanitized_value = call_user_func( $sanitizer, $raw_value );
            } else {
                $sanitized_value = sanitize_text_field( $raw_value );
            }

            // Handle empty strings for nullable fields
            if ( $sanitized_value === '' && in_array( $field_name, array( 'sourcing_type', 'dimension_units_original' ), true ) ) {
                $sanitized_value = null;
            }

            // Validate max length
            if ( $field_config['max_length'] !== null && $sanitized_value !== null && strlen( $sanitized_value ) > $field_config['max_length'] ) {
                wp_send_json_error( array(
                    'message' => sprintf( 'Field "%s" exceeds maximum length of %d characters.', $field_name, $field_config['max_length'] ),
                ), 400 );
            }

            // Validate whitelist for status and item_type
            if ( 'status' === $field_name ) {
                if ( ! in_array( $sanitized_value, self::$allowed_item_statuses, true ) ) {
                    wp_send_json_error( array( 'message' => 'Invalid status value.' ), 400 );
                }
            } elseif ( 'item_type' === $field_name ) {
                if ( ! in_array( $sanitized_value, self::$allowed_item_types, true ) ) {
                    wp_send_json_error( array( 'message' => 'Invalid item type value.' ), 400 );
                }
            } elseif ( 'sourcing_type' === $field_name ) {
                // Validate sourcing_type via N88_Intelligence
                if ( $sanitized_value !== null && ! N88_Intelligence::is_valid_sourcing_type( $sanitized_value ) ) {
                    wp_send_json_error( array( 'message' => 'Invalid sourcing type. Allowed: furniture, global_sourcing' ), 400 );
                }
            } elseif ( 'dimension_units_original' === $field_name ) {
                // Validate unit via N88_Intelligence
                if ( $sanitized_value !== null && ! N88_Intelligence::is_valid_unit( $sanitized_value ) ) {
                    wp_send_json_error( array( 'message' => 'Invalid unit. Allowed: mm, cm, m, in' ), 400 );
                }
            }

            // Check if value changed
            $old_value = null;
            switch ( $field_name ) {
                case 'title':
                    $old_value = $old_title;
                    break;
                case 'description':
                    $old_value = $old_description;
                    break;
                case 'status':
                    $old_value = $old_status;
                    break;
                case 'item_type':
                    $old_value = $old_item_type;
                    break;
                case 'sourcing_type':
                    $old_value = $old_sourcing_type;
                    if ( $sanitized_value !== $old_value ) {
                        $sourcing_type_changed = true;
                    }
                    break;
                case 'dimension_width':
                case 'dimension_depth':
                case 'dimension_height':
                    $dimension_changed = true;
                    // Don't store raw dimension - will normalize below
                    continue 2; // Skip adding to update_data, will handle in normalization step
                case 'dimension_units_original':
                    $old_value = $old_dimension_units_original;
                    if ( $sanitized_value !== $old_value ) {
                        $unit_changed = true;
                        $dimension_changed = true; // Unit change triggers dimension recalculation
                    }
                    break;
            }

            if ( $sanitized_value !== $old_value ) {
                // Store user-provided fields (not calculated fields)
                if ( ! in_array( $field_name, array( 'dimension_width', 'dimension_depth', 'dimension_height' ), true ) ) {
                    $update_data[ $field_name ] = $sanitized_value;
                    $update_format[] = '%s';
                }
                $changed_fields[] = array(
                    'field' => $field_name,
                    'old_value' => $old_value,
                    'new_value' => $sanitized_value,
                );
            }
        }

        // Phase 1.2: Intelligence Processing
        // 1. Handle dimension normalization with validation
        
        // Check if any dimensions are being updated
        $has_dimension_input = isset( $_POST['dimension_width'] ) || isset( $_POST['dimension_depth'] ) || isset( $_POST['dimension_height'] );
        
        if ( $has_dimension_input ) {
            // Get raw dimension values
            $raw_width = isset( $_POST['dimension_width'] ) ? floatval( $_POST['dimension_width'] ) : null;
            $raw_depth = isset( $_POST['dimension_depth'] ) ? floatval( $_POST['dimension_depth'] ) : null;
            $raw_height = isset( $_POST['dimension_height'] ) ? floatval( $_POST['dimension_height'] ) : null;
            
            // Validate dimension ranges BEFORE normalization (reject with HTTP 400)
            $dimension_max_cm = 5000; // Maximum allowed dimension (50 meters)
            $dimension_min = 0.01; // Minimum allowed dimension (0.01 units)
            
            $dimension_errors = array();
            
            if ( $raw_width !== null ) {
                if ( $raw_width <= 0 ) {
                    $dimension_errors[] = 'dimension_width must be greater than 0';
                } elseif ( $raw_width > $dimension_max_cm ) {
                    $dimension_errors[] = sprintf( 'dimension_width exceeds maximum of %d cm', $dimension_max_cm );
                }
            }
            if ( $raw_depth !== null ) {
                if ( $raw_depth <= 0 ) {
                    $dimension_errors[] = 'dimension_depth must be greater than 0';
                } elseif ( $raw_depth > $dimension_max_cm ) {
                    $dimension_errors[] = sprintf( 'dimension_depth exceeds maximum of %d cm', $dimension_max_cm );
                }
            }
            if ( $raw_height !== null ) {
                if ( $raw_height <= 0 ) {
                    $dimension_errors[] = 'dimension_height must be greater than 0';
                } elseif ( $raw_height > $dimension_max_cm ) {
                    $dimension_errors[] = sprintf( 'dimension_height exceeds maximum of %d cm', $dimension_max_cm );
                }
            }
            
            // Reject invalid dimensions with HTTP 400 (no CBM or events written)
            if ( ! empty( $dimension_errors ) ) {
                wp_send_json_error( array(
                    'message' => 'Invalid dimensions: ' . implode( ', ', $dimension_errors ),
                    'errors' => $dimension_errors,
                ), 400 );
            }
            
            // Get unit (Option B: Default to 'cm' if missing, and store it)
            $new_dimension_units_original = isset( $_POST['dimension_units_original'] ) ? sanitize_text_field( wp_unslash( $_POST['dimension_units_original'] ) ) : null;
            if ( empty( $new_dimension_units_original ) ) {
                // Option B: Default to 'cm' when unit is missing
                $new_dimension_units_original = 'cm';
            }
            
            // Validate unit against whitelist (must be done before normalization)
            if ( ! N88_Intelligence::is_valid_unit( $new_dimension_units_original ) ) {
                wp_send_json_error( array(
                    'message' => 'Invalid unit. Allowed: mm, cm, m, in',
                ), 400 );
            }
            
            // Store original dimension values (raw user input)
            $new_dimension_width_original = $raw_width;
            $new_dimension_depth_original = $raw_depth;
            $new_dimension_height_original = $raw_height;
            
            // Initialize normalized values
            $new_dimension_width_cm = $old_dimension_width_cm;
            $new_dimension_depth_cm = $old_dimension_depth_cm;
            $new_dimension_height_cm = $old_dimension_height_cm;
            
            // Normalize dimensions to cm
            if ( $raw_width !== null ) {
                $normalized = N88_Intelligence::normalize_to_cm( $raw_width, $new_dimension_units_original );
                if ( $normalized !== null ) {
                    $new_dimension_width_cm = $normalized;
                    // Validate normalized value doesn't exceed max (after conversion)
                    if ( $new_dimension_width_cm > $dimension_max_cm ) {
                        wp_send_json_error( array(
                            'message' => sprintf( 'dimension_width exceeds maximum of %d cm after conversion', $dimension_max_cm ),
                        ), 400 );
                    }
                    if ( $new_dimension_width_cm !== $old_dimension_width_cm ) {
                        $dimension_changed = true;
                        $changed_fields[] = array(
                            'field' => 'dimension_width_cm',
                            'old_value' => $old_dimension_width_cm,
                            'new_value' => $new_dimension_width_cm,
                        );
                    }
                }
            }
            if ( $raw_depth !== null ) {
                $normalized = N88_Intelligence::normalize_to_cm( $raw_depth, $new_dimension_units_original );
                if ( $normalized !== null ) {
                    $new_dimension_depth_cm = $normalized;
                    if ( $new_dimension_depth_cm > $dimension_max_cm ) {
                        wp_send_json_error( array(
                            'message' => sprintf( 'dimension_depth exceeds maximum of %d cm after conversion', $dimension_max_cm ),
                        ), 400 );
                    }
                    if ( $new_dimension_depth_cm !== $old_dimension_depth_cm ) {
                        $dimension_changed = true;
                        $changed_fields[] = array(
                            'field' => 'dimension_depth_cm',
                            'old_value' => $old_dimension_depth_cm,
                            'new_value' => $new_dimension_depth_cm,
                        );
                    }
                }
            }
            if ( $raw_height !== null ) {
                $normalized = N88_Intelligence::normalize_to_cm( $raw_height, $new_dimension_units_original );
                if ( $normalized !== null ) {
                    $new_dimension_height_cm = $normalized;
                    if ( $new_dimension_height_cm > $dimension_max_cm ) {
                        wp_send_json_error( array(
                            'message' => sprintf( 'dimension_height exceeds maximum of %d cm after conversion', $dimension_max_cm ),
                        ), 400 );
                    }
                    if ( $new_dimension_height_cm !== $old_dimension_height_cm ) {
                        $dimension_changed = true;
                        $changed_fields[] = array(
                            'field' => 'dimension_height_cm',
                            'old_value' => $old_dimension_height_cm,
                            'new_value' => $new_dimension_height_cm,
                        );
                    }
                }
            }
            
            // Update normalized dimensions and original values in database
            if ( $dimension_changed || $raw_width !== null || $raw_depth !== null || $raw_height !== null ) {
                // Store normalized cm values
                $update_data['dimension_width_cm'] = $new_dimension_width_cm;
                $update_data['dimension_depth_cm'] = $new_dimension_depth_cm;
                $update_data['dimension_height_cm'] = $new_dimension_height_cm;
                // Store original raw values
                $update_data['dimension_width_original'] = $new_dimension_width_original;
                $update_data['dimension_depth_original'] = $new_dimension_depth_original;
                $update_data['dimension_height_original'] = $new_dimension_height_original;
                // Store unit (always set, defaults to 'cm' if missing)
                $update_data['dimension_units_original'] = $new_dimension_units_original;
                $update_format[] = '%f';
                $update_format[] = '%f';
                $update_format[] = '%f';
                $update_format[] = '%f';
                $update_format[] = '%f';
                $update_format[] = '%f';
                $update_format[] = '%s';
                
                // Log unit normalization event (with flag if unit was defaulted)
                $intelligence_events[] = 'item_unit_normalized';
            }
        } else {
            // No dimension input, keep existing values
            $new_dimension_width_cm = $old_dimension_width_cm;
            $new_dimension_depth_cm = $old_dimension_depth_cm;
            $new_dimension_height_cm = $old_dimension_height_cm;
            $new_dimension_width_original = $old_dimension_width_original;
            $new_dimension_depth_original = $old_dimension_depth_original;
            $new_dimension_height_original = $old_dimension_height_original;
            $new_dimension_units_original = $old_dimension_units_original;
        }

        // 2. Calculate CBM (recalculate if dimensions changed)
        $new_cbm = null;
        if ( $dimension_changed ) {
            $new_cbm = N88_Intelligence::calculate_cbm( $new_dimension_width_cm, $new_dimension_depth_cm, $new_dimension_height_cm );
            if ( $new_cbm !== $old_cbm ) {
                $update_data['cbm'] = $new_cbm;
                $update_format[] = '%f';
                $changed_fields[] = array(
                    'field' => 'cbm',
                    'old_value' => $old_cbm,
                    'new_value' => $new_cbm,
                );
                $intelligence_events[] = 'item_cbm_recalculated';
            }
        }

        // 3. Derive timeline_type from sourcing_type (recalculate if sourcing_type changed)
        $new_sourcing_type = isset( $update_data['sourcing_type'] ) ? $update_data['sourcing_type'] : $old_sourcing_type;
        $new_timeline_type = null;
        if ( $sourcing_type_changed || $new_sourcing_type !== null ) {
            $new_timeline_type = N88_Intelligence::derive_timeline_type( $new_sourcing_type );
            if ( $new_timeline_type !== $old_timeline_type ) {
                $update_data['timeline_type'] = $new_timeline_type;
                $update_format[] = '%s';
                $changed_fields[] = array(
                    'field' => 'timeline_type',
                    'old_value' => $old_timeline_type,
                    'new_value' => $new_timeline_type,
                );
                $intelligence_events[] = 'item_timeline_type_derived';
            }
        }

        // Log sourcing_type events
        if ( $sourcing_type_changed ) {
            if ( $old_sourcing_type === null ) {
                $intelligence_events[] = 'item_sourcing_type_set';
            } else {
                $intelligence_events[] = 'item_sourcing_type_changed';
            }
        }

        // Log dimension changed event
        if ( $dimension_changed ) {
            $intelligence_events[] = 'item_dimension_changed';
        }

        // If no changes, return success
        if ( empty( $update_data ) && empty( $changed_fields ) ) {
            wp_send_json_success( array(
                'message' => 'No changes to update.',
                'item_id' => $item_id,
            ) );
        }

        // Update version and timestamp
        $update_data['version'] = $item->version + 1;
        $update_data['updated_at'] = current_time( 'mysql' );
        $update_format[] = '%d';
        $update_format[] = '%s';

        global $wpdb;
        $table = $wpdb->prefix . 'n88_items';

        // Update item
        $updated = $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $item_id ),
            $update_format,
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to update item.' ), 500 );
        }

        // Log edit history for each changed field
        $edits_table = $wpdb->prefix . 'n88_item_edits';
        $user_role = current_user_can( 'manage_options' ) ? 'admin' : 'user';
        $now = current_time( 'mysql' );

        foreach ( $changed_fields as $change ) {
            $wpdb->insert(
                $edits_table,
                array(
                    'item_id'        => $item_id,
                    'field_name'     => $change['field'],
                    'old_value'      => $change['old_value'] !== null ? (string) $change['old_value'] : null,
                    'new_value'      => $change['new_value'] !== null ? (string) $change['new_value'] : null,
                    'editor_user_id' => $user_id,
                    'editor_role'    => $user_role,
                    'created_at'     => $now,
                ),
                array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
            );
        }

        // Log intelligence events
        foreach ( $intelligence_events as $event_type ) {
            n88_log_event(
                $event_type,
                'item',
                array(
                    'object_id' => $item_id,
                    'item_id'   => $item_id,
                    'payload_json' => array(
                        'sourcing_type' => $new_sourcing_type,
                        'timeline_type' => $new_timeline_type,
                        'dimension_width_cm' => $new_dimension_width_cm,
                        'dimension_depth_cm' => $new_dimension_depth_cm,
                        'dimension_height_cm' => $new_dimension_height_cm,
                        'dimension_width_original' => $new_dimension_width_original,
                        'dimension_depth_original' => $new_dimension_depth_original,
                        'dimension_height_original' => $new_dimension_height_original,
                        'dimension_units_original' => $new_dimension_units_original,
                        'cbm' => $new_cbm,
                    ),
                )
            );
        }

        // Log general item_field_changed event if non-intelligence fields changed
        $non_intelligence_fields = array();
        foreach ( $changed_fields as $change ) {
            if ( ! in_array( $change['field'], array( 'dimension_width_cm', 'dimension_depth_cm', 'dimension_height_cm', 'dimension_units_original', 'cbm', 'timeline_type' ), true ) ) {
                $non_intelligence_fields[] = $change['field'];
            }
        }
        if ( ! empty( $non_intelligence_fields ) ) {
            n88_log_event(
                'item_field_changed',
                'item',
                array(
                    'object_id' => $item_id,
                    'item_id'   => $item_id,
                    'payload_json' => array(
                        'changed_fields' => $non_intelligence_fields,
                        'version'        => $update_data['version'],
                    ),
                )
            );
        }

        wp_send_json_success( array(
            'item_id' => $item_id,
            'message' => 'Item updated successfully.',
            'changed_fields' => array_column( $changed_fields, 'field' ),
            'intelligence' => array(
                'cbm' => $new_cbm,
                'timeline_type' => $new_timeline_type,
            ),
        ) );
    }

    /**
     * Ensure designer profile exists (lazy creation).
     * 
     * @param int $user_id User ID
     */
    private function ensure_designer_profile( $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'n88_designer_profiles';

        // Check if profile exists
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d",
                $user_id
            )
        );

        if ( $existing ) {
            return;
        }

        // Create profile
        $user = get_userdata( $user_id );
        $display_name = $user ? $user->display_name : '';

        $wpdb->insert(
            $table,
            array(
                'user_id'     => $user_id,
                'display_name' => $display_name,
                'created_at'  => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s' )
        );

        // Log event
        n88_log_event(
            'designer_profile_created',
            'designer_profile',
            array(
                'object_id' => $wpdb->insert_id,
            )
        );
    }
}

