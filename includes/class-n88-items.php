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
     * Commit 2.3.5.3: Updated to match new category dropdown values
     * @var array
     */
    private static $allowed_item_types = array(
        // Legacy values (for backward compatibility)
        'furniture',
        'lighting',
        'accessory',
        'art',
        'other',
        // Commit 2.3.5.3: New category values
        'Indoor Furniture',
        'Sofas & Seating (Indoor)',
        'Chairs & Armchairs (Indoor)',
        'Dining Tables (Indoor)',
        'Cabinetry / Millwork (Custom)',
        'Casegoods (Beds, Nightstands, Desks, Consoles)',
        'Outdoor Furniture',
        'Outdoor Seating',
        'Outdoor Dining Sets',
        'Outdoor Loungers & Daybeds',
        'Pool Furniture',
        'Lighting',
        'Material Sample Kit',
        'Fabric Sample',
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
        add_action( 'wp_ajax_n88_upload_inspiration_image', array( $this, 'ajax_upload_inspiration_image' ) );
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
            
            // Validate file type - allow HEIC images
            $file_type = wp_check_filetype( $_FILES['image_file']['name'] );
            $allowed_types = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif' );
            if ( ! in_array( strtolower( $file_type['ext'] ), $allowed_types, true ) ) {
                wp_send_json_error( array( 'message' => 'Invalid file type. Only images are allowed (JPG, PNG, GIF, WEBP, HEIC).' ) );
                return;
            }
            
            // Allow HEIC MIME types in upload
            $mimes = array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'gif' => 'image/gif',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'heic' => 'image/heic',
                'heif' => 'image/heif',
            );
            
            $upload = wp_handle_upload( $_FILES['image_file'], array( 
                'test_form' => false,
                'mimes' => $mimes,
            ) );
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
        $default_size = isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : 'L';
        
        // Validate size
        $allowed_sizes = array( 'S', 'D', 'L', 'XL' );
        if ( ! in_array( $default_size, $allowed_sizes, true ) ) {
            $default_size = 'L';
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
        
        // Commit 2.3.5.1: Handle dimensions and quantity from Add Item form
        $quantity = isset( $_POST['quantity'] ) ? intval( $_POST['quantity'] ) : null;
        $dims = null;
        if ( isset( $_POST['dims'] ) ) {
            $dims_data = json_decode( wp_unslash( $_POST['dims'] ), true );
            if ( is_array( $dims_data ) && ( isset( $dims_data['w'] ) || isset( $dims_data['d'] ) || isset( $dims_data['h'] ) ) ) {
                $dims = array(
                    'w' => isset( $dims_data['w'] ) ? floatval( $dims_data['w'] ) : null,
                    'd' => isset( $dims_data['d'] ) ? floatval( $dims_data['d'] ) : null,
                    'h' => isset( $dims_data['h'] ) ? floatval( $dims_data['h'] ) : null,
                    'unit' => isset( $dims_data['unit'] ) ? sanitize_text_field( $dims_data['unit'] ) : 'in',
                );
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
        
        // Add quantity and dimensions to meta_json if provided
        if ( $quantity !== null && $quantity > 0 ) {
            $meta_json['quantity'] = $quantity;
        }
        if ( $dims !== null ) {
            $meta_json['dims'] = $dims;
            
            // Calculate dims_cm and CBM if all dimensions provided
            if ( $dims['w'] && $dims['d'] && $dims['h'] ) {
                $unit = $dims['unit'];
                $w_cm = null;
                $d_cm = null;
                $h_cm = null;
                
                // Convert to cm
                switch ( $unit ) {
                    case 'mm':
                        $w_cm = $dims['w'] / 10;
                        $d_cm = $dims['d'] / 10;
                        $h_cm = $dims['h'] / 10;
                        break;
                    case 'cm':
                        $w_cm = $dims['w'];
                        $d_cm = $dims['d'];
                        $h_cm = $dims['h'];
                        break;
                    case 'm':
                        $w_cm = $dims['w'] * 100;
                        $d_cm = $dims['d'] * 100;
                        $h_cm = $dims['h'] * 100;
                        break;
                    case 'in':
                    default:
                        $w_cm = $dims['w'] * 2.54;
                        $d_cm = $dims['d'] * 2.54;
                        $h_cm = $dims['h'] * 2.54;
                        break;
                }
                
                $meta_json['dims_cm'] = array(
                    'w' => $w_cm,
                    'd' => $d_cm,
                    'h' => $h_cm,
                );
                
                // Calculate CBM
                $cbm = ( $w_cm * $d_cm * $h_cm ) / 1000000.0;
                $meta_json['cbm'] = round( $cbm, 6 );
            }
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
                        
                        // Force database commit to ensure item is available immediately
                        // This is critical when redirecting right after item creation
                        global $wpdb;
                        
                        // Clear all caches
                        if ( function_exists( 'wp_cache_flush' ) ) {
                            wp_cache_flush();
                        }
                        
                        // Clear wpdb query cache
                        if ( isset( $wpdb->query_cache ) ) {
                            $wpdb->query_cache = array();
                        }
                        
                        // Clear caches to ensure fresh data
                        wp_cache_flush();
                        if ( isset( $wpdb->query_cache ) ) {
                            $wpdb->query_cache = array();
                        }
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
            error_log( 'Item Facts Save ERROR - Invalid payload format for item ' . $item_id . '. Payload JSON: ' . substr( $payload_json, 0, 500 ) );
            wp_send_json_error( array( 'message' => 'Invalid payload format.' ) );
            return;
        }
        
        // Log received payload for debugging
        error_log( 'Item Facts Save - Received payload for item ' . $item_id . ': ' . wp_json_encode( $payload ) );
        
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
        
        // Log existing meta for debugging
        error_log( 'Item Facts Save - Existing meta_json for item ' . $item_id . ': ' . wp_json_encode( $meta ) );
        
        // Commit 2.3.5.1: Check if RFQ exists and if bids exist (for post-RFQ editing detection)
        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $has_rfq = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$rfq_routes_table} 
            WHERE item_id = %d 
            AND status IN ('queued', 'sent', 'viewed', 'bid_submitted')",
            $item_id
        ) ) > 0;
        
        $has_bids = false;
        if ( $has_rfq ) {
            $has_bids = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$item_bids_table} 
                WHERE item_id = %d 
                AND status = 'submitted'",
                $item_id
            ) ) > 0;
        }
        
        // Track changes for event logging (only if RFQ exists)
        $changed_fields = array();
        $old_values = array();
        $new_values = array();
        
        if ( $has_rfq ) {
            // Store old values for dims and quantity
            if ( isset( $meta['dims'] ) ) {
                $old_values['dims'] = $meta['dims'];
            }
            if ( isset( $meta['quantity'] ) ) {
                $old_values['quantity'] = $meta['quantity'];
            }
            if ( isset( $meta['cbm'] ) ) {
                $old_values['cbm'] = $meta['cbm'];
            }
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
            // Validate and sanitize inspiration array
            $inspiration = $payload['inspiration'];
            if ( is_array( $inspiration ) ) {
                $valid_inspiration = array();
                foreach ( $inspiration as $insp_item ) {
                    // Only save items with valid structure - must have either a valid ID or a valid HTTP URL
                    if ( ! is_array( $insp_item ) ) {
                        continue;
                    }
                    
                    // Check for valid ID (must be numeric and > 0)
                    // Note: isset() returns false for null, so we need to check array_key_exists or use null coalescing
                    $id_value = isset( $insp_item['id'] ) ? $insp_item['id'] : null;
                    $has_valid_id = $id_value !== null && 
                                    $id_value !== '' &&
                                    is_numeric( $id_value ) && 
                                    intval( $id_value ) > 0;
                    
                    // Check for valid URL (must be non-empty and start with http/https)
                    $url = isset( $insp_item['url'] ) ? trim( $insp_item['url'] ) : '';
                    $has_valid_url = ! empty( $url ) && 
                                    $url !== '' &&
                                    ( strpos( $url, 'http://' ) === 0 || strpos( $url, 'https://' ) === 0 );
                    
                    // Only save if it has either a valid ID or a valid URL
                    if ( $has_valid_id || $has_valid_url ) {
                        $valid_inspiration[] = array(
                            'type' => isset( $insp_item['type'] ) ? sanitize_text_field( $insp_item['type'] ) : 'image',
                            'id' => $has_valid_id ? intval( $id_value ) : null,
                            'url' => $has_valid_url ? esc_url_raw( $url ) : '',
                            'title' => isset( $insp_item['title'] ) ? sanitize_text_field( $insp_item['title'] ) : '',
                        );
                    } else {
                        error_log( 'Item Facts Save - Skipping invalid inspiration item for item ' . $item_id . ': ' . wp_json_encode( $insp_item ) );
                    }
                }
                $meta['inspiration'] = $valid_inspiration;
                error_log( 'Item Facts Save - Saved ' . count( $valid_inspiration ) . ' inspiration images for item ' . $item_id . ': ' . wp_json_encode( $valid_inspiration ) );
            } else {
                error_log( 'Item Facts Save - Inspiration is not an array for item ' . $item_id );
                $meta['inspiration'] = array();
            }
        }
        
        // Smart Alternatives (Commit: Designer Item Modal)
        if ( isset( $payload['smart_alternatives'] ) ) {
            $meta['smart_alternatives'] = (bool) $payload['smart_alternatives'];
        }
        
        // Smart Alternatives Note (Notes for suppliers) - ALWAYS save if key exists
        // Commit 2.3.5.1: Add anti-circumvention filter (reject emails, URLs, phone patterns, contact phrases)
        if ( array_key_exists( 'smart_alternatives_note', $payload ) ) {
            $note_value = $payload['smart_alternatives_note'];
            
            // Anti-circumvention validation
            $note_lower = strtolower( $note_value );
            
            // Check for email addresses
            if ( preg_match( '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $note_value ) ) {
                wp_send_json_error( array( 'message' => 'Notes cannot include contact details or links.' ) );
                return;
            }
            
            // Check for URLs (http, https, www, .com, .net, etc.)
            if ( preg_match( '/\b(https?:\/\/|www\.|[a-zA-Z0-9-]+\.[a-zA-Z]{2,})/', $note_value ) ) {
                wp_send_json_error( array( 'message' => 'Notes cannot include contact details or links.' ) );
                return;
            }
            
            // Check for phone patterns (7+ digits with common separators)
            if ( preg_match( '/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b|\b\d{7,}\b/', $note_value ) ) {
                wp_send_json_error( array( 'message' => 'Notes cannot include contact details or links.' ) );
                return;
            }
            
            // Check for contact phrases
            $contact_phrases = array( 'call', 'text', 'whatsapp', 'contact me', 'reach out', 'get in touch', 'email me', 'phone me', 'call me', 'text me' );
            foreach ( $contact_phrases as $phrase ) {
                if ( strpos( $note_lower, $phrase ) !== false ) {
                    wp_send_json_error( array( 'message' => 'Notes cannot include contact details or links.' ) );
                    return;
                }
            }
            
            // If validation passes, save the note
            $meta['smart_alternatives_note'] = sanitize_textarea_field( $note_value );
            error_log( 'Item Facts Save - Saving smart_alternatives_note for item ' . $item_id . ': ' . substr( $note_value, 0, 100 ) );
        } else {
            error_log( 'Item Facts Save - WARNING: smart_alternatives_note key NOT found in payload for item ' . $item_id );
        }
        
        // Delivery info (Commit: Designer Item Modal)
        if ( isset( $payload['delivery_country'] ) ) {
            $meta['delivery_country'] = sanitize_text_field( $payload['delivery_country'] );
        }
        
        if ( isset( $payload['delivery_postal'] ) ) {
            $meta['delivery_postal'] = sanitize_text_field( $payload['delivery_postal'] );
        }
        
        // Quantity (Commit: State B save fix) - ALWAYS save if key exists and value is valid
        if ( array_key_exists( 'quantity', $payload ) ) {
            $qty_value = $payload['quantity'];
            error_log( 'Item Facts Save - Processing quantity for item ' . $item_id . '. Raw value: ' . var_export( $qty_value, true ) . ' (type: ' . gettype( $qty_value ) . ')' );
            
            // Save if key exists AND value is numeric AND >= 0
            // Fix: Use is_numeric() instead of is_nan() for integers
            if ( $qty_value !== null && $qty_value !== '' ) {
                // Check if value is numeric (handles both string and numeric)
                if ( is_numeric( $qty_value ) ) {
                    $qty_int = (int) $qty_value; // Cast to int
                    // Allow 0 and positive values
                    if ( $qty_int >= 0 ) {
                        $meta['quantity'] = $qty_int;
                        error_log( 'Item Facts Save - SUCCESS: Saving quantity ' . $qty_int . ' for item ' . $item_id );
                    } else {
                        error_log( 'Item Facts Save - ERROR: Quantity must be >= 0 for item ' . $item_id . '. Value: ' . var_export( $qty_value, true ) );
                    }
                } else {
                    error_log( 'Item Facts Save - ERROR: Quantity is not numeric for item ' . $item_id . '. Value: ' . var_export( $qty_value, true ) );
                }
            } else {
                error_log( 'Item Facts Save - WARNING: Quantity is null or empty for item ' . $item_id . '. Value: ' . var_export( $qty_value, true ) );
            }
        } else {
            error_log( 'Item Facts Save - WARNING: quantity key NOT found in payload for item ' . $item_id );
        }
        
        // Commit 2.3.5.1: Detect changes after RFQ (for event logging) - must be done before updating meta_json
        if ( $has_rfq ) {
            // Check if dims changed
            if ( isset( $payload['dims'] ) ) {
                $new_dims = array(
                    'w' => isset( $payload['dims']['w'] ) ? floatval( $payload['dims']['w'] ) : null,
                    'd' => isset( $payload['dims']['d'] ) ? floatval( $payload['dims']['d'] ) : null,
                    'h' => isset( $payload['dims']['h'] ) ? floatval( $payload['dims']['h'] ) : null,
                    'unit' => isset( $payload['dims']['unit'] ) ? sanitize_text_field( $payload['dims']['unit'] ) : 'in',
                );
                $old_dims = isset( $old_values['dims'] ) ? $old_values['dims'] : null;
                $old_unit = isset( $old_dims['unit'] ) ? $old_dims['unit'] : null;
                $new_unit = $new_dims['unit'];
                
                if ( wp_json_encode( $old_dims ) !== wp_json_encode( $new_dims ) ) {
                    $changed_fields[] = 'dims';
                    $new_values['dims'] = $new_dims;
                    
                    // Also track dimension_unit separately if unit changed
                    if ( $old_unit !== $new_unit ) {
                        if ( ! in_array( 'dimension_unit', $changed_fields, true ) ) {
                            $changed_fields[] = 'dimension_unit';
                        }
                        $new_values['dimension_unit'] = $new_unit;
                        if ( ! isset( $old_values['dimension_unit'] ) ) {
                            $old_values['dimension_unit'] = $old_unit;
                        }
                    }
                }
            }
            
            // Check if quantity changed
            if ( array_key_exists( 'quantity', $payload ) ) {
                $qty_value = $payload['quantity'];
                if ( $qty_value !== null && $qty_value !== '' && is_numeric( $qty_value ) ) {
                    $new_qty = (int) $qty_value;
                    $old_qty = isset( $old_values['quantity'] ) ? $old_values['quantity'] : null;
                    if ( $old_qty !== $new_qty ) {
                        $changed_fields[] = 'quantity';
                        $new_values['quantity'] = $new_qty;
                    }
                }
            }
            
            // Check if CBM changed (recalculated)
            if ( isset( $payload['cbm'] ) ) {
                $new_cbm = floatval( $payload['cbm'] );
                $old_cbm = isset( $old_values['cbm'] ) ? $old_values['cbm'] : null;
                if ( abs( ( $old_cbm ? $old_cbm : 0 ) - $new_cbm ) > 0.0001 ) {
                    $changed_fields[] = 'cbm';
                    $new_values['cbm'] = $new_cbm;
                }
            }
        }
        
        // D2) Revision Increment Logic: If RFQ exists and dims/quantity changed, increment revision
        $revision_incremented = false;
        $new_revision = null;
        if ( $has_rfq && ! empty( $changed_fields ) ) {
            // Check if dims or quantity changed (these trigger revision increment)
            $specs_changed = in_array( 'dims', $changed_fields, true ) || in_array( 'quantity', $changed_fields, true );
            
            if ( $specs_changed ) {
                // Get current revision from meta (default to 1 if not set)
                $current_revision = isset( $meta['rfq_revision_current'] ) ? intval( $meta['rfq_revision_current'] ) : 1;
                
                // Increment revision
                $new_revision = $current_revision + 1;
                $meta['rfq_revision_current'] = $new_revision;
                $revision_incremented = true;
                
                // Set revision_changed flag to true
                $meta['revision_changed'] = true;
                
                error_log( 'Item Facts Save - Revision incremented for item ' . $item_id . ' from ' . $current_revision . ' to ' . $new_revision );
                
                // Mark existing bids as stale (bids with older revision or no revision)
                $item_bids_table = $wpdb->prefix . 'n88_item_bids';
                $bids_columns = $wpdb->get_col( "DESCRIBE {$item_bids_table}" );
                $has_revision_column = in_array( 'rfq_revision_at_submit', $bids_columns, true );
                
                if ( $has_revision_column ) {
                    // Update bids where rfq_revision_at_submit < new_revision OR is NULL
                    $stale_bids_updated = $wpdb->query( $wpdb->prepare(
                        "UPDATE {$item_bids_table} 
                        SET rfq_revision_at_submit = NULL 
                        WHERE item_id = %d 
                        AND (rfq_revision_at_submit IS NULL OR rfq_revision_at_submit < %d)",
                        $item_id,
                        $new_revision
                    ) );
                    
                    if ( $stale_bids_updated !== false ) {
                        error_log( 'Item Facts Save - Marked ' . $stale_bids_updated . ' stale bid(s) for item ' . $item_id );
                    }
                }
                
                // Get supplier IDs from RFQ routes for this item
                $supplier_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT supplier_id FROM {$rfq_routes_table} 
                    WHERE item_id = %d 
                    AND status IN ('queued', 'sent', 'viewed', 'bid_submitted')",
                    $item_id
                ) );
                
                // Send notifications to suppliers about spec changes
                if ( ! empty( $supplier_ids ) ) {
                    // Get item title for notification
                    $item_title = $wpdb->get_var( $wpdb->prepare(
                        "SELECT title FROM {$items_table} WHERE id = %d",
                        $item_id
                    ) );
                    $item_title = $item_title ? $item_title : 'Item #' . $item_id;
                    
                    // Get board_id from board_items table if available (for notification project_id)
                    $board_items_table = $wpdb->prefix . 'n88_board_items';
                    $board_id_for_notification = $board_id > 0 ? $board_id : $wpdb->get_var( $wpdb->prepare(
                        "SELECT board_id FROM {$board_items_table} 
                        WHERE item_id = %d AND removed_at IS NULL 
                        LIMIT 1",
                        $item_id
                    ) );
                    $board_id_for_notification = $board_id_for_notification ? intval( $board_id_for_notification ) : 0;
                    
                    // Send notification to each supplier
                    foreach ( $supplier_ids as $supplier_id ) {
                        // Create in-app notification
                        if ( class_exists( 'N88_RFQ_Notifications' ) ) {
                            $notification_message = sprintf( 
                                'Specifications changed for item: %s. Please review the updated requirements.',
                                $item_title
                            );
                            
                            // Use board_id as project_id for notification system
                            N88_RFQ_Notifications::create_notification(
                                $board_id_for_notification, // project_id (using board_id)
                                $supplier_id,
                                'specs_changed',
                                $notification_message,
                                $item_id
                            );
                            
                            error_log( 'Item Facts Save - Sent specs_changed notification to supplier ' . $supplier_id . ' for item ' . $item_id . ' (board_id: ' . $board_id_for_notification . ')' );
                        }
                    }
                }
            }
        }
        
        // Update meta_json
        $meta_json_encoded = wp_json_encode( $meta );
        $update_data['meta_json'] = $meta_json_encoded;
        $update_format[] = '%s';
        
        // Log final meta before save
        error_log( 'Item Facts Save - Final meta_json to save for item ' . $item_id . ': ' . $meta_json_encoded );
        error_log( 'Item Facts Save - Quantity in final meta: ' . ( isset( $meta['quantity'] ) ? var_export( $meta['quantity'], true ) : 'NOT SET' ) );
        error_log( 'Item Facts Save - smart_alternatives_note in final meta: ' . ( isset( $meta['smart_alternatives_note'] ) ? substr( $meta['smart_alternatives_note'], 0, 100 ) : 'NOT SET' ) );
        
        // Update item
        $result = $wpdb->update(
            $items_table,
            $update_data,
            array( 'id' => $item_id ),
            $update_format,
            array( '%d' )
        );
        
        if ( $result === false ) {
            error_log( 'Item Facts Save - DATABASE UPDATE FAILED for item ' . $item_id . '. Error: ' . $wpdb->last_error );
            error_log( 'Item Facts Save - Update data: ' . wp_json_encode( $update_data ) );
            error_log( 'Item Facts Save - Update format: ' . wp_json_encode( $update_format ) );
            wp_send_json_error( array( 'message' => 'Failed to update item. Database error: ' . $wpdb->last_error ) );
            return;
        }
        
        // Verify the save worked by reading back from database
        $verify_meta_json = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_json FROM {$items_table} WHERE id = %d",
            $item_id
        ) );
        $verify_meta = ! empty( $verify_meta_json ) ? json_decode( $verify_meta_json, true ) : array();
        
        error_log( 'Item Facts Save - Database update result: ' . ( $result > 0 ? 'SUCCESS (' . $result . ' rows updated)' : 'NO ROWS UPDATED' ) );
        error_log( 'Item Facts Save - Verified meta_json after save: ' . wp_json_encode( $verify_meta ) );
        error_log( 'Item Facts Save - Verified quantity after save: ' . ( isset( $verify_meta['quantity'] ) ? var_export( $verify_meta['quantity'], true ) : 'NOT FOUND' ) );
        error_log( 'Item Facts Save - Verified smart_alternatives_note after save: ' . ( isset( $verify_meta['smart_alternatives_note'] ) ? substr( $verify_meta['smart_alternatives_note'], 0, 100 ) : 'NOT FOUND' ) );
        
        // Commit 2.3.10: Recalculate delivery cost if dimensions, quantity, or delivery country changed
        $should_recalculate_delivery = false;
        if ( in_array( 'dims', $changed_fields, true ) || in_array( 'quantity', $changed_fields, true ) || 
             ( isset( $payload['delivery_country'] ) || isset( $payload['delivery_postal'] ) ) ) {
            $should_recalculate_delivery = true;
        }
        
        if ( $should_recalculate_delivery ) {
            // Update delivery context with latest dimensions, quantity, and country/postal
            $delivery_context_table = $wpdb->prefix . 'n88_item_delivery_context';
            $delivery_data = array();
            $delivery_format = array();
            
            // Check if dimensions_json and quantity columns exist
            $columns = $wpdb->get_col( "DESCRIBE {$delivery_context_table}" );
            $has_dimensions_column = in_array( 'dimensions_json', $columns, true );
            $has_quantity_column = in_array( 'quantity', $columns, true );
            
            // Update dimensions_json if dimensions were updated
            if ( $has_dimensions_column && isset( $payload['dims'] ) && is_array( $payload['dims'] ) ) {
                $dimensions_data = array(
                    'width' => isset( $payload['dims']['w'] ) ? floatval( $payload['dims']['w'] ) : null,
                    'depth' => isset( $payload['dims']['d'] ) ? floatval( $payload['dims']['d'] ) : null,
                    'height' => isset( $payload['dims']['h'] ) ? floatval( $payload['dims']['h'] ) : null,
                    'unit' => isset( $payload['dims']['unit'] ) ? sanitize_text_field( $payload['dims']['unit'] ) : 'in',
                );
                $delivery_data['dimensions_json'] = wp_json_encode( $dimensions_data );
                $delivery_format[] = '%s';
            }
            
            // Update quantity if it was updated
            if ( $has_quantity_column && isset( $payload['quantity'] ) && $payload['quantity'] > 0 ) {
                $delivery_data['quantity'] = (int) $payload['quantity'];
                $delivery_format[] = '%d';
            }
            
            // Update delivery country/postal if provided
            if ( isset( $payload['delivery_country'] ) ) {
                $delivery_data['delivery_country_code'] = strtoupper( sanitize_text_field( $payload['delivery_country'] ) );
                $delivery_format[] = '%s';
            }
            
            if ( isset( $payload['delivery_postal'] ) ) {
                $delivery_data['delivery_postal_code'] = sanitize_text_field( $payload['delivery_postal'] );
                $delivery_format[] = '%s';
            }
            
            // Update delivery_context if we have data to update
            if ( ! empty( $delivery_data ) ) {
                $existing_delivery = $wpdb->get_var( $wpdb->prepare(
                    "SELECT item_id FROM {$delivery_context_table} WHERE item_id = %d",
                    $item_id
                ) );
                
                if ( $existing_delivery ) {
                    $wpdb->update(
                        $delivery_context_table,
                        $delivery_data,
                        array( 'item_id' => $item_id ),
                        $delivery_format,
                        array( '%d' )
                    );
                    error_log( sprintf( 
                        'Item Update - Updated delivery_context for item %d with latest dimensions/quantity: %s',
                        $item_id, wp_json_encode( $delivery_data )
                    ) );
                } else {
                    // Insert with minimal required fields
                    $delivery_data['item_id'] = $item_id;
                    $delivery_data['shipping_estimate_mode'] = 'auto';
                    if ( ! isset( $delivery_data['delivery_country_code'] ) ) {
                        $delivery_data['delivery_country_code'] = 'US'; // Default
                    }
                    $delivery_format = array_merge( array( '%d' ), $delivery_format, array( '%s' ) );
                    $wpdb->insert( $delivery_context_table, $delivery_data, $delivery_format );
                }
            }
            
            // Recalculate and store delivery cost with updated dimensions/quantity
            if ( class_exists( 'N88_RFQ_Pricing' ) ) {
                error_log( sprintf( 'Item Update - Triggering delivery cost recalculation for item %d', $item_id ) );
                N88_RFQ_Pricing::calculate_and_store_delivery_cost( $item_id );
            }
        }
        
        // Commit 2.3.5.1: Log event - use item_facts_updated_after_rfq if RFQ exists and dims/qty changed
        if ( $has_rfq && ! empty( $changed_fields ) ) {
            // Log item_facts_updated_after_rfq event with before/after values
            $event_payload = array(
                'changed_fields' => $changed_fields,
                'before' => array(),
                'after' => array(),
                'has_bids' => $has_bids ? true : false,
            );
            
            foreach ( $changed_fields as $field ) {
                if ( isset( $old_values[ $field ] ) ) {
                    $event_payload['before'][ $field ] = $old_values[ $field ];
                }
                if ( isset( $new_values[ $field ] ) ) {
                    $event_payload['after'][ $field ] = $new_values[ $field ];
                }
            }
            
            n88_log_event(
                'item_facts_updated_after_rfq',
                'item',
                array(
                    'object_id' => $item_id,
                    'item_id' => $item_id,
                    'board_id' => $board_id > 0 ? $board_id : null,
                    'payload_json' => $event_payload,
                )
            );
            
            error_log( 'Item Facts Save - Logged item_facts_updated_after_rfq event for item ' . $item_id . ' with changes: ' . wp_json_encode( $event_payload ) );
        } else {
            // Normal save event (no RFQ or no changes)
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
        }
        
        // Return warning flag if bids exist and dims/qty changed
        $has_warning = false;
        if ( $has_bids && ! empty( $changed_fields ) ) {
            $has_warning = true;
        }
        
        wp_send_json_success( array(
            'message' => 'Item facts saved successfully.',
            'item_id' => $item_id,
            'has_warning' => $has_warning, // Flag for frontend to show warning banner
        ) );
    }

    /**
     * AJAX: Upload inspiration image to WordPress media library
     * This ensures all inspiration images are properly stored in WordPress media library
     */
    public function ajax_upload_inspiration_image() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();
        
        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
            return;
        }
        
        // Check if file was uploaded
        if ( empty( $_FILES['inspiration_image'] ) || $_FILES['inspiration_image']['error'] !== UPLOAD_ERR_OK ) {
            $error_msg = 'No file uploaded or upload error occurred.';
            if ( ! empty( $_FILES['inspiration_image']['error'] ) ) {
                $error_codes = array(
                    UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                    UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
                );
                $error_code = $_FILES['inspiration_image']['error'];
                $error_msg = isset( $error_codes[ $error_code ] ) ? $error_codes[ $error_code ] : 'Upload error code: ' . $error_code;
            }
            wp_send_json_error( array( 'message' => $error_msg ) );
            return;
        }
        
        // Commit 2.3.5.3: Verify it's an image or PDF
        $file_type = wp_check_filetype( $_FILES['inspiration_image']['name'] );
        $allowed_types = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'pdf' );
        if ( ! in_array( strtolower( $file_type['ext'] ), $allowed_types, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid file type. Only images and PDFs are allowed (JPG, PNG, GIF, WEBP, HEIC, PDF).' ) );
            return;
        }
        
        $is_pdf = ( strtolower( $file_type['ext'] ) === 'pdf' );
        $is_heic = ( strtolower( $file_type['ext'] ) === 'heic' );
        
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        
        // Check file size (max 10MB)
        $max_size = 10 * 1024 * 1024; // 10MB in bytes
        if ( $_FILES['inspiration_image']['size'] > $max_size ) {
            wp_send_json_error( array( 
                'message' => 'File size exceeds maximum allowed size of 10MB.' 
            ) );
            return;
        }
        
        // Use wp_handle_upload first to process the file
        // Commit 2.3.5.3: Allow PDFs in addition to images
        // Added HEIC support for designer image uploads
        $mimes = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
        );
        if ( $is_pdf ) {
            $mimes['pdf'] = 'application/pdf';
        }
        
        $upload = wp_handle_upload( $_FILES['inspiration_image'], array( 
            'test_form' => false,
            'mimes' => $mimes,
        ) );
        
        if ( isset( $upload['error'] ) ) {
            error_log( 'Inspiration image upload failed - wp_handle_upload error: ' . $upload['error'] );
            wp_send_json_error( array( 
                'message' => 'Failed to upload image: ' . $upload['error'] 
            ) );
            return;
        }
        
        // Verify upload file exists
        if ( ! isset( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
            error_log( 'Inspiration image upload failed - Upload file does not exist: ' . ( isset( $upload['file'] ) ? $upload['file'] : 'not set' ) );
            wp_send_json_error( array( 
                'message' => 'Uploaded file not found. Please try again.' 
            ) );
            return;
        }
        
        // Create attachment in media library
        $user_id = get_current_user_id();
        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $user_id, // Set author so it appears in user's media library
        );
        
        $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
        
        if ( is_wp_error( $attachment_id ) ) {
            error_log( 'Inspiration image upload failed - wp_insert_attachment error: ' . $attachment_id->get_error_message() );
            wp_send_json_error( array( 
                'message' => 'Failed to create attachment: ' . $attachment_id->get_error_message() 
            ) );
            return;
        }
        
        // Validate attachment ID is valid
        if ( ! $attachment_id || $attachment_id <= 0 ) {
            error_log( 'Inspiration image upload failed - Invalid attachment ID: ' . $attachment_id );
            wp_send_json_error( array( 
                'message' => 'Failed to create attachment. Invalid attachment ID.' 
            ) );
            return;
        }
        
        // Generate attachment metadata (creates thumbnails, etc.)
        // Commit 2.3.5.3: Only generate thumbnails for images, not PDFs
        // Note: HEIC files may not generate thumbnails in WordPress by default, but file will be uploaded
        if ( ! $is_pdf ) {
            $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
            wp_update_attachment_metadata( $attachment_id, $attach_data );
        }
        
        // Get attachment data
        $attachment_url = wp_get_attachment_url( $attachment_id );
        $attachment_title = get_the_title( $attachment_id );
        $attachment_filename = basename( get_attached_file( $attachment_id ) );
        
        // Validate that we have both ID and URL before returning
        if ( ! $attachment_id || $attachment_id <= 0 || empty( $attachment_url ) ) {
            error_log( 'Inspiration image upload failed - Missing ID or URL. ID: ' . $attachment_id . ', URL: ' . ( $attachment_url ? 'present' : 'missing' ) );
            wp_send_json_error( array( 
                'message' => 'Failed to retrieve attachment data. Please try again.' 
            ) );
            return;
        }
        
        // Log success for debugging
        error_log( 'Inspiration image uploaded successfully - Attachment ID: ' . $attachment_id . ', URL: ' . $attachment_url );
        
        // Return attachment data for frontend
        wp_send_json_success( array(
            'id' => intval( $attachment_id ),
            'url' => esc_url_raw( $attachment_url ),
            'full_url' => esc_url_raw( $attachment_url ),
            'title' => $attachment_title ? sanitize_text_field( $attachment_title ) : sanitize_file_name( $attachment_filename ),
            'filename' => sanitize_file_name( $attachment_filename ),
        ) );
    }
}

