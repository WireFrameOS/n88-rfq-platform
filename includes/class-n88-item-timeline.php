<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Commit 3.A.1 — Item Timeline Spine (Immutable, Read-Only + Minimal Enforcement)
 *
 * One timeline per item with 6 locked steps. Designer/supplier read-only; operator can start/complete steps.
 */
class N88_Item_Timeline {

    const STEP_LABELS = array(
        1 => 'Design & Specifications',
        2 => 'Technical Review & Documentation',
        3 => 'Pre-Production Approval',
        4 => 'Production / Fabrication',
        5 => 'Quality Review & Packing',
        6 => 'Ready for Delivery',
    );

    const STATUS_PENDING    = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_DELAYED    = 'delayed'; // derived when expected_by passed and not completed

    /**
     * Ensure a timeline exists for the item (create if missing).
     *
     * @param int $item_id n88_items.id
     * @return int|null timeline_id or null on failure
     */
    public static function ensure_timeline_for_item( $item_id ) {
        global $wpdb;
        $item_id = absint( $item_id );
        if ( ! $item_id ) {
            return null;
        }

        $timelines_table = $wpdb->prefix . 'n88_item_timelines';
        $steps_table    = $wpdb->prefix . 'n88_item_timeline_steps';

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT timeline_id FROM {$timelines_table} WHERE item_id = %d",
            $item_id
        ) );

        if ( $existing ) {
            return (int) $existing;
        }

        $now = current_time( 'mysql' );
        $r   = $wpdb->insert( $timelines_table, array(
            'item_id'    => $item_id,
            'created_at' => $now,
        ), array( '%d', '%s' ) );

        if ( ! $r ) {
            return null;
        }

        $timeline_id = $wpdb->insert_id;

        foreach ( self::STEP_LABELS as $step_number => $label ) {
            $wpdb->insert( $steps_table, array(
                'timeline_id'       => $timeline_id,
                'step_number'       => $step_number,
                'label'             => $label,
                'status'            => self::STATUS_PENDING,
                'evidence_required' => 1,
            ), array( '%d', '%d', '%s', '%s', '%d' ) );
        }

        if ( function_exists( 'n88_log_event' ) ) {
            n88_log_event( 'timeline_created', 'item', array(
                'item_id'     => $item_id,
                'object_id'   => $timeline_id,
                'payload_json' => array(
                    'item_id'    => $item_id,
                    'timeline_id' => $timeline_id,
                    'step_count' => 6,
                    'actor_role' => 'system',
                    'timestamp'  => $now,
                ),
            ) );
        }

        return $timeline_id;
    }

    /**
     * Get timeline and steps for an item (read-only view; delayed computed).
     *
     * @param int $item_id n88_items.id
     * @return array { timeline_id, item_id, created_at, steps: [ { step_id, step_number, label, status, display_status, started_at, completed_at, expected_by, is_delayed, evidence_required, evidence_verified_at } ], show_prototype_mini: bool }
     */
    public static function get_timeline_for_item( $item_id ) {
        global $wpdb;
        $item_id = absint( $item_id );
        if ( ! $item_id ) {
            return array( 'timeline_id' => null, 'item_id' => $item_id, 'steps' => array(), 'show_prototype_mini' => false );
        }

        $timeline_id = self::ensure_timeline_for_item( $item_id );
        if ( ! $timeline_id ) {
            return array( 'timeline_id' => null, 'item_id' => $item_id, 'steps' => array(), 'show_prototype_mini' => false );
        }

        $timelines_table = $wpdb->prefix . 'n88_item_timelines';
        $steps_table     = $wpdb->prefix . 'n88_item_timeline_steps';

        $timeline = $wpdb->get_row( $wpdb->prepare(
            "SELECT timeline_id, item_id, created_at FROM {$timelines_table} WHERE timeline_id = %d",
            $timeline_id
        ), ARRAY_A );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT step_id, timeline_id, step_number, label, status, started_at, completed_at, expected_by, evidence_required, evidence_verified_at, evidence_verified_by FROM {$steps_table} WHERE timeline_id = %d ORDER BY step_number ASC",
            $timeline_id
        ), ARRAY_A );

        $now_date = current_time( 'Y-m-d' );
        $steps = array();
        foreach ( $rows as $row ) {
            $expected_by = $row['expected_by'] ? trim( (string) $row['expected_by'] ) : null;
            $completed_at = $row['completed_at'] ? trim( (string) $row['completed_at'] ) : null;
            $is_delayed = ( $expected_by && ! $completed_at && $expected_by < $now_date );
            $steps[] = array(
                'step_id'              => (int) $row['step_id'],
                'step_number'          => (int) $row['step_number'],
                'label'                => $row['label'],
                'status'               => $row['status'],
                'display_status'       => $is_delayed ? self::STATUS_DELAYED : $row['status'],
                'started_at'           => $row['started_at'],
                'completed_at'         => $row['completed_at'],
                'expected_by'          => $row['expected_by'],
                'is_delayed'           => $is_delayed,
                'evidence_required'    => ! empty( $row['evidence_required'] ),
                'evidence_verified_at' => $row['evidence_verified_at'],
                'evidence_verified_by' => $row['evidence_verified_by'] ? (int) $row['evidence_verified_by'] : null,
            );
        }

        $show_prototype_mini = self::item_has_prototype_payment_gate_cleared( $item_id );

        return array(
            'timeline_id'         => (int) $timeline_id,
            'item_id'             => $item_id,
            'created_at'          => $timeline['created_at'],
            'steps'               => $steps,
            'show_prototype_mini' => $show_prototype_mini,
        );
    }

    /**
     * Whether prototype payment gate is cleared (marked_received) for this item — for showing mini-timeline under Step 3.
     */
    public static function item_has_prototype_payment_gate_cleared( $item_id ) {
        global $wpdb;
        $payments_table = $wpdb->prefix . 'n88_prototype_payments';
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$payments_table} WHERE item_id = %d AND status = 'marked_received' AND received_at IS NOT NULL",
            $item_id
        ) );
        return $count > 0;
    }

    /**
     * Check if required evidence exists for completing a step.
     * Steps 1-3: Phase 3 compatible checks (payment, CAD, prototype).
     * Steps 4-6: evidence_verified_at fallback until their workflows exist.
     *
     * @param int $item_id
     * @param int $step_number 1-6
     * @return bool
     */
    public static function has_required_evidence( $item_id, $step_number ) {
        global $wpdb;
        $steps_table = $wpdb->prefix . 'n88_item_timeline_steps';
        $timelines_table = $wpdb->prefix . 'n88_item_timelines';
        $payments_table = $wpdb->prefix . 'n88_prototype_payments';

        $timeline_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT timeline_id FROM {$timelines_table} WHERE item_id = %d",
            $item_id
        ) );
        if ( ! $timeline_id ) {
            return false;
        }

        $step = $wpdb->get_row( $wpdb->prepare(
            "SELECT evidence_required, evidence_verified_at FROM {$steps_table} WHERE timeline_id = %d AND step_number = %d",
            $timeline_id,
            $step_number
        ), ARRAY_A );

        if ( ! $step || ! $step['evidence_required'] ) {
            return true;
        }

        if ( ! empty( $step['evidence_verified_at'] ) ) {
            return true;
        }

        // Step-specific evidence checks (Phase 3 compatible)
        if ( $step_number === 1 ) {
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$payments_table} WHERE item_id = %d AND status = 'marked_received' AND received_at IS NOT NULL",
                $item_id
            ) );
            return $count > 0;
        }
        if ( $step_number === 2 ) {
            $pp_cols = $wpdb->get_col( "DESCRIBE {$payments_table}" );
            $has_cad_approved_at = is_array( $pp_cols ) && in_array( 'cad_approved_at', $pp_cols, true );
            $has_cad_approved_version = is_array( $pp_cols ) && in_array( 'cad_approved_version', $pp_cols, true );
            if ( $has_cad_approved_at ) {
                $count = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$payments_table} WHERE item_id = %d AND cad_status = 'approved' AND cad_approved_at IS NOT NULL",
                    $item_id
                ) );
            } elseif ( $has_cad_approved_version ) {
                $count = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$payments_table} WHERE item_id = %d AND cad_status = 'approved' AND cad_approved_version IS NOT NULL AND cad_approved_version > 0",
                    $item_id
                ) );
            } else {
                $count = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$payments_table} WHERE item_id = %d AND cad_status = 'approved'",
                    $item_id
                ) );
            }
            return $count > 0;
        }
        if ( $step_number === 3 ) {
            $pp_columns = $wpdb->get_col( "DESCRIBE {$payments_table}" );
            $has_prototype_approved = in_array( 'prototype_status', $pp_columns, true );
            if ( $has_prototype_approved ) {
                $count = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$payments_table} WHERE item_id = %d AND prototype_status = 'approved'",
                    $item_id
                ) );
                return $count > 0;
            }
            $has_approved_version = in_array( 'prototype_approved_version', $pp_columns, true );
            if ( $has_approved_version ) {
                $count = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$payments_table} WHERE item_id = %d AND prototype_approved_version IS NOT NULL AND prototype_approved_version > 0",
                    $item_id
                ) );
                return $count > 0;
            }
        }

        return false;
    }

    /**
     * Operator: start a step (pending → in_progress).
     *
     * @param int $item_id
     * @param int $step_number 1-6
     * @param int $operator_user_id
     * @return array { success, message }
     */
    public static function start_step( $item_id, $step_number, $operator_user_id = null ) {
        global $wpdb;
        if ( $operator_user_id === null ) {
            $operator_user_id = get_current_user_id();
        }
        $item_id = absint( $item_id );
        $step_number = absint( $step_number );
        if ( $step_number < 1 || $step_number > 6 ) {
            return array( 'success' => false, 'message' => 'Invalid step number.' );
        }

        $timeline_id = self::ensure_timeline_for_item( $item_id );
        if ( ! $timeline_id ) {
            return array( 'success' => false, 'message' => 'Timeline not found.' );
        }

        $steps_table = $wpdb->prefix . 'n88_item_timeline_steps';
        $step = $wpdb->get_row( $wpdb->prepare(
            "SELECT step_id, status FROM {$steps_table} WHERE timeline_id = %d AND step_number = %d",
            $timeline_id,
            $step_number
        ), ARRAY_A );

        if ( ! $step ) {
            return array( 'success' => false, 'message' => 'Step not found.' );
        }
        if ( $step['status'] !== self::STATUS_PENDING ) {
            return array( 'success' => false, 'message' => 'Step is not pending.' );
        }

        $now = current_time( 'mysql' );
        $updated = $wpdb->update(
            $steps_table,
            array( 'status' => self::STATUS_IN_PROGRESS, 'started_at' => $now ),
            array( 'step_id' => $step['step_id'] ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            return array( 'success' => false, 'message' => 'Database error.' );
        }

        if ( function_exists( 'n88_log_event' ) ) {
            n88_log_event( 'timeline_step_started', 'item', array(
                'item_id'     => $item_id,
                'object_id'   => $step['step_id'],
                'payload_json' => array(
                    'item_id'           => $item_id,
                    'step_number'       => $step_number,
                    'operator_user_id'  => $operator_user_id,
                    'timestamp'         => $now,
                ),
            ) );
        }

        return array( 'success' => true, 'message' => 'Step started.' );
    }

    /**
     * Operator: complete a step (in_progress → completed). Blocked if evidence required and not verified.
     *
     * @param int $item_id
     * @param int $step_number 1-6
     * @param int $operator_user_id
     * @param bool $evidence_verified_override If true, treat as evidence verified (v1 fallback).
     * @return array { success, message }
     */
    public static function complete_step( $item_id, $step_number, $operator_user_id = null, $evidence_verified_override = false ) {
        global $wpdb;
        if ( $operator_user_id === null ) {
            $operator_user_id = get_current_user_id();
        }
        $item_id = absint( $item_id );
        $step_number = absint( $step_number );
        if ( $step_number < 1 || $step_number > 6 ) {
            return array( 'success' => false, 'message' => 'Invalid step number.' );
        }

        $timeline_id = self::ensure_timeline_for_item( $item_id );
        if ( ! $timeline_id ) {
            return array( 'success' => false, 'message' => 'Timeline not found.' );
        }

        $steps_table = $wpdb->prefix . 'n88_item_timeline_steps';
        $step = $wpdb->get_row( $wpdb->prepare(
            "SELECT step_id, status, evidence_required, evidence_verified_at FROM {$steps_table} WHERE timeline_id = %d AND step_number = %d",
            $timeline_id,
            $step_number
        ), ARRAY_A );

        if ( ! $step ) {
            return array( 'success' => false, 'message' => 'Step not found.' );
        }
        if ( $step['status'] !== self::STATUS_IN_PROGRESS ) {
            return array( 'success' => false, 'message' => 'Step is not in progress.' );
        }

        $evidence_ok = $evidence_verified_override || ! $step['evidence_required'] || ! empty( $step['evidence_verified_at'] );
        if ( ! $evidence_ok ) {
            return array( 'success' => false, 'message' => 'Evidence required to complete this step.', 'blocked' => true );
        }

        $now = current_time( 'mysql' );
        $update_data = array(
            'status'       => self::STATUS_COMPLETED,
            'completed_at' => $now,
        );
        if ( $evidence_verified_override && empty( $step['evidence_verified_at'] ) ) {
            $update_data['evidence_verified_at'] = $now;
            $update_data['evidence_verified_by'] = $operator_user_id;
        }

        $updated = $wpdb->update(
            $steps_table,
            $update_data,
            array( 'step_id' => $step['step_id'] ),
            null,
            array( '%d' )
        );

        if ( $updated === false ) {
            return array( 'success' => false, 'message' => 'Database error.' );
        }

        if ( function_exists( 'n88_log_event' ) ) {
            n88_log_event( 'timeline_step_completed', 'item', array(
                'item_id'     => $item_id,
                'object_id'   => $step['step_id'],
                'payload_json' => array(
                    'item_id'             => $item_id,
                    'step_number'         => $step_number,
                    'operator_user_id'    => $operator_user_id,
                    'evidence_verified_at' => isset( $update_data['evidence_verified_at'] ) ? $update_data['evidence_verified_at'] : $step['evidence_verified_at'],
                    'timestamp'           => $now,
                ),
            ) );
        }

        return array( 'success' => true, 'message' => 'Step completed.' );
    }

    /**
     * Mark evidence as verified for a step (operator fallback when no evidence model yet).
     *
     * @param int $item_id
     * @param int $step_number
     * @param int $operator_user_id
     * @return array { success, message }
     */
    public static function set_evidence_verified( $item_id, $step_number, $operator_user_id = null ) {
        global $wpdb;
        if ( $operator_user_id === null ) {
            $operator_user_id = get_current_user_id();
        }
        $timeline_id = self::ensure_timeline_for_item( $item_id );
        if ( ! $timeline_id ) {
            return array( 'success' => false, 'message' => 'Timeline not found.' );
        }

        $steps_table = $wpdb->prefix . 'n88_item_timeline_steps';
        $step = $wpdb->get_row( $wpdb->prepare(
            "SELECT step_id FROM {$steps_table} WHERE timeline_id = %d AND step_number = %d",
            $timeline_id,
            $step_number
        ), ARRAY_A );

        if ( ! $step ) {
            return array( 'success' => false, 'message' => 'Step not found.' );
        }

        $now = current_time( 'mysql' );
        $wpdb->update(
            $steps_table,
            array(
                'evidence_verified_at' => $now,
                'evidence_verified_by' => $operator_user_id,
            ),
            array( 'step_id' => $step['step_id'] ),
            array( '%s', '%d' ),
            array( '%d' )
        );

        return array( 'success' => true, 'message' => 'Evidence marked verified.' );
    }

    /**
     * Sync step 1 start: when designer submits CAD/prototype request.
     * Called from cad_prototype_requested handler.
     */
    public static function sync_from_cad_prototype_requested( $item_id ) {
        $timeline = self::get_timeline_for_item( $item_id );
        if ( empty( $timeline['steps'] ) ) {
            return;
        }
        $step1 = $timeline['steps'][0];
        if ( $step1['status'] === self::STATUS_PENDING ) {
            self::start_step( $item_id, 1, 0 );
        }
    }

    /**
     * Sync step 1 complete: when operator marks prototype payment received.
     * Step 2 starts on cad_uploaded (operator sends CAD to designer).
     * Called from prototype_payment_marked_received handler.
     */
    public static function sync_from_payment_marked_received( $item_id ) {
        $timeline = self::get_timeline_for_item( $item_id );
        if ( empty( $timeline['steps'] ) ) {
            return;
        }
        $step1 = $timeline['steps'][0];
        if ( $step1['status'] === self::STATUS_IN_PROGRESS ) {
            self::complete_step( $item_id, 1, 0, true );
        }
    }

    /**
     * Sync step 2 start: when operator uploads/sends CAD files to designer.
     * Call when cad_uploaded event is logged.
     */
    public static function sync_from_cad_uploaded( $item_id ) {
        $timeline = self::get_timeline_for_item( $item_id );
        if ( empty( $timeline['steps'] ) ) {
            return;
        }
        $step2 = isset( $timeline['steps'][1] ) ? $timeline['steps'][1] : null;
        if ( $step2 && $step2['status'] === self::STATUS_PENDING ) {
            self::start_step( $item_id, 2, 0 );
        }
    }

    /**
     * Sync step 2: complete when designer approves CAD; then start step 3.
     * Call when cad_approved event is logged.
     */
    public static function sync_from_cad_approved( $item_id ) {
        $timeline = self::get_timeline_for_item( $item_id );
        if ( empty( $timeline['steps'] ) ) {
            return;
        }
        $step2 = isset( $timeline['steps'][1] ) ? $timeline['steps'][1] : null;
        if ( $step2 && $step2['status'] === self::STATUS_IN_PROGRESS ) {
            self::complete_step( $item_id, 2, 0, true );
            self::start_step( $item_id, 3, 0 );
        }
    }

    /**
     * Sync step 3: complete when prototype video approved by designer.
     * Call when prototype_video_approved event is logged.
     */
    public static function sync_from_prototype_approved( $item_id ) {
        $timeline = self::get_timeline_for_item( $item_id );
        if ( empty( $timeline['steps'] ) ) {
            return;
        }
        $step3 = isset( $timeline['steps'][2] ) ? $timeline['steps'][2] : null;
        if ( $step3 && $step3['status'] === self::STATUS_IN_PROGRESS ) {
            self::complete_step( $item_id, 3, 0, true );
        }
    }
}
