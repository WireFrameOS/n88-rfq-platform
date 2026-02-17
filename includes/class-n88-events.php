<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Event System - Immutable Event Spine
 * 
 * Phase 1.1: Core Infrastructure
 * 
 * This class provides a write-only API for logging events.
 * Events are append-only and immutable - no UPDATE or DELETE operations are allowed.
 */
class N88_Events {

    /**
     * Allowed event types for Phase 1.1 + 1.2
     * 
     * @var array
     */
    private static $allowed_event_types = array(
        'designer_profile_created',
        'item_created',
        'item_field_changed',
        'board_created',
        'item_added_to_board',
        'board_layout_updated',
        // Phase 1.2: Intelligence events
        'item_sourcing_type_set',
        'item_sourcing_type_changed',
        'item_timeline_type_derived',
        'item_dimension_changed',
        'item_cbm_recalculated',
        'item_unit_normalized',
        // Phase 1.2.3: Material Bank events
        'material_created',
        'material_updated',
        'material_activated',
        'material_deactivated',
        'material_attached_to_item',
        'material_detached_from_item',
        // Phase 1.2.4: Materials-in-Mind linking
        'materials_in_mind_linked_to_item',
        // Commit 1.3.8: Item facts saved
        'item_facts_saved',
        // Commit 2.3.5.1: Item facts updated after RFQ
        'item_facts_updated_after_rfq',
        // Commit 2.3.9.1A: CAD + Prototype request events
        'cad_prototype_requested',
        'video_direction_submitted',
        // Commit 2.3.9.1C-a: Item message events
        'item_message_sent',
        // Commit 2.3.9.1D: Payment confirmation events
        'payment_marked_received',
        // Commit 2.3.9.1F: Payment evidence closure events
        'prototype_payment_marked_received',
        // Commit 2.3.9.2A: CAD workflow events
        'cad_uploaded',
        'cad_revision_requested',
        'cad_approved',
        'cad_released_to_supplier',
        // Commit 2.3.9.2B-S: Prototype video submission events
        'prototype_video_submitted',
        // Commit 2.3.9.2B-D: Designer prototype review events
        'prototype_video_changes_requested',
        'prototype_video_approved',
        // Commit 3.B.5A/B: Revision detail + project awarded
        'prototype_revision_requested',
        'prototype_approved',
        // Commit 3.A.1: Item timeline spine events
        'timeline_created',
        'timeline_step_started',
        'timeline_step_completed',
        // Commit 3.A.2S: Supplier step evidence
        'step_evidence_submitted',
        // Commit 3.B.5.A1: Step 4–6 video evidence + designer step comments
        'timeline_step_video_submitted',
        'timeline_step_video_added_by_operator',
        'timeline_step_comment_added',
    );

    /**
     * Maximum payload size in bytes (10KB)
     * 
     * @var int
     */
    const MAX_PAYLOAD_SIZE = 10240;

    /**
     * Allowed object types (entity types)
     * 
     * @var array
     */
    private static $allowed_object_types = array(
        'item',
        'board',
        'board_layout',
        'designer_profile',
        'prototype_payment',
        'item_message',
    );

