<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Commit 3.A.2S — Supplier step evidence (video links only: YouTube, Vimeo, Loom).
 * Append-only, versioned per (item_id, timeline_step_id, supplier_id).
 */
class N88_Step_Evidence_Submissions {

    const PROVIDER_YOUTUBE = 'youtube';
    const PROVIDER_VIMEO   = 'vimeo';
    const PROVIDER_LOOM    = 'loom';

    const MAX_LINKS_PER_SUBMISSION = 3;
    const MIN_LINKS_PER_SUBMISSION = 1;

    /**
     * Validate URL and return provider (youtube|vimeo|loom) or null.
     *
     * @param string $url
     * @return string|null
     */
    public static function validate_video_url( $url ) {
        $url = trim( $url );
        if ( $url === '' ) {
            return null;
        }
        $url = esc_url_raw( $url );
        if ( ! $url ) {
            return null;
        }
        $host = strtolower( parse_url( $url, PHP_URL_HOST ) ?: '' );
        if ( preg_match( '#(?:^|\.)(youtube\.com|youtu\.be)$#', $host ) ) {
            return self::PROVIDER_YOUTUBE;
        }
        if ( preg_match( '#(?:^|\.)vimeo\.com$#', $host ) ) {
            return self::PROVIDER_VIMEO;
        }
        if ( preg_match( '#(?:^|\.)loom\.com$#', $host ) ) {
            return self::PROVIDER_LOOM;
        }
        return null;
    }

