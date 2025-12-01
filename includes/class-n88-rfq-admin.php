<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin-facing dashboard scaffolding.
 * Developer: build admin menus + pages here to match Figma/admin designs.
 */
class N88_RFQ_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menus' ) );
    }

    public function render_notifications_center() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to view this page.', 'n88-rfq' ) );
        }

        global $wpdb;
        $table_notifications = $wpdb->prefix . 'project_notifications';
        $table_projects      = $wpdb->prefix . 'projects';
        $table_users         = $wpdb->users;

        $status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'all';
        $type   = isset( $_GET['notification_type'] ) ? sanitize_text_field( $_GET['notification_type'] ) : '';
        $limit  = isset( $_GET['limit'] ) ? max( 10, min( 500, intval( $_GET['limit'] ) ) ) : 100;

        $admin_users = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
        $admin_ids = array_map( 'intval', $admin_users );

        $where = array( '1=1' );
        if ( 'unread' === $status ) {
            $where[] = 'n.is_read = 0';
        } elseif ( 'read' === $status ) {
            $where[] = 'n.is_read = 1';
        }

        $prepare_values = array();

        if ( ! empty( $admin_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $admin_ids ), '%d' ) );
            $where[] = "n.user_id IN ( {$placeholders} )";
            $prepare_values = array_merge( $prepare_values, $admin_ids );
        }

        if ( ! empty( $type ) ) {
            $where[] = 'n.notification_type = %s';
            $prepare_values[] = $type;
        }

        $where_sql = implode( ' AND ', $where );

        $query = "
            SELECT n.*, p.project_name, p.user_id AS project_owner_id,
                   recipient.display_name AS recipient_name,
                   owner.display_name AS owner_name
            FROM {$table_notifications} n
            LEFT JOIN {$table_projects} p ON n.project_id = p.id
            LEFT JOIN {$table_users} recipient ON n.user_id = recipient.ID
            LEFT JOIN {$table_users} owner ON p.user_id = owner.ID
            WHERE {$where_sql}
            ORDER BY n.created_at DESC
            LIMIT %d
        ";

        $prepare_values[] = $limit;
        $notifications = $wpdb->get_results( $wpdb->prepare( $query, $prepare_values ) );

        $notification_types = $wpdb->get_col( "SELECT DISTINCT notification_type FROM {$table_notifications} ORDER BY notification_type ASC" );
        ?>
        <div class="wrap n88-admin-notifications">
            <h1><?php esc_html_e( 'Notifications Center', 'n88-rfq' ); ?></h1>

            <form method="get" class="n88-filters">
                <input type="hidden" name="page" value="n88-rfq-notifications" />
                <label>
                    <span><?php esc_html_e( 'Status', 'n88-rfq' ); ?></span>
                    <select name="status">
                        <option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'All', 'n88-rfq' ); ?></option>
                        <option value="unread" <?php selected( $status, 'unread' ); ?>><?php esc_html_e( 'Unread', 'n88-rfq' ); ?></option>
                        <option value="read" <?php selected( $status, 'read' ); ?>><?php esc_html_e( 'Read', 'n88-rfq' ); ?></option>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e( 'Type', 'n88-rfq' ); ?></span>
                    <select name="notification_type">
                        <option value=""><?php esc_html_e( 'All Types', 'n88-rfq' ); ?></option>
                        <?php foreach ( $notification_types as $notif_type ) : ?>
                            <option value="<?php echo esc_attr( $notif_type ); ?>" <?php selected( $type, $notif_type ); ?>>
                                <?php echo esc_html( ucwords( str_replace( '_', ' ', $notif_type ) ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e( 'Limit', 'n88-rfq' ); ?></span>
                    <input type="number" name="limit" value="<?php echo esc_attr( $limit ); ?>" min="10" max="500" />
                </label>

                <button class="button button-primary"><?php esc_html_e( 'Filter', 'n88-rfq' ); ?></button>
            </form>

            <table class="widefat fixed striped n88-notifications-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'n88-rfq' ); ?></th>
                        <th><?php esc_html_e( 'Project', 'n88-rfq' ); ?></th>
                        <th><?php esc_html_e( 'Recipient', 'n88-rfq' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'n88-rfq' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'n88-rfq' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'n88-rfq' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $notifications ) ) : ?>
                        <tr>
                            <td colspan="6" style="text-align:center;"><?php esc_html_e( 'No notifications found for the selected filters.', 'n88-rfq' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $notifications as $notification ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $notification->created_at ) ) ); ?></strong>
                                    <div class="description"><?php echo esc_html( date_i18n( get_option( 'time_format' ), strtotime( $notification->created_at ) ) ); ?></div>
                                </td>
                                <td>
                                    <?php if ( $notification->project_id ) : ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=n88-rfq-projects&project_id=' . $notification->project_id ) ); ?>">
                                            <?php echo esc_html( $notification->project_name ?: __( 'Unknown Project', 'n88-rfq' ) ); ?>
                                        </a>
                                        <?php if ( $notification->owner_name ) : ?>
                                            <div class="description"><?php printf( esc_html__( 'Owner: %s', 'n88-rfq' ), esc_html( $notification->owner_name ) ); ?></div>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <em><?php esc_html_e( 'General', 'n88-rfq' ); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html( $notification->recipient_name ?: __( 'Unknown user', 'n88-rfq' ) ); ?>
                                    <div class="description"><?php printf( esc_html__( 'User ID: %d', 'n88-rfq' ), (int) $notification->user_id ); ?></div>
                                </td>
                                <td>
                                    <span class="n88-type-badge"><?php echo esc_html( ucwords( str_replace( '_', ' ', $notification->notification_type ) ) ); ?></span>
                                </td>
                                <td><?php echo esc_html( $notification->message ); ?></td>
                                <td>
                                    <?php if ( $notification->is_read ) : ?>
                                        <span class="status-read"><?php esc_html_e( 'Read', 'n88-rfq' ); ?></span>
                                    <?php else : ?>
                                        <span class="status-unread"><?php esc_html_e( 'Unread', 'n88-rfq' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <style>
            .n88-admin-notifications .n88-filters {
                display: flex;
                gap: 20px;
                align-items: flex-end;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }
            .n88-admin-notifications .n88-filters label {
                display: flex;
                flex-direction: column;
                font-weight: 600;
            }
            .n88-notifications-table .description {
                color: #666;
                font-size: 12px;
            }
            .n88-type-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 999px;
                background: #eef2ff;
                color: #3730a3;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .status-read,
            .status-unread {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-weight: 600;
            }
            .status-read::before,
            .status-unread::before {
                content: '';
                width: 8px;
                height: 8px;
                border-radius: 50%;
                display: inline-block;
            }
            .status-read {
                color: #2e7d32;
            }
            .status-read::before {
                background: #2e7d32;
            }
            .status-unread {
                color: #c62828;
            }
            .status-unread::before {
                background: #c62828;
            }
        </style>
        <?php
    }

    public function register_menus() {
        add_menu_page(
            __( 'NorthEightyEight RFQ', 'n88-rfq' ),
            'N88 RFQ',
            'manage_options',
            'n88-rfq-dashboard',
            array( $this, 'render_main_dashboard' ),
            'dashicons-feedback',
            26
        );

        add_submenu_page(
            'n88-rfq-dashboard',
            __( 'Projects', 'n88-rfq' ),
            __( 'Projects', 'n88-rfq' ),
            'manage_options',
            'n88-rfq-projects',
            array( $this, 'render_projects_list' )
        );

        add_submenu_page(
            'n88-rfq-dashboard',
            __( 'Quotes', 'n88-rfq' ),
            __( 'Quotes', 'n88-rfq' ),
            'manage_options',
            'n88-rfq-quotes',
            array( $this, 'render_quotes_manager' )
        );

        add_submenu_page(
            'n88-rfq-dashboard',
            __( 'Notifications', 'n88-rfq' ),
            __( 'Notifications', 'n88-rfq' ),
            'manage_options',
            'n88-rfq-notifications',
            array( $this, 'render_notifications_center' )
        );

        add_submenu_page(
            'n88-rfq-dashboard',
            __( 'Comments', 'n88-rfq' ),
            __( 'Comments', 'n88-rfq' ),
            'manage_options',
            'n88-rfq-comments',
            array( $this, 'render_comments_hub' )
        );

        add_submenu_page(
            'n88-rfq-dashboard',
            __( 'Audit Trail', 'n88-rfq' ),
            __( 'Audit Trail', 'n88-rfq' ),
            'manage_options',
            'n88-rfq-audit',
            array( $this, 'render_audit_trail' )
        );

        add_submenu_page(
            'n88-rfq-dashboard',
            __( 'Content Manager', 'n88-rfq' ),
            __( 'Content Manager', 'n88-rfq' ),
            'manage_options',
            'n88-rfq-content',
            array( $this, 'render_content_manager' )
        );
    }

    public function render_main_dashboard() {
        echo '<div class="wrap"><h1>NorthEightyEight RFQ – Admin Dashboard</h1><p>TODO: Implement cards and metrics per design.</p></div>';
    }

    public function render_projects_list() {
        echo '<div class="wrap"><h1>Projects</h1><p>TODO: Implement filters, table, and status chips.</p></div>';
    }

    /**
     * Render quotes manager page
     */
    public function render_quotes_manager() {
        global $wpdb;

        $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
        $project_id = isset( $_GET['project_id'] ) ? intval( $_GET['project_id'] ) : 0;

        ?>
        <div class="wrap">
            <h1>Quote Manager</h1>

            <?php if ( $action === 'list' ) : ?>
                <p>Manage quotes for all projects.</p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Sent</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $projects_table = $wpdb->prefix . 'projects';
                        $quotes_table = $wpdb->prefix . 'project_quotes';

                        // Show ALL projects (draft and submitted) - admin can upload quotes for any
                        $projects = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT p.id, p.project_name, p.status, p.created_at as project_created, q.id as quote_id, q.quote_status, q.created_at as quote_created, q.sent_at
                                FROM {$projects_table} p
                                LEFT JOIN {$quotes_table} q ON p.id = q.project_id
                                ORDER BY p.created_at DESC
                                LIMIT 50"
                            )
                        );
                        
                        // Fetch client update flags for all projects
                        $project_ids = array_map( function( $p ) { return $p->id; }, $projects );
                        $client_updates_map = array();
                        if ( ! empty( $project_ids ) ) {
                            $meta_table = $wpdb->prefix . 'project_metadata';
                            $ids_sql = implode( ',', array_map( 'intval', $project_ids ) );
                            $update_rows = $wpdb->get_results(
                                "SELECT project_id, meta_value 
                                FROM {$meta_table} 
                                WHERE meta_key = 'n88_has_client_updates' 
                                AND meta_value = '1'
                                AND project_id IN ({$ids_sql})"
                            );
                            foreach ( $update_rows as $row ) {
                                $client_updates_map[ $row->project_id ] = true;
                            }
                        }

                        if ( empty( $projects ) ) {
                            echo '<tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;">No projects found</td></tr>';
                        }

                        foreach ( $projects as $project ) {
                            // Show project status
                            $project_status = ( $project->status == 1 ) ? 'Submitted' : 'Draft';
                            $status_badge_color = ( $project->status == 1 ) ? '#4CAF50' : '#FF9800';
                            ?>
                            <tr>
                                <td>
                                    <?php echo esc_html( $project->project_name ); ?> <span style="color: #999; font-size: 12px;">(<?php echo esc_html( $project_status ); ?>)</span>
                                    <?php if ( ! empty( $client_updates_map[ $project->id ] ) ) : ?>
                                        <span style="background: #ff5252; color: #fff; padding: 3px 8px; border-radius: 999px; font-weight: 600; font-size: 11px; letter-spacing: 0.5px; margin-left: 8px;">NEW UPDATES</span>
                                        <span style="background: #ffe0b2; color: #bf360c; padding: 3px 8px; border-radius: 999px; font-weight: 600; font-size: 11px; margin-left: 4px;">Updated by Client</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( $project->quote_id ) : ?>
                                        <span class="badge" style="background: <?php echo ( $project->quote_status === 'sent' || $project->quote_status === 'quote_updated' ) ? ( $project->quote_status === 'quote_updated' ? '#ff9800' : '#4caf50' ) : '#ff9800'; ?>; color: white; padding: 4px 8px; border-radius: 3px;">
                                            <?php 
                                            if ( $project->quote_status === 'quote_updated' ) {
                                                echo 'Quote Updated';
                                            } elseif ( $project->quote_status === 'sent' ) {
                                                echo 'Quote Sent';
                                            } else {
                                                echo esc_html( ucfirst( $project->quote_status ) );
                                            }
                                            ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="badge" style="background: #999; color: white; padding: 4px 8px; border-radius: 3px;">No Quote</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $project->quote_id ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $project->quote_created ) ) ) : '—'; ?></td>
                                <td><?php echo $project->sent_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $project->sent_at ) ) ) : '—'; ?></td>
                                <td>
                                    <a href="<?php echo add_query_arg( array( 'action' => 'edit', 'project_id' => $project->id ) ); ?>" class="button">Manage</a>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>

            <?php elseif ( $action === 'edit' && $project_id ) : ?>
                <h2>Manage Quote for Project</h2>
                <?php
                $projects_class = new N88_RFQ_Projects();
                $project = $projects_class->get_project_admin( $project_id );

                if ( ! $project ) {
                    echo '<p>Project not found.</p>';
                    return;
                }

                // Handle clearing of client-updated flag (mark as viewed)
                if ( isset( $_POST['n88_clear_updates'] ) && check_admin_referer( 'n88_clear_updates' ) ) {
                    $clear_project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : $project_id;
                    if ( $clear_project_id ) {
                        $projects_class->save_project_metadata( $clear_project_id, array(
                            'n88_has_client_updates' => '0',
                            'n88_client_updates_viewed' => '1',
                            'n88_client_updates_viewed_at' => current_time( 'mysql' ),
                            'n88_client_updates_viewed_by' => (string) get_current_user_id(),
                        ) );
                        // Refresh project data immediately
                        $project = $projects_class->get_project_admin( $clear_project_id );
                        // Redirect to refresh the page and show updated state
                        wp_redirect( admin_url( 'admin.php?page=n88-rfq-quotes&action=edit&project_id=' . $clear_project_id . '&updated=1' ) );
                        exit;
                    }
                }

                // Handle quote upload with pricing
                if ( ( isset( $_POST['n88_upload_quote'] ) || isset( $_POST['n88_send_quote'] ) ) && check_admin_referer( 'n88_upload_quote' ) ) {
                    if ( ! empty( $_FILES['quote_file']['name'] ) ) {
                        $client_message = isset( $_POST['client_message'] ) ? sanitize_textarea_field( $_POST['client_message'] ) : '';
                        $should_send = isset( $_POST['n88_send_quote'] );
                        
                        $quote_id = N88_RFQ_Quotes::upload_quote( array(
                            'project_id' => $project_id,
                            'user_id' => get_current_user_id(),
                            'admin_notes' => isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( $_POST['admin_notes'] ) : '',
                            'client_message' => $client_message,
                            'quote_file' => $_FILES['quote_file'],
                            'labor_cost' => isset( $_POST['labor_cost'] ) ? (float) $_POST['labor_cost'] : 0,
                            'materials_cost' => isset( $_POST['materials_cost'] ) ? (float) $_POST['materials_cost'] : 0,
                            'overhead_cost' => isset( $_POST['overhead_cost'] ) ? (float) $_POST['overhead_cost'] : 0,
                            'margin_percentage' => isset( $_POST['margin_percentage'] ) ? (float) $_POST['margin_percentage'] : 0,
                            'shipping_zone' => isset( $_POST['shipping_zone'] ) ? sanitize_text_field( $_POST['shipping_zone'] ) : '',
                        ) );

                        if ( $quote_id ) {
                            if ( $should_send ) {
                                // Send quote to client
                                if ( N88_RFQ_Quotes::send_quote( $quote_id, get_current_user_id() ) ) {
                                    echo '<div class="notice notice-success"><p>Quote uploaded and sent to client successfully!</p></div>';
                                } else {
                                    echo '<div class="notice notice-warning"><p>Quote uploaded but failed to send. You can send it manually from the quote details.</p></div>';
                                }
                            } else {
                                echo '<div class="notice notice-success"><p>Quote saved as draft successfully!</p></div>';
                            }
                        } else {
                            echo '<div class="notice notice-error"><p>Failed to upload quote.</p></div>';
                        }
                    } else {
                        echo '<div class="notice notice-error"><p>Please select a quote file to upload.</p></div>';
                    }
                }

                // Handle instant pricing calculation (AJAX or form submission)
                if ( isset( $_POST['n88_calculate_pricing'] ) && check_admin_referer( 'n88_calculate_pricing' ) ) {
                    $labor_cost = isset( $_POST['labor_cost'] ) ? (float) $_POST['labor_cost'] : 0;
                    $materials_cost = isset( $_POST['materials_cost'] ) ? (float) $_POST['materials_cost'] : 0;
                    $overhead_cost = isset( $_POST['overhead_cost'] ) ? (float) $_POST['overhead_cost'] : 0;
                    $margin_percentage = isset( $_POST['margin_percentage'] ) ? (float) $_POST['margin_percentage'] : 0;
                    $shipping_zone = isset( $_POST['shipping_zone'] ) ? sanitize_text_field( $_POST['shipping_zone'] ) : '';

                    if ( class_exists( 'N88_RFQ_Pricing' ) ) {
                        $pricing_result = N88_RFQ_Pricing::calculate_project_pricing(
                            $project_id,
                            $labor_cost,
                            $materials_cost,
                            $overhead_cost,
                            $margin_percentage,
                            $shipping_zone
                        );

                        if ( $pricing_result ) {
                            $calculated_pricing = $pricing_result;
                        }
                    }
                }

                // Show success message if redirected after marking as viewed
                if ( isset( $_GET['updated'] ) && $_GET['updated'] == '1' ) {
                    echo '<div class="notice notice-success is-dismissible"><p>Client updates have been marked as reviewed.</p></div>';
                }

                // Get existing quotes
                $quotes = N88_RFQ_Quotes::get_project_quotes( $project_id );
                $latest_quote = $quotes ? $quotes[0] : null;
                ?>

                <?php
                $has_client_updates = ! empty( $project['metadata']['n88_has_client_updates'] ) && '1' === $project['metadata']['n88_has_client_updates'];
                $client_updates_viewed = ! empty( $project['metadata']['n88_client_updates_viewed'] ) && '1' === $project['metadata']['n88_client_updates_viewed'];
                $last_client_update = $project['metadata']['n88_last_client_update'] ?? '';
                $last_client_update_by = $project['metadata']['n88_last_client_update_by'] ?? '';
                $last_client_updater = '';
                if ( $last_client_update_by ) {
                    $client_user = get_userdata( (int) $last_client_update_by );
                    $last_client_updater = $client_user ? $client_user->display_name : '';
                }
                ?>

                <!-- Client Updates Highlight Section (Show prominently at top if not viewed) -->
                <?php if ( $has_client_updates && ! $client_updates_viewed ) : ?>
                    <?php
                    // Get project items to show what was changed
                    $items_json = $projects_class->get_project_metadata( $project_id, 'n88_repeater_raw' );
                    $items = ! empty( $items_json ) ? json_decode( $items_json, true ) : array();
                    ?>
                    <div style="margin: 20px 0; padding: 25px; background: linear-gradient(135deg, #fff8e1 0%, #ffe0b2 100%); border: 3px solid #ff9800; border-radius: 8px; box-shadow: 0 4px 12px rgba(255,152,0,0.3); position: relative;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                            <div>
                                <h3 style="margin: 0 0 10px 0; color: #bf360c; font-size: 20px; display: flex; align-items: center; gap: 12px;">
                                    <span style="font-size: 32px;">⚠️</span>
                                    <span>Client Updated Items - Review Required</span>
                                </h3>
                                <p style="margin: 0; color: #856404; font-size: 15px; font-weight: 500;">
                                    <?php if ( $last_client_updater ) : ?>
                                        Updated by: <strong><?php echo esc_html( $last_client_updater ); ?></strong>
                                    <?php endif; ?>
                                    <?php if ( $last_client_update ) : ?>
                                        on <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_client_update ) ) ); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <form method="post" style="margin: 0;">
                                <?php wp_nonce_field( 'n88_clear_updates' ); ?>
                                <input type="hidden" name="project_id" value="<?php echo esc_attr( $project_id ); ?>">
                                <button type="submit" name="n88_clear_updates" value="1" class="button button-primary" style="background: #ff9800; border-color: #f57c00; color: #fff; font-weight: 700; padding: 10px 20px; font-size: 14px; box-shadow: 0 2px 8px rgba(255,152,0,0.4);">✓ Mark as Viewed</button>
                            </form>
                        </div>
                        
                        <?php if ( ! empty( $items ) ) : ?>
                            <div style="background: white; border-radius: 6px; padding: 20px; margin-top: 15px; border: 2px solid #ff9800;">
                                <h4 style="margin: 0 0 15px 0; color: #bf360c; font-size: 16px; font-weight: 700;">Updated Items (<?php echo count( $items ); ?>):</h4>
                                <div style="max-height: 400px; overflow-y: auto;">
                                    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                        <thead>
                                            <tr style="background: #fff3cd; border-bottom: 2px solid #ff9800;">
                                                <th style="padding: 12px; text-align: left; font-weight: 700; color: #856404;">Item #</th>
                                                <th style="padding: 12px; text-align: left; font-weight: 700; color: #856404;">Material</th>
                                                <th style="padding: 12px; text-align: left; font-weight: 700; color: #856404;">Dimensions (L×D×H)</th>
                                                <th style="padding: 12px; text-align: left; font-weight: 700; color: #856404;">Quantity</th>
                                                <th style="padding: 12px; text-align: left; font-weight: 700; color: #856404;">Construction Notes</th>
                                                <th style="padding: 12px; text-align: left; font-weight: 700; color: #856404;">Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $items as $index => $item ) : 
                                                $item_num = $index + 1;
                                                
                                                // Handle dimensions
                                                $length = $item['length_in'] ?? '';
                                                $depth = $item['depth_in'] ?? '';
                                                $height = $item['height_in'] ?? '';
                                                if ( isset( $item['dimensions'] ) && is_array( $item['dimensions'] ) ) {
                                                    $length = $item['dimensions']['length'] ?? $length;
                                                    $depth = $item['dimensions']['depth'] ?? $depth;
                                                    $height = $item['dimensions']['height'] ?? $height;
                                                }
                                                
                                                // Handle primary material
                                                $primary_material = $item['primary_material'] ?? '';
                                                if ( isset( $item['materials'] ) && is_array( $item['materials'] ) ) {
                                                    $primary_material = implode( ', ', $item['materials'] );
                                                }
                                            ?>
                                                <tr style="border-bottom: 1px solid #eee; background: <?php echo ( $index % 2 === 0 ) ? '#fff' : '#fffbf0'; ?>;">
                                                    <td style="padding: 12px; font-weight: 700; color: #ff9800; font-size: 16px;">#<?php echo esc_html( $item_num ); ?></td>
                                                    <td style="padding: 12px;"><?php echo esc_html( $primary_material ?: '—' ); ?></td>
                                                    <td style="padding: 12px; font-weight: 600;"><?php echo esc_html( $length . '×' . $depth . '×' . $height ); ?></td>
                                                    <td style="padding: 12px; font-weight: 600;"><?php echo esc_html( $item['quantity'] ?? '—' ); ?></td>
                                                    <td style="padding: 12px; color: #666;"><?php echo nl2br( esc_html( $item['construction_notes'] ?? '—' ) ); ?></td>
                                                    <td style="padding: 12px; color: #666; max-width: 200px;"><?php echo esc_html( $item['notes'] ?? '—' ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else : ?>
                            <div style="background: white; border-radius: 6px; padding: 20px; margin-top: 15px; text-align: center; color: #856404;">
                                <p style="margin: 0;">No items found in this project.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div style="background: white; padding: 20px; border-radius: 5px; margin: 20px 0; position: relative;">
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <h3 style="margin: 0;"><?php echo esc_html( $project['project_name'] ); ?></h3>
                        <?php if ( $has_client_updates ) : ?>
                            <span style="background: #ff5252; color: #fff; padding: 4px 10px; border-radius: 999px; font-weight: 600; font-size: 12px; letter-spacing: 0.5px;">NEW UPDATES</span>
                            <span style="background: #ffe0b2; color: #bf360c; padding: 4px 10px; border-radius: 999px; font-weight: 600; font-size: 12px;">Updated by Client</span>
                        <?php endif; ?>
                    </div>
                    <p><strong>Client:</strong> <?php echo esc_html( $project['metadata']['n88_company_name'] ?? 'N/A' ); ?></p>
                    <p><strong>Contact:</strong> <?php echo esc_html( $project['metadata']['n88_contact_name'] ?? 'N/A' ); ?></p>
                    <?php if ( $has_client_updates && $client_updates_viewed ) : ?>
                        <p style="margin-top: 10px; background: #e8f5e9; padding: 10px; border-radius: 4px; color: #2e7d32;">
                            <strong>✓ Client updates were reviewed on:</strong>
                            <?php
                                $viewed_at = $project['metadata']['n88_client_updates_viewed_at'] ?? '';
                                $viewed_by = $project['metadata']['n88_client_updates_viewed_by'] ?? '';
                                if ( $viewed_by ) {
                                    $viewer = get_userdata( (int) $viewed_by );
                                    if ( $viewer ) {
                                        echo esc_html( $viewer->display_name );
                                    }
                                }
                                if ( $viewed_at ) {
                                    echo ' • ' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $viewed_at ) ) );
                                }
                            ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ( $latest_quote ) : ?>
                    <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0; position: relative;">
                        <h3>Current Quote</h3>
                        <?php if ( $has_client_updates ) : ?>
                            <div style="margin-bottom: 20px; padding: 15px; background: #fff8e1; border-left: 4px solid #ff9800; border-radius: 4px;">
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                                    <span style="background: #ff5252; color: #fff; padding: 6px 12px; border-radius: 999px; font-weight: 700; font-size: 13px; letter-spacing: 0.5px;">NEW UPDATES</span>
                                    <span style="background: #ffe0b2; color: #bf360c; padding: 6px 12px; border-radius: 999px; font-weight: 700; font-size: 13px;">Updated by Client</span>
                                </div>
                                <?php if ( $last_client_update ) : ?>
                                    <p style="margin: 8px 0 0 0; color: #856404; font-size: 14px;">
                                        <strong>Latest Client Update:</strong>
                                        <?php
                                            if ( $last_client_updater ) {
                                                echo esc_html( $last_client_updater ) . ' • ';
                                            }
                                            echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_client_update ) ) );
                                        ?>
                                    </p>
                                <?php endif; ?>
                                <form method="post" style="margin-top: 12px;">
                                    <?php wp_nonce_field( 'n88_clear_updates' ); ?>
                                    <input type="hidden" name="project_id" value="<?php echo esc_attr( $project_id ); ?>">
                                    <button type="submit" name="n88_clear_updates" value="1" class="button button-primary">Mark Client Updates as Reviewed</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <p><strong>Status:</strong> 
                            <?php 
                            $status_display = $latest_quote->quote_status;
                            if ( $status_display === 'quote_updated' ) {
                                echo '<span style="color: #ff9800; font-weight: bold;">Quote Updated</span>';
                            } elseif ( $status_display === 'sent' ) {
                                echo '<span style="color: #4caf50; font-weight: bold;">Quote Sent</span>';
                            } else {
                                echo esc_html( ucfirst( $status_display ) );
                            }
                            ?>
                        </p>
                        <p><strong>Created:</strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $latest_quote->created_at ) ) ); ?></p>
                        <?php if ( $latest_quote->sent_at ) : ?>
                            <p><strong>Sent:</strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $latest_quote->sent_at ) ) ); ?></p>
                        <?php endif; ?>
                        <?php
                        $quote_formatted = N88_RFQ_Quotes::format_quote( $latest_quote );
                        ?>
                        <p><a href="<?php echo esc_url( $quote_formatted['quote_file_url'] ); ?>" class="button button-primary">Download Quote</a></p>

                            <?php
                        $has_pricing = (
                            ! empty( $quote_formatted['unit_price'] ) ||
                            ! empty( $quote_formatted['total_price'] ) ||
                            ! empty( $quote_formatted['lead_time'] ) ||
                            ! empty( $quote_formatted['cbm_volume'] )
                        );
                        ?>

                        <?php if ( $has_pricing ) : ?>
                            <div class="n88-pricing-summary-container" style="margin-top: 20px; padding: 15px; background: #fff; border-radius: 6px; border: 1px solid #e0e0e0;">
                                <h4 style="margin-top: 0; color: #007cba;">Pricing Summary</h4>
                                <div class="n88-pricing-summary-content" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                                    <?php if ( ! empty( $quote_formatted['unit_price'] ) ) : ?>
                                        <div style="padding: 10px; background: #f0f7ff; border-radius: 4px;">
                                            <div style="font-size: 12px; color: #666;">Unit Price</div>
                                            <div class="n88-unit-price" style="font-size: 20px; font-weight: bold; color: #007cba;">$<?php echo number_format( $quote_formatted['unit_price'], 2 ); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $quote_formatted['total_price'] ) ) : ?>
                                        <div style="padding: 10px; background: #f0f7ff; border-radius: 4px;">
                                            <div style="font-size: 12px; color: #666;">Total Price</div>
                                            <div class="n88-total-price" style="font-size: 20px; font-weight: bold; color: #4caf50;">$<?php echo number_format( $quote_formatted['total_price'], 2 ); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $quote_formatted['lead_time'] ) ) : ?>
                                        <div style="padding: 10px; background: #fff8e1; border-radius: 4px;">
                                            <div style="font-size: 12px; color: #666;">Lead Time</div>
                                            <div class="n88-lead-time" style="font-size: 16px; font-weight: bold; color: #ff9800;"><?php echo esc_html( $quote_formatted['lead_time'] ); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $quote_formatted['cbm_volume'] ) ) : ?>
                                        <div style="padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                            <div style="font-size: 12px; color: #666;">Total Volume</div>
                                            <div class="n88-cbm-volume" style="font-size: 16px; font-weight: bold; color: #333;"><?php echo number_format( $quote_formatted['cbm_volume'], 4 ); ?> m³</div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ( ! empty( $quote_formatted['volume_rules_applied'] ) ) : ?>
                                    <div class="n88-volume-rules" style="margin-top: 15px; padding: 12px; background: #fff3cd; border-radius: 4px; border-left: 4px solid #ffc107;">
                                        <strong style="color: #856404;">Volume Rules Applied:</strong>
                                        <ul style="margin: 8px 0 0 20px; color: #856404;">
                                            <?php foreach ( $quote_formatted['volume_rules_applied'] as $rule ) : ?>
                                                <li><?php echo esc_html( $rule ); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $quote_formatted['labor_cost'] || $quote_formatted['materials_cost'] || $quote_formatted['overhead_cost'] ) : ?>
                                    <div style="margin-top: 15px; padding: 12px; background: #f9f9f9; border-radius: 4px;">
                                        <strong>Cost Breakdown:</strong>
                                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-top: 10px; font-size: 14px;">
                                            <?php if ( $quote_formatted['labor_cost'] ) : ?>
                                                <div>Labor: $<?php echo number_format( $quote_formatted['labor_cost'], 2 ); ?></div>
                                            <?php endif; ?>
                                            <?php if ( $quote_formatted['materials_cost'] ) : ?>
                                                <div>Materials: $<?php echo number_format( $quote_formatted['materials_cost'], 2 ); ?></div>
                                            <?php endif; ?>
                                            <?php if ( $quote_formatted['overhead_cost'] ) : ?>
                                                <div>Overhead: $<?php echo number_format( $quote_formatted['overhead_cost'], 2 ); ?></div>
                                            <?php endif; ?>
                                            <?php if ( $quote_formatted['margin_percentage'] ) : ?>
                                                <div>Margin: <?php echo number_format( $quote_formatted['margin_percentage'], 2 ); ?>%</div>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $quote_formatted['shipping_zone'] ) ) : ?>
                                                <div>Shipping Zone: <?php echo esc_html( ucfirst( $quote_formatted['shipping_zone'] ) ); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Editable Quote Form - All fields shown with saved values -->
                        <div style="background: #fff; padding: 20px; border-radius: 5px; margin: 20px 0; border: 1px solid #ddd;">
                            <?php if ( $latest_quote->quote_status === 'sent' ) : ?>
                                <p class="description" style="margin-bottom: 20px; padding: 10px; background: #e8f5e9; border-left: 4px solid #4caf50; color: #2e7d32;">
                                    <strong>✓ Quote was sent on <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $latest_quote->sent_at ) ) ); ?>.</strong> 
                                    You can update any fields below and resend the updated quote to the client.
                                </p>
                            <?php endif; ?>
                            <?php
                            if ( isset( $_POST['n88_update_quote'] ) && check_admin_referer( 'n88_update_quote' ) ) {
                                $update_data = array();
                                
                                // Handle file upload if provided
                                if ( ! empty( $_FILES['quote_file']['tmp_name'] ) ) {
                                    $uploaded_file_path = N88_RFQ_Quotes::handle_quote_file_upload( $_FILES['quote_file'], $project_id, $latest_quote->id );
                                    if ( $uploaded_file_path ) {
                                        $update_data['quote_file_path'] = $uploaded_file_path;
                                    }
                                }
                                
                                if ( isset( $_POST['admin_notes'] ) ) {
                                    $update_data['admin_notes'] = sanitize_textarea_field( $_POST['admin_notes'] );
                                }
                                
                                // Always include client_message if the form is being submitted (even if empty)
                                // Textareas are always in POST, even if empty, so we can safely check and save
                                $update_data['client_message'] = isset( $_POST['client_message'] ) ? sanitize_textarea_field( $_POST['client_message'] ) : '';
                                
                                // Handle project items update (when quote is sent)
                                if ( isset( $_POST['project_items'] ) && is_array( $_POST['project_items'] ) && in_array( $latest_quote->quote_status, array( 'sent', 'quote_updated', 'quoted' ), true ) ) {
                                    $updated_items = array();
                                    $items_json = $projects_class->get_project_metadata( $project_id, 'n88_repeater_raw' );
                                    $current_items = ! empty( $items_json ) ? json_decode( $items_json, true ) : array();
                                    
                                    foreach ( $_POST['project_items'] as $index => $item_data ) {
                                        if ( isset( $current_items[ $index ] ) ) {
                                            $item = $current_items[ $index ];
                                            
                                            // Update dimensions
                                            if ( isset( $item_data['dimensions_length'] ) ) {
                                                $item['length_in'] = sanitize_text_field( $item_data['dimensions_length'] );
                                                if ( isset( $item['dimensions'] ) ) {
                                                    $item['dimensions']['length'] = $item['length_in'];
                                                }
                                            }
                                            if ( isset( $item_data['dimensions_depth'] ) ) {
                                                $item['depth_in'] = sanitize_text_field( $item_data['dimensions_depth'] );
                                                if ( isset( $item['dimensions'] ) ) {
                                                    $item['dimensions']['depth'] = $item['depth_in'];
                                                }
                                            }
                                            if ( isset( $item_data['dimensions_height'] ) ) {
                                                $item['height_in'] = sanitize_text_field( $item_data['dimensions_height'] );
                                                if ( isset( $item['dimensions'] ) ) {
                                                    $item['dimensions']['height'] = $item['height_in'];
                                                }
                                            }
                                            
                                            // Update other fields
                                            if ( isset( $item_data['quantity'] ) ) {
                                                $item['quantity'] = intval( $item_data['quantity'] );
                                            }
                                            if ( isset( $item_data['primary_material'] ) ) {
                                                $item['primary_material'] = sanitize_text_field( $item_data['primary_material'] );
                                                if ( isset( $item['materials'] ) ) {
                                                    $item['materials']['primary'] = $item['primary_material'];
                                                }
                                            }
                                            if ( isset( $item_data['finishes'] ) ) {
                                                $item['finishes'] = sanitize_text_field( $item_data['finishes'] );
                                            }
                                            if ( isset( $item_data['construction_notes'] ) ) {
                                                $item['construction_notes'] = sanitize_textarea_field( $item_data['construction_notes'] );
                                            }
                                            
                                            $updated_items[] = $item;
                                        }
                                    }
                                    
                                    // Save updated items
                                    if ( ! empty( $updated_items ) ) {
                                        $projects_class->save_project_metadata( $project_id, array(
                                            'n88_repeater_raw' => wp_json_encode( $updated_items ),
                                        ) );
                                    }
                                }
                                
                                // Handle quote items update
                                if ( isset( $_POST['quote_items'] ) && is_array( $_POST['quote_items'] ) ) {
                                    $quote_items = array();
                                    foreach ( $_POST['quote_items'] as $item_data ) {
                                        $unit_price = isset( $item_data['unit_price'] ) ? (float) $item_data['unit_price'] : 0;
                                        $quantity = isset( $item_data['quantity'] ) ? (int) $item_data['quantity'] : 0;
                                        $total_price = isset( $item_data['total_price'] ) ? (float) $item_data['total_price'] : ( $unit_price * $quantity );
                                        
                                        $quote_items[] = array(
                                            'item_id' => isset( $item_data['item_id'] ) ? sanitize_text_field( $item_data['item_id'] ) : '',
                                            'unit_price' => $unit_price,
                                            'quantity' => $quantity,
                                            'total_price' => $total_price,
                                            'dimensions' => isset( $item_data['dimensions'] ) ? sanitize_text_field( $item_data['dimensions'] ) : '',
                                            'material' => isset( $item_data['material'] ) ? sanitize_text_field( $item_data['material'] ) : '',
                                            'notes' => isset( $item_data['notes'] ) ? sanitize_textarea_field( $item_data['notes'] ) : '',
                                        );
                                    }
                                    $update_data['quote_items'] = wp_json_encode( $quote_items );
                                }
                                
                                if ( isset( $_POST['labor_cost'] ) || isset( $_POST['materials_cost'] ) || isset( $_POST['overhead_cost'] ) ) {
                                    $update_data['labor_cost'] = isset( $_POST['labor_cost'] ) ? (float) $_POST['labor_cost'] : $latest_quote->labor_cost ?? 0;
                                    $update_data['materials_cost'] = isset( $_POST['materials_cost'] ) ? (float) $_POST['materials_cost'] : $latest_quote->materials_cost ?? 0;
                                    $update_data['overhead_cost'] = isset( $_POST['overhead_cost'] ) ? (float) $_POST['overhead_cost'] : $latest_quote->overhead_cost ?? 0;
                                    $update_data['margin_percentage'] = isset( $_POST['margin_percentage'] ) ? (float) $_POST['margin_percentage'] : $latest_quote->margin_percentage ?? 0;
                                    $update_data['shipping_zone'] = isset( $_POST['shipping_zone'] ) ? sanitize_text_field( $_POST['shipping_zone'] ) : ( $latest_quote->shipping_zone ?? '' );
                                    
                                    // Recalculate pricing
                                    if ( class_exists( 'N88_RFQ_Pricing' ) ) {
                                        $pricing_result = N88_RFQ_Pricing::calculate_project_pricing(
                                            $project_id,
                                            $update_data['labor_cost'],
                                            $update_data['materials_cost'],
                                            $update_data['overhead_cost'],
                                            $update_data['margin_percentage'],
                                            $update_data['shipping_zone']
                                        );
                                        
                                        if ( $pricing_result ) {
                                            $update_data['unit_price'] = $pricing_result['final_unit_price'];
                                            $update_data['total_price'] = $pricing_result['total_price'];
                                            $update_data['lead_time'] = $pricing_result['lead_time'];
                                            $update_data['cbm_volume'] = $pricing_result['total_cbm'];
                                            $update_data['volume_rules_applied'] = wp_json_encode( $pricing_result['volume_rules_applied'] );
                                        }
                                    }
                                }
                                
                                // Check if we should also send the quote
                                $should_send = isset( $_POST['n88_update_and_send'] ) && $_POST['n88_update_and_send'] === '1';
                                
                                // If quote was already sent and we're updating it, change status to quote_updated
                                // This should happen regardless of what fields are being updated
                                // Check for 'sent', 'quote_updated', or 'quoted' status
                                if ( in_array( $latest_quote->quote_status, array( 'sent', 'quote_updated', 'quoted' ), true ) ) {
                                    // Always set status to quote_updated if updating an already-sent quote
                                    $update_data['quote_status'] = 'quote_updated';
                                }
                                
                                if ( ! empty( $update_data ) ) {
                                    if ( N88_RFQ_Quotes::update_quote( $latest_quote->id, $update_data ) ) {
                                        // Refresh quote data after update
                                        $latest_quote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}project_quotes WHERE id = %d", $latest_quote->id ) );
                                        $quote_formatted = N88_RFQ_Quotes::format_quote( $latest_quote );
                                        
                                        // Track admin updates
                                        $projects_class->save_project_metadata( $project_id, array(
                                            'n88_has_admin_updates' => '1',
                                            'n88_last_admin_update' => current_time( 'mysql' ),
                                            'n88_last_admin_update_by' => (string) get_current_user_id(),
                                        ) );

                                        // If send was requested, send the quote but preserve quote_updated status
                                        if ( $should_send ) {
                                            // Update status to quote_updated before sending if it was already sent
                                            if ( in_array( $latest_quote->quote_status, array( 'sent', 'quote_updated', 'quoted' ), true ) ) {
                                                $wpdb->update(
                                                    $wpdb->prefix . 'project_quotes',
                                                    array( 'quote_status' => 'quote_updated' ),
                                                    array( 'id' => $latest_quote->id ),
                                                    array( '%s' ),
                                                    array( '%d' )
                                                );
                                            }
                                if ( N88_RFQ_Quotes::send_quote( $latest_quote->id, get_current_user_id() ) ) {
                                                echo '<div class="notice notice-success"><p>Quote updated and sent to client successfully! Client has been notified.</p></div>';
                                                // Refresh quote data after sending
                                                $latest_quote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}project_quotes WHERE id = %d", $latest_quote->id ) );
                                                $quote_formatted = N88_RFQ_Quotes::format_quote( $latest_quote );
                                            } else {
                                                echo '<div class="notice notice-warning"><p>Quote updated successfully, but failed to send. Please try sending again.</p></div>';
                                            }
                                        } else {
                                            // Just notify client of update
                                            if ( class_exists( 'N88_RFQ_Notifications' ) ) {
                                                $project = $projects_class->get_project_admin( $project_id );
                                                if ( $project && ! empty( $project['user_id'] ) ) {
                                                    N88_RFQ_Notifications::create_notification(
                                                        $project_id,
                                                        $project['user_id'],
                                                        'quote_updated_by_admin',
                                                        'Admin updated your quote. Please review the changes.',
                                                        $latest_quote->id
                                                    );
                                                }
                                            }
                                            echo '<div class="notice notice-success"><p>Quote updated successfully! Status changed to "Quote Updated". Client has been notified.</p></div>';
                                        }
                                    } else {
                                        echo '<div class="notice notice-error"><p>Failed to update quote. Please try again.</p></div>';
                                    }
                                } elseif ( $should_send ) {
                                    // If no updates but send was requested, just send
                                    if ( N88_RFQ_Quotes::send_quote( $latest_quote->id, get_current_user_id() ) ) {
                                        echo '<div class="notice notice-success"><p>Quote sent to client successfully!</p></div>';
                                        // Refresh quote data
                                        $latest_quote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}project_quotes WHERE id = %d", $latest_quote->id ) );
                                        $quote_formatted = N88_RFQ_Quotes::format_quote( $latest_quote );
                                    }
                                }
                            }
                            ?>
                            <form method="post" enctype="multipart/form-data" id="n88-edit-quote-form">
                                <?php wp_nonce_field( 'n88_update_quote' ); ?>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label for="edit_admin_notes">Internal Notes</label></th>
                                        <td>
                                            <textarea name="admin_notes" id="edit_admin_notes" rows="4" cols="60"><?php echo esc_textarea( $latest_quote->admin_notes ?? '' ); ?></textarea>
                                            <p class="description">Internal notes visible only to admins.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="edit_client_message">Important Message to Client</label></th>
                                        <td>
                                            <textarea name="client_message" id="edit_client_message" rows="4" cols="60" placeholder="Add an important note or message for the client..."><?php echo esc_textarea( $latest_quote->client_message ?? '' ); ?></textarea>
                                            <p class="description">This message will be visible to the client on the quote.</p>
                                        </td>
                                    </tr>
                                    <?php if ( N88_RFQ_Quotes::quote_table_supports_pricing() ) : ?>
                                        <tr>
                                            <th scope="row"><label>Pricing</label></th>
                                            <td>
                                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                                                    <div>
                                                        <label for="edit_labor_cost">Labor Cost</label>
                                                        <input type="number" name="labor_cost" id="edit_labor_cost" step="0.01" value="<?php echo esc_attr( $latest_quote->labor_cost ?? 0 ); ?>" style="width: 100%;">
                                                    </div>
                                                    <div>
                                                        <label for="edit_materials_cost">Materials Cost</label>
                                                        <input type="number" name="materials_cost" id="edit_materials_cost" step="0.01" value="<?php echo esc_attr( $latest_quote->materials_cost ?? 0 ); ?>" style="width: 100%;">
                                                    </div>
                                                    <div>
                                                        <label for="edit_overhead_cost">Overhead Cost</label>
                                                        <input type="number" name="overhead_cost" id="edit_overhead_cost" step="0.01" value="<?php echo esc_attr( $latest_quote->overhead_cost ?? 0 ); ?>" style="width: 100%;">
                                                    </div>
                                                    <div>
                                                        <label for="edit_margin_percentage">Margin %</label>
                                                        <input type="number" name="margin_percentage" id="edit_margin_percentage" step="0.01" value="<?php echo esc_attr( $latest_quote->margin_percentage ?? 0 ); ?>" style="width: 100%;">
                                                    </div>
                                                    <div>
                                                        <label for="edit_shipping_zone">Shipping Zone</label>
                                                        <select name="shipping_zone" id="edit_shipping_zone" style="width: 100%;">
                                                            <option value="domestic" <?php selected( $latest_quote->shipping_zone ?? '', 'domestic' ); ?>>Domestic</option>
                                                            <option value="international" <?php selected( $latest_quote->shipping_zone ?? '', 'international' ); ?>>International</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                        <?php endif; ?>
                                    <tr>
                                        <th scope="row"><label for="edit_quote_file">Update Quote File (Optional)</label></th>
                                        <td>
                                            <?php if ( ! empty( $latest_quote->quote_file_path ) ) : ?>
                                                <p style="margin: 0 0 10px 0;">
                                                    <strong>Current file:</strong> 
                                                    <a href="<?php echo esc_url( wp_get_upload_dir()['baseurl'] . '/' . $latest_quote->quote_file_path ); ?>" target="_blank" style="color: #007cba;">
                                                        <?php echo esc_html( basename( $latest_quote->quote_file_path ) ); ?>
                                                    </a>
                                                </p>
                                            <?php endif; ?>
                                            <input type="file" name="quote_file" id="edit_quote_file" accept=".pdf,.jpg,.jpeg,.png,.dwg">
                                            <p class="description">Upload a new quote file (PDF, JPG, PNG, or DWG). Leave empty to keep the current file.</p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Quote Items Table (Editable) -->
                                <?php
                                // Get quote items if they exist, otherwise use project items
                                $quote_items_json = $latest_quote->quote_items ?? '';
                                $quote_items = ! empty( $quote_items_json ) ? json_decode( $quote_items_json, true ) : array();
                                
                                // If no quote items, initialize from project items
                                if ( empty( $quote_items ) && ! empty( $items ) ) {
                                    foreach ( $items as $index => $item ) {
                                        $length = isset( $item['length_in'] ) ? $item['length_in'] : ( $item['dimensions']['length'] ?? '' );
                                        $depth  = isset( $item['depth_in'] ) ? $item['depth_in'] : ( $item['dimensions']['depth'] ?? '' );
                                        $height = isset( $item['height_in'] ) ? $item['height_in'] : ( $item['dimensions']['height'] ?? '' );
                                        $quote_items[] = array(
                                            'item_id' => 'item_' . $index,
                                            'unit_price' => 0,
                                            'quantity' => $item['quantity'] ?? 0,
                                            'total_price' => 0,
                                            'dimensions' => trim( sprintf( '%s × %s × %s', $length, $depth, $height ) ),
                                            'material' => $item['primary_material'] ?? $item['materials']['primary'] ?? '',
                                            'notes' => $item['notes'] ?? '',
                                        );
                                    }
                                }
                                ?>
                                
                                <?php if ( ! empty( $quote_items ) || ! empty( $items ) ) : ?>
                                    <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd;">
                                        <h4 style="margin-top: 0;">Quote Items Table (Editable)</h4>
                                        <p class="description">Edit pricing, dimensions, and notes for each item. Changes will be saved to the quote.</p>
                                        <div style="overflow-x: auto; margin-top: 15px;">
                                            <table class="widefat fixed" id="n88-quote-items-table" style="min-width: 1000px;">
                                                <thead>
                                                    <tr style="background: #f5f5f5;">
                                                        <th style="width: 50px; padding: 10px;">#</th>
                                                        <th style="padding: 10px;">Item ID</th>
                                                        <th style="padding: 10px;">Dimensions</th>
                                                        <th style="padding: 10px;">Material</th>
                                                        <th style="padding: 10px;">Quantity</th>
                                                        <th style="padding: 10px;">Unit Price ($)</th>
                                                        <th style="padding: 10px;">Total Price ($)</th>
                                                        <th style="padding: 10px;">Notes</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $display_items = ! empty( $quote_items ) ? $quote_items : array();
                                                    if ( empty( $display_items ) && ! empty( $items ) ) {
                                                        foreach ( $items as $index => $item ) {
                                                            $length = isset( $item['length_in'] ) ? $item['length_in'] : ( $item['dimensions']['length'] ?? '' );
                                                            $depth  = isset( $item['depth_in'] ) ? $item['depth_in'] : ( $item['dimensions']['depth'] ?? '' );
                                                            $height = isset( $item['height_in'] ) ? $item['height_in'] : ( $item['dimensions']['height'] ?? '' );
                                                            $display_items[] = array(
                                                                'item_id' => 'item_' . $index,
                                                                'unit_price' => 0,
                                                                'quantity' => $item['quantity'] ?? 0,
                                                                'total_price' => 0,
                                                                'dimensions' => trim( sprintf( '%s × %s × %s', $length, $depth, $height ) ),
                                                                'material' => $item['primary_material'] ?? $item['materials']['primary'] ?? '',
                                                                'notes' => $item['notes'] ?? '',
                                                            );
                                                        }
                                                    }
                                                    foreach ( $display_items as $index => $quote_item ) : 
                                                    ?>
                                                        <tr class="n88-quote-item-row">
                                                            <td style="padding: 10px; text-align: center; font-weight: bold;"><?php echo esc_html( $index + 1 ); ?></td>
                                                            <td style="padding: 10px;">
                                                                <input type="hidden" name="quote_items[<?php echo esc_attr( $index ); ?>][item_id]" value="<?php echo esc_attr( $quote_item['item_id'] ?? 'item_' . $index ); ?>">
                                                                <span style="font-weight: 600;"><?php echo esc_html( $quote_item['item_id'] ?? 'item_' . $index ); ?></span>
                                                            </td>
                                                            <td style="padding: 10px;">
                                                                <input type="text" name="quote_items[<?php echo esc_attr( $index ); ?>][dimensions]" value="<?php echo esc_attr( $quote_item['dimensions'] ?? '' ); ?>" class="regular-text" placeholder="L × D × H" style="width: 100%;">
                                                            </td>
                                                            <td style="padding: 10px;">
                                                                <input type="text" name="quote_items[<?php echo esc_attr( $index ); ?>][material]" value="<?php echo esc_attr( $quote_item['material'] ?? '' ); ?>" class="regular-text" style="width: 100%;">
                                                            </td>
                                                            <td style="padding: 10px;">
                                                                <input type="number" name="quote_items[<?php echo esc_attr( $index ); ?>][quantity]" value="<?php echo esc_attr( $quote_item['quantity'] ?? 0 ); ?>" class="n88-quote-qty" min="0" step="1" style="width: 80px;">
                                                            </td>
                                                            <td style="padding: 10px;">
                                                                <input type="number" name="quote_items[<?php echo esc_attr( $index ); ?>][unit_price]" value="<?php echo esc_attr( $quote_item['unit_price'] ?? 0 ); ?>" class="n88-quote-unit-price" min="0" step="0.01" style="width: 100px;">
                                                            </td>
                                                            <td style="padding: 10px;">
                                                                <input type="number" name="quote_items[<?php echo esc_attr( $index ); ?>][total_price]" value="<?php echo esc_attr( $quote_item['total_price'] ?? 0 ); ?>" class="n88-quote-total-price" min="0" step="0.01" readonly style="width: 100px; background: #f5f5f5;">
                                                            </td>
                                                            <td style="padding: 10px;">
                                                                <textarea name="quote_items[<?php echo esc_attr( $index ); ?>][notes]" rows="2" class="regular-text" style="width: 100%; resize: vertical;"><?php echo esc_textarea( $quote_item['notes'] ?? '' ); ?></textarea>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <p class="description" style="margin-top: 10px;">💡 <strong>Tip:</strong> Unit Price × Quantity = Total Price (auto-calculated)</p>
                    </div>
                <?php endif; ?>

                                <!-- Project Items Table (Editable) - Inside form when quote is sent -->
                                <?php
                                // Get project items for editing
                                $items_json = $projects_class->get_project_metadata( $project_id, 'n88_repeater_raw' );
                                $items = ! empty( $items_json ) ? json_decode( $items_json, true ) : array();
                                ?>
                                
                                <?php if ( ! empty( $items ) && ( $latest_quote->quote_status === 'sent' || $latest_quote->quote_status === 'quote_updated' ) ) : ?>
                                    <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd;">
                                        <h4 style="margin-top: 0;">Project Items (<?php echo count( $items ); ?>) - Editable</h4>
                                        <p class="description">Edit any field in the project items. Changes will be saved when you update the quote.</p>
                                        <div style="overflow-x: auto; margin-top: 15px;">
                                            <table class="widefat fixed" style="min-width: 800px; background: white;">
                                                <thead>
                                                    <tr style="background: #f5f5f5;">
                                                        <th style="width: 50px; padding: 10px;">#</th>
                                                        <th style="padding: 10px;">Dimensions (L × D × H)</th>
                                                        <th style="padding: 10px;">Qty</th>
                                                        <th style="padding: 10px;">Primary Material</th>
                                                        <th style="padding: 10px;">Finishes</th>
                                                        <th style="padding: 10px;">Construction Notes</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ( $items as $index => $item ) : 
                                                        $length = isset( $item['length_in'] ) ? $item['length_in'] : ( $item['dimensions']['length'] ?? '' );
                                                        $depth  = isset( $item['depth_in'] ) ? $item['depth_in'] : ( $item['dimensions']['depth'] ?? '' );
                                                        $height = isset( $item['height_in'] ) ? $item['height_in'] : ( $item['dimensions']['height'] ?? '' );
                                                    ?>
                                                        <tr>
                                                            <td style="padding: 10px; text-align: center; font-weight: bold;"><?php echo esc_html( $index + 1 ); ?></td>
                                                            <td style="padding: 10px;">
                                                                <input type="text" name="project_items[<?php echo esc_attr( $index ); ?>][dimensions_length]" value="<?php echo esc_attr( $length ); ?>" placeholder="Length" style="width: 80px; margin-right: 5px;">
                                                                <span>×</span>
                                                                <input type="text" name="project_items[<?php echo esc_attr( $index ); ?>][dimensions_depth]" value="<?php echo esc_attr( $depth ); ?>" placeholder="Depth" style="width: 80px; margin: 0 5px;">
                                                                <span>×</span>
                                                                <input type="text" name="project_items[<?php echo esc_attr( $index ); ?>][dimensions_height]" value="<?php echo esc_attr( $height ); ?>" placeholder="Height" style="width: 80px; margin-left: 5px;">
                                                            </td>
                                                            <td style="padding: 10px;">
                                                                <input type="number" name="project_items[<?php echo esc_attr( $index ); ?>][quantity]" value="<?php echo esc_attr( $item['quantity'] ?? 0 ); ?>" min="0" step="1" style="width: 80px;">
                                                            </td>
                                                            <td style="padding: 10px;">
                                                                <input type="text" name="project_items[<?php echo esc_attr( $index ); ?>][primary_material]" value="<?php echo esc_attr( $item['primary_material'] ?? $item['materials']['primary'] ?? '' ); ?>" class="regular-text" style="width: 100%;">
                                                            </td>
                                                            <td style="padding: 10px;">
                                                                <input type="text" name="project_items[<?php echo esc_attr( $index ); ?>][finishes]" value="<?php echo esc_attr( $item['finishes'] ?? '' ); ?>" class="regular-text" style="width: 100%;">
                                                            </td>
                                                            <td style="padding: 10px;">
                                                                <textarea name="project_items[<?php echo esc_attr( $index ); ?>][construction_notes]" rows="2" class="regular-text" style="width: 100%; resize: vertical;"><?php echo esc_textarea( $item['construction_notes'] ?? '' ); ?></textarea>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                                    <button type="submit" name="n88_update_quote" value="1" class="button button-primary" style="padding: 10px 20px; font-size: 14px;">
                                        💾 Update Quote
                                    </button>
                                    <button type="submit" name="n88_update_and_send" value="1" class="button button-success" style="padding: 10px 20px; font-size: 14px; background: #4caf50; border-color: #45a049;">
                                        📤 Update & Send to Client
                                    </button>
                                    <p class="description" style="margin: 0; color: #666;">
                                        Click "Update Quote" to save changes, or "Update & Send to Client" to save and send immediately with notifications.
                                    </p>
                                  
                                    <?php if ( N88_RFQ_Quotes::quote_table_supports_pricing() ) : ?>
                                        <button type="button" id="n88-recalculate-pricing" class="button" style="background: #ff9800; border-color: #f57c00; color: white; padding: 10px 20px; font-size: 14px;">
                                            🧮 Recalculate Pricing
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                            
                            <!-- Handle Update & Send -->
                            <?php
                            if ( isset( $_POST['n88_update_and_send_quote'] ) && check_admin_referer( 'n88_update_quote' ) ) {
                                // First update the quote (same logic as n88_update_quote)
                                $update_data = array();
                                
                                // Handle file upload if provided
                                if ( ! empty( $_FILES['quote_file']['tmp_name'] ) ) {
                                    $uploaded_file_path = N88_RFQ_Quotes::handle_quote_file_upload( $_FILES['quote_file'], $project_id, $latest_quote->id );
                                    if ( $uploaded_file_path ) {
                                        $update_data['quote_file_path'] = $uploaded_file_path;
                                    }
                                }
                                
                                if ( isset( $_POST['admin_notes'] ) ) {
                                    $update_data['admin_notes'] = sanitize_textarea_field( $_POST['admin_notes'] );
                                }
                                
                                // Always include client_message if the form is being submitted (even if empty)
                                // Textareas are always in POST, even if empty, so we can safely check and save
                                $update_data['client_message'] = isset( $_POST['client_message'] ) ? sanitize_textarea_field( $_POST['client_message'] ) : '';
                                
                                // Handle quote items update
                                if ( isset( $_POST['quote_items'] ) && is_array( $_POST['quote_items'] ) ) {
                                    $quote_items = array();
                                    foreach ( $_POST['quote_items'] as $item_data ) {
                                        $unit_price = isset( $item_data['unit_price'] ) ? (float) $item_data['unit_price'] : 0;
                                        $quantity = isset( $item_data['quantity'] ) ? (int) $item_data['quantity'] : 0;
                                        $total_price = isset( $item_data['total_price'] ) ? (float) $item_data['total_price'] : ( $unit_price * $quantity );
                                        
                                        $quote_items[] = array(
                                            'item_id' => isset( $item_data['item_id'] ) ? sanitize_text_field( $item_data['item_id'] ) : '',
                                            'unit_price' => $unit_price,
                                            'quantity' => $quantity,
                                            'total_price' => $total_price,
                                            'dimensions' => isset( $item_data['dimensions'] ) ? sanitize_text_field( $item_data['dimensions'] ) : '',
                                            'material' => isset( $item_data['material'] ) ? sanitize_text_field( $item_data['material'] ) : '',
                                            'notes' => isset( $item_data['notes'] ) ? sanitize_textarea_field( $item_data['notes'] ) : '',
                                        );
                                    }
                                    $update_data['quote_items'] = wp_json_encode( $quote_items );
                                }
                                
                                if ( isset( $_POST['labor_cost'] ) || isset( $_POST['materials_cost'] ) || isset( $_POST['overhead_cost'] ) ) {
                                    $update_data['labor_cost'] = isset( $_POST['labor_cost'] ) ? (float) $_POST['labor_cost'] : $latest_quote->labor_cost ?? 0;
                                    $update_data['materials_cost'] = isset( $_POST['materials_cost'] ) ? (float) $_POST['materials_cost'] : $latest_quote->materials_cost ?? 0;
                                    $update_data['overhead_cost'] = isset( $_POST['overhead_cost'] ) ? (float) $_POST['overhead_cost'] : $latest_quote->overhead_cost ?? 0;
                                    $update_data['margin_percentage'] = isset( $_POST['margin_percentage'] ) ? (float) $_POST['margin_percentage'] : $latest_quote->margin_percentage ?? 0;
                                    $update_data['shipping_zone'] = isset( $_POST['shipping_zone'] ) ? sanitize_text_field( $_POST['shipping_zone'] ) : ( $latest_quote->shipping_zone ?? '' );
                                    
                                    // Recalculate pricing
                                    if ( class_exists( 'N88_RFQ_Pricing' ) ) {
                                        $pricing_result = N88_RFQ_Pricing::calculate_project_pricing(
                                            $project_id,
                                            $update_data['labor_cost'],
                                            $update_data['materials_cost'],
                                            $update_data['overhead_cost'],
                                            $update_data['margin_percentage'],
                                            $update_data['shipping_zone']
                                        );
                                        
                                        if ( $pricing_result ) {
                                            $update_data['unit_price'] = $pricing_result['final_unit_price'];
                                            $update_data['total_price'] = $pricing_result['total_price'];
                                            $update_data['lead_time'] = $pricing_result['lead_time'];
                                            $update_data['cbm_volume'] = $pricing_result['total_cbm'];
                                            $update_data['volume_rules_applied'] = wp_json_encode( $pricing_result['volume_rules_applied'] );
                                        }
                                    }
                                }
                                
                                // If quote was already sent and we're updating it, change status to quote_updated
                                // This should happen regardless of what fields are being updated
                                // Check for 'sent', 'quote_updated', or 'quoted' status
                                if ( in_array( $latest_quote->quote_status, array( 'sent', 'quote_updated', 'quoted' ), true ) ) {
                                    // Always set status to quote_updated if updating an already-sent quote
                                    $update_data['quote_status'] = 'quote_updated';
                                }
                                
                                // Update the quote
                                if ( ! empty( $update_data ) ) {
                                    N88_RFQ_Quotes::update_quote( $latest_quote->id, $update_data );
                                }
                                
                                // Refresh quote data
                                $latest_quote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}project_quotes WHERE id = %d", $latest_quote->id ) );
                                
                                // Then send the quote - but preserve the quote_updated status if we just set it
                                $preserve_status = ! empty( $update_data['quote_status'] ) && $update_data['quote_status'] === 'quote_updated';
                                if ( N88_RFQ_Quotes::send_quote( $latest_quote->id, get_current_user_id(), $preserve_status ) ) {
                                    // Track admin updates
                                    $projects_class->save_project_metadata( $project_id, array(
                                        'n88_has_admin_updates' => '1',
                                        'n88_last_admin_update' => current_time( 'mysql' ),
                                        'n88_last_admin_update_by' => (string) get_current_user_id(),
                                    ) );

                                    // Notify client
                                    if ( class_exists( 'N88_RFQ_Notifications' ) ) {
                                        $project = $projects_class->get_project_admin( $project_id );
                                        if ( $project && ! empty( $project['user_id'] ) ) {
                                            N88_RFQ_Notifications::create_notification(
                                                $project_id,
                                                $project['user_id'],
                                                'quote_updated_by_admin',
                                                'Admin updated and resent your quote. Please review the changes.',
                                                $latest_quote->id
                                            );
                                        }
                                    }

                                    echo '<div class="notice notice-success"><p>Quote updated and sent successfully! Client has been notified.</p></div>';
                                    // Refresh quote data again
                                    $latest_quote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}project_quotes WHERE id = %d", $latest_quote->id ) );
                                    $quote_formatted = N88_RFQ_Quotes::format_quote( $latest_quote );
                                }
                            }
                            ?>
                            
                            <script type="text/javascript">
                            (function($) {
                                'use strict';
                                
                                // Auto-calculate total price (unit_price × quantity)
                                $(document).on('input', '.n88-quote-unit-price, .n88-quote-qty', function() {
                                    var $row = $(this).closest('tr');
                                    var unitPrice = parseFloat($row.find('.n88-quote-unit-price').val()) || 0;
                                    var quantity = parseInt($row.find('.n88-quote-qty').val()) || 0;
                                    var totalPrice = unitPrice * quantity;
                                    $row.find('.n88-quote-total-price').val(totalPrice.toFixed(2));
                                });
                                
                                // Initialize totals on page load
                                $(document).ready(function() {
                                    $('.n88-quote-item-row').each(function() {
                                        var $row = $(this);
                                        var unitPrice = parseFloat($row.find('.n88-quote-unit-price').val()) || 0;
                                        var quantity = parseInt($row.find('.n88-quote-qty').val()) || 0;
                                        var totalPrice = unitPrice * quantity;
                                        $row.find('.n88-quote-total-price').val(totalPrice.toFixed(2));
                                    });
                                });
                                
                                // Handle Recalculate Pricing button
                                $('#n88-recalculate-pricing').on('click', function(e) {
                                    e.preventDefault();
                                    var $button = $(this);
                                    var originalText = $button.html();
                                    
                                    // Disable button and show loading
                                    $button.prop('disabled', true).html('⏳ Calculating...');
                                    
                                    // Get pricing values from form
                                    var projectId = <?php echo intval( $project_id ); ?>;
                                    var laborCost = parseFloat($('#edit_labor_cost').val()) || 0;
                                    var materialsCost = parseFloat($('#edit_materials_cost').val()) || 0;
                                    var overheadCost = parseFloat($('#edit_overhead_cost').val()) || 0;
                                    var marginPercentage = parseFloat($('#edit_margin_percentage').val()) || 0;
                                    var shippingZone = $('#edit_shipping_zone').val() || '';
                                    
                                    // Make AJAX call to recalculate pricing
                                    $.ajax({
                                        url: typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                                        type: 'POST',
                                        data: {
                                            action: 'n88_calculate_pricing',
                                            nonce: '<?php echo esc_js( N88_RFQ_Helpers::create_ajax_nonce() ); ?>',
                                            project_id: projectId,
                                            labor_cost: laborCost,
                                            materials_cost: materialsCost,
                                            overhead_cost: overheadCost,
                                            margin_percentage: marginPercentage,
                                            shipping_zone: shippingZone
                                        },
                                        success: function(response) {
                                            if (response.success && response.data) {
                                                var pricing = response.data;
                                                
                                                // Update Pricing Summary section dynamically
                                                var $pricingSummary = $('.n88-pricing-summary-container');
                                                if ($pricingSummary.length > 0) {
                                                    // Update existing values
                                                    $pricingSummary.find('.n88-unit-price').text('$' + pricing.final_unit_price.toFixed(2));
                                                    $pricingSummary.find('.n88-total-price').text('$' + pricing.total_price.toFixed(2));
                                                    $pricingSummary.find('.n88-lead-time').text(pricing.lead_time || 'N/A');
                                                    $pricingSummary.find('.n88-cbm-volume').text(pricing.total_cbm.toFixed(4) + ' m³');
                                                    
                                                    // Update volume rules if they exist
                                                    var $volumeRules = $pricingSummary.find('.n88-volume-rules');
                                                    if (pricing.volume_rules_applied && pricing.volume_rules_applied.length > 0) {
                                                        if ($volumeRules.length === 0) {
                                                            $volumeRules = $('<div class="n88-volume-rules" style="margin-top: 15px; padding: 12px; background: #fff3cd; border-radius: 4px; border-left: 4px solid #ffc107;"></div>');
                                                            $pricingSummary.append($volumeRules);
                                                        }
                                                        var rulesHtml = '<strong style="color: #856404;">Volume Rules Applied:</strong><ul style="margin: 8px 0 0 20px; color: #856404;">';
                                                        pricing.volume_rules_applied.forEach(function(rule) {
                                                            rulesHtml += '<li>' + rule + '</li>';
                                                        });
                                                        rulesHtml += '</ul>';
                                                        $volumeRules.html(rulesHtml);
                                                    } else if ($volumeRules.length > 0) {
                                                        $volumeRules.remove();
                                                    }
                                                    
                                                    // Add visual feedback
                                                    $pricingSummary.css('border', '2px solid #4caf50');
                                                    setTimeout(function() {
                                                        $pricingSummary.css('border', '1px solid #e0e0e0');
                                                    }, 2000);
                                                }
                                                
                                                // Show success message
                                                var message = 'Pricing recalculated successfully!\n\n' +
                                                    'Unit Price: $' + pricing.final_unit_price.toFixed(2) + '\n' +
                                                    'Total Price: $' + pricing.total_price.toFixed(2) + '\n' +
                                                    'Total CBM: ' + pricing.total_cbm.toFixed(4) + ' m³\n' +
                                                    'Lead Time: ' + (pricing.lead_time || 'N/A') + '\n\n' +
                                                    'Click "Update Quote" to save these calculated values to the quote.';
                                                
                                                alert(message);
                                                
                                                // The pricing will be automatically recalculated and saved when the form is submitted
                                                // because the server-side code recalculates based on the form values
                                            } else {
                                                alert('Error: ' + (response.data || 'Failed to calculate pricing. Please check your inputs.'));
                                            }
                                        },
                                        error: function(xhr, status, error) {
                                            console.error('Pricing calculation error:', error);
                                            alert('Network error. Please check your connection and try again.');
                                        },
                                        complete: function() {
                                            // Re-enable button
                                            $button.prop('disabled', false).html(originalText);
                                        }
                                    });
                                });
                            })(jQuery);
                            </script>
                        </div>
                    </div>
                <?php endif; ?>


                <!-- Phase 2B: Instant Pricing Calculator - Hidden when quote is sent -->
                <?php if ( ! $latest_quote || ( $latest_quote->quote_status !== 'sent' && $latest_quote->quote_status !== 'quote_updated' ) ) : ?>
                <div style="background: white; padding: 20px; border-radius: 5px; margin: 20px 0; border: 2px solid #007cba;">
                    <h3 style="color: #007cba; margin-top: 0;">💡 Instant Pricing Calculator</h3>
                    <form method="post" id="n88-pricing-form">
                        <?php wp_nonce_field( 'n88_calculate_pricing' ); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="labor_cost">Labor Cost (per unit)</label></th>
                                <td>
                                    <input type="number" step="0.01" name="labor_cost" id="labor_cost" value="<?php echo isset( $calculated_pricing ) ? esc_attr( $calculated_pricing['labor_cost'] ) : ''; ?>" placeholder="0.00" style="width: 200px;">
                                    <span class="description">Cost of labor per item</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="materials_cost">Materials Cost (per unit)</label></th>
                                <td>
                                    <input type="number" step="0.01" name="materials_cost" id="materials_cost" value="<?php echo isset( $calculated_pricing ) ? esc_attr( $calculated_pricing['materials_cost'] ) : ''; ?>" placeholder="0.00" style="width: 200px;">
                                    <span class="description">Cost of materials per item</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="overhead_cost">Overhead Cost (per unit)</label></th>
                                <td>
                                    <input type="number" step="0.01" name="overhead_cost" id="overhead_cost" value="<?php echo isset( $calculated_pricing ) ? esc_attr( $calculated_pricing['overhead_cost'] ) : ''; ?>" placeholder="0.00" style="width: 200px;">
                                    <span class="description">Overhead expenses per item</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="margin_percentage">Margin %</label></th>
                                <td>
                                    <input type="number" step="0.01" name="margin_percentage" id="margin_percentage" value="<?php echo isset( $calculated_pricing ) ? esc_attr( $calculated_pricing['margin_percentage'] ) : '15'; ?>" placeholder="15.00" style="width: 200px;">
                                    <span class="description">Profit margin percentage (e.g., 15 for 15%)</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="shipping_zone">Shipping Zone</label></th>
                                <td>
                                    <select name="shipping_zone" id="shipping_zone" style="width: 200px;">
                                        <option value="">Select Zone</option>
                                        <option value="domestic" <?php echo ( isset( $calculated_pricing ) && $calculated_pricing['shipping_zone'] === 'domestic' ) ? 'selected' : ''; ?>>Domestic</option>
                                        <option value="international" <?php echo ( isset( $calculated_pricing ) && $calculated_pricing['shipping_zone'] === 'international' ) ? 'selected' : ''; ?>>International</option>
                                        <option value="express" <?php echo ( isset( $calculated_pricing ) && $calculated_pricing['shipping_zone'] === 'express' ) ? 'selected' : ''; ?>>Express</option>
                                        <option value="local" <?php echo ( isset( $calculated_pricing ) && $calculated_pricing['shipping_zone'] === 'local' ) ? 'selected' : ''; ?>>Local</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <button type="submit" name="n88_calculate_pricing" value="1" class="button button-primary">Calculate Pricing</button>
                    </form>

                    <?php if ( isset( $calculated_pricing ) ) : ?>
                        <div style="background: #f0f7ff; padding: 20px; border-radius: 5px; margin-top: 20px; border-left: 4px solid #007cba;">
                            <h4 style="margin-top: 0; color: #007cba;">📊 Calculated Pricing</h4>
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Base Unit Price:</strong></td>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd; text-align: right;">$<?php echo number_format( $calculated_pricing['base_unit_price'], 2 ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Final Unit Price:</strong></td>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd; text-align: right; font-size: 18px; font-weight: bold; color: #007cba;">$<?php echo number_format( $calculated_pricing['final_unit_price'], 2 ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Total Quantity:</strong></td>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd; text-align: right;"><?php echo number_format( $calculated_pricing['total_quantity'] ); ?> units</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Total CBM:</strong></td>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd; text-align: right;"><?php echo number_format( $calculated_pricing['total_cbm'], 4 ); ?> m³</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Total Price:</strong></td>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd; text-align: right; font-size: 20px; font-weight: bold; color: #4caf50;">$<?php echo number_format( $calculated_pricing['total_price'], 2 ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Lead Time:</strong></td>
                                    <td style="padding: 8px; border-bottom: 1px solid #ddd; text-align: right;"><?php echo esc_html( $calculated_pricing['lead_time'] ); ?></td>
                                </tr>
                                <?php if ( ! empty( $calculated_pricing['volume_rules_applied'] ) ) : ?>
                                    <tr>
                                        <td colspan="2" style="padding: 8px; border-top: 2px solid #007cba; padding-top: 15px;">
                                            <strong>Volume Rules Applied:</strong>
                                            <ul style="margin: 10px 0 0 20px;">
                                                <?php foreach ( $calculated_pricing['volume_rules_applied'] as $rule ) : ?>
                                                    <li><?php echo esc_html( $rule ); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Upload Quote & Send to Client - Hidden when quote is sent -->
                <?php if ( ! $latest_quote || ( $latest_quote->quote_status !== 'sent' && $latest_quote->quote_status !== 'quote_updated' ) ) : ?>
                <div style="background: white; padding: 20px; border-radius: 5px; margin: 20px 0; border: 1px solid #ddd;">
                    <h3>Upload Quote & Send to Client</h3>
                    <p class="description">Upload a quote PDF, add internal notes (admin only), and send a message to the client.</p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'n88_upload_quote' ); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="quote_file">Upload Quote PDF <span style="color: red;">*</span></label></th>
                                <td>
                                    <input type="file" name="quote_file" id="quote_file" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <p class="description">Required: PDF, JPG, or PNG file containing the quote.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="optional_attachments">Optional Attachments</label></th>
                                <td>
                                    <input type="file" name="optional_attachments[]" id="optional_attachments" multiple accept=".pdf,.jpg,.jpeg,.png,.dwg,.doc,.docx">
                                    <p class="description">Additional files: drawings, alternates, shipping details, etc.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admin_notes">Internal Notes (Admin Only)</label></th>
                                <td>
                                    <textarea name="admin_notes" id="admin_notes" rows="5" cols="60" placeholder="Private notes for internal use only. Not visible to client."></textarea>
                                    <p class="description">These notes are only visible to administrators and will not be shown to the client.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="client_message">Short Message to Client</label></th>
                                <td>
                                    <textarea name="client_message" id="client_message" rows="4" cols="60" placeholder="e.g., We included 2 fabric options in this quote."></textarea>
                                    <p class="description">This message will be visible to the client when they view the quote.</p>
                                </td>
                            </tr>
                            <?php if ( isset( $calculated_pricing ) ) : ?>
                                <!-- Include calculated pricing in form -->
                                <input type="hidden" name="labor_cost" value="<?php echo esc_attr( $calculated_pricing['labor_cost'] ); ?>">
                                <input type="hidden" name="materials_cost" value="<?php echo esc_attr( $calculated_pricing['materials_cost'] ); ?>">
                                <input type="hidden" name="overhead_cost" value="<?php echo esc_attr( $calculated_pricing['overhead_cost'] ); ?>">
                                <input type="hidden" name="margin_percentage" value="<?php echo esc_attr( $calculated_pricing['margin_percentage'] ); ?>">
                                <input type="hidden" name="shipping_zone" value="<?php echo esc_attr( $calculated_pricing['shipping_zone'] ); ?>">
                            <?php endif; ?>
                        </table>
                        <div style="margin-top: 20px; display: flex; gap: 10px;">
                            <button type="submit" name="n88_upload_quote" value="1" class="button button-secondary">Save as Draft</button>
                            <button type="submit" name="n88_send_quote" value="1" class="button button-primary">Send Quote to Client</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Project Items (Read-only display when quote not sent) -->
                <?php if ( ! empty( $items ) && ( ! $latest_quote || ( $latest_quote->quote_status !== 'sent' && $latest_quote->quote_status !== 'quote_updated' ) ) ) : ?>
                <div style="background: white; padding: 20px; border-radius: 5px; margin: 20px 0; border: 1px solid #ddd;">
                        <h3 style="margin-top: 0;">Project Items (<?php echo count( $items ); ?>)</h3>
                        <div style="overflow-x: auto;">
                            <table class="widefat fixed striped" style="min-width: 700px;">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">#</th>
                                        <th>Dimensions (L × D × H)</th>
                                        <th>Qty</th>
                                        <th>Primary Material</th>
                                        <th>Finishes</th>
                                        <th style="width: 30%;">Construction Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $items as $index => $item ) : ?>
                                        <tr>
                                            <td><?php echo esc_html( $index + 1 ); ?></td>
                                            <td>
                                                <?php
                                                $length = isset( $item['length_in'] ) ? $item['length_in'] : ( $item['dimensions']['length'] ?? '' );
                                                $depth  = isset( $item['depth_in'] ) ? $item['depth_in'] : ( $item['dimensions']['depth'] ?? '' );
                                                $height = isset( $item['height_in'] ) ? $item['height_in'] : ( $item['dimensions']['height'] ?? '' );
                                                echo esc_html( trim( sprintf( '%s × %s × %s', $length, $depth, $height ) ) );
                                                ?>
                                            </td>
                                            <td><?php echo esc_html( $item['quantity'] ?? 0 ); ?></td>
                                            <td><?php echo esc_html( $item['primary_material'] ?? $item['materials']['primary'] ?? '' ); ?></td>
                                            <td><?php echo esc_html( $item['finishes'] ?? '' ); ?></td>
                                            <td><?php echo nl2br( esc_html( $item['construction_notes'] ?? '' ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <p><a href="<?php echo remove_query_arg( array( 'action', 'project_id' ) ); ?>" class="button">Back to Quotes</a></p>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render audit trail page
     */
    public function render_audit_trail() {
        global $wpdb;

        $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
        $project_id = isset( $_GET['project_id'] ) ? intval( $_GET['project_id'] ) : 0;

        ?>
        <div class="wrap">
            <h1>Audit Trail</h1>

            <?php if ( $action === 'list' || ! $project_id ) : ?>
                <p>View all system activity and changes.</p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Field</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $audit_table = $wpdb->prefix . 'project_audit';

                        $audits = $wpdb->get_results(
                            "SELECT * FROM {$audit_table} ORDER BY created_at DESC LIMIT 100"
                        );

                        foreach ( $audits as $audit ) {
                            $formatted = N88_RFQ_Audit::format_audit( $audit );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $formatted['created_at'] ); ?></td>
                                <td><?php echo esc_html( $formatted['user_name'] ); ?></td>
                                <td><?php echo esc_html( N88_RFQ_Audit::get_action_label( $formatted['action'] ) ); ?></td>
                                <td><?php echo esc_html( $formatted['field_name'] ); ?></td>
                                <td><code><?php echo esc_html( substr( $formatted['old_value'], 0, 50 ) ); ?></code></td>
                                <td><code><?php echo esc_html( substr( $formatted['new_value'], 0, 50 ) ); ?></code></td>
                                <td><?php echo esc_html( $formatted['ip_address'] ); ?></td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>

            <?php endif; ?>
        </div>
        <?php
    }

    public function render_content_manager() {
        echo '<div class="wrap"><h1>Content Manager</h1><p>TODO: Implement video upload and library per design.</p></div>';
    }

    public function render_comments_hub() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to view this page.', 'n88-rfq' ) );
        }

        global $wpdb;

        $table_comments = $wpdb->prefix . 'project_comments';
        $table_projects = $wpdb->prefix . 'projects';
        $table_users    = $wpdb->users;

        $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'all';
        $project_id    = isset( $_GET['project_id'] ) ? intval( $_GET['project_id'] ) : 0;
        $search_term   = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

        $where = array( '1=1' );
        $values = array();

        if ( 'unread' === $status_filter ) {
            $where[] = 'n88_meta.needs_review = 1';
        }

        if ( $project_id > 0 ) {
            $where[] = 'c.project_id = %d';
            $values[] = $project_id;
        }

        if ( ! empty( $search_term ) ) {
            $where[] = '(c.comment_text LIKE %s OR p.project_name LIKE %s)';
            $values[] = '%' . $wpdb->esc_like( $search_term ) . '%';
            $values[] = '%' . $wpdb->esc_like( $search_term ) . '%';
        }

        if ( ! empty( $admin_ids ) ) {
            $placeholders_admin = implode( ',', array_fill( 0, count( $admin_ids ), '%d' ) );
            $where[] = "c.user_id NOT IN ( {$placeholders_admin} )";
            $values = array_merge( $values, $admin_ids );
        }

        $where_sql = implode( ' AND ', $where );

        $query = "
            SELECT c.*, p.project_name, u.display_name AS author_name
            FROM {$table_comments} c
            LEFT JOIN {$table_projects} p ON c.project_id = p.id
            LEFT JOIN {$table_users} u ON c.user_id = u.ID
            LEFT JOIN (
                SELECT project_id, meta_value AS needs_review
                FROM {$wpdb->prefix}project_metadata
                WHERE meta_key = 'n88_has_client_updates'
            ) n88_meta ON n88_meta.project_id = c.project_id
            WHERE {$where_sql}
            ORDER BY c.created_at DESC
            LIMIT 200
        ";

        $comments = $wpdb->get_results( $wpdb->prepare( $query, $values ) );

        if ( isset( $_POST['n88_admin_reply'], $_POST['original_comment_id'] ) && check_admin_referer( 'n88_admin_reply_action' ) ) {
            $parent_id = intval( $_POST['original_comment_id'] );
            $project_id = intval( $_POST['reply_project_id'] );
            $item_id = sanitize_text_field( $_POST['reply_item_id'] ?? '' );
            $video_id = sanitize_text_field( $_POST['reply_video_id'] ?? '' );
            $reply_text = sanitize_textarea_field( $_POST['reply_text'] ?? '' );

            if ( $project_id && ! empty( $reply_text ) ) {
                $comment_id = N88_RFQ_Comments::add_comment( array(
                    'project_id' => $project_id,
                    'item_id'    => $item_id ?: null,
                    'video_id'   => $video_id ?: null,
                    'user_id'    => get_current_user_id(),
                    'comment_text'=> $reply_text,
                    'is_urgent'  => ! empty( $_POST['reply_urgent'] ),
                    'parent_comment_id' => $parent_id,
                ) );

                if ( $comment_id ) {
                    echo '<div class="notice notice-success"><p>' . esc_html__( 'Reply posted successfully.', 'n88-rfq' ) . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to post reply. Please try again.', 'n88-rfq' ) . '</p></div>';
                }
            }
        }

        $projects = $wpdb->get_results( "SELECT id, project_name FROM {$table_projects} ORDER BY project_name ASC LIMIT 200" );
        ?>
        <div class="wrap n88-admin-comments">
            <h1><?php esc_html_e( 'Comments Hub', 'n88-rfq' ); ?></h1>

            <form method="get" class="n88-filters">
                <input type="hidden" name="page" value="n88-rfq-comments" />
                <label>
                    <span><?php esc_html_e( 'Project', 'n88-rfq' ); ?></span>
                    <select name="project_id">
                        <option value="0"><?php esc_html_e( 'All Projects', 'n88-rfq' ); ?></option>
                        <?php foreach ( $projects as $project ) : ?>
                            <option value="<?php echo esc_attr( $project->id ); ?>" <?php selected( $project_id, $project->id ); ?>>
                                <?php echo esc_html( $project->project_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e( 'Status', 'n88-rfq' ); ?></span>
                    <select name="status">
                        <option value="all" <?php selected( $status_filter, 'all' ); ?>><?php esc_html_e( 'All', 'n88-rfq' ); ?></option>
                        <option value="unread" <?php selected( $status_filter, 'unread' ); ?>><?php esc_html_e( 'Needs Review', 'n88-rfq' ); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e( 'Search', 'n88-rfq' ); ?></span>
                    <input type="search" name="s" value="<?php echo esc_attr( $search_term ); ?>" placeholder="<?php esc_attr_e( 'Search text…', 'n88-rfq' ); ?>" />
                </label>
                <button class="button button-primary"><?php esc_html_e( 'Apply', 'n88-rfq' ); ?></button>
            </form>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'n88-rfq' ); ?></th>
                        <th><?php esc_html_e( 'Project', 'n88-rfq' ); ?></th>
                        <th><?php esc_html_e( 'Author', 'n88-rfq' ); ?></th>
                        <th><?php esc_html_e( 'Comment', 'n88-rfq' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'n88-rfq' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $comments ) ) : ?>
                        <tr>
                            <td colspan="5"><?php esc_html_e( 'No comments found.', 'n88-rfq' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $comments as $comment ) : ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $comment->created_at ) ) ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( home_url( '/project-detail/?project_id=' . $comment->project_id ) ); ?>" target="_blank">
                                        <?php echo esc_html( $comment->project_name ?: __( 'Unknown project', 'n88-rfq' ) ); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php echo esc_html( $comment->author_name ?: __( 'Unknown user', 'n88-rfq' ) ); ?>
                                    <div class="description">
                                        <?php echo esc_html( $comment->item_id ?: __( 'Project level', 'n88-rfq' ) ); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo wp_kses_post( wp_trim_words( $comment->comment_text, 30, '…' ) ); ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-secondary n88-admin-reply-toggle" data-comment-id="<?php echo esc_attr( $comment->id ); ?>">
                                        <?php esc_html_e( 'Reply', 'n88-rfq' ); ?>
                                    </button>
                                    <div class="n88-admin-reply-panel" id="n88-admin-reply-<?php echo esc_attr( $comment->id ); ?>" style="display:none; margin-top:10px;">
                                        <form method="post">
                                            <?php wp_nonce_field( 'n88_admin_reply_action' ); ?>
                                            <input type="hidden" name="page" value="n88-rfq-comments" />
                                            <input type="hidden" name="original_comment_id" value="<?php echo esc_attr( $comment->id ); ?>" />
                                            <input type="hidden" name="reply_project_id" value="<?php echo esc_attr( $comment->project_id ); ?>" />
                                            <input type="hidden" name="reply_item_id" value="<?php echo esc_attr( $comment->item_id ); ?>" />
                                            <input type="hidden" name="reply_video_id" value="<?php echo esc_attr( $comment->video_id ); ?>" />
                                            <textarea name="reply_text" rows="3" style="width:100%;" placeholder="<?php esc_attr_e( 'Type your reply…', 'n88-rfq' ); ?>"></textarea>
                                            <label style="display:inline-flex; align-items:center; gap:6px; margin-top:6px;">
                                                <input type="checkbox" name="reply_urgent" value="1" />
                                                <?php esc_html_e( 'Mark as urgent', 'n88-rfq' ); ?>
                                            </label>
                                            <div style="margin-top:8px;">
                                                <button type="submit" name="n88_admin_reply" class="button button-primary"><?php esc_html_e( 'Send Reply', 'n88-rfq' ); ?></button>
                                                <button type="button" class="button n88-admin-reply-cancel"><?php esc_html_e( 'Cancel', 'n88-rfq' ); ?></button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
        <style>
            .n88-admin-reply-panel textarea {
                resize: vertical;
            }
        </style>
        <script>
            document.addEventListener('click', function(e) {
                const toggleBtn = e.target.closest('.n88-admin-reply-toggle');
                if (toggleBtn) {
                    e.preventDefault();
                    const panel = document.getElementById('n88-admin-reply-' + toggleBtn.dataset.commentId);
                    if (panel) {
                        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
                    }
                }

                const cancelBtn = e.target.closest('.n88-admin-reply-cancel');
                if (cancelBtn) {
                    e.preventDefault();
                    const panel = cancelBtn.closest('.n88-admin-reply-panel');
                    if (panel) {
                        panel.style.display = 'none';
                    }
                }
            });
        </script>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <style>
            .n88-admin-comments .n88-filters {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
                align-items: flex-end;
                margin-bottom: 20px;
            }
            .n88-admin-comments .n88-filters label {
                display: flex;
                flex-direction: column;
                font-weight: 600;
            }
        </style>
        <?php
    }
}
