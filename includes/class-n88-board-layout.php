<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Board Layout Endpoints
 * 
 * Milestone 1.1: Board layout update endpoint.
 * Rate limit increased to 100 per minute to support smooth drag/resize UX.
 */
class N88_Board_Layout {

    /**
     * Allowed view modes for board layout
     * 
     * @var array
     */
    private static $allowed_view_modes = array(
        'grid',
        'list',
        '3d',
    );

    /**
     * Rate limit: 100 layout updates per minute per user
     * Increased from 20 to support smooth drag/resize UX without breaking.
     */
    const RATE_LIMIT_COUNT = 100;
    const RATE_LIMIT_WINDOW = MINUTE_IN_SECONDS;

    /**
     * Milestone 1.3: Layout validation constants
     */
    const MIN_WIDTH = 100;
    const MAX_WIDTH = 800;
    const MIN_HEIGHT = 100;
    const MAX_HEIGHT = 1000;
    const MAX_POSITION = 100000;
    const MIN_POSITION = -100000;

    public function __construct() {
        // Register AJAX endpoint (logged-in users only)
        add_action( 'wp_ajax_n88_update_board_layout', array( $this, 'ajax_update_board_layout' ) );
        // Milestone 1.3: Board layout snapshot save endpoint
        add_action( 'wp_ajax_n88_save_board_layout', array( $this, 'ajax_save_board_layout' ) );
    }

    /**
     * AJAX: Update Board Layout
     * 
     * Updates layout position/size for an item on a board.
     * Accepts only: position_x, position_y, position_z, size_width, size_height, view_mode
     */
    public function ajax_update_board_layout() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to update board layout.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Rate limiting for layout updates (100 per minute per user)
        $rate_limit_result = N88_RFQ_Helpers::check_rate_limit( 'board_layout_update', self::RATE_LIMIT_COUNT, self::RATE_LIMIT_WINDOW, $user_id );
        if ( $rate_limit_result && isset( $rate_limit_result['throttled'] ) && $rate_limit_result['throttled'] ) {
            $retry_after = isset( $rate_limit_result['retry_after'] ) ? $rate_limit_result['retry_after'] : 60;
            wp_send_json_error(
                array(
                    'message'    => sprintf( 'Rate limit exceeded. Please try again in %d second(s).', $retry_after ),
                    'retry_after' => $retry_after,
                ),
                429
            );
        }

        // Sanitize and validate inputs
        $board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;

        if ( $board_id === 0 || $item_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid board ID or item ID.' ), 400 );
        }

        // Ownership validation: user must own the board (OR be admin)
        $board = N88_Authorization::get_board_for_user( $board_id, $user_id );
        if ( ! $board ) {
            wp_send_json_error( array( 'message' => 'Board not found or access denied.' ), 403 );
        }

