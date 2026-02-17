<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Commit 3.B.5.A1 — Step 4–6 video submissions (supplier + operator).
 * Append-only, versioned per (item_id, step_number). Max 3 links per submission.
 */
class N88_Timeline_Step_Videos {

    const ALLOWED_STEPS = array( 4, 5, 6 );
    const PROVIDER_YOUTUBE = 'youtube';
    const PROVIDER_VIMEO   = 'vimeo';
    const PROVIDER_LOOM    = 'loom';
    const MAX_LINKS_PER_SUBMISSION = 3;
    const MIN_LINKS_PER_SUBMISSION = 1;

    /**
     * Validate URL: YouTube, Vimeo, Loom only.
     *
     * @param string $url
     * @return string|null Provider key or null if invalid.
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
     * Submit supplier video for step 4, 5, or 6. Append-only; new version.
     *
     * @param int    $item_id       n88_items.id
     * @param int    $step_number   4, 5, or 6
     * @param int    $supplier_id   User ID
     * @param array  $urls          Array of 1–3 video URLs
     * @param string $optional_note Optional note (visible to operator + designer)
     * @return array { success, message, submission_id, version }
     */
    public static function submit_supplier( $item_id, $step_number, $supplier_id, $urls, $optional_note = '' ) {
        global $wpdb;
        $item_id     = absint( $item_id );
        $step_number = absint( $step_number );
        $supplier_id = absint( $supplier_id );
        if ( ! in_array( $step_number, self::ALLOWED_STEPS, true ) ) {
            return array( 'success' => false, 'message' => 'Video submission only allowed for Steps 4, 5, and 6.' );
        }
        if ( ! $item_id || ! $supplier_id ) {
            return array( 'success' => false, 'message' => 'Invalid item or supplier.' );
        }

        $links = array();
        foreach ( (array) $urls as $url ) {
            $url = is_string( $url ) ? trim( $url ) : '';
            if ( $url === '' ) {
                continue;
            }
            $provider = self::validate_video_url( $url );
            if ( ! $provider ) {
                return array( 'success' => false, 'message' => 'Allowed providers: YouTube, Vimeo, Loom. Invalid URL.' );
            }
            $links[] = array( 'provider' => $provider, 'url' => esc_url_raw( $url ) );
        }
        $count = count( $links );
        if ( $count < self::MIN_LINKS_PER_SUBMISSION || $count > self::MAX_LINKS_PER_SUBMISSION ) {
            return array( 'success' => false, 'message' => 'Submit between 1 and 3 video links.' );
        }

        $sub_table  = $wpdb->prefix . 'n88_timeline_step_video_submissions';
        $links_table = $wpdb->prefix . 'n88_timeline_step_video_links';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sub_table}'" ) !== $sub_table ) {
            return array( 'success' => false, 'message' => 'Tables not available.' );
        }

        $next_version = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(version), 0) + 1 FROM {$sub_table} WHERE item_id = %d AND step_number = %d",
            $item_id,
            $step_number
        ) );

        $optional_note = is_string( $optional_note ) ? wp_kses_post( trim( $optional_note ) ) : '';
        $r = $wpdb->insert(
            $sub_table,
            array(
                'item_id'       => $item_id,
                'step_number'   => $step_number,
                'supplier_id'   => $supplier_id,
                'operator_id'   => null,
                'version'       => $next_version,
                'optional_note' => $optional_note !== '' ? $optional_note : null,
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
        );
        if ( ! $r ) {
            return array( 'success' => false, 'message' => 'Failed to save submission.' );
        }
        $submission_id = (int) $wpdb->insert_id;
        foreach ( $links as $i => $link ) {
            $wpdb->insert(
                $links_table,
                array(
                    'submission_id' => $submission_id,
                    'provider'      => $link['provider'],
                    'url'           => $link['url'],
                    'sort_order'    => $i,
                ),
                array( '%d', '%s', '%s', '%d' )
            );
        }

        if ( function_exists( 'n88_log_event' ) ) {
            n88_log_event( 'timeline_step_video_submitted', 'item', array(
                'item_id'      => $item_id,
                'object_id'    => $submission_id,
                'payload_json' => array(
                    'item_id'     => $item_id,
                    'step_number' => $step_number,
                    'supplier_id' => $supplier_id,
                    'version'     => $next_version,
                    'timestamp'   => current_time( 'mysql' ),
                ),
            ) );
        }

        return array( 'success' => true, 'message' => 'Video evidence submitted.', 'submission_id' => $submission_id, 'version' => $next_version );
    }

    /**
     * Operator: add video evidence for step 4, 5, or 6. Append-only; new version.
     *
     * @param int    $item_id       n88_items.id
     * @param int    $step_number   4, 5, or 6
     * @param int    $operator_id   User ID
     * @param array  $urls          Array of 1–3 video URLs
     * @param string $optional_note Optional note
     * @return array { success, message, submission_id, version }
     */
    public static function submit_operator( $item_id, $step_number, $operator_id, $urls, $optional_note = '' ) {
        global $wpdb;
        $item_id     = absint( $item_id );
        $step_number = absint( $step_number );
        $operator_id = absint( $operator_id );
        if ( ! in_array( $step_number, self::ALLOWED_STEPS, true ) ) {
            return array( 'success' => false, 'message' => 'Video evidence only for Steps 4, 5, and 6.' );
        }
        if ( ! $item_id || ! $operator_id ) {
            return array( 'success' => false, 'message' => 'Invalid item or operator.' );
        }

        $links = array();
        foreach ( (array) $urls as $url ) {
            $url = is_string( $url ) ? trim( $url ) : '';
            if ( $url === '' ) {
                continue;
            }
            $provider = self::validate_video_url( $url );
            if ( ! $provider ) {
                return array( 'success' => false, 'message' => 'Allowed providers: YouTube, Vimeo, Loom. Invalid URL.' );
            }
            $links[] = array( 'provider' => $provider, 'url' => esc_url_raw( $url ) );
        }
        $count = count( $links );
        if ( $count < self::MIN_LINKS_PER_SUBMISSION || $count > self::MAX_LINKS_PER_SUBMISSION ) {
            return array( 'success' => false, 'message' => 'Submit between 1 and 3 video links.' );
        }

        $sub_table  = $wpdb->prefix . 'n88_timeline_step_video_submissions';
        $links_table = $wpdb->prefix . 'n88_timeline_step_video_links';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sub_table}'" ) !== $sub_table ) {
            return array( 'success' => false, 'message' => 'Tables not available.' );
        }

        $next_version = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(version), 0) + 1 FROM {$sub_table} WHERE item_id = %d AND step_number = %d",
            $item_id,
            $step_number
        ) );

        $optional_note = is_string( $optional_note ) ? wp_kses_post( trim( $optional_note ) ) : '';
        $r = $wpdb->insert(
            $sub_table,
            array(
                'item_id'       => $item_id,
                'step_number'   => $step_number,
                'supplier_id'   => null,
                'operator_id'   => $operator_id,
                'version'       => $next_version,
                'optional_note' => $optional_note !== '' ? $optional_note : null,
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
        );
        if ( ! $r ) {
            return array( 'success' => false, 'message' => 'Failed to save submission.' );
        }
        $submission_id = (int) $wpdb->insert_id;
        foreach ( $links as $i => $link ) {
            $wpdb->insert(
                $links_table,
                array(
                    'submission_id' => $submission_id,
                    'provider'      => $link['provider'],
                    'url'           => $link['url'],
                    'sort_order'    => $i,
                ),
                array( '%d', '%s', '%s', '%d' )
            );
        }

        if ( function_exists( 'n88_log_event' ) ) {
            n88_log_event( 'timeline_step_video_added_by_operator', 'item', array(
                'item_id'      => $item_id,
                'object_id'    => $submission_id,
                'payload_json' => array(
                    'item_id'      => $item_id,
                    'step_number'  => $step_number,
                    'operator_id'  => $operator_id,
                    'timestamp'    => current_time( 'mysql' ),
                ),
            ) );
        }

        return array( 'success' => true, 'message' => 'Video evidence added.', 'submission_id' => $submission_id, 'version' => $next_version );
    }

    /**
     * Get all video submissions for a step (for operator/designer view). Ordered by version ASC.
     *
     * @param int $item_id     n88_items.id
     * @param int $step_number 4, 5, or 6
     * @return array List of { submission_id, version, source: 'supplier'|'operator', optional_note, created_at, links: [ { provider, url } ] }
     */
    public static function get_submissions_for_step( $item_id, $step_number ) {
        global $wpdb;
        $item_id     = absint( $item_id );
        $step_number = absint( $step_number );
        if ( ! $item_id || ! in_array( $step_number, self::ALLOWED_STEPS, true ) ) {
            return array();
        }
        $sub_table  = $wpdb->prefix . 'n88_timeline_step_video_submissions';
        $links_table = $wpdb->prefix . 'n88_timeline_step_video_links';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sub_table}'" ) !== $sub_table ) {
            return array();
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, version, supplier_id, operator_id, optional_note, created_at FROM {$sub_table} WHERE item_id = %d AND step_number = %d ORDER BY version ASC",
            $item_id,
            $step_number
        ), ARRAY_A );
        if ( empty( $rows ) ) {
            return array();
        }

        $out = array();
        foreach ( $rows as $row ) {
            $source = ! empty( $row['operator_id'] ) ? 'operator' : 'supplier';
            $links = $wpdb->get_results( $wpdb->prepare(
                "SELECT provider, url FROM {$links_table} WHERE submission_id = %d ORDER BY sort_order ASC",
                $row['id']
            ), ARRAY_A );
            $out[] = array(
                'submission_id'   => (int) $row['id'],
                'version'         => (int) $row['version'],
                'source'          => $source,
                'optional_note'   => $row['optional_note'],
                'created_at'      => $row['created_at'],
                'links'           => array_map( function ( $l ) {
                    return array( 'provider' => $l['provider'], 'url' => $l['url'] );
                }, $links ),
            );
        }
        return $out;
    }
}

