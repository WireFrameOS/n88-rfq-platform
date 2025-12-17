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

        self::maybe_upgrade();
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

        // Add dimension columns (after primary_image_id)
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

        // Add cbm column (after dimension_units_original)
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