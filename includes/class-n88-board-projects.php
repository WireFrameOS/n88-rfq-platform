<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Board-First Projects Management
 * 
 * Commit 2.5.2: Projects are containers within boards, with rooms and items
 * One Board = Multiple Projects
 * One Project = Multiple Rooms
 * One Room = Multiple Items
 */
class N88_Board_Projects {

    public function __construct() {
        // Register AJAX endpoints
        add_action( 'wp_ajax_n88_create_board_project', array( $this, 'ajax_create_project' ) );
        add_action( 'wp_ajax_n88_get_board_projects', array( $this, 'ajax_get_projects' ) );
        add_action( 'wp_ajax_n88_create_project_room', array( $this, 'ajax_create_room' ) );
        add_action( 'wp_ajax_n88_update_project_room', array( $this, 'ajax_update_room' ) );
        add_action( 'wp_ajax_n88_delete_project_room', array( $this, 'ajax_delete_room' ) );
        add_action( 'wp_ajax_n88_reorder_project_rooms', array( $this, 'ajax_reorder_rooms' ) );
        add_action( 'wp_ajax_n88_get_project_rooms', array( $this, 'ajax_get_rooms' ) );
    }

    /**
     * AJAX: Create Project
     * 
     * Creates a new project within a board
     */
    public function ajax_create_project() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to create projects.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Sanitize and validate inputs
        $board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

        if ( $board_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Board ID is required.' ), 400 );
        }

        if ( empty( $name ) ) {
            wp_send_json_error( array( 'message' => 'Project name is required.' ), 400 );
        }

        // Verify board ownership
        $board = N88_Authorization::get_board_for_user( $board_id, $user_id );
        if ( ! $board ) {
            wp_send_json_error( array( 'message' => 'Board not found or access denied.' ), 403 );
        }

        global $wpdb;
        $projects_table = $wpdb->prefix . 'n88_projects';
        $now = current_time( 'mysql' );

        // Insert project
        $inserted = $wpdb->insert(
            $projects_table,
            array(
                'board_id'    => $board_id,
                'name'        => $name,
                'description' => $description,
                'status'      => 'draft',
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Failed to create project.' ), 500 );
        }

        $project_id = $wpdb->insert_id;

        // Log event
        n88_log_event(
            'project_created',
            'project',
            array(
                'object_id' => $project_id,
                'board_id'  => $board_id,
                'project_id' => $project_id,
                'payload_json' => array(
                    'name' => $name,
                ),
            )
        );

        wp_send_json_success( array(
            'project_id' => $project_id,
            'message'    => 'Project created successfully.',
        ) );
    }

