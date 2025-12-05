<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class N88_RFQ_Installer {

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

        self::maybe_upgrade();
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
            $comments_columns = $wpdb->get_col( "DESCRIBE {$comments_table}" );

            if ( ! in_array( 'video_id', $comments_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$comments_table} ADD COLUMN video_id VARCHAR(255) NULL AFTER item_id" );
                $wpdb->query( "ALTER TABLE {$comments_table} ADD KEY video_id (video_id)" );
            }

            if ( ! in_array( 'is_urgent', $comments_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$comments_table} ADD COLUMN is_urgent TINYINT(1) NOT NULL DEFAULT 0 AFTER comment_text" );
                $wpdb->query( "ALTER TABLE {$comments_table} ADD KEY is_urgent (is_urgent)" );
            }

            if ( ! in_array( 'parent_comment_id', $comments_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$comments_table} ADD COLUMN parent_comment_id BIGINT UNSIGNED NULL AFTER is_urgent" );
                $wpdb->query( "ALTER TABLE {$comments_table} ADD KEY parent_comment_id (parent_comment_id)" );
            }
        }

        // Projects table upgrades
        if ( self::table_exists( $projects_table ) ) {
            $projects_columns = $wpdb->get_col( "DESCRIBE {$projects_table}" );

            if ( ! in_array( 'item_count', $projects_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$projects_table} ADD COLUMN item_count INT UNSIGNED DEFAULT 0 AFTER quote_type" );
            }
        }

        // Quotes table upgrades
        if ( self::table_exists( $quotes_table ) ) {
            $quotes_columns = $wpdb->get_col( "DESCRIBE {$quotes_table}" );
            $pricing_columns = array(
                'labor_cost'          => "ALTER TABLE {$quotes_table} ADD COLUMN labor_cost DECIMAL(10,2) NULL DEFAULT 0.00 AFTER sent_at",
                'materials_cost'      => "ALTER TABLE {$quotes_table} ADD COLUMN materials_cost DECIMAL(10,2) NULL DEFAULT 0.00 AFTER labor_cost",
                'overhead_cost'       => "ALTER TABLE {$quotes_table} ADD COLUMN overhead_cost DECIMAL(10,2) NULL DEFAULT 0.00 AFTER materials_cost",
                'margin_percentage'   => "ALTER TABLE {$quotes_table} ADD COLUMN margin_percentage DECIMAL(5,2) NULL DEFAULT 0.00 AFTER overhead_cost",
                'shipping_zone'       => "ALTER TABLE {$quotes_table} ADD COLUMN shipping_zone VARCHAR(100) NULL AFTER margin_percentage",
                'unit_price'          => "ALTER TABLE {$quotes_table} ADD COLUMN unit_price DECIMAL(10,2) NULL DEFAULT 0.00 AFTER shipping_zone",
                'total_price'         => "ALTER TABLE {$quotes_table} ADD COLUMN total_price DECIMAL(10,2) NULL DEFAULT 0.00 AFTER unit_price",
                'lead_time'           => "ALTER TABLE {$quotes_table} ADD COLUMN lead_time VARCHAR(50) NULL AFTER total_price",
                'cbm_volume'          => "ALTER TABLE {$quotes_table} ADD COLUMN cbm_volume DECIMAL(10,4) NULL DEFAULT 0.0000 AFTER lead_time",
                'volume_rules_applied'=> "ALTER TABLE {$quotes_table} ADD COLUMN volume_rules_applied TEXT NULL AFTER cbm_volume",
            );

            foreach ( $pricing_columns as $column => $sql ) {
                if ( ! in_array( $column, $quotes_columns, true ) ) {
                    $wpdb->query( $sql );
                }
            }

            // Add client_message and quote_items columns
            if ( ! in_array( 'client_message', $quotes_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$quotes_table} ADD COLUMN client_message LONGTEXT NULL AFTER volume_rules_applied" );
            }
            if ( ! in_array( 'quote_items', $quotes_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$quotes_table} ADD COLUMN quote_items LONGTEXT NULL AFTER client_message" );
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