<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Boards Endpoints
 * 
 * Milestone 1.1: Board creation and item-board relationship endpoints.
 */
class N88_Boards {

    /**
     * Allowed view modes for boards
     * 
     * @var array
     */
    private static $allowed_view_modes = array(
        'grid',
        'list',
        '3d',
    );

    public function __construct() {
        // Register AJAX endpoints (logged-in users only)
        add_action( 'wp_ajax_n88_create_board', array( $this, 'ajax_create_board' ) );
        add_action( 'wp_ajax_n88_add_item_to_board', array( $this, 'ajax_add_item_to_board' ) );
        add_action( 'wp_ajax_n88_remove_item_from_board', array( $this, 'ajax_remove_item_from_board' ) );
        // Milestone 1.3: Board layout read endpoint
        add_action( 'wp_ajax_n88_get_board_layout', array( $this, 'ajax_get_board_layout' ) );
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

        // Check if user is designer - designers can only have 1 board
        $current_user = wp_get_current_user();
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        
        if ( $is_designer ) {
            global $wpdb;
            $boards_table = $wpdb->prefix . 'n88_boards';
            $existing_board = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$boards_table} WHERE owner_user_id = %d AND deleted_at IS NULL LIMIT 1",
                    $user_id
                )
            );
            
            if ( $existing_board ) {
                wp_send_json_error( array( 'message' => 'You already have a workspace. Designers can only create one workspace.' ), 400 );
            }
        }

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
     * AJAX: Remove Item from Board
     * 
     * Removes an item from a board (soft delete by setting removed_at).
     */
    public function ajax_remove_item_from_board() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to remove items from boards.' ), 401 );
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

        // Verify board-item relationship exists and is active
        global $wpdb;
        $board_items_table = $wpdb->prefix . 'n88_board_items';
        $relationship = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$board_items_table} WHERE board_id = %d AND item_id = %d AND removed_at IS NULL",
                $board_id,
                $item_id
            )
        );

        if ( ! $relationship ) {
            wp_send_json_error( array( 'message' => 'Item is not on this board or has already been removed.' ), 404 );
        }

        // Soft delete: set removed_at timestamp
        $now = current_time( 'mysql' );
        $updated = $wpdb->update(
            $board_items_table,
            array( 'removed_at' => $now ),
            array( 'id' => $relationship->id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to remove item from board.' ), 500 );
        }
        
        // Verify the update was successful
        if ( $updated === 0 ) {
            // No rows were updated - item might already be deleted
            wp_send_json_error( array( 'message' => 'Item not found or already removed.' ), 404 );
        }

        // Clear cache to ensure deleted item doesn't reappear on refresh
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
        
        // Clear wpdb query cache
        global $wpdb;
        if ( isset( $wpdb->query_cache ) ) {
            $wpdb->query_cache = array();
        }

        // Log event
        n88_log_event(
            'item_removed_from_board',
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
            'message'  => 'Item removed from board successfully.',
        ) );
    }

    /**
     * AJAX: Get Board Layout
     * 
     * Milestone 1.3: Read-only endpoint to fetch board metadata and latest layout snapshot.
     * This endpoint is read-only: no database writes, no event writes, no side effects.
     * 
     * Security steps (in order):
     * 1. Nonce check
     * 2. Auth check
     * 3. Ownership check
     * 4. Fetch and return board data
     */
    public function ajax_get_board_layout() {
        // Step 1: Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Step 2: Auth check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to view board layout.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Step 3: Ownership check
        $board_id = isset( $_REQUEST['board_id'] ) ? absint( $_REQUEST['board_id'] ) : 0;
        if ( $board_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid board ID.' ), 400 );
        }

        $board = N88_Authorization::get_board_for_user( $board_id, $user_id );
        if ( ! $board ) {
            wp_send_json_error( array( 'message' => 'Board not found or access denied.' ), 403 );
        }

        // Step 4: Fetch and return board data (read-only, no side effects)
        // Parse latest_layout_json if it exists
        $layout_data = null;
        if ( ! empty( $board->latest_layout_json ) ) {
            $layout_data = json_decode( $board->latest_layout_json, true );
            // Validate JSON structure
            if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $layout_data ) ) {
                // Invalid JSON: treat as empty
                $layout_data = null;
            }
        }

        // Graceful empty state: if no layout exists, return empty structure
        if ( $layout_data === null || ! isset( $layout_data['items'] ) || ! is_array( $layout_data['items'] ) ) {
            $layout_data = array(
                'items' => array(),
            );
        }

        // Deterministic response shape: always return the same JSON keys
        wp_send_json_success( array(
            'board' => array(
                'id'          => absint( $board->id ),
                'name'        => sanitize_text_field( $board->name ),
                'description' => ! empty( $board->description ) ? sanitize_textarea_field( $board->description ) : null,
                'view_mode'   => sanitize_text_field( $board->view_mode ),
                'created_at'  => sanitize_text_field( $board->created_at ),
                'updated_at'  => sanitize_text_field( $board->updated_at ),
            ),
            'layout' => $layout_data,
        ) );
    }
}

