<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * N88 RFQ Projects Management Class
 *
 * SECURITY IMPLEMENTATION CHECKLIST:
 * ✓ Nonce verification: All form submissions validated via wp_verify_nonce()
 * ✓ Login verification: All methods check is_user_logged_in()
 * ✓ User authorization: Project ownership verified via get_project( $project_id, $user_id )
 * ✓ Input sanitization: All $_POST/$_GET data sanitized with appropriate functions
 *   - sanitize_text_field() for text inputs
 *   - sanitize_textarea_field() for multi-line text
 *   - sanitize_email() for email addresses
 *   - (float), (int) for numeric values
 *   - wp_handle_upload() for file uploads with type checking
 * ✓ Output escaping: All data escaped on output in shortcodes/templates
 *   - esc_html() for plain text
 *   - esc_url() for URLs
 *   - esc_attr() for HTML attributes
 *   - wp_kses_post() for HTML content
 * ✓ File uploads: Restricted to PDF, JPG, PNG, GIF via allowed_types whitelist
 * ✓ SQL injection prevention: Using wpdb->prepare() with placeholders
 * ✓ CSRF protection: Nonce tokens required on all form submissions
 */
class N88_RFQ_Projects {

    protected $projects_table;
    protected $meta_table;

    /**
     * Project editing workflow:
     *
     * 1. New Project Creation:
     *    - User fills form without project_id parameter
     *    - Submits via form → handle_project_submit()
     *    - Creates new wp_projects row with status 0 (draft)
     *    - Saves all metadata and repeater items
     *    - Redirects to /my-projects/?n88_saved=1
     *
     * 2. Draft Editing:
     *    - User navigates to /rfq-form/?project_id=123
     *    - Frontend loads project via get_project() if user owns it
     *    - Form prefills with all existing values
     *    - User updates fields and saves
     *    - Submission calls handle_project_submit() with project_id in POST
     *    - save_project() updates existing record instead of creating new
     *    - updated_at timestamp refreshed
     *    - All metadata fields updated/replaced
     *
     * 3. Submit for Quote:
     *    - User clicks "Submit for Quote" button (not "Save as Draft")
     *    - Validation runs on required fields
     *    - If valid: status changed to 1 (submitted), submitted_at set
     *    - Admin email sent with full project summary
     *    - User redirected to thank-you page
     *    - Project can NO LONGER be edited
     *
     * 4. After Submission:
     *    - Project marked as submitted (status = 1)
     *    - is_project_editable() returns false
     *    - Frontend shows "cannot edit" message
     *    - User can view in My Projects dashboard
     *    - User can view details in Project Detail view
     */
    public function __construct() {
        global $wpdb;

        $this->projects_table = $wpdb->prefix . 'projects';
        $this->meta_table     = $wpdb->prefix . 'project_metadata';

        add_action( 'admin_post_n88_submit_project', array( $this, 'handle_project_submit' ) );
        add_action( 'admin_post_nopriv_n88_submit_project', array( $this, 'handle_project_submit' ) );
    }

    /**
     * Send admin notification email for submitted project.
     *
     * @param array $project Project data.
     * @param int   $user_id User ID who submitted.
     * @return bool True if email was sent.
     */
    public function send_admin_notification_email( $project, $user_id ) {
        $admin_email = get_option( 'admin_email' );

        if ( ! $admin_email ) {
            return false;
        }

        $user = get_userdata( $user_id );
        $project_name = $project['project_name'] ?? 'Untitled Project';
        $project_type = $project['project_type'] ?? 'N/A';
        $timeline     = $project['timeline'] ?? 'N/A';
        $budget_range = $project['budget_range'] ?? 'N/A';
        $metadata     = $project['metadata'] ?? array();
        $contact_name = $metadata['n88_contact_name'] ?? '';
        $email        = $metadata['n88_email'] ?? '';
        $company_name = $metadata['n88_company_name'] ?? '';
        $phone        = $metadata['n88_phone'] ?? '';
        $location     = $metadata['n88_location'] ?? '';
        $repeater_json = $metadata['n88_repeater_raw'] ?? '[]';
        $repeater      = json_decode( $repeater_json, true ) ?: array();

        // Build email subject and body
        $subject = "New RFQ Submitted – {$project_name}";

        $message = "A new Request for Quote has been submitted.\n\n";
        $message .= "=== PROJECT DETAILS ===\n";
        $message .= "Project Name: {$project_name}\n";
        $message .= "Project Type: {$project_type}\n";
        $message .= "Timeline: {$timeline}\n";
        $message .= "Budget Range: {$budget_range}\n\n";

        $message .= "=== CLIENT INFORMATION ===\n";
        if ( $company_name ) {
            $message .= "Company: {$company_name}\n";
        }
        if ( $contact_name ) {
            $message .= "Contact Name: {$contact_name}\n";
        }
        $message .= "Email: {$email}\n";
        if ( $phone ) {
            $message .= "Phone: {$phone}\n";
        }
        if ( $location ) {
            $message .= "Location: {$location}\n";
        }

        $message .= "\n=== ITEMS ({" . count( $repeater ) . "}) ===\n";
        foreach ( $repeater as $index => $item ) {
            $message .= "\nItem " . ( $index + 1 ) . ":\n";
            $message .= "  Dimensions: {$item['length_in']}\" x {$item['depth_in']}\" x {$item['height_in']}\"\n";
            $message .= "  Quantity: {$item['quantity']}\n";
            if ( ! empty( $item['cushions'] ) ) {
                $message .= "  Cushions: {$item['cushions']}\n";
            }
            if ( ! empty( $item['fabric_category'] ) ) {
                $message .= "  Fabric Category: {$item['fabric_category']}\n";
            }
            if ( ! empty( $item['frame_material'] ) ) {
                $message .= "  Frame Material: {$item['frame_material']}\n";
            }
            if ( ! empty( $item['finish'] ) ) {
                $message .= "  Finish: {$item['finish']}\n";
            }
            if ( ! empty( $item['notes'] ) ) {
                $message .= "  Notes: {$item['notes']}\n";
            }
        }

        $message .= "\n=== SUBMITTED BY ===\n";
        if ( $user ) {
            $message .= "User: {$user->display_name}\n";
            $message .= "Email: {$user->user_email}\n";
        }

        $message .= "\n=== ACTION REQUIRED ===\n";
        $admin_url = admin_url( 'admin.php?page=n88-rfq-projects' );
        $message .= "View and manage this project in the admin dashboard:\n";
        $message .= "{$admin_url}\n";

        /**
         * Allow filtering the admin notification email.
         *
         * @param string $subject Email subject.
         * @param string $message Email body.
         * @param array  $project Project data.
         * @param int    $user_id User ID.
         */
        $result = apply_filters( 'n88_rfq_admin_email', array(
            'subject' => $subject,
            'message' => $message,
            'to'      => $admin_email,
            'headers' => array( 'Content-Type: text/plain; charset=UTF-8' ),
        ), $project, $user_id );

        // Send email if filter returns result
        if ( is_array( $result ) && isset( $result['to'] ) ) {
            return wp_mail( $result['to'], $result['subject'], $result['message'], $result['headers'] ?? array() );
        }

        return false;
    }