        // Verify item is on board
        global $wpdb;
        $board_items_table = $wpdb->prefix . 'n88_board_items';
        $board_item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$board_items_table} WHERE board_id = %d AND item_id = %d AND removed_at IS NULL",
                $board_id,
                $item_id
            )
        );

        if ( ! $board_item ) {
            wp_send_json_error( array( 'message' => 'Item is not on this board.' ), 404 );
        }

        // Sanitize and validate layout fields
        $position_x = isset( $_POST['position_x'] ) ? floatval( $_POST['position_x'] ) : 0.00;
        $position_y = isset( $_POST['position_y'] ) ? floatval( $_POST['position_y'] ) : 0.00;
        $position_z = isset( $_POST['position_z'] ) ? intval( $_POST['position_z'] ) : 0;
        $size_width = isset( $_POST['size_width'] ) ? floatval( $_POST['size_width'] ) : null;
        $size_height = isset( $_POST['size_height'] ) ? floatval( $_POST['size_height'] ) : null;
        $view_mode = isset( $_POST['view_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['view_mode'] ) ) : 'grid';

        // Validate view_mode against whitelist
        if ( ! in_array( $view_mode, self::$allowed_view_modes, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid view mode.' ), 400 );
        }

        // Validate numeric ranges (reasonable limits)
        // Position can be negative (for flexible layouts), but limit to reasonable range
        if ( abs( $position_x ) > 100000 || abs( $position_y ) > 100000 ) {
            wp_send_json_error( array( 'message' => 'Position values out of range.' ), 400 );
        }

        if ( $size_width !== null && ( $size_width < 0 || $size_width > 100000 ) ) {
            wp_send_json_error( array( 'message' => 'Size width out of range.' ), 400 );
        }

        if ( $size_height !== null && ( $size_height < 0 || $size_height > 100000 ) ) {
            wp_send_json_error( array( 'message' => 'Size height out of range.' ), 400 );
        }

        // Build update/insert data
        $layout_table = $wpdb->prefix . 'n88_board_layout';
        $now = current_time( 'mysql' );

        $layout_data = array(
            'board_id'   => $board_id,
            'item_id'    => $item_id,
            'position_x' => $position_x,
            'position_y' => $position_y,
            'position_z' => $position_z,
            'view_mode'  => $view_mode,
            'updated_at' => $now,
        );

        $layout_format = array( '%d', '%d', '%f', '%f', '%d', '%s', '%s' );

        if ( $size_width !== null ) {
            $layout_data['size_width'] = $size_width;
            $layout_format[] = '%f';
        }

        if ( $size_height !== null ) {
            $layout_data['size_height'] = $size_height;
            $layout_format[] = '%f';
        }

        // Check if layout exists
        $existing_layout = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$layout_table} WHERE board_id = %d AND item_id = %d",
                $board_id,
                $item_id
            )
        );

        if ( $existing_layout ) {
            // Update existing layout
            $updated = $wpdb->update(
                $layout_table,
                $layout_data,
                array(
                    'board_id' => $board_id,
                    'item_id'  => $item_id,
                ),
                $layout_format,
                array( '%d', '%d' )
            );

            if ( $updated === false ) {
                wp_send_json_error( array( 'message' => 'Failed to update board layout.' ), 500 );
            }
        } else {
            // Insert new layout
            $inserted = $wpdb->insert(
                $layout_table,
                $layout_data,
                $layout_format
            );

            if ( ! $inserted ) {
                wp_send_json_error( array( 'message' => 'Failed to create board layout.' ), 500 );
            }
        }

        // Log event
        n88_log_event(
            'board_layout_updated',
            'board_layout',
            array(
                'board_id' => $board_id,
                'item_id'  => $item_id,
                'payload_json' => array(
                    'position_x' => $position_x,
                    'position_y' => $position_y,
                    'position_z' => $position_z,
                    'view_mode'  => $view_mode,
                ),
            )
        );

        wp_send_json_success( array(
            'board_id' => $board_id,
            'item_id'  => $item_id,
            'message'  => 'Board layout updated successfully.',
        ) );
    }

    /**
     * AJAX: Save Board Layout Snapshot
     * 
     * Milestone 1.3: Saves the full layout snapshot as a single JSON blob.
     * This endpoint accepts the complete layout state and stores it deterministically.
     * 
     * Security steps (in order):
     * 1. Nonce check
     * 2. Auth check
     * 3. Ownership check
     * 4. Validate payload
     * 5. Save snapshot
     * 6. Write event (only on success)
     */
    public function ajax_save_board_layout() {
        // Step 1: Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Step 2: Auth check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to save board layout.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Step 3: Ownership check (before processing payload)
        $board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
        if ( $board_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid board ID.' ), 400 );
        }

        $board = N88_Authorization::get_board_for_user( $board_id, $user_id );
        if ( ! $board ) {
            wp_send_json_error( array( 'message' => 'Board not found or access denied.' ), 403 );
        }

        // Step 4: Validate payload
        if ( ! isset( $_POST['items'] ) ) {
            wp_send_json_error( array( 'message' => 'Items array is required.' ), 400 );
        }

        $items_raw = wp_unslash( $_POST['items'] );
        if ( is_string( $items_raw ) ) {
            $items_raw = json_decode( $items_raw, true );
        }

        if ( ! is_array( $items_raw ) ) {
            wp_send_json_error( array( 'message' => 'Items must be a valid JSON array.' ), 400 );
        }

        // Validate each item structure
        $validated_items = array();
        $allowed_display_modes = array( 'photo_only', 'full' );

        foreach ( $items_raw as $index => $item ) {
            // Strict whitelist: only allow required fields
            $allowed_keys = array( 'id', 'x', 'y', 'z', 'width', 'height', 'sizeKey', 'displayMode' );
            $item_keys = array_keys( $item );
            $unknown_keys = array_diff( $item_keys, $allowed_keys );
            
            if ( ! empty( $unknown_keys ) ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d contains unknown keys: %s', $index, implode( ', ', $unknown_keys ) )
                ), 400 );
            }

            // Validate required fields
            if ( ! isset( $item['id'] ) || ! is_string( $item['id'] ) || empty( $item['id'] ) ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: id is required and must be a non-empty string.', $index )
                ), 400 );
            }

            if ( ! isset( $item['x'] ) || ! is_numeric( $item['x'] ) ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: x must be numeric.', $index )
                ), 400 );
            }

            if ( ! isset( $item['y'] ) || ! is_numeric( $item['y'] ) ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: y must be numeric.', $index )
                ), 400 );
            }

            if ( ! isset( $item['z'] ) || ! is_numeric( $item['z'] ) ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: z must be numeric.', $index )
                ), 400 );
            }

            if ( ! isset( $item['width'] ) || ! is_numeric( $item['width'] ) ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: width must be numeric.', $index )
                ), 400 );
            }

            if ( ! isset( $item['height'] ) || ! is_numeric( $item['height'] ) ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: height must be numeric.', $index )
                ), 400 );
            }

            if ( ! isset( $item['displayMode'] ) || ! in_array( $item['displayMode'], $allowed_display_modes, true ) ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: displayMode must be one of: %s', $index, implode( ', ', $allowed_display_modes ) )
                ), 400 );
            }

            // Validate numeric ranges
            $x = floatval( $item['x'] );
            $y = floatval( $item['y'] );
            $z = intval( $item['z'] );
            $width = floatval( $item['width'] );
            $height = floatval( $item['height'] );

            if ( $x < self::MIN_POSITION || $x > self::MAX_POSITION ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: x position out of range (must be between %d and %d).', $index, self::MIN_POSITION, self::MAX_POSITION )
                ), 400 );
            }

            if ( $y < self::MIN_POSITION || $y > self::MAX_POSITION ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: y position out of range (must be between %d and %d).', $index, self::MIN_POSITION, self::MAX_POSITION )
                ), 400 );
            }

            if ( $width < self::MIN_WIDTH || $width > self::MAX_WIDTH ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: width out of range (must be between %d and %d pixels).', $index, self::MIN_WIDTH, self::MAX_WIDTH )
                ), 400 );
            }

            if ( $height < self::MIN_HEIGHT || $height > self::MAX_HEIGHT ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 'Item at index %d: height out of range (must be between %d and %d pixels).', $index, self::MIN_HEIGHT, self::MAX_HEIGHT )
                ), 400 );
            }

            // Validate sizeKey if provided (optional, but must be valid if present)
            $size_key = null;
            if ( isset( $item['sizeKey'] ) && ! empty( $item['sizeKey'] ) ) {
                $allowed_size_keys = array( 'S', 'D', 'L', 'XL' );
                if ( ! in_array( $item['sizeKey'], $allowed_size_keys, true ) ) {
                    wp_send_json_error( array( 
                        'message' => sprintf( 'Item at index %d: sizeKey must be one of: %s', $index, implode( ', ', $allowed_size_keys ) )
                    ), 400 );
                }
                $size_key = sanitize_text_field( $item['sizeKey'] );
            }

            // Store validated item (will normalize z-index later)
            $validated_item = array(
                'id'          => sanitize_text_field( $item['id'] ),
                'x'           => $x,
                'y'           => $y,
                'z'           => $z,
                'width'       => $width,
                'height'      => $height,
                'displayMode' => sanitize_text_field( $item['displayMode'] ),
            );
            
            // Add sizeKey if provided (for forward compatibility)
            if ( $size_key !== null ) {
                $validated_item['sizeKey'] = $size_key;
            }
            
            $validated_items[] = $validated_item;
        }

        // Normalize z-index: preserve relative order, compact to 1..N
        if ( ! empty( $validated_items ) ) {
            // Create array with original index and z-value for sorting
            $items_with_index = array();
            foreach ( $validated_items as $idx => $item ) {
                $items_with_index[] = array(
                    'index' => $idx,
                    'z'     => $item['z'],
                    'item'  => $item,
                );
            }

            // Sort by z-index (ascending)
            usort( $items_with_index, function( $a, $b ) {
                return $a['z'] <=> $b['z'];
            } );

            // Assign normalized z-index (1, 2, 3, ... N)
            $normalized_items = array();
            foreach ( $items_with_index as $new_idx => $item_data ) {
                $item_data['item']['z'] = $new_idx + 1;
                $normalized_items[] = $item_data['item'];
            }

            $validated_items = $normalized_items;
        }

        // Step 5: Save snapshot
        global $wpdb;
        $boards_table = $wpdb->prefix . 'n88_boards';

        // Check if latest_layout_json column exists
        $table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $boards_table );
        $columns = $wpdb->get_col( "DESCRIBE {$table_safe}" );
        
        if ( ! in_array( 'latest_layout_json', $columns, true ) ) {
            wp_send_json_error( array( 
                'message' => 'Database schema not ready. Please contact administrator.' 
            ), 500 );
        }

        // Prepare layout snapshot JSON
        $layout_snapshot = array(
            'items' => $validated_items,
        );
        $layout_json = wp_json_encode( $layout_snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        if ( $layout_json === false ) {
            wp_send_json_error( array( 'message' => 'Failed to encode layout snapshot.' ), 500 );
        }

        // Update board with layout snapshot
        $now = current_time( 'mysql' );
        $updated = $wpdb->update(
            $boards_table,
            array(
                'latest_layout_json' => $layout_json,
                'updated_at'         => $now,
            ),
            array( 'id' => $board_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to save board layout.' ), 500 );
        }

        // Step 6: Write event (only on successful save)
        n88_log_event(
            'board_layout_updated',
            'board',
            array(
                'board_id'            => $board_id,
                'full_layout_snapshot' => $validated_items,
                'item_count'          => count( $validated_items ),
                'saved_by_user_id'    => $user_id,
                'saved_at'            => $now,
            )
        );

        // Success response
        wp_send_json_success( array(
            'message'  => 'Layout saved successfully.',
            'saved_at' => $now,
        ) );
    }
}

