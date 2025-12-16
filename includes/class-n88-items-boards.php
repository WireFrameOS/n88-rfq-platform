<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Items and Boards Endpoints
 * 
 * Milestone 1.1: Minimal endpoints for items and boards.
 * No UI, no workflows, no firm logic - endpoints only.
 */
class N88_Items_Boards {

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

    public function __construct() {
        // Register AJAX endpoints (logged-in users only)
        add_action( 'wp_ajax_n88_create_item', array( $this, 'ajax_create_item' ) );
        add_action( 'wp_ajax_n88_update_item', array( $this, 'ajax_update_item' ) );
        add_action( 'wp_ajax_n88_create_board', array( $this, 'ajax_create_board' ) );
        add_action( 'wp_ajax_n88_add_item_to_board', array( $this, 'ajax_add_item_to_board' ) );
        add_action( 'wp_ajax_n88_update_board_layout', array( $this, 'ajax_update_board_layout' ) );
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
     * AJAX: Update Item (core fields only)
     * 
     * Updates core fields of an item: title, description, status, item_type
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

        // Get current values for edit history
        $old_title = $item->title;
        $old_description = $item->description;
        $old_status = $item->status;
        $old_item_type = $item->item_type;

        // Sanitize and validate inputs (only core fields)
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : null;
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : null;
        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : null;
        $item_type = isset( $_POST['item_type'] ) ? sanitize_text_field( wp_unslash( $_POST['item_type'] ) ) : null;

        // Build update array
        $update_data = array();
        $update_format = array();
        $changed_fields = array();

        if ( $title !== null ) {
            if ( strlen( $title ) > 500 ) {
                wp_send_json_error( array( 'message' => 'Title exceeds maximum length of 500 characters.' ), 400 );
            }
            if ( $title !== $old_title ) {
                $update_data['title'] = $title;
                $update_format[] = '%s';
                $changed_fields[] = array(
                    'field' => 'title',
                    'old_value' => $old_title,
                    'new_value' => $title,
                );
            }
        }

        if ( $description !== null ) {
            if ( $description !== $old_description ) {
                $update_data['description'] = $description;
                $update_format[] = '%s';
                $changed_fields[] = array(
                    'field' => 'description',
                    'old_value' => $old_description,
                    'new_value' => $description,
                );
            }
        }

        if ( $status !== null ) {
            if ( ! in_array( $status, self::$allowed_item_statuses, true ) ) {
                wp_send_json_error( array( 'message' => 'Invalid status.' ), 400 );
            }
            if ( $status !== $old_status ) {
                $update_data['status'] = $status;
                $update_format[] = '%s';
                $changed_fields[] = array(
                    'field' => 'status',
                    'old_value' => $old_status,
                    'new_value' => $status,
                );
            }
        }

        if ( $item_type !== null ) {
            if ( ! in_array( $item_type, self::$allowed_item_types, true ) ) {
                wp_send_json_error( array( 'message' => 'Invalid item type.' ), 400 );
            }
            if ( $item_type !== $old_item_type ) {
                $update_data['item_type'] = $item_type;
                $update_format[] = '%s';
                $changed_fields[] = array(
                    'field' => 'item_type',
                    'old_value' => $old_item_type,
                    'new_value' => $item_type,
                );
            }
        }

        // If no changes, return success
        if ( empty( $update_data ) ) {
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
                    'old_value'      => $change['old_value'],
                    'new_value'      => $change['new_value'],
                    'editor_user_id' => $user_id,
                    'editor_role'    => $user_role,
                    'created_at'     => $now,
                ),
                array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
            );
        }

        // Log event
        n88_log_event(
            'item_field_changed',
            'item',
            array(
                'object_id' => $item_id,
                'item_id'   => $item_id,
                'payload_json' => array(
                    'changed_fields' => array_column( $changed_fields, 'field' ),
                    'version'        => $update_data['version'],
                ),
            )
        );

        wp_send_json_success( array(
            'item_id' => $item_id,
            'message' => 'Item updated successfully.',
            'changed_fields' => array_column( $changed_fields, 'field' ),
        ) );
    }

    /**
     * AJAX: Create Board
     * 
     * Creates a new board owned by the current user.
     */
    public function ajax_create_board() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to create boards.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Sanitize and validate inputs
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
        $view_mode = isset( $_POST['view_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['view_mode'] ) ) : 'grid';

        // Validate required fields
        if ( empty( $name ) ) {
            wp_send_json_error( array( 'message' => 'Board name is required.' ), 400 );
        }

        // Validate name length (max 255 chars per schema)
        if ( strlen( $name ) > 255 ) {
            wp_send_json_error( array( 'message' => 'Board name exceeds maximum length of 255 characters.' ), 400 );
        }

        // Validate view_mode against whitelist
        if ( ! in_array( $view_mode, self::$allowed_view_modes, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid view mode.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_boards';
        $now = current_time( 'mysql' );

        // Insert board
        $inserted = $wpdb->insert(
            $table,
            array(
                'owner_user_id' => $user_id,
                'name'         => $name,
                'description'  => $description,
                'view_mode'    => $view_mode,
                'created_at'   => $now,
                'updated_at'   => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Failed to create board.' ), 500 );
        }

        $board_id = $wpdb->insert_id;

        // Log event
        n88_log_event(
            'board_created',
            'board',
            array(
                'object_id' => $board_id,
                'board_id'  => $board_id,
                'payload_json' => array(
                    'name'      => $name,
                    'view_mode' => $view_mode,
                ),
            )
        );

        wp_send_json_success( array(
            'board_id' => $board_id,
            'message'  => 'Board created successfully.',
        ) );
    }

    /**
     * AJAX: Add Item to Board
     * 
     * Adds an item to a board (creates board-item relationship).
     */
    public function ajax_add_item_to_board() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to add items to boards.' ), 401 );
        }

        $user_id = get_current_user_id();

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

        // Verify item exists and is not deleted
        global $wpdb;
        $items_table = $wpdb->prefix . 'n88_items';
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$items_table} WHERE id = %d AND deleted_at IS NULL",
                $item_id
            )
        );

        if ( ! $item ) {
            wp_send_json_error( array( 'message' => 'Item not found or has been deleted.' ), 404 );
        }

        // Check if item is already on board (active relationship)
        $board_items_table = $wpdb->prefix . 'n88_board_items';
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$board_items_table} WHERE board_id = %d AND item_id = %d AND removed_at IS NULL",
                $board_id,
                $item_id
            )
        );

        if ( $existing ) {
            wp_send_json_error( array( 'message' => 'Item is already on this board.' ), 400 );
        }

        // Insert board-item relationship
        $now = current_time( 'mysql' );
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

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Failed to add item to board.' ), 500 );
        }

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

        wp_send_json_success( array(
            'board_id' => $board_id,
            'item_id'  => $item_id,
            'message'  => 'Item added to board successfully.',
        ) );
    }

    /**
     * AJAX: Update Board Layout
     * 
     * Updates layout position/size for an item on a board.
     * Accepts only: x, y, w, h, z, view_mode
     */
    public function ajax_update_board_layout() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to update board layout.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Rate limiting for layout updates (20 per minute per user)
        $rate_limit_result = N88_RFQ_Helpers::check_rate_limit( 'board_layout_update', 20, MINUTE_IN_SECONDS, $user_id );
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

