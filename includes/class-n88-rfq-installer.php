<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class N88_RFQ_Installer {

    /**
     * Phase 1.1 Schema Version
     */
    const PHASE_1_1_SCHEMA_VERSION = '1.1.0';
    const PHASE_1_1_SCHEMA_OPTION = 'n88_phase_1_1_schema_version';

    /**
     * Phase 1.2 Schema Version
     */
    const PHASE_1_2_SCHEMA_VERSION = '1.2.0';
    const PHASE_1_2_SCHEMA_OPTION = 'n88_phase_1_2_schema_version';

    /**
     * Safely add a foreign key constraint, suppressing errors if it fails
     * 
     * @param string $table_name Table name (will be escaped)
     * @param string $constraint_name Constraint name
     * @param string $column_name Column name in the table
     * @param string $referenced_table Referenced table name (will be escaped)
     * @param string $referenced_column Referenced column name
     * @param string $on_delete ON DELETE action (e.g., 'CASCADE', 'SET NULL')
     * @return bool True if constraint was added or already exists, false on failure
     */
    /**
     * Check if a foreign key constraint exists
     */
    private static function foreign_key_exists( $table_name, $constraint_name ) {
        global $wpdb;
        
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = %s",
            DB_NAME,
            $table_name,
            $constraint_name
        ) );
        
        return $exists > 0;
    }
    
    private static function safe_add_foreign_key( $table_name, $constraint_name, $column_name, $referenced_table, $referenced_column, $on_delete = 'CASCADE' ) {
        global $wpdb;
        
        // Escape table and column names
        $table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $table_name );
        $referenced_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $referenced_table );
        $column_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $column_name );
        $referenced_column_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $referenced_column );
        $constraint_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $constraint_name );
        $on_delete_safe = strtoupper( $on_delete ) === 'SET NULL' ? 'SET NULL' : 'CASCADE';
        
        // Check if constraint already exists and is valid
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = %s
            AND REFERENCED_TABLE_NAME = %s
            AND REFERENCED_COLUMN_NAME = %s",
            DB_NAME,
            $table_name,
            $constraint_name,
            $referenced_table,
            $referenced_column
        ) );
        
        if ( $exists > 0 ) {
            return true; // Constraint already exists and is valid
        }
        
        // If constraint exists but is invalid (wrong referenced column), drop it first
        $exists_any = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = %s",
            DB_NAME,
            $table_name,
            $constraint_name
        ) );
        
        if ( $exists_any > 0 ) {
            // Drop the broken constraint (suppress errors)
            $wpdb->suppress_errors( true );
            @$wpdb->query( "ALTER TABLE {$table_safe} DROP FOREIGN KEY {$constraint_safe}" );
            $wpdb->suppress_errors( false );
        }
        
        // Suppress errors and attempt to add constraint
        $wpdb->suppress_errors( true );
        $old_error = $wpdb->last_error;
        $old_query = $wpdb->last_query;
        
        $query = "ALTER TABLE {$table_safe} ADD CONSTRAINT {$constraint_safe} FOREIGN KEY ({$column_safe}) REFERENCES {$referenced_table_safe}({$referenced_column_safe}) ON DELETE {$on_delete_safe}";
        $result = @$wpdb->query( $query );
        
        // Clear the error if the query failed (constraint can't be added for valid reasons)
        if ( $result === false || ! empty( $wpdb->last_error ) ) {
            $wpdb->last_error = '';
            $wpdb->last_query = $old_query;
        }
        
        $wpdb->suppress_errors( false );
        
        return $result !== false;
    }

    public static function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $projects_table    = $wpdb->prefix . 'projects';
        $meta_table        = $wpdb->prefix . 'project_metadata';
        $comments_table    = $wpdb->prefix . 'project_comments';
        $quotes_table      = $wpdb->prefix . 'project_quotes';
        $notifications_table = $wpdb->prefix . 'project_notifications';
        $audit_table       = $wpdb->prefix . 'project_audit';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_projects = "CREATE TABLE {$projects_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            project_name VARCHAR(255) NOT NULL,
            project_type VARCHAR(100) NOT NULL DEFAULT '',
            timeline VARCHAR(100) NOT NULL DEFAULT '',
            budget_range VARCHAR(100) NOT NULL DEFAULT '',
            status TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            submitted_at DATETIME NULL,
            updated_by BIGINT UNSIGNED NULL,
            quote_type VARCHAR(100) NULL,
            item_count INT UNSIGNED DEFAULT 0,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY updated_by (updated_by)
        ) {$charset_collate};";

        $sql_meta = "CREATE TABLE {$meta_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL,
            meta_value LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY meta_key (meta_key)
        ) {$charset_collate};";

        $sql_comments = "CREATE TABLE {$comments_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            item_id VARCHAR(255) NULL,
            video_id VARCHAR(255) NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            comment_text LONGTEXT NOT NULL,
            is_urgent TINYINT(1) NOT NULL DEFAULT 0,
            parent_comment_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY item_id (item_id),
            KEY video_id (video_id),
            KEY user_id (user_id),
            KEY is_urgent (is_urgent),
            KEY parent_comment_id (parent_comment_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_quotes = "CREATE TABLE {$quotes_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            quote_file_path VARCHAR(500) NULL,
            admin_notes LONGTEXT NULL,
            quote_status VARCHAR(50) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            labor_cost DECIMAL(10,2) NULL DEFAULT 0.00,
            materials_cost DECIMAL(10,2) NULL DEFAULT 0.00,
            overhead_cost DECIMAL(10,2) NULL DEFAULT 0.00,
            margin_percentage DECIMAL(5,2) NULL DEFAULT 0.00,
            shipping_zone VARCHAR(100) NULL,
            unit_price DECIMAL(10,2) NULL DEFAULT 0.00,
            total_price DECIMAL(10,2) NULL DEFAULT 0.00,
            lead_time VARCHAR(50) NULL,
            cbm_volume DECIMAL(10,4) NULL DEFAULT 0.0000,
            volume_rules_applied TEXT NULL,
            client_message LONGTEXT NULL,
            quote_items LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY quote_status (quote_status)
        ) {$charset_collate};";

        $sql_notifications = "CREATE TABLE {$notifications_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(100) NOT NULL,
            message LONGTEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY notification_type (notification_type),
            KEY is_read (is_read)
        ) {$charset_collate};";

        $sql_audit = "CREATE TABLE {$audit_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(100) NOT NULL,
            field_name VARCHAR(255) NULL,
            old_value LONGTEXT NULL,
            new_value LONGTEXT NULL,
            ip_address VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Phase 3: Timeline Events table
        $timeline_events_table = $wpdb->prefix . 'n88_timeline_events';
        $sql_timeline_events = "CREATE TABLE {$timeline_events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL COMMENT 'Item index within project (0-based)',
            step_key VARCHAR(50) NOT NULL COMMENT 'e.g. prototype, frame_structure, surface_treatment, sourcing, qc, packing',
            event_type VARCHAR(30) NOT NULL COMMENT 'step_started, step_completed, step_reopened, step_delayed, step_unblocked, override_applied, file_added, comment_added, video_added, note_added, status_changed',
            status VARCHAR(30) DEFAULT NULL COMMENT 'pending, in_progress, completed, blocked, delayed',
            event_data LONGTEXT NULL COMMENT 'JSON blob for extra data (reason, old_status, new_status, file_id, comment_id, video_id, user_agent, etc.)',
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL COMMENT 'User ID who triggered the event',
            PRIMARY KEY (id),
            KEY idx_project_item (project_id, item_id),
            KEY idx_item_step (item_id, step_key),
            KEY idx_project (project_id),
            KEY idx_event_type (event_type, created_at),
            KEY idx_status (status),
            KEY idx_created_at (created_at),
            KEY idx_created_by (created_by)
        ) {$charset_collate};";

        // Phase 3: Project Videos table
        $project_videos_table = $wpdb->prefix . 'n88_project_videos';
        $sql_project_videos = "CREATE TABLE {$project_videos_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NULL COMMENT 'Item index (0-based), NULL if project-level video',
            step_key VARCHAR(50) NULL COMMENT 'Timeline step key, NULL if item-level or project-level',
            youtube_id VARCHAR(20) NOT NULL COMMENT 'YouTube video ID (extracted from URL)',
            youtube_url VARCHAR(255) NOT NULL COMMENT 'Full YouTube URL (always youtube-nocookie.com)',
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            thumbnail_attachment_id BIGINT UNSIGNED NULL COMMENT 'WP Media Library attachment ID for custom thumbnail',
            display_order INT UNSIGNED DEFAULT 0 COMMENT 'Order within step/item/project',
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_project (project_id),
            KEY idx_item (item_id),
            KEY idx_step (step_key),
            KEY idx_project_item_step (project_id, item_id, step_key),
            KEY idx_youtube_id (youtube_id),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql_projects );
        dbDelta( $sql_meta );
        dbDelta( $sql_comments );
        dbDelta( $sql_quotes );
        dbDelta( $sql_notifications );
        dbDelta( $sql_audit );
        dbDelta( $sql_timeline_events );
        dbDelta( $sql_project_videos );

        // Phase 1.1: Core Data + Event Spine tables
        self::create_phase_1_1_tables( $charset_collate );

        // Phase 1.2: Core Intelligence + Material Bank tables
        self::create_phase_1_2_tables( $charset_collate );

        // Commit 2.2.1: Create custom roles
        self::create_custom_roles();

        // Commit 2.2.1: Create required pages with shortcodes
        self::create_required_pages();

        // Commit 2.2.2: Create supplier profiles, designer profiles, and categories tables
        self::create_phase_2_2_2_tables( $charset_collate );

        // Commit 2.2.5: Create keyword tables and seed keyword library
        self::create_phase_2_2_5_tables( $charset_collate );
        self::seed_keyword_library();

        // Commit 2.2.6: Create practice types tables and seed practice types
        self::create_phase_2_2_6_tables( $charset_collate );
        self::seed_practice_types();

        // Commit 2.2.9: Create RFQ routing rails and item delivery context tables
        self::create_phase_2_2_9_tables( $charset_collate );

        // Commit 2.3.1: Create bid tables (DB only, no UI)
        self::create_phase_2_3_1_tables( $charset_collate );

        // Commit 2.3.9.1A: Create prototype payments table
        self::create_phase_2_3_9_1a_tables( $charset_collate );
        
        // Commit 2.3.9.1C-a: Create item messages table
        self::create_phase_2_3_9_1c_a_tables( $charset_collate );
        
        // Commit 2.3.9.2B-S: Create prototype video submission tables
        self::create_phase_2_3_9_2b_tables( $charset_collate );
        
        // Commit 2.3.9.2B-D: Create designer prototype review tables
        self::create_phase_2_3_9_2b_d_tables( $charset_collate );
        self::seed_keyword_phrases();

        // Commit 2.5.2: Create board-first projects and rooms tables
        self::create_phase_2_5_2_tables( $charset_collate );

        self::maybe_upgrade();
    }

    /**
     * Create required pages with shortcodes (Commit 2.2.1)
     */
    private static function create_required_pages() {
        // Check if pages already exist to avoid duplicates
        $workspace_page = get_page_by_path( 'workspace' );
        
        // For nested pages, try get_page_by_path first, then check by parent
        $supplier_queue_page = get_page_by_path( 'supplier/queue' );
        $supplier_onboarding_page = get_page_by_path( 'supplier/onboarding' );
        $designer_onboarding_page = get_page_by_path( 'designer/onboarding' );
        $admin_queue_page = get_page_by_path( 'admin/queue' );
        
        // If get_page_by_path didn't work, try finding by parent and slug
        if ( ! $supplier_queue_page ) {
            $supplier_parent_check = get_page_by_path( 'supplier' );
            if ( $supplier_parent_check ) {
                $pages = get_pages( array(
                    'post_status' => 'publish',
                    'parent' => $supplier_parent_check->ID,
                ) );
                foreach ( $pages as $page ) {
                    if ( $page->post_name === 'queue' ) {
                        $supplier_queue_page = $page;
                        break;
                    }
                }
            }
        }
        
        if ( ! $supplier_onboarding_page ) {
            $supplier_parent_check = get_page_by_path( 'supplier' );
            if ( $supplier_parent_check ) {
                $pages = get_pages( array(
                    'post_status' => 'publish',
                    'parent' => $supplier_parent_check->ID,
                ) );
                foreach ( $pages as $page ) {
                    if ( $page->post_name === 'onboarding' ) {
                        $supplier_onboarding_page = $page;
                        break;
                    }
                }
            }
        }

        if ( ! $designer_onboarding_page ) {
            $designer_parent_check = get_page_by_path( 'designer' );
            if ( $designer_parent_check ) {
                $pages = get_pages( array(
                    'post_status' => 'publish',
                    'parent' => $designer_parent_check->ID,
                ) );
                foreach ( $pages as $page ) {
                    if ( $page->post_name === 'onboarding' ) {
                        $designer_onboarding_page = $page;
                        break;
                    }
                }
            }
        }

        if ( ! $admin_queue_page ) {
            $admin_parent_check = get_page_by_path( 'admin' );
            if ( $admin_parent_check ) {
                $pages = get_pages( array(
                    'post_status' => 'publish',
                    'parent' => $admin_parent_check->ID,
                ) );
                foreach ( $pages as $page ) {
                    if ( $page->post_name === 'queue' ) {
                        $admin_queue_page = $page;
                        break;
                    }
                }
            }
        }

        // Create Workspace page
        if ( ! $workspace_page ) {
            $workspace_id = wp_insert_post( array(
                'post_title'    => 'Workspace',
                'post_name'    => 'workspace',
                'post_content' => '[n88_workspace]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ) );

            if ( is_wp_error( $workspace_id ) ) {
                error_log( 'N88 RFQ: Failed to create workspace page: ' . $workspace_id->get_error_message() );
            } else {
                update_option( 'n88_rfq_workspace_page_id', $workspace_id );
            }
        } else {
            // Update existing page to ensure it has the shortcode
            if ( strpos( $workspace_page->post_content, '[n88_workspace]' ) === false ) {
                wp_update_post( array(
                    'ID'           => $workspace_page->ID,
                    'post_content' => '[n88_workspace]',
                ) );
            }
            update_option( 'n88_rfq_workspace_page_id', $workspace_page->ID );
        }

        // Create Supplier parent page if it doesn't exist
        $supplier_parent = get_page_by_path( 'supplier' );
        if ( ! $supplier_parent ) {
            $supplier_parent_id = wp_insert_post( array(
                'post_title'    => 'Supplier',
                'post_name'    => 'supplier',
                'post_content' => '',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ) );

            if ( is_wp_error( $supplier_parent_id ) ) {
                error_log( 'N88 RFQ: Failed to create supplier parent page: ' . $supplier_parent_id->get_error_message() );
                $supplier_parent_id = 0;
            }
        } else {
            $supplier_parent_id = $supplier_parent->ID;
        }

        // Create Supplier Queue page
        if ( ! $supplier_queue_page ) {
            $supplier_queue_id = wp_insert_post( array(
                'post_title'    => 'Supplier Queue',
                'post_name'    => 'queue',
                'post_content' => '[n88_supplier_queue]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
                'post_parent'  => $supplier_parent_id > 0 ? $supplier_parent_id : 0,
            ) );

            if ( is_wp_error( $supplier_queue_id ) ) {
                error_log( 'N88 RFQ: Failed to create supplier queue page: ' . $supplier_queue_id->get_error_message() );
            } else {
                update_option( 'n88_rfq_supplier_queue_page_id', $supplier_queue_id );
            }
        } else {
            // Update existing page to ensure it has the shortcode
            if ( strpos( $supplier_queue_page->post_content, '[n88_supplier_queue]' ) === false ) {
                wp_update_post( array(
                    'ID'           => $supplier_queue_page->ID,
                    'post_content' => '[n88_supplier_queue]',
                    'post_parent' => $supplier_parent_id > 0 ? $supplier_parent_id : $supplier_queue_page->post_parent,
                ) );
            }
            update_option( 'n88_rfq_supplier_queue_page_id', $supplier_queue_page->ID );
        }

        // Create Supplier Onboarding page (Commit 2.2.7)
        if ( ! $supplier_onboarding_page ) {
            $supplier_onboarding_id = wp_insert_post( array(
                'post_title'    => 'Supplier Onboarding',
                'post_name'     => 'onboarding',
                'post_content'  => '[n88_supplier_onboarding]',
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_author'   => 1,
                'post_parent'   => $supplier_parent_id > 0 ? $supplier_parent_id : 0,
            ) );

            if ( is_wp_error( $supplier_onboarding_id ) ) {
                error_log( 'N88 RFQ: Failed to create supplier onboarding page: ' . $supplier_onboarding_id->get_error_message() );
            } else {
                update_option( 'n88_rfq_supplier_onboarding_page_id', $supplier_onboarding_id );
            }
        } else {
            // Update existing page to ensure it has the shortcode
            if ( strpos( $supplier_onboarding_page->post_content, '[n88_supplier_onboarding]' ) === false ) {
                wp_update_post( array(
                    'ID'           => $supplier_onboarding_page->ID,
                    'post_content' => '[n88_supplier_onboarding]',
                    'post_parent' => $supplier_parent_id > 0 ? $supplier_parent_id : $supplier_onboarding_page->post_parent,
                ) );
            }
            update_option( 'n88_rfq_supplier_onboarding_page_id', $supplier_onboarding_page->ID );
        }

        // Create Designer parent page if it doesn't exist (Commit 2.2.8)
        $designer_parent = get_page_by_path( 'designer' );
        if ( ! $designer_parent ) {
            $designer_parent_id = wp_insert_post( array(
                'post_title'    => 'Designer',
                'post_name'     => 'designer',
                'post_content'  => '',
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_author'   => 1,
            ) );

            if ( is_wp_error( $designer_parent_id ) ) {
                error_log( 'N88 RFQ: Failed to create designer parent page: ' . $designer_parent_id->get_error_message() );
                $designer_parent_id = 0;
            }
        } else {
            $designer_parent_id = $designer_parent->ID;
        }

        // Create Designer Onboarding page (Commit 2.2.8)
        if ( ! $designer_onboarding_page ) {
            $designer_onboarding_id = wp_insert_post( array(
                'post_title'    => 'Designer Onboarding',
                'post_name'     => 'onboarding',
                'post_content'  => '[n88_designer_onboarding]',
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_author'   => 1,
                'post_parent'   => $designer_parent_id > 0 ? $designer_parent_id : 0,
            ) );

            if ( is_wp_error( $designer_onboarding_id ) ) {
                error_log( 'N88 RFQ: Failed to create designer onboarding page: ' . $designer_onboarding_id->get_error_message() );
            } else {
                update_option( 'n88_rfq_designer_onboarding_page_id', $designer_onboarding_id );
            }
        } else {
            // Update existing page to ensure it has the shortcode
            if ( strpos( $designer_onboarding_page->post_content, '[n88_designer_onboarding]' ) === false ) {
                wp_update_post( array(
                    'ID'           => $designer_onboarding_page->ID,
                    'post_content' => '[n88_designer_onboarding]',
                    'post_parent' => $designer_parent_id > 0 ? $designer_parent_id : $designer_onboarding_page->post_parent,
                ) );
            }
            update_option( 'n88_rfq_designer_onboarding_page_id', $designer_onboarding_page->ID );
        }

        // Create Operator Queue page (Commit 2.3.9.1C)
        $operator_queue_page = get_page_by_path( 'operator/queue' );
        if ( ! $operator_queue_page ) {
            // Check if operator parent page exists
            $operator_parent = get_page_by_path( 'operator' );
            if ( ! $operator_parent ) {
                $operator_parent_id = wp_insert_post( array(
                    'post_title'    => 'Operator',
                    'post_name'     => 'operator',
                    'post_content'  => '',
                    'post_status'   => 'publish',
                    'post_type'     => 'page',
                    'post_author'   => 1,
                ) );

                if ( is_wp_error( $operator_parent_id ) ) {
                    error_log( 'N88 RFQ: Failed to create operator parent page: ' . $operator_parent_id->get_error_message() );
                    $operator_parent_id = 0;
                }
            } else {
                $operator_parent_id = $operator_parent->ID;
            }

            // Try to find queue page under operator parent
            if ( $operator_parent_id > 0 ) {
                $pages = get_pages( array(
                    'post_status' => 'publish',
                    'parent' => $operator_parent_id,
                ) );
                foreach ( $pages as $page ) {
                    if ( $page->post_name === 'queue' ) {
                        $operator_queue_page = $page;
                        break;
                    }
                }
            }
        }

        // Create Operator Queue page if it doesn't exist
        if ( ! $operator_queue_page ) {
            $operator_parent = get_page_by_path( 'operator' );
            $operator_parent_id = $operator_parent ? $operator_parent->ID : 0;

            $operator_queue_id = wp_insert_post( array(
                'post_title'    => 'Operator Queue',
                'post_name'     => 'queue',
                'post_content'  => '[n88_operator_queue]',
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_author'   => 1,
                'post_parent'   => $operator_parent_id > 0 ? $operator_parent_id : 0,
            ) );

            if ( is_wp_error( $operator_queue_id ) ) {
                error_log( 'N88 RFQ: Failed to create operator queue page: ' . $operator_queue_id->get_error_message() );
            } else {
                update_option( 'n88_rfq_operator_queue_page_id', $operator_queue_id );
            }
        } else {
            // Update existing page to ensure it has the shortcode
            if ( strpos( $operator_queue_page->post_content, '[n88_operator_queue]' ) === false ) {
                $operator_parent = get_page_by_path( 'operator' );
                $operator_parent_id = $operator_parent ? $operator_parent->ID : 0;
                wp_update_post( array(
                    'ID'           => $operator_queue_page->ID,
                    'post_content' => '[n88_operator_queue]',
                    'post_parent' => $operator_parent_id > 0 ? $operator_parent_id : $operator_queue_page->post_parent,
                ) );
            }
            update_option( 'n88_rfq_operator_queue_page_id', $operator_queue_page->ID );
        }

        // Create Admin parent page if it doesn't exist
        $admin_parent = get_page_by_path( 'admin' );
        if ( ! $admin_parent ) {
            $admin_parent_id = wp_insert_post( array(
                'post_title'    => 'Admin',
                'post_name'    => 'admin',
                'post_content' => '',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ) );

            if ( is_wp_error( $admin_parent_id ) ) {
                error_log( 'N88 RFQ: Failed to create admin parent page: ' . $admin_parent_id->get_error_message() );
                $admin_parent_id = 0;
            }
        } else {
            $admin_parent_id = $admin_parent->ID;
        }

        // Create Admin Queue page
        if ( ! $admin_queue_page ) {
            $admin_queue_id = wp_insert_post( array(
                'post_title'    => 'Admin Queue',
                'post_name'    => 'queue',
                'post_content' => '[n88_admin_queue]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
                'post_parent'  => $admin_parent_id > 0 ? $admin_parent_id : 0,
            ) );

            if ( is_wp_error( $admin_queue_id ) ) {
                error_log( 'N88 RFQ: Failed to create admin queue page: ' . $admin_queue_id->get_error_message() );
            } else {
                update_option( 'n88_rfq_admin_queue_page_id', $admin_queue_id );
            }
        } else {
            // Update existing page to ensure it has the shortcode
            if ( strpos( $admin_queue_page->post_content, '[n88_admin_queue]' ) === false ) {
                wp_update_post( array(
                    'ID'           => $admin_queue_page->ID,
                    'post_content' => '[n88_admin_queue]',
                    'post_parent' => $admin_parent_id > 0 ? $admin_parent_id : $admin_queue_page->post_parent,
                ) );
            }
            update_option( 'n88_rfq_admin_queue_page_id', $admin_queue_page->ID );
        }

        // Flush rewrite rules to ensure permalinks work
        flush_rewrite_rules();
    }

    /**
     * Create custom roles (Commit 2.2.1)
     */
    private static function create_custom_roles() {
        // Create n88_designer role
        if ( ! get_role( 'n88_designer' ) ) {
            add_role(
                'n88_designer',
                __( 'Designer', 'n88-rfq' ),
                array(
                    'read' => true,
                    'upload_files' => true,
                    'n88_access_boards' => true,
                    'n88_access_items' => true,
                    'n88_access_projects' => true,
                )
            );
        }

        // Create n88_supplier_admin role
        if ( ! get_role( 'n88_supplier_admin' ) ) {
            add_role(
                'n88_supplier_admin',
                __( 'Supplier Admin', 'n88-rfq' ),
                array(
                    'read' => true,
                    'n88_view_supplier_queue' => true,
                )
            );
        }

        // Create n88_system_operator role
        if ( ! get_role( 'n88_system_operator' ) ) {
            add_role(
                'n88_system_operator',
                __( 'System Operator', 'n88-rfq' ),
                array(
                    'read' => true,
                    'n88_view_supplier_queue' => true,
                    'n88_view_global_queue' => true,
                    'manage_options' => true,
                )
            );
        }
    }

    /**
     * Create Phase 1.1 tables (Core Data + Event Spine)
     */
    private static function create_phase_1_1_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Active in 1.1: Core tables
        $designer_profiles_table = $wpdb->prefix . 'n88_designer_profiles';
        $items_table = $wpdb->prefix . 'n88_items';
        $boards_table = $wpdb->prefix . 'n88_boards';
        $board_items_table = $wpdb->prefix . 'n88_board_items';
        $board_layout_table = $wpdb->prefix . 'n88_board_layout';
        $events_table = $wpdb->prefix . 'n88_events';
        $item_edits_table = $wpdb->prefix . 'n88_item_edits';

        // Schema-only in 1.1: Future-ready tables
        $firms_table = $wpdb->prefix . 'n88_firms';
        $firm_members_table = $wpdb->prefix . 'n88_firm_members';
        $board_areas_table = $wpdb->prefix . 'n88_board_areas';
        $item_files_table = $wpdb->prefix . 'n88_item_files';

        // n88_designer_profiles
        $sql_designer_profiles = "CREATE TABLE {$designer_profiles_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            display_name VARCHAR(255) NOT NULL DEFAULT '',
            bio TEXT NULL,
            avatar_url VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // n88_items
        $sql_items = "CREATE TABLE {$items_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            owner_firm_id BIGINT UNSIGNED NULL,
            title VARCHAR(500) NOT NULL,
            description TEXT NULL,
            item_type VARCHAR(100) NOT NULL DEFAULT 'furniture',
            status VARCHAR(50) NOT NULL DEFAULT 'draft',
            primary_image_id BIGINT UNSIGNED NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY owner_user_id (owner_user_id),
            KEY owner_firm_id (owner_firm_id),
            KEY status (status),
            KEY item_type (item_type),
            KEY created_at (created_at),
            KEY deleted_at (deleted_at)
        ) {$charset_collate};";

        // n88_boards
        $sql_boards = "CREATE TABLE {$boards_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            owner_firm_id BIGINT UNSIGNED NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            view_mode VARCHAR(50) NOT NULL DEFAULT 'grid',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY owner_user_id (owner_user_id),
            KEY owner_firm_id (owner_firm_id),
            KEY created_at (created_at),
            KEY deleted_at (deleted_at)
        ) {$charset_collate};";

        // n88_board_items
        $sql_board_items = "CREATE TABLE {$board_items_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            board_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            added_by_user_id BIGINT UNSIGNED NOT NULL,
            added_at DATETIME NOT NULL,
            removed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY board_item_active (board_id, item_id, removed_at),
            KEY board_id (board_id),
            KEY item_id (item_id),
            KEY added_at (added_at)
        ) {$charset_collate};";

        // n88_board_layout
        $sql_board_layout = "CREATE TABLE {$board_layout_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            board_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            position_x DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            position_y DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            position_z INT NOT NULL DEFAULT 0,
            size_width DECIMAL(10,2) NULL,
            size_height DECIMAL(10,2) NULL,
            view_mode VARCHAR(50) NOT NULL DEFAULT 'grid',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY board_item_layout (board_id, item_id),
            KEY board_id (board_id),
            KEY item_id (item_id)
        ) {$charset_collate};";

        // n88_events
        $sql_events = "CREATE TABLE {$events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id BIGINT UNSIGNED NOT NULL,
            actor_firm_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(100) NOT NULL,
            object_type VARCHAR(50) NOT NULL,
            object_id BIGINT UNSIGNED NULL,
            item_id BIGINT UNSIGNED NULL,
            board_id BIGINT UNSIGNED NULL,
            payload_json LONGTEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY actor_user_id (actor_user_id),
            KEY event_type (event_type),
            KEY item_id (item_id),
            KEY board_id (board_id),
            KEY created_at (created_at),
            KEY item_created (item_id, created_at),
            KEY board_created (board_id, created_at)
        ) {$charset_collate};";

        // n88_item_edits
        $sql_item_edits = "CREATE TABLE {$item_edits_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            field_name VARCHAR(100) NOT NULL,
            old_value LONGTEXT NULL,
            new_value LONGTEXT NULL,
            editor_user_id BIGINT UNSIGNED NOT NULL,
            editor_role VARCHAR(50) NOT NULL,
            edit_reason VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY item_id (item_id),
            KEY editor_user_id (editor_user_id),
            KEY field_name (field_name),
            KEY created_at (created_at),
            KEY item_field_created (item_id, field_name, created_at)
        ) {$charset_collate};";

        // Schema-only: n88_firms
        $sql_firms = "CREATE TABLE {$firms_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            description TEXT NULL,
            logo_file_id BIGINT UNSIGNED NULL,
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY created_by_user_id (created_by_user_id),
            KEY created_at (created_at),
            KEY deleted_at (deleted_at)
        ) {$charset_collate};";

        // Schema-only: n88_firm_members
        $sql_firm_members = "CREATE TABLE {$firm_members_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            firm_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'member',
            invited_by_user_id BIGINT UNSIGNED NULL,
            joined_at DATETIME NOT NULL,
            left_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY firm_user_active (firm_id, user_id, left_at),
            KEY firm_id (firm_id),
            KEY user_id (user_id),
            KEY role (role)
        ) {$charset_collate};";

        // Schema-only: n88_board_areas
        $sql_board_areas = "CREATE TABLE {$board_areas_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            board_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            area_type VARCHAR(50) NOT NULL DEFAULT 'room',
            bounds_json TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY board_id (board_id),
            KEY area_type (area_type),
            KEY created_at (created_at),
            KEY deleted_at (deleted_at)
        ) {$charset_collate};";

        // Schema-only: n88_item_files
        $sql_item_files = "CREATE TABLE {$item_files_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            file_id BIGINT UNSIGNED NOT NULL,
            attached_by_user_id BIGINT UNSIGNED NOT NULL,
            attachment_type VARCHAR(50) NOT NULL DEFAULT 'general',
            payment_id BIGINT UNSIGNED NULL,
            bid_id BIGINT UNSIGNED NULL,
            cad_version INT UNSIGNED NULL,
            file_name VARCHAR(255) NULL,
            mime_type VARCHAR(100) NULL,
            display_order INT UNSIGNED NOT NULL DEFAULT 0,
            attached_at DATETIME NOT NULL,
            detached_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY item_file_active (item_id, file_id, detached_at),
            KEY item_id (item_id),
            KEY file_id (file_id)
        ) {$charset_collate};";

        // Create all tables
        dbDelta( $sql_designer_profiles );
        dbDelta( $sql_items );
        dbDelta( $sql_boards );
        dbDelta( $sql_board_items );
        dbDelta( $sql_board_layout );
        dbDelta( $sql_events );
        dbDelta( $sql_item_edits );
        dbDelta( $sql_firms );
        dbDelta( $sql_firm_members );
        dbDelta( $sql_board_areas );
        dbDelta( $sql_item_files );

        // Store schema version
        update_option( self::PHASE_1_1_SCHEMA_OPTION, self::PHASE_1_1_SCHEMA_VERSION );
    }

    /**
     * Create Phase 1.2 tables (Core Intelligence + Material Bank)
     */
    private static function create_phase_1_2_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // New tables for Phase 1.2
        $materials_table = $wpdb->prefix . 'n88_materials';
        $item_materials_table = $wpdb->prefix . 'n88_item_materials';
        $material_requests_table = $wpdb->prefix . 'n88_material_requests';

        // n88_materials (Phase 1.2: read-only reference bank only)
        $sql_materials = "CREATE TABLE {$materials_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            category VARCHAR(100) NULL,
            material_code VARCHAR(100) NULL,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY category (category),
            KEY material_code (material_code),
            KEY is_active (is_active),
            KEY created_at (created_at),
            KEY deleted_at (deleted_at)
        ) {$charset_collate};";

        // n88_item_materials
        $sql_item_materials = "CREATE TABLE {$item_materials_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            material_id BIGINT UNSIGNED NOT NULL,
            quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
            unit VARCHAR(50) NOT NULL DEFAULT 'unit',
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            attached_by_user_id BIGINT UNSIGNED NOT NULL,
            attached_at DATETIME NOT NULL,
            detached_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY item_material (item_id, material_id),
            KEY item_id (item_id),
            KEY material_id (material_id),
            KEY is_active (is_active),
            KEY attached_at (attached_at)
        ) {$charset_collate};";

        // n88_material_requests (schema only)
        $sql_material_requests = "CREATE TABLE {$material_requests_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NULL,
            board_id BIGINT UNSIGNED NULL,
            requested_by_user_id BIGINT UNSIGNED NOT NULL,
            material_name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            urgency VARCHAR(50) NOT NULL DEFAULT 'normal',
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY item_id (item_id),
            KEY board_id (board_id),
            KEY requested_by_user_id (requested_by_user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Create all Phase 1.2 tables
        dbDelta( $sql_materials );
        dbDelta( $sql_item_materials );
        dbDelta( $sql_material_requests );

        // Add new columns to n88_items
        $items_table = $wpdb->prefix . 'n88_items';
        $items_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $items_table );

        // Check if columns exist before adding
        $items_columns = $wpdb->get_col( "DESCRIBE {$items_table_safe}" );

        // Add sourcing_type column (after status) - NULL default, allowed: 'furniture' | 'global_sourcing'
        if ( ! in_array( 'sourcing_type', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN sourcing_type VARCHAR(50) NULL AFTER status" );
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD KEY sourcing_type (sourcing_type)" );
        }

        // Add timeline_type column (after sourcing_type)
        if ( ! in_array( 'timeline_type', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN timeline_type VARCHAR(50) NULL AFTER sourcing_type" );
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD KEY timeline_type (timeline_type)" );
        }

        // Add meta_json column (after primary_image_id) - for storing default_size and other metadata
        if ( ! in_array( 'meta_json', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN meta_json LONGTEXT NULL AFTER primary_image_id" );
        }
        
        // Add dimension columns (after primary_image_id or meta_json)
        if ( ! in_array( 'dimension_width_cm', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN dimension_width_cm DECIMAL(10,2) NULL AFTER primary_image_id" );
        }
        if ( ! in_array( 'dimension_depth_cm', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN dimension_depth_cm DECIMAL(10,2) NULL AFTER dimension_width_cm" );
        }
        if ( ! in_array( 'dimension_height_cm', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN dimension_height_cm DECIMAL(10,2) NULL AFTER dimension_depth_cm" );
        }
        if ( ! in_array( 'dimension_units_original', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN dimension_units_original VARCHAR(20) NULL AFTER dimension_height_cm" );
        }

        // Add original dimension value columns (store raw user input)
        if ( ! in_array( 'dimension_width_original', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN dimension_width_original DECIMAL(10,2) NULL AFTER dimension_units_original" );
        }
        if ( ! in_array( 'dimension_depth_original', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN dimension_depth_original DECIMAL(10,2) NULL AFTER dimension_width_original" );
        }
        if ( ! in_array( 'dimension_height_original', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN dimension_height_original DECIMAL(10,2) NULL AFTER dimension_depth_original" );
        }

        // Add cbm column (after dimension_height_original)
        if ( ! in_array( 'cbm', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN cbm DECIMAL(10,6) NULL AFTER dimension_units_original" );
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD KEY cbm (cbm)" );
        }

        // Store schema version
        update_option( self::PHASE_1_2_SCHEMA_OPTION, self::PHASE_1_2_SCHEMA_VERSION );
    }

    /**
     * Ensure any new columns are added when the plugin is updated without reactivation.
     */
    public static function maybe_upgrade() {
        static $did_upgrade = false;

        if ( $did_upgrade ) {
            return;
        }

        $did_upgrade = true;

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $projects_table     = $wpdb->prefix . 'projects';
        $comments_table     = $wpdb->prefix . 'project_comments';
        $quotes_table       = $wpdb->prefix . 'project_quotes';
        $meta_table         = $wpdb->prefix . 'project_metadata';
        $notifications_table = $wpdb->prefix . 'project_notifications';
        $audit_table        = $wpdb->prefix . 'project_audit';
        $timeline_events_table = $wpdb->prefix . 'n88_timeline_events';
        $project_videos_table = $wpdb->prefix . 'n88_project_videos';
        $charset_collate    = $wpdb->get_charset_collate();

        // Phase 1.1: Ensure Phase 1.1 tables exist
        $current_phase_1_1_version = get_option( self::PHASE_1_1_SCHEMA_OPTION, '0.0.0' );
        if ( version_compare( $current_phase_1_1_version, self::PHASE_1_1_SCHEMA_VERSION, '<' ) ) {
            self::create_phase_1_1_tables( $charset_collate );
        }

        // Phase 1.2: Ensure Phase 1.2 tables exist
        $current_phase_1_2_version = get_option( self::PHASE_1_2_SCHEMA_OPTION, '0.0.0' );
        if ( version_compare( $current_phase_1_2_version, self::PHASE_1_2_SCHEMA_VERSION, '<' ) ) {
            self::create_phase_1_2_tables( $charset_collate );
        }

        // Commit 2.2.1: Ensure required pages exist (runs on every upgrade check)
        self::create_required_pages();

        // Commit 2.2.2: Ensure supplier profiles, designer profiles, and categories tables exist
        self::create_phase_2_2_2_tables( $charset_collate );

        // Commit 2.2.5: Ensure keyword tables exist and seed keyword library
        self::create_phase_2_2_5_tables( $charset_collate );
        self::seed_keyword_library();

        // Commit 2.2.6: Ensure practice types tables exist and seed practice types
        self::create_phase_2_2_6_tables( $charset_collate );
        self::seed_practice_types();

        // Commit 2.2.9: Create RFQ routing rails and item delivery context tables
        self::create_phase_2_2_9_tables( $charset_collate );

        // Commit 2.3.1: Create bid tables (DB only, no UI)
        self::create_phase_2_3_1_tables( $charset_collate );

        // Commit 2.3.9.1A: Create prototype payments table
        self::create_phase_2_3_9_1a_tables( $charset_collate );
        
        // Commit 2.3.9.1C-a: Create item messages table
        self::create_phase_2_3_9_1c_a_tables( $charset_collate );
        
        // Commit 2.3.9.1C-a: Migrate prototype_payments table (add marked_received status and received_at column)
        self::migrate_prototype_payments_table();

        // Commit 2.3.9.2A: Migrate item_files table for CAD metadata
        self::migrate_item_files_table_for_cad();
        
        // Commit 2.3.9.1C-B: Create clarifications table
        self::create_phase_2_3_9_1c_b_tables( $charset_collate );

        // Fix #13/#26: Create case resolutions table (Mark Resolved)
        self::create_case_resolutions_table( $charset_collate );

        // Payment receipts (designer upload JPG/PDF; operator views before mark received)
        self::create_payment_receipts_table( $charset_collate );
        self::add_payment_receipt_message_column();
        
        // Commit 2.3.9.2B-S: Create prototype video submission tables
        self::create_phase_2_3_9_2b_tables( $charset_collate );
        
        // Commit 2.3.9.2B-D: Create designer prototype review tables
        self::create_phase_2_3_9_2b_d_tables( $charset_collate );
        self::seed_keyword_phrases();
        
        // Commit 2.3.9.2B-D: Add prototype_status fields to n88_prototype_payments
        self::add_prototype_status_fields();

        // Commit 2.3.10: Migrate item_delivery_context table for delivery quoting
        self::migrate_item_delivery_context_for_delivery_quoting();

        // Commit 2.6.1: Migrate firm_members table for pending invitations
        self::migrate_firm_members_for_invitations();

        // Commit 3.A.1: Item Timeline Spine (immutable 6-step per item)
        self::create_phase_3_a_1_item_timeline_tables( $charset_collate );

        // Commit 3.A.2: Timeline Step Evidence (media bound to item + step; append-only)
        self::create_phase_3_a_2_timeline_step_evidence_table( $charset_collate );

        // Ensure core tables exist (handles upgrades where plugin wasn't reactivated)
        $table_schemas = array(
            $projects_table => "CREATE TABLE {$projects_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                project_name VARCHAR(255) NOT NULL,
                project_type VARCHAR(100) NOT NULL DEFAULT '',
                timeline VARCHAR(100) NOT NULL DEFAULT '',
                budget_range VARCHAR(100) NOT NULL DEFAULT '',
                status TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                submitted_at DATETIME NULL,
                updated_by BIGINT UNSIGNED NULL,
                quote_type VARCHAR(100) NULL,
                item_count INT UNSIGNED DEFAULT 0,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY status (status),
                KEY updated_by (updated_by)
            ) {$charset_collate};",
            $meta_table => "CREATE TABLE {$meta_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                meta_key VARCHAR(255) NOT NULL,
                meta_value LONGTEXT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY meta_key (meta_key)
            ) {$charset_collate};",
            $comments_table => "CREATE TABLE {$comments_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                item_id VARCHAR(255) NULL,
                video_id VARCHAR(255) NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                comment_text LONGTEXT NOT NULL,
                is_urgent TINYINT(1) NOT NULL DEFAULT 0,
                parent_comment_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY item_id (item_id),
                KEY video_id (video_id),
                KEY user_id (user_id),
                KEY is_urgent (is_urgent),
                KEY parent_comment_id (parent_comment_id),
                KEY created_at (created_at)
            ) {$charset_collate};",
            $quotes_table => "CREATE TABLE {$quotes_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                quote_file_path VARCHAR(500) NULL,
                admin_notes LONGTEXT NULL,
                quote_status VARCHAR(50) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                labor_cost DECIMAL(10,2) NULL DEFAULT 0.00,
                materials_cost DECIMAL(10,2) NULL DEFAULT 0.00,
                overhead_cost DECIMAL(10,2) NULL DEFAULT 0.00,
                margin_percentage DECIMAL(5,2) NULL DEFAULT 0.00,
                shipping_zone VARCHAR(100) NULL,
                unit_price DECIMAL(10,2) NULL DEFAULT 0.00,
                total_price DECIMAL(10,2) NULL DEFAULT 0.00,
                lead_time VARCHAR(50) NULL,
                cbm_volume DECIMAL(10,4) NULL DEFAULT 0.0000,
                volume_rules_applied TEXT NULL,
                client_message LONGTEXT NULL,
                quote_items LONGTEXT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY user_id (user_id),
                KEY quote_status (quote_status)
            ) {$charset_collate};",
            $notifications_table => "CREATE TABLE {$notifications_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                notification_type VARCHAR(100) NOT NULL,
                message LONGTEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY user_id (user_id),
                KEY notification_type (notification_type),
                KEY is_read (is_read)
            ) {$charset_collate};",
            $audit_table => "CREATE TABLE {$audit_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                action VARCHAR(100) NOT NULL,
                field_name VARCHAR(255) NULL,
                old_value LONGTEXT NULL,
                new_value LONGTEXT NULL,
                ip_address VARCHAR(100) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY user_id (user_id),
                KEY action (action),
                KEY created_at (created_at)
            ) {$charset_collate};",
            $timeline_events_table => "CREATE TABLE {$timeline_events_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                item_id INT UNSIGNED NOT NULL COMMENT 'Item index within project (0-based)',
                step_key VARCHAR(50) NOT NULL COMMENT 'e.g. prototype, frame_structure, surface_treatment, sourcing, qc, packing',
                event_type VARCHAR(30) NOT NULL COMMENT 'step_started, step_completed, step_reopened, step_delayed, step_unblocked, override_applied, file_added, comment_added, video_added, note_added, status_changed',
                status VARCHAR(30) DEFAULT NULL COMMENT 'pending, in_progress, completed, blocked, delayed',
                event_data LONGTEXT NULL COMMENT 'JSON blob for extra data',
                created_at DATETIME NOT NULL,
                created_by BIGINT UNSIGNED NULL COMMENT 'User ID who triggered the event',
                PRIMARY KEY (id),
                KEY idx_project_item (project_id, item_id),
                KEY idx_item_step (item_id, step_key),
                KEY idx_project (project_id),
                KEY idx_event_type (event_type, created_at),
                KEY idx_status (status),
                KEY idx_created_at (created_at),
                KEY idx_created_by (created_by)
            ) {$charset_collate};",
            $project_videos_table => "CREATE TABLE {$project_videos_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                item_id INT UNSIGNED NULL COMMENT 'Item index (0-based), NULL if project-level video',
                step_key VARCHAR(50) NULL COMMENT 'Timeline step key, NULL if item-level or project-level',
                youtube_id VARCHAR(20) NOT NULL COMMENT 'YouTube video ID (extracted from URL)',
                youtube_url VARCHAR(255) NOT NULL COMMENT 'Full YouTube URL (always youtube-nocookie.com)',
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                thumbnail_attachment_id BIGINT UNSIGNED NULL COMMENT 'WP Media Library attachment ID for custom thumbnail',
                display_order INT UNSIGNED DEFAULT 0 COMMENT 'Order within step/item/project',
                created_at DATETIME NOT NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_project (project_id),
                KEY idx_item (item_id),
                KEY idx_step (step_key),
                KEY idx_project_item_step (project_id, item_id, step_key),
                KEY idx_youtube_id (youtube_id),
                KEY idx_created_at (created_at)
            ) {$charset_collate};",
        );

        foreach ( $table_schemas as $table_name => $schema ) {
            if ( ! self::table_exists( $table_name ) ) {
                dbDelta( $schema );
            }
        }

        // Comments table upgrades
        if ( self::table_exists( $comments_table ) ) {
            // Table name is safe (from $wpdb->prefix), but we validate it contains only safe characters
            $comments_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $comments_table );
            $comments_columns = $wpdb->get_col( "DESCRIBE {$comments_table_safe}" );

            if ( ! in_array( 'video_id', $comments_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$comments_table_safe} ADD COLUMN video_id VARCHAR(255) NULL AFTER item_id" );
                $wpdb->query( "ALTER TABLE {$comments_table_safe} ADD KEY video_id (video_id)" );
            }

            if ( ! in_array( 'is_urgent', $comments_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$comments_table_safe} ADD COLUMN is_urgent TINYINT(1) NOT NULL DEFAULT 0 AFTER comment_text" );
                $wpdb->query( "ALTER TABLE {$comments_table_safe} ADD KEY is_urgent (is_urgent)" );
            }

            if ( ! in_array( 'parent_comment_id', $comments_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$comments_table_safe} ADD COLUMN parent_comment_id BIGINT UNSIGNED NULL AFTER is_urgent" );
                $wpdb->query( "ALTER TABLE {$comments_table_safe} ADD KEY parent_comment_id (parent_comment_id)" );
            }
        }

        // Projects table upgrades
        if ( self::table_exists( $projects_table ) ) {
            // Table name is safe (from $wpdb->prefix), but we validate it contains only safe characters
            $projects_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $projects_table );
            $projects_columns = $wpdb->get_col( "DESCRIBE {$projects_table_safe}" );

            if ( ! in_array( 'item_count', $projects_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$projects_table_safe} ADD COLUMN item_count INT UNSIGNED DEFAULT 0 AFTER quote_type" );
            }
        }

        // Quotes table upgrades
        if ( self::table_exists( $quotes_table ) ) {
            // Table name is safe (from $wpdb->prefix), but we validate it contains only safe characters
            $quotes_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $quotes_table );
            $quotes_columns = $wpdb->get_col( "DESCRIBE {$quotes_table_safe}" );
            $pricing_columns = array(
                'labor_cost'          => "ALTER TABLE {$quotes_table_safe} ADD COLUMN labor_cost DECIMAL(10,2) NULL DEFAULT 0.00 AFTER sent_at",
                'materials_cost'      => "ALTER TABLE {$quotes_table_safe} ADD COLUMN materials_cost DECIMAL(10,2) NULL DEFAULT 0.00 AFTER labor_cost",
                'overhead_cost'       => "ALTER TABLE {$quotes_table_safe} ADD COLUMN overhead_cost DECIMAL(10,2) NULL DEFAULT 0.00 AFTER materials_cost",
                'margin_percentage'   => "ALTER TABLE {$quotes_table_safe} ADD COLUMN margin_percentage DECIMAL(5,2) NULL DEFAULT 0.00 AFTER overhead_cost",
                'shipping_zone'       => "ALTER TABLE {$quotes_table_safe} ADD COLUMN shipping_zone VARCHAR(100) NULL AFTER margin_percentage",
                'unit_price'          => "ALTER TABLE {$quotes_table_safe} ADD COLUMN unit_price DECIMAL(10,2) NULL DEFAULT 0.00 AFTER shipping_zone",
                'total_price'         => "ALTER TABLE {$quotes_table_safe} ADD COLUMN total_price DECIMAL(10,2) NULL DEFAULT 0.00 AFTER unit_price",
                'lead_time'           => "ALTER TABLE {$quotes_table_safe} ADD COLUMN lead_time VARCHAR(50) NULL AFTER total_price",
                'cbm_volume'          => "ALTER TABLE {$quotes_table_safe} ADD COLUMN cbm_volume DECIMAL(10,4) NULL DEFAULT 0.0000 AFTER lead_time",
                'volume_rules_applied'=> "ALTER TABLE {$quotes_table_safe} ADD COLUMN volume_rules_applied TEXT NULL AFTER cbm_volume",
            );

            foreach ( $pricing_columns as $column => $sql ) {
                if ( ! in_array( $column, $quotes_columns, true ) ) {
                    $wpdb->query( $sql );
                }
            }

            // Add client_message and quote_items columns
            if ( ! in_array( 'client_message', $quotes_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$quotes_table_safe} ADD COLUMN client_message LONGTEXT NULL AFTER volume_rules_applied" );
            }
            if ( ! in_array( 'quote_items', $quotes_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$quotes_table_safe} ADD COLUMN quote_items LONGTEXT NULL AFTER client_message" );
            }
        }

        // Milestone 1.3: Board layout snapshot persistence
        $boards_table = $wpdb->prefix . 'n88_boards';
        if ( self::table_exists( $boards_table ) ) {
            $boards_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $boards_table );
            $boards_columns = $wpdb->get_col( "DESCRIBE {$boards_table_safe}" );

            if ( ! in_array( 'latest_layout_json', $boards_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$boards_table_safe} ADD COLUMN latest_layout_json LONGTEXT NULL AFTER deleted_at" );
            }
        }
    }

    /**
     * Create Phase 2.2.2 tables: Supplier Profiles, Designer Profiles, Categories (Commit 2.2.2)
     * Data-only commit: No UI, routing, bidding, or Phase 2.3 logic
     * 
     * Note: The Phase 2.2.2 designer profiles table is named n88_designer_profiles_v2 to avoid
     * conflicts with the existing Phase 1.1 n88_designer_profiles table. Migration will be
     * handled in a dedicated milestone.
     */
    private static function create_phase_2_2_2_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $supplier_profiles_table = $wpdb->prefix . 'n88_supplier_profiles';
        $designer_profiles_table = $wpdb->prefix . 'n88_designer_profiles_v2';
        $categories_table = $wpdb->prefix . 'n88_categories';

        // 1. n88_categories (must be created first due to FK dependency)
        $sql_categories = "CREATE TABLE {$categories_table} (
            category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (category_id),
            UNIQUE KEY name (name),
            KEY is_active (is_active)
        ) {$charset_collate};";

        // 2. n88_supplier_profiles (without FK constraints - added separately)
        $sql_supplier_profiles = "CREATE TABLE {$supplier_profiles_table} (
            supplier_id INT UNSIGNED NOT NULL,
            company_name VARCHAR(255) NOT NULL,
            display_nickname VARCHAR(255) NULL,
            contact_name VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(50) NULL,
            country_code CHAR(2) NULL,
            state_region VARCHAR(255) NULL,
            city VARCHAR(255) NULL,
            postal_code VARCHAR(50) NULL,
            address_line1 VARCHAR(500) NULL,
            origin_country_code CHAR(2) NULL,
            origin_region ENUM('USA', 'CANADA', 'ASIA', 'EUROPE', 'MIDDLE_EAST', 'OTHER') NULL,
            duty_rate_override DECIMAL(5,2) NULL,
            primary_category_id INT UNSIGNED NULL,
            prototype_video_capable TINYINT(1) NOT NULL DEFAULT 0,
            cad_capable TINYINT(1) NOT NULL DEFAULT 0,
            qty_min INT UNSIGNED NULL,
            qty_max INT UNSIGNED NULL,
            lead_time_min_days INT UNSIGNED NULL,
            lead_time_max_days INT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_overloaded TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (supplier_id),
            KEY primary_category_id (primary_category_id),
            KEY origin_region (origin_region),
            KEY is_active (is_active)
        ) {$charset_collate};";

        // 3. n88_designer_profiles_v2 (without FK constraints - added separately)
        $sql_designer_profiles = "CREATE TABLE {$designer_profiles_table} (
            designer_id INT UNSIGNED NOT NULL,
            firm_name VARCHAR(255) NOT NULL,
            display_nickname VARCHAR(255) NULL,
            contact_name VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(50) NULL,
            country_code CHAR(2) NULL,
            state_region VARCHAR(255) NULL,
            city VARCHAR(255) NULL,
            postal_code VARCHAR(50) NULL,
            address_line1 VARCHAR(500) NULL,
            default_allow_system_invites TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (designer_id),
            KEY country_code (country_code)
        ) {$charset_collate};";

        // Create tables using dbDelta
        dbDelta( $sql_categories );
        dbDelta( $sql_supplier_profiles );
        dbDelta( $sql_designer_profiles );

        // Add foreign key constraints separately (dbDelta doesn't handle them well)
        $users_table = $wpdb->users;
        $supplier_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $supplier_profiles_table );
        $designer_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $designer_profiles_table );
        $categories_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $categories_table );
        $users_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $users_table );

        // Check if foreign keys exist before adding (using information_schema for reliability)
        $supplier_has_fk_user = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_supplier_user'",
            DB_NAME,
            $supplier_profiles_table
        ) );

        $supplier_has_fk_category = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_supplier_category'",
            DB_NAME,
            $supplier_profiles_table
        ) );

        $designer_has_fk_user = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_designer_user'",
            DB_NAME,
            $designer_profiles_table
        ) );

        // Add foreign key: supplier_id -> wp_users.ID
        if ( ! $supplier_has_fk_user ) {
            self::safe_add_foreign_key( $supplier_profiles_table, 'fk_supplier_user', 'supplier_id', $users_table, 'ID', 'CASCADE' );
        }

        // Add foreign key: primary_category_id -> n88_categories.category_id
        if ( ! $supplier_has_fk_category ) {
            self::safe_add_foreign_key( $supplier_profiles_table, 'fk_supplier_category', 'primary_category_id', $categories_table, 'category_id', 'SET NULL' );
        }

        // Add foreign key: designer_id -> wp_users.ID
        if ( ! $designer_has_fk_user ) {
            self::safe_add_foreign_key( $designer_profiles_table, 'fk_designer_user', 'designer_id', $users_table, 'ID', 'CASCADE' );
        }
    }

    /**
     * Create Phase 2.2.5 tables: Keywords, Supplier Keyword Mapping, Freeform Keywords (Commit 2.2.5)
     * Data-only commit: No UI, routing, bidding, or Phase 2.3 logic
     */
    private static function create_phase_2_2_5_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $keywords_table = $wpdb->prefix . 'n88_keywords';
        $supplier_keyword_map_table = $wpdb->prefix . 'n88_supplier_keyword_map';
        $supplier_keyword_freeform_table = $wpdb->prefix . 'n88_supplier_keyword_freeform';
        $categories_table = $wpdb->prefix . 'n88_categories';
        $users_table = $wpdb->users;

        // 1. n88_keywords (master keyword library)
        $sql_keywords = "CREATE TABLE {$keywords_table} (
            keyword_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id INT UNSIGNED NULL,
            keyword VARCHAR(255) NOT NULL,
            is_suggested TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (keyword_id),
            KEY category_id (category_id),
            KEY is_active (is_active)
        ) {$charset_collate};";

        // 2. n88_supplier_keyword_map (maps suppliers to approved keywords)
        $sql_supplier_keyword_map = "CREATE TABLE {$supplier_keyword_map_table} (
            supplier_id INT UNSIGNED NOT NULL,
            keyword_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (supplier_id, keyword_id),
            KEY supplier_id (supplier_id),
            KEY keyword_id (keyword_id)
        ) {$charset_collate};";

        // 3. n88_supplier_keyword_freeform (freeform keywords from suppliers)
        $sql_supplier_keyword_freeform = "CREATE TABLE {$supplier_keyword_freeform_table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_id INT UNSIGNED NOT NULL,
            freeform_keyword VARCHAR(255) NOT NULL,
            status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY supplier_id (supplier_id),
            KEY status (status)
        ) {$charset_collate};";

        // Create tables using dbDelta
        dbDelta( $sql_keywords );
        dbDelta( $sql_supplier_keyword_map );
        dbDelta( $sql_supplier_keyword_freeform );

        // Add foreign key constraints separately
        $keywords_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $keywords_table );
        $supplier_keyword_map_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $supplier_keyword_map_table );
        $supplier_keyword_freeform_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $supplier_keyword_freeform_table );
        $categories_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $categories_table );
        $users_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $users_table );

        // Check if foreign keys exist before adding (using information_schema for reliability)
        $keywords_has_fk_category = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_keyword_category'",
            DB_NAME,
            $keywords_table
        ) );

        $map_has_fk_supplier = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_map_supplier'",
            DB_NAME,
            $supplier_keyword_map_table
        ) );

        $map_has_fk_keyword = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_map_keyword'",
            DB_NAME,
            $supplier_keyword_map_table
        ) );

        $freeform_has_fk_supplier = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_freeform_supplier'",
            DB_NAME,
            $supplier_keyword_freeform_table
        ) );

        // Add foreign key: category_id -> n88_categories.category_id
        if ( ! $keywords_has_fk_category ) {
            self::safe_add_foreign_key( $keywords_table, 'fk_keyword_category', 'category_id', $categories_table, 'category_id', 'SET NULL' );
        }

        // Add foreign key: supplier_id -> wp_users.ID (map table)
        if ( ! $map_has_fk_supplier ) {
            self::safe_add_foreign_key( $supplier_keyword_map_table, 'fk_map_supplier', 'supplier_id', $users_table, 'ID', 'CASCADE' );
        }

        // Add foreign key: keyword_id -> n88_keywords.keyword_id (map table)
        if ( ! $map_has_fk_keyword ) {
            self::safe_add_foreign_key( $supplier_keyword_map_table, 'fk_map_keyword', 'keyword_id', $keywords_table, 'keyword_id', 'CASCADE' );
        }

        // Add foreign key: supplier_id -> wp_users.ID (freeform table)
        if ( ! $freeform_has_fk_supplier ) {
            self::safe_add_foreign_key( $supplier_keyword_freeform_table, 'fk_freeform_supplier', 'supplier_id', $users_table, 'ID', 'CASCADE' );
        }
    }

    /**
     * Seed keyword library with suggested keywords per SOT (Commit 2.2.5)
     * Seeds keywords exactly as specified, linked to correct categories
     * 
     * IDEMPOTENT: This function is safe to run multiple times.
     * - Checks for existing categories before inserting (no duplicates)
     * - Checks for existing keywords before inserting (no duplicates)
     * - No TRUNCATE, DELETE, or DROP operations
     * - Only inserts missing records
     */
    private static function seed_keyword_library() {
        global $wpdb;

        $categories_table = $wpdb->prefix . 'n88_categories';
        $keywords_table = $wpdb->prefix . 'n88_keywords';

        // First, ensure categories exist (create if they don't)
        // IDEMPOTENT: Checks for existing category by exact name match before inserting
        $categories = array(
            // Existing furniture categories
            'Indoor Furniture',
            'Sofas & Seating (Indoor)',
            'Chairs & Armchairs (Indoor)',
            'Dining Tables (Indoor)',
            'Cabinetry / Millwork (Custom)',
            'Casegoods (Beds, Nightstands, Desks, Consoles)',
            'Outdoor Furniture',
            'Outdoor Seating',
            'Outdoor Dining Sets',
            'Outdoor Loungers & Daybeds',
            'Pool Furniture',
            // Lighting
            'Lighting',
            'Decorative Lighting',
            'Architectural Lighting',
            'Electrical / LED Components',
            // Plumbing + Bath + Kitchen
            'Bathroom Fixtures',
            'Kitchen Fixtures',
            'Faucets / Hardware (Plumbing)',
            'Sinks / Basins',
            'Shower Systems / Accessories',
            // Surfaces + Stone
            'Marble / Stone',
            'Granite',
            'Quartz',
            'Porcelain / Ceramic Slabs',
            'Tile (Wall / Floor)',
            'Terrazzo',
            // Soft Goods
            'Rugs / Carpets',
            'Drapery',
            'Window Treatments / Shades',
            'Wallcoverings',
            'Acoustic Panels',
            // Decor + Accessories
            'Mirrors',
            'Artwork',
            'Decorative Accessories',
            'Planters',
            'Sculptural Objects',
            // Architectural / Exterior
            'Railings',
            'Screens / Louvers',
            'Pergola / Shade Components',
            'Facade Materials',
            // Other
            'Material Sample Kit',
            'Fabric Sample',
            'Custom Sourcing / Not Listed',
            // New categories for CAD + Prototype Video keywords (Commit 2.3.9.1B)
            'Upholstery',
            'Indoor Furniture (Casegoods)',
            'Stone (Marble / Granite / Quartz)',
            'Metalwork',
            'Millwork / Cabinetry',
            'Flooring',
            'Drapery / Window Treatments',
            'Glass / Mirrors',
            'Hardware / Accessories',
            'Wallcoverings / Finishes',
            'Appliances',
            'Other'
        );

        $category_ids = array();

        foreach ( $categories as $category_name ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT category_id FROM {$categories_table} WHERE name = %s",
                $category_name
            ) );

            if ( $existing ) {
                $category_ids[ $category_name ] = $existing;
            } else {
                $wpdb->insert(
                    $categories_table,
                    array(
                        'name' => $category_name,
                        'is_active' => 1,
                    ),
                    array( '%s', '%d' )
                );
                $category_ids[ $category_name ] = $wpdb->insert_id;
            }
        }

        // Define keywords by category (Updated with new CAD + Prototype Video keywords)
        // Must match dropdown: UPHOLSTERY, INDOOR FURNITURE (CASEGOODS), etc. DB stores category name as Title Case (e.g. Upholstery).
        $keywords_by_category = array(
            'Upholstery' => array(
                'HEIGHT-MEASUREMENT',
                'STITCH-DETAIL',
                'FIRMNESS-TEST',
                'DEPTH-VERIFICATION',
                'ARM-MEASUREMENT',
                'BACK-HEIGHT',
                'DIMENSION-OVERLAY',
                'FOAM-LAYERS',
                'SUSPENSION-STRUCTURE',
                'TENSION-CHECK'
            ),
            'Indoor Furniture (Casegoods)' => array(
                'JOINERY-DETAIL',
                'FINISH-TYPE',
                'GLIDE-OPERATION',
                'INTERIOR-DIMENSIONS',
                'REVEAL-SPACING',
                'STABILITY-TEST',
                'VENEER-CONSISTENCY',
                'SOFT-CLOSE-DEMO',
                'LEVELING-FEET',
                'EDGE-QUALITY'
            ),
            'Outdoor Furniture' => array(
                'FRAME-CLOSEUP',
                'POWDER-COATING',
                'DRAINAGE-TEST',
                'WELD-QUALITY',
                'FRAME-THICKNESS',
                'WEAVE-DETAIL',
                'QUICK-DRY-DEMO',
                'STACKABILITY-CHECK',
                'DAYLIGHT-VIEW',
                'CUSHION-THICKNESS'
            ),
            'Lighting' => array(
                'OUTPUT-POWER',
                'DIMMING-DEMO',
                'CANOPY-DETAIL',
                'SCALE-REFERENCE',
                'WIRING-CLOSEUP',
                'MOUNTING-METHOD',
                'GLASS-QUALITY',
                'COLOR-TEMP',
                'GLARE-CONTROL',
                'CERTIFICATION-LABEL'
            ),
            'Stone (Marble / Granite / Quartz)' => array(
                'VEIN-DETAIL',
                'EDGE-PROFILE',
                'SLAB-OVERVIEW',
                'COLOR-CONSISTENCY',
                'REFLECTION-TEST',
                'THICKNESS-MEASURE',
                'BOOKMATCH-ALIGN',
                'SHADE-VARIATION',
                'TEXTURE-CLOSEUP',
                'SEAM-ALIGNMENT'
            ),
            'Metalwork' => array(
                'WELD-QUALITY',
                'CNC-DETAIL',
                'BEND-CONSISTENCY',
                'FINISH-UNIFORMITY',
                'PATINA-VARIATION',
                'RIGIDITY-DEMO',
                'GAUGE-MEASURE',
                'HARDWARE-INTEGRATION',
                'DIMENSION-OVERLAY',
                'COATING-THICKNESS'
            ),
            'Millwork / Cabinetry' => array(
                'VENEER-MATCHING',
                'REVEAL-ALIGNMENT',
                'DRAWER-BOX',
                'HINGE-ALIGNMENT',
                'CARCASS-CONSTRUCTION',
                'PAINT-CONSISTENCY',
                'LAMINATE-EDGE',
                'GLIDE-TEST',
                'DIMENSION-VERIFY',
                'FIT-INTEGRATION'
            ),
            'Flooring' => array(
                'PATTERN-VIEW',
                'GRAIN-TEXTURE',
                'LOCKING-SYSTEM',
                'SLIP-RESISTANCE',
                'EDGE-DETAIL',
                'COLOR-RANGE',
                'UNDERLAYMENT-PROOF',
                'MOISTURE-BARRIER',
                'THICKNESS-CHECK',
                'SURFACE-REFLECT'
            ),
            'Drapery / Window Treatments' => array(
                'MOTION-OPERATION',
                'DROP-LENGTH',
                'STACK-BACK',
                'FABRIC-TEXTURE',
                'COM-APPLICATION',
                'MOTORIZED-DEMO',
                'TRACK-DETAIL',
                'HEM-FINISH',
                'LIGHT-LEAK',
                'PLEAT-ALIGNMENT'
            ),
            'Glass / Mirrors' => array(
                'THICKNESS-MEASUREMENT',
                'EDGE-FINISH',
                'SAFETY-MARKING',
                'TINT-CONSISTENCY',
                'BEVEL-DETAIL',
                'BACK-PAINT',
                'DISTORTION-CHECK',
                'HARDWARE-SEATING',
                'CLARITY-TEST',
                'MOUNTING-STABILITY'
            ),
            'Hardware / Accessories' => array(
                'GAUGE-VERIFICATION',
                'MACHINING-DETAIL',
                'FINISH-UNIFORMITY',
                'WEAR-SURFACE',
                'MOUNTING-DEMO',
                'FUNCTION-TEST',
                'SPRING-ACTION',
                'THREADING-CHECK',
                'SCALE-CHECK',
                'COMPONENT-FIT'
            ),
            'Rugs / Carpets' => array(
                'PATTERN-CONTINUITY',
                'TONE-VARIATION',
                'BACKING-DETAIL',
                'BINDING-QUALITY',
                'PILE-HEIGHT',
                'RESISTANCE-DEMO',
                'KNOT-DENSITY',
                'SHEDDING-TEST',
                'FRINGE-DETAIL',
                'LUSTR-CHECK'
            ),
            'Appliances' => array(
                'CAVITY-FIT',
                'REVEAL-ALIGNMENT',
                'STARTUP-SEQUENCE',
                'INTERFACE-LEGIBILITY',
                'SPEC-LABEL',
                'DOOR-CLEARANCE',
                'INTERIOR-LAYOUT',
                'VENTILATION-GAP',
                'INDICATOR-LIGHTS',
                'SOUND-LEVEL'
            ),
            'Wallcoverings / Finishes' => array(
                'ALIGNMENT-CHECK',
                'TEXTURE-DEPTH',
                'SEAM-DETAIL',
                'CORNER-TRANSITION',
                'CLEANABILITY-PROOF',
                'PANEL-THICKNESS',
                'ADHESIVE-CHECK',
                'PEEL-STRENGTH',
                'GRAIN-DIRECTION',
                'SUBSTRATE-TEXTURE'
            ),
            'Other' => array(
                'DIMENSION-OVERLAY',
                'FINISH-DETAIL',
                'MATERIAL-CLOSEUP'
            )
        );

        // Categories that have been updated with comprehensive CAD + Prototype Video keywords (Commit 2.3.9.1B)
        // For these categories, we'll DEACTIVATE ALL old keywords first, then only activate the new comprehensive ones
        $updated_categories = array(
            'Upholstery',
            'Indoor Furniture (Casegoods)',
            'Outdoor Furniture',
            'Lighting',
            'Stone (Marble / Granite / Quartz)',
            'Metalwork',
            'Millwork / Cabinetry',
            'Flooring',
            'Drapery / Window Treatments',
            'Glass / Mirrors',
            'Hardware / Accessories',
            'Rugs / Carpets',
            'Wallcoverings / Finishes',
            'Appliances',
            'Other'
        );

        // Map old category names to new category names (for cleanup of renamed categories)
        $category_name_mappings = array(
            'Marble / Stone' => 'Stone (Marble / Granite / Quartz)',
            'Mirrors' => 'Glass / Mirrors',
            'Drapery' => 'Drapery / Window Treatments',
            'Wallcoverings' => 'Wallcoverings / Finishes',
            'Indoor Furniture' => 'Indoor Furniture (Casegoods)'
        );

        // STEP 1: Clean up old category keywords (deactivate ALL keywords in old categories that were renamed)
        foreach ( $category_name_mappings as $old_category_name => $new_category_name ) {
            $old_category_id = isset( $category_ids[ $old_category_name ] ) ? $category_ids[ $old_category_name ] : null;
            if ( $old_category_id ) {
                // Deactivate ALL keywords in the old category (they should be in the new category now)
                $wpdb->update(
                    $keywords_table,
                    array( 'is_active' => 0 ),
                    array( 'category_id' => $old_category_id ),
                    array( '%d' ),
                    array( '%d' )
                );
            }
        }

        // STEP 2: For updated categories, deactivate ALL existing keywords first (clean slate approach)
        foreach ( $updated_categories as $category_name ) {
            $category_id = isset( $category_ids[ $category_name ] ) ? $category_ids[ $category_name ] : null;
            if ( $category_id ) {
                // Deactivate ALL existing keywords for this category (we'll reactivate only the new ones below)
                $wpdb->update(
                    $keywords_table,
                    array( 'is_active' => 0 ),
                    array( 'category_id' => $category_id ),
                    array( '%d' ),
                    array( '%d' )
                );
            }
        }

        // Insert keywords, avoiding duplicates
        // IDEMPOTENT: Checks for existing keyword by exact match (keyword + category_id) before inserting
        // For updated categories, we already deactivated ALL old keywords above (clean slate approach)
        // Now we only activate the new comprehensive keywords
        foreach ( $keywords_by_category as $category_name => $keywords ) {
            $category_id = isset( $category_ids[ $category_name ] ) ? $category_ids[ $category_name ] : null;
            
            if ( ! $category_id ) {
                continue;
            }

            // Skip empty keyword arrays (for replaced categories that have no keywords in the old structure)
            if ( empty( $keywords ) || ! is_array( $keywords ) ) {
                continue;
            }

            // For updated categories, we already deactivated ALL old keywords above
            // Now we only activate the new comprehensive keywords (clean slate approach)

            foreach ( $keywords as $keyword ) {
                // Skip empty keywords
                if ( empty( trim( $keyword ) ) ) {
                    continue;
                }
                // Check if keyword already exists (exact match on keyword text and category_id)
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT keyword_id FROM {$keywords_table} WHERE keyword = %s AND category_id = %d",
                    $keyword,
                    $category_id
                ) );

                if ( $existing ) {
                    // If keyword exists but is inactive, reactivate it
                    $wpdb->update(
                        $keywords_table,
                        array( 'is_active' => 1, 'is_suggested' => 1 ),
                        array( 'keyword_id' => $existing ),
                        array( '%d', '%d' ),
                        array( '%d' )
                    );
                } else {
                // Only insert if keyword doesn't exist (idempotent - safe to run multiple times)
                    $wpdb->insert(
                        $keywords_table,
                        array(
                            'category_id' => $category_id,
                            'keyword' => $keyword,
                            'is_suggested' => 1,
                            'is_active' => 1,
                        ),
                        array( '%d', '%s', '%d', '%d' )
                    );
                }
            }
        }
    }

    /**
     * Create Phase 2.2.6 tables: Practice Types and Designer Practice Mapping (Commit 2.2.6)
     * Data-only commit: No UI, routing, bidding, or Phase 2.3 logic
     */
    private static function create_phase_2_2_6_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $practice_types_table = $wpdb->prefix . 'n88_practice_types';
        $designer_practice_map_table = $wpdb->prefix . 'n88_designer_practice_map';
        $users_table = $wpdb->users;

        // 1. n88_practice_types (master practice types library)
        $sql_practice_types = "CREATE TABLE {$practice_types_table} (
            practice_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            PRIMARY KEY (practice_id),
            UNIQUE KEY name (name)
        ) {$charset_collate};";

        // 2. n88_designer_practice_map (maps designers to practice types)
        $sql_designer_practice_map = "CREATE TABLE {$designer_practice_map_table} (
            designer_id INT UNSIGNED NOT NULL,
            practice_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (designer_id, practice_id),
            KEY designer_id (designer_id),
            KEY practice_id (practice_id)
        ) {$charset_collate};";

        // Create tables using dbDelta
        dbDelta( $sql_practice_types );
        dbDelta( $sql_designer_practice_map );

        // Add foreign key constraints separately
        $practice_types_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $practice_types_table );
        $designer_practice_map_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $designer_practice_map_table );
        $users_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $users_table );

        // Check if foreign keys exist before adding (using information_schema for reliability)
        $map_has_fk_designer = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_practice_map_designer'",
            DB_NAME,
            $designer_practice_map_table
        ) );

        $map_has_fk_practice = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_practice_map_practice'",
            DB_NAME,
            $designer_practice_map_table
        ) );

        // Add foreign key: designer_id -> wp_users.ID (map table)
        if ( ! $map_has_fk_designer ) {
            self::safe_add_foreign_key( $designer_practice_map_table, 'fk_practice_map_designer', 'designer_id', $users_table, 'ID', 'CASCADE' );
        }

        // Add foreign key: practice_id -> n88_practice_types.practice_id (map table)
        if ( ! $map_has_fk_practice ) {
            self::safe_add_foreign_key( $designer_practice_map_table, 'fk_practice_map_practice', 'practice_id', $practice_types_table, 'practice_id', 'CASCADE' );
        }
    }

    /**
     * Seed practice types library (Commit 2.2.6)
     * Seeds practice types exactly as specified
     * 
     * IDEMPOTENT: This function is safe to run multiple times.
     * - Checks for existing practice types before inserting (no duplicates)
     * - No TRUNCATE, DELETE, or DROP operations
     * - Only inserts missing records
     */
    private static function seed_practice_types() {
        global $wpdb;

        $practice_types_table = $wpdb->prefix . 'n88_practice_types';

        // Practice types to seed (exactly as specified)
        $practice_types = array(
            'Hospitality',
            'Luxury Residential',
            'Multi-Family',
            'Commercial',
            'Office/Workplace',
            'Retail',
            'F&B/Restaurants',
            'Healthcare',
            'Other'
        );

        // Insert practice types, avoiding duplicates
        // IDEMPOTENT: Checks for existing practice type by exact name match before inserting
        foreach ( $practice_types as $practice_name ) {
            // Check if practice type already exists
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT practice_id FROM {$practice_types_table} WHERE name = %s",
                $practice_name
            ) );

            // Only insert if practice type doesn't exist (idempotent - safe to run multiple times)
            if ( ! $existing ) {
                $wpdb->insert(
                    $practice_types_table,
                    array(
                        'name' => $practice_name,
                    ),
                    array( '%s' )
                );
            }
        }
    }

    /**
     * Verification query to check keyword counts per category (Commit 2.2.5)
     * Run this query to verify category linkage is correct and no duplicate categories exist
     * 
     * SQL Query:
     * SELECT 
     *     c.category_id,
     *     c.name AS category_name,
     *     COUNT(k.keyword_id) AS keyword_count
     * FROM {$wpdb->prefix}n88_categories c
     * LEFT JOIN {$wpdb->prefix}n88_keywords k ON c.category_id = k.category_id
     * WHERE c.is_active = 1
     * GROUP BY c.category_id, c.name
     * ORDER BY c.name;
     * 
     * Expected results:
     * - 14 categories total
     * - Each category should have the correct keyword count (see SOT)
     * - No duplicate category names (case-sensitive check)
     */
    public static function verify_keyword_seeding() {
        global $wpdb;
        
        $categories_table = $wpdb->prefix . 'n88_categories';
        $keywords_table = $wpdb->prefix . 'n88_keywords';
        
        $results = $wpdb->get_results(
            "SELECT 
                c.category_id,
                c.name AS category_name,
                COUNT(k.keyword_id) AS keyword_count
            FROM {$categories_table} c
            LEFT JOIN {$keywords_table} k ON c.category_id = k.category_id AND k.is_active = 1
            WHERE c.is_active = 1
            GROUP BY c.category_id, c.name
            ORDER BY c.name"
        );
        
        return $results;
    }

    /**
     * Create Phase 2.2.9 tables: RFQ Routing Rails + Item Delivery Context (Commit 2.2.9)
     * Data-only commit: No UI, routing logic, bids, pricing, prototype, or payment logic
     * 
     * Tables:
     * - n88_rfq_routes: RFQ routing ledger (who received RFQ, when eligible)
     * - n88_item_delivery_context: Item delivery destination and shipping eligibility
     */
    private static function create_phase_2_2_9_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
        $item_delivery_context_table = $wpdb->prefix . 'n88_item_delivery_context';
        $items_table = $wpdb->prefix . 'n88_items';
        $users_table = $wpdb->prefix . 'users';

        // 1. n88_rfq_routes - RFQ Routing Ledger
        $sql_rfq_routes = "CREATE TABLE {$rfq_routes_table} (
            route_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id INT UNSIGNED NOT NULL,
            supplier_id INT UNSIGNED NOT NULL,
            route_type ENUM('designer_invited', 'system_invited') NOT NULL,
            eligible_after DATETIME NULL,
            routed_at DATETIME NULL,
            status ENUM('queued', 'sent', 'viewed', 'bid_submitted', 'expired') NOT NULL DEFAULT 'queued',
            PRIMARY KEY (route_id),
            UNIQUE KEY unique_item_supplier (item_id, supplier_id),
            KEY idx_item_id (item_id),
            KEY idx_supplier_id (supplier_id),
            KEY idx_route_type (route_type),
            KEY idx_status (status),
            KEY idx_eligible_after (eligible_after)
        ) {$charset_collate};";

        // 2. n88_item_delivery_context - Item Delivery + Shipping Eligibility
        $sql_item_delivery_context = "CREATE TABLE {$item_delivery_context_table} (
            item_id INT UNSIGNED NOT NULL,
            delivery_country_code CHAR(2) NOT NULL,
            delivery_postal_code VARCHAR(20) NULL,
            shipping_estimate_mode ENUM('auto', 'manual') NOT NULL DEFAULT 'manual',
            PRIMARY KEY (item_id)
        ) {$charset_collate};";

        // Create tables using dbDelta
        dbDelta( $sql_rfq_routes );
        dbDelta( $sql_item_delivery_context );

        // Add foreign keys separately (dbDelta doesn't handle FKs well)
        $rfq_routes_table_safe = esc_sql( $rfq_routes_table );
        $item_delivery_context_table_safe = esc_sql( $item_delivery_context_table );
        $items_table_safe = esc_sql( $items_table );
        $users_table_safe = esc_sql( $users_table );

        // Check if foreign keys already exist
        $fk_routes_item = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_routes_item'",
            DB_NAME,
            $rfq_routes_table
        ) );

        $fk_routes_supplier = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_routes_supplier'",
            DB_NAME,
            $rfq_routes_table
        ) );

        $fk_delivery_item = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_delivery_item'",
            DB_NAME,
            $item_delivery_context_table
        ) );

        // Add foreign key: item_id -> n88_items.id (routes table)
        if ( ! $fk_routes_item ) {
            self::safe_add_foreign_key( $rfq_routes_table, 'fk_routes_item', 'item_id', $items_table, 'id', 'CASCADE' );
        }

        // Add foreign key: supplier_id -> wp_users.ID (routes table)
        if ( ! $fk_routes_supplier ) {
            self::safe_add_foreign_key( $rfq_routes_table, 'fk_routes_supplier', 'supplier_id', $users_table, 'ID', 'CASCADE' );
        }

        // Add foreign key: item_id -> n88_items.id (delivery context table)
        if ( ! $fk_delivery_item ) {
            self::safe_add_foreign_key( $item_delivery_context_table, 'fk_delivery_item', 'item_id', $items_table, 'id', 'CASCADE' );
        }
    }

    /**
     * Create Phase 2.3.1 tables: Bid Tables (Commit 2.3.1)
     * DB-only commit: No UI, no workflow logic, no routing writes, no prototype payment logic
     * 
     * Tables:
     * - n88_item_bids: Stores one bid per supplier per item
     * - n88_bid_media_links: Embedded video links for bids (YouTube/Vimeo/Loom)
     */
    private static function create_phase_2_3_1_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $bid_media_links_table = $wpdb->prefix . 'n88_bid_media_links';
        $bid_media_files_table = $wpdb->prefix . 'n88_bid_media_files'; // Commit 2.3.5.1: Bid photos table
        $items_table = $wpdb->prefix . 'n88_items';
        $users_table = $wpdb->prefix . 'users';

        // 1. n88_item_bids - Stores one bid per supplier per item
        $sql_item_bids = "CREATE TABLE {$item_bids_table} (
            bid_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id INT UNSIGNED NOT NULL,
            supplier_id INT UNSIGNED NOT NULL,
            is_anonymous TINYINT(1) NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NULL,
            production_lead_time_text VARCHAR(255) NULL,
            prototype_video_yes TINYINT(1) NULL,
            prototype_timeline_option VARCHAR(100) NULL,
            prototype_cost DECIMAL(10,2) NULL,
            cad_yes TINYINT(1) NULL,
            status ENUM('submitted', 'withdrawn', 'awarded', 'declined') NOT NULL DEFAULT 'submitted',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (bid_id),
            UNIQUE KEY unique_item_supplier_bid (item_id, supplier_id),
            KEY idx_item_id (item_id),
            KEY idx_supplier_id (supplier_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        // 2. n88_bid_media_links - Embedded video links for bids
        $sql_bid_media_links = "CREATE TABLE {$bid_media_links_table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            bid_id INT UNSIGNED NOT NULL,
            provider ENUM('youtube', 'vimeo', 'loom') NOT NULL,
            url VARCHAR(500) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_bid_id (bid_id),
            KEY idx_sort_order (sort_order)
        ) {$charset_collate};";

        // 3. n88_bid_media_files - Bid photos (Commit 2.3.5.1)
        $sql_bid_media_files = "CREATE TABLE {$bid_media_files_table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            bid_id INT UNSIGNED NOT NULL,
            file_url VARCHAR(500) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_bid_id (bid_id),
            KEY idx_sort_order (sort_order)
        ) {$charset_collate};";

        // Create tables using dbDelta
        dbDelta( $sql_item_bids );
        dbDelta( $sql_bid_media_links );
        dbDelta( $sql_bid_media_files );

        // Add foreign keys separately (dbDelta doesn't handle FKs well)
        $item_bids_table_safe = esc_sql( $item_bids_table );
        $bid_media_links_table_safe = esc_sql( $bid_media_links_table );
        $items_table_safe = esc_sql( $items_table );
        $users_table_safe = esc_sql( $users_table );

        // Check if foreign keys already exist
        $fk_bids_item = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_bids_item'",
            DB_NAME,
            $item_bids_table
        ) );

        $fk_bids_supplier = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_bids_supplier'",
            DB_NAME,
            $item_bids_table
        ) );

        $fk_media_bid = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_media_bid'",
            DB_NAME,
            $bid_media_links_table
        ) );

        // Add foreign key: item_id -> n88_items.id (bids table)
        if ( ! $fk_bids_item ) {
            self::safe_add_foreign_key( $item_bids_table, 'fk_bids_item', 'item_id', $items_table, 'id', 'CASCADE' );
        }

        // Add foreign key: supplier_id -> wp_users.ID (bids table)
        if ( ! $fk_bids_supplier ) {
            self::safe_add_foreign_key( $item_bids_table, 'fk_bids_supplier', 'supplier_id', $users_table, 'ID', 'CASCADE' );
        }

        // Add foreign key: bid_id -> n88_item_bids.bid_id (media links table)
        if ( ! $fk_media_bid ) {
            self::safe_add_foreign_key( $bid_media_links_table, 'fk_media_bid', 'bid_id', $item_bids_table, 'bid_id', 'CASCADE' );
        }
        
        // Commit 2.3.5.1: Add foreign key for bid_media_files table
        $bid_media_files_table_safe = esc_sql( $bid_media_files_table );
        $fk_media_files_bid = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_media_files_bid'",
            DB_NAME,
            $bid_media_files_table
        ) );
        
        if ( ! $fk_media_files_bid ) {
            self::safe_add_foreign_key( $bid_media_files_table, 'fk_media_files_bid', 'bid_id', $item_bids_table, 'bid_id', 'CASCADE' );
        }
    }

    /**
     * Create Phase 2.3.9.1A tables: Prototype Payments (Commit 2.3.9.1A)
     * 
     * Creates n88_prototype_payments table for CAD + Prototype request tracking
     */
    private static function create_phase_2_3_9_1a_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $prototype_payments_table = $wpdb->prefix . 'n88_prototype_payments';
        $items_table = $wpdb->prefix . 'n88_items';
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $users_table = $wpdb->prefix . 'users';

        // Create n88_prototype_payments table
        $sql_prototype_payments = "CREATE TABLE {$prototype_payments_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            bid_id BIGINT UNSIGNED NOT NULL,
            designer_user_id BIGINT UNSIGNED NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL,
            status ENUM('requested', 'marked_received', 'paid_confirmed', 'cad_in_progress', 'cad_sent', 'cad_approved', 'prototype_released', 'prototype_submitted', 'prototype_approved') NOT NULL DEFAULT 'requested',
            cad_status ENUM('not_started', 'uploaded', 'revision_requested', 'approved') NOT NULL DEFAULT 'not_started',
            video_direction_json TEXT NULL,
            cad_fee_usd DECIMAL(10,2) NOT NULL DEFAULT 60.00,
            cad_revision_rounds_included INT UNSIGNED NOT NULL DEFAULT 3,
            cad_revision_round_fee_usd DECIMAL(10,2) NOT NULL DEFAULT 25.00,
            cad_revision_rounds_used INT UNSIGNED NOT NULL DEFAULT 0,
            cad_approved_at DATETIME NULL,
            cad_approved_version INT UNSIGNED NULL,
            cad_released_to_supplier_at DATETIME NULL,
            prototype_video_cost_estimate_usd DECIMAL(10,2) NULL,
            total_due_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_reference VARCHAR(255) NULL,
            payment_instructions_version VARCHAR(50) NOT NULL DEFAULT 'v1',
            received_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_item_id (item_id),
            KEY idx_bid_id (bid_id),
            KEY idx_designer_user_id (designer_user_id),
            KEY idx_supplier_id (supplier_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at),
            KEY idx_received_at (received_at)
        ) {$charset_collate};";

        dbDelta( $sql_prototype_payments );

        // Add foreign keys separately (dbDelta doesn't handle FKs well)
        $prototype_payments_table_safe = esc_sql( $prototype_payments_table );
        $items_table_safe = esc_sql( $items_table );
        $item_bids_table_safe = esc_sql( $item_bids_table );
        $users_table_safe = esc_sql( $users_table );

        // Check if foreign keys already exist
        $fk_prototype_item = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_prototype_item'",
            DB_NAME,
            $prototype_payments_table
        ) );

        $fk_prototype_bid = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_prototype_bid'",
            DB_NAME,
            $prototype_payments_table
        ) );

        $fk_prototype_designer = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_prototype_designer'",
            DB_NAME,
            $prototype_payments_table
        ) );

        $fk_prototype_supplier = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_prototype_supplier'",
            DB_NAME,
            $prototype_payments_table
        ) );

        // Add foreign keys if they don't exist
        if ( ! $fk_prototype_item ) {
            self::safe_add_foreign_key( $prototype_payments_table, 'fk_prototype_item', 'item_id', $items_table, 'id', 'CASCADE' );
        }

        if ( ! $fk_prototype_bid ) {
            self::safe_add_foreign_key( $prototype_payments_table, 'fk_prototype_bid', 'bid_id', $item_bids_table, 'bid_id', 'CASCADE' );
        }

        if ( ! $fk_prototype_designer ) {
            self::safe_add_foreign_key( $prototype_payments_table, 'fk_prototype_designer', 'designer_user_id', $users_table, 'ID', 'CASCADE' );
        }

        if ( ! $fk_prototype_supplier ) {
            self::safe_add_foreign_key( $prototype_payments_table, 'fk_prototype_supplier', 'supplier_id', $users_table, 'ID', 'CASCADE' );
        }
    }

    /**
     * Create Phase 2.3.9.1C-a tables: Item Messages (Commit 2.3.9.1C-a)
     * Operator-bridged messaging system with two isolated threads per item
     */
    private static function create_phase_2_3_9_1c_a_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $messages_table = $wpdb->prefix . 'n88_item_messages';
        $items_table = $wpdb->prefix . 'n88_items';
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $users_table = $wpdb->prefix . 'users';

        // Create n88_item_messages table
        $sql_messages = "CREATE TABLE {$messages_table} (
            message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_type ENUM('supplier_operator', 'designer_operator') NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            bid_id BIGINT UNSIGNED NULL,
            supplier_id BIGINT UNSIGNED NULL,
            designer_id BIGINT UNSIGNED NULL,
            sender_role ENUM('supplier', 'designer', 'operator') NOT NULL,
            sender_user_id BIGINT UNSIGNED NOT NULL,
            message_text TEXT NOT NULL,
            category VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (message_id),
            KEY idx_item_thread (item_id, thread_type),
            KEY idx_bid_supplier (bid_id, supplier_id),
            KEY idx_designer_item (designer_id, item_id),
            KEY idx_created_at (created_at),
            KEY idx_sender (sender_user_id, sender_role)
        ) {$charset_collate};";

        dbDelta( $sql_messages );

        // Add foreign keys separately
        $messages_table_safe = esc_sql( $messages_table );
        $items_table_safe = esc_sql( $items_table );
        $item_bids_table_safe = esc_sql( $item_bids_table );
        $users_table_safe = esc_sql( $users_table );

        // Check if foreign keys already exist
        $fk_message_item = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_message_item'",
            DB_NAME,
            $messages_table
        ) );

        $fk_message_bid = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_message_bid'",
            DB_NAME,
            $messages_table
        ) );

        $fk_message_supplier = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_message_supplier'",
            DB_NAME,
            $messages_table
        ) );

        $fk_message_designer = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_message_designer'",
            DB_NAME,
            $messages_table
        ) );

        $fk_message_sender = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_message_sender'",
            DB_NAME,
            $messages_table
        ) );

        // Add foreign keys if they don't exist
        if ( ! $fk_message_item ) {
            self::safe_add_foreign_key( $messages_table, 'fk_message_item', 'item_id', $items_table, 'id', 'CASCADE' );
        }

        if ( ! $fk_message_bid ) {
            self::safe_add_foreign_key( $messages_table, 'fk_message_bid', 'bid_id', $item_bids_table, 'bid_id', 'CASCADE' );
        }

        if ( ! $fk_message_supplier ) {
            self::safe_add_foreign_key( $messages_table, 'fk_message_supplier', 'supplier_id', $users_table, 'ID', 'CASCADE' );
        }

        if ( ! $fk_message_designer ) {
            self::safe_add_foreign_key( $messages_table, 'fk_message_designer', 'designer_id', $users_table, 'ID', 'CASCADE' );
        }

        if ( ! $fk_message_sender ) {
            self::safe_add_foreign_key( $messages_table, 'fk_message_sender', 'sender_user_id', $users_table, 'ID', 'CASCADE' );
        }
    }

    /**
     * Migrate prototype_payments table to add marked_received status and received_at column
     */
    private static function migrate_prototype_payments_table() {
        global $wpdb;
        $prototype_payments_table = $wpdb->prefix . 'n88_prototype_payments';
        
        // Check if table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$prototype_payments_table}'" ) !== $prototype_payments_table ) {
            return; // Table doesn't exist yet, will be created by create_phase_2_3_9_1a_tables
        }
        
        // Check if received_at column exists
        $columns = $wpdb->get_col( "DESCRIBE {$prototype_payments_table}" );
        if ( ! in_array( 'received_at', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} ADD COLUMN received_at DATETIME NULL AFTER payment_instructions_version" );
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} ADD KEY idx_received_at (received_at)" );
        }
        
        // Check if marked_received is in the enum
        $column_info = $wpdb->get_row( "SHOW COLUMNS FROM {$prototype_payments_table} WHERE Field = 'status'" );
        if ( $column_info && strpos( $column_info->Type, 'marked_received' ) === false ) {
            // Modify enum to include marked_received
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} MODIFY COLUMN status ENUM('requested', 'marked_received', 'paid_confirmed', 'cad_in_progress', 'cad_sent', 'cad_approved', 'prototype_released', 'prototype_submitted', 'prototype_approved') NOT NULL DEFAULT 'requested'" );
        }
        
        // Commit 2.3.9.1E: Add notifications_sent_at column if not exists
        $columns = $wpdb->get_col( "DESCRIBE {$prototype_payments_table}" );
        if ( ! in_array( 'notifications_sent_at', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} ADD COLUMN notifications_sent_at DATETIME NULL AFTER received_at" );
        }
        
        // Commit 2.3.9.1F: Add payment_evidence_logged_at column if not exists
        if ( ! in_array( 'payment_evidence_logged_at', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} ADD COLUMN payment_evidence_logged_at DATETIME NULL AFTER notifications_sent_at" );
        }

        // Commit 2.3.9.2A: Add CAD workflow fields if not exists
        $columns = $wpdb->get_col( "DESCRIBE {$prototype_payments_table}" );
        if ( ! in_array( 'cad_status', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} ADD COLUMN cad_status ENUM('not_started', 'uploaded', 'revision_requested', 'approved') NOT NULL DEFAULT 'not_started' AFTER status" );
        }
        if ( ! in_array( 'cad_approved_at', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} ADD COLUMN cad_approved_at DATETIME NULL AFTER cad_revision_rounds_used" );
        }
        if ( ! in_array( 'cad_approved_version', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} ADD COLUMN cad_approved_version INT UNSIGNED NULL AFTER cad_approved_at" );
        }
        if ( ! in_array( 'cad_released_to_supplier_at', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} ADD COLUMN cad_released_to_supplier_at DATETIME NULL AFTER cad_approved_version" );
        }
        if ( ! in_array( 'updated_at', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} ADD COLUMN updated_at DATETIME NULL AFTER created_at" );
        }
    }

    /**
     * Commit 2.3.9.2A: Migrate n88_item_files table to support CAD artifact metadata.
     */
    private static function migrate_item_files_table_for_cad() {
        global $wpdb;
        $item_files_table = $wpdb->prefix . 'n88_item_files';
        
        // Check if table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$item_files_table}'" ) !== $item_files_table ) {
            return;
        }
        
        $columns = $wpdb->get_col( "DESCRIBE {$item_files_table}" );
        
        if ( ! in_array( 'payment_id', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$item_files_table} ADD COLUMN payment_id BIGINT UNSIGNED NULL AFTER attachment_type" );
        }
        if ( ! in_array( 'bid_id', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$item_files_table} ADD COLUMN bid_id BIGINT UNSIGNED NULL AFTER payment_id" );
        }
        if ( ! in_array( 'cad_version', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$item_files_table} ADD COLUMN cad_version INT UNSIGNED NULL AFTER bid_id" );
        }
        if ( ! in_array( 'file_name', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$item_files_table} ADD COLUMN file_name VARCHAR(255) NULL AFTER cad_version" );
        }
        if ( ! in_array( 'mime_type', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$item_files_table} ADD COLUMN mime_type VARCHAR(100) NULL AFTER file_name" );
        }
    }
    
    /**
     * Create Phase 2.3.9.1C-B tables: RFQ Clarifications (Commit 2.3.9.1C-B)
     * Operator-mediated clarification system
     */
    private static function create_phase_2_3_9_1c_b_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $clarifications_table = $wpdb->prefix . 'n88_rfq_clarifications';
        $items_table = $wpdb->prefix . 'n88_items';
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $users_table = $wpdb->prefix . 'users';

        // Create n88_rfq_clarifications table
        $sql_clarifications = "CREATE TABLE {$clarifications_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            bid_id BIGINT UNSIGNED NULL,
            supplier_id BIGINT UNSIGNED NOT NULL,
            operator_user_id BIGINT UNSIGNED NULL,
            question_text TEXT NOT NULL,
            answer_text TEXT NULL,
            status ENUM('open', 'needs_designer', 'answered', 'closed') NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            answered_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_item_id (item_id),
            KEY idx_bid_id (bid_id),
            KEY idx_supplier_id (supplier_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql_clarifications );

        // Add foreign keys separately
        $clarifications_table_safe = esc_sql( $clarifications_table );
        $items_table_safe = esc_sql( $items_table );
        $item_bids_table_safe = esc_sql( $item_bids_table );
        $users_table_safe = esc_sql( $users_table );

        // Check if foreign keys already exist
        $fk_clarification_item = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_clarification_item'",
            DB_NAME,
            $clarifications_table_safe
        ) );

        $fk_clarification_bid = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_clarification_bid'",
            DB_NAME,
            $clarifications_table_safe
        ) );

        $fk_clarification_supplier = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_clarification_supplier'",
            DB_NAME,
            $clarifications_table_safe
        ) );

        // Add foreign keys if they don't exist
        if ( ! $fk_clarification_item ) {
            self::safe_add_foreign_key( $clarifications_table, 'fk_clarification_item', 'item_id', $items_table, 'id', 'CASCADE' );
        }

        if ( ! $fk_clarification_bid ) {
            self::safe_add_foreign_key( $clarifications_table, 'fk_clarification_bid', 'bid_id', $item_bids_table, 'bid_id', 'CASCADE' );
        }

        if ( ! $fk_clarification_supplier ) {
            self::safe_add_foreign_key( $clarifications_table, 'fk_clarification_supplier', 'supplier_id', $users_table, 'ID', 'CASCADE' );
        }
    }

    /**
     * Create n88_rfq_case_resolutions table (Fix #13 / #26: Mark Resolved)
     * Tracks when an operator marks clarification/case as resolved to clear Action Required.
     */
    private static function create_case_resolutions_table( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $resolutions_table = $wpdb->prefix . 'n88_rfq_case_resolutions';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$resolutions_table}'" ) === $resolutions_table ) {
            return;
        }

        $sql = "CREATE TABLE {$resolutions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            bid_id BIGINT UNSIGNED NULL,
            actor_user_id BIGINT UNSIGNED NOT NULL,
            resolved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_item_id (item_id),
            KEY idx_item_bid (item_id, bid_id),
            KEY idx_resolved_at (resolved_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create n88_prototype_payment_receipts table
     * Designer uploads payment receipt (JPG/PDF); operator views before marking received.
     */
    private static function create_payment_receipts_table( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $receipts_table = $wpdb->prefix . 'n88_prototype_payment_receipts';
        $payments_table = $wpdb->prefix . 'n88_prototype_payments';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$receipts_table}'" ) === $receipts_table ) {
            return;
        }

        $sql = "CREATE TABLE {$receipts_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id BIGINT UNSIGNED NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_payment_id (payment_id),
            KEY idx_uploaded_at (uploaded_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Add message column to n88_prototype_payment_receipts (designer note when uploading receipt).
     */
    private static function add_payment_receipt_message_column() {
        global $wpdb;
        $receipts_table = $wpdb->prefix . 'n88_prototype_payment_receipts';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$receipts_table}'" ) !== $receipts_table ) {
            return;
        }
        $columns = $wpdb->get_col( "DESCRIBE {$receipts_table}" );
        if ( ! is_array( $columns ) || ! in_array( 'message', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$receipts_table} ADD COLUMN message TEXT NULL AFTER file_name" );
        }
    }

    /**
     * Commit 2.3.9.2B-S: Create prototype video submission tables
     */
    private static function create_phase_2_3_9_2b_tables( $charset_collate ) {
        global $wpdb;
        
        $submissions_table = $wpdb->prefix . 'n88_prototype_video_submissions';
        $links_table = $wpdb->prefix . 'n88_prototype_video_links';
        $prototype_payments_table = $wpdb->prefix . 'n88_prototype_payments';
        $items_table = $wpdb->prefix . 'n88_items';
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $users_table = $wpdb->prefix . 'users';
        
        // Create n88_prototype_video_submissions table
        $sql_submissions = "CREATE TABLE {$submissions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            bid_id BIGINT UNSIGNED NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL,
            link_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_payment_version (payment_id, version),
            KEY idx_payment_id (payment_id),
            KEY idx_supplier_id (supplier_id),
            KEY idx_item_id (item_id),
            KEY idx_bid_id (bid_id),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";
        
        dbDelta( $sql_submissions );
        
        // Create n88_prototype_video_links table
        $sql_links = "CREATE TABLE {$links_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,
            provider ENUM('youtube', 'vimeo', 'loom') NOT NULL,
            url VARCHAR(500) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_submission_id (submission_id),
            KEY idx_provider (provider),
            KEY idx_sort_order (sort_order)
        ) {$charset_collate};";
        
        dbDelta( $sql_links );
        
        // Add foreign keys
        $submissions_table_safe = esc_sql( $submissions_table );
        $links_table_safe = esc_sql( $links_table );
        
        // Check if foreign keys already exist
        $fk_submission_payment = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_submission_payment'",
            DB_NAME,
            $submissions_table_safe
        ) );
        
        $fk_link_submission = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = 'fk_link_submission'",
            DB_NAME,
            $links_table_safe
        ) );
        
        // Add foreign keys if they don't exist
        if ( ! $fk_submission_payment ) {
            self::safe_add_foreign_key( $submissions_table, 'fk_submission_payment', 'payment_id', $prototype_payments_table, 'id', 'CASCADE' );
        }
        
        if ( ! $fk_link_submission ) {
            self::safe_add_foreign_key( $links_table, 'fk_link_submission', 'submission_id', $submissions_table, 'id', 'CASCADE' );
        }
    }

    /**
     * Commit 2.3.9.2B-D: Create designer prototype review tables
     */
    private static function create_phase_2_3_9_2b_d_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $feedback_packets_table = $wpdb->prefix . 'n88_prototype_feedback_packets';
        $feedback_packet_lines_table = $wpdb->prefix . 'n88_prototype_feedback_packet_lines';
        $keyword_phrases_table = $wpdb->prefix . 'n88_keyword_phrases';
        $prototype_payments_table = $wpdb->prefix . 'n88_prototype_payments';
        $items_table = $wpdb->prefix . 'n88_items';
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $users_table = $wpdb->prefix . 'users';
        $keywords_table = $wpdb->prefix . 'n88_keywords';
        $categories_table = $wpdb->prefix . 'n88_categories';
        
        // Create n88_prototype_feedback_packets table
        $sql_feedback_packets = "CREATE TABLE {$feedback_packets_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            bid_id BIGINT UNSIGNED NOT NULL,
            designer_id BIGINT UNSIGNED NOT NULL,
            submission_version INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_payment_id (payment_id),
            KEY idx_item_id (item_id),
            KEY idx_bid_id (bid_id),
            KEY idx_designer_id (designer_id),
            KEY idx_submission_version (submission_version),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";
        
        dbDelta( $sql_feedback_packets );
        
        // Create n88_prototype_feedback_packet_lines table
        $sql_feedback_packet_lines = "CREATE TABLE {$feedback_packet_lines_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            feedback_packet_id BIGINT UNSIGNED NOT NULL,
            keyword_id INT UNSIGNED NOT NULL,
            keyword_status ENUM('satisfied', 'needs_adjustment', 'not_addressed') NOT NULL,
            severity ENUM('must_fix', 'should_fix', 'optional') NULL,
            selected_phrase_ids JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_feedback_packet_id (feedback_packet_id),
            KEY idx_keyword_id (keyword_id),
            KEY idx_keyword_status (keyword_status)
        ) {$charset_collate};";
        
        dbDelta( $sql_feedback_packet_lines );
        
        // Create n88_keyword_phrases table
        $sql_keyword_phrases = "CREATE TABLE {$keyword_phrases_table} (
            phrase_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id INT UNSIGNED NULL,
            keyword_id INT UNSIGNED NULL,
            phrase_text TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (phrase_id),
            KEY idx_category_id (category_id),
            KEY idx_keyword_id (keyword_id),
            KEY idx_is_active (is_active),
            KEY idx_sort_order (sort_order)
        ) {$charset_collate};";
        
        dbDelta( $sql_keyword_phrases );
        
        // Add foreign keys
        $feedback_packets_table_safe = esc_sql( $feedback_packets_table );
        $feedback_packet_lines_table_safe = esc_sql( $feedback_packet_lines_table );
        $keyword_phrases_table_safe = esc_sql( $keyword_phrases_table );
        
        // FK for feedback_packets.payment_id
        if ( ! self::foreign_key_exists( $feedback_packets_table, 'fk_feedback_packet_payment' ) ) {
            self::safe_add_foreign_key( $feedback_packets_table, 'fk_feedback_packet_payment', 'payment_id', $prototype_payments_table, 'id', 'CASCADE' );
        }
        
        // FK for feedback_packets.item_id
        if ( ! self::foreign_key_exists( $feedback_packets_table, 'fk_feedback_packet_item' ) ) {
            self::safe_add_foreign_key( $feedback_packets_table, 'fk_feedback_packet_item', 'item_id', $items_table, 'id', 'CASCADE' );
        }
        
        // FK for feedback_packets.bid_id
        if ( ! self::foreign_key_exists( $feedback_packets_table, 'fk_feedback_packet_bid' ) ) {
            self::safe_add_foreign_key( $feedback_packets_table, 'fk_feedback_packet_bid', 'bid_id', $item_bids_table, 'bid_id', 'CASCADE' );
        }
        
        // FK for feedback_packets.designer_id
        if ( ! self::foreign_key_exists( $feedback_packets_table, 'fk_feedback_packet_designer' ) ) {
            self::safe_add_foreign_key( $feedback_packets_table, 'fk_feedback_packet_designer', 'designer_id', $users_table, 'ID', 'CASCADE' );
        }
        
        // FK for feedback_packet_lines.feedback_packet_id
        if ( ! self::foreign_key_exists( $feedback_packet_lines_table, 'fk_feedback_packet_line_packet' ) ) {
            self::safe_add_foreign_key( $feedback_packet_lines_table, 'fk_feedback_packet_line_packet', 'feedback_packet_id', $feedback_packets_table, 'id', 'CASCADE' );
        }
        
        // FK for feedback_packet_lines.keyword_id
        if ( ! self::foreign_key_exists( $feedback_packet_lines_table, 'fk_feedback_packet_line_keyword' ) ) {
            self::safe_add_foreign_key( $feedback_packet_lines_table, 'fk_feedback_packet_line_keyword', 'keyword_id', $keywords_table, 'keyword_id', 'CASCADE' );
        }
        
        // FK for keyword_phrases.category_id
        if ( ! self::foreign_key_exists( $keyword_phrases_table, 'fk_keyword_phrase_category' ) ) {
            self::safe_add_foreign_key( $keyword_phrases_table, 'fk_keyword_phrase_category', 'category_id', $categories_table, 'category_id', 'SET NULL' );
        }
        
        // FK for keyword_phrases.keyword_id
        if ( ! self::foreign_key_exists( $keyword_phrases_table, 'fk_keyword_phrase_keyword' ) ) {
            self::safe_add_foreign_key( $keyword_phrases_table, 'fk_keyword_phrase_keyword', 'keyword_id', $keywords_table, 'keyword_id', 'CASCADE' );
        }
    }
    
    /**
     * Commit 2.3.9.2B-D: Add prototype_status fields to n88_prototype_payments
     */
    private static function add_prototype_status_fields() {
        global $wpdb;
        
        $prototype_payments_table = $wpdb->prefix . 'n88_prototype_payments';
        
        // Check if prototype_status column exists
        $column_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = 'prototype_status'",
            DB_NAME,
            $prototype_payments_table
        ) );
        
        if ( ! $column_exists ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} 
                ADD COLUMN prototype_status ENUM('not_submitted', 'submitted', 'changes_requested', 'approved') NOT NULL DEFAULT 'not_submitted' AFTER cad_released_to_supplier_at" );
        }
        
        // Check if prototype_approved_at column exists
        $column_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = 'prototype_approved_at'",
            DB_NAME,
            $prototype_payments_table
        ) );
        
        if ( ! $column_exists ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} 
                ADD COLUMN prototype_approved_at DATETIME NULL AFTER prototype_status" );
        }
        
        // Check if prototype_current_version column exists
        $column_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = 'prototype_current_version'",
            DB_NAME,
            $prototype_payments_table
        ) );
        
        if ( ! $column_exists ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} 
                ADD COLUMN prototype_current_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER prototype_approved_at" );
        }
        
        // Check if prototype_approved_version column exists
        $column_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = 'prototype_approved_version'",
            DB_NAME,
            $prototype_payments_table
        ) );
        
        if ( ! $column_exists ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} 
                ADD COLUMN prototype_approved_version INT UNSIGNED NULL AFTER prototype_current_version" );
        }
        
        // Check if direction_keyword_ids column exists
        $column_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = 'direction_keyword_ids'",
            DB_NAME,
            $prototype_payments_table
        ) );
        
        if ( ! $column_exists ) {
            $wpdb->query( "ALTER TABLE {$prototype_payments_table} 
                ADD COLUMN direction_keyword_ids JSON NULL AFTER video_direction_json" );
        }
    }

    /**
     * Commit 3.A.1: Item Timeline Spine — n88_item_timelines + n88_item_timeline_steps
     */
    private static function create_phase_3_a_1_item_timeline_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $timelines_table = $wpdb->prefix . 'n88_item_timelines';
        $steps_table     = $wpdb->prefix . 'n88_item_timeline_steps';
        $items_table     = $wpdb->prefix . 'n88_items';

        $sql_timelines = "CREATE TABLE {$timelines_table} (
            timeline_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (timeline_id),
            UNIQUE KEY item_id (item_id)
        ) {$charset_collate};";

        $sql_steps = "CREATE TABLE {$steps_table} (
            step_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            timeline_id BIGINT UNSIGNED NOT NULL,
            step_number TINYINT UNSIGNED NOT NULL,
            label VARCHAR(255) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            expected_by DATE NULL,
            evidence_required TINYINT(1) NOT NULL DEFAULT 1,
            evidence_verified_at DATETIME NULL,
            evidence_verified_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (step_id),
            UNIQUE KEY timeline_step (timeline_id, step_number),
            KEY timeline_id (timeline_id),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta( $sql_timelines );
        dbDelta( $sql_steps );

        if ( ! self::foreign_key_exists( $timelines_table, 'fk_item_timeline_item' ) ) {
            self::safe_add_foreign_key( $timelines_table, 'fk_item_timeline_item', 'item_id', $items_table, 'id', 'CASCADE' );
        }
        if ( ! self::foreign_key_exists( $steps_table, 'fk_timeline_step_timeline' ) ) {
            self::safe_add_foreign_key( $steps_table, 'fk_timeline_step_timeline', 'timeline_id', $timelines_table, 'timeline_id', 'CASCADE' );
        }
    }

    /**
     * Commit 3.A.2: Timeline Step Evidence — media bound to item_id + timeline_step_id (append-only).
     */
    private static function create_phase_3_a_2_timeline_step_evidence_table( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $evidence_table  = $wpdb->prefix . 'n88_timeline_step_evidence';
        $steps_table     = $wpdb->prefix . 'n88_item_timeline_steps';
        $items_table     = $wpdb->prefix . 'n88_items';

        $sql = "CREATE TABLE {$evidence_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            step_id BIGINT UNSIGNED NOT NULL,
            media_type VARCHAR(20) NOT NULL DEFAULT 'image',
            attachment_id BIGINT UNSIGNED NULL,
            file_path VARCHAR(500) NULL,
            youtube_url VARCHAR(500) NULL,
            add_to_media_library TINYINT(1) NOT NULL DEFAULT 0,
            add_to_material_bank TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED NULL,
            hidden TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY item_id (item_id),
            KEY step_id (step_id),
            KEY item_step (item_id, step_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql );

        if ( ! self::foreign_key_exists( $evidence_table, 'fk_evidence_item' ) ) {
            self::safe_add_foreign_key( $evidence_table, 'fk_evidence_item', 'item_id', $items_table, 'id', 'CASCADE' );
        }
        if ( ! self::foreign_key_exists( $evidence_table, 'fk_evidence_step' ) ) {
            self::safe_add_foreign_key( $evidence_table, 'fk_evidence_step', 'step_id', $steps_table, 'step_id', 'CASCADE' );
        }
    }
    
    /**
     * Commit 2.3.10: Migrate item_delivery_context table for delivery quoting
     * Adds fields for storing computed CBM, shipping mode, and delivery cost
     */
    private static function migrate_item_delivery_context_for_delivery_quoting() {
        global $wpdb;
        
        $item_delivery_context_table = $wpdb->prefix . 'n88_item_delivery_context';
        
        // Check if table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$item_delivery_context_table}'" ) !== $item_delivery_context_table ) {
            return; // Table doesn't exist yet, will be created by create_phase_2_2_9_tables
        }
        
        $columns = $wpdb->get_col( "DESCRIBE {$item_delivery_context_table}" );
        
        // Add cbm column if not exists
        if ( ! in_array( 'cbm', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$item_delivery_context_table} ADD COLUMN cbm DECIMAL(10,6) NULL AFTER shipping_estimate_mode" );
        }
        
        // Add shipping_mode column if not exists
        if ( ! in_array( 'shipping_mode', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$item_delivery_context_table} ADD COLUMN shipping_mode ENUM('LCL', 'FCL_20', 'FCL_40HQ') NULL AFTER cbm" );
        }
        
        // Add delivery_cost_usd column if not exists
        if ( ! in_array( 'delivery_cost_usd', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$item_delivery_context_table} ADD COLUMN delivery_cost_usd DECIMAL(10,2) NULL AFTER shipping_mode" );
        }
        
        // Add updated_at column if not exists
        if ( ! in_array( 'updated_at', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$item_delivery_context_table} ADD COLUMN updated_at DATETIME NULL AFTER delivery_cost_usd" );
        }
        
        // Update delivery_country_code to delivery_country for consistency (if needed)
        // Note: Keeping delivery_country_code as is for backward compatibility
    }
    
    /**
     * Commit 2.3.9.2B-D: Seed keyword phrases library
     * 
     * Populates n88_keyword_phrases with phrases linked to keywords by category.
     * Keywords are matched by exact name (case-insensitive) within their category.
     */
    private static function seed_keyword_phrases() {
        global $wpdb;
        
        $keyword_phrases_table = $wpdb->prefix . 'n88_keyword_phrases';
        $keywords_table = $wpdb->prefix . 'n88_keywords';
        $categories_table = $wpdb->prefix . 'n88_categories';
        
        // Define keyword-phrase mappings by category
        $category_keyword_phrases = array(
            'UPHOLSTERY' => array(
                'HEIGHT-MEASUREMENT' => 'Please reshoot clearly showing the seat height measurement.',
                'STITCH-DETAIL' => 'Please reshoot with tighter close-ups of stitching detail.',
                'FIRMNESS-TEST' => 'Please demonstrate cushion firmness using hand pressure.',
                'DEPTH-VERIFICATION' => 'Please reshoot clearly showing the seat depth measurement.',
                'ARM-MEASUREMENT' => 'Please reshoot clearly showing the arm height measurement.',
                'BACK-HEIGHT' => 'Please reshoot clearly showing the back height measurement.',
                'DIMENSION-OVERLAY' => 'Provide an overall width / depth / height overlay for scale.',
                'FOAM-LAYERS' => 'Show cushion construction layers (foam / fill) in detail.',
                'SUSPENSION-STRUCTURE' => 'Provide a frame structure close-up showing the suspension system.',
                'TENSION-CHECK' => 'Demonstrate fabric tension to ensure no sagging or wrinkling.',
            ),
            'INDOOR FURNITURE (CASEGOODS)' => array(
                'JOINERY-DETAIL' => 'Please reshoot showing joinery in close-up.',
                'FINISH-TYPE' => 'Please reshoot to better show finish type (Matte / Gloss / Lacquer).',
                'GLIDE-OPERATION' => 'Please demonstrate drawer glide operation.',
                'INTERIOR-DIMENSIONS' => 'Please reshoot showing drawer interior dimensions.',
                'REVEAL-SPACING' => 'Please reshoot showing clearance and reveal spacing.',
                'STABILITY-TEST' => 'Please demonstrate a structural stability test.',
                'VENEER-CONSISTENCY' => 'Show solid wood vs veneer close-ups to confirm matching consistency.',
                'SOFT-CLOSE-DEMO' => 'Please demonstrate the door swing and soft-close functionality.',
                'LEVELING-FEET' => 'Please provide a demonstration of the leveling feet.',
                'EDGE-QUALITY' => 'Show a close-up of edge banding quality and junctions.',
            ),
            'OUTDOOR FURNITURE' => array(
                'FRAME-CLOSEUP' => 'Please reshoot showing frame material in close-up.',
                'POWDER-COATING' => 'Please reshoot to show powder coat consistency.',
                'DRAINAGE-TEST' => 'Please demonstrate cushion water drainage.',
                'WELD-QUALITY' => 'Show weld quality and joint detail in a close-up.',
                'FRAME-THICKNESS' => 'Show a measurement of the frame material thickness.',
                'WEAVE-DETAIL' => 'Show rope / weave detail and tension in a close-up.',
                'QUICK-DRY-DEMO' => 'Provide a quick-dry foam demonstration.',
                'STACKABILITY-CHECK' => 'Demonstrate unit stackability to confirm fit.',
                'DAYLIGHT-VIEW' => 'Provide a view of the piece in outdoor natural daylight.',
                'CUSHION-THICKNESS' => 'Show a measurement of the cushion thickness.',
            ),
            'LIGHTING' => array(
                'OUTPUT-POWER' => 'Please reshoot with fixture fully powered on.',
                'DIMMING-DEMO' => 'Please demonstrate dimming functionality.',
                'CANOPY-DETAIL' => 'Show drop length and canopy mounting detail.',
                'SCALE-REFERENCE' => 'Show fixture scale relative to a person or the space.',
                'WIRING-CLOSEUP' => 'Show socket and wiring construction details.',
                'MOUNTING-METHOD' => 'Demonstrate the fixture mounting method clearly.',
                'GLASS-QUALITY' => 'Show shade thickness and glass quality in detail.',
                'COLOR-TEMP' => 'Demonstrate the light color temperature against a neutral surface.',
                'GLARE-CONTROL' => 'Provide a view of the fixture to demonstrate glare control.',
                'CERTIFICATION-LABEL' => 'Show the UL / ETL label clearly on the fixture.',
            ),
            'STONE (MARBLE / GRANITE / QUARTZ)' => array(
                'VEIN-DETAIL' => 'Please reshoot showing vein detail in close-up.',
                'EDGE-PROFILE' => 'Please reshoot showing edge profile in close-up.',
                'SLAB-OVERVIEW' => 'Provide an overall slab view to show vein movement.',
                'COLOR-CONSISTENCY' => 'Show color consistency across the entire slab.',
                'REFLECTION-TEST' => 'Perform a light reflection test to show surface finish quality.',
                'THICKNESS-MEASURE' => 'Show a measurement of the slab and edge thickness.',
                'BOOKMATCH-ALIGN' => 'Show bookmatch alignment at the seam for pattern matching.',
                'SHADE-VARIATION' => 'Provide a shade variation comparison against the approved sample.',
                'TEXTURE-CLOSEUP' => 'Provide a surface texture close-up for honed or leathered finishes.',
                'SEAM-ALIGNMENT' => 'Show the alignment of the stone against the drawings.',
            ),
            'METALWORK' => array(
                'WELD-QUALITY' => 'Please reshoot showing weld quality in close-up.',
                'CNC-DETAIL' => 'Show laser cut or CNC detail and edge smoothness.',
                'BEND-CONSISTENCY' => 'Provide a view of bend consistency across the piece.',
                'FINISH-UNIFORMITY' => 'Show finish uniformity across all metal surfaces.',
                'PATINA-VARIATION' => 'Show intentional patina variation to confirm it meets the spec.',
                'RIGIDITY-DEMO' => 'Provide a load or rigidity demonstration of the frame.',
                'GAUGE-MEASURE' => 'Show a thickness measurement of the metal material.',
                'HARDWARE-INTEGRATION' => 'Show how hardware is integrated and fastened to the metal.',
                'DIMENSION-OVERLAY' => 'Provide an overall dimensions overlay for verification.',
                'COATING-THICKNESS' => 'Show a side profile view of the coating thickness.',
            ),
            'MILLWORK / CABINETRY' => array(
                'VENEER-MATCHING' => 'Please reshoot showing veneer matching across panels.',
                'REVEAL-ALIGNMENT' => 'Please reshoot showing reveal spacing and alignment across units.',
                'DRAWER-BOX' => 'Show the drawer box construction and joinery detail.',
                'HINGE-ALIGNMENT' => 'Show hinge alignment and soft-close operation.',
                'CARCASS-CONSTRUCTION' => 'Show the internal carcass construction and shelving supports.',
                'PAINT-CONSISTENCY' => 'Show paint or stain consistency across all surfaces.',
                'LAMINATE-EDGE' => 'Show laminate edge detail to check for seams.',
                'GLIDE-TEST' => 'Demonstrate drawer glide operation and stability.',
                'DIMENSION-VERIFY' => 'Provide an overall dimensions overlay against shop drawings.',
                'FIT-INTEGRATION' => 'Show how the unit aligns and fits within the designated space.',
            ),
            'FLOORING' => array(
                'PATTERN-VIEW' => 'Please reshoot showing the overall flooring pattern view.',
                'GRAIN-TEXTURE' => 'Provide a grain and texture close-up.',
                'LOCKING-SYSTEM' => 'Demonstrate the locking system or tongue-and-groove detail.',
                'SLIP-RESISTANCE' => 'Please provide a slip resistance demonstration.',
                'EDGE-DETAIL' => 'Show the edge detail and bevel of the planks.',
                'COLOR-RANGE' => 'Show color variation across multiple pieces.',
                'UNDERLAYMENT-PROOF' => 'Show the underlayment material used.',
                'MOISTURE-BARRIER' => 'Provide confirmation of the moisture barrier installation.',
                'THICKNESS-CHECK' => 'Show a measurement of the top wear layer.',
                'SURFACE-REFLECT' => 'Show light reflection to confirm the finish gloss level.',
            ),
            'DRAPERY / WINDOW TREATMENTS' => array(
                'MOTION-OPERATION' => 'Please demonstrate open and close operation.',
                'DROP-LENGTH' => 'Please reshoot showing the full drop length.',
                'STACK-BACK' => 'Provide a stack-back measurement when fully open.',
                'FABRIC-TEXTURE' => 'Show a close-up of the fabric texture and lining detail.',
                'COM-APPLICATION' => 'Confirm COM application quality and seam placement.',
                'MOTORIZED-DEMO' => 'Provide a demonstration of the motorized function.',
                'TRACK-DETAIL' => 'Show the track system and carrier details.',
                'HEM-FINISH' => 'Show the bottom hem finish and weighting.',
                'LIGHT-LEAK' => 'Demonstrate the light-blocking capability at the edges.',
                'PLEAT-ALIGNMENT' => 'Show pleat spacing and alignment across the header.',
            ),
            'GLASS / MIRRORS' => array(
                'THICKNESS-MEASUREMENT' => 'Please reshoot showing glass thickness measurement.',
                'EDGE-FINISH' => 'Show the edge finish detail and polish quality.',
                'SAFETY-MARKING' => 'Show the safety marking or tempering logo.',
                'TINT-CONSISTENCY' => 'Show mirror tint consistency across the surface.',
                'BEVEL-DETAIL' => 'Show a close-up of the bevel detail and width.',
                'BACK-PAINT' => 'Show the quality and coverage of the back-paint.',
                'DISTORTION-CHECK' => 'Show a reflection at an angle to check for glass distortion.',
                'HARDWARE-SEATING' => 'Show how glass is seated within the frame or hardware.',
                'CLARITY-TEST' => 'Provide a shot through the glass to confirm clarity.',
                'MOUNTING-STABILITY' => 'Show the mounting points to confirm structural stability.',
            ),
            'HARDWARE / ACCESSORIES' => array(
                'GAUGE-VERIFICATION' => 'Please reshoot clearly showing material thickness measurement.',
                'MACHINING-DETAIL' => 'Please reshoot showing casting/machining detail in close-up.',
                'FINISH-UNIFORMITY' => 'Please reshoot to show finish consistency across surfaces.',
                'WEAR-SURFACE' => 'Please reshoot with close-up of wear surface areas.',
                'MOUNTING-DEMO' => 'Please demonstrate installation steps clearly.',
                'FUNCTION-TEST' => 'Please demonstrate operation test clearly.',
                'SPRING-ACTION' => 'Demonstrate the spring return or mechanical tension.',
                'THREADING-CHECK' => 'Show internal threading and fastener quality.',
                'SCALE-CHECK' => 'Show the hardware relative to a standard measurement tool.',
                'COMPONENT-FIT' => 'Show how multiple parts of the hardware assemble together.',
            ),
            'RUGS / CARPETS' => array(
                'PATTERN-CONTINUITY' => 'Please reshoot showing pattern consistency across the full rug.',
                'TONE-VARIATION' => 'Please reshoot to show accurate color variation.',
                'BACKING-DETAIL' => 'Please reshoot showing backing detail in close-up.',
                'BINDING-QUALITY' => 'Please reshoot showing edge binding in close-up.',
                'PILE-HEIGHT' => 'Please reshoot showing pile height measurement.',
                'RESISTANCE-DEMO' => 'Please reshoot demonstrating stain resistance performance.',
                'KNOT-DENSITY' => 'Show the back of the rug to confirm knot density/tufting.',
                'SHEDDING-TEST' => 'Demonstrate a shedding test with a brush or hand.',
                'FRINGE-DETAIL' => 'Show the fringe attachment or edge termination.',
                'LUSTR-CHECK' => 'Show the rug under directional light to check fiber luster.',
            ),
            'WALLCOVERINGS / FINISHES' => array(
                'ALIGNMENT-CHECK' => 'Please reshoot showing pattern alignment across seams.',
                'TEXTURE-DEPTH' => 'Please reshoot with close-up of surface texture.',
                'SEAM-DETAIL' => 'Please reshoot showing seam detail in close-up.',
                'CORNER-TRANSITION' => 'Please reshoot showing corner transitions clearly.',
                'CLEANABILITY-PROOF' => 'Please reshoot demonstrating cleanability.',
                'PANEL-THICKNESS' => 'Please reshoot showing acoustic panel thickness measurement.',
                'ADHESIVE-CHECK' => 'Show seams to ensure no adhesive bleed-through.',
                'PEEL-STRENGTH' => 'Demonstrate a peel-strength test on the substrate.',
                'GRAIN-DIRECTION' => 'Confirm the grain or pattern direction is consistent.',
                'SUBSTRATE-TEXTURE' => 'Show the substrate texture through the finish.',
            ),
            'APPLIANCES' => array(
                'CAVITY-FIT' => 'Please reshoot showing built-in fit clearly.',
                'REVEAL-ALIGNMENT' => 'Please reshoot showing panel alignment clearly.',
                'STARTUP-SEQUENCE' => 'Please reshoot showing power-on sequence clearly.',
                'INTERFACE-LEGIBILITY' => 'Please reshoot showing control interface clearly.',
                'SPEC-LABEL' => 'Please reshoot showing voltage label clearly.',
                'DOOR-CLEARANCE' => 'Demonstrate the door swing and handle clearance.',
                'INTERIOR-LAYOUT' => 'Show the internal rack and shelving configuration.',
                'VENTILATION-GAP' => 'Show the ventilation gaps required for the build-in.',
                'INDICATOR-LIGHTS' => 'Show all indicator lights and display functions.',
                'SOUND-LEVEL' => 'Provide a video with sound to demonstrate operational noise.',
            ),
            'OTHER' => array(
                'DIMENSION-OVERLAY' => 'Provide an overall width / depth / height overlay for scale.',
                'FINISH-DETAIL' => 'Please reshoot showing finish detail in close-up.',
                'MATERIAL-CLOSEUP' => 'Show material and construction in close-up.',
            ),
        );
        
        // Process each category
        foreach ( $category_keyword_phrases as $category_name => $keyword_phrases ) {
            // Get category_id by name (case-insensitive)
            $category = $wpdb->get_row( $wpdb->prepare(
                "SELECT category_id FROM {$categories_table} WHERE LOWER(name) = LOWER(%s) LIMIT 1",
                $category_name
            ), ARRAY_A );
            
            if ( ! $category ) {
                continue; // Skip if category doesn't exist
            }
            
            $category_id = intval( $category['category_id'] );
            
            // Process each keyword-phrase pair
            foreach ( $keyword_phrases as $keyword_name => $phrase_text ) {
                // Try exact match first (case-insensitive)
                $keyword = $wpdb->get_row( $wpdb->prepare(
                    "SELECT keyword_id FROM {$keywords_table} 
                    WHERE LOWER(keyword) = LOWER(%s) AND category_id = %d LIMIT 1",
                    $keyword_name,
                    $category_id
                ), ARRAY_A );
                
                // If exact match fails, try flexible matching (keyword contains the search term)
                if ( ! $keyword ) {
                    // Convert 'JOINERY-DETAIL' to 'joinery detail' for flexible matching
                    $search_term = str_replace( array( '-', '_' ), ' ', strtolower( $keyword_name ) );
                    $keyword = $wpdb->get_row( $wpdb->prepare(
                        "SELECT keyword_id FROM {$keywords_table} 
                        WHERE category_id = %d 
                        AND (LOWER(keyword) LIKE %s OR LOWER(keyword) LIKE %s)
                        LIMIT 1",
                        $category_id,
                        '%' . $wpdb->esc_like( $search_term ) . '%',
                        '%' . $wpdb->esc_like( str_replace( ' ', '', $search_term ) ) . '%'
                    ), ARRAY_A );
                }
                
                // If still no match, try matching just the first significant word
                if ( ! $keyword ) {
                    $first_word = explode( ' ', strtolower( $keyword_name ) )[0];
                    $first_word = str_replace( array( '-', '_' ), '', $first_word );
                    if ( strlen( $first_word ) > 3 ) {
                        $keyword = $wpdb->get_row( $wpdb->prepare(
                            "SELECT keyword_id FROM {$keywords_table} 
                            WHERE category_id = %d 
                            AND LOWER(keyword) LIKE %s
                            LIMIT 1",
                            $category_id,
                            '%' . $wpdb->esc_like( $first_word ) . '%'
                        ), ARRAY_A );
                    }
                }
                
                if ( ! $keyword ) {
                    continue; // Skip if keyword doesn't exist
                }
                
                $keyword_id = intval( $keyword['keyword_id'] );
                
                // Check if phrase already exists for this keyword
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$keyword_phrases_table} 
                    WHERE keyword_id = %d AND phrase_text = %s",
                    $keyword_id,
                    $phrase_text
                ) );
                
                if ( $existing > 0 ) {
                    continue; // Skip if already exists
                }
                
                // Insert phrase
                $wpdb->insert(
                    $keyword_phrases_table,
                    array(
                        'category_id' => $category_id,
                        'keyword_id'  => $keyword_id,
                        'phrase_text' => $phrase_text,
                        'is_active'   => 1,
                        'sort_order'  => 0,
                        'created_at'  => current_time( 'mysql' ),
                    ),
                    array( '%d', '%d', '%s', '%d', '%d', '%s' )
                );
            }
        }
        
        // Fallback: Direct keyword_id to phrase mapping for known keywords that might not match by name
        // This ensures phrases are seeded even if name matching fails
        $direct_keyword_phrases = array(
            // Keyword ID 550: "Joinery detail (dovetail / dowel / cam)"
            550 => array(
                'Please reshoot showing joinery in close-up.',
                'Please reshoot to better show finish type (Matte / Gloss / Lacquer).',
                'Please demonstrate drawer glide operation.',
                'Please reshoot showing drawer interior dimensions.',
                'Please reshoot showing clearance and reveal spacing.',
                'Please demonstrate a structural stability test.',
                'Show solid wood vs veneer close-ups to confirm matching consistency.',
                'Please demonstrate the door swing and soft-close functionality.',
                'Please provide a demonstration of the leveling feet.',
                'Show a close-up of edge banding quality and junctions.',
            ),
        );
        
        foreach ( $direct_keyword_phrases as $direct_keyword_id => $direct_phrases ) {
            // Check if keyword exists
            $keyword_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$keywords_table} WHERE keyword_id = %d",
                $direct_keyword_id
            ) );
            
            if ( $keyword_exists > 0 ) {
                foreach ( $direct_phrases as $phrase_text ) {
                    // Check if phrase already exists
                    $existing = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$keyword_phrases_table} 
                        WHERE keyword_id = %d AND phrase_text = %s",
                        $direct_keyword_id,
                        $phrase_text
                    ) );
                    
                    if ( $existing == 0 ) {
                        // Get category_id for this keyword
                        $keyword_info = $wpdb->get_row( $wpdb->prepare(
                            "SELECT category_id FROM {$keywords_table} WHERE keyword_id = %d",
                            $direct_keyword_id
                        ), ARRAY_A );
                        
                        $phrase_category_id = $keyword_info && isset( $keyword_info['category_id'] ) ? intval( $keyword_info['category_id'] ) : null;
                        
                        // Insert phrase
                        $wpdb->insert(
                            $keyword_phrases_table,
                            array(
                                'category_id' => $phrase_category_id,
                                'keyword_id'  => $direct_keyword_id,
                                'phrase_text' => $phrase_text,
                                'is_active'   => 1,
                                'sort_order'  => 0,
                                'created_at'  => current_time( 'mysql' ),
                            ),
                            array( '%d', '%d', '%s', '%d', '%d', '%s' )
                        );
                    }
                }
            }
        }
    }
    
    /**
     * Check if a table exists before attempting schema changes.
     */
    private static function table_exists( $table_name ) {
        global $wpdb;
        $like = $wpdb->esc_like( $table_name );
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

        return $result === $table_name;
    }

    /**
     * Commit 2.6.1: Migrate firm_members table to support pending invitations
     * Adds: email, status, invitation_token, invited_at columns
     */
    private static function migrate_firm_members_for_invitations() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $firm_members_table = $wpdb->prefix . 'n88_firm_members';

        // Check if table exists
        if ( ! self::table_exists( $firm_members_table ) ) {
            error_log( 'N88 Firm Members: Table does not exist, skipping migration.' );
            return;
        }

        // Get existing columns
        $columns = $wpdb->get_col( "DESCRIBE {$firm_members_table}" );

        // Add email column (for pending invitations before user account exists)
        if ( ! in_array( 'email', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$firm_members_table} ADD COLUMN email VARCHAR(255) NULL AFTER firm_id" );
            error_log( 'N88 Firm Members: Added email column.' );
        }

        // Add status column (pending, active, inactive)
        if ( ! in_array( 'status', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$firm_members_table} ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active' AFTER role" );
            // Update existing records to 'active'
            $wpdb->query( "UPDATE {$firm_members_table} SET status = 'active' WHERE status IS NULL OR status = ''" );
            error_log( 'N88 Firm Members: Added status column.' );
        }

        // Add invitation_token column (for email verification)
        if ( ! in_array( 'invitation_token', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$firm_members_table} ADD COLUMN invitation_token VARCHAR(64) NULL AFTER status" );
            $wpdb->query( "ALTER TABLE {$firm_members_table} ADD UNIQUE KEY unique_invitation_token (invitation_token)" );
            error_log( 'N88 Firm Members: Added invitation_token column.' );
        }

        // Add invited_at column
        if ( ! in_array( 'invited_at', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$firm_members_table} ADD COLUMN invited_at DATETIME NULL AFTER invitation_token" );
            error_log( 'N88 Firm Members: Added invited_at column.' );
        }

        // Add index on email for faster lookups
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$firm_members_table} WHERE Key_name = 'idx_email'" );
        if ( empty( $indexes ) ) {
            $wpdb->query( "ALTER TABLE {$firm_members_table} ADD INDEX idx_email (email)" );
            error_log( 'N88 Firm Members: Added email index.' );
        }

        // Add index on status
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$firm_members_table} WHERE Key_name = 'idx_status'" );
        if ( empty( $indexes ) ) {
            $wpdb->query( "ALTER TABLE {$firm_members_table} ADD INDEX idx_status (status)" );
            error_log( 'N88 Firm Members: Added status index.' );
        }
    }

    /**
     * Create Phase 2.5.2 tables (Board-First Projects and Rooms)
     * 
     * Commit 2.5.2: Projects are containers within boards, with rooms and items
     */
    private static function create_phase_2_5_2_tables( $charset_collate ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $projects_table = $wpdb->prefix . 'n88_projects';
        $project_rooms_table = $wpdb->prefix . 'n88_project_rooms';
        $items_table = $wpdb->prefix . 'n88_items';

        // 1. n88_projects - Projects within boards
        $sql_projects = "CREATE TABLE {$projects_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            board_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status ENUM('draft', 'locked') NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY board_id (board_id),
            KEY status (status),
            KEY created_at (created_at),
            KEY deleted_at (deleted_at)
        ) {$charset_collate};";

        // 2. n88_project_rooms - Rooms within projects
        $sql_project_rooms = "CREATE TABLE {$project_rooms_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            display_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY display_order (display_order),
            KEY created_at (created_at),
            KEY deleted_at (deleted_at)
        ) {$charset_collate};";

        // Create tables
        dbDelta( $sql_projects );
        dbDelta( $sql_project_rooms );

        // Add project_id and room_id columns to items table if they don't exist
        $items_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $items_table );
        $items_columns = $wpdb->get_col( "DESCRIBE {$items_table_safe}" );
        
        if ( ! in_array( 'project_id', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER owner_firm_id" );
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD KEY project_id (project_id)" );
        }
        
        if ( ! in_array( 'room_id', $items_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD COLUMN room_id BIGINT UNSIGNED NULL AFTER project_id" );
            $wpdb->query( "ALTER TABLE {$items_table_safe} ADD KEY room_id (room_id)" );
        }
    }
}
// .....