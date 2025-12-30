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

        // Check if foreign keys exist before adding
        $supplier_fks = $wpdb->get_results( "SHOW CREATE TABLE {$supplier_table_safe}" );
        $designer_fks = $wpdb->get_results( "SHOW CREATE TABLE {$designer_table_safe}" );

        $supplier_has_fk_user = false;
        $supplier_has_fk_category = false;
        $designer_has_fk_user = false;

        if ( ! empty( $supplier_fks ) ) {
            $create_statement = $supplier_fks[0]->{'Create Table'};
            $supplier_has_fk_user = strpos( $create_statement, 'fk_supplier_user' ) !== false;
            $supplier_has_fk_category = strpos( $create_statement, 'fk_supplier_category' ) !== false;
        }

        if ( ! empty( $designer_fks ) ) {
            $create_statement = $designer_fks[0]->{'Create Table'};
            $designer_has_fk_user = strpos( $create_statement, 'fk_designer_user' ) !== false;
        }

        // Add foreign key: supplier_id -> wp_users.ID
        if ( ! $supplier_has_fk_user ) {
            $wpdb->query( "ALTER TABLE {$supplier_table_safe} ADD CONSTRAINT fk_supplier_user FOREIGN KEY (supplier_id) REFERENCES {$users_table_safe}(ID) ON DELETE CASCADE" );
        }

        // Add foreign key: primary_category_id -> n88_categories.category_id
        if ( ! $supplier_has_fk_category ) {
            $wpdb->query( "ALTER TABLE {$supplier_table_safe} ADD CONSTRAINT fk_supplier_category FOREIGN KEY (primary_category_id) REFERENCES {$categories_table_safe}(category_id) ON DELETE SET NULL" );
        }

        // Add foreign key: designer_id -> wp_users.ID
        if ( ! $designer_has_fk_user ) {
            $wpdb->query( "ALTER TABLE {$designer_table_safe} ADD CONSTRAINT fk_designer_user FOREIGN KEY (designer_id) REFERENCES {$users_table_safe}(ID) ON DELETE CASCADE" );
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

        // Check if foreign keys exist before adding
        $keywords_fks = $wpdb->get_results( "SHOW CREATE TABLE {$keywords_table_safe}" );
        $map_fks = $wpdb->get_results( "SHOW CREATE TABLE {$supplier_keyword_map_table_safe}" );
        $freeform_fks = $wpdb->get_results( "SHOW CREATE TABLE {$supplier_keyword_freeform_table_safe}" );

        $keywords_has_fk_category = false;
        $map_has_fk_supplier = false;
        $map_has_fk_keyword = false;
        $freeform_has_fk_supplier = false;

        if ( ! empty( $keywords_fks ) ) {
            $create_statement = $keywords_fks[0]->{'Create Table'};
            $keywords_has_fk_category = strpos( $create_statement, 'fk_keyword_category' ) !== false;
        }

        if ( ! empty( $map_fks ) ) {
            $create_statement = $map_fks[0]->{'Create Table'};
            $map_has_fk_supplier = strpos( $create_statement, 'fk_map_supplier' ) !== false;
            $map_has_fk_keyword = strpos( $create_statement, 'fk_map_keyword' ) !== false;
        }

        if ( ! empty( $freeform_fks ) ) {
            $create_statement = $freeform_fks[0]->{'Create Table'};
            $freeform_has_fk_supplier = strpos( $create_statement, 'fk_freeform_supplier' ) !== false;
        }

        // Add foreign key: category_id -> n88_categories.category_id
        if ( ! $keywords_has_fk_category ) {
            $wpdb->query( "ALTER TABLE {$keywords_table_safe} ADD CONSTRAINT fk_keyword_category FOREIGN KEY (category_id) REFERENCES {$categories_table_safe}(category_id) ON DELETE SET NULL" );
        }

        // Add foreign key: supplier_id -> wp_users.ID (map table)
        if ( ! $map_has_fk_supplier ) {
            $wpdb->query( "ALTER TABLE {$supplier_keyword_map_table_safe} ADD CONSTRAINT fk_map_supplier FOREIGN KEY (supplier_id) REFERENCES {$users_table_safe}(ID) ON DELETE CASCADE" );
        }

        // Add foreign key: keyword_id -> n88_keywords.keyword_id (map table)
        if ( ! $map_has_fk_keyword ) {
            $wpdb->query( "ALTER TABLE {$supplier_keyword_map_table_safe} ADD CONSTRAINT fk_map_keyword FOREIGN KEY (keyword_id) REFERENCES {$keywords_table_safe}(keyword_id) ON DELETE CASCADE" );
        }

        // Add foreign key: supplier_id -> wp_users.ID (freeform table)
        if ( ! $freeform_has_fk_supplier ) {
            $wpdb->query( "ALTER TABLE {$supplier_keyword_freeform_table_safe} ADD CONSTRAINT fk_freeform_supplier FOREIGN KEY (supplier_id) REFERENCES {$users_table_safe}(ID) ON DELETE CASCADE" );
        }
    }

    /**
     * Seed keyword library with suggested keywords per SOT (Commit 2.2.5)
     * Seeds keywords exactly as specified, linked to correct categories
     */
    private static function seed_keyword_library() {
        global $wpdb;

        $categories_table = $wpdb->prefix . 'n88_categories';
        $keywords_table = $wpdb->prefix . 'n88_keywords';

        // First, ensure categories exist (create if they don't)
        $categories = array(
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
            'Appliances'
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

        // Define keywords by category (exactly as specified in SOT)
        $keywords_by_category = array(
            'Upholstery' => array(
                'COM / COL',
                'Banquettes',
                'Curved seating',
                'Tufting',
                'Channel stitching',
                'Tight seat / loose seat',
                'Bench seating',
                'Dining chairs',
                'Barstools upholstery',
                'Headboards',
                'Ottomans',
                'Booth seating (F&B)',
                'Leather',
                'Performance fabric',
                'Hospitality grade',
                'CAL117 / FR compliance'
            ),
            'Indoor Furniture (Casegoods)' => array(
                'Solid wood',
                'Veneer',
                'Lacquer finish',
                'High gloss',
                'Matte finish',
                'RTA / KD (knockdown)',
                'Tables (dining / coffee / side)',
                'Nightstands',
                'Dressers',
                'Desks',
                'Credenzas',
                'Built-in look',
                'Hospitality casegoods',
                'Contract grade',
                'Mixed materials'
            ),
            'Outdoor Furniture' => array(
                'Aluminum frames',
                'Stainless steel',
                'Teak',
                'Powder coat',
                'Rope weave',
                'Wicker / resin weave',
                'Sling',
                'Upholstered outdoor cushions',
                'Quick dry foam',
                'Sunbrella / outdoor fabrics',
                'Sectionals',
                'Daybeds',
                'Chaise lounges',
                'Outdoor dining',
                'Salt-air / coastal spec'
            ),
            'Lighting' => array(
                'Decorative lighting',
                'Custom chandeliers',
                'Sconces',
                'Pendants',
                'Table lamps',
                'Floor lamps',
                'LED',
                'Dimmable',
                'UL / ETL',
                'Hospitality lighting',
                'Custom finishes',
                'Glass shades',
                'Metal shades'
            ),
            'Stone (Marble / Granite / Quartz)' => array(
                'Marble',
                'Granite',
                'Quartz',
                'Sintered stone',
                'Porcelain slab',
                'Waterjet',
                'Edge profiles',
                'Bookmatch',
                'Honed / polished / leathered',
                'Thickness (2cm / 3cm)',
                'Vanity tops',
                'Tabletops',
                'Feature walls',
                'Hospitality stone packages'
            ),
            'Metalwork' => array(
                'Stainless steel',
                'Aluminum',
                'Brass',
                'Bronze',
                'Blackened steel',
                'Patina finishes',
                'Welded frames',
                'Sheet metal',
                'Laser cut',
                'CNC bending',
                'Architectural metal',
                'Custom hardware'
            ),
            'Millwork / Cabinetry' => array(
                'Kitchen cabinetry',
                'Bathroom vanities',
                'Veneer matching',
                'Laminate',
                'Thermofoil',
                'Paint grade',
                'Stain grade',
                'Soft-close hardware',
                'Hospitality millwork',
                'Reception desks',
                'Built-ins',
                'Shop drawings / submittals'
            ),
            'Flooring' => array(
                'Engineered wood',
                'Solid wood',
                'LVT',
                'Tile',
                'Stone flooring',
                'Terrazzo',
                'Underlayment',
                'Moisture barrier',
                'Commercial spec',
                'Hospitality corridors',
                'Stair nosings'
            ),
            'Drapery / Window Treatments' => array(
                'Blackout',
                'Sheer',
                'Motorized',
                'Manual',
                'Roller shades',
                'Roman shades',
                'Track systems',
                'Hospitality drapery',
                'COM',
                'Hardware included'
            ),
            'Glass / Mirrors' => array(
                'Tempered',
                'Laminated',
                'Safety glass',
                'Mirrors (antique, smoked)',
                'Beveled',
                'Custom shapes',
                'Back-painted'
            ),
            'Hardware / Accessories' => array(
                'Pulls / knobs',
                'Hinges',
                'Locks',
                'Bathroom accessories',
                'Door hardware',
                'Custom finishes',
                'Hospitality durability'
            ),
            'Rugs / Carpets' => array(
                'Broadloom',
                'Area rugs',
                'Hand-tufted',
                'Hand-knotted',
                'Flatweave',
                'Custom patterns',
                'Hospitality rating',
                'Stain resistant'
            ),
            'Wallcoverings / Finishes' => array(
                'Wallpaper',
                'Vinyl wallcovering',
                'Acoustic panels',
                'Decorative panels',
                'Paint systems',
                'Hospitality durability'
            ),
            'Appliances' => array(
                'Built-in',
                'Commercial kitchen spec',
                'Panels / integrated fronts',
                'Voltage requirements'
            )
        );

        // Insert keywords, avoiding duplicates
        foreach ( $keywords_by_category as $category_name => $keywords ) {
            $category_id = isset( $category_ids[ $category_name ] ) ? $category_ids[ $category_name ] : null;

            foreach ( $keywords as $keyword ) {
                // Check if keyword already exists
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT keyword_id FROM {$keywords_table} WHERE keyword = %s AND category_id = %d",
                    $keyword,
                    $category_id
                ) );

                if ( ! $existing ) {
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
     * Check if a table exists before attempting schema changes.
     */
    private static function table_exists( $table_name ) {
        global $wpdb;
        $like = $wpdb->esc_like( $table_name );
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

        return $result === $table_name;
    }
}
// .....