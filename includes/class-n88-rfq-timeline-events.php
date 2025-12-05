<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * N88 RFQ Timeline Events Class
 * 
 * Handles logging of all timeline events for analytics and audit trail.
 * Phase 3 - Milestone 3.1
 */
class N88_RFQ_Timeline_Events {

    /**
     * Event type constants
     */
    const EVENT_STEP_STARTED = 'step_started';
    const EVENT_STEP_COMPLETED = 'step_completed';
    const EVENT_STEP_REOPENED = 'step_reopened';
    const EVENT_STEP_DELAYED = 'step_delayed';
    const EVENT_STEP_UNBLOCKED = 'step_unblocked';
    const EVENT_OVERRIDE_APPLIED = 'override_applied';
    const EVENT_FILE_ADDED = 'file_added';
    const EVENT_FILE_REMOVED = 'file_removed';
    const EVENT_COMMENT_ADDED = 'comment_added';
    const EVENT_COMMENT_REMOVED = 'comment_removed';
    const EVENT_VIDEO_ADDED = 'video_added';
    const EVENT_VIDEO_REMOVED = 'video_removed';
    const EVENT_NOTE_ADDED = 'note_added';
    const EVENT_NOTE_UPDATED = 'note_updated';
    const EVENT_STATUS_CHANGED = 'status_changed';
    const EVENT_DEPENDENCY_UNLOCKED = 'dependency_unlocked';
    const EVENT_DEPENDENCY_LOCKED = 'dependency_locked';
    const EVENT_OUT_OF_SEQUENCE = 'out_of_sequence';

