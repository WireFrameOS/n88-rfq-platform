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
        add_action( 'wp_ajax_n88_save_item_facts', array( $this, 'ajax_save_item_facts' ) );
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

        // Handle file upload if provided
        $image_id = 0;
        $image_url = '';
        if ( ! empty( $_FILES['image_file'] ) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK ) {
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            
            $upload = wp_handle_upload( $_FILES['image_file'], array( 'test_form' => false ) );
            if ( ! isset( $upload['error'] ) ) {
                $attachment = array(
                    'post_mime_type' => $upload['type'],
                    'post_title'     => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ),
                    'post_content'   => '',
                    'post_status'    => 'inherit'
                );
                $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
                $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
                wp_update_attachment_metadata( $attach_id, $attach_data );
                $image_id = $attach_id;
                $image_url = $upload['url'];
            }
        } else {
            // Fallback to POST data if no file upload
            $image_id = isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;
            $image_url = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
        }

        // Sanitize and validate inputs
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
        $item_type = isset( $_POST['item_type'] ) ? sanitize_text_field( wp_unslash( $_POST['item_type'] ) ) : 'furniture';
        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'active'; // Default to active
        $default_size = isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : 'D';
        
        // Validate size
        $allowed_sizes = array( 'S', 'D', 'L', 'XL' );
        if ( ! in_array( $default_size, $allowed_sizes, true ) ) {
            $default_size = 'D';
        }

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

        // Handle image: prefer image_id, fallback to image_url
        $primary_image_id = null;
        if ( $image_id > 0 ) {
            // Verify attachment exists
            $attachment = get_post( $image_id );
            if ( $attachment && $attachment->post_type === 'attachment' ) {
                $primary_image_id = $image_id;
            }
        } elseif ( ! empty( $image_url ) ) {
            // If URL provided but no ID, try to find attachment by URL
            $attachment_id = attachment_url_to_postid( $image_url );
            if ( $attachment_id > 0 ) {
                $primary_image_id = $attachment_id;
            }
        }
        
        // Prepare meta_json with default size (only if column exists)
        $meta_json = array(
            'default_size' => $default_size,
        );
        if ( ! empty( $image_url ) && ! $primary_image_id ) {
            // Store image URL in meta if we couldn't find attachment ID
            $meta_json['image_url'] = $image_url;
        }
        
        // Check if meta_json column exists
        $table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
        $columns = $wpdb->get_col( "DESCRIBE {$table_safe}" );
        $has_meta_json = in_array( 'meta_json', $columns, true );
        
        // Insert item
        $insert_data = array(
            'owner_user_id' => $user_id,
            'title'         => $title,
            'description'  => $description,
            'item_type'    => $item_type,
            'status'       => $status,
            'version'      => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        );
        $insert_format = array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
        
        // Add meta_json only if column exists
        if ( $has_meta_json ) {
            $meta_json_string = wp_json_encode( $meta_json );
            $insert_data['meta_json'] = $meta_json_string;
            $insert_format[] = '%s';
            error_log('Item creation - Saving meta_json: ' . $meta_json_string . ' (default_size: ' . $default_size . ')');
        } else {
            error_log('Item creation - WARNING: meta_json column does not exist. Size preference NOT saved. default_size was: ' . $default_size);
            error_log('Please run migration to add meta_json column or deactivate/reactivate plugin.');
        }
        
        if ( $primary_image_id ) {
            $insert_data['primary_image_id'] = $primary_image_id;
            $insert_format[] = '%d';
        }
        
        $inserted = $wpdb->insert(
            $table,
            $insert_data,
            $insert_format
        );

        if ( ! $inserted ) {
            $error_message = 'Failed to create item.';
            if ( $wpdb->last_error ) {
                $error_message .= ' Database error: ' . $wpdb->last_error;
            }
            wp_send_json_error( array( 'message' => $error_message ), 500 );
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

        // If board_id provided, add item to board
        $added_to_board = false;
        $board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
        if ( $board_id > 0 ) {
            // Verify board exists and user owns it
            $board = N88_Authorization::get_board_for_user( $board_id, $user_id );
            if ( $board ) {
                // Add item to board
                global $wpdb;
                $board_items_table = $wpdb->prefix . 'n88_board_items';
                $now = current_time( 'mysql' );
                
                // Check if already on board
                $existing = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id FROM {$board_items_table} WHERE board_id = %d AND item_id = %d AND removed_at IS NULL",
                        $board_id,
                        $item_id
                    )
                );
                
                if ( ! $existing ) {
                    $inserted = $wpdb->insert(
                        $board_items_table,
                        array(
                            'board_id'        => $board_id,
                            'item_id'         => $item_id,
                            'added_by_user_id' => $user_id,
                            'added_at'        => $now,
                        ),
                        array( '%d', '%d', '%d', '%s' )
                    );
                    
                    if ( $inserted ) {
                        $added_to_board = true;
                        // Log event
                        n88_log_event(
                            'item_added_to_board',
                            'board',
                            array(
                                'object_id' => $board_id,
                                'board_id'  => $board_id,
                                'item_id'   => $item_id,
                            )
                        );
                    }
                }
            }
        }

        wp_send_json_success( array(
            'item_id' => $item_id,
            'message' => 'Item created successfully.',
            'added_to_board' => $added_to_board,
            'board_id' => $added_to_board ? $board_id : null,
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
        $dimension_null_clear_fields = array(); // Fields to explicitly clear to NULL

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
        // Semantics:
        // - Field not in POST → do not change it
        // - Field present but empty ("") → explicit CLEAR (set to NULL)
        // - Field present with value → normalize and store
        $has_dimension_input = isset( $_POST['dimension_width'] ) || isset( $_POST['dimension_depth'] ) || isset( $_POST['dimension_height'] );
        
        // Track which dimensions are being explicitly cleared
        $width_is_clear = false;
        $depth_is_clear = false;
        $height_is_clear = false;
        
        if ( $has_dimension_input ) {
            // Get raw dimension values with explicit clear detection
            if ( isset( $_POST['dimension_width'] ) ) {
                $raw_width_str = wp_unslash( $_POST['dimension_width'] );
                if ( $raw_width_str === '' ) {
                    // Explicit clear: empty string means set to NULL
                    $raw_width = null;
                    $width_is_clear = true;
                } else {
                    $raw_width = floatval( $raw_width_str );
                }
            } else {
                $raw_width = null;
            }
            
            if ( isset( $_POST['dimension_depth'] ) ) {
                $raw_depth_str = wp_unslash( $_POST['dimension_depth'] );
                if ( $raw_depth_str === '' ) {
                    // Explicit clear: empty string means set to NULL
                    $raw_depth = null;
                    $depth_is_clear = true;
                } else {
                    $raw_depth = floatval( $raw_depth_str );
                }
            } else {
                $raw_depth = null;
            }
            
            if ( isset( $_POST['dimension_height'] ) ) {
                $raw_height_str = wp_unslash( $_POST['dimension_height'] );
                if ( $raw_height_str === '' ) {
                    // Explicit clear: empty string means set to NULL
                    $raw_height = null;
                    $height_is_clear = true;
                } else {
                    $raw_height = floatval( $raw_height_str );
                }
            } else {
                $raw_height = null;
            }
            
            // Validate dimension ranges BEFORE normalization (reject with HTTP 400)
            // Skip validation for explicitly cleared dimensions (they will be set to NULL)
            $dimension_max_cm = 5000; // Maximum allowed dimension (50 meters)
            $dimension_min = 0.01; // Minimum allowed dimension (0.01 units)
            
            $dimension_errors = array();
            
            if ( $raw_width !== null && ! $width_is_clear ) {
                if ( $raw_width <= 0 ) {
                    $dimension_errors[] = 'dimension_width must be greater than 0';
                } elseif ( $raw_width > $dimension_max_cm ) {
                    $dimension_errors[] = sprintf( 'dimension_width exceeds maximum of %d cm', $dimension_max_cm );
                }
            }
            if ( $raw_depth !== null && ! $depth_is_clear ) {
                if ( $raw_depth <= 0 ) {
                    $dimension_errors[] = 'dimension_depth must be greater than 0';
                } elseif ( $raw_depth > $dimension_max_cm ) {
                    $dimension_errors[] = sprintf( 'dimension_depth exceeds maximum of %d cm', $dimension_max_cm );
                }
            }
            if ( $raw_height !== null && ! $height_is_clear ) {
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
            
            // Handle dimension values: explicit clear vs. normalization
            // Initialize normalized values (preserve existing if not being updated)
            $new_dimension_width_cm = $old_dimension_width_cm;
            $new_dimension_depth_cm = $old_dimension_depth_cm;
            $new_dimension_height_cm = $old_dimension_height_cm;
            $new_dimension_width_original = $old_dimension_width_original;
            $new_dimension_depth_original = $old_dimension_depth_original;
            $new_dimension_height_original = $old_dimension_height_original;
            
            // Process width: explicit clear or normalize
            if ( isset( $_POST['dimension_width'] ) ) {
                if ( $width_is_clear ) {
                    // Explicit clear: set both original and normalized to NULL
                    $new_dimension_width_original = null;
                    $new_dimension_width_cm = null;
                    if ( $old_dimension_width_cm !== null ) {
                        $dimension_changed = true;
                        $changed_fields[] = array(
                            'field' => 'dimension_width_cm',
                            'old_value' => $old_dimension_width_cm,
                            'new_value' => null,
                        );
                        $changed_fields[] = array(
                            'field' => 'dimension_width_original',
                            'old_value' => $old_dimension_width_original,
                            'new_value' => null,
                        );
                    }
                } else {
                    // Normalize provided value
                    $new_dimension_width_original = $raw_width;
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
                        if ( $new_dimension_width_original !== $old_dimension_width_original ) {
                            $changed_fields[] = array(
                                'field' => 'dimension_width_original',
                                'old_value' => $old_dimension_width_original,
                                'new_value' => $new_dimension_width_original,
                            );
                        }
                    }
                }
            }
            
            // Process depth: explicit clear or normalize
            if ( isset( $_POST['dimension_depth'] ) ) {
                if ( $depth_is_clear ) {
                    // Explicit clear: set both original and normalized to NULL
                    $new_dimension_depth_original = null;
                    $new_dimension_depth_cm = null;
                    if ( $old_dimension_depth_cm !== null ) {
                        $dimension_changed = true;
                        $changed_fields[] = array(
                            'field' => 'dimension_depth_cm',
                            'old_value' => $old_dimension_depth_cm,
                            'new_value' => null,
                        );
                        $changed_fields[] = array(
                            'field' => 'dimension_depth_original',
                            'old_value' => $old_dimension_depth_original,
                            'new_value' => null,
                        );
                    }
                } else {
                    // Normalize provided value
                    $new_dimension_depth_original = $raw_depth;
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
                        if ( $new_dimension_depth_original !== $old_dimension_depth_original ) {
                            $changed_fields[] = array(
                                'field' => 'dimension_depth_original',
                                'old_value' => $old_dimension_depth_original,
                                'new_value' => $new_dimension_depth_original,
                            );
                        }
                    }
                }
            }
            
            // Process height: explicit clear or normalize
            if ( isset( $_POST['dimension_height'] ) ) {
                if ( $height_is_clear ) {
                    // Explicit clear: set both original and normalized to NULL
                    $new_dimension_height_original = null;
                    $new_dimension_height_cm = null;
                    if ( $old_dimension_height_cm !== null ) {
                        $dimension_changed = true;
                        $changed_fields[] = array(
                            'field' => 'dimension_height_cm',
                            'old_value' => $old_dimension_height_cm,
                            'new_value' => null,
                        );
                        $changed_fields[] = array(
                            'field' => 'dimension_height_original',
                            'old_value' => $old_dimension_height_original,
                            'new_value' => null,
                        );
                    }
                } else {
                    // Normalize provided value
                    $new_dimension_height_original = $raw_height;
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
                        if ( $new_dimension_height_original !== $old_dimension_height_original ) {
                            $changed_fields[] = array(
                                'field' => 'dimension_height_original',
                                'old_value' => $old_dimension_height_original,
                                'new_value' => $new_dimension_height_original,
                            );
                        }
                    }
                }
            }
            
            // Update normalized dimensions and original values in database
            // Fields present in POST are updated (including explicit NULL clears)
            // Fields not in POST are omitted (preserve existing values)
            $unit_changed_or_defaulted = false;
            
            if ( isset( $_POST['dimension_width'] ) ) {
                // Width was provided (either value or explicit clear)
                if ( $width_is_clear ) {
                    // Explicit clear: set to NULL via separate query
                    $dimension_null_clear_fields[] = 'dimension_width_cm';
                    $dimension_null_clear_fields[] = 'dimension_width_original';
                } else {
                    // Value provided: update both original and normalized
                    if ( $new_dimension_width_cm !== null ) {
                        $update_data['dimension_width_cm'] = $new_dimension_width_cm;
                        $update_format[] = '%f';
                    }
                    if ( $new_dimension_width_original !== null ) {
                        $update_data['dimension_width_original'] = $new_dimension_width_original;
                        $update_format[] = '%f';
                    }
                }
            }
            if ( isset( $_POST['dimension_depth'] ) ) {
                // Depth was provided (either value or explicit clear)
                if ( $depth_is_clear ) {
                    // Explicit clear: set to NULL via separate query
                    $dimension_null_clear_fields[] = 'dimension_depth_cm';
                    $dimension_null_clear_fields[] = 'dimension_depth_original';
                } else {
                    // Value provided: update both original and normalized
                    if ( $new_dimension_depth_cm !== null ) {
                        $update_data['dimension_depth_cm'] = $new_dimension_depth_cm;
                        $update_format[] = '%f';
                    }
                    if ( $new_dimension_depth_original !== null ) {
                        $update_data['dimension_depth_original'] = $new_dimension_depth_original;
                        $update_format[] = '%f';
                    }
                }
            }
            if ( isset( $_POST['dimension_height'] ) ) {
                // Height was provided (either value or explicit clear)
                if ( $height_is_clear ) {
                    // Explicit clear: set to NULL via separate query
                    $dimension_null_clear_fields[] = 'dimension_height_cm';
                    $dimension_null_clear_fields[] = 'dimension_height_original';
                } else {
                    // Value provided: update both original and normalized
                    if ( $new_dimension_height_cm !== null ) {
                        $update_data['dimension_height_cm'] = $new_dimension_height_cm;
                        $update_format[] = '%f';
                    }
                    if ( $new_dimension_height_original !== null ) {
                        $update_data['dimension_height_original'] = $new_dimension_height_original;
                        $update_format[] = '%f';
                    }
                }
            }
            
            // Store unit if any dimension was provided (always set, defaults to 'cm' if missing)
            if ( isset( $_POST['dimension_width'] ) || isset( $_POST['dimension_depth'] ) || isset( $_POST['dimension_height'] ) ) {
                // Check if unit changed or was defaulted
                $unit_was_defaulted = ( ! isset( $_POST['dimension_units_original'] ) || empty( $_POST['dimension_units_original'] ) );
                $unit_changed = ( $new_dimension_units_original !== $old_dimension_units_original );
                
                $update_data['dimension_units_original'] = $new_dimension_units_original;
                $update_format[] = '%s';
                
                // Only log unit normalization if unit was defaulted, changed, or conversion occurred
                if ( $unit_was_defaulted || $unit_changed || $dimension_changed ) {
                    $intelligence_events[] = 'item_unit_normalized';
                }
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
        // CBM must be cleared if dimensions become incomplete (not all 3 are set)
        $new_cbm = null;
        $cbm_needs_null_clear = false; // Track if CBM needs explicit NULL clear
        $dimensions_incomplete = ( $new_dimension_width_cm === null || $new_dimension_depth_cm === null || $new_dimension_height_cm === null );
        
        if ( $dimension_changed || $dimensions_incomplete ) {
            if ( $dimensions_incomplete ) {
                // Dimensions are incomplete - CBM must be NULL
                $new_cbm = null;
                if ( $old_cbm !== null ) {
                    $cbm_needs_null_clear = true;
                    $changed_fields[] = array(
                        'field' => 'cbm',
                        'old_value' => $old_cbm,
                        'new_value' => null,
                    );
                    $intelligence_events[] = 'item_cbm_recalculated';
                }
            } else {
                // All dimensions present - calculate CBM
                $new_cbm = N88_Intelligence::calculate_cbm( $new_dimension_width_cm, $new_dimension_depth_cm, $new_dimension_height_cm );
                if ( $new_cbm !== $old_cbm ) {
                    // Store CBM if not NULL (omit from update_data if NULL to preserve existing)
                    // If CBM becomes NULL (incomplete dimensions), we'll clear it explicitly
                    if ( $new_cbm !== null ) {
                        $update_data['cbm'] = $new_cbm;
                        $update_format[] = '%f';
                    } else {
                        // CBM is NULL - mark for explicit NULL clear
                        $cbm_needs_null_clear = true;
                    }
                    $changed_fields[] = array(
                        'field' => 'cbm',
                        'old_value' => $old_cbm,
                        'new_value' => $new_cbm,
                    );
                    $intelligence_events[] = 'item_cbm_recalculated';
                }
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

        // Explicitly clear dimension fields to NULL if explicitly cleared
        if ( ! empty( $dimension_null_clear_fields ) ) {
            // Whitelist: only allow dimension fields (cm, original, units_original)
            $allowed_dimension_fields = array(
                'dimension_width_cm',
                'dimension_depth_cm',
                'dimension_height_cm',
                'dimension_width_original',
                'dimension_depth_original',
                'dimension_height_original',
                'dimension_units_original',
            );
            
            // Build SET clause: field1 = NULL, field2 = NULL, ...
            $clear_parts = array();
            foreach ( $dimension_null_clear_fields as $field ) {
                // Only process whitelisted fields
                if ( in_array( $field, $allowed_dimension_fields, true ) ) {
                    $field_safe = sanitize_key( $field ); // Sanitize field name
                    $clear_parts[] = "{$field_safe} = NULL";
                }
            }
            
            // Only execute query if we have valid fields to clear
            if ( ! empty( $clear_parts ) ) {
                $clear_clause = implode( ', ', $clear_parts );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table} SET {$clear_clause} WHERE id = %d",
                    $item_id
                ) );
            }
        }
        
        // Explicitly clear CBM to NULL if dimensions became incomplete
        if ( $cbm_needs_null_clear ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET cbm = NULL WHERE id = %d",
                $item_id
            ) );
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

    /**
     * AJAX: Save Item Facts
     * 
     * Commit 1.3.8: Saves item facts from Item Detail Modal
     */
    public function ajax_save_item_facts() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();
        
        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
            return;
        }
        
        global $wpdb;
        
        // Get parameters
        $board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
        $payload_json = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
        
        if ( ! $item_id ) {
            wp_send_json_error( array( 'message' => 'Item ID is required.' ) );
            return;
        }
        
        // Decode payload
        $payload = json_decode( $payload_json, true );
        if ( ! is_array( $payload ) ) {
            wp_send_json_error( array( 'message' => 'Invalid payload format.' ) );
            return;
        }
        
        // Verify item ownership
        $items_table = $wpdb->prefix . 'n88_items';
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, owner_user_id FROM {$items_table} WHERE id = %d AND deleted_at IS NULL",
            $item_id
        ) );
        
        if ( ! $item ) {
            wp_send_json_error( array( 'message' => 'Item not found.' ) );
            return;
        }
        
        $user_id = get_current_user_id();
        if ( isset( $item->owner_user_id ) && $item->owner_user_id != $user_id && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
            return;
        }
        
        // Prepare update data
        $update_data = array();
        $update_format = array();
        
        // Category (map to item_type if exists, or store in meta_json)
        if ( isset( $payload['category'] ) ) {
            $category = sanitize_text_field( $payload['category'] );
            $update_data['item_type'] = $category;
            $update_format[] = '%s';
        }
        
        // Description
        if ( isset( $payload['description'] ) ) {
            $description = sanitize_textarea_field( $payload['description'] );
            $update_data['description'] = $description;
            $update_format[] = '%s';
        }
        
        // Get existing meta_json
        $meta_json = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_json FROM {$items_table} WHERE id = %d",
            $item_id
        ) );
        $meta = ! empty( $meta_json ) ? json_decode( $meta_json, true ) : array();
        if ( ! is_array( $meta ) ) {
            $meta = array();
        }
        
        // Store item facts in meta_json
        if ( isset( $payload['dims'] ) ) {
            $meta['dims'] = array(
                'w' => isset( $payload['dims']['w'] ) ? floatval( $payload['dims']['w'] ) : null,
                'd' => isset( $payload['dims']['d'] ) ? floatval( $payload['dims']['d'] ) : null,
                'h' => isset( $payload['dims']['h'] ) ? floatval( $payload['dims']['h'] ) : null,
                'unit' => isset( $payload['dims']['unit'] ) ? sanitize_text_field( $payload['dims']['unit'] ) : 'in',
            );
        }
        
        if ( isset( $payload['dims_cm'] ) ) {
            $meta['dims_cm'] = $payload['dims_cm'];
        }
        
        if ( isset( $payload['cbm'] ) ) {
            $meta['cbm'] = floatval( $payload['cbm'] );
        }
        
        if ( isset( $payload['sourcing_type'] ) ) {
            $meta['sourcing_type'] = sanitize_text_field( $payload['sourcing_type'] );
        }
        
        if ( isset( $payload['timeline_type'] ) ) {
            $meta['timeline_type'] = sanitize_text_field( $payload['timeline_type'] );
        }
        
        if ( isset( $payload['inspiration'] ) ) {
            $meta['inspiration'] = $payload['inspiration'];
        }
        
        // Update meta_json
        $update_data['meta_json'] = wp_json_encode( $meta );
        $update_format[] = '%s';
        
        // Update item
        $result = $wpdb->update(
            $items_table,
            $update_data,
            array( 'id' => $item_id ),
            $update_format,
            array( '%d' )
        );
        
        if ( $result === false ) {
            wp_send_json_error( array( 'message' => 'Failed to update item.' ) );
            return;
        }
        
        // Log event
        n88_log_event(
            'item_facts_saved',
            'item',
            array(
                'object_id' => $item_id,
                'item_id' => $item_id,
                'board_id' => $board_id > 0 ? $board_id : null,
                'payload_json' => $payload,
            )
        );
        
        wp_send_json_success( array(
            'message' => 'Item facts saved successfully.',
            'item_id' => $item_id,
        ) );
    }
}