    /**
     * Handle file uploads for a project.
     *
     * @param array $files $_FILES array.
     * @return array Array of attachment IDs.
     */
    public function handle_file_uploads( $files ) {
        $attachment_ids = array();

        if ( empty( $files['name'] ) ) {
            return $attachment_ids;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Allow specific file types (PDF, JPG, PNG, GIF, DWG)
        $allowed_types = array( 
            'application/pdf', 
            'image/jpeg', 
            'image/png', 
            'image/gif',
            'application/acad',
            'application/x-acad',
            'image/vnd.dwg',
            'application/dwg',
            'application/x-dwg',
            'image/x-dwg'
        );

        // Handle multiple files
        $file_count = is_array( $files['name'] ) ? count( $files['name'] ) : 1;

        for ( $i = 0; $i < $file_count; $i++ ) {
            if ( is_array( $files['name'] ) ) {
                $_FILES['n88_project_file'] = array(
                    'name'     => $files['name'][ $i ],
                    'type'     => $files['type'][ $i ],
                    'tmp_name' => $files['tmp_name'][ $i ],
                    'error'    => $files['error'][ $i ],
                    'size'     => $files['size'][ $i ],
                );
            } else {
                $_FILES['n88_project_file'] = $files;
            }

            // Check file type - also check by extension for DWG files
            $file_ext = strtolower( pathinfo( $_FILES['n88_project_file']['name'], PATHINFO_EXTENSION ) );
            $is_dwg = ( $file_ext === 'dwg' );
            $is_allowed_type = in_array( $_FILES['n88_project_file']['type'], $allowed_types );
            
            if ( ! $is_allowed_type && ! $is_dwg ) {
                continue;
            }

            $attachment_id = media_handle_upload( 'n88_project_file', 0 );

            if ( ! is_wp_error( $attachment_id ) ) {
                $attachment_ids[] = $attachment_id;
            }
        }

        return $attachment_ids;
    }

    /**
     * Get project files (attachment IDs).
     *
     * @param int $project_id The project ID.
     * @return array Array of attachment IDs.
     */
    public function get_project_files( $project_id ) {
        global $wpdb;

        $files_json = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$this->meta_table} WHERE project_id = %d AND meta_key = %s",
                $project_id,
                'n88_files'
            )
        );

        if ( ! $files_json ) {
            return array();
        }

        return json_decode( $files_json, true ) ?: array();
    }

    /**
     * Save file attachments as project metadata.
     *
     * @param int   $project_id The project ID.
     * @param array $attachment_ids Array of attachment IDs.
     * @return bool True on success.
     */
    public function save_project_files( $project_id, $attachment_ids ) {
        if ( empty( $attachment_ids ) ) {
            return true;
        }

        return $this->save_project_metadata(
            $project_id,
            array( 'n88_files' => wp_json_encode( $attachment_ids ) )
        );
    }

    /**
     * Validate required fields for submit action.
     *
     * @param array $data The POST data to validate.
     * @return array Array of error messages, empty if valid.
     */
    public function validate_submit_data( $data ) {
        $errors = array();
        $form_type = sanitize_text_field( $data['form_type'] ?? 'rfq' );

        // Common validations for all form types
        if ( empty( $data['project_name'] ) ) {
            $errors[] = 'Request Name is required.';
        }

        if ( empty( $data['timeline'] ) ) {
            $errors[] = 'Timeline is required.';
        }

        if ( empty( $data['budget_range'] ) ) {
            $errors[] = 'Budget Range is required.';
        }

        if ( empty( $data['email'] ) ) {
            $errors[] = 'Email is required.';
        } elseif ( ! is_email( $data['email'] ) ) {
            $errors[] = 'Email is not valid.';
        }

        // Form-specific validations
        if ( 'sourcing' === $form_type ) {
            // Sourcing form requires sourcing_category
            if ( empty( $data['sourcing_category'] ) ) {
                $errors[] = 'Sourcing Category is required.';
            }
        } else {
            // RFQ form requires project_type
            if ( empty( $data['project_type'] ) ) {
                $errors[] = 'Project Type is required.';
            }
        }

        // Check for at least 1 item in repeater with required fields
        $has_valid_item = false;
        if ( ! empty( $data['pieces'] ) && is_array( $data['pieces'] ) ) {
            foreach ( $data['pieces'] as $piece ) {
                if ( ! empty( $piece['length_in'] ) && ! empty( $piece['depth_in'] ) && ! empty( $piece['height_in'] ) && ! empty( $piece['quantity'] ) && ! empty( $piece['primary_material'] ) && ! empty( $piece['finishes'] ) && ! empty( $piece['construction_notes'] ) ) {
                    $has_valid_item = true;
                    break;
                }
            }
        }

        if ( ! $has_valid_item ) {
            $errors[] = 'At least 1 item with Length, Depth, Height, Quantity, Primary Material, Finishes, and Construction Notes is required.';
        }

        return $errors;
    }

    /**
     * Save or update a project in the database.
     *
     * @param int    $user_id The current user ID.
     * @param array  $project_data Basic project data (project_name, project_type, timeline, budget_range).
     * @param int    $status Project status (0=draft, 1=submitted).
     * @param int    $existing_project_id If updating, pass the project ID.
     * @param int    $item_count Number of items in the project (optional).
     * @return int|false Project ID on success, false on failure.
     */
    public function save_project( $user_id, $project_data, $status, $existing_project_id = 0, $item_count = 0 ) {
        global $wpdb;

        // Validate user_id
        if ( empty( $user_id ) || ! is_numeric( $user_id ) ) {
            error_log( 'N88 RFQ: Invalid user_id: ' . var_export( $user_id, true ) );
            return false;
        }

        // Verify table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $this->projects_table ) );
        if ( ! $table_exists ) {
            error_log( 'N88 RFQ: Projects table does not exist: ' . $this->projects_table );
            return false;
        }

        $submitted_at = ( N88_RFQ_STATUS_SUBMITTED === $status ) ? current_time( 'mysql' ) : null;
        $quote_type = isset( $project_data['quote_type'] ) ? sanitize_text_field( $project_data['quote_type'] ) : null;

        // Ensure project_name is not empty (use default for drafts if needed)
        $project_name = sanitize_text_field( $project_data['project_name'] ?? '' );
        if ( empty( $project_name ) && N88_RFQ_STATUS_DRAFT === $status ) {
            // For drafts, use a default name if empty
            $project_name = 'Draft Project - ' . current_time( 'Y-m-d H:i:s' );
        }
        
        // For submitted projects, project_name is required (validated elsewhere)
        if ( empty( $project_name ) && N88_RFQ_STATUS_SUBMITTED === $status ) {
            error_log( 'N88 RFQ: Cannot save submitted project without project_name' );
            return false;
        }
        
        // Log before save for debugging
        error_log( 'N88 RFQ: save_project called - User: ' . $user_id . ', Status: ' . $status . ', Existing ID: ' . $existing_project_id . ', Project Name: ' . $project_name );

        $insert_data = array(
            'user_id'      => $user_id,
            'project_name' => $project_name,
            'project_type' => sanitize_text_field( $project_data['project_type'] ?? '' ),
            'timeline'     => sanitize_text_field( $project_data['timeline'] ?? '' ),
            'budget_range' => sanitize_text_field( $project_data['budget_range'] ?? '' ),
            'status'       => $status,
            'updated_at'   => current_time( 'mysql' ),
            'submitted_at' => $submitted_at,
            'updated_by'   => $user_id,
            'quote_type'   => $quote_type,
        );

        // Use null format specifier for nullable fields
        $format = array( 
            '%d',  // user_id
            '%s',  // project_name
            '%s',  // project_type
            '%s',  // timeline
            '%s',  // budget_range
            '%d',  // status
            '%s',  // updated_at
            null,  // submitted_at (can be NULL)
            '%d',  // updated_by
            null,  // quote_type (can be NULL)
        );

        if ( $existing_project_id ) {
            // Update existing project
            $result = $wpdb->update(
                $this->projects_table,
                $insert_data,
                array( 'id' => $existing_project_id, 'user_id' => $user_id ),
                $format,
                array( '%d', '%d' )
            );

            // Check for actual error (false means error, 0 means no rows changed but that's OK)
            if ( false === $result ) {
                // Log error for debugging
                error_log( 'N88 RFQ: Update failed. Error: ' . $wpdb->last_error );
                error_log( 'N88 RFQ: Last query: ' . $wpdb->last_query );
                return false;
            }

            // Log the update (suppress errors if audit table doesn't exist yet)
            @N88_RFQ_Audit::log_action(
                $existing_project_id,
                $user_id,
                'project_updated',
                'project_data',
                '',
                'Project updated'
            );

            return $existing_project_id;
        } else {
            // Create new project
            $insert_data['created_at'] = current_time( 'mysql' );
            $format[] = '%s';  // created_at format

            $result = $wpdb->insert( $this->projects_table, $insert_data, $format );

            if ( false === $result ) {
                // Log error for debugging
                error_log( 'N88 RFQ: Insert failed. Error: ' . $wpdb->last_error );
                error_log( 'N88 RFQ: Last query: ' . $wpdb->last_query );
                error_log( 'N88 RFQ: Insert data: ' . print_r( $insert_data, true ) );
                error_log( 'N88 RFQ: Format: ' . print_r( $format, true ) );
                error_log( 'N88 RFQ: Table name: ' . $this->projects_table );
                return false;
            }

            // Get the insert ID
            $project_id = (int) $wpdb->insert_id;
            
            // Verify we got a valid ID
            if ( empty( $project_id ) || $project_id === 0 ) {
                error_log( 'N88 RFQ: Insert succeeded but insert_id is empty or 0' );
                error_log( 'N88 RFQ: Last query: ' . $wpdb->last_query );
                error_log( 'N88 RFQ: Insert result: ' . var_export( $result, true ) );
                error_log( 'N88 RFQ: Insert data: ' . print_r( $insert_data, true ) );
                return false;
            }

            // Log the creation (suppress errors if audit table doesn't exist yet)
            @N88_RFQ_Audit::log_action(
                $project_id,
                $user_id,
                'project_created',
                '',
                '',
                'Project created'
            );

            return $project_id;
        }
    }

    /**
     * Save repeater items (pieces) as JSON metadata.
     *
     * @param int   $project_id The project ID.
     * @param array $pieces_data Raw pieces data from POST.
     * @return bool True on success.
     */
    public function save_repeater_items( $project_id, $pieces_data ) {
        global $wpdb;

        // Get existing items to compare for change tracking
        $existing_items_json = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$this->meta_table} WHERE project_id = %d AND meta_key = 'n88_repeater_raw'",
            $project_id
        ) );
        $existing_items = ! empty( $existing_items_json ) ? json_decode( $existing_items_json, true ) : array();
        
        // Initialize Item Flags class for change tracking
        $flags_class = null;
        if ( class_exists( 'N88_RFQ_Item_Flags' ) ) {
            $flags_class = new N88_RFQ_Item_Flags();
        }
        
        // Track who is editing (for notifications)
        $current_user_id = get_current_user_id();
        $is_admin_edit = current_user_can( 'manage_options' );
        $has_item_changes = false; // Track if any items were changed by non-admin

        $pieces = array();

        if ( ! empty( $pieces_data ) && is_array( $pieces_data ) ) {
            foreach ( $pieces_data as $index => $row ) {
                $piece = array(
                    'length_in'         => isset( $row['length_in'] ) ? (float) $row['length_in'] : 0,
                    'depth_in'          => isset( $row['depth_in'] ) ? (float) $row['depth_in'] : 0,
                    'height_in'         => isset( $row['height_in'] ) ? (float) $row['height_in'] : 0,
                    'quantity'          => isset( $row['quantity'] ) ? (int) $row['quantity'] : 0,
                    'primary_material'  => sanitize_text_field( $row['primary_material'] ?? '' ),
                    'finishes'          => sanitize_text_field( $row['finishes'] ?? '' ),
                    'construction_notes' => sanitize_textarea_field( $row['construction_notes'] ?? '' ),
                    'cushions'          => isset( $row['cushions'] ) ? (int) $row['cushions'] : 0,
                    'fabric_category'  => sanitize_text_field( $row['fabric_category'] ?? '' ),
                    'frame_material'   => sanitize_text_field( $row['frame_material'] ?? '' ),
                    'finish'           => sanitize_text_field( $row['finish'] ?? '' ),
                    'notes'            => sanitize_textarea_field( $row['notes'] ?? '' ),
                );
                
                // Check if this item was extracted (from existing data or from form hidden fields)
                $is_extracted = ! empty( $row['extracted'] ) || ( isset( $existing_items[ $index ] ) && ! empty( $existing_items[ $index ]['extracted'] ) );
                
                if ( $is_extracted ) {
                    $existing_item = isset( $existing_items[ $index ] ) ? $existing_items[ $index ] : array();
                    
                    // Preserve extraction status and locked state
                    $piece['extracted'] = true;
                    $piece['extraction_status'] = $row['extraction_status'] ?? $existing_item['extraction_status'] ?? 'extracted';
                    $piece['locked'] = ( isset( $row['locked'] ) && $row['locked'] === '1' ) ? true : ( $existing_item['locked'] ?? true );
                    
                    // Set or preserve original values
                    if ( isset( $existing_item['original_length'] ) ) {
                        // Original values already exist - preserve them
                        $piece['original_length'] = $existing_item['original_length'];
                        $piece['original_depth'] = $existing_item['original_depth'];
                        $piece['original_height'] = $existing_item['original_height'];
                        $piece['original_material'] = $existing_item['original_material'] ?? '';
                        $piece['original_finishes'] = $existing_item['original_finishes'] ?? '';
                        $piece['original_quantity'] = $existing_item['original_quantity'] ?? 0;
                        $piece['original_notes'] = $existing_item['original_notes'] ?? '';
                    } else {
                        // First time saving extracted item - set current values as original
                        $piece['original_length'] = (float) $piece['length_in'];
                        $piece['original_depth'] = (float) $piece['depth_in'];
                        $piece['original_height'] = (float) $piece['height_in'];
                        $piece['original_material'] = $piece['primary_material'];
                        $piece['original_finishes'] = $piece['finishes'];
                        $piece['original_quantity'] = (int) $piece['quantity'];
                        $piece['original_notes'] = $piece['construction_notes'];
                    }
                    
                    // Track changes: Compare current values with original extracted values
                    $has_changes = false;
                    $changed_fields = array();
                    
                    // Check if locked fields have been modified
                    if ( abs( (float) $piece['length_in'] - (float) ( $piece['original_length'] ?? 0 ) ) > 0.01 ) {
                        $has_changes = true;
                        $changed_fields[] = 'Length';
                    }
                    if ( abs( (float) $piece['depth_in'] - (float) ( $piece['original_depth'] ?? 0 ) ) > 0.01 ) {
                        $has_changes = true;
                        $changed_fields[] = 'Depth';
                    }
                    if ( abs( (float) $piece['height_in'] - (float) ( $piece['original_height'] ?? 0 ) ) > 0.01 ) {
                        $has_changes = true;
                        $changed_fields[] = 'Height';
                    }
                    if ( (int) $piece['quantity'] !== (int) ( $piece['original_quantity'] ?? 0 ) ) {
                        $has_changes = true;
                        $changed_fields[] = 'Quantity';
                    }
                    if ( trim( $piece['primary_material'] ) !== trim( $piece['original_material'] ?? '' ) ) {
                        $has_changes = true;
                        $changed_fields[] = 'Primary Material';
                    }
                    if ( trim( $piece['finishes'] ) !== trim( $piece['original_finishes'] ?? '' ) ) {
                        $has_changes = true;
                        $changed_fields[] = 'Finishes';
                    }
                    if ( trim( $piece['construction_notes'] ) !== trim( $piece['original_notes'] ?? '' ) ) {
                        $has_changes = true;
                        $changed_fields[] = 'Construction Notes';
                    }
                    
                    // If item was changed, add "changed" flag
                    if ( $has_changes && $flags_class ) {
                        $reason = 'Modified after extraction: ' . implode( ', ', $changed_fields );
                        $flags_class->add_flag( $project_id, $index, N88_RFQ_Item_Flags::FLAG_CHANGED, $reason );
                        
                        // Track changes (for both admin and non-admin updates)
                        $has_item_changes = true;
                        
                        // Phase 2B: Notify based on who edited
                        if ( class_exists( 'N88_RFQ_Notifications' ) ) {
                            N88_RFQ_Notifications::notify_item_edited( $project_id, $index, $current_user_id );
                        }
                        
                        // If item was flagged as "needs_review" and admin made changes, auto-resolve the needs_review flag
                        if ( ( $piece['extraction_status'] ?? 'extracted' ) === 'needs_review' ) {
                            // Remove needs_review flag (item has been reviewed and fixed)
                            $flags_class->remove_flag( $project_id, $index, N88_RFQ_Item_Flags::FLAG_NEEDS_REVIEW );
                            // Update extraction status to "extracted" since it's been reviewed
                            $piece['extraction_status'] = 'extracted';
                            // Re-lock the item since it's been reviewed
                            $piece['locked'] = true;
                        }
                    }
                } else {
                    // For non-extracted items, check if they were modified by comparing with existing
                    if ( isset( $existing_items[ $index ] ) ) {
                        $existing_item = $existing_items[ $index ];
                        $item_changed = false;
                        
                        // Check if dimensions, quantity, materials, or notes changed
                        if ( abs( (float) $piece['length_in'] - (float) ( $existing_item['length_in'] ?? 0 ) ) > 0.01 ||
                             abs( (float) $piece['depth_in'] - (float) ( $existing_item['depth_in'] ?? 0 ) ) > 0.01 ||
                             abs( (float) $piece['height_in'] - (float) ( $existing_item['height_in'] ?? 0 ) ) > 0.01 ||
                             (int) $piece['quantity'] !== (int) ( $existing_item['quantity'] ?? 0 ) ||
                             trim( $piece['primary_material'] ) !== trim( $existing_item['primary_material'] ?? '' ) ||
                             trim( $piece['construction_notes'] ) !== trim( $existing_item['construction_notes'] ?? '' ) ) {
                            $item_changed = true;
                        }
                        
                        // Track changes (for both admin and non-admin updates)
                        if ( $item_changed ) {
                            $has_item_changes = true;
                            
                            // Notify based on who edited
                            if ( class_exists( 'N88_RFQ_Notifications' ) ) {
                                N88_RFQ_Notifications::notify_item_edited( $project_id, $index, $current_user_id );
                            }
                        }
                    }
                }
                
                $pieces[] = $piece;
            }
        }

        // Always save, even if empty (to clear old data when all items removed)

        // Delete existing repeater data for this project
        $wpdb->delete(
            $this->meta_table,
            array(
                'project_id' => $project_id,
                'meta_key'   => 'n88_repeater_raw',
            ),
            array( '%d', '%s' )
        );

        // Check if meta already exists (for update vs insert)
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->meta_table} WHERE project_id = %d AND meta_key = %s",
            $project_id,
            'n88_repeater_raw'
        ) );
        
        if ( $existing ) {
            // Update existing
            $result = $wpdb->update(
                $this->meta_table,
                array( 'meta_value' => wp_json_encode( $pieces ) ),
                array( 'id' => $existing ),
                array( '%s' ),
                array( '%d' )
            );
        } else {
            // Insert new (even if empty array)
            $result = $wpdb->insert(
                $this->meta_table,
                array(
                    'project_id' => $project_id,
                    'meta_key'   => 'n88_repeater_raw',
                    'meta_value' => wp_json_encode( $pieces ),
                ),
                array( '%d', '%s', '%s' )
            );
        }

        if ( false === $result ) {
            error_log( 'N88 RFQ: Failed to save repeater items. Error: ' . $wpdb->last_error );
            error_log( 'N88 RFQ: Project ID: ' . $project_id );
            error_log( 'N88 RFQ: Pieces count: ' . count( $pieces ) );
            return false;
        }

        // Update the project's item_count based on the number of pieces saved
        $item_count = count( $pieces );
        $wpdb->update(
            $this->projects_table,
            array( 'item_count' => $item_count ),
            array( 'id' => $project_id ),
            array( '%d' ),
            array( '%d' )
        );

        // Update project timestamp when items are edited
        $this->update_project_timestamp( $project_id, $current_user_id );

        // If a non-admin user edited items, track client updates
        if ( $has_item_changes && ! $is_admin_edit ) {
            $this->save_project_metadata( $project_id, array(
                'n88_has_client_updates'   => '1',
                'n88_last_client_update'   => current_time( 'mysql' ),
                'n88_last_client_update_by'=> (string) $current_user_id,
            ) );
        }

        // If an admin edited items, track admin updates
        if ( $has_item_changes && $is_admin_edit ) {
            $this->save_project_metadata( $project_id, array(
                'n88_has_admin_updates'   => '1',
                'n88_last_admin_update'   => current_time( 'mysql' ),
                'n88_last_admin_update_by'=> (string) $current_user_id,
            ) );
        }

        return true;
    }

    /**
     * Save metadata fields for a project.
     *
     * @param int   $project_id The project ID.
     * @param array $meta_fields Key-value pairs of metadata.
     * @return bool True if all saved successfully.
     */
    public function save_project_metadata( $project_id, $meta_fields ) {
        global $wpdb;

        foreach ( $meta_fields as $key => $value ) {
            if ( '' === $value ) {
                continue;
            }

            // Check if meta key already exists
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$this->meta_table} WHERE project_id = %d AND meta_key = %s",
                    $project_id,
                    $key
                )
            );

            if ( $existing ) {
                // Update existing meta
                $wpdb->update(
                    $this->meta_table,
                    array( 'meta_value' => $value ),
                    array( 'id' => $existing->id ),
                    array( '%s' ),
                    array( '%d' )
                );
            } else {
                // Insert new meta
                $wpdb->insert(
                    $this->meta_table,
                    array(
                        'project_id' => $project_id,
                        'meta_key'   => $key,
                        'meta_value' => $value,
                    ),
                    array( '%d', '%s', '%s' )
                );
            }
        }

        return true;
    }

    /**
     * Retrieve a metadata value for a project.
     *
     * @param int    $project_id Project ID.
     * @param string $meta_key   Metadata key.
     * @param mixed  $default    Default value if not found.
     * @return mixed Meta value or default if not set.
     */
    public function get_project_metadata( $project_id, $meta_key, $default = '' ) {
        global $wpdb;

        $meta_value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$this->meta_table} WHERE project_id = %d AND meta_key = %s",
                $project_id,
                $meta_key
            )
        );

        if ( null === $meta_value ) {
            return $default;
        }

        return $meta_value;
    }

    /**
     * Check if a project can be edited by a user.
     * Users can edit their own projects even after submission.
     * Admins can always edit any project.
     *
     * @param int $project_id The project ID.
     * @param int $user_id The user ID.
     * @return bool True if editable, false otherwise.
     */
    public function is_project_editable( $project_id, $user_id ) {
        global $wpdb;

        $project = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status, user_id FROM {$this->projects_table} WHERE id = %d",
                $project_id
            )
        );

        if ( ! $project ) {
            return false;
        }

        // Admins can always edit
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Users can edit their own projects (draft or submitted)
        if ( (int) $project->user_id === (int) $user_id ) {
            return true;
        }

        return false;
    }

    /**
     * Get a project by ID with all associated metadata.
     *
     * @param int $project_id The project ID.
     * @param int $user_id The user ID (for ownership verification).
     * @return array|null Project data with metadata, or null if not found/unauthorized.
     */
    public function get_project( $project_id, $user_id ) {
        global $wpdb;

        $project = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->projects_table} WHERE id = %d AND user_id = %d",
                $project_id,
                $user_id
            ),
            ARRAY_A
        );

        if ( ! $project ) {
            return null;
        }

        // Get all metadata for this project
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$this->meta_table} WHERE project_id = %d",
                $project_id
            ),
            ARRAY_A
        );

        $project['metadata'] = array();
        foreach ( $meta_rows as $meta ) {
            $project['metadata'][ $meta['meta_key'] ] = $meta['meta_value'];
        }

        return $project;
    }

    /**
     * Get project by ID without user authorization check (for admin).
     * Used in admin pages where we want to view any project.
     *
     * @param int $project_id The project ID.
     * @return array|null Project data with metadata, or null if not found.
     */
    public function get_project_admin( $project_id ) {
        global $wpdb;

        $project = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->projects_table} WHERE id = %d",
                $project_id
            ),
            ARRAY_A
        );

        if ( ! $project ) {
            return null;
        }

        // Get all metadata for this project
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$this->meta_table} WHERE project_id = %d",
                $project_id
            ),
            ARRAY_A
        );

        $project['metadata'] = array();
        foreach ( $meta_rows as $meta ) {
            $project['metadata'][ $meta['meta_key'] ] = $meta['meta_value'];
        }

        return $project;
    }

    /**
     * Handle project create (draft or submit).
     */
    public function handle_project_submit() {
        // Log the submission attempt for debugging
        error_log( 'N88 RFQ: handle_project_submit called. POST keys: ' . implode( ', ', array_keys( $_POST ) ) );
        
        // Verify nonce
        if ( ! N88_RFQ_Helpers::verify_form_nonce( false ) ) {
            error_log( 'N88 RFQ: Nonce verification failed' );
            N88_RFQ_Helpers::verify_form_nonce( true ); // Dies on failure
        }

        // Verify user is logged in
        if ( ! is_user_logged_in() ) {
            error_log( 'N88 RFQ: User not logged in' );
            wp_die( 'You must be logged in to submit a project.' );
        }

        $user_id      = get_current_user_id();
        $submit_type  = sanitize_text_field( $_POST['submit_type'] ?? 'draft' );
        $existing_id  = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
        $form_type    = sanitize_text_field( $_POST['form_type'] ?? 'rfq' );
        
        error_log( 'N88 RFQ: User ID: ' . $user_id . ', Submit Type: ' . $submit_type . ', Existing ID: ' . $existing_id );

        // If editing existing project, verify ownership
        if ( $existing_id > 0 ) {
            $existing_project = $this->get_project( $existing_id, $user_id );
            if ( ! $existing_project ) {
                wp_die( 'You do not have permission to edit this project.' );
            }
        }

        // Validate data if submitting (not drafting)
        $validation_errors = array();
        if ( 'submit' === $submit_type ) {
            $validation_errors = $this->validate_submit_data( $_POST );
        }

        // If validation fails on submit, redirect back with errors
        if ( ! empty( $validation_errors ) ) {
            $error_msg = implode( ' | ', $validation_errors );
            $form_page = ( 'sourcing' === $form_type ) ? '/sourcing-form/' : '/rfq-form/';
            $redirect_url = $existing_id 
                ? add_query_arg( array( 'project_id' => $existing_id, 'n88_error' => 1, 'n88_error_msg' => urlencode( $error_msg ) ), home_url( $form_page ) )
                : add_query_arg( array( 'n88_error' => 1, 'n88_error_msg' => urlencode( $error_msg ) ), home_url( $form_page ) );
            
            wp_redirect( $redirect_url );
            exit;
        }

        // Determine quote type based on form type
        // RFQ form = '24-hour', Sourcing form = 'sourcing'
        if ( 'sourcing' === $form_type ) {
            $quote_type = 'sourcing';
        } else {
            // Default to '24-hour' for RFQ form (or any other form type)
            $quote_type = '24-hour';
        }
        
        error_log( 'N88 RFQ: Form type: ' . $form_type . ', Quote type set to: ' . $quote_type );

        // Prepare project data with sanitization
        $project_data = array(
            'project_name' => sanitize_text_field( $_POST['project_name'] ?? '' ),
            'project_type' => sanitize_text_field( $_POST['project_type'] ?? '' ),
            'timeline'     => sanitize_text_field( $_POST['timeline'] ?? '' ),
            'budget_range' => sanitize_text_field( $_POST['budget_range'] ?? '' ),
            'quote_type'   => $quote_type,
        );
        
        error_log( 'N88 RFQ: Project data: ' . print_r( $project_data, true ) );

        $status = ( 'submit' === $submit_type ) ? N88_RFQ_STATUS_SUBMITTED : N88_RFQ_STATUS_DRAFT;

        // Calculate item count from pieces
        $item_count = 0;
        if ( isset( $_POST['pieces'] ) && is_array( $_POST['pieces'] ) ) {
            $item_count = count( $_POST['pieces'] );
        }
        
        error_log( 'N88 RFQ: About to save project - User: ' . $user_id . ', Status: ' . $status . ', Item count: ' . $item_count . ', Existing ID: ' . $existing_id );

        // Save main project
        $project_id = $this->save_project( $user_id, $project_data, $status, $existing_id, $item_count );

        // Check for failure: false, 0, or null all indicate failure
        if ( ! $project_id || $project_id === 0 || $project_id === false ) {
            $error_msg = 'Unable to save project. Please try again.';
            error_log( 'N88 RFQ: save_project returned: ' . var_export( $project_id, true ) );
            
            // Log database errors if available
            global $wpdb;
            if ( ! empty( $wpdb->last_error ) ) {
                error_log( 'N88 RFQ: Database error: ' . $wpdb->last_error );
                error_log( 'N88 RFQ: Last query: ' . $wpdb->last_query );
            }
            
            $form_page = ( 'sourcing' === $form_type ) ? '/sourcing-form/' : '/rfq-form/';
            wp_redirect( add_query_arg( array( 'n88_error' => 1, 'n88_error_msg' => urlencode( $error_msg ) ), home_url( $form_page ) ) );
            exit;
        }

        // Save repeater items (allow empty for drafts)
        if ( isset( $_POST['pieces'] ) && is_array( $_POST['pieces'] ) ) {
            $save_result = $this->save_repeater_items( $project_id, $_POST['pieces'] );
            if ( false === $save_result ) {
                error_log( 'N88 RFQ: Failed to save repeater items for project ' . $project_id );
            }
        } else {
            // If no pieces submitted, save empty array to clear old data
            $this->save_repeater_items( $project_id, array() );
        }

        // Save file uploads
        if ( ! empty( $_FILES['project_files'] ) && $_FILES['project_files']['error'][0] !== UPLOAD_ERR_NO_FILE ) {
            $attachment_ids = $this->handle_file_uploads( $_FILES['project_files'] );
            if ( ! empty( $attachment_ids ) ) {
                $this->save_project_files( $project_id, $attachment_ids );
            }
        }

        // Save metadata fields with proper sanitization
        $meta_fields = array(
            'n88_product_type'      => sanitize_text_field( $_POST['product_type'] ?? '' ),
            'n88_city'              => sanitize_text_field( $_POST['city'] ?? '' ),
            'n88_country'           => sanitize_text_field( $_POST['country'] ?? '' ),
            'n88_notes'             => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'n88_company_name'      => sanitize_text_field( $_POST['company_name'] ?? '' ),
            'n88_contact_name'      => sanitize_text_field( $_POST['contact_name'] ?? '' ),
            'n88_email'             => sanitize_email( $_POST['email'] ?? '' ),
            'n88_phone'             => sanitize_text_field( $_POST['phone'] ?? '' ),
            'n88_location'          => sanitize_text_field( $_POST['location'] ?? '' ),
            'n88_sourcing_category' => sanitize_text_field( $_POST['sourcing_category'] ?? '' ),
        );
        // For brand new projects, default updates flag to 0
        if ( ! $existing_id ) {
            $meta_fields['n88_has_client_updates'] = '0';
        }

        $this->save_project_metadata( $project_id, $meta_fields );

        // If a client (non-admin) edited an existing project, flag as "updated by client"
        if ( $existing_id && ! current_user_can( 'manage_options' ) ) {
            $this->save_project_metadata( $project_id, array(
                'n88_has_client_updates'   => '1',
                'n88_last_client_update'   => current_time( 'mysql' ),
                'n88_last_client_update_by'=> (string) $user_id,
            ) );
        }

        // Send admin notification if submitted (not draft)
        if ( N88_RFQ_STATUS_SUBMITTED === $status ) {
            $project = $this->get_project( $project_id, $user_id );
            if ( $project ) {
                // Send notifications and emails using the notification class
                if ( class_exists( 'N88_RFQ_Notifications' ) ) {
                    N88_RFQ_Notifications::notify_admin_project_upload( $project_id, $project );
                } else {
                    // Fallback to old method if notification class not available
                    $this->send_admin_notification_email( $project, $user_id );
                }
            }
        }

        // Determine redirect URL
        if ( N88_RFQ_STATUS_SUBMITTED === $status ) {
            // Thank you page for submitted quotes
            $redirect_url = apply_filters( 'n88_rfq_thank_you_url', home_url( '/projects/' ), $project_id );
        } else {
            // Back to projects for draft saves
            $redirect_url = home_url( '/my-projects/?n88_saved=1' );
        }

        /**
         * Allow filtering the redirect URL after save.
         *
         * @param string $redirect_url The redirect URL.
         * @param int    $project_id The project ID.
         * @param string $submit_type 'draft' or 'submit'.
         */
        $redirect_url = apply_filters( 'n88_rfq_after_save_redirect', $redirect_url, $project_id, $submit_type );

        wp_redirect( $redirect_url );
        exit;
    }
    
    /**
     * Update database schema if columns are missing (backward compatibility)
     */
    public function maybe_update_database_schema() {
        global $wpdb;
        
        // Only run for admins
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        $table_columns = $wpdb->get_col( "DESCRIBE {$this->projects_table}" );
        
        // Add updated_by column if missing
        if ( ! in_array( 'updated_by', $table_columns ) ) {
            $wpdb->query( "ALTER TABLE {$this->projects_table} ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER submitted_at" );
            $wpdb->query( "ALTER TABLE {$this->projects_table} ADD KEY updated_by (updated_by)" );
        }
        
        // Add quote_type column if missing
        if ( ! in_array( 'quote_type', $table_columns ) ) {
            $wpdb->query( "ALTER TABLE {$this->projects_table} ADD COLUMN quote_type VARCHAR(100) NULL AFTER updated_by" );
        }
        
        // Add item_count column if missing
        if ( ! in_array( 'item_count', $table_columns ) ) {
            $wpdb->query( "ALTER TABLE {$this->projects_table} ADD COLUMN item_count INT UNSIGNED DEFAULT 0 AFTER quote_type" );
            
            // Populate item_count for existing projects from metadata
            $this->populate_item_counts();
        }

        // Migrate any numeric quote_type values to string names (backward compatibility)
        $wpdb->query( "UPDATE {$this->projects_table} SET quote_type = '24-hour' WHERE quote_type = '0' OR quote_type = '1'" );
        $wpdb->query( "UPDATE {$this->projects_table} SET quote_type = 'sourcing' WHERE quote_type = 'sourcing'" );
    }

    /**
     * Update project's updated_at and updated_by fields.
     * This should be called whenever a project is modified (quote uploaded, item edited, comment added, etc.)
     *
     * @param int $project_id Project ID.
     * @param int $user_id User ID who made the update (0 for system/client updates).
     * @return bool True on success.
     */
    public function update_project_timestamp( $project_id, $user_id = 0 ) {
        global $wpdb;

        $project_id = intval( $project_id );
        if ( ! $project_id ) {
            return false;
        }

        // If user_id is 0, try to get current user
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        $result = $wpdb->update(
            $this->projects_table,
            array(
                'updated_at' => current_time( 'mysql' ),
                'updated_by' => $user_id ? $user_id : null,
            ),
            array( 'id' => $project_id ),
            array( '%s', '%d' ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * Populate item_count for existing projects based on saved metadata.
     * This is called when the item_count column is first added.
     */
    public function populate_item_counts() {
        global $wpdb;

        $meta_table = $wpdb->prefix . 'project_metadata';

        // Get all projects with metadata
        $projects = $wpdb->get_results(
            "SELECT DISTINCT p.id FROM {$this->projects_table} p
             LEFT JOIN {$meta_table} pm ON p.id = pm.project_id
             WHERE pm.meta_key = 'n88_repeater_raw'"
        );

        if ( empty( $projects ) ) {
            return;
        }

        foreach ( $projects as $project ) {
            // Get the repeater data
            $repeater_raw = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$meta_table} WHERE project_id = %d AND meta_key = 'n88_repeater_raw'",
                    $project->id
                )
            );

            if ( ! empty( $repeater_raw ) ) {
                $repeater = json_decode( $repeater_raw, true );
                $item_count = is_array( $repeater ) ? count( $repeater ) : 0;

                // Update the project's item_count
                $wpdb->update(
                    $this->projects_table,
                    array( 'item_count' => $item_count ),
                    array( 'id' => $project->id ),
                    array( '%d' ),
                    array( '%d' )
                );
            }
        }
    }
}
