<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class N88_RFQ_Quotes {

    /**
     * Cache for quote table pricing column support.
     *
     * @var bool|null
     */
    private static $quote_table_supports_pricing = null;

    /**
     * Check whether the project_quotes table has the pricing columns.
     *
     * @return bool
     */
    public static function quote_table_supports_pricing() {
        if ( null !== self::$quote_table_supports_pricing ) {
            return self::$quote_table_supports_pricing;
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'project_quotes';
        // Table name is safe (from $wpdb->prefix), but we validate it contains only safe characters
        $table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
        $columns = $wpdb->get_col( "DESC {$table_safe}", 0 );

        $required = array(
            'labor_cost',
            'materials_cost',
            'overhead_cost',
            'margin_percentage',
            'shipping_zone',
            'unit_price',
            'total_price',
            'lead_time',
            'cbm_volume',
            'volume_rules_applied',
        );

        foreach ( $required as $column ) {
            if ( ! in_array( $column, $columns, true ) ) {
                self::$quote_table_supports_pricing = false;
                return false;
            }
        }

        self::$quote_table_supports_pricing = true;
        return true;
    }

    /**
     * Upload a quote for a project
     *
     * @param array $data {
     *     @type int $project_id Project ID.
     *     @type int $user_id Admin user ID
     *     @type string $admin_notes Internal notes
     *     @type array $file Quote file (from $_FILES)
     * }
     * @return int|false Quote ID on success, false on failure
     */
    public static function upload_quote( $data ) {
        global $wpdb;

        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        if ( empty( $data['project_id'] ) || empty( $_FILES['quote_file'] ) ) {
            return false;
        }

        $project_id = intval( $data['project_id'] );
        $user_id = intval( $data['user_id'] );
        $admin_notes = isset( $data['admin_notes'] ) ? wp_kses_post( $data['admin_notes'] ) : '';

        // Validate project exists
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        if ( ! $project ) {
            return false;
        }

        // Handle file upload
        $quote_file_path = self::handle_quote_file_upload( $_FILES['quote_file'], $project_id );
        if ( ! $quote_file_path ) {
            return false;
        }

        // Create quote record
        $table = $wpdb->prefix . 'project_quotes';
        $now = current_time( 'mysql' );

        // Phase 2B: Get pricing data if provided
        $labor_cost = isset( $data['labor_cost'] ) ? (float) $data['labor_cost'] : 0;
        $materials_cost = isset( $data['materials_cost'] ) ? (float) $data['materials_cost'] : 0;
        $overhead_cost = isset( $data['overhead_cost'] ) ? (float) $data['overhead_cost'] : 0;
        $margin_percentage = isset( $data['margin_percentage'] ) ? (float) $data['margin_percentage'] : 0;
        $shipping_zone = isset( $data['shipping_zone'] ) ? sanitize_text_field( $data['shipping_zone'] ) : '';

        // Calculate pricing if all components provided
        $unit_price = 0;
        $total_price = 0;
        $lead_time = '';
        $cbm_volume = 0;
        $volume_rules_applied = '';

        if ( $labor_cost > 0 || $materials_cost > 0 || $overhead_cost > 0 ) {
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
                    $unit_price = $pricing_result['final_unit_price'];
                    $total_price = $pricing_result['total_price'];
                    $lead_time = $pricing_result['lead_time'];
                    $cbm_volume = $pricing_result['total_cbm'];
                    $volume_rules_applied = wp_json_encode( $pricing_result['volume_rules_applied'] );
                }
            }
        }

        $insert_data = array(
            'project_id'     => $project_id,
            'user_id'        => $user_id,
            'quote_file_path'=> $quote_file_path,
            'admin_notes'    => $admin_notes,
            'quote_status'   => 'pending',
        );
        $insert_format = array( '%d', '%d', '%s', '%s', '%s' );
        
        // Store client message in metadata if table supports it, otherwise in admin_notes with separator
        if ( ! empty( $client_message ) ) {
            // For now, append to admin_notes with a separator (can be enhanced with dedicated column later)
            // Format: "CLIENT_MSG_START:{message}CLIENT_MSG_END"
            if ( ! empty( $admin_notes ) ) {
                $admin_notes .= "\n\n--- CLIENT MESSAGE ---\n" . $client_message;
            } else {
                $admin_notes = "CLIENT_MSG_START:" . $client_message . "CLIENT_MSG_END";
            }
            $insert_data['admin_notes'] = $admin_notes;
        }

        if ( self::quote_table_supports_pricing() ) {
            $insert_data['labor_cost']         = $labor_cost;
            $insert_format[]                   = '%f';
            $insert_data['materials_cost']     = $materials_cost;
            $insert_format[]                   = '%f';
            $insert_data['overhead_cost']      = $overhead_cost;
            $insert_format[]                   = '%f';
            $insert_data['margin_percentage']  = $margin_percentage;
            $insert_format[]                   = '%f';
            $insert_data['shipping_zone']      = $shipping_zone;
            $insert_format[]                   = '%s';
            $insert_data['unit_price']         = $unit_price;
            $insert_format[]                   = '%f';
            $insert_data['total_price']        = $total_price;
            $insert_format[]                   = '%f';
            $insert_data['lead_time']          = $lead_time;
            $insert_format[]                   = '%s';
            $insert_data['cbm_volume']         = $cbm_volume;
            $insert_format[]                   = '%f';
            $insert_data['volume_rules_applied'] = $volume_rules_applied;
            $insert_format[]                   = '%s';
        } elseif ( $labor_cost > 0 || $materials_cost > 0 || $overhead_cost > 0 ) {
            error_log( 'N88 RFQ: Quote pricing columns missing; pricing data skipped.' );
        }

        $insert_data['created_at'] = $now;
        $insert_format[]           = '%s';
        $insert_data['updated_at'] = $now;
        $insert_format[]           = '%s';

        $inserted = $wpdb->insert( $table, $insert_data, $insert_format );

        if ( $inserted ) {
            $quote_id = $wpdb->insert_id;

            // Log action
            N88_RFQ_Audit::log_action(
                $project_id,
                $user_id,
                'quote_uploaded',
                'quote_id',
                '',
                $quote_id
            );

            // Update project timestamp when quote is uploaded
            $projects_class = new N88_RFQ_Projects();
            $projects_class->update_project_timestamp( $project_id, $user_id );

            // Track admin updates (admin uploaded quote)
            $projects_class->save_project_metadata( $project_id, array(
                'n88_has_admin_updates'   => '1',
                'n88_last_admin_update'   => current_time( 'mysql' ),
                'n88_last_admin_update_by'=> (string) $user_id,
            ) );

            return $quote_id;
        }

        return false;
    }

    /**
     * Handle quote file upload
     *
     * @param array $file File from $_FILES
     * @param int $project_id Project ID
     * @param int|null $quote_id Optional quote ID to delete old file
     * @return string|false File path on success
     */
    public static function handle_quote_file_upload( $file, $project_id, $quote_id = null ) {
        if ( empty( $file['tmp_name'] ) ) {
            return false;
        }

        // Get allowed file types from helper
        $allowed_types = N88_RFQ_Helpers::get_allowed_file_types();
        
        // Validate file using MIME type and file header checks
        if ( ! empty( $file['tmp_name'] ) ) {
            $is_valid = N88_RFQ_Helpers::validate_file_mime_type(
                $file['tmp_name'],
                $file['type'],
                $allowed_types
            );
            
            if ( ! $is_valid ) {
                // Fallback: Check by extension for DWG files
                $file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
                $is_dwg = ( $file_ext === 'dwg' );
                if ( ! $is_dwg ) {
                    return false;
                }
            }
        } else {
            // Fallback: Check by MIME type or extension (for DWG files)
            $file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
            $is_dwg = ( $file_ext === 'dwg' );
            $is_allowed_type = in_array( $file['type'], $allowed_types );
            
            if ( ! $is_allowed_type && ! $is_dwg ) {
                return false;
            }
        }

        // Check file size (max 10MB)
        $max_size = 10 * 1024 * 1024;
        if ( $file['size'] > $max_size ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $quote_dir = $upload_dir['basedir'] . '/n88-rfq-quotes/' . $project_id;

        if ( ! wp_mkdir_p( $quote_dir ) ) {
            return false;
        }

        // Delete old quote file if updating an existing quote
        if ( $quote_id ) {
            $old_quote = self::get_quote( $quote_id );
            if ( $old_quote && ! empty( $old_quote->quote_file_path ) ) {
                $old_file_path = $upload_dir['basedir'] . '/' . $old_quote->quote_file_path;
                if ( file_exists( $old_file_path ) ) {
                    @unlink( $old_file_path );
                }
            }
        }

        // Use a consistent filename for the quote (quote.pdf) so it replaces the old one
        $file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $filename = 'quote.' . $file_ext;
        $file_path = $quote_dir . '/' . $filename;

        // If file already exists, delete it first
        if ( file_exists( $file_path ) ) {
            @unlink( $file_path );
        }

        if ( ! move_uploaded_file( $file['tmp_name'], $file_path ) ) {
            return false;
        }

        // Return relative path
        return 'n88-rfq-quotes/' . $project_id . '/' . $filename;
    }

    /**
     * Send quote to user
     *
     * @param int $quote_id Quote ID
     * @param int $user_id Admin user ID
     * @return bool True on success
     */
    public static function send_quote( $quote_id, $user_id ) {
        global $wpdb;

        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $quote_id = intval( $quote_id );
        $user_id = intval( $user_id );

        // Get quote - refresh to get latest status in case it was just updated
        $quote = self::get_quote( $quote_id );
        if ( ! $quote ) {
            return false;
        }

        // Phase 2B: Only generate PDF if no file was manually uploaded
        // Check if the current file is an auto-generated one (starts with 'n88-auto-quote-')
        $is_auto_generated = false;
        $file_exists = false;
        if ( ! empty( $quote->quote_file_path ) ) {
            $filename = basename( $quote->quote_file_path );
            $is_auto_generated = ( strpos( $filename, 'n88-auto-quote-' ) === 0 );
            
            // Check if file actually exists
            $upload_dir = wp_upload_dir();
            $full_path = $upload_dir['basedir'] . '/' . $quote->quote_file_path;
            $file_exists = file_exists( $full_path );
        }
        
        // Only generate PDF if:
        // 1. No file exists, OR
        // 2. Current file is auto-generated (can be regenerated with latest data)
        // If admin manually uploaded a file (not auto-generated), preserve it
        if ( empty( $quote->quote_file_path ) || ! $file_exists || $is_auto_generated ) {
            $generated_path = self::generate_quote_pdf( $quote_id );
            if ( $generated_path ) {
                $quote->quote_file_path = $generated_path;
            }
        }
        
        // Refresh quote to get latest status (in case it was updated just before calling send_quote)
        // This ensures we use the most current status when determining if it should be 'quote_updated'
        $quote = self::get_quote( $quote_id );
        if ( ! $quote ) {
            return false;
        }

        // Get project and project owner
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $quote->project_id );
        if ( ! $project ) {
            return false;
        }

        // Get project owner user info
        $project_owner = get_userdata( $project['user_id'] );
        if ( ! $project_owner ) {
            return false;
        }

        // Update quote status
        $table = $wpdb->prefix . 'project_quotes';
        $now = current_time( 'mysql' );
        
        // If quote was already sent before, mark as updated; otherwise mark as sent
        // Check for 'sent', 'quote_updated', or 'quoted' status
        $already_sent_statuses = array( 'sent', 'quote_updated', 'quoted' );
        $new_status = in_array( $quote->quote_status, $already_sent_statuses, true ) ? 'quote_updated' : 'sent';

        // Before updating, check if status was already set to 'quote_updated' in a recent update
        // If so, preserve it instead of potentially overwriting it
        $current_quote = $wpdb->get_row( $wpdb->prepare( "SELECT quote_status FROM {$table} WHERE id = %d", $quote_id ) );
        if ( $current_quote && $current_quote->quote_status === 'quote_updated' ) {
            $new_status = 'quote_updated';
        }
        
        $updated = $wpdb->update(
            $table,
            array(
                'quote_status' => $new_status,
                'sent_at' => $now,
                'updated_at' => $now,
            ),
            array( 'id' => $quote_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( $updated ) {
            // Update project timestamp when quote is sent
            $projects_class->update_project_timestamp( $quote->project_id, $user_id );

            // Track admin updates (admin sent quote)
            $projects_class->save_project_metadata( $quote->project_id, array(
                'n88_has_admin_updates'   => '1',
                'n88_last_admin_update'   => current_time( 'mysql' ),
                'n88_last_admin_update_by'=> (string) $user_id,
            ) );

            // Track admin updates (admin sent quote)
            $projects_class->save_project_metadata( $quote->project_id, array(
                'n88_has_admin_updates'   => '1',
                'n88_last_admin_update'   => current_time( 'mysql' ),
                'n88_last_admin_update_by'=> (string) $user_id,
            ) );

            // Send email notification
            $admin = get_userdata( $user_id );
            $is_update = ( $new_status === 'quote_updated' );
            $subject = $is_update ? 'Your Quote Has Been Updated - ' . $project['project_name'] : 'Your Quote for ' . $project['project_name'];
            
            $message = "Hello " . $project_owner->display_name . ",\n\n";
            if ( $is_update ) {
                $message .= "Your quote has been updated!\n\n";
                $message .= "Project: " . $project['project_name'] . "\n";
                $message .= "Status: Quote Updated\n\n";
                $message .= "Please check your project dashboard to view the updated quote.\n\n";
            } else {
                $message .= "Your requested quote is ready!\n\n";
                $message .= "Project: " . $project['project_name'] . "\n";
                $message .= "Status: Quote Ready\n\n";
                $message .= "Please check your project dashboard to view and download your quote.\n\n";
            }
            $message .= "Best regards,\n" . get_bloginfo( 'name' );

            wp_mail(
                $project_owner->user_email,
                $subject,
                $message,
                'Content-Type: text/plain; charset=UTF-8'
            );

            // Create notification
            $notification_type = $is_update ? 'quote_updated' : 'quote_ready';
            $notification_message = $is_update ? 'Your quote has been updated. Click to view changes.' : 'Your quote is ready. Click to view.';
            
            N88_RFQ_Notifications::create_notification(
                $quote->project_id,
                $project['user_id'],
                $notification_type,
                $notification_message,
                $quote_id
            );

            // Log action
            N88_RFQ_Audit::log_action(
                $quote->project_id,
                $user_id,
                'quote_sent',
                'quote_id',
                'pending',
                'sent'
            );

            return true;
        }

        return false;
    }

    /**
     * Get quote by ID
     *
     * @param int $quote_id Quote ID
     * @return object|null Quote object
     */
    public static function get_quote( $quote_id ) {
        global $wpdb;

        $quote_id = intval( $quote_id );
        $table = $wpdb->prefix . 'project_quotes';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $quote_id
            )
        );
    }

    /**
     * Get quotes for a project
     *
     * @param int $project_id Project ID
     * @return array Array of quote objects
     */
    public static function get_project_quotes( $project_id ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $table = $wpdb->prefix . 'project_quotes';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE project_id = %d ORDER BY created_at DESC",
                $project_id
            )
        );
    }

    /**
     * Get latest quote for a project
     *
     * @param int $project_id Project ID
     * @return object|null Quote object
     */
    public static function get_latest_quote( $project_id ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $table = $wpdb->prefix . 'project_quotes';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE project_id = %d ORDER BY created_at DESC LIMIT 1",
                $project_id
            )
        );
    }

    /**
     * Update quote
     *
     * @param int $quote_id Quote ID
     * @param array $data Quote data to update
     * @return bool True on success
     */
    public static function update_quote( $quote_id, $data ) {
        global $wpdb;

        // Allow both admins and clients to update quotes (clients can only update their own quotes)
        $quote_id = intval( $quote_id );
        $quote = self::get_quote( $quote_id );
        
        if ( ! $quote ) {
            return false;
        }
        
        // Check permissions: admins can update any quote, clients can only update their own project quotes
        if ( ! current_user_can( 'manage_options' ) ) {
            $projects_class = new N88_RFQ_Projects();
            $project = $projects_class->get_project( $quote->project_id );
            if ( ! $project || (int) $project['user_id'] !== get_current_user_id() ) {
                return false;
            }
        }

        $quote_id = intval( $quote_id );
        $quote = self::get_quote( $quote_id );

        if ( ! $quote ) {
            return false;
        }

        $update_data = array();
        $update_format = array();

        if ( isset( $data['admin_notes'] ) ) {
            $update_data['admin_notes'] = wp_kses_post( $data['admin_notes'] );
            $update_format[] = '%s';
        }

        if ( isset( $data['client_message'] ) ) {
            $update_data['client_message'] = wp_kses_post( $data['client_message'] );
            $update_format[] = '%s';
        }

        if ( isset( $data['quote_items'] ) ) {
            $update_data['quote_items'] = is_string( $data['quote_items'] ) ? $data['quote_items'] : wp_json_encode( $data['quote_items'] );
            $update_format[] = '%s';
        }

        if ( isset( $data['quote_status'] ) ) {
            $update_data['quote_status'] = sanitize_text_field( $data['quote_status'] );
            $update_format[] = '%s';
        }

        if ( self::quote_table_supports_pricing() ) {
            $string_fields = array( 'shipping_zone', 'lead_time' );
            $pricing_fields = array(
                'labor_cost'         => '%f',
                'materials_cost'     => '%f',
                'overhead_cost'      => '%f',
                'margin_percentage'  => '%f',
                'shipping_zone'      => '%s',
                'unit_price'         => '%f',
                'total_price'        => '%f',
                'lead_time'          => '%s',
                'cbm_volume'         => '%f',
                'volume_rules_applied' => '%s',
            );

            foreach ( $pricing_fields as $field => $format ) {
                if ( isset( $data[ $field ] ) ) {
                    $value = $data[ $field ];

                    if ( 'volume_rules_applied' === $field && is_array( $value ) ) {
                        $value = wp_json_encode( $value );
                    } elseif ( in_array( $field, $string_fields, true ) ) {
                        $value = sanitize_text_field( $value );
                    } elseif ( 'volume_rules_applied' !== $field ) {
                        $value = (float) $value;
                    }

                    $update_data[ $field ] = $value;
                    $update_format[] = $format;
                }
            }
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $update_data['updated_at'] = current_time( 'mysql' );
        $update_format[] = '%s';

        $table = $wpdb->prefix . 'project_quotes';

        $updated = $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $quote_id ),
            $update_format,
            array( '%d' )
        );

        if ( $updated !== false ) {
            // Track admin updates (admin updated quote/pricing)
            $projects_class = new N88_RFQ_Projects();
            $projects_class->save_project_metadata( $quote->project_id, array(
                'n88_has_admin_updates'   => '1',
                'n88_last_admin_update'   => current_time( 'mysql' ),
                'n88_last_admin_update_by'=> (string) get_current_user_id(),
            ) );
        }

        return $updated !== false;
    }

    /**
     * Generate a PDF quote summary with latest pricing and items.
     *
     * @param int $quote_id Quote ID.
     * @return string|false Relative file path on success.
     */
    public static function generate_quote_pdf( $quote_id ) {
        if ( ! class_exists( 'N88_FPDF' ) && ! class_exists( 'FPDF' ) ) {
            return false;
        }

        $quote = self::get_quote( $quote_id );
        if ( ! $quote ) {
            return false;
        }

        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $quote->project_id );
        if ( ! $project ) {
            return false;
        }

        $metadata    = $project['metadata'] ?? array();
        $items_json  = $metadata['n88_repeater_raw'] ?? '[]';
        $items       = json_decode( $items_json, true );
        $items       = is_array( $items ) ? $items : array();
        $owner       = get_userdata( $project['user_id'] );
        $owner_name  = $owner ? $owner->display_name : 'Client';
        $project_name = $project['project_name'] ?? 'Project';

        $pdf = class_exists( 'N88_FPDF' ) ? new N88_FPDF() : new FPDF();
        $pdf->AddPage();
        $pdf->SetFont( 'Arial', 'B', 16 );
        $pdf->Cell( 0, 10, 'Quote Summary', 0, 1 );

        $pdf->SetFont( 'Arial', '', 12 );
        $pdf->Cell( 0, 7, 'Project: ' . $project_name, 0, 1 );
        $pdf->Cell( 0, 7, 'Client: ' . $owner_name, 0, 1 );
        if ( ! empty( $metadata['n88_company_name'] ) ) {
            $pdf->Cell( 0, 7, 'Company: ' . $metadata['n88_company_name'], 0, 1 );
        }
        $pdf->Cell( 0, 7, 'Generated: ' . date_i18n( get_option( 'date_format' ) ), 0, 1 );
        $pdf->Ln( 5 );

        // Pricing summary
        $pdf->SetFont( 'Arial', 'B', 14 );
        $pdf->Cell( 0, 8, 'Pricing Summary', 0, 1 );
        $pdf->SetFont( 'Arial', '', 12 );
        if ( isset( $quote->unit_price ) ) {
            $pdf->Cell( 0, 7, 'Unit Price: $' . number_format( (float) $quote->unit_price, 2 ), 0, 1 );
        }
        if ( isset( $quote->total_price ) ) {
            $pdf->Cell( 0, 7, 'Total Price: $' . number_format( (float) $quote->total_price, 2 ), 0, 1 );
        }
        if ( ! empty( $quote->lead_time ) ) {
            $pdf->Cell( 0, 7, 'Lead Time: ' . $quote->lead_time, 0, 1 );
        }
        if ( isset( $quote->cbm_volume ) ) {
            $pdf->Cell( 0, 7, 'Total Volume: ' . number_format( (float) $quote->cbm_volume, 4 ) . ' m³', 0, 1 );
        }
        $pdf->Ln( 5 );

        // Items table
        $pdf->SetFont( 'Arial', 'B', 14 );
        $pdf->Cell( 0, 8, 'Items', 0, 1 );
        $pdf->SetFont( 'Arial', '', 11 );
        if ( empty( $items ) ) {
            $pdf->Cell( 0, 7, 'No items found.', 0, 1 );
        } else {
            foreach ( $items as $index => $item ) {
                $pdf->SetFont( 'Arial', 'B', 11 );
                $pdf->Cell( 0, 6, 'Item ' . ( $index + 1 ), 0, 1 );
                $pdf->SetFont( 'Arial', '', 11 );
                $dimensions = array(
                    $item['length_in'] ?? '',
                    $item['depth_in'] ?? '',
                    $item['height_in'] ?? '',
                );
                $pdf->Cell( 0, 6, 'Dimensions: ' . implode( ' x ', array_map( 'trim', $dimensions ) ) . ' in', 0, 1 );
                $pdf->Cell( 0, 6, 'Quantity: ' . ( $item['quantity'] ?? 0 ), 0, 1 );
                if ( ! empty( $item['primary_material'] ) ) {
                    $pdf->Cell( 0, 6, 'Primary Material: ' . $item['primary_material'], 0, 1 );
                }
                if ( ! empty( $item['finishes'] ) ) {
                    $pdf->Cell( 0, 6, 'Finishes: ' . $item['finishes'], 0, 1 );
                }
                if ( ! empty( $item['construction_notes'] ) ) {
                    $pdf->MultiCell( 0, 6, 'Construction Notes: ' . $item['construction_notes'] );
                }
                $pdf->Ln( 2 );
            }
        }

        $upload_dir = wp_upload_dir();
        $quote_dir  = $upload_dir['basedir'] . '/n88-rfq-quotes/' . $quote->project_id;
        if ( ! wp_mkdir_p( $quote_dir ) ) {
            return false;
        }

        $filename   = 'n88-auto-quote-' . $quote_id . '.pdf';
        $full_path  = trailingslashit( $quote_dir ) . $filename;
        $relative   = 'n88-rfq-quotes/' . $quote->project_id . '/' . $filename;

        $pdf->Output( 'F', $full_path );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'project_quotes',
            array(
                'quote_file_path' => $relative,
                'updated_at'      => current_time( 'mysql' ),
            ),
            array( 'id' => $quote_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        return $relative;
    }

    /**
     * Delete quote
     *
     * @param int $quote_id Quote ID
     * @return bool True on success
     */
    public static function delete_quote( $quote_id ) {
        global $wpdb;

        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $quote_id = intval( $quote_id );
        $quote = self::get_quote( $quote_id );

        if ( ! $quote ) {
            return false;
        }

        // Delete file
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $quote->quote_file_path;
        if ( file_exists( $file_path ) ) {
            unlink( $file_path );
        }

        // Delete quote record
        $table = $wpdb->prefix . 'project_quotes';
        return $wpdb->delete(
            $table,
            array( 'id' => $quote_id ),
            array( '%d' )
        );
    }

    /**
     * Format quote for display
     *
     * @param object $quote Quote object
     * @return array Formatted quote data
     */
    public static function format_quote( $quote ) {
        $admin = get_userdata( $quote->user_id );
        $upload_dir = wp_upload_dir();

        // Parse volume rules if stored as JSON
        $volume_rules = array();
        if ( ! empty( $quote->volume_rules_applied ) ) {
            $decoded = json_decode( $quote->volume_rules_applied, true );
            $volume_rules = is_array( $decoded ) ? $decoded : array();
        }

        // Verify file exists before generating URL
        $quote_file_url = '';
        if ( ! empty( $quote->quote_file_path ) ) {
            $full_path = $upload_dir['basedir'] . '/' . $quote->quote_file_path;
            if ( file_exists( $full_path ) ) {
                $quote_file_url = $upload_dir['baseurl'] . '/' . $quote->quote_file_path;
            }
        }

        return array(
            'id' => $quote->id,
            'project_id' => $quote->project_id,
            'user_id' => $quote->user_id,
            'admin_name' => $admin ? $admin->display_name : 'Unknown',
            'admin_email' => $admin ? $admin->user_email : '',
            'quote_file_url' => $quote_file_url,
            'quote_file_path' => $quote->quote_file_path,
            'admin_notes' => $quote->admin_notes,
            'quote_status' => $quote->quote_status,
            'labor_cost' => isset( $quote->labor_cost ) ? (float) $quote->labor_cost : 0,
            'materials_cost' => isset( $quote->materials_cost ) ? (float) $quote->materials_cost : 0,
            'overhead_cost' => isset( $quote->overhead_cost ) ? (float) $quote->overhead_cost : 0,
            'margin_percentage' => isset( $quote->margin_percentage ) ? (float) $quote->margin_percentage : 0,
            'shipping_zone' => isset( $quote->shipping_zone ) ? $quote->shipping_zone : '',
            'unit_price' => isset( $quote->unit_price ) ? (float) $quote->unit_price : 0,
            'total_price' => isset( $quote->total_price ) ? (float) $quote->total_price : 0,
            'lead_time' => isset( $quote->lead_time ) ? $quote->lead_time : '',
            'cbm_volume' => isset( $quote->cbm_volume ) ? (float) $quote->cbm_volume : 0,
            'volume_rules_applied' => $volume_rules,
            'created_at' => $quote->created_at,
            'updated_at' => $quote->updated_at,
            'sent_at' => $quote->sent_at,
        );
    }
}