/**
 * Commit 3.B.5.A1 — Designer step comments (Steps 4–6). Append-only, immutable.
 */
class N88_Timeline_Step_Comments {

    const ALLOWED_STEPS = array( 4, 5, 6 );

    /**
     * Add designer comment for a step. Immutable once submitted.
     *
     * @param int    $item_id      n88_items.id
     * @param int    $step_number  4, 5, or 6
     * @param int    $designer_id  User ID
     * @param string $comment_text Comment content
     * @param int|null $media_version Optional version of media this comment refers to
     * @return array { success, message, comment_id }
     */
    public static function add_comment( $item_id, $step_number, $designer_id, $comment_text, $media_version = null ) {
        global $wpdb;
        $item_id     = absint( $item_id );
        $step_number = absint( $step_number );
        $designer_id = absint( $designer_id );
        if ( ! in_array( $step_number, self::ALLOWED_STEPS, true ) ) {
            return array( 'success' => false, 'message' => 'Comments only for Steps 4, 5, and 6.' );
        }
        if ( ! $item_id || ! $designer_id ) {
            return array( 'success' => false, 'message' => 'Invalid item or designer.' );
        }
        $comment_text = is_string( $comment_text ) ? wp_kses_post( trim( $comment_text ) ) : '';
        if ( $comment_text === '' ) {
            return array( 'success' => false, 'message' => 'Comment text is required.' );
        }

        $table = $wpdb->prefix . 'n88_timeline_step_comments';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return array( 'success' => false, 'message' => 'Table not available.' );
        }

