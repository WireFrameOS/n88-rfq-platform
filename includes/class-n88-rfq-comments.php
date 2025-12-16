<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class N88_RFQ_Comments {

    /**
     * Add a comment to a project item
     *
     * @param array $data {
     *     @type int $project_id Project ID
     *     @type string $item_id Item ID (optional for project-level comments)
     *     @type string $video_id Video ID (optional for video-level comments)
     *     @type int $user_id User ID
     *     @type string $comment_text Comment text
     *     @type bool $is_urgent Whether comment is urgent
     *     @type int $parent_comment_id Parent comment ID for replies
     * }
     * @return int|false Comment ID on success, false on failure
     */
    public static function add_comment( $data ) {
        global $wpdb;

        // Validate required fields with detailed logging
        if ( empty( $data['project_id'] ) ) {
            error_log( 'N88 RFQ: add_comment - Missing project_id. Data: ' . print_r( $data, true ) );
            return false;
        }
        
        if ( empty( $data['user_id'] ) ) {
            error_log( 'N88 RFQ: add_comment - Missing user_id. Data: ' . print_r( $data, true ) );
            return false;
        }
        
        if ( empty( $data['comment_text'] ) ) {
            error_log( 'N88 RFQ: add_comment - Missing comment_text. Data: ' . print_r( $data, true ) );
            return false;
        }

        // Sanitize inputs
        $project_id = intval( $data['project_id'] );
        $item_id = isset( $data['item_id'] ) && ! empty( $data['item_id'] ) ? sanitize_text_field( $data['item_id'] ) : null;
        $video_id = isset( $data['video_id'] ) && ! empty( $data['video_id'] ) ? sanitize_text_field( $data['video_id'] ) : null;
        $user_id = intval( $data['user_id'] );
        $comment_text_raw = isset( $data['comment_text'] ) ? $data['comment_text'] : '';
        
        // Sanitize comment text - use wp_kses_post but allow more HTML tags for comments
        // First try wp_kses_post, if it removes everything, use sanitize_textarea_field
        $comment_text = wp_kses_post( $comment_text_raw );
        
        // If wp_kses_post removed everything but we had content, use sanitize_textarea_field
        if ( empty( $comment_text ) && ! empty( trim( $comment_text_raw ) ) ) {
            error_log( 'N88 RFQ: add_comment - wp_kses_post removed all content, using sanitize_textarea_field. Original length: ' . strlen( $comment_text_raw ) );
            $comment_text = sanitize_textarea_field( $comment_text_raw );
            
            // If still empty, try strip_tags as last resort
            if ( empty( $comment_text ) && ! empty( trim( $comment_text_raw ) ) ) {
                error_log( 'N88 RFQ: add_comment - sanitize_textarea_field also failed, using strip_tags' );
                $comment_text = wp_strip_all_tags( $comment_text_raw );
            }
        }
        
        // Final check - if still empty, log and return false
        if ( empty( trim( $comment_text ) ) && ! empty( trim( $comment_text_raw ) ) ) {
            error_log( 'N88 RFQ: add_comment - All sanitization methods failed. Original: ' . substr( $comment_text_raw, 0, 200 ) );
            return false;
        }
        
        $is_urgent = isset( $data['is_urgent'] ) ? (bool) $data['is_urgent'] : false;
        $parent_comment_id = isset( $data['parent_comment_id'] ) && ! empty( $data['parent_comment_id'] ) ? intval( $data['parent_comment_id'] ) : null;

        // Verify user has permission to comment
        if ( ! self::user_can_comment( $project_id, $user_id ) ) {
            error_log( 'N88 RFQ: add_comment - Permission denied. Project: ' . $project_id . ', User: ' . $user_id );
            return false;
        }

        $table = $wpdb->prefix . 'project_comments';
        
        // Verify table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( ! $table_exists ) {
            error_log( 'N88 RFQ: add_comment - Table does not exist: ' . $table );
            return false;
        }
        
        $now = current_time( 'mysql' );

        // Prepare insert data - only include fields with values (WordPress way)
        $insert_data = array(
            'project_id' => $project_id,
            'user_id' => $user_id,
            'comment_text' => $comment_text,
            'is_urgent' => $is_urgent ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        );
        
        $format = array( '%d', '%d', '%s', '%d', '%s', '%s' );
        
        // Add optional fields only if they have values
        if ( ! empty( $item_id ) ) {
            $insert_data['item_id'] = $item_id;
            $format[] = '%s';
        }
        
        if ( ! empty( $video_id ) ) {
            $insert_data['video_id'] = $video_id;
            $format[] = '%s';
        }
        
        if ( ! empty( $parent_comment_id ) && $parent_comment_id > 0 ) {
            $insert_data['parent_comment_id'] = $parent_comment_id;
            $format[] = '%d';
        }

        $inserted = $wpdb->insert( $table, $insert_data, $format );

        if ( false === $inserted ) {
            error_log( 'N88 RFQ: add_comment - Database insert failed. Error: ' . $wpdb->last_error );
            error_log( 'N88 RFQ: add_comment - Last query: ' . $wpdb->last_query );
            error_log( 'N88 RFQ: add_comment - Insert data: ' . print_r( $insert_data, true ) );
            error_log( 'N88 RFQ: add_comment - Format: ' . print_r( $format, true ) );
            error_log( 'N88 RFQ: add_comment - Table: ' . $table );
            
            // Check if table exists and has correct structure
            // Table name is safe (from $wpdb->prefix), but we validate it contains only safe characters
            $table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
            $table_check = $wpdb->get_results( "DESCRIBE {$table_safe}" );
            if ( empty( $table_check ) ) {
                error_log( 'N88 RFQ: add_comment - Table structure check failed. Table may not exist or be accessible.' );
            } else {
                error_log( 'N88 RFQ: add_comment - Table structure: ' . print_r( $table_check, true ) );
            }
            
            return false;
        }

        if ( $inserted ) {
            $comment_id = $wpdb->insert_id;

            // Log the action
            N88_RFQ_Audit::log_action(
                $project_id,
                $user_id,
                'comment_added',
                'comment_id',
                '',
                $comment_id
            );

            // Get the comment object for notification
            $comment = self::get_comment( $comment_id );

            // Trigger notification through proper notification class
            if ( class_exists( 'N88_RFQ_Notifications' ) ) {
                N88_RFQ_Notifications::notify_comment_added( $project_id, $comment );
            }

            return $comment_id;
        }

        return false;
    }

    /**
     * Get comments for a project or item
     *
     * @param int $project_id Project ID
     * @param string $item_id Item ID (optional)
     * @param string $video_id Video ID (optional)
     * @param int $limit Comments per page
     * @param int $offset Pagination offset
     * @return array Array of comment objects
     */
    public static function get_comments( $project_id, $item_id = null, $video_id = null, $limit = 20, $offset = 0 ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $limit = intval( $limit );
        $offset = intval( $offset );

        $table = $wpdb->prefix . 'project_comments';
        
        // Build WHERE clause
        $where = array( $wpdb->prepare( 'project_id = %d', $project_id ) );
        
        if ( $item_id !== null ) {
            $item_id = sanitize_text_field( $item_id );
            $where[] = $wpdb->prepare( 'item_id = %s', $item_id );
        } else {
            // If item_id is null, only show project-level comments (item_id IS NULL)
            $where[] = 'item_id IS NULL';
        }
        
        if ( $video_id !== null ) {
            $video_id = sanitize_text_field( $video_id );
            $where[] = $wpdb->prepare( 'video_id = %s', $video_id );
        } else {
            // If video_id is null, only show comments without video_id (video_id IS NULL)
            $where[] = 'video_id IS NULL';
        }
        
        $where_sql = implode( ' AND ', $where );
        
        // Order: Urgent first, then parent comments (parent_comment_id IS NULL) before replies, then by creation date
        // This ensures threaded display: parents first, then their replies
        $query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY is_urgent DESC, CASE WHEN parent_comment_id IS NULL THEN 0 ELSE 1 END, created_at ASC LIMIT %d OFFSET %d";
        $query = $wpdb->prepare( $query, $limit, $offset );

        return $wpdb->get_results( $query );
    }

    /**
     * Get total comment count
     *
     * @param int $project_id Project ID
     * @param string $item_id Item ID (optional)
     * @param string $video_id Video ID (optional)
     * @return int Comment count
     */
    public static function get_comment_count( $project_id, $item_id = null, $video_id = null ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $table = $wpdb->prefix . 'project_comments';

        $where = array( $wpdb->prepare( 'project_id = %d', $project_id ) );

        if ( $item_id !== null ) {
            $item_id = sanitize_text_field( $item_id );
            $where[] = $wpdb->prepare( 'item_id = %s', $item_id );
        } else {
            $where[] = 'item_id IS NULL';
        }

        if ( $video_id !== null ) {
            $video_id = sanitize_text_field( $video_id );
            $where[] = $wpdb->prepare( 'video_id = %s', $video_id );
        } else {
            $where[] = 'video_id IS NULL';
        }

        $where_sql = implode( ' AND ', $where );
        
        // Table name is safe (from $wpdb->prefix), but we validate it contains only safe characters
        $table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
        $query = $wpdb->prepare( "SELECT COUNT(*) FROM {$table_safe} WHERE {$where_sql}" );

        return intval( $wpdb->get_var( $query ) );
    }

    /**
     * Update a comment
     *
     * @param int $comment_id Comment ID
     * @param string $comment_text New comment text
     * @param int $user_id User ID (must be owner or admin)
     * @return bool True on success
     */
    public static function update_comment( $comment_id, $comment_text, $user_id ) {
        global $wpdb;

        $comment_id = intval( $comment_id );
        $user_id = intval( $user_id );

        // Get comment
        $comment = self::get_comment( $comment_id );
        if ( ! $comment ) {
            return false;
        }

        // Verify permission
        if ( $comment->user_id != $user_id && ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $table = $wpdb->prefix . 'project_comments';
        $now = current_time( 'mysql' );

        return $wpdb->update(
            $table,
            array(
                'comment_text' => wp_kses_post( $comment_text ),
                'updated_at' => $now,
            ),
            array( 'id' => $comment_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Delete a comment
     *
     * @param int $comment_id Comment ID
     * @param int $user_id User ID (must be owner or admin)
     * @return bool True on success
     */
    public static function delete_comment( $comment_id, $user_id ) {
        global $wpdb;

        $comment_id = intval( $comment_id );
        $user_id = intval( $user_id );

        // Get comment
        $comment = self::get_comment( $comment_id );
        if ( ! $comment ) {
            return false;
        }

        // Verify permission
        if ( $comment->user_id != $user_id && ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $table = $wpdb->prefix . 'project_comments';
        $deleted = $wpdb->delete(
            $table,
            array( 'id' => $comment_id ),
            array( '%d' )
        );

        if ( $deleted ) {
            N88_RFQ_Audit::log_action(
                $comment->project_id,
                $user_id,
                'comment_deleted',
                'comment_id',
                $comment_id,
                ''
            );
        }

        return $deleted;
    }

    /**
     * Get single comment
     *
     * @param int $comment_id Comment ID
     * @return object|null Comment object
     */
    public static function get_comment( $comment_id ) {
        global $wpdb;

        $comment_id = intval( $comment_id );
        $table = $wpdb->prefix . 'project_comments';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $comment_id
            )
        );
    }

    /**
     * Check if user can comment on project
     *
     * @param int $project_id Project ID
     * @param int $user_id User ID
     * @return bool True if user can comment
     */
    public static function user_can_comment( $project_id, $user_id ) {
        $project_id = intval( $project_id );
        $user_id = intval( $user_id );

        // Admins can always comment
        if ( current_user_can( 'manage_options' ) ) {
            error_log( 'N88 RFQ: user_can_comment - User ' . $user_id . ' is admin, allowing comment' );
            return true;
        }

        // Project owner can comment
        $projects_class = new N88_RFQ_Projects();
        
        // Try to get project as owner first (returns array with ARRAY_A)
        $project = $projects_class->get_project( $project_id, $user_id );
        if ( $project && is_array( $project ) && isset( $project['user_id'] ) && (int) $project['user_id'] === $user_id ) {
            error_log( 'N88 RFQ: user_can_comment - User ' . $user_id . ' is project owner, allowing comment' );
            return true;
        }
        
        // If get_project returned null, try get_project_admin to verify project exists and check ownership
        $project_admin = $projects_class->get_project_admin( $project_id );
        if ( $project_admin && is_array( $project_admin ) && isset( $project_admin['user_id'] ) && (int) $project_admin['user_id'] === $user_id ) {
            error_log( 'N88 RFQ: user_can_comment - User ' . $user_id . ' is project owner (via admin method), allowing comment' );
            return true;
        }

        $project_owner_id = ( $project_admin && is_array( $project_admin ) && isset( $project_admin['user_id'] ) ) ? $project_admin['user_id'] : 'unknown';
        error_log( 'N88 RFQ: user_can_comment - User ' . $user_id . ' does NOT have permission. Project owner: ' . $project_owner_id );
        return false;
    }

    /**
     * Format comment for display
     *
     * @param object $comment Comment object
     * @return array Formatted comment data
     */
    public static function format_comment( $comment ) {
        $user = get_userdata( $comment->user_id );
        $created_time = strtotime( $comment->created_at );
        $current_time = current_time( 'timestamp' );
        $time_diff = $current_time - $created_time;

        // Format time ago
        if ( $time_diff < 60 ) {
            $time_ago = 'just now';
        } elseif ( $time_diff < 3600 ) {
            $mins = floor( $time_diff / 60 );
            $time_ago = $mins . ' minute' . ( $mins > 1 ? 's' : '' ) . ' ago';
        } elseif ( $time_diff < 86400 ) {
            $hours = floor( $time_diff / 3600 );
            $time_ago = $hours . ' hour' . ( $hours > 1 ? 's' : '' ) . ' ago';
        } elseif ( $time_diff < 604800 ) {
            $days = floor( $time_diff / 86400 );
            $time_ago = $days . ' day' . ( $days > 1 ? 's' : '' ) . ' ago';
        } else {
            $time_ago = date_i18n( get_option( 'date_format' ), $created_time );
        }

        $was_edited = $comment->updated_at !== $comment->created_at;

        return array(
            'id' => $comment->id,
            'project_id' => $comment->project_id,
            'item_id' => $comment->item_id,
            'video_id' => $comment->video_id ?? null,
            'user_id' => $comment->user_id,
            'user_name' => $user ? $user->display_name : 'Unknown',
            'user_email' => $user ? $user->user_email : '',
            'comment_text' => $comment->comment_text,
            'is_urgent' => ! empty( $comment->is_urgent ),
            'parent_comment_id' => $comment->parent_comment_id ?? null,
            'created_at' => $comment->created_at,
            'created_at_formatted' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_time ),
            'created_at_ago' => $time_ago,
            'updated_at' => $comment->updated_at,
            'updated_at_formatted' => $was_edited ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $comment->updated_at ) ) : null,
            'was_edited' => $was_edited,
            'can_edit' => get_current_user_id() == $comment->user_id || current_user_can( 'manage_options' ),
            'can_delete' => get_current_user_id() == $comment->user_id || current_user_can( 'manage_options' ),
        );
    }
}