    /**
     * AJAX: Get Projects for a Board
     */
    public function ajax_get_projects() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ), 401 );
        }

        $user_id = get_current_user_id();
        $board_id = isset( $_GET['board_id'] ) ? absint( $_GET['board_id'] ) : 0;

        if ( $board_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Board ID is required.' ), 400 );
        }

        // Verify board ownership
        $board = N88_Authorization::get_board_for_user( $board_id, $user_id );
        if ( ! $board ) {
            wp_send_json_error( array( 'message' => 'Board not found or access denied.' ), 403 );
        }

        global $wpdb;
        $projects_table = $wpdb->prefix . 'n88_projects';

        $projects = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, board_id, name, description, status, created_at, updated_at
                 FROM {$projects_table}
                 WHERE board_id = %d
                 AND deleted_at IS NULL
                 ORDER BY created_at ASC",
                $board_id
            ),
            ARRAY_A
        );

        wp_send_json_success( array(
            'projects' => $projects ? $projects : array(),
        ) );
    }

    /**
     * AJAX: Create Room in Project
     */
    public function ajax_create_room() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to create rooms.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Sanitize and validate inputs
        $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

        if ( $project_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Project ID is required.' ), 400 );
        }

        if ( empty( $name ) ) {
            wp_send_json_error( array( 'message' => 'Room name is required.' ), 400 );
        }

        // Verify project access (via board ownership)
        if ( ! $this->can_access_project( $project_id, $user_id ) ) {
            wp_send_json_error( array( 'message' => 'Project not found or access denied.' ), 403 );
        }

        global $wpdb;
        $rooms_table = $wpdb->prefix . 'n88_project_rooms';

        // Get max display_order for this project
        $max_order = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(display_order), 0) FROM {$rooms_table}
                 WHERE project_id = %d AND deleted_at IS NULL",
                $project_id
            )
        );

        $now = current_time( 'mysql' );

        // Insert room
        $inserted = $wpdb->insert(
            $rooms_table,
            array(
                'project_id'    => $project_id,
                'name'          => $name,
                'description'   => $description,
                'display_order' => intval( $max_order ) + 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ),
            array( '%d', '%s', '%s', '%d', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Failed to create room.' ), 500 );
        }

        $room_id = $wpdb->insert_id;

        wp_send_json_success( array(
            'room_id' => $room_id,
            'message' => 'Room created successfully.',
        ) );
    }

    /**
     * AJAX: Update Room
     */
    public function ajax_update_room() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ), 401 );
        }

        $user_id = get_current_user_id();
        $room_id = isset( $_POST['room_id'] ) ? absint( $_POST['room_id'] ) : 0;
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

        if ( $room_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Room ID is required.' ), 400 );
        }

        if ( empty( $name ) ) {
            wp_send_json_error( array( 'message' => 'Room name is required.' ), 400 );
        }

        // Verify room access (via project -> board ownership)
        global $wpdb;
        $rooms_table = $wpdb->prefix . 'n88_project_rooms';
        $projects_table = $wpdb->prefix . 'n88_projects';
        $boards_table = $wpdb->prefix . 'n88_boards';

        $room = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.project_id, p.board_id
                 FROM {$rooms_table} r
                 INNER JOIN {$projects_table} p ON r.project_id = p.id
                 WHERE r.id = %d AND r.deleted_at IS NULL",
                $room_id
            ),
            ARRAY_A
        );

        if ( ! $room ) {
            wp_send_json_error( array( 'message' => 'Room not found.' ), 404 );
        }

        // Verify board ownership
        $board = N88_Authorization::get_board_for_user( $room['board_id'], $user_id );
        if ( ! $board ) {
            wp_send_json_error( array( 'message' => 'Access denied.' ), 403 );
        }

        // Update room
        $updated = $wpdb->update(
            $rooms_table,
            array(
                'name'        => $name,
                'description' => $description,
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( 'id' => $room_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to update room.' ), 500 );
        }

        wp_send_json_success( array(
            'message' => 'Room updated successfully.',
        ) );
    }

    /**
     * AJAX: Delete Room
     */
    public function ajax_delete_room() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ), 401 );
        }

        $user_id = get_current_user_id();
        $room_id = isset( $_POST['room_id'] ) ? absint( $_POST['room_id'] ) : 0;

        if ( $room_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Room ID is required.' ), 400 );
        }

        // Verify room access
        global $wpdb;
        $rooms_table = $wpdb->prefix . 'n88_project_rooms';
        $projects_table = $wpdb->prefix . 'n88_projects';
        $items_table = $wpdb->prefix . 'n88_items';

        $room = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.project_id, p.board_id
                 FROM {$rooms_table} r
                 INNER JOIN {$projects_table} p ON r.project_id = p.id
                 WHERE r.id = %d AND r.deleted_at IS NULL",
                $room_id
            ),
            ARRAY_A
        );

        if ( ! $room ) {
            wp_send_json_error( array( 'message' => 'Room not found.' ), 404 );
        }

        // Verify board ownership
        $board = N88_Authorization::get_board_for_user( $room['board_id'], $user_id );
        if ( ! $board ) {
            wp_send_json_error( array( 'message' => 'Access denied.' ), 403 );
        }

        // Check if room has items
        $item_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$items_table}
                 WHERE room_id = %d AND deleted_at IS NULL",
                $room_id
            )
        );

        if ( $item_count > 0 ) {
            wp_send_json_error( array( 'message' => 'Cannot delete room with items. Please remove items first.' ), 400 );
        }

        // Soft delete room
        $updated = $wpdb->update(
            $rooms_table,
            array(
                'deleted_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $room_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to delete room.' ), 500 );
        }

        wp_send_json_success( array(
            'message' => 'Room deleted successfully.',
        ) );
    }

    /**
     * AJAX: Reorder Rooms
     */
    public function ajax_reorder_rooms() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ), 401 );
        }

        $user_id = get_current_user_id();
        $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
        $room_orders = isset( $_POST['room_orders'] ) ? $_POST['room_orders'] : array();

        if ( $project_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Project ID is required.' ), 400 );
        }

        if ( ! is_array( $room_orders ) || empty( $room_orders ) ) {
            wp_send_json_error( array( 'message' => 'Room orders are required.' ), 400 );
        }

        // Verify project access
        if ( ! $this->can_access_project( $project_id, $user_id ) ) {
            wp_send_json_error( array( 'message' => 'Access denied.' ), 403 );
        }

        global $wpdb;
        $rooms_table = $wpdb->prefix . 'n88_project_rooms';

        // Update display_order for each room
        foreach ( $room_orders as $order_data ) {
            $room_id = isset( $order_data['room_id'] ) ? absint( $order_data['room_id'] ) : 0;
            $display_order = isset( $order_data['display_order'] ) ? absint( $order_data['display_order'] ) : 0;

            if ( $room_id > 0 ) {
                $wpdb->update(
                    $rooms_table,
                    array( 'display_order' => $display_order ),
                    array( 'id' => $room_id, 'project_id' => $project_id ),
                    array( '%d' ),
                    array( '%d', '%d' )
                );
            }
        }

        wp_send_json_success( array(
            'message' => 'Rooms reordered successfully.',
        ) );
    }

    /**
     * AJAX: Get Rooms for Project
     */
    public function ajax_get_rooms() {
        // Nonce verification - check both GET and POST parameters
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( $_REQUEST['nonce'] ) : '';
        
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'n88-rfq-nonce' ) ) {
            // Log the error for debugging
            error_log( 'N88 Get Rooms: Nonce verification failed. Nonce received: ' . ( $nonce ? 'yes' : 'no' ) . ', REQUEST: ' . print_r( $_REQUEST, true ) );
            wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page and try again.' ), 403 );
        }

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ), 401 );
        }

        $user_id = get_current_user_id();
        $project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0;

        if ( $project_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Project ID is required.' ), 400 );
        }

        // Verify project access
        if ( ! $this->can_access_project( $project_id, $user_id ) ) {
            wp_send_json_error( array( 'message' => 'Access denied.' ), 403 );
        }

        global $wpdb;
        $rooms_table = $wpdb->prefix . 'n88_project_rooms';

        $rooms = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, project_id, name, description, display_order, created_at, updated_at
                 FROM {$rooms_table}
                 WHERE project_id = %d
                 AND deleted_at IS NULL
                 ORDER BY display_order ASC, created_at ASC",
                $project_id
            ),
            ARRAY_A
        );

        wp_send_json_success( array(
            'rooms' => $rooms ? $rooms : array(),
        ) );
    }

    /**
     * Check if user can access a project (via board ownership)
     */
    private function can_access_project( $project_id, $user_id ) {
        global $wpdb;
        $projects_table = $wpdb->prefix . 'n88_projects';

        $project = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT board_id FROM {$projects_table}
                 WHERE id = %d AND deleted_at IS NULL",
                $project_id
            ),
            ARRAY_A
        );

        if ( ! $project ) {
            return false;
        }

        $board = N88_Authorization::get_board_for_user( $project['board_id'], $user_id );
        return $board !== null;
    }
}