    /**
     * Get table name
     * 
     * @return string Table name
     */
    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'n88_timeline_events';
    }

    /**
     * Log a timeline event
     * 
     * @param int $project_id Project ID
     * @param int $item_id Item index (0-based)
     * @param string $step_key Step key (e.g., 'prototype', 'frame_structure')
     * @param string $event_type Event type constant
     * @param string $status Current status (pending, in_progress, completed, blocked, delayed)
     * @param array $event_data Additional event data (will be JSON encoded)
     * @param int|null $user_id User ID who triggered the event (null = current user)
     * @return int|false Event ID on success, false on failure
     */
    public static function log_event( $project_id, $item_id, $step_key, $event_type, $status = null, $event_data = array(), $user_id = null ) {
        global $wpdb;

        if ( $user_id === null ) {
            $user_id = get_current_user_id();
        }

        $table = self::get_table_name();

        $data = array(
            'project_id' => absint( $project_id ),
            'item_id' => absint( $item_id ),
            'step_key' => sanitize_text_field( $step_key ),
            'event_type' => sanitize_text_field( $event_type ),
            'status' => $status ? sanitize_text_field( $status ) : null,
            'event_data' => ! empty( $event_data ) ? wp_json_encode( $event_data ) : null,
            'created_at' => current_time( 'mysql' ),
            'created_by' => $user_id ? absint( $user_id ) : null,
        );

        $format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' );

        $result = $wpdb->insert( $table, $data, $format );

        if ( $result === false ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'N88 RFQ Timeline Events: Failed to log event. Error: ' . $wpdb->last_error );
            }
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get events for a specific item and step
     * 
     * @param int $project_id Project ID
     * @param int $item_id Item index (0-based)
     * @param string $step_key Step key (optional)
     * @param int $limit Limit number of results
     * @return array Array of event objects
     */
    public static function get_events( $project_id, $item_id, $step_key = null, $limit = 100 ) {
        global $wpdb;

        $table = self::get_table_name();

        $where = $wpdb->prepare( 'project_id = %d AND item_id = %d', $project_id, $item_id );

        if ( $step_key ) {
            $where .= $wpdb->prepare( ' AND step_key = %s', $step_key );
        }

        $query = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d";
        $query = $wpdb->prepare( $query, $limit );

        $results = $wpdb->get_results( $query, ARRAY_A );

        if ( ! $results ) {
            return array();
        }

        // Decode event_data JSON
        foreach ( $results as &$result ) {
            if ( ! empty( $result['event_data'] ) ) {
                $result['event_data'] = json_decode( $result['event_data'], true );
            }
        }

        return $results;
    }

    /**
     * Get latest event for a specific item and step
     * 
     * @param int $project_id Project ID
     * @param int $item_id Item index (0-based)
     * @param string $step_key Step key
     * @return array|null Event array or null if not found
     */
    public static function get_latest_event( $project_id, $item_id, $step_key ) {
        global $wpdb;

        $table = self::get_table_name();

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE project_id = %d AND item_id = %d AND step_key = %s ORDER BY created_at DESC LIMIT 1",
            $project_id,
            $item_id,
            $step_key
        );

        $result = $wpdb->get_row( $query, ARRAY_A );

        if ( ! $result ) {
            return null;
        }

        // Decode event_data JSON
        if ( ! empty( $result['event_data'] ) ) {
            $result['event_data'] = json_decode( $result['event_data'], true );
        }

        return $result;
    }

    /**
     * Get current status for a step (from latest event)
     * 
     * @param int $project_id Project ID
     * @param int $item_id Item index (0-based)
     * @param string $step_key Step key
     * @return string Status (pending, in_progress, completed, blocked, delayed) or 'pending' if no events
     */
    public static function get_step_status( $project_id, $item_id, $step_key ) {
        $latest_event = self::get_latest_event( $project_id, $item_id, $step_key );

        if ( ! $latest_event || empty( $latest_event['status'] ) ) {
            return 'pending';
        }

        return $latest_event['status'];
    }

    /**
     * Log step started event
     * 
     * @param int $project_id Project ID
     * @param int $item_id Item index (0-based)
     * @param string $step_key Step key
     * @param string $old_status Previous status
     * @param int|null $user_id User ID
     * @return int|false Event ID on success
     */
    public static function log_step_started( $project_id, $item_id, $step_key, $old_status = 'pending', $user_id = null ) {
        $event_data = array(
            'old_status' => $old_status,
            'new_status' => 'in_progress',
        );

        return self::log_event(
            $project_id,
            $item_id,
            $step_key,
            self::EVENT_STEP_STARTED,
            'in_progress',
            $event_data,
            $user_id
        );
    }

    /**
     * Log step completed event
     * 
     * @param int $project_id Project ID
     * @param int $item_id Item index (0-based)
     * @param string $step_key Step key
     * @param string $old_status Previous status
     * @param int|null $user_id User ID
     * @param array $additional_data Additional event data
     * @return int|false Event ID on success
     */
    public static function log_step_completed( $project_id, $item_id, $step_key, $old_status = 'in_progress', $user_id = null, $additional_data = array() ) {
        $event_data = array_merge(
            array(
                'old_status' => $old_status,
                'new_status' => 'completed',
                'completed_by' => $user_id ?: get_current_user_id(),
            ),
            $additional_data
        );

        return self::log_event(
            $project_id,
            $item_id,
            $step_key,
            self::EVENT_STEP_COMPLETED,
            'completed',
            $event_data,
            $user_id
        );
    }

    /**
     * Log out-of-sequence event
     * 
     * @param int $project_id Project ID
     * @param int $item_id Item index (0-based)
     * @param string $step_key Step key that was completed out of sequence
     * @param array $previous_steps_status Array of previous step statuses
     * @param int|null $user_id User ID
     * @return int|false Event ID on success
     */
    public static function log_out_of_sequence( $project_id, $item_id, $step_key, $previous_steps_status = array(), $user_id = null ) {
        $event_data = array(
            'step_key' => $step_key,
            'previous_steps_status' => $previous_steps_status,
            'warning' => 'Step completed out of sequence',
        );

        return self::log_event(
            $project_id,
            $item_id,
            $step_key,
            self::EVENT_OUT_OF_SEQUENCE,
            null,
            $event_data,
            $user_id
        );
    }
}