    /**
     * Submit supplier step evidence (1–3 video links). Append-only; creates version N+1.
     * Caller must ensure: supplier is routed to item, step is in_progress or completed.
     *
     * @param int   $item_id   n88_items.id
     * @param int   $step_id   n88_item_timeline_steps.step_id
     * @param int   $supplier_id User ID of supplier
     * @param int|null $bid_id Optional bid_id
     * @param array $urls     Array of 1–3 video URLs (validated)
     * @return array { success, message, submission_id, version }
     */
    public static function submit( $item_id, $step_id, $supplier_id, $bid_id, $urls ) {
        global $wpdb;
        $item_id     = absint( $item_id );
        $step_id     = absint( $step_id );
        $supplier_id = absint( $supplier_id );
        $bid_id      = $bid_id !== null ? absint( $bid_id ) : null;

        if ( ! $item_id || ! $step_id || ! $supplier_id ) {
            return array( 'success' => false, 'message' => 'Invalid item, step, or supplier.' );
        }

        $links = array();
        foreach ( (array) $urls as $url ) {
            $url = is_string( $url ) ? trim( $url ) : '';
            if ( $url === '' ) {
                continue;
            }
            $provider = self::validate_video_url( $url );
            if ( ! $provider ) {
                return array( 'success' => false, 'message' => 'Allowed providers: YouTube, Vimeo, Loom. Invalid URL: ' . substr( $url, 0, 50 ) . '…' );
            }
            $links[] = array( 'provider' => $provider, 'url' => esc_url_raw( $url ) );
        }

        $count = count( $links );
        if ( $count < self::MIN_LINKS_PER_SUBMISSION || $count > self::MAX_LINKS_PER_SUBMISSION ) {
            return array( 'success' => false, 'message' => 'Submit between 1 and 3 video links.' );
        }

        $submissions_table = $wpdb->prefix . 'n88_step_evidence_submissions';
        $next_version = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(version), 0) + 1 FROM {$submissions_table} WHERE item_id = %d AND timeline_step_id = %d AND supplier_id = %d",
            $item_id,
            $step_id,
            $supplier_id
        ) );

        $r = $wpdb->insert(
            $submissions_table,
            array(
                'item_id'             => $item_id,
                'timeline_step_id'    => $step_id,
                'supplier_id'         => $supplier_id,
                'bid_id'              => $bid_id,
                'version'             => $next_version,
                'link_count'          => $count,
                'created_at'          => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
        );

        if ( ! $r ) {
            return array( 'success' => false, 'message' => 'Failed to save submission.' );
        }

        $submission_id = (int) $wpdb->insert_id;
        $links_table   = $wpdb->prefix . 'n88_step_evidence_links';
        foreach ( $links as $i => $link ) {
            $wpdb->insert(
                $links_table,
                array(
                    'submission_id' => $submission_id,
                    'provider'     => $link['provider'],
                    'url'          => $link['url'],
                    'sort_order'   => $i,
                ),
                array( '%d', '%s', '%s', '%d' )
            );
        }

        if ( function_exists( 'n88_log_event' ) ) {
            n88_log_event( 'step_evidence_submitted', 'item', array(
                'item_id'     => $item_id,
                'object_id'   => $submission_id,
                'payload_json' => array(
                    'item_id'             => $item_id,
                    'timeline_step_id'    => $step_id,
                    'supplier_id'         => $supplier_id,
                    'bid_id'              => $bid_id,
                    'version'             => $next_version,
                    'link_count'          => $count,
                    'timestamp'           => current_time( 'mysql' ),
                ),
            ) );
        }

        return array( 'success' => true, 'message' => 'Evidence submitted.', 'submission_id' => $submission_id, 'version' => $next_version );
    }

    /**
     * Get latest submission for a supplier on a step (for supplier UI: "Evidence submitted (vN)").
     *
     * @param int $item_id
     * @param int $step_id
     * @param int $supplier_id
     * @return array|null { id, version, link_count, created_at, links: [ { provider, url } ] } or null
     */
    public static function get_latest_for_supplier_step( $item_id, $step_id, $supplier_id ) {
        global $wpdb;
        $item_id     = absint( $item_id );
        $step_id     = absint( $step_id );
        $supplier_id = absint( $supplier_id );
        if ( ! $item_id || ! $step_id || ! $supplier_id ) {
            return null;
        }
        $submissions_table = $wpdb->prefix . 'n88_step_evidence_submissions';
        $links_table       = $wpdb->prefix . 'n88_step_evidence_links';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, version, link_count, created_at FROM {$submissions_table} WHERE item_id = %d AND timeline_step_id = %d AND supplier_id = %d ORDER BY version DESC LIMIT 1",
            $item_id,
            $step_id,
            $supplier_id
        ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }
        $links = $wpdb->get_results( $wpdb->prepare(
            "SELECT provider, url FROM {$links_table} WHERE submission_id = %d ORDER BY sort_order ASC",
            $row['id']
        ), ARRAY_A );
        $row['links'] = is_array( $links ) ? $links : array();
        return $row;
    }

    /**
     * Get all submissions for a step for supplier (own only). Returns list of submissions (versions) with links.
     *
     * @param int $item_id
     * @param int $step_id
     * @param int $supplier_id
     * @return array
     */
    public static function get_all_for_supplier_step( $item_id, $step_id, $supplier_id ) {
        global $wpdb;
        $item_id     = absint( $item_id );
        $step_id     = absint( $step_id );
        $supplier_id = absint( $supplier_id );
        if ( ! $item_id || ! $step_id || ! $supplier_id ) {
            return array();
        }
        $submissions_table = $wpdb->prefix . 'n88_step_evidence_submissions';
        $links_table       = $wpdb->prefix . 'n88_step_evidence_links';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, version, link_count, created_at FROM {$submissions_table} WHERE item_id = %d AND timeline_step_id = %d AND supplier_id = %d ORDER BY version ASC",
            $item_id,
            $step_id,
            $supplier_id
        ), ARRAY_A );
        $out = array();
        foreach ( (array) $rows as $row ) {
            $links = $wpdb->get_results( $wpdb->prepare(
                "SELECT provider, url FROM {$links_table} WHERE submission_id = %d ORDER BY sort_order ASC",
                $row['id']
            ), ARRAY_A );
            $row['links'] = is_array( $links ) ? $links : array();
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Get supplier evidence for all steps of an item (for one supplier). Keyed by step_id.
     * Returns for each step: latest only { version, link_count, created_at, links } or null.
     *
     * @param int $item_id
     * @param int $supplier_id
     * @return array [ step_id => { version, link_count, created_at, links } ]
     */
    public static function get_by_item_for_supplier( $item_id, $supplier_id ) {
        global $wpdb;
        $item_id     = absint( $item_id );
        $supplier_id = absint( $supplier_id );
        if ( ! $item_id || ! $supplier_id ) {
            return array();
        }
        $submissions_table = $wpdb->prefix . 'n88_step_evidence_submissions';
        $links_table       = $wpdb->prefix . 'n88_step_evidence_links';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, timeline_step_id, version, link_count, created_at FROM {$submissions_table} WHERE item_id = %d AND supplier_id = %d ORDER BY timeline_step_id ASC, version DESC",
            $item_id,
            $supplier_id
        ), ARRAY_A );
        $by_step = array();
        foreach ( (array) $rows as $row ) {
            $step_id = (int) $row['timeline_step_id'];
            if ( isset( $by_step[ $step_id ] ) ) {
                continue;
            }
            $links = $wpdb->get_results( $wpdb->prepare(
                "SELECT provider, url FROM {$links_table} WHERE submission_id = %d ORDER BY sort_order ASC",
                $row['id']
            ), ARRAY_A );
            $by_step[ $step_id ] = array(
                'version'     => (int) $row['version'],
                'link_count'  => (int) $row['link_count'],
                'created_at'  => $row['created_at'],
                'links'       => is_array( $links ) ? $links : array(),
            );
        }
        return $by_step;
    }

    /**
     * Get evidence for a step for designer/operator view.
     * Designer: no supplier identity (label "Supplier Evidence Received", links only).
     * Operator: full (submissions with supplier_id, or at least links grouped).
     *
     * @param int  $item_id
     * @param int  $step_id
     * @param bool $for_designer If true, do not expose supplier identity.
     * @return array { has_evidence: bool, submissions: [ { version, link_count, created_at, links[, supplier_id? ] } ] }
     */
    public static function get_for_step_view( $item_id, $step_id, $for_designer = false ) {
        global $wpdb;
        $item_id = absint( $item_id );
        $step_id = absint( $step_id );
        if ( ! $item_id || ! $step_id ) {
            return array( 'has_evidence' => false, 'submissions' => array() );
        }
        $submissions_table = $wpdb->prefix . 'n88_step_evidence_submissions';
        $links_table       = $wpdb->prefix . 'n88_step_evidence_links';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, supplier_id, version, link_count, created_at FROM {$submissions_table} WHERE item_id = %d AND timeline_step_id = %d ORDER BY created_at ASC",
            $item_id,
            $step_id
        ), ARRAY_A );
        if ( empty( $rows ) ) {
            return array( 'has_evidence' => false, 'submissions' => array() );
        }
        $submissions = array();
        foreach ( $rows as $row ) {
            $links = $wpdb->get_results( $wpdb->prepare(
                "SELECT provider, url FROM {$links_table} WHERE submission_id = %d ORDER BY sort_order ASC",
                $row['id']
            ), ARRAY_A );
            $rec = array(
                'version'    => (int) $row['version'],
                'link_count' => (int) $row['link_count'],
                'created_at' => $row['created_at'],
                'links'      => is_array( $links ) ? $links : array(),
            );
            if ( ! $for_designer ) {
                $rec['supplier_id'] = (int) $row['supplier_id'];
            }
            $submissions[] = $rec;
        }
        return array( 'has_evidence' => true, 'submissions' => $submissions );
    }

    /**
     * Get step IDs that have at least one supplier evidence submission (for designer/operator badges).
     *
     * @param int $item_id
     * @return array Associative array step_id => true for steps with supplier evidence
     */
    public static function get_steps_with_supplier_evidence( $item_id ) {
        global $wpdb;
        $item_id = absint( $item_id );
        if ( ! $item_id ) {
            return array();
        }
        $submissions_table = $wpdb->prefix . 'n88_step_evidence_submissions';
        $step_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT timeline_step_id FROM {$submissions_table} WHERE item_id = %d",
            $item_id
        ) );
        $out = array();
        foreach ( (array) $step_ids as $sid ) {
            $out[ (int) $sid ] = true;
        }
        return $out;
    }

    /**
     * Check if step allows supplier submission (step must be in_progress or completed).
     *
     * @param int $step_id
     * @return bool
     */
    public static function step_allows_submission( $step_id ) {
        global $wpdb;
        $step_id = absint( $step_id );
        if ( ! $step_id ) {
            return false;
        }
        $steps_table = $wpdb->prefix . 'n88_item_timeline_steps';
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$steps_table} WHERE step_id = %d",
            $step_id
        ) );
        return $status === 'in_progress' || $status === 'completed';
    }
}
