<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Commit 3.A.3 — Evidence Comments (anchored to evidence media, immutable, append-only).
 * Designer may add; Designer + Operator/Admin may view. No edit, delete, or replies.
 */
class N88_Evidence_Comments {

    /**
     * Add a comment anchored to an evidence record. Designer only (v1). Immutable — no updates.
     *
     * @param int    $evidence_id  n88_timeline_step_evidence.id
     * @param string $comment_text Comment content (sanitized)
     * @param int    $created_by   User ID (default current user)
     * @return array { success, message, comment_id }
     */
    public static function add_comment( $evidence_id, $comment_text, $created_by = null ) {
        global $wpdb;
        $evidence_id  = absint( $evidence_id );
        $comment_text = is_string( $comment_text ) ? trim( $comment_text ) : '';
        if ( ! $evidence_id ) {
            return array( 'success' => false, 'message' => 'Invalid evidence.' );
        }
        if ( $comment_text === '' ) {
            return array( 'success' => false, 'message' => 'Comment text is required.' );
        }
        $comment_text = sanitize_textarea_field( $comment_text );
        $comment_text = substr( $comment_text, 0, 65535 );
        if ( $comment_text === '' ) {
            return array( 'success' => false, 'message' => 'Comment text is required.' );
        }

        $evidence_row = N88_Timeline_Step_Evidence::get_evidence_by_id( $evidence_id );
        if ( ! $evidence_row ) {
            return array( 'success' => false, 'message' => 'Evidence not found.' );
        }

        if ( $created_by === null ) {
            $created_by = get_current_user_id();
        }
        $created_by = absint( $created_by );
        if ( ! $created_by ) {
            return array( 'success' => false, 'message' => 'Invalid user.' );
        }

        $comments_table = $wpdb->prefix . 'n88_evidence_comments';
        $r = $wpdb->insert(
            $comments_table,
            array(
                'evidence_id'  => $evidence_id,
                'comment_text' => $comment_text,
                'created_at'   => current_time( 'mysql' ),
                'created_by'   => $created_by,
            ),
            array( '%d', '%s', '%s', '%d' )
        );

        if ( ! $r ) {
            return array( 'success' => false, 'message' => 'Failed to save comment.' );
        }

        $comment_id = (int) $wpdb->insert_id;
        return array( 'success' => true, 'message' => 'Comment added.', 'comment_id' => $comment_id );
    }

    /**
     * Get all comments for an evidence record. Chronological order (oldest first).
     * Caller must ensure user has access to the evidence's item.
     *
     * @param int $evidence_id n88_timeline_step_evidence.id
     * @return array List of { id, evidence_id, comment_text, created_at, created_by }
     */
    public static function get_comments_for_evidence( $evidence_id ) {
        global $wpdb;
        $evidence_id = absint( $evidence_id );
        if ( ! $evidence_id ) {
            return array();
        }
        $comments_table = $wpdb->prefix . 'n88_evidence_comments';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, evidence_id, comment_text, created_at, created_by FROM {$comments_table} WHERE evidence_id = %d ORDER BY created_at ASC",
                $evidence_id
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Get comments for multiple evidence IDs. Returns keyed by evidence_id.
     *
     * @param int[] $evidence_ids
     * @return array [ evidence_id => [ comment rows ] ]
     */
    public static function get_comments_for_evidence_batch( $evidence_ids ) {
        global $wpdb;
        $evidence_ids = array_filter( array_map( 'absint', $evidence_ids ) );
        if ( empty( $evidence_ids ) ) {
            return array();
        }
        $comments_table = $wpdb->prefix . 'n88_evidence_comments';
        $placeholders   = implode( ',', array_fill( 0, count( $evidence_ids ), '%d' ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, evidence_id, comment_text, created_at, created_by FROM {$comments_table} WHERE evidence_id IN ({$placeholders}) ORDER BY evidence_id ASC, created_at ASC",
                ...$evidence_ids
            ),
            ARRAY_A
        );
        $by_evidence = array();
        foreach ( $evidence_ids as $eid ) {
            $by_evidence[ $eid ] = array();
        }
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $eid = (int) $row['evidence_id'];
                if ( ! isset( $by_evidence[ $eid ] ) ) {
                    $by_evidence[ $eid ] = array();
                }
                $by_evidence[ $eid ][] = $row;
            }
        }
        return $by_evidence;
    }
}