        $media_version = $media_version !== null ? absint( $media_version ) : null;
        $r = $wpdb->insert(
            $table,
            array(
                'item_id'       => $item_id,
                'step_number'   => $step_number,
                'designer_id'   => $designer_id,
                'media_version' => $media_version,
                'comment_text'  => $comment_text,
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%d', '%d', '%s', '%s' )
        );
        if ( ! $r ) {
            return array( 'success' => false, 'message' => 'Failed to save comment.' );
        }
        $comment_id = (int) $wpdb->insert_id;

        if ( function_exists( 'n88_log_event' ) ) {
            n88_log_event( 'timeline_step_comment_added', 'item', array(
                'item_id'      => $item_id,
                'object_id'    => $comment_id,
                'payload_json' => array(
                    'item_id'      => $item_id,
                    'step_number'  => $step_number,
                    'designer_id'  => $designer_id,
                    'timestamp'    => current_time( 'mysql' ),
                ),
            ) );
        }

        return array( 'success' => true, 'message' => 'Comment submitted.', 'comment_id' => $comment_id );
    }

    /**
     * Get comments for a step (designer + operator view). Newest first.
     *
     * @param int $item_id     n88_items.id
     * @param int $step_number 4, 5, or 6
     * @return array List of { id, designer_id, designer_name, media_version, comment_text, created_at }
     */
    public static function get_comments_for_step( $item_id, $step_number ) {
        global $wpdb;
        $item_id     = absint( $item_id );
        $step_number = absint( $step_number );
        if ( ! $item_id || ! in_array( $step_number, self::ALLOWED_STEPS, true ) ) {
            return array();
        }
        $table = $wpdb->prefix . 'n88_timeline_step_comments';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return array();
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, designer_id, media_version, comment_text, created_at FROM {$table} WHERE item_id = %d AND step_number = %d ORDER BY created_at DESC",
            $item_id,
            $step_number
        ), ARRAY_A );
        if ( empty( $rows ) ) {
            return array();
        }

        $out = array();
        foreach ( $rows as $row ) {
            $designer_id = (int) $row['designer_id'];
            $designer_name = $designer_id ? get_the_author_meta( 'display_name', $designer_id ) : '';
            $out[] = array(
                'id'            => (int) $row['id'],
                'designer_id'   => $designer_id,
                'designer_name' => $designer_name,
                'media_version' => $row['media_version'] !== null ? (int) $row['media_version'] : null,
                'comment_text'  => $row['comment_text'],
                'created_at'    => $row['created_at'],
            );
        }
        return $out;
    }
}