    /**
     * Insert a new event into the event spine.
     * 
     * This is the ONLY write method for events. Events are immutable and append-only.
     * No UPDATE or DELETE methods exist in this class.
     * 
     * IMPORTANT: actor_user_id is ALWAYS derived from get_current_user_id() - never trust incoming user_id.
     * 
     * @param array $data Event data with the following keys:
     *   - event_type (string, required): Event type (must be in whitelist)
     *   - object_type (string, required): Object type (must be in whitelist: 'item', 'board', 'board_layout', 'designer_profile')
     *   - object_id (int, optional): Object ID
     *   - item_id (int, optional): Item ID if event relates to item
     *   - board_id (int, optional): Board ID if event relates to board
     *   - payload_json (string|array, optional): JSON payload (max 10KB)
     *   - ip_address (string, optional): Client IP address (auto-captured if not provided)
     *   - user_agent (string, optional): Client user agent (auto-captured if not provided)
     * 
     * @return int|false Event ID on success, false on failure
     */
    public static function insert_event( $data ) {
        global $wpdb;

        // ALWAYS derive actor_user_id from get_current_user_id() - never trust incoming user_id
        $actor_user_id = get_current_user_id();
        if ( $actor_user_id === 0 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'N88_Events::insert_event() - User not logged in' );
            }
            return false;
        }

        // Validate required fields
        if ( ! isset( $data['event_type'] ) || ! isset( $data['object_type'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'N88_Events::insert_event() - Missing required fields: event_type or object_type' );
            }
            return false;
        }

        // Validate event type against whitelist
        if ( ! in_array( $data['event_type'], self::$allowed_event_types, true ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'N88_Events::insert_event() - Invalid event type: ' . $data['event_type'] );
            }
            return false;
        }

        // Validate object_type against whitelist
        if ( ! in_array( $data['object_type'], self::$allowed_object_types, true ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'N88_Events::insert_event() - Invalid object_type: ' . $data['object_type'] );
            }
            return false;
        }

        // Sanitize event_type and object_type (already validated against whitelist)
        $event_type = sanitize_text_field( $data['event_type'] );
        $object_type = sanitize_text_field( $data['object_type'] );
        
        // Validate event_type string length (max 100 chars per schema)
        if ( strlen( $event_type ) > 100 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'N88_Events::insert_event() - Event type exceeds maximum length: ' . strlen( $event_type ) );
            }
            return false;
        }

        // Sanitize optional fields
        $actor_firm_id = isset( $data['actor_firm_id'] ) ? absint( $data['actor_firm_id'] ) : null;
        if ( $actor_firm_id === 0 ) {
            $actor_firm_id = null;
        }

        $object_id = isset( $data['object_id'] ) ? absint( $data['object_id'] ) : null;
        if ( $object_id === 0 ) {
            $object_id = null;
        }

        $item_id = isset( $data['item_id'] ) ? absint( $data['item_id'] ) : null;
        if ( $item_id === 0 ) {
            $item_id = null;
        }

        $board_id = isset( $data['board_id'] ) ? absint( $data['board_id'] ) : null;
        if ( $board_id === 0 ) {
            $board_id = null;
        }

        // Process payload_json
        $payload_json = null;
        if ( isset( $data['payload_json'] ) ) {
            if ( is_array( $data['payload_json'] ) ) {
                $payload_json = wp_json_encode( $data['payload_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                // Handle JSON encoding errors
                if ( $payload_json === false ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( 'N88_Events::insert_event() - Failed to encode payload JSON' );
                    }
                    return false;
                }
            } else {
                // If it's already a string, validate it's valid JSON
                $test_decode = json_decode( $data['payload_json'], true );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( 'N88_Events::insert_event() - Invalid JSON payload: ' . json_last_error_msg() );
                    }
                    return false;
                }
                $payload_json = sanitize_textarea_field( $data['payload_json'] );
            }

            // Validate payload size (max 10KB)
            if ( strlen( $payload_json ) > self::MAX_PAYLOAD_SIZE ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'N88_Events::insert_event() - Payload exceeds maximum size: ' . strlen( $payload_json ) . ' bytes (max: ' . self::MAX_PAYLOAD_SIZE . ')' );
                }
                return false;
            }
        }

        // Get IP address and user agent from request if not provided
        $ip_address = isset( $data['ip_address'] ) ? sanitize_text_field( $data['ip_address'] ) : '';
        if ( empty( $ip_address ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        // Limit IP address to 45 characters (IPv6 max length)
        if ( strlen( $ip_address ) > 45 ) {
            $ip_address = substr( $ip_address, 0, 45 );
        }

        $user_agent = isset( $data['user_agent'] ) ? sanitize_text_field( $data['user_agent'] ) : '';
        if ( empty( $user_agent ) && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
        }
        // Limit user agent to 500 characters
        if ( strlen( $user_agent ) > 500 ) {
            $user_agent = substr( $user_agent, 0, 500 );
        }

        // Set created_at to current time (not user-provided)
        $created_at = current_time( 'mysql' );

        // Prepare table name
        $table = $wpdb->prefix . 'n88_events';

        // Build insert data array
        $insert_data = array(
            'actor_user_id' => $actor_user_id,
            'event_type'    => $event_type,
            'object_type'   => $object_type,
            'created_at'    => $created_at,
        );

        $format = array( '%d', '%s', '%s', '%s' );

        // Add optional fields
        if ( $actor_firm_id !== null ) {
            $insert_data['actor_firm_id'] = $actor_firm_id;
            $format[] = '%d';
        }

        if ( $object_id !== null ) {
            $insert_data['object_id'] = $object_id;
            $format[] = '%d';
        }

        if ( $item_id !== null ) {
            $insert_data['item_id'] = $item_id;
            $format[] = '%d';
        }

        if ( $board_id !== null ) {
            $insert_data['board_id'] = $board_id;
            $format[] = '%d';
        }

        if ( $payload_json !== null ) {
            $insert_data['payload_json'] = $payload_json;
            $format[] = '%s';
        }

        if ( ! empty( $ip_address ) ) {
            $insert_data['ip_address'] = $ip_address;
            $format[] = '%s';
        }

        if ( ! empty( $user_agent ) ) {
            $insert_data['user_agent'] = $user_agent;
            $format[] = '%s';
        }

        // Insert event using prepared statement
        $inserted = $wpdb->insert( $table, $insert_data, $format );

        if ( $inserted ) {
            return $wpdb->insert_id;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'N88_Events::insert_event() - Failed to insert event: ' . $wpdb->last_error );
        }

        return false;
    }

    /**
     * Get allowed event types.
     * 
     * @return array Array of allowed event type strings
     */
    public static function get_allowed_event_types() {
        return self::$allowed_event_types;
    }

    /**
     * Check if an event type is allowed.
     * 
     * @param string $event_type Event type to check
     * @return bool True if allowed, false otherwise
     */
    public static function is_event_type_allowed( $event_type ) {
        return in_array( $event_type, self::$allowed_event_types, true );
    }

    /**
     * Get allowed object types.
     * 
     * @return array Array of allowed object type strings
     */
    public static function get_allowed_object_types() {
        return self::$allowed_object_types;
    }

    /**
     * Check if an object type is allowed.
     * 
     * @param string $object_type Object type to check
     * @return bool True if allowed, false otherwise
     */
    public static function is_object_type_allowed( $object_type ) {
        return in_array( $object_type, self::$allowed_object_types, true );
    }
}

/**
 * Centralized event logging helper function.
 * 
 * This is the single entry point for writing events.
 * Always derives actor_user_id from get_current_user_id().
 * 
 * @param string $event_type Event type (must be in whitelist)
 * @param string $object_type Object type (must be in whitelist)
 * @param array $args Optional arguments:
 *   - object_id (int): Object ID
 *   - item_id (int): Item ID if event relates to item
 *   - board_id (int): Board ID if event relates to board
 *   - payload_json (string|array): JSON payload (max 10KB)
 *   - ip_address (string): Client IP address
 *   - user_agent (string): Client user agent
 * 
 * @return int|false Event ID on success, false on failure
 */
function n88_log_event( $event_type, $object_type, $args = array() ) {
    $data = array_merge(
        array(
            'event_type'  => $event_type,
            'object_type' => $object_type,
        ),
        $args
    );
    
    return N88_Events::insert_event( $data );
}

