<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Frontend/user dashboard + RFQ form scaffolding.
 */
class N88_RFQ_Frontend {

    public function __construct() {
        add_shortcode( 'n88_rfq_form', array( $this, 'render_rfq_form_shortcode' ) );
        add_shortcode( 'n88_sourcing_form', array( $this, 'render_sourcing_form_shortcode' ) );
        add_shortcode( 'n88_my_projects', array( $this, 'render_my_projects_shortcode' ) );
        add_shortcode( 'n88_project_detail', array( $this, 'render_project_detail_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_form_styles' ) );

        // Also enqueue on admin pages that use our features
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_form_styles' ) );

        // Phase 2A AJAX handlers
        add_action( 'wp_ajax_n88_get_project_modal', array( $this, 'ajax_get_project_modal' ) );
        add_action( 'wp_ajax_nopriv_n88_get_project_modal', array( $this, 'ajax_get_project_modal' ) );
        add_action( 'wp_ajax_n88_add_comment', array( $this, 'ajax_add_comment' ) );
        add_action( 'wp_ajax_nopriv_n88_add_comment', array( $this, 'ajax_add_comment' ) );
        add_action( 'wp_ajax_n88_get_comments', array( $this, 'ajax_get_comments' ) );
        add_action( 'wp_ajax_nopriv_n88_get_comments', array( $this, 'ajax_get_comments' ) );
        add_action( 'wp_ajax_n88_get_project_comments', array( $this, 'ajax_get_project_comments' ) );
        add_action( 'wp_ajax_nopriv_n88_get_project_comments', array( $this, 'ajax_get_project_comments' ) );
        add_action( 'wp_ajax_n88_delete_comment', array( $this, 'ajax_delete_comment' ) );
        add_action( 'wp_ajax_n88_save_item_edit', array( $this, 'ajax_save_item_edit' ) );
        add_action( 'wp_ajax_nopriv_n88_delete_comment', array( $this, 'ajax_delete_comment' ) );
        add_action( 'wp_ajax_n88_get_project_quote', array( $this, 'ajax_get_project_quote' ) );
        add_action( 'wp_ajax_nopriv_n88_get_project_quote', array( $this, 'ajax_get_project_quote' ) );
        add_action( 'wp_ajax_n88_update_client_quote', array( $this, 'ajax_update_client_quote' ) );
        add_action( 'wp_ajax_n88_upload_item_file', array( $this, 'ajax_upload_item_file' ) );
        add_action( 'wp_ajax_nopriv_n88_upload_item_file', array( $this, 'ajax_upload_item_file' ) );
        add_action( 'wp_ajax_n88_get_item_files', array( $this, 'ajax_get_item_files' ) );
        add_action( 'wp_ajax_nopriv_n88_get_item_files', array( $this, 'ajax_get_item_files' ) );
        add_action( 'wp_ajax_n88_delete_item_file', array( $this, 'ajax_delete_item_file' ) );
        add_action( 'wp_ajax_nopriv_n88_delete_item_file', array( $this, 'ajax_delete_item_file' ) );

        // Notification AJAX handlers
        add_action( 'wp_ajax_n88_get_notifications', array( $this, 'ajax_get_notifications' ) );
        add_action( 'wp_ajax_n88_get_unread_count', array( $this, 'ajax_get_unread_count' ) );
        add_action( 'wp_ajax_n88_mark_notification_read', array( $this, 'ajax_mark_notification_read' ) );
        add_action( 'wp_ajax_n88_mark_all_notifications_read', array( $this, 'ajax_mark_all_notifications_read' ) );

        // Phase 2B AJAX handlers - PDF Extraction
        add_action( 'wp_ajax_n88_extract_pdf', array( $this, 'ajax_extract_pdf' ) );
        add_action( 'wp_ajax_nopriv_n88_extract_pdf', array( $this, 'ajax_extract_pdf' ) );
        add_action( 'wp_ajax_n88_confirm_extraction', array( $this, 'ajax_confirm_extraction' ) );
        add_action( 'wp_ajax_nopriv_n88_confirm_extraction', array( $this, 'ajax_confirm_extraction' ) );
        add_action( 'wp_ajax_n88_add_item_flag', array( $this, 'ajax_add_item_flag' ) );
        add_action( 'wp_ajax_nopriv_n88_add_item_flag', array( $this, 'ajax_add_item_flag' ) );
        add_action( 'wp_ajax_n88_remove_item_flag', array( $this, 'ajax_remove_item_flag' ) );
        add_action( 'wp_ajax_nopriv_n88_remove_item_flag', array( $this, 'ajax_remove_item_flag' ) );
            
            // Phase 2B AJAX handlers - Instant Pricing
            add_action( 'wp_ajax_n88_calculate_pricing', array( $this, 'ajax_calculate_pricing' ) );
            add_action( 'wp_ajax_nopriv_n88_calculate_pricing', array( $this, 'ajax_calculate_pricing' ) );
            
            // Create draft project for PDF extraction
            add_action( 'wp_ajax_n88_create_draft_for_pdf', array( $this, 'ajax_create_draft_for_pdf' ) );
            add_action( 'wp_ajax_nopriv_n88_create_draft_for_pdf', array( $this, 'ajax_create_draft_for_pdf' ) );
            
            // Verify project exists
            add_action( 'wp_ajax_n88_verify_project', array( $this, 'ajax_verify_project' ) );
            add_action( 'wp_ajax_nopriv_n88_verify_project', array( $this, 'ajax_verify_project' ) );

        // Schedule cleanup of abandoned PDF extraction attachments
        add_action( 'n88_rfq_cleanup_pdf_extractions', array( $this, 'cleanup_abandoned_pdf_extractions' ) );
        if ( ! wp_next_scheduled( 'n88_rfq_cleanup_pdf_extractions' ) ) {
            wp_schedule_event( time(), 'daily', 'n88_rfq_cleanup_pdf_extractions' );
        }
    }

    /**
     * Enqueue form styles.
     */
    public function enqueue_form_styles() {
        // Use the plugin URL constant if defined, otherwise calculate it
        if ( defined( 'N88_RFQ_PLUGIN_URL' ) ) {
            $plugin_url = trailingslashit( N88_RFQ_PLUGIN_URL );
        } else {
            // Fallback: get plugin URL from main plugin file
            $plugin_file = dirname( dirname( __FILE__ ) ) . '/n88-rfq-platform.php';
            $plugin_url = trailingslashit( plugin_dir_url( $plugin_file ) );
        }
        
        // Build file URLs
        $css_url = $plugin_url . 'assets/css/n88-rfq-form.css';
        $js_url = $plugin_url . 'assets/n88-rfq-modal.js';
            $pdf_js_url = $plugin_url . 'assets/n88-rfq-pdf-extraction.js';
        
        // Verify files exist (for debugging)
        $css_path = dirname( dirname( __FILE__ ) ) . '/assets/css/n88-rfq-form.css';
        $js_path = dirname( dirname( __FILE__ ) ) . '/assets/n88-rfq-modal.js';
            $pdf_js_path = dirname( dirname( __FILE__ ) ) . '/assets/n88-rfq-pdf-extraction.js';
        
        // Enqueue CSS
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style( 
                'n88-rfq-form', 
                $css_url, 
                array(), 
                N88_RFQ_VERSION 
            );
        } else {
            // Debug: Log if file doesn't exist
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'N88 RFQ: CSS file not found at: ' . $css_path );
            }
        }
        
        // Enqueue JavaScript
        if ( file_exists( $js_path ) ) {
            wp_enqueue_script( 
                'n88-rfq-modal', 
                $js_url, 
                    array( 'jquery' ), // Add jQuery as dependency
                N88_RFQ_VERSION, 
                    true // Load in footer
            );

        // Pass AJAX data to JavaScript
        wp_localize_script( 'n88-rfq-modal', 'n88', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => N88_RFQ_Helpers::create_ajax_nonce(),
            ) );
        } else {
            // Debug: Log if file doesn't exist
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'N88 RFQ: JS file not found at: ' . $js_path );
            }
        }
            
            // Enqueue PDF Extraction JavaScript
            if ( file_exists( $pdf_js_path ) ) {
                wp_enqueue_script( 
                    'n88-rfq-pdf-extraction', 
                    $pdf_js_url, 
                    array( 'jquery', 'n88-rfq-modal' ), // Depend on jQuery and main modal script for n88 object
                    N88_RFQ_VERSION, 
                    true // Load in footer
                );
            } else {
                // Debug: Log if file doesn't exist
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'N88 RFQ: PDF extraction JS file not found at: ' . $pdf_js_path );
            }
        }

        // Output modal HTML markup (only once)
        if ( ! has_action( 'wp_footer', array( $this, 'render_modal_markup' ) ) ) {
        add_action( 'wp_footer', array( $this, 'render_modal_markup' ) );
        }
        
        // Add inline critical CSS as fallback (ensures basic styling works)
        add_action( 'wp_head', array( $this, 'add_critical_css' ), 1 );
    }
    
    /**
     * Add critical inline CSS as fallback
     */
    public function add_critical_css() {
        // Only add on pages that might use our shortcodes
        global $post;
        if ( ! is_object( $post ) ) {
            return;
        }
        
        $has_shortcode = has_shortcode( $post->post_content, 'n88_rfq_form' ) ||
                        has_shortcode( $post->post_content, 'n88_sourcing_form' ) ||
                        has_shortcode( $post->post_content, 'n88_my_projects' ) ||
                        has_shortcode( $post->post_content, 'n88_project_detail' );
        
        if ( ! $has_shortcode ) {
            return;
        }
        
        ?>
        <style id="n88-rfq-critical-css">
        /* Critical CSS Fallback - Basic Form Styling */
        .n88-form-wrapper { max-width: 900px; margin: 20px auto; padding: 20px; }
        .n88-form-title { font-size: 28px; font-weight: 700; color: #333; margin: 0 0 8px 0; }
        .n88-message { padding: 16px 20px; border-radius: 6px; margin-bottom: 20px; }
        .n88-message-success { background-color: #e8f5e9; border: 1px solid #4caf50; color: #2e7d32; }
        .n88-message-error { background-color: #ffebee; border: 1px solid #f44336; color: #c62828; }
        #n88-rfq-form fieldset { margin-bottom: 30px; padding: 20px; border: 1px solid #e4e4e4; border-radius: 8px; background: #fafafa; }
        .form-group { margin-bottom: 16px; }
        #n88-rfq-form label { display: block; margin-bottom: 6px; font-weight: 500; color: #333; }
        #n88-rfq-form input[type="text"], #n88-rfq-form input[type="email"], #n88-rfq-form input[type="number"], #n88-rfq-form select, #n88-rfq-form textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .btn { padding: 10px 20px; border: 1px solid transparent; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; }
        .btn-draft { background-color: #4CAF50; color: white; }
        .btn-submit { background-color: #f44336; color: white; }
        </style>
        <?php
    }

    /**
     * Render the RFQ form shortcode.
     */
    public function render_rfq_form_shortcode( $atts = array() ) {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to submit a project.</p>';
        }

        $project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
        $project    = null;
        $message    = '';
        $message_type = '';

        // Load existing project if editing
        if ( $project_id ) {
            $projects_class = new N88_RFQ_Projects();
            $project        = $projects_class->get_project( $project_id, get_current_user_id() );

            if ( ! $project ) {
                return '<p>You are not authorized to view this project.</p>';
            }

                // Check if project can be edited
            if ( ! $projects_class->is_project_editable( $project_id, get_current_user_id() ) ) {
                    return '<p>You do not have permission to edit this project.</p>';
            }
        }

        // Check for success message
        if ( isset( $_GET['n88_saved'] ) ) {
            $message_type = 'success';
            $message = 'Project saved as draft successfully! You can continue editing anytime.';
        }

        // Check for error message
        if ( isset( $_GET['n88_error'] ) ) {
            $message_type = 'error';
            $message = isset( $_GET['n88_error_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['n88_error_msg'] ) ) : 'An error occurred while saving.';
        }

        // Check for rate limit message
        if ( isset( $_GET['n88_rate_limit'] ) ) {
            $message_type = 'error';
            $message = isset( $_GET['n88_error_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['n88_error_msg'] ) ) : 'Rate limit exceeded. Please try again later.';
        }

        ob_start();
        if ( $message ) {
            $this->render_message( $message, $message_type );
        }
        $this->render_form( $project );
        return ob_get_clean();
    }

    /**
     * Render the Sourcing form shortcode.
     * Similar to RFQ form but for general product sourcing requests.
     */
    public function render_sourcing_form_shortcode( $atts = array() ) {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to submit a sourcing request.</p>';
        }

        $project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
        $project    = null;
        $message    = '';
        $message_type = '';

        // Load existing project if editing
        if ( $project_id ) {
            $projects_class = new N88_RFQ_Projects();
            $project        = $projects_class->get_project( $project_id, get_current_user_id() );

            if ( ! $project ) {
                return '<p>You are not authorized to view this project.</p>';
            }

                // Check if project can be edited
            if ( ! $projects_class->is_project_editable( $project_id, get_current_user_id() ) ) {
                    return '<p>You do not have permission to edit this project.</p>';
            }
        }

        // Check for success message
        if ( isset( $_GET['n88_saved'] ) ) {
            $message_type = 'success';
            $message = 'Sourcing request saved as draft successfully! You can continue editing anytime.';
        }

        // Check for error message
        if ( isset( $_GET['n88_error'] ) ) {
            $message_type = 'error';
            $message = isset( $_GET['n88_error_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['n88_error_msg'] ) ) : 'An error occurred while saving.';
        }

        // Check for rate limit message
        if ( isset( $_GET['n88_rate_limit'] ) ) {
            $message_type = 'error';
            $message = isset( $_GET['n88_error_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['n88_error_msg'] ) ) : 'Rate limit exceeded. Please try again later.';
        }

        ob_start();
        if ( $message ) {
            $this->render_message( $message, $message_type );
        }
        $this->render_sourcing_form( $project );
        return ob_get_clean();
    }

    /**
     * Render a message (success/error).
     *
     * @param string $message The message text.
     * @param string $type The message type ('success' or 'error').
     */
    private function render_message( $message, $type = 'success' ) {
        $class = 'success' === $type ? 'n88-message-success' : 'n88-message-error';
        ?>
        <div class="n88-message <?php echo esc_attr( $class ); ?>">
            <?php echo esc_html( $message ); ?>
        </div>
        <?php
    }

    /**
     * Render the form UI.
     *
     * @param array|null $project Existing project data (null if creating new).
     */
    private function render_form( $project = null ) {
        $project_id    = $project['id'] ?? 0;
        $is_edit_mode  = ! empty( $project_id );
        $form_title    = $is_edit_mode ? 'Edit Project' : 'Create New Project';
        $project_name  = $project['project_name'] ?? '';
        $project_type  = $project['project_type'] ?? '';
        $timeline      = $project['timeline'] ?? '';
        $budget_range  = $project['budget_range'] ?? '';
        $metadata      = $project['metadata'] ?? array();
        $company_name  = $metadata['n88_company_name'] ?? '';
        $contact_name  = $metadata['n88_contact_name'] ?? '';
        $email         = $metadata['n88_email'] ?? '';
        $phone         = $metadata['n88_phone'] ?? '';
        $location      = $metadata['n88_location'] ?? '';
        $repeater_json = $metadata['n88_repeater_raw'] ?? '[]';
        $repeater      = is_string( $repeater_json ) ? json_decode( $repeater_json, true ) : array();
            
            // Check if project is in extraction mode
            $is_extraction_mode = false;
            if ( $project_id && class_exists( 'N88_RFQ_PDF_Extractor' ) ) {
                $is_extraction_mode = N88_RFQ_PDF_Extractor::is_extraction_mode( $project_id );
            }
        ?>
        <div class="n88-form-wrapper">
            <h1 class="n88-form-title"><?php echo esc_html( $form_title ); ?></h1>
            <?php if ( $is_edit_mode ) : ?>
                <p class="n88-edit-mode-info">You are editing project <strong><?php echo esc_html( $project_name ); ?></strong></p>
            <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="n88-rfq-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="n88_submit_project">
            <input type="hidden" name="form_type" value="rfq">
            <?php wp_nonce_field( N88_RFQ_Helpers::NONCE_ACTION_FORM, N88_RFQ_Helpers::NONCE_PARAM_FORM ); ?>
                <input type="hidden" name="project_id" id="n88-project-id-input" value="<?php echo esc_attr( $project_id ); ?>">

            <!-- PROJECT HEADER SECTION -->
            <fieldset>
                <legend>Project Information</legend>

                <div class="form-group">
                    <label for="project_name">Project Name <span class="required">*</span></label>
                    <input type="text" id="project_name" name="project_name" value="<?php echo esc_attr( $project_name ); ?>" required>
                </div>

                <div class="form-group">
                    <label for="project_type">Project Type <span class="required">*</span></label>
                    <select id="project_type" name="project_type" required>
                        <option value="">-- Select --</option>
                        <option value="Hotel - Boutique" <?php selected( $project_type, 'Hotel - Boutique' ); ?>>Hotel – Boutique</option>
                        <option value="Hotel - Resort" <?php selected( $project_type, 'Hotel - Resort' ); ?>>Hotel – Resort</option>
                        <option value="Multi-Family / Residential Development" <?php selected( $project_type, 'Multi-Family / Residential Development' ); ?>>Multi-Family / Residential Development</option>
                        <option value="Restaurant / F&B" <?php selected( $project_type, 'Restaurant / F&B' ); ?>>Restaurant / F&B</option>
                        <option value="Commercial Space" <?php selected( $project_type, 'Commercial Space' ); ?>>Commercial Space</option>
                        <option value="Other" <?php selected( $project_type, 'Other' ); ?>>Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="timeline">Timeline <span class="required">*</span></label>
                    <select id="timeline" name="timeline" required>
                        <option value="">-- Select --</option>
                        <option value="ASAP" <?php selected( $timeline, 'ASAP' ); ?>>ASAP</option>
                        <option value="4-6 weeks" <?php selected( $timeline, '4-6 weeks' ); ?>>4–6 weeks</option>
                        <option value="8-12 weeks" <?php selected( $timeline, '8-12 weeks' ); ?>>8–12 weeks</option>
                        <option value="12+ weeks" <?php selected( $timeline, '12+ weeks' ); ?>>12+ weeks</option>
                        <option value="Flexible" <?php selected( $timeline, 'Flexible' ); ?>>Flexible</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="budget_range">Budget Range <span class="required">*</span></label>
                    <select id="budget_range" name="budget_range" required>
                        <option value="">-- Select --</option>
                        <option value="Under $10K" <?php selected( $budget_range, 'Under $10K' ); ?>>Under $10K</option>
                        <option value="$10K - $50K" <?php selected( $budget_range, '$10K - $50K' ); ?>>$10K – $50K</option>
                        <option value="$50K - $250K" <?php selected( $budget_range, '$50K - $250K' ); ?>>$50K – $250K</option>
                        <option value="$250K - $1M" <?php selected( $budget_range, '$250K - $1M' ); ?>>$250K – $1M</option>
                        <option value="$1M+" <?php selected( $budget_range, '$1M+' ); ?>>$1M+</option>
                    </select>
                </div>
            </fieldset>

            <!-- ITEMS REPEATER SECTION -->
            <fieldset>
                <legend>Items / Pieces</legend>
                
                    <!-- PHASE 2B: Entry Mode Toggle (Hidden) -->
                    <div class="n88-entry-mode-selector" style="display: none;">
                    <div class="n88-mode-toggle">
                        <label class="n88-toggle-label">
                            <input type="radio" name="entry_mode" value="manual" class="entry-mode-radio" checked>
                            <span class="n88-toggle-text">
                                <i class="n88-icon-edit"></i> Manual Entry - Enter each item details
                            </span>
                        </label>
                        <label class="n88-toggle-label">
                            <input type="radio" name="entry_mode" value="pdf" class="entry-mode-radio">
                            <span class="n88-toggle-text">
                                <i class="n88-icon-upload"></i> PDF Upload - Extract items automatically
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Manual Entry Mode -->
                <div id="manual-entry-mode" class="n88-entry-mode-content" style="display: block;">
                        <!-- Skip Manual Entry Toggle -->
                        <div class="n88-skip-manual-toggle" style="margin-bottom: 20px; padding: 15px; background: #f5f5f5; border-radius: 6px; border: 1px solid #ddd;">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 500;">
                                <input type="checkbox" id="skip-manual-entry" name="skip_manual_entry" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                                <span>Skip Manual Entry → Use uploaded PDF package</span>
                            </label>
                            <p style="margin: 8px 0 0 0; font-size: 13px; color: #666;">When enabled, manual fields will be disabled and you can upload a PDF package instead.</p>
                </div>

                <!-- PDF Upload Mode -->
                <div id="pdf-upload-mode" class="n88-entry-mode-content" style="display: none;">
                        <div class="n88-pdf-upload-section" style="padding: 20px; background: #f9f9f9; border-radius: 6px; border: 1px solid #ddd;">
                            <p class="n88-pdf-upload-info" style="margin-bottom: 15px; padding: 12px; background: #e3f2fd; border-left: 4px solid #2196F3; border-radius: 4px; color: #1565c0; font-size: 14px;">
                            <i class="n88-icon-info"></i>
                            Upload a PDF containing your furniture specifications. Our system will automatically extract item details.
                        </p>
                        
                        <div class="form-group">
                                <label for="pdf_file_upload" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">PDF File <span class="required" style="color: red;">*</span></label>
                                <div id="n88-pdf-dropzone" class="n88-pdf-dropzone" style="border: 2px dashed #ccc; border-radius: 6px; padding: 40px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: white; min-height: 200px; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative;">
                                <input type="file" id="pdf_file_upload" name="pdf_file_upload" accept=".pdf" class="n88-pdf-input" style="display: none;">
                                    <div class="n88-pdf-dropzone-content" style="pointer-events: none;">
                                        <svg class="n88-pdf-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width: 48px; height: 48px; margin: 0 auto 15px; color: #007cba;">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="12" y1="13" x2="12" y2="19"></line>
                                        <line x1="9" y1="16" x2="15" y2="16"></line>
                                    </svg>
                                        <p class="n88-pdf-dropzone-text" style="font-size: 16px; font-weight: 500; color: #333; margin-bottom: 8px;">Drag & drop PDF here or click to browse</p>
                                        <small style="color: #666; font-size: 12px;">Accepted format: PDF only</small>
                                </div>
                            </div>
                                <div id="n88-pdf-upload-progress" class="n88-pdf-upload-progress" style="display: none; margin-top: 15px;">
                                    <div class="progress-bar" style="width: 100%; height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
                                        <div class="progress-fill" style="height: 100%; background: #007cba; transition: width 0.3s ease; width: 0%;"></div>
                                </div>
                                    <p class="progress-text" style="text-align: center; color: #666; font-size: 14px;">Extracting items from PDF...</p>
                            </div>
                        </div>

                        <!-- Extraction Preview Modal (hidden until PDF uploaded) -->
                        <div id="extraction-preview" class="n88-extraction-preview" style="display: none;">
                            <div class="preview-header">
                                    <h3>We found <span id="items-detected-count" class="count">0</span> items in your PDF</h3>
                                    <p class="preview-subtitle">Review the extracted data below and confirm to import items</p>
                            </div>
                            
                            <div class="preview-table-container">
                                <table class="extraction-preview-table">
                                    <thead>
                                        <tr>
                                                <th>Thumbnail</th>
                                                <th>Item Title</th>
                                            <th>Dimensions</th>
                                            <th>Materials</th>
                                                <th>Quantity</th>
                                                <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="extraction-items-list">
                                        <!-- Items populated by JavaScript -->
                                    </tbody>
                                </table>
                            </div>

                            <div class="preview-actions">
                                    <button type="button" id="confirm-extraction-btn" class="btn btn-primary">Confirm Extraction</button>
                                    <button type="button" id="cancel-extraction-btn" class="btn btn-secondary">Back to Upload</button>
                            </div>
                        </div>
                    </div>
                </div>
                        
                        <div id="pieces-container" class="n88-manual-fields-container">
                    <?php if ( ! empty( $repeater ) ) : ?>
                        <?php foreach ( $repeater as $index => $piece ) : ?>
                                    <?php $this->render_item_fields( $index, $piece, $is_extraction_mode, $project_id ); ?>
                        <?php endforeach; ?>
                    <?php else : ?>
                                <?php $this->render_item_fields( 0, array(), $is_extraction_mode, $project_id ); ?>
                    <?php endif; ?>
                </div>
                        <?php if ( ! $is_extraction_mode ) : ?>
                <button type="button" id="add-item-btn" class="btn btn-secondary">+ Add Another Item</button>
                        <?php endif; ?>
                    </div>

            </fieldset>

            <!-- FILES SECTION -->
            <fieldset>
                <legend>Project Files</legend>
                <div class="form-group">
                    <label for="project_files">Upload Files (PDFs, images, etc.)</label>
                    <div id="n88-file-dropzone" class="n88-file-dropzone">
                        <input type="file" id="project_files" name="project_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.dwg" class="n88-file-input" style="display: none;">
                        <div class="n88-dropzone-content">
                            <svg class="n88-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="17 8 12 3 7 8"></polyline>
                                <line x1="12" y1="3" x2="12" y2="15"></line>
                            </svg>
                            <p class="n88-dropzone-text">Drag & drop files here or click to browse</p>
                            <small>Accepted formats: PDF, JPG, PNG, GIF, DWG</small>
                        </div>
                    </div>
                    <div id="n88-file-list" class="n88-file-list"></div>
                </div>
            </fieldset>

            <!-- CLIENT INFO SECTION -->
            <fieldset>
                <legend>Client Information</legend>

                <div class="form-group">
                    <label for="company_name">Company Name</label>
                    <input type="text" id="company_name" name="company_name" value="<?php echo esc_attr( $company_name ); ?>">
                </div>

                <div class="form-group">
                    <label for="contact_name">Contact Name</label>
                    <input type="text" id="contact_name" name="contact_name" value="<?php echo esc_attr( $contact_name ); ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email <span class="required">*</span></label>
                    <input type="email" id="email" name="email" value="<?php echo esc_attr( $email ); ?>" required>
                </div>

                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo esc_attr( $phone ); ?>">
                </div>

                <div class="form-group">
                    <label for="location">Location / City</label>
                    <input type="text" id="location" name="location" value="<?php echo esc_attr( $location ); ?>">
                </div>
            </fieldset>

            <!-- FORM ACTIONS -->
            <div class="form-actions">
                <button type="submit" name="submit_type" value="draft" class="btn btn-draft">Save as Draft</button>
                <button type="submit" name="submit_type" value="submit" class="btn btn-submit">Submit for Quote</button>
            </div>
        </form>

        <style>
            /* File Upload Styles */
            .n88-file-dropzone {
                border: 2px dashed #007cba;
                border-radius: 8px;
                padding: 40px 20px;
                text-align: center;
                cursor: pointer;
                background-color: #f0f7ff;
                transition: all 0.3s ease;
                margin-bottom: 20px;
            }

            .n88-file-dropzone:hover {
                border-color: #0056b3;
                background-color: #e6f2ff;
            }

            .n88-file-dropzone.dragover {
                border-color: #0056b3;
                background-color: #cce5ff;
            }

            .n88-dropzone-content {
                pointer-events: none;
            }

            .n88-upload-icon {
                width: 48px;
                height: 48px;
                color: #007cba;
                margin-bottom: 12px;
            }

            .n88-dropzone-text {
                font-size: 16px;
                font-weight: 500;
                color: #333;
                margin: 0 0 8px 0;
            }

            .n88-file-list {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 15px;
                margin-top: 20px;
            }

            .n88-file-item {
                position: relative;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 12px;
                text-align: center;
                transition: all 0.3s ease;
            }

            .n88-file-item:hover {
                border-color: #007cba;
                box-shadow: 0 2px 8px rgba(0, 124, 186, 0.2);
            }

            .n88-file-preview {
                display: flex;
                align-items: center;
                justify-content: center;
                height: 80px;
                margin-bottom: 10px;
                background: #f9f9f9;
                border-radius: 4px;
            }

            .n88-file-preview img {
                max-width: 80px;
                max-height: 80px;
                object-fit: cover;
            }

            .n88-file-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 80px;
                height: 80px;
                font-size: 24px;
                font-weight: 600;
                border-radius: 4px;
                color: #fff;
            }

            .n88-file-icon.n88-pdf {
                background: linear-gradient(135deg, #f44336, #e91e63);
            }

            .n88-file-icon.n88-doc {
                background: linear-gradient(135deg, #2196f3, #1976d2);
            }

            .n88-file-icon.n88-dwg {
                background: linear-gradient(135deg, #ff9800, #f57c00);
            }

            .n88-file-info {
                margin-bottom: 8px;
            }

            .n88-file-name {
                font-size: 12px;
                font-weight: 500;
                color: #333;
                word-break: break-word;
                margin-bottom: 4px;
            }

            .n88-file-size {
                font-size: 11px;
                color: #999;
            }

            .n88-file-delete {
                position: absolute;
                top: 5px;
                right: 5px;
                background: #f44336;
                color: white;
                border: none;
                border-radius: 50%;
                width: 24px;
                height: 24px;
                padding: 0;
                font-size: 18px;
                cursor: pointer;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .n88-file-delete:hover {
                background: #d32f2f;
                transform: scale(1.1);
            }

            @media (max-width: 600px) {
                .n88-file-list {
                    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                    gap: 12px;
                }

                .n88-file-dropzone {
                    padding: 30px 15px;
                }
            }
        </style>

        <script>
        (function() {
            let itemCount = <?php echo (int) max( 1, count( $repeater ?? array() ) ); ?>;

                // Initialize item file dropzones (similar to project files)
                function initializeItemFileDropzones() {
                    const items = document.querySelectorAll('.piece-item');
                    items.forEach((item, index) => {
                        const dropzone = document.getElementById('item-file-dropzone-' + index);
                        const fileInput = document.getElementById('item-file-input-' + index);
                        
                        if (!dropzone || !fileInput) return;
                        
                        // Click to browse
                        dropzone.addEventListener('click', () => fileInput.click());
                        
                        // Drag over
                        dropzone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                            dropzone.classList.add('dragover');
                        });
                        
                        dropzone.addEventListener('dragleave', () => {
                            dropzone.classList.remove('dragover');
                        });
                        
                        // Drop
                        dropzone.addEventListener('drop', (e) => {
                            e.preventDefault();
                            dropzone.classList.remove('dragover');
                            handleItemFiles(e.dataTransfer.files, index);
                        });
                        
                        // File input change
                        fileInput.addEventListener('change', (e) => {
                            handleItemFiles(e.target.files, index);
                        });
                    });
                }
                
                // Initialize dropzones when page loads
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initializeItemFileDropzones);
                } else {
                    initializeItemFileDropzones();
                }
                

                // Store pending files for items when project ID doesn't exist
                window.pendingItemFiles = window.pendingItemFiles || {};

                function handleItemFiles(files, itemIndex) {
                    const fileListContainer = document.getElementById('item-files-list-' + itemIndex);
                    const fileInput = document.getElementById('item-file-input-' + itemIndex);
                    if (!fileListContainer || !fileInput) return;

                    // Get project ID
                    const projectIdInput = document.getElementById('n88-project-id-input') || document.querySelector('input[name="project_id"]');
                    const projectId = projectIdInput ? parseInt(projectIdInput.value) : 0;
                    
                    // Process each file
                    Array.from(files).forEach(file => {
                        // If no project ID, store files temporarily and show preview
                        if (!projectId) {
                            if (!window.pendingItemFiles[itemIndex]) {
                                window.pendingItemFiles[itemIndex] = [];
                            }
                            // Store file object for later upload
                            window.pendingItemFiles[itemIndex].push(file);
                            // Show preview (same style as project files)
                            addItemFilePreview(file, fileListContainer, itemIndex, null, true);
                        } else {
                            // If project ID exists, upload files via AJAX immediately
                            const itemId = 'item_' + itemIndex;
                            uploadItemFiles([file], projectId, itemId, itemIndex);
                        }
                    });
                    
                    // Reset file input
                    fileInput.value = '';
                }
                
                function addItemFilePreview(file, container, itemIndex, fileId = null, isPending = false) {
                        const fileItem = document.createElement('div');
                    fileItem.className = 'n88-file-item';
                    if (isPending) {
                        fileItem.classList.add('n88-item-file-pending');
                    }
                    
                    let preview = '';
                    const ext = file.name.split('.').pop().toLowerCase();
                    const fileSize = (file.size / 1024).toFixed(2); // Convert to KB
                    
                    if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            const img = container.querySelector(`[data-file="${file.name}"] .n88-file-preview img`);
                            if (img) {
                                img.src = e.target.result;
                            }
                        };
                        reader.readAsDataURL(file);
                        preview = `<img src="" alt="${file.name}" style="max-width: 80px; max-height: 80px;">`;
                    } else if (ext === 'pdf') {
                        preview = '<div class="n88-file-icon n88-pdf">PDF</div>';
                    } else if (ext === 'dwg') {
                        preview = '<div class="n88-file-icon n88-dwg">DWG</div>';
                    } else {
                        preview = '<div class="n88-file-icon">FILE</div>';
                    }
                    
                    fileItem.setAttribute('data-file', file.name);
                    if (fileId) {
                        fileItem.setAttribute('data-file-id', fileId);
                    }
                    
                const pendingText = isPending ? ' <span style="color: #999; font-size: 11px;">(will upload on save)</span>' : '';
                
                fileItem.innerHTML = `
                    <div class="n88-file-preview">
                        ${preview}
                    </div>
                    <div class="n88-file-info">
                        <div class="n88-file-name">${escapeHtmlForItem(file.name)}</div>
                        <div class="n88-file-size">${fileSize} KB${pendingText}</div>
                    </div>
                    <button type="button" class="n88-file-delete" title="Delete file">&times;</button>
                `;
                    
                    container.appendChild(fileItem);
                }
                
                // Make function globally accessible
                window.removeItemFileFromList = function(itemIndex, fileName, fileId) {
                    const fileListContainer = document.getElementById('item-files-list-' + itemIndex);
                    if (!fileListContainer) return;
                    
                    const fileItem = fileListContainer.querySelector(`[data-file="${fileName}"]`);
                    if (!fileItem) return;
                    
                    // If it's a pending file, remove from pending list
                    if (fileItem.classList.contains('n88-item-file-pending')) {
                        if (window.pendingItemFiles[itemIndex]) {
                            window.pendingItemFiles[itemIndex] = window.pendingItemFiles[itemIndex].filter(file => file.name !== fileName);
                        }
                        fileItem.remove();
                        return;
                    }
                    
                    // If it's an uploaded file, delete via AJAX
                    if (fileId && fileId !== 'null' && fileId !== null) {
                        const projectIdInput = document.getElementById('n88-project-id-input') || document.querySelector('input[name="project_id"]');
                        const projectId = projectIdInput ? parseInt(projectIdInput.value) : 0;
                        const itemId = 'item_' + itemIndex;
                        
                        if (projectId && confirm('Remove this file?')) {
                            fetch(typeof n88 !== 'undefined' ? n88.ajaxUrl : '/wp-admin/admin-ajax.php', {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: new URLSearchParams({
                                    action: 'n88_delete_item_file',
                                    project_id: projectId,
                                    item_id: itemId,
                                    file_id: fileId,
                                    nonce: typeof n88 !== 'undefined' ? n88.nonce : ''
                                })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    fileItem.remove();
                                } else {
                                    alert('Error removing file');
                                }
                            })
                            .catch(error => {
                                console.error('Error removing file:', error);
                                fileItem.remove();
                            });
                        }
                    } else {
                        // Just remove from DOM if no file ID
                        if (confirm('Remove this file?')) {
                            fileItem.remove();
                        }
                    }
                };
                
                // Also use event delegation for better reliability
                document.addEventListener('click', function(e) {
                    if (e.target.classList.contains('n88-file-delete') || e.target.closest('.n88-file-delete')) {
                        const deleteBtn = e.target.classList.contains('n88-file-delete') ? e.target : e.target.closest('.n88-file-delete');
                        const fileItem = deleteBtn.closest('.n88-file-item');
                        if (!fileItem) return;
                        
                        e.preventDefault();
                        e.stopPropagation();
                        
                        const fileName = fileItem.getAttribute('data-file');
                        const fileId = fileItem.getAttribute('data-file-id');
                        
                        // Find item index from the container
                        const fileListContainer = fileItem.closest('.n88-file-list');
                        if (!fileListContainer) return;
                        
                        const containerId = fileListContainer.id;
                        const itemIndexMatch = containerId.match(/item-files-list-(\d+)/);
                        if (!itemIndexMatch) return;
                        
                        const itemIndex = parseInt(itemIndexMatch[1]);
                        
                        // Call the remove function
                        if (typeof window.removeItemFileFromList === 'function') {
                            window.removeItemFileFromList(itemIndex, fileName, fileId);
                        }
                    }
                });
                

                function uploadItemFiles(files, projectId, itemId, itemIndex) {
                    // Validate file types
                    const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 
                                        'application/acad', 'application/x-acad', 'image/vnd.dwg', 
                                        'application/dwg', 'application/x-dwg', 'image/x-dwg'];
                    
                    const validFiles = Array.from(files).filter(file => {
                        return allowedTypes.includes(file.type) || 
                            ['.pdf', '.jpg', '.jpeg', '.png', '.gif', '.dwg'].some(ext => 
                                file.name.toLowerCase().endsWith(ext));
                    });
                    
                    if (validFiles.length === 0) {
                        alert('Please upload only PDF, JPG, PNG, GIF, or DWG files.');
                        return;
                    }
                    
                    const fileListContainer = document.getElementById('item-files-list-' + itemIndex);
                    
                    // Show loading state
                    validFiles.forEach(file => {
                        const fileItem = document.createElement('div');
                        fileItem.className = 'n88-item-file-item n88-item-file-uploading';
                        fileItem.innerHTML = `
                            <div class="n88-item-file-icon">⏳</div>
                            <div class="n88-item-file-info">
                                <div class="n88-item-file-name">${escapeHtmlForItem(file.name)}</div>
                                <div class="n88-item-file-size">Uploading...</div>
                            </div>
                        `;
                        fileListContainer.appendChild(fileItem);
                    });
                    
                    const formData = new FormData();
                    formData.append('action', 'n88_upload_item_file');
                    formData.append('project_id', projectId);
                    formData.append('item_id', itemId);
                    formData.append('nonce', typeof n88 !== 'undefined' ? n88.nonce : '');

                    validFiles.forEach(file => {
                        formData.append('files[]', file);
                    });

                    fetch(typeof n88 !== 'undefined' ? n88.ajaxUrl : '/wp-admin/admin-ajax.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Remove loading items
                        fileListContainer.querySelectorAll('.n88-item-file-uploading').forEach(item => item.remove());
                        
                        if (data.success) {
                            // Show uploaded files using the same style as project files
                            validFiles.forEach((file, idx) => {
                                const fileId = data.data.file_ids && data.data.file_ids[idx] ? data.data.file_ids[idx] : null;
                                // Remove any pending version of this file
                                const pendingItem = fileListContainer.querySelector(`[data-file="${file.name}"].n88-item-file-pending`);
                                if (pendingItem) {
                                    pendingItem.remove();
                                }
                                // Add uploaded version
                                addItemFilePreview(file, fileListContainer, itemIndex, fileId, false);
                            });
                        } else {
                            alert('Error uploading files: ' + (data.data?.message || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        console.error('Error uploading item files:', error);
                        fileListContainer.querySelectorAll('.n88-item-file-uploading').forEach(item => item.remove());
                        alert('Error uploading files. Please try again.');
                    });
                }

                function removeItemFile(button, projectId, itemId, fileId) {
                    if (!confirm('Remove this file?')) return;
                    
                    if (fileId) {
                        // Delete via AJAX if file ID exists
                        fetch(typeof n88 !== 'undefined' ? n88.ajaxUrl : '/wp-admin/admin-ajax.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                action: 'n88_delete_item_file',
                                project_id: projectId,
                                item_id: itemId,
                                file_id: fileId,
                                nonce: typeof n88 !== 'undefined' ? n88.nonce : ''
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                button.closest('.n88-item-file-item').remove();
                            } else {
                                alert('Error removing file');
                            }
                        })
                        .catch(error => {
                            console.error('Error removing file:', error);
                            // Still remove from UI
                            button.closest('.n88-item-file-item').remove();
                        });
                    } else {
                        // Just remove from UI if no file ID
                        button.closest('.n88-item-file-item').remove();
                    }
                }

                function getFileIconForItem(filename) {
                    const ext = filename.split('.').pop().toLowerCase();
                    const icons = {
                        'pdf': '📄',
                        'jpg': '🖼️',
                        'jpeg': '🖼️',
                        'png': '🖼️',
                        'gif': '🖼️',
                        'dwg': '📐'
                    };
                    return icons[ext] || '📎';
                }

                function escapeHtmlForItem(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                // Skip Manual Entry Toggle Handler
                const skipManualToggle = document.getElementById('skip-manual-entry');
                if (skipManualToggle) {
                    skipManualToggle.addEventListener('change', function(e) {
                        const isChecked = e.target.checked;
                        const manualFieldsContainer = document.getElementById('pieces-container');
                        const pdfUploadMode = document.getElementById('pdf-upload-mode');
                        
                        if (manualFieldsContainer) {
                            // Find ALL item fields (using the n88-item-field class)
                            const fieldsToDisable = manualFieldsContainer.querySelectorAll('.n88-item-field');
                            
                            fieldsToDisable.forEach(field => {
                                if (isChecked) {
                                    field.disabled = true;
                                    field.classList.add('n88-field-ghosted');
                                    field.style.backgroundColor = '#E5E5E5';
                                    field.style.color = '#999';
                                    field.style.cursor = 'not-allowed';
                                } else {
                                    field.disabled = false;
                                    field.classList.remove('n88-field-ghosted');
                                    field.style.backgroundColor = '';
                                    field.style.color = '';
                                    field.style.cursor = '';
                                }
                            });
                            
                            // Show/hide the entire manual fields container
                            manualFieldsContainer.style.display = isChecked ? 'none' : 'block';
                            
                            // Hide Files sections when checkbox is checked
                            const filesSections = manualFieldsContainer.querySelectorAll('.n88-item-files-section');
                            filesSections.forEach(section => {
                                section.style.display = isChecked ? 'none' : 'block';
                            });
                            
                            // Also disable the "Add Another Item" button if toggle is ON
                            const addItemBtn = document.getElementById('add-item-btn');
                            if (addItemBtn) {
                                if (isChecked) {
                                    addItemBtn.disabled = true;
                                    addItemBtn.classList.add('n88-field-ghosted');
                                    addItemBtn.style.opacity = '0.5';
                                    addItemBtn.style.cursor = 'not-allowed';
                                    addItemBtn.style.display = 'none';
                                } else {
                                    addItemBtn.disabled = false;
                                    addItemBtn.classList.remove('n88-field-ghosted');
                                    addItemBtn.style.opacity = '';
                                    addItemBtn.style.cursor = '';
                                    addItemBtn.style.display = 'inline-block';
                                }
                            }
                        }
                        
                        // Show inline PDF upload section when checkbox is checked (before disabled fields)
                        const pdfUploadInline = document.getElementById('pdf-upload-mode-inline');
                        if (pdfUploadInline) {
                            if (isChecked) {
                                pdfUploadInline.style.display = 'block';
                                // Initialize PDF upload handlers for inline section
                                setTimeout(() => {
                                    initializeInlinePDFUpload();
                                }, 100);
                            } else {
                                pdfUploadInline.style.display = 'none';
                            }
                        }
                        
                        // Also show/hide the main PDF upload mode section
                        if (pdfUploadMode) {
                            if (isChecked) {
                                pdfUploadMode.style.display = 'block';
                                // Also ensure manual mode radio is selected
                                const manualRadio = document.querySelector('.entry-mode-radio[value="manual"]');
                                if (manualRadio) {
                                    manualRadio.checked = true;
                                }
                            } else {
                                // Only hide PDF upload if we're not in PDF mode
                                const pdfRadio = document.querySelector('.entry-mode-radio[value="pdf"]');
                                if (!pdfRadio || !pdfRadio.checked) {
                                    pdfUploadMode.style.display = 'none';
                                }
                            }
                        }
                        
                        function initializeInlinePDFUpload() {
                            const pdfDropzoneInline = document.getElementById('n88-pdf-dropzone-inline');
                            const pdfInput = document.getElementById('pdf_file_upload');
                            
                            if (!pdfDropzoneInline || !pdfInput) return;
                            
                            // Click handler - use the main PDF input
                            pdfDropzoneInline.addEventListener('click', (e) => {
                                if (e.target !== pdfInput) {
                                    e.preventDefault();
                                    pdfInput.click();
                                }
                            });
                            
                            // Drag and drop handlers
                            pdfDropzoneInline.addEventListener('dragover', (e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                pdfDropzoneInline.style.borderColor = '#007cba';
                                pdfDropzoneInline.style.background = '#e6f2ff';
                            });
                            
                            pdfDropzoneInline.addEventListener('dragleave', (e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                pdfDropzoneInline.style.borderColor = '#ccc';
                                pdfDropzoneInline.style.background = 'white';
                            });
                            
                            pdfDropzoneInline.addEventListener('drop', (e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                pdfDropzoneInline.style.borderColor = '#ccc';
                                pdfDropzoneInline.style.background = 'white';
                                
                                const files = e.dataTransfer.files;
                                if (files.length > 0) {
                                    const file = files[0];
                                    if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
                                        // Use the main PDF input
                                        const dataTransfer = new DataTransfer();
                                        dataTransfer.items.add(file);
                                        pdfInput.files = dataTransfer.files;
                                        
                                        // Trigger change event to use existing handler
                                        const event = new Event('change', { bubbles: true });
                                        pdfInput.dispatchEvent(event);
                                    } else {
                                        alert('Please upload a PDF file only.');
                                    }
                                }
                            });
                        }
                    });
                }
                
                // Show Files sections only for manual entry mode (not PDF extraction)
                function toggleFilesSectionsVisibility() {
                    const entryModeRadios = document.querySelectorAll('.entry-mode-radio');
                    const filesSections = document.querySelectorAll('.n88-item-files-section');
                    const manualFieldsContainer = document.getElementById('pieces-container');
                    const addItemBtn = document.getElementById('add-item-btn');
                    const skipCheckbox = document.getElementById('skip-manual-entry');
                    
                    entryModeRadios.forEach(radio => {
                        radio.addEventListener('change', function() {
                            const isManualMode = this.value === 'manual' && !document.getElementById('skip-manual-entry')?.checked;
                            filesSections.forEach(section => {
                                section.style.display = isManualMode ? 'block' : 'none';
                            });
                            if (manualFieldsContainer) {
                                manualFieldsContainer.style.display = isManualMode ? 'block' : 'none';
                            }
                            if (addItemBtn) {
                                addItemBtn.style.display = isManualMode ? 'inline-block' : 'none';
                            }
                        });
                    });
                    
                    // Initial state - show if manual mode is selected and checkbox is not checked
                    const manualRadio = document.querySelector('.entry-mode-radio[value="manual"]');
                    if (manualRadio && manualRadio.checked && (!skipCheckbox || !skipCheckbox.checked)) {
                        filesSections.forEach(section => {
                            section.style.display = 'block';
                        });
                        if (manualFieldsContainer) {
                            manualFieldsContainer.style.display = 'block';
                        }
                        if (addItemBtn) {
                            addItemBtn.style.display = 'inline-block';
                        }
                    } else {
                        filesSections.forEach(section => {
                            section.style.display = 'none';
                        });
                        if (manualFieldsContainer) {
                            manualFieldsContainer.style.display = 'none';
                        }
                        if (addItemBtn) {
                            addItemBtn.style.display = 'none';
                        }
                    }
                }
                
                // Initialize files sections visibility
                toggleFilesSectionsVisibility();
                
                // Initial state check for Files sections
                setTimeout(function() {
                    const manualRadio = document.querySelector('.entry-mode-radio[value="manual"]');
                    const skipCheckbox = document.getElementById('skip-manual-entry');
                    const filesSections = document.querySelectorAll('.n88-item-files-section');
                    
                    if (manualRadio && manualRadio.checked && (!skipCheckbox || !skipCheckbox.checked)) {
                        filesSections.forEach(section => {
                            section.style.display = 'block';
                        });
                    } else {
                        filesSections.forEach(section => {
                            section.style.display = 'none';
                        });
                    }
                    
                    // Load existing item files if project ID exists
                    const projectIdInput = document.getElementById('n88-project-id-input') || document.querySelector('input[name="project_id"]');
                    const projectId = projectIdInput ? parseInt(projectIdInput.value) : 0;
                    if (projectId > 0) {
                        loadExistingItemFiles(projectId);
                    }
                }, 100);
                
                // Load existing item files for all items
                function loadExistingItemFiles(projectId) {
                    const items = document.querySelectorAll('.piece-item');
                    items.forEach((item, index) => {
                        const itemId = 'item_' + index;
                        const fileListContainer = document.getElementById('item-files-list-' + index);
                        if (!fileListContainer) return;
                        
                        fetch(typeof n88 !== 'undefined' ? n88.ajaxUrl : '/wp-admin/admin-ajax.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                action: 'n88_get_item_files',
                                project_id: projectId,
                                item_id: itemId,
                                nonce: typeof n88 !== 'undefined' ? n88.nonce : ''
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.data && data.data.length > 0) {
                                data.data.forEach(file => {
                                    // Create a file-like object for the preview function
                                    const fileObj = {
                                        name: file.name,
                                        size: file.size || 0,
                                        type: file.type || ''
                                    };
                                    addItemFilePreview(fileObj, fileListContainer, index, file.id, false);
                                });
                            }
                        })
                        .catch(error => {
                            console.error('Error loading item files:', error);
                        });
                    });
                }

                // Upload pending item files after project is created
                function uploadPendingItemFiles(projectId) {
                    if (!window.pendingItemFiles || Object.keys(window.pendingItemFiles).length === 0) {
                        return Promise.resolve();
                    }
                    
                    const uploadPromises = [];
                    
                    Object.keys(window.pendingItemFiles).forEach(itemIndex => {
                        const files = window.pendingItemFiles[itemIndex];
                        if (!files || files.length === 0) return;
                        
                        const itemId = 'item_' + itemIndex;
                        const fileListContainer = document.getElementById('item-files-list-' + itemIndex);
                        
                        files.forEach(file => {
                            const formData = new FormData();
                            formData.append('action', 'n88_upload_item_file');
                            formData.append('project_id', projectId);
                            formData.append('item_id', itemId);
                            formData.append('nonce', typeof n88 !== 'undefined' ? n88.nonce : '');
                            formData.append('files[]', file);
                            
                            uploadPromises.push(
                                fetch(typeof n88 !== 'undefined' ? n88.ajaxUrl : '/wp-admin/admin-ajax.php', {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    body: formData
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success && fileListContainer) {
                                        // Update the pending file item to show it's uploaded
                                        const pendingItem = fileListContainer.querySelector(`[data-file="${file.name}"].n88-item-file-pending`);
                                        if (pendingItem) {
                                            const fileId = data.data.file_ids && data.data.file_ids[0] ? data.data.file_ids[0] : null;
                                            // Remove pending item and add uploaded version
                                            pendingItem.remove();
                                            addItemFilePreview(file, fileListContainer, itemIndex, fileId, false);
                                        }
                                    }
                                })
                                .catch(error => {
                                    console.error('Error uploading pending file:', error);
                                })
                            );
                        });
                    });
                    
                    return Promise.all(uploadPromises);
                }

            // Form validation for submit
            document.getElementById('n88-rfq-form')?.addEventListener('submit', function(e) {
                const submitType = e.submitter?.value;
                    const projectIdInput = document.getElementById('n88-project-id-input') || document.querySelector('input[name="project_id"]');
                    const projectId = projectIdInput ? parseInt(projectIdInput.value) : 0;
                
                // Only validate if submitting (not drafting)
                if ( submitType === 'submit' ) {
                    const projectName = document.getElementById('project_name')?.value?.trim();
                    const projectType = document.getElementById('project_type')?.value?.trim();
                    const timeline = document.getElementById('timeline')?.value?.trim();
                    const budgetRange = document.getElementById('budget_range')?.value?.trim();
                    const email = document.getElementById('email')?.value?.trim();
                    
                    const items = document.querySelectorAll('.piece-item');
                    let hasValidItem = false;
                    
                    items.forEach(item => {
                        const length = item.querySelector('input[name*="[length_in]"]')?.value;
                        const depth = item.querySelector('input[name*="[depth_in]"]')?.value;
                        const height = item.querySelector('input[name*="[height_in]"]')?.value;
                        const quantity = item.querySelector('input[name*="[quantity]"]')?.value;
                        const primaryMaterial = item.querySelector('input[name*="[primary_material]"]')?.value;
                        const finishes = item.querySelector('input[name*="[finishes]"]')?.value;
                        const constructionNotes = item.querySelector('textarea[name*="[construction_notes]"]')?.value;
                        
                        if ( length && depth && height && quantity && primaryMaterial && finishes && constructionNotes ) {
                            hasValidItem = true;
                        }
                    });
                    
                    if ( !projectName || !projectType || !timeline || !budgetRange || !email || !hasValidItem ) {
                            alert('Please fill in all required fields:\n- Project Name\n- Project Type\n- Timeline\n- Budget Range\n- Email\n- At least one complete item (with Dimensions, Quantity, Primary Material, Finishes, and Construction Notes)');
                        e.preventDefault();
                        return false;
                    }
                }
                    
                    // If there are pending files and no project ID, we'll upload them after project is created
                    // Store pending files info in sessionStorage to retrieve after redirect
                    if (window.pendingItemFiles && Object.keys(window.pendingItemFiles).length > 0 && !projectId) {
                        // Store file names and item indices for later upload
                        const pendingFilesInfo = {};
                        Object.keys(window.pendingItemFiles).forEach(itemIndex => {
                            pendingFilesInfo[itemIndex] = window.pendingItemFiles[itemIndex].map(file => ({
                                name: file.name,
                                size: file.size,
                                type: file.type
                            }));
                        });
                        sessionStorage.setItem('n88_pending_item_files', JSON.stringify(pendingFilesInfo));
                    }
                });
                
                // Monitor project ID changes and upload pending files
                function checkAndUploadPendingFiles() {
                    const projectIdInput = document.getElementById('n88-project-id-input') || document.querySelector('input[name="project_id"]');
                    const projectId = projectIdInput ? parseInt(projectIdInput.value) : 0;
                    
                    if (projectId > 0 && window.pendingItemFiles && Object.keys(window.pendingItemFiles).length > 0) {
                        // Upload all pending files
                        uploadPendingItemFiles(projectId).then(() => {
                            // Clear pending files after successful upload
                            window.pendingItemFiles = {};
                        });
                    }
                }
                
                // Check for pending files after page load (if redirected from form submission)
                window.addEventListener('load', function() {
                    setTimeout(checkAndUploadPendingFiles, 500);
                });
                
                // Also check when project ID input changes
                const projectIdInput = document.getElementById('n88-project-id-input') || document.querySelector('input[name="project_id"]');
                if (projectIdInput) {
                    // Use MutationObserver to watch for value changes
                    const observer = new MutationObserver(checkAndUploadPendingFiles);
                    observer.observe(projectIdInput, { attributes: true, attributeFilter: ['value'] });
                    
                    // Also listen for input events
                    projectIdInput.addEventListener('input', checkAndUploadPendingFiles);
                    projectIdInput.addEventListener('change', checkAndUploadPendingFiles);
                }

            document.getElementById('add-item-btn')?.addEventListener('click', function(e) {
                e.preventDefault();
                const container = document.getElementById('pieces-container');
                const newItem = document.createElement('div');
                newItem.className = 'piece-item';
                newItem.innerHTML = `
                    <div class="piece-item-header">
                        <h4>Item ${itemCount + 1}</h4>
                        <button type="button" class="btn btn-remove">Remove</button>
                    </div>
                    <div class="piece-item-fields">
                            <div class="form-group">
                                <label>Primary Material / Upholstery Direction <span class="required">*</span></label>
                                <select name="pieces[${itemCount}][primary_material]" required class="n88-item-field">
                                    <option value="">-- Select --</option>
                                    <optgroup label="Upholstery Options">
                                        <option value="COM (Client's Own Material)">COM (Client's Own Material)</option>
                                        <option value="Fabric (We will provide options)">Fabric (We will provide options)</option>
                                        <option value="Leather">Leather</option>
                                        <option value="Velvet">Velvet</option>
                                        <option value="Performance Fabric (Indoor/Outdoor)">Performance Fabric (Indoor/Outdoor)</option>
                                    </optgroup>
                                    <optgroup label="Outdoor Fabrics">
                                        <option value="Sunbrella (Outdoor)">Sunbrella (Outdoor)</option>
                                        <option value="Perennials Fabric">Perennials Fabric</option>
                                    </optgroup>
                                    <optgroup label="Frame & Structure Materials">
                                        <option value="Powder-Coated Aluminum">Powder-Coated Aluminum</option>
                                        <option value="Metal (Indoor - specify finish in notes)">Metal (Indoor - specify finish in notes)</option>
                                        <option value="All Wood (Indoor - specify finish in notes)">All Wood (Indoor - specify finish in notes)</option>
                                        <option value="Teak (Outdoor)">Teak (Outdoor)</option>
                                        <option value="Woven Rope">Woven Rope</option>
                                    </optgroup>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Product Category <span class="required">*</span></label>
                                <select name="pieces[${itemCount}][product_category]" required class="n88-item-field">
                                    <option value="">-- Select Category --</option>
                                        <option value="Indoor Furniture">Indoor Furniture</option>
                                        <option value="Sofas & Seating (Indoor)">Sofas & Seating (Indoor)</option>
                                        <option value="Chairs & Armchairs (Indoor)">Chairs & Armchairs (Indoor)</option>
                                        <option value="Dining Tables (Indoor)">Dining Tables (Indoor)</option>
                                        <option value="Cabinetry / Millwork (Custom)">Cabinetry / Millwork (Custom)</option>
                                        <option value="Casegoods (Beds, Nightstands, Desks, Consoles)">Casegoods (Beds, Nightstands, Desks, Consoles)</option>
                                        <option value="Outdoor Furniture">Outdoor Furniture</option>
                                        <option value="Outdoor Seating">Outdoor Seating</option>
                                        <option value="Outdoor Dining Sets">Outdoor Dining Sets</option>
                                        <option value="Outdoor Loungers & Daybeds">Outdoor Loungers & Daybeds</option>
                                        <option value="Pool Furniture">Pool Furniture</option>
                                        <option value="Lighting">Lighting</option>
                                    <option value="Decorative Lighting">Decorative Lighting</option>
                                    <option value="Architectural Lighting">Architectural Lighting</option>
                                    <option value="Electrical / LED Components">Electrical / LED Components</option>
                                    <option value="Bathroom Fixtures">Bathroom Fixtures</option>
                                    <option value="Kitchen Fixtures">Kitchen Fixtures</option>
                                    <option value="Faucets / Hardware (Plumbing)">Faucets / Hardware (Plumbing)</option>
                                    <option value="Sinks / Basins">Sinks / Basins</option>
                                    <option value="Shower Systems / Accessories">Shower Systems / Accessories</option>
                                    <option value="Marble / Stone">Marble / Stone</option>
                                    <option value="Granite">Granite</option>
                                    <option value="Quartz">Quartz</option>
                                    <option value="Porcelain / Ceramic Slabs">Porcelain / Ceramic Slabs</option>
                                    <option value="Tile (Wall / Floor)">Tile (Wall / Floor)</option>
                                    <option value="Terrazzo">Terrazzo</option>
                                    <option value="Rugs / Carpets">Rugs / Carpets</option>
                                    <option value="Drapery">Drapery</option>
                                    <option value="Window Treatments / Shades">Window Treatments / Shades</option>
                                    <option value="Wallcoverings">Wallcoverings</option>
                                    <option value="Acoustic Panels">Acoustic Panels</option>
                                    <option value="Mirrors">Mirrors</option>
                                    <option value="Artwork">Artwork</option>
                                    <option value="Decorative Accessories">Decorative Accessories</option>
                                    <option value="Planters">Planters</option>
                                    <option value="Sculptural Objects">Sculptural Objects</option>
                                    <option value="Railings">Railings</option>
                                    <option value="Screens / Louvers">Screens / Louvers</option>
                                    <option value="Pergola / Shade Components">Pergola / Shade Components</option>
                                    <option value="Facade Materials">Facade Materials</option>
                                        <option value="Material Sample Kit">Material Sample Kit</option>
                                        <option value="Fabric Sample">Fabric Sample</option>
                                    <option value="Custom Sourcing / Not Listed">Custom Sourcing / Not Listed</option>
                                </select>
                                <small class="description">This determines the production timeline for this item</small>
                            </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Length (in) <span class="required">*</span></label>
                                    <input type="number" step="0.01" name="pieces[${itemCount}][length_in]" required class="n88-item-field">
                            </div>
                            <div class="form-group">
                                <label>Depth (in) <span class="required">*</span></label>
                                    <input type="number" step="0.01" name="pieces[${itemCount}][depth_in]" required class="n88-item-field">
                            </div>
                            <div class="form-group">
                                <label>Height (in) <span class="required">*</span></label>
                                    <input type="number" step="0.01" name="pieces[${itemCount}][height_in]" required class="n88-item-field">
                            </div>
                            <div class="form-group">
                                <label>Quantity <span class="required">*</span></label>
                                    <input type="number" name="pieces[${itemCount}][quantity]" required class="n88-item-field">
                            </div>
                        </div>
                            <div class="form-group">
                                <label>Construction Notes <span class="required">*</span></label>
                                <textarea name="pieces[${itemCount}][construction_notes]" rows="3" required class="n88-item-field"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Finishes <span class="required">*</span></label>
                                <input type="text" name="pieces[${itemCount}][finishes]" required class="n88-item-field">
                            </div>
                            <div class="form-group n88-item-files-section n88-manual-entry-only" style="display: none;">
                                <label>Files</label>
                                <div id="item-file-dropzone-${itemCount}" class="n88-file-dropzone">
                                    <input type="file" 
                                        id="item-file-input-${itemCount}" 
                                        name="item_files[${itemCount}][]" 
                                        multiple 
                                        accept=".pdf,.jpg,.jpeg,.png,.gif,.dwg" 
                                        class="n88-file-input" 
                                        style="display: none;">
                                    <div class="n88-dropzone-content">
                                        <svg class="n88-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                            <polyline points="17 8 12 3 7 8"></polyline>
                                            <line x1="12" y1="3" x2="12" y2="15"></line>
                                        </svg>
                                        <p class="n88-dropzone-text">Drag & drop files here or click to browse</p>
                                        <small>Accepted formats: PDF, JPG, PNG, GIF, DWG</small>
                                    </div>
                                </div>
                                <div id="item-files-list-${itemCount}" class="n88-file-list"></div>
                            </div>
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="pieces[${itemCount}][notes]" rows="3" class="n88-item-field"></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Cushions</label>
                                    <input type="number" name="pieces[${itemCount}][cushions]" class="n88-item-field">
                            </div>
                            <div class="form-group">
                                <label>Fabric Category</label>
                                    <input type="text" name="pieces[${itemCount}][fabric_category]" class="n88-item-field">
                            </div>
                            <div class="form-group">
                                <label>Frame Material</label>
                                    <input type="text" name="pieces[${itemCount}][frame_material]" class="n88-item-field">
                            </div>
                            <div class="form-group">
                                <label>Finish</label>
                                    <input type="text" name="pieces[${itemCount}][finish]" class="n88-item-field">
                            </div>
                        </div>
                    </div>
                `;
                container.appendChild(newItem);
                    
                    // Show Files section for the new item (if manual entry mode and skip checkbox is not checked)
                    const skipCheckbox = document.getElementById('skip-manual-entry');
                    const isSkipChecked = skipCheckbox && skipCheckbox.checked;
                    const manualRadio = document.querySelector('.entry-mode-radio[value="manual"]');
                    const isManualMode = manualRadio && manualRadio.checked;
                    
                    const filesSection = newItem.querySelector('.n88-item-files-section');
                    if (filesSection) {
                        // Show if manual mode is selected and skip checkbox is not checked
                        if (isManualMode && !isSkipChecked) {
                            filesSection.style.display = 'block';
                        } else {
                            filesSection.style.display = 'none';
                        }
                    }
                    
                    // Initialize dropzone for the new item
                    setTimeout(() => {
                        initializeItemFileDropzones();
                    }, 50);
                    
                itemCount++;
                attachRemoveListener(newItem.querySelector('.btn-remove'));
            });

            function attachRemoveListener(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    this.closest('.piece-item').remove();
                });
            }

            // Attach listeners to existing remove buttons
            document.querySelectorAll('.piece-item .btn-remove').forEach(btn => {
                attachRemoveListener(btn);
            });

                // Phase 2B: PDF extraction is now handled by n88-rfq-pdf-extraction.js
                // The separate file handles all PDF upload, extraction preview, and confirmation

                // File Upload Drag & Drop Handler (for project files, not PDF extraction)
            const dropzone = document.getElementById('n88-file-dropzone');
            const fileInput = document.getElementById('project_files');
            const fileList = document.getElementById('n88-file-list');

            if (dropzone && fileInput) {
                // Click to browse
                dropzone.addEventListener('click', () => fileInput.click());

                // Drag over
                dropzone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    dropzone.classList.add('dragover');
                });

                dropzone.addEventListener('dragleave', () => {
                    dropzone.classList.remove('dragover');
                });

                // Drop
                dropzone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    dropzone.classList.remove('dragover');
                    handleFiles(e.dataTransfer.files);
                });

                // File input change
                fileInput.addEventListener('change', (e) => {
                    handleFiles(e.target.files);
                });
            }

            function handleFiles(files) {
                const fileListContainer = document.getElementById('n88-file-list');
                for (let file of files) {
                    addFilePreview(file, fileListContainer);
                }
                // Reset file input
                fileInput.value = '';
            }

            function addFilePreview(file, container) {
                const fileItem = document.createElement('div');
                fileItem.className = 'n88-file-item';
                
                let preview = '';
                const ext = file.name.split('.').pop().toLowerCase();
                const fileSize = (file.size / 1024).toFixed(2); // Convert to KB

                if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const img = container.querySelector(`[data-file="${file.name}"] .n88-file-preview img`);
                        if (img) {
                            img.src = e.target.result;
                        }
                    };
                    reader.readAsDataURL(file);
                    preview = `<img src="" alt="${file.name}" style="max-width: 80px; max-height: 80px;">`;
                } else if (ext === 'pdf') {
                    preview = '<div class="n88-file-icon n88-pdf">PDF</div>';
                } else if (['doc', 'docx'].includes(ext)) {
                    preview = '<div class="n88-file-icon n88-doc">DOC</div>';
                } else if (ext === 'dwg') {
                    preview = '<div class="n88-file-icon n88-dwg">DWG</div>';
                } else {
                    preview = '<div class="n88-file-icon">FILE</div>';
                }

                fileItem.setAttribute('data-file', file.name);
                fileItem.innerHTML = `
                    <div class="n88-file-preview">
                        ${preview}
                    </div>
                    <div class="n88-file-info">
                        <div class="n88-file-name">${file.name}</div>
                        <div class="n88-file-size">${fileSize} KB</div>
                    </div>
                    <button type="button" class="n88-file-delete" title="Delete file">&times;</button>
                `;

                // Delete button handler
                fileItem.querySelector('.n88-file-delete').addEventListener('click', (e) => {
                    e.preventDefault();
                    fileItem.remove();
                });

                container.appendChild(fileItem);
            }
                
                // Admin Flagging Controls - Handle flag button clicks
                document.addEventListener('click', function(e) {
                    if ( e.target.classList.contains( 'n88-flag-btn' ) ) {
                        e.preventDefault();
                        const btn = e.target;
                        const projectId = btn.getAttribute( 'data-project-id' );
                        const itemIndex = btn.getAttribute( 'data-item-index' );
                        const flagType = btn.getAttribute( 'data-flag-type' );
                        const isRemoveAll = btn.classList.contains( 'n88-flag-remove-all' );
                        
                        if ( ! projectId || itemIndex === null ) {
                            alert( 'Error: Missing project or item information.' );
                            return;
                        }
                        
                        // Handle "Clear Flags" button
                        if ( isRemoveAll ) {
                            if ( ! confirm( 'Clear all flags for this item?' ) ) {
                                return;
                            }
                            
                            // Remove both needs_review and urgent flags
                            const flagsToRemove = [ 'needs_review', 'urgent' ];
                            let removedCount = 0;
                            
                            flagsToRemove.forEach( function( flag ) {
                                fetch( n88.ajaxUrl, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams( {
                                        action: 'n88_remove_item_flag',
                                        project_id: projectId,
                                        item_index: itemIndex,
                                        flag_type: flag,
                                        nonce: n88.nonce
                                    } )
                                } )
                                .then( response => response.json() )
                                .then( data => {
                                    removedCount++;
                                    if ( removedCount === flagsToRemove.length ) {
                                        location.reload(); // Reload to show updated flags
                                    }
                                } )
                                .catch( error => {
                                    console.error( 'Error removing flag:', error );
                                    alert( 'Error removing flag. Please try again.' );
                                } );
                            } );
                            return;
                        }
                        
                        // Handle individual flag buttons (needs_review, urgent)
                        if ( ! flagType ) {
                            alert( 'Error: Flag type not specified.' );
                            return;
                        }
                        
                        const isAdding = ! btn.textContent.includes( '✓' );
                        const action = isAdding ? 'n88_add_item_flag' : 'n88_remove_item_flag';
                        const reason = isAdding ? ( prompt( 'Enter reason for flagging (optional):' ) || '' ) : '';
                        
                        fetch( n88.ajaxUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams( {
                                action: action,
                                project_id: projectId,
                                item_index: itemIndex,
                                flag_type: flagType,
                                reason: reason,
                                nonce: n88.nonce
                            } )
                        } )
                        .then( response => response.json() )
                        .then( data => {
                            if ( data.success ) {
                                location.reload(); // Reload to show updated flags
                            } else {
                                alert( 'Error: ' + ( data.data?.message || 'Failed to update flag.' ) );
                            }
                        } )
                        .catch( error => {
                            console.error( 'Error updating flag:', error );
                            alert( 'Error updating flag. Please try again.' );
                        } );
                    }
                } );
        })();
        </script>
        <?php
    }

    /**
     * Render individual item fields.
     *
     * @param int   $index Item index.
     * @param array $piece Item data.
         * @param bool  $is_extraction_mode Whether project is in extraction mode.
     */
        private function render_item_fields( $index = 0, $piece = array(), $is_extraction_mode = false, $project_id = 0 ) {
        $length_in         = $piece['length_in'] ?? '';
        $depth_in          = $piece['depth_in'] ?? '';
        $height_in        = $piece['height_in'] ?? '';
        $quantity          = $piece['quantity'] ?? '';
        $primary_material  = $piece['primary_material'] ?? '';
        $finishes          = $piece['finishes'] ?? '';
        $construction_notes = $piece['construction_notes'] ?? '';
        $cushions          = $piece['cushions'] ?? '';
        $fabric_category   = $piece['fabric_category'] ?? '';
        $frame_material    = $piece['frame_material'] ?? '';
        $finish            = $piece['finish'] ?? '';
        $notes             = $piece['notes'] ?? '';
            
            // Phase 2B: Check if item is extracted and locked
            $is_extracted = ! empty( $piece['extracted'] );
            $is_locked = ! empty( $piece['locked'] ) && $is_extraction_mode;
            $extraction_status = $piece['extraction_status'] ?? 'extracted';
            $needs_review = ( 'needs_review' === $extraction_status );
            
            // If needs review, unlock ALL fields for editing
            // Otherwise, only lock specific fields: Dimensions, Materials, Finishes, Quantity, Construction Notes
            $is_locked = $is_locked && ! $needs_review;
            
            // Define which fields should be locked (only for extracted items, not needs review)
            $locked_fields = array(
                'length_in' => $is_locked,
                'depth_in' => $is_locked,
                'height_in' => $is_locked,
                'quantity' => $is_locked,
                'primary_material' => $is_locked,
                'product_category' => $is_locked,
                'finishes' => $is_locked,
                'construction_notes' => $is_locked,
                // These fields are NEVER locked (always editable):
                'cushions' => false,
                'fabric_category' => false,
                'frame_material' => false,
                'finish' => false,
                'notes' => false,
            );
            
            // Handle new extraction format (dimensions as array)
            if ( isset( $piece['dimensions'] ) && is_array( $piece['dimensions'] ) ) {
                $length_in = $piece['dimensions']['length'] ?? $length_in;
                $depth_in = $piece['dimensions']['depth'] ?? $depth_in;
                $height_in = $piece['dimensions']['height'] ?? $height_in;
            }
            
            // Handle materials as array
            if ( isset( $piece['materials'] ) && is_array( $piece['materials'] ) ) {
                $primary_material = implode( ', ', $piece['materials'] );
            }
            
            // Handle finishes from extraction
            if ( empty( $finishes ) && ! empty( $piece['finishes'] ) ) {
                $finishes = $piece['finishes'];
            }
            ?>
            <div class="piece-item <?php echo $is_extracted ? 'n88-item-extracted' : ''; ?> <?php echo $needs_review ? 'n88-item-needs-review' : ''; ?>">
            <div class="piece-item-header">
                    <h4>
                        Item <?php echo (int) $index + 1; ?>
                        <?php if ( $is_extracted ) : ?>
                            <span class="n88-extraction-badge n88-badge-extracted">✔ Extracted</span>
                        <?php endif; ?>
                        <?php if ( $needs_review ) : ?>
                            <span class="n88-extraction-badge n88-badge-review">■ Needs Review</span>
                        <?php endif; ?>
                    </h4>
                    <?php if ( ! $is_extraction_mode ) : ?>
                <button type="button" class="btn btn-remove">Remove</button>
                    <?php endif; ?>
            </div>
            <div class="piece-item-fields">
                    <!-- 1. Primary Material -->
                    <div class="form-group">
                        <label>Primary Material / Upholstery Direction <span class="required">*</span></label>
                        <?php $field_locked = $locked_fields['primary_material']; ?>
                        <select name="pieces[<?php echo (int) $index; ?>][primary_material]" <?php echo $field_locked ? 'readonly' : 'required'; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                            <option value="">-- Select --</option>
                            <optgroup label="Upholstery Options">
                                <option value="COM (Client's Own Material)" <?php selected( $primary_material, "COM (Client's Own Material)" ); ?>>COM (Client's Own Material)</option>
                                <option value="Fabric (We will provide options)" <?php selected( $primary_material, "Fabric (We will provide options)" ); ?>>Fabric (We will provide options)</option>
                                <option value="Leather" <?php selected( $primary_material, "Leather" ); ?>>Leather</option>
                                <option value="Velvet" <?php selected( $primary_material, "Velvet" ); ?>>Velvet</option>
                                <option value="Performance Fabric (Indoor/Outdoor)" <?php selected( $primary_material, "Performance Fabric (Indoor/Outdoor)" ); ?>>Performance Fabric (Indoor/Outdoor)</option>
                            </optgroup>
                            <optgroup label="Outdoor Fabrics">
                                <option value="Sunbrella (Outdoor)" <?php selected( $primary_material, "Sunbrella (Outdoor)" ); ?>>Sunbrella (Outdoor)</option>
                                <option value="Perennials Fabric" <?php selected( $primary_material, "Perennials Fabric" ); ?>>Perennials Fabric</option>
                            </optgroup>
                            <optgroup label="Frame & Structure Materials">
                                <option value="Powder-Coated Aluminum" <?php selected( $primary_material, "Powder-Coated Aluminum" ); ?>>Powder-Coated Aluminum</option>
                                <option value="Metal (Indoor - specify finish in notes)" <?php selected( $primary_material, "Metal (Indoor - specify finish in notes)" ); ?>>Metal (Indoor - specify finish in notes)</option>
                                <option value="All Wood (Indoor - specify finish in notes)" <?php selected( $primary_material, "All Wood (Indoor - specify finish in notes)" ); ?>>All Wood (Indoor - specify finish in notes)</option>
                                <option value="Teak (Outdoor)" <?php selected( $primary_material, "Teak (Outdoor)" ); ?>>Teak (Outdoor)</option>
                                <option value="Woven Rope" <?php selected( $primary_material, "Woven Rope" ); ?>>Woven Rope</option>
                            </optgroup>
                        </select>
                    </div>
                    
                    <!-- Product Category -->
                    <div class="form-group">
                        <label>Product Category <span class="required">*</span></label>
                        <?php 
                        $product_category = isset( $piece['product_category'] ) ? $piece['product_category'] : '';
                        $field_locked = $locked_fields['product_category'] ?? false;
                        ?>
                        <select name="pieces[<?php echo (int) $index; ?>][product_category]" <?php echo $field_locked ? 'readonly' : 'required'; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                            <option value="">-- Select Category --</option>
                            <optgroup label="Indoor Furniture (6-Step Timeline)">
                                <option value="Indoor Furniture" <?php selected( $product_category, 'Indoor Furniture' ); ?>>Indoor Furniture</option>
                                <option value="Sofas & Seating (Indoor)" <?php selected( $product_category, 'Sofas & Seating (Indoor)' ); ?>>Sofas & Seating (Indoor)</option>
                                <option value="Chairs & Armchairs (Indoor)" <?php selected( $product_category, 'Chairs & Armchairs (Indoor)' ); ?>>Chairs & Armchairs (Indoor)</option>
                                <option value="Dining Tables (Indoor)" <?php selected( $product_category, 'Dining Tables (Indoor)' ); ?>>Dining Tables (Indoor)</option>
                                <option value="Cabinetry / Millwork (Custom)" <?php selected( $product_category, 'Cabinetry / Millwork (Custom)' ); ?>>Cabinetry / Millwork (Custom)</option>
                                <option value="Casegoods (Beds, Nightstands, Desks, Consoles)" <?php selected( $product_category, 'Casegoods (Beds, Nightstands, Desks, Consoles)' ); ?>>Casegoods (Beds, Nightstands, Desks, Consoles)</option>
                            </optgroup>
                            <optgroup label="Outdoor Furniture (6-Step Timeline)">
                                <option value="Outdoor Furniture" <?php selected( $product_category, 'Outdoor Furniture' ); ?>>Outdoor Furniture</option>
                                <option value="Outdoor Seating" <?php selected( $product_category, 'Outdoor Seating' ); ?>>Outdoor Seating</option>
                                <option value="Outdoor Dining Sets" <?php selected( $product_category, 'Outdoor Dining Sets' ); ?>>Outdoor Dining Sets</option>
                                <option value="Outdoor Loungers & Daybeds" <?php selected( $product_category, 'Outdoor Loungers & Daybeds' ); ?>>Outdoor Loungers & Daybeds</option>
                                <option value="Pool Furniture" <?php selected( $product_category, 'Pool Furniture' ); ?>>Pool Furniture</option>
                            </optgroup>
                            <optgroup label="Sourcing (4-Step Timeline)">
                                <option value="Lighting" <?php selected( $product_category, 'Lighting' ); ?>>Lighting</option>
                            </optgroup>
                            <optgroup label="Other">
                                <option value="Material Sample Kit" <?php selected( $product_category, 'Material Sample Kit' ); ?>>Material Sample Kit</option>
                                <option value="Fabric Sample" <?php selected( $product_category, 'Fabric Sample' ); ?>>Fabric Sample</option>
                            </optgroup>
                        </select>
                        <small class="description">This determines the production timeline for this item</small>
                    </div>
                    
                    <!-- 2. Dimensions -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Length (in) <span class="required">*</span></label>
                            <?php $field_locked = $locked_fields['length_in']; ?>
                            <input type="number" step="0.01" name="pieces[<?php echo (int) $index; ?>][length_in]" value="<?php echo esc_attr( $length_in ); ?>" <?php echo $field_locked ? 'readonly' : 'required'; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                            <?php if ( $field_locked ) : ?>
                                <small class="n88-locked-hint">Locked (extracted from PDF)</small>
                            <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Depth (in) <span class="required">*</span></label>
                            <?php $field_locked = $locked_fields['depth_in']; ?>
                            <input type="number" step="0.01" name="pieces[<?php echo (int) $index; ?>][depth_in]" value="<?php echo esc_attr( $depth_in ); ?>" <?php echo $field_locked ? 'readonly' : 'required'; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                    </div>
                    <div class="form-group">
                        <label>Height (in) <span class="required">*</span></label>
                            <?php $field_locked = $locked_fields['height_in']; ?>
                            <input type="number" step="0.01" name="pieces[<?php echo (int) $index; ?>][height_in]" value="<?php echo esc_attr( $height_in ); ?>" <?php echo $field_locked ? 'readonly' : 'required'; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                    </div>
                    <div class="form-group">
                        <label>Quantity <span class="required">*</span></label>
                        <?php $field_locked = $locked_fields['quantity']; ?>
                            <input type="number" name="pieces[<?php echo (int) $index; ?>][quantity]" value="<?php echo esc_attr( $quantity ); ?>" <?php echo $field_locked ? 'readonly' : 'required'; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                    </div>
                </div>
                    
                    <!-- 4. Construction Notes -->
                    <div class="form-group">
                        <label>Construction Notes <span class="required">*</span></label>
                        <?php $field_locked = $locked_fields['construction_notes']; ?>
                        <textarea name="pieces[<?php echo (int) $index; ?>][construction_notes]" rows="3" <?php echo $field_locked ? 'readonly' : 'required'; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field"><?php echo esc_textarea( $construction_notes ); ?></textarea>
                    </div>
                    
                    <!-- 5. Finishes -->
                    <div class="form-group">
                        <label>Finishes <span class="required">*</span></label>
                        <?php $field_locked = $locked_fields['finishes']; ?>
                        <input type="text" name="pieces[<?php echo (int) $index; ?>][finishes]" value="<?php echo esc_attr( $finishes ); ?>" <?php echo $field_locked ? 'readonly' : 'required'; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                    </div>
                    
                    <!-- 6. Files (only show for manual entry mode) -->
                    <div class="form-group n88-item-files-section n88-manual-entry-only" style="display: none;">
                        <label>Files</label>
                        <div id="item-file-dropzone-<?php echo (int) $index; ?>" class="n88-file-dropzone">
                                <input type="file" 
                                    id="item-file-input-<?php echo (int) $index; ?>" 
                                    name="item_files[<?php echo (int) $index; ?>][]" 
                                    multiple 
                                    accept=".pdf,.jpg,.jpeg,.png,.gif,.dwg" 
                                class="n88-file-input" 
                                style="display: none;">
                            <div class="n88-dropzone-content">
                                <svg class="n88-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                        <polyline points="17 8 12 3 7 8"></polyline>
                                        <line x1="12" y1="3" x2="12" y2="15"></line>
                                    </svg>
                                <p class="n88-dropzone-text">Drag & drop files here or click to browse</p>
                                <small>Accepted formats: PDF, JPG, PNG, GIF, DWG</small>
                                </div>
                            </div>
                        <div id="item-files-list-<?php echo (int) $index; ?>" class="n88-file-list"></div>
                    </div>
                    
                    <!-- 7. Notes -->
                    <div class="form-group">
                        <label>Notes</label>
                        <?php $field_locked = $locked_fields['notes']; ?>
                        <textarea name="pieces[<?php echo (int) $index; ?>][notes]" rows="3" class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field"><?php echo esc_textarea( $notes ); ?></textarea>
                    </div>
                    
                    <!-- Additional optional fields (Cushions, Fabric Category, Frame Material, Finish) -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Cushions</label>
                            <?php $field_locked = $locked_fields['cushions']; ?>
                            <input type="number" name="pieces[<?php echo (int) $index; ?>][cushions]" value="<?php echo esc_attr( $cushions ); ?>" <?php echo $field_locked ? 'readonly' : ''; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                    </div>
                    <div class="form-group">
                        <label>Fabric Category</label>
                            <?php $field_locked = $locked_fields['fabric_category']; ?>
                            <input type="text" name="pieces[<?php echo (int) $index; ?>][fabric_category]" value="<?php echo esc_attr( $fabric_category ); ?>" <?php echo $field_locked ? 'readonly' : ''; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                    </div>
                    <div class="form-group">
                        <label>Frame Material</label>
                            <?php $field_locked = $locked_fields['frame_material']; ?>
                            <input type="text" name="pieces[<?php echo (int) $index; ?>][frame_material]" value="<?php echo esc_attr( $frame_material ); ?>" <?php echo $field_locked ? 'readonly' : ''; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                    </div>
                    <div class="form-group">
                        <label>Finish</label>
                            <?php $field_locked = $locked_fields['finish']; ?>
                            <input type="text" name="pieces[<?php echo (int) $index; ?>][finish]" value="<?php echo esc_attr( $finish ); ?>" <?php echo $field_locked ? 'readonly' : ''; ?> class="<?php echo $field_locked ? 'n88-field-locked' : ''; ?> n88-item-field">
                    </div>
                </div>
                    
                    <?php if ( $is_extracted && $needs_review ) : ?>
                        <div class="n88-item-review-notice" style="margin-top: 15px; padding: 12px; background: #fff3e0; border-left: 4px solid #e65100; border-radius: 4px;">
                            <p style="margin: 0; color: #e65100;"><strong>⚠ This item needs review.</strong> You can edit the fields above to correct any issues.</p>
                </div>
                    <?php endif; ?>
                    
                    <?php 
                    // Get project ID from form or URL
                    $current_project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
                    if ( ! $current_project_id ) {
                        $current_project_id = isset( $project_id ) ? (int) $project_id : 0;
                    }
                    if ( ! $current_project_id ) {
                        // Try to get from hidden input
                        $project_id_input = $GLOBALS['n88_current_project_id'] ?? 0;
                        $current_project_id = $project_id_input;
                    }
                    
                    if ( $is_extracted && current_user_can( 'manage_options' ) && $current_project_id > 0 ) : 
                        // Get current flags for this item
                        $item_flags = array();
                        if ( class_exists( 'N88_RFQ_Item_Flags' ) ) {
                            $flags_class = new N88_RFQ_Item_Flags();
                            $item_flags = $flags_class->get_item_flags( $current_project_id, $index );
                        }
                        $has_urgent_flag = isset( $item_flags['urgent'] ) && ! empty( $item_flags['urgent'] );
                        $has_needs_review_flag = isset( $item_flags['needs_review'] ) && ! empty( $item_flags['needs_review'] );
                        $has_changed_flag = isset( $item_flags['changed'] ) && ! empty( $item_flags['changed'] );
                    ?>
                        <!-- Admin Flagging Controls -->
                        <div class="n88-admin-flag-controls" style="margin-top: 15px; padding: 12px; background: #f5f5f5; border-radius: 4px; border: 1px solid #ddd;">
                            <p style="margin: 0 0 10px 0; font-weight: 600; color: #333;">Admin Actions:</p>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button type="button" class="button n88-flag-btn n88-flag-needs-review" data-project-id="<?php echo esc_attr( $project_id ); ?>" data-item-index="<?php echo esc_attr( $index ); ?>" data-flag-type="needs_review" style="<?php echo $has_needs_review_flag ? 'background: #fff3e0; border-color: #e65100; color: #e65100;' : ''; ?>">
                                    <?php echo $has_needs_review_flag ? '✓ ' : ''; ?>Flag: Needs Review
                                </button>
                                <button type="button" class="button n88-flag-btn n88-flag-urgent" data-project-id="<?php echo esc_attr( $project_id ); ?>" data-item-index="<?php echo esc_attr( $index ); ?>" data-flag-type="urgent" style="<?php echo $has_urgent_flag ? 'background: #ffebee; border-color: #c62828; color: #c62828;' : ''; ?>">
                                    <?php echo $has_urgent_flag ? '✓ ' : ''; ?>Flag: Urgent
                                </button>
                                <?php if ( $has_needs_review_flag || $has_urgent_flag ) : ?>
                                    <button type="button" class="button n88-flag-btn n88-flag-remove-all" data-project-id="<?php echo esc_attr( $project_id ); ?>" data-item-index="<?php echo esc_attr( $index ); ?>" style="background: #e8f5e9; border-color: #2e7d32; color: #2e7d32;">
                                        Clear Flags
                                    </button>
                                <?php endif; ?>
                </div>
                            <?php if ( $has_changed_flag ) : ?>
                                <p style="margin: 10px 0 0 0; font-size: 12px; color: #1976d2;">
                                    <strong>◆ Changed:</strong> This item was modified after extraction.
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the sourcing form UI.
     * Similar to RFQ form with sourcing-specific sections.
     *
     * @param array|null $project Existing project data (null if creating new).
     */
    private function render_sourcing_form( $project = null ) {
        $project_id      = $project['id'] ?? 0;
        $is_edit_mode    = ! empty( $project_id );
        $form_title      = $is_edit_mode ? 'Edit Sourcing Request' : 'Create New Sourcing Request';
        $project_name    = $project['project_name'] ?? '';
        $project_type    = $project['project_type'] ?? 'Sourcing';
        $timeline        = $project['timeline'] ?? '';
        $budget_range    = $project['budget_range'] ?? '';
        $metadata        = $project['metadata'] ?? array();
        $sourcing_cat    = $metadata['n88_sourcing_category'] ?? '';
        $company_name    = $metadata['n88_company_name'] ?? '';
        $contact_name    = $metadata['n88_contact_name'] ?? '';
        $email           = $metadata['n88_email'] ?? '';
        $phone           = $metadata['n88_phone'] ?? '';
        $location        = $metadata['n88_location'] ?? '';
        $repeater_json   = $metadata['n88_repeater_raw'] ?? '[]';
        $repeater        = is_string( $repeater_json ) ? json_decode( $repeater_json, true ) : array();

            // Check if project is in extraction mode
            $is_extraction_mode = false;
            if ( $project_id && class_exists( 'N88_RFQ_PDF_Extractor' ) ) {
                $is_extraction_mode = N88_RFQ_PDF_Extractor::is_extraction_mode( $project_id );
            }
        ?>
        <div class="n88-form-wrapper">
            <h1 class="n88-form-title"><?php echo esc_html( $form_title ); ?></h1>
            <?php if ( $is_edit_mode ) : ?>
                <p class="n88-edit-mode-info">You are editing sourcing request <strong><?php echo esc_html( $project_name ); ?></strong></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="n88-sourcing-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="n88_submit_project">
                <input type="hidden" name="form_type" value="sourcing">
                <?php wp_nonce_field( N88_RFQ_Helpers::NONCE_ACTION_FORM, N88_RFQ_Helpers::NONCE_PARAM_FORM ); ?>
                <?php if ( $project_id ) : ?>
                    <input type="hidden" name="project_id" value="<?php echo esc_attr( $project_id ); ?>">
                <?php endif; ?>

                <!-- REQUEST HEADER SECTION -->
                <fieldset>
                    <legend>Sourcing Request Information</legend>

                    <div class="form-group">
                        <label for="project_name">Request Name <span class="required">*</span></label>
                        <input type="text" id="project_name" name="project_name" value="<?php echo esc_attr( $project_name ); ?>" placeholder="e.g., Lighting Fixtures for Lobby" required>
                    </div>

                    <div class="form-group">
                        <label for="sourcing_category">Sourcing Category <span class="required">*</span></label>
                        <select id="sourcing_category" name="sourcing_category" required>
                            <option value="">-- Select Category --</option>
                            <option value="Lighting" <?php selected( $sourcing_cat, 'Lighting' ); ?>>Lighting</option>
                            <option value="Marble & Stone" <?php selected( $sourcing_cat, 'Marble & Stone' ); ?>>Marble & Stone</option>
                            <option value="Upholstery" <?php selected( $sourcing_cat, 'Upholstery' ); ?>>Upholstery</option>
                            <option value="Case Goods" <?php selected( $sourcing_cat, 'Case Goods' ); ?>>Case Goods</option>
                            <option value="Outdoor Furniture" <?php selected( $sourcing_cat, 'Outdoor Furniture' ); ?>>Outdoor Furniture</option>
                            <option value="Other" <?php selected( $sourcing_cat, 'Other' ); ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="timeline">Timeline <span class="required">*</span></label>
                        <input type="text" id="timeline" name="timeline" value="<?php echo esc_attr( $timeline ); ?>" placeholder="e.g., 2 weeks, ASAP" required>
                    </div>

                    <div class="form-group">
                        <label for="budget_range">Budget Range <span class="required">*</span></label>
                        <input type="text" id="budget_range" name="budget_range" value="<?php echo esc_attr( $budget_range ); ?>" placeholder="e.g., $5,000 - $10,000" required>
                    </div>
                </fieldset>

                <!-- ITEMS SECTION -->
                <fieldset>
                    <legend>Products / Items</legend>
                        
                        <!-- PHASE 2B: Entry Mode Toggle (Hidden) -->
                        <div class="n88-entry-mode-selector" style="display: none;">
                            <div class="n88-mode-toggle">
                                <label class="n88-toggle-label">
                                    <input type="radio" name="entry_mode" value="manual" class="entry-mode-radio" checked>
                                    <span class="n88-toggle-text">
                                        <i class="n88-icon-edit"></i> Manual Entry - Enter product details
                                    </span>
                                </label>
                                <label class="n88-toggle-label">
                                    <input type="radio" name="entry_mode" value="pdf" class="entry-mode-radio">
                                    <span class="n88-toggle-text">
                                        <i class="n88-icon-upload"></i> PDF Upload - Extract items automatically
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Manual Entry Mode -->
                        <div id="manual-entry-mode" class="n88-entry-mode-content" style="display: block;">
                    <div id="pieces-container">
                        <?php if ( ! empty( $repeater ) ) : ?>
                            <?php foreach ( $repeater as $index => $piece ) : ?>
                                        <?php $this->render_item_fields( $index, $piece, $is_extraction_mode, $project_id ); ?>
                            <?php endforeach; ?>
                        <?php else : ?>
                                    <?php $this->render_item_fields( 0, array(), $is_extraction_mode, $project_id ); ?>
                        <?php endif; ?>
                    </div>
                            <?php if ( ! $is_extraction_mode ) : ?>
                    <button type="button" id="add-item-btn" class="btn btn-secondary">+ Add Another Item</button>
                            <?php endif; ?>
                        </div>

                        <!-- PDF Upload Mode -->
                        <div id="pdf-upload-mode" class="n88-entry-mode-content" style="display: none;">
                            <div class="n88-pdf-upload-section" style="padding: 20px; background: #f9f9f9; border-radius: 6px; border: 1px solid #ddd;">
                                <p class="n88-pdf-upload-info" style="margin-bottom: 15px; padding: 12px; background: #e3f2fd; border-left: 4px solid #2196F3; border-radius: 4px; color: #1565c0; font-size: 14px;">
                                    <i class="n88-icon-info"></i>
                                    Upload a PDF containing your sourcing specifications. Our system will automatically detect and extract item details.
                                </p>
                                
                                <div class="form-group">
                                    <label for="pdf_file_upload" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">PDF File <span class="required" style="color: red;">*</span></label>
                                    <div id="n88-pdf-dropzone" class="n88-pdf-dropzone" style="border: 2px dashed #ccc; border-radius: 6px; padding: 40px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: white; min-height: 200px; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative;">
                                        <input type="file" id="pdf_file_upload" name="pdf_file_upload" accept=".pdf" class="n88-pdf-input" style="display: none;">
                                        <div class="n88-pdf-dropzone-content" style="pointer-events: none;">
                                            <svg class="n88-pdf-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width: 48px; height: 48px; margin: 0 auto 15px; color: #007cba;">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                                <line x1="12" y1="13" x2="12" y2="19"></line>
                                                <line x1="9" y1="16" x2="15" y2="16"></line>
                                            </svg>
                                            <p class="n88-pdf-dropzone-text" style="font-size: 16px; font-weight: 500; color: #333; margin-bottom: 8px;">Drag & drop PDF here or click to browse</p>
                                            <small style="color: #666; font-size: 12px;">Accepted format: PDF only</small>
                                        </div>
                                    </div>
                                    <div id="n88-pdf-upload-progress" class="n88-pdf-upload-progress" style="display: none; margin-top: 15px;">
                                        <div class="progress-bar" style="width: 100%; height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
                                            <div class="progress-fill" style="height: 100%; background: #007cba; transition: width 0.3s ease; width: 0%;"></div>
                                        </div>
                                        <p class="progress-text" style="text-align: center; color: #666; font-size: 14px;">Extracting items from PDF...</p>
                                    </div>
                                </div>

                                <!-- Extraction Preview -->
                                <div id="extraction-preview" class="n88-extraction-preview" style="display: none;">
                                    <div class="preview-header">
                                        <h3><i class="n88-icon-check"></i> Extraction Complete</h3>
                                        <p id="items-detected-count">Detected <span class="count">0</span> items from PDF</p>
                                    </div>
                                    
                                    <div class="preview-table-container" style="overflow-x: auto; max-height: 500px; overflow-y: auto;">
                                        <table class="extraction-preview-table">
                                            <thead>
                                                <tr>
                                                    <th>Status</th>
                                                    <th>Item</th>
                                                    <th>Length (in) *</th>
                                                    <th>Depth (in) *</th>
                                                    <th>Height (in) *</th>
                                                    <th>Quantity *</th>
                                                    <th>Primary Material *</th>
                                                    <th>Finishes *</th>
                                                    <th>Construction Notes *</th>
                                                </tr>
                                            </thead>
                                            <tbody id="extraction-items-list">
                                                <!-- Populated via JS -->
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="preview-actions">
                                        <button type="button" id="confirm-extraction-btn" class="btn btn-success">✓ Confirm & Import Items</button>
                                        <button type="button" id="cancel-extraction-btn" class="btn btn-secondary">Cancel & Re-upload</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                </fieldset>

                <!-- FILES SECTION -->
                <fieldset>
                    <legend>Supporting Files</legend>
                    <div class="form-group">
                        <label for="project_files">Upload Files (PDFs, images, specs, etc.)</label>
                        <div id="n88-file-dropzone" class="n88-file-dropzone">
                            <input type="file" id="project_files" name="project_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.dwg,.doc,.docx" class="n88-file-input" style="display: none;">
                            <div class="n88-dropzone-content">
                                <svg class="n88-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                    <polyline points="17 8 12 3 7 8"></polyline>
                                    <line x1="12" y1="3" x2="12" y2="15"></line>
                                </svg>
                                <p class="n88-dropzone-text">Drag & drop files here or click to browse</p>
                                <small>Accepted formats: PDF, JPG, PNG, GIF, DWG, DOC, DOCX</small>
                            </div>
                        </div>
                        <div id="n88-file-list" class="n88-file-list"></div>
                    </div>
                </fieldset>

                <!-- CLIENT INFO SECTION -->
                <fieldset>
                    <legend>Contact Information</legend>

                    <div class="form-group">
                        <label for="company_name">Company Name</label>
                        <input type="text" id="company_name" name="company_name" value="<?php echo esc_attr( $company_name ); ?>">
                    </div>

                    <div class="form-group">
                        <label for="contact_name">Contact Name</label>
                        <input type="text" id="contact_name" name="contact_name" value="<?php echo esc_attr( $contact_name ); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email <span class="required">*</span></label>
                        <input type="email" id="email" name="email" value="<?php echo esc_attr( $email ); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo esc_attr( $phone ); ?>">
                    </div>

                    <div class="form-group">
                        <label for="location">Location / City</label>
                        <input type="text" id="location" name="location" value="<?php echo esc_attr( $location ); ?>">
                    </div>
                </fieldset>

                <!-- FORM ACTIONS -->
                <div class="form-actions">
                    <button type="submit" name="submit_type" value="draft" class="btn btn-draft">Save as Draft</button>
                    <button type="submit" name="submit_type" value="submit" class="btn btn-submit">Submit Sourcing Request</button>
                </div>
            </form>

            <style>
                /* File Upload Styles */
                .n88-file-dropzone {
                    border: 2px dashed #007cba;
                    border-radius: 8px;
                    padding: 40px 20px;
                    text-align: center;
                    cursor: pointer;
                    background-color: #f0f7ff;
                    transition: all 0.3s ease;
                    margin-bottom: 20px;
                }

                .n88-file-dropzone:hover {
                    border-color: #0056b3;
                    background-color: #e6f2ff;
                }

                .n88-file-dropzone.dragover {
                    border-color: #0056b3;
                    background-color: #cce5ff;
                }

                .n88-dropzone-content {
                    pointer-events: none;
                }

                .n88-upload-icon {
                    width: 48px;
                    height: 48px;
                    color: #007cba;
                    margin-bottom: 12px;
                }

                .n88-dropzone-text {
                    font-size: 16px;
                    font-weight: 500;
                    color: #333;
                    margin: 0 0 8px 0;
                }

                .n88-file-list {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                    gap: 15px;
                    margin-top: 20px;
                }

                .n88-file-item {
                    position: relative;
                    background: #fff;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    padding: 12px;
                    text-align: center;
                    transition: all 0.3s ease;
                }

                .n88-file-item:hover {
                    border-color: #007cba;
                    box-shadow: 0 2px 8px rgba(0, 124, 186, 0.2);
                }

                .n88-file-preview {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 80px;
                    margin-bottom: 10px;
                    background: #f9f9f9;
                    border-radius: 4px;
                }

                .n88-file-preview img {
                    max-width: 80px;
                    max-height: 80px;
                    object-fit: cover;
                }

                .n88-file-icon {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    width: 80px;
                    height: 80px;
                    font-size: 24px;
                    font-weight: 600;
                    border-radius: 4px;
                    color: #fff;
                }

                .n88-file-icon.n88-pdf {
                    background: linear-gradient(135deg, #f44336, #e91e63);
                }

                .n88-file-icon.n88-doc {
                    background: linear-gradient(135deg, #2196f3, #1976d2);
                }

                .n88-file-icon.n88-dwg {
                    background: linear-gradient(135deg, #ff9800, #f57c00);
                }

                .n88-file-info {
                    margin-bottom: 8px;
                }

                .n88-file-name {
                    font-size: 12px;
                    font-weight: 500;
                    color: #333;
                    word-break: break-word;
                    margin-bottom: 4px;
                }

                .n88-file-size {
                    font-size: 11px;
                    color: #999;
                }

                .n88-file-delete {
                    position: absolute;
                    top: 5px;
                    right: 5px;
                    background: #f44336;
                    color: white;
                    border: none;
                    border-radius: 50%;
                    width: 24px;
                    height: 24px;
                    padding: 0;
                    font-size: 18px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .n88-file-delete:hover {
                    background: #d32f2f;
                    transform: scale(1.1);
                }

                @media (max-width: 600px) {
                    .n88-file-list {
                        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                        gap: 12px;
                    }

                    .n88-file-dropzone {
                        padding: 30px 15px;
                    }
                }
            </style>

            <script>
            (function() {
                let itemCount = <?php echo (int) max( 1, count( $repeater ?? array() ) ); ?>;

                // Form validation for submit
                document.getElementById('n88-sourcing-form')?.addEventListener('submit', function(e) {
                    const submitType = e.submitter?.value;
                    
                    // Only validate if submitting (not drafting)
                    if ( submitType === 'submit' ) {
                        const requestName = document.getElementById('project_name')?.value?.trim();
                        const category = document.getElementById('sourcing_category')?.value?.trim();
                        const timeline = document.getElementById('timeline')?.value?.trim();
                        const budgetRange = document.getElementById('budget_range')?.value?.trim();
                        const email = document.getElementById('email')?.value?.trim();
                        
                        const items = document.querySelectorAll('.piece-item');
                        let hasValidItem = false;
                        
                        items.forEach(item => {
                            const length = item.querySelector('input[name*="[length_in]"]')?.value;
                            const depth = item.querySelector('input[name*="[depth_in]"]')?.value;
                            const height = item.querySelector('input[name*="[height_in]"]')?.value;
                            const quantity = item.querySelector('input[name*="[quantity]"]')?.value;
                            
                            if ( length && depth && height && quantity ) {
                                hasValidItem = true;
                            }
                        });
                        
                        if ( !requestName || !category || !timeline || !budgetRange || !email || !hasValidItem ) {
                            alert('Please fill in all required fields:\n- Request Name\n- Sourcing Category\n- Timeline\n- Budget Range\n- Email\n- At least one complete item (with Dimensions, Quantity, Primary Material, Finishes, and Construction Notes)');
                            e.preventDefault();
                            return false;
                        }
                    }
                });

                document.getElementById('add-item-btn')?.addEventListener('click', function(e) {
                    e.preventDefault();
                    const container = document.getElementById('pieces-container');
                    const newItem = document.createElement('div');
                    newItem.className = 'piece-item';
                    newItem.innerHTML = `
                        <div class="piece-item-header">
                            <h4>Item ${itemCount + 1}</h4>
                            <button type="button" class="btn btn-remove">Remove</button>
                        </div>
                        <div class="piece-item-fields">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Length (in) <span class="required">*</span></label>
                                    <input type="number" step="0.01" name="pieces[${itemCount}][length_in]" required>
                                </div>
                                <div class="form-group">
                                    <label>Depth (in) <span class="required">*</span></label>
                                    <input type="number" step="0.01" name="pieces[${itemCount}][depth_in]" required>
                                </div>
                                <div class="form-group">
                                    <label>Height (in) <span class="required">*</span></label>
                                    <input type="number" step="0.01" name="pieces[${itemCount}][height_in]" required>
                                </div>
                                <div class="form-group">
                                    <label>Quantity <span class="required">*</span></label>
                                    <input type="number" name="pieces[${itemCount}][quantity]" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Primary Material <span class="required">*</span></label>
                                    <input type="text" name="pieces[${itemCount}][primary_material]" required>
                                </div>
                                <div class="form-group">
                                    <label>Finishes <span class="required">*</span></label>
                                    <input type="text" name="pieces[${itemCount}][finishes]" required>
                                </div>
                                <div class="form-group">
                                    <label>Cushions</label>
                                    <input type="number" name="pieces[${itemCount}][cushions]">
                                </div>
                                <div class="form-group">
                                    <label>Fabric Category</label>
                                    <input type="text" name="pieces[${itemCount}][fabric_category]">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Frame Material</label>
                                    <input type="text" name="pieces[${itemCount}][frame_material]">
                                </div>
                                <div class="form-group">
                                    <label>Finish</label>
                                    <input type="text" name="pieces[${itemCount}][finish]">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Construction Notes <span class="required">*</span></label>
                                <textarea name="pieces[${itemCount}][construction_notes]" rows="3" required></textarea>
                            </div>
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="pieces[${itemCount}][notes]" rows="3"></textarea>
                            </div>
                        </div>
                    `;
                    container.appendChild(newItem);
                    itemCount++;
                    attachRemoveListener(newItem.querySelector('.btn-remove'));
                });

                function attachRemoveListener(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        this.closest('.piece-item').remove();
                    });
                }

                // Attach listeners to existing remove buttons
                document.querySelectorAll('.piece-item .btn-remove').forEach(btn => {
                    attachRemoveListener(btn);
                });

                // File Upload Drag & Drop Handler
                const dropzone = document.getElementById('n88-file-dropzone');
                const fileInput = document.getElementById('project_files');
                const fileList = document.getElementById('n88-file-list');

                if (dropzone && fileInput) {
                    // Click to browse
                    dropzone.addEventListener('click', () => fileInput.click());

                    // Drag over
                    dropzone.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        dropzone.classList.add('dragover');
                    });

                    dropzone.addEventListener('dragleave', () => {
                        dropzone.classList.remove('dragover');
                    });

                    // Drop
                    dropzone.addEventListener('drop', (e) => {
                        e.preventDefault();
                        dropzone.classList.remove('dragover');
                        handleFiles(e.dataTransfer.files);
                    });

                    // File input change
                    fileInput.addEventListener('change', (e) => {
                        handleFiles(e.target.files);
                    });
                }

                function handleFiles(files) {
                    const fileListContainer = document.getElementById('n88-file-list');
                    for (let file of files) {
                        addFilePreview(file, fileListContainer);
                    }
                    // Reset file input
                    fileInput.value = '';
                }

                function addFilePreview(file, container) {
                    const fileItem = document.createElement('div');
                    fileItem.className = 'n88-file-item';
                    
                    let preview = '';
                    const ext = file.name.split('.').pop().toLowerCase();
                    const fileSize = (file.size / 1024).toFixed(2); // Convert to KB

                    if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            const img = container.querySelector(`[data-file=\"${file.name}\"] .n88-file-preview img`);
                            if (img) {
                                img.src = e.target.result;
                            }
                        };
                        reader.readAsDataURL(file);
                        preview = `<img src=\"\" alt=\"${file.name}\" style=\"max-width: 80px; max-height: 80px;\">`;
                    } else if (ext === 'pdf') {
                        preview = '<div class=\"n88-file-icon n88-pdf\">PDF</div>';
                    } else if (['doc', 'docx'].includes(ext)) {
                        preview = '<div class=\"n88-file-icon n88-doc\">DOC</div>';
                    } else if (ext === 'dwg') {
                        preview = '<div class=\"n88-file-icon n88-dwg\">DWG</div>';
                    } else {
                        preview = '<div class=\"n88-file-icon\">FILE</div>';
                    }

                    fileItem.setAttribute('data-file', file.name);
                    fileItem.innerHTML = `
                        <div class=\"n88-file-preview\">
                            ${preview}
                        </div>
                        <div class=\"n88-file-info\">
                            <div class=\"n88-file-name\">${file.name}</div>
                            <div class=\"n88-file-size\">${fileSize} KB</div>
                        </div>
                        <button type=\"button\" class=\"n88-file-delete\" title=\"Delete file\">&times;</button>
                    `;

                    // Delete button handler
                    fileItem.querySelector('.n88-file-delete').addEventListener('click', (e) => {
                        e.preventDefault();
                        fileItem.remove();
                    });

                    container.appendChild(fileItem);
                }
            })();
            </script>
        </div>
        <?php
    }

    /**
     * Render the my projects shortcode.
     */
    public function render_my_projects_shortcode( $atts = array() ) {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to view your projects.</p>';
        }

            $project_detail_template = add_query_arg(
                array( 'project_id' => '__PROJECT__' ),
                home_url( '/project-detail/' )
            );

        $user_id = get_current_user_id();
        $status  = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'all';
        $message_type = '';
        $message = '';

        // Check for success message
        if ( isset( $_GET['n88_saved'] ) ) {
            $message_type = 'success';
            $message = 'Project saved successfully!';
        }

        return $this->render_my_projects_list( $user_id, $status, $message, $message_type );
    }

    /**
     * Render the projects list with filters.
     *
     * @param int    $user_id      The user ID.
     * @param string $status       Filter type: 'all', 'draft', 'submitted'.
     * @param string $message      Success/error message.
     * @param string $message_type Type of message: 'success' or 'error'.
     */
    private function render_my_projects_list( $user_id, $status, $message = '', $message_type = '' ) {
        global $wpdb;

        // Build query based on status filter - now includes quote status
        $query = $wpdb->prepare(
            "SELECT DISTINCT p.*, q.id as quote_id, q.quote_status, q.sent_at 
             FROM {$wpdb->prefix}projects p
             LEFT JOIN {$wpdb->prefix}project_quotes q ON p.id = q.project_id
             WHERE p.user_id = %d 
             ORDER BY p.created_at DESC",
            $user_id
        );

        if ( 'draft' === $status ) {
            $query = $wpdb->prepare(
                "SELECT DISTINCT p.*, q.id as quote_id, q.quote_status, q.sent_at 
                 FROM {$wpdb->prefix}projects p
                 LEFT JOIN {$wpdb->prefix}project_quotes q ON p.id = q.project_id
                 WHERE p.user_id = %d AND p.status = %d 
                 ORDER BY p.created_at DESC",
                $user_id,
                N88_RFQ_STATUS_DRAFT
            );
        } elseif ( 'submitted' === $status ) {
            $query = $wpdb->prepare(
                "SELECT DISTINCT p.*, q.id as quote_id, q.quote_status, q.sent_at 
                 FROM {$wpdb->prefix}projects p
                 LEFT JOIN {$wpdb->prefix}project_quotes q ON p.id = q.project_id
                 WHERE p.user_id = %d AND p.status = %d 
                 ORDER BY p.created_at DESC",
                $user_id,
                N88_RFQ_STATUS_SUBMITTED
            );
        } elseif ( 'quoted' === $status ) {
            $query = $wpdb->prepare(
                "SELECT DISTINCT p.*, q.id as quote_id, q.quote_status, q.sent_at 
                 FROM {$wpdb->prefix}projects p
                 INNER JOIN {$wpdb->prefix}project_quotes q ON p.id = q.project_id
                 WHERE p.user_id = %d AND q.quote_status = 'sent'
                 ORDER BY p.created_at DESC",
                $user_id
            );
        }

        $projects = $wpdb->get_results( $query );

            // Phase 2B: Fetch accurate item counts from project metadata
            $project_item_counts = array();
            if ( ! empty( $projects ) ) {
                $project_ids = array_map( 'intval', wp_list_pluck( $projects, 'id' ) );
                if ( ! empty( $project_ids ) ) {
                    $meta_table = $wpdb->prefix . 'project_metadata';
                    $ids_sql    = implode( ',', $project_ids );

                    // Query metadata for all projects in current view
                    $meta_rows = $wpdb->get_results(
                        "SELECT project_id, meta_value 
                        FROM {$meta_table} 
                        WHERE meta_key = 'n88_repeater_raw' 
                        AND project_id IN ({$ids_sql})"
                    );

                    foreach ( $meta_rows as $row ) {
                        $items = json_decode( $row->meta_value, true );
                        $project_item_counts[ $row->project_id ] = is_array( $items ) ? count( $items ) : 0;
                    }
                }
            }

            // Phase 2B: Fetch urgent/change flag summary per project for dashboard badges
            $project_flag_summary = array();
            if ( ! empty( $projects ) && class_exists( 'N88_RFQ_Item_Flags' ) ) {
                $flags_class = new N88_RFQ_Item_Flags();
                foreach ( $projects as $proj ) {
                    $project_flag_summary[ $proj->id ] = $flags_class->get_flag_summary( $proj->id );
                }
            }

            // Fetch admin update flags for user dashboard
            $project_admin_updates = array();
            if ( ! empty( $projects ) ) {
                $project_ids = array_map( 'intval', wp_list_pluck( $projects, 'id' ) );
                if ( ! empty( $project_ids ) ) {
                    $meta_table = $wpdb->prefix . 'project_metadata';
                    $ids_sql    = implode( ',', $project_ids );

                    // Query admin update metadata
                    $admin_update_rows = $wpdb->get_results(
                        "SELECT project_id, meta_value 
                        FROM {$meta_table} 
                        WHERE meta_key = 'n88_has_admin_updates' 
                        AND meta_value = '1'
                        AND project_id IN ({$ids_sql})"
                    );

                    foreach ( $admin_update_rows as $row ) {
                        $project_admin_updates[ $row->project_id ] = true;
                    }
                }
            }

        // Calculate statistics
        $total_projects = count( $projects );
        $draft_projects = 0;
        $submitted_projects = 0;
        $quoted_projects = 0;

        foreach ( $projects as $project ) {
            if ( N88_RFQ_STATUS_DRAFT === (int) $project->status ) {
                $draft_projects++;
            } elseif ( N88_RFQ_STATUS_SUBMITTED === (int) $project->status ) {
                if ( 'sent' === $project->quote_status ) {
                    $quoted_projects++;
                } else {
                    $submitted_projects++;
                }
            }
        }

        // Get filter URLs
        $filter_url_all       = remove_query_arg( 'status' );
        $filter_url_draft     = add_query_arg( array( 'status' => 'draft' ), remove_query_arg( 'status' ) );
        $filter_url_submitted = add_query_arg( array( 'status' => 'submitted' ), remove_query_arg( 'status' ) );
        $filter_url_quoted    = add_query_arg( array( 'status' => 'quoted' ), remove_query_arg( 'status' ) );

        ob_start();
        ?>
        <div class="n88-my-projects-wrapper">
            <div class="n88-projects-header">
                <div class="n88-header-top">
                        <div class="n88-header-title">
                    <h1><i class="n88-icon-dashboard"></i> My Projects Dashboard</h1>
                        </div>
                    <div class="n88-create-buttons">
                        <a href="<?php echo esc_url( home_url( '/rfq-form/' ) ); ?>" class="n88-btn-create n88-btn-rfq">
                            <span class="n88-btn-icon"><i class="n88-icon-form"></i></span>
                            <span>Create RFQ Project</span>
                        </a>
                        <a href="<?php echo esc_url( home_url( '/sourcing-form/' ) ); ?>" class="n88-btn-create n88-btn-sourcing">
                            <span class="n88-btn-icon"><i class="n88-icon-search"></i></span>
                            <span>Create Sourcing Project</span>
                        </a>
                    </div>
                        <div class="n88-notification-center" aria-live="polite" data-detail-template="<?php echo esc_attr( $project_detail_template ); ?>">
                            <button type="button" class="n88-notification-bell" aria-label="Show notifications">
                                <span class="n88-bell-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
                                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                                    </svg>
                                </span>
                                <span class="n88-notification-count" data-count="0">0</span>
                            </button>
                            <div class="n88-notification-panel" role="region" aria-label="Notifications">
                                <div class="n88-notification-header">
                                    <h4>Notifications</h4>
                                    <button type="button" class="n88-btn-mark-all">Mark all read</button>
                                </div>
                                <div class="n88-notification-body">
                                    <div class="n88-notification-loading">Loading notifications...</div>
                                    <div class="n88-notification-empty">No notifications yet.</div>
                                    <div class="n88-notification-list"></div>
                                </div>
                            </div>
                    </div>
                </div>

            <?php if ( ! empty( $message ) ) : ?>
                <div class="n88-message n88-message-<?php echo esc_attr( $message_type ); ?>">
                        <span class="n88-message-icon"><i class="n88-icon-<?php echo 'success' === $message_type ? 'checkmark' : 'alert'; ?>"></i></span>
                    <span class="n88-message-text"><?php echo esc_html( $message ); ?></span>
                </div>
            <?php endif; ?>

                <!-- Dashboard Stats -->
                <div class="n88-dashboard-stats">
                    <div class="n88-stat-card n88-stat-total">
                        <div class="n88-stat-icon"><i class="n88-icon-folder"></i></div>
                        <div class="n88-stat-content">
                            <div class="n88-stat-number"><?php echo esc_html( $total_projects ); ?></div>
                            <div class="n88-stat-label">Total Projects</div>
                        </div>
                    </div>

                    <div class="n88-stat-card n88-stat-draft">
                        <div class="n88-stat-icon"><i class="n88-icon-edit"></i></div>
                        <div class="n88-stat-content">
                            <div class="n88-stat-number"><?php echo esc_html( $draft_projects ); ?></div>
                            <div class="n88-stat-label">In Draft</div>
                        </div>
                        <a href="<?php echo esc_url( $filter_url_draft ); ?>" class="n88-stat-link" title="View drafts"></a>
                    </div>

                    <div class="n88-stat-card n88-stat-submitted">
                        <div class="n88-stat-icon"><i class="n88-icon-send"></i></div>
                        <div class="n88-stat-content">
                            <div class="n88-stat-number"><?php echo esc_html( $submitted_projects ); ?></div>
                            <div class="n88-stat-label">Awaiting Quote</div>
                        </div>
                        <a href="<?php echo esc_url( $filter_url_submitted ); ?>" class="n88-stat-link" title="View awaiting quote"></a>
                    </div>

                    <div class="n88-stat-card n88-stat-quoted">
                        <div class="n88-stat-icon"><i class="n88-icon-check"></i></div>
                        <div class="n88-stat-content">
                            <div class="n88-stat-number"><?php echo esc_html( $quoted_projects ); ?></div>
                            <div class="n88-stat-label">Quote Sent</div>
                        </div>
                        <a href="<?php echo esc_url( $filter_url_quoted ); ?>" class="n88-stat-link" title="View quoted"></a>
                    </div>
                </div>
            </div>

            <!-- Status Filter Pills -->
            <div class="n88-status-filters">
                <a href="<?php echo esc_url( $filter_url_all ); ?>" class="n88-status-pill <?php echo 'all' === $status ? 'active' : ''; ?>">
                    All
                </a>
                <a href="<?php echo esc_url( $filter_url_draft ); ?>" class="n88-status-pill <?php echo 'draft' === $status ? 'active' : ''; ?>">
                    Draft
                </a>
                <a href="<?php echo esc_url( $filter_url_submitted ); ?>" class="n88-status-pill <?php echo 'submitted' === $status ? 'active' : ''; ?>">
                    Needs Quote
                </a>
                <a href="<?php echo esc_url( $filter_url_quoted ); ?>" class="n88-status-pill <?php echo 'quoted' === $status ? 'active' : ''; ?>">
                    Quote Sent
                </a>
            </div>

            <!-- Projects Table/Grid -->
            <?php if ( ! empty( $projects ) ) : ?>
                <div class="n88-projects-table-container">
                    <table class="n88-projects-table">
                        <thead>
                            <tr>
                                <th>Project Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Items</th>
                                <th>Created</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $projects as $project ) : ?>
                                <?php
                                    $project_id = $project->id;
                                        // Prefer fresh count from metadata; fallback to stored column if unavailable
                                        if ( isset( $project_item_counts[ $project_id ] ) ) {
                                            $item_count = (int) $project_item_counts[ $project_id ];
                                        } else {
                                    $item_count = isset( $project->item_count ) ? (int) $project->item_count : 0;
                                    }

                                    $status_badge = ( N88_RFQ_STATUS_DRAFT === (int) $project->status )
                                        ? '<span class="n88-status-badge n88-status-draft">Draft</span>'
                                        : ( $project->quote_status === 'sent' 
                                            ? '<span class="n88-status-badge n88-status-quoted" style="background: #4CAF50;">Quote Sent</span>'
                                            : '<span class="n88-status-badge n88-status-submitted">Needs Quote</span>' );

                                    $created_date = date_i18n( get_option( 'date_format' ), strtotime( $project->created_at ) );
                                    $updated_date = date_i18n( get_option( 'date_format' ), strtotime( $project->updated_at ) );

                                    // Determine edit/view URL
                                    $edit_url = ( N88_RFQ_STATUS_DRAFT === (int) $project->status )
                                        ? add_query_arg( array( 'project_id' => $project_id ), home_url( '/rfq-form/' ) )
                                        : add_query_arg( array( 'project_id' => $project_id ), home_url( '/project-detail/' ) );
                                ?>
                                <tr>
                                        <td>
                                            <strong><?php echo esc_html( $project->project_name ); ?></strong>
                                            <?php if ( ! empty( $project_admin_updates[ $project_id ] ) ) : ?>
                                                <span class="n88-badge-new-updates" style="margin-left: 8px;">NEW UPDATES</span>
                                            <?php endif; ?>
                                        </td>
                                    <td>
                                        <?php 
                                            $quote_type = $project->quote_type ?? 'N/A';
                                            if ( '24-hour' === $quote_type ) {
                                                echo '<span class="n88-quote-type-badge n88-quote-type-24hr">24-Hour</span>';
                                            } elseif ( 'sourcing' === $quote_type ) {
                                                echo '<span class="n88-quote-type-badge n88-quote-type-sourcing">Sourcing</span>';
                                            } else {
                                                echo esc_html( $quote_type );
                                            }
                                        ?>
                                    </td>
                                        <td>
                                            <?php echo wp_kses_post( $status_badge ); ?>
                                            <?php if ( ! empty( $project_flag_summary[ $project_id ]['urgent'] ) ) : ?>
                                                <span class="n88-urgent-dot" title="<?php echo esc_attr( $project_flag_summary[ $project_id ]['urgent'] ); ?> urgent item(s)"></span>
                                            <?php endif; ?>
                                        </td>
                                    <td><?php echo esc_html( $item_count ); ?></td>
                                    <td><?php echo esc_html( $created_date ); ?></td>
                                    <td><?php echo esc_html( $updated_date ); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url( $edit_url ); ?>" class="n88-action-btn n88-btn-view">
                                            <?php echo ( N88_RFQ_STATUS_DRAFT === (int) $project->status ) ? 'Edit' : 'View'; ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="n88-no-projects">
                    <p>You haven't created any projects yet.</p>
                    <a href="<?php echo esc_url( home_url( '/rfq-form/' ) ); ?>" class="btn btn-primary">Create Your First Project</a>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .n88-my-projects-wrapper {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px;
                background: #f8f9fa;
                min-height: 100vh;
            }

            /* Header Section */
            .n88-projects-header {
                background: #ffffff;
                border-radius: 8px;
                padding: 30px;
                margin-bottom: 30px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }

            .n88-header-top {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 25px;
                gap: 20px;
                flex-wrap: wrap;
            }

                .n88-notification-center {
                    position: relative;
                }

                .n88-notification-bell {
                    position: relative;
                    background: #fff;
                    border: 1px solid #e0e0e0;
                    border-radius: 999px;
                    padding: 8px 16px;
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }

                .n88-notification-bell:hover {
                    border-color: #007cba;
                    box-shadow: 0 4px 12px rgba(0,124,186,0.15);
                }

                .n88-notification-bell.has-unread {
                    border-color: #ff5252;
                }

                .n88-bell-icon svg {
                    width: 20px;
                    height: 20px;
                    stroke: currentColor;
                }

                .n88-notification-count {
                    background: #ff5252;
                    color: #fff;
                    border-radius: 999px;
                    padding: 2px 8px;
                    font-size: 12px;
                    font-weight: 600;
                }

                .n88-notification-count.is-zero {
                    background: #cfd8dc;
                    color: #546e7a;
                }

                .n88-badge-new-updates {
                    background: linear-gradient(90deg, #ff7043, #d84315);
                    color: #fff;
                    padding: 4px 12px;
                    border-radius: 999px;
                    font-size: 0.75em;
                    font-weight: 700;
                    letter-spacing: 0.5px;
                    text-transform: uppercase;
                }

                .n88-notification-panel {
                    position: absolute;
                    right: 0;
                    top: calc(100% + 12px);
                    width: 320px;
                    background: #fff;
                    border-radius: 8px;
                    box-shadow: 0 12px 24px rgba(0,0,0,0.15);
                    border: 1px solid #e0e0e0;
                    display: none;
                    z-index: 50;
                }

                .n88-notification-panel.is-visible {
                    display: block;
                }

                .n88-notification-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 12px 16px;
                    border-bottom: 1px solid #f0f0f0;
                }

                .n88-notification-header h4 {
                    margin: 0;
                    font-size: 14px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }

                .n88-btn-mark-all {
                    background: transparent;
                    border: none;
                    color: #007cba;
                    font-weight: 600;
                    cursor: pointer;
                }

                .n88-btn-mark-all:hover {
                    text-decoration: underline;
                }

                .n88-notification-body {
                    max-height: 360px;
                    overflow-y: auto;
                }

                .n88-notification-list {
                    display: flex;
                    flex-direction: column;
                }

                .n88-notification-item {
                    display: block;
                    width: 100%;
                    text-align: left;
                    border: none;
                    background: transparent;
                    padding: 12px 16px;
                    border-bottom: 1px solid #f6f6f6;
                    cursor: pointer;
                }

                .n88-notification-item.unread {
                    background: #eef6ff;
                }

                .n88-notification-item:hover {
                    background: #f9f9f9;
                }

                .n88-notification-item strong {
                    display: block;
                    margin-bottom: 4px;
                    color: #333;
                }

                .n88-notification-time {
                    display: block;
                    font-size: 12px;
                    color: #999;
                    margin-top: 4px;
                }

                .n88-notification-empty,
                .n88-notification-loading {
                    padding: 20px;
                    text-align: center;
                    color: #666;
                }

                .n88-urgent-dot {
                    display: inline-block;
                    width: 10px;
                    height: 10px;
                    border-radius: 50%;
                    background: #ff1744;
                    margin-left: 6px;
            }

            .n88-my-projects-wrapper h1 {
                font-size: 2em;
                margin: 0;
                color: #333;
                font-weight: 700;
                letter-spacing: -0.5px;
                display: flex;
                align-items: center;
                gap: 10px;
            }

                .n88-header-title {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    flex-wrap: wrap;
            }

            /* Icon System */
            .n88-icon-dashboard::before { content: "═"; }
            .n88-icon-form::before { content: "☐"; }
            .n88-icon-search::before { content: "⊙"; }
            .n88-icon-folder::before { content: "▦"; }
            .n88-icon-edit::before { content: "✎"; }
            .n88-icon-send::before { content: "→"; }
            .n88-icon-check::before { content: "✓"; }
            .n88-icon-checkmark::before { content: "✓"; }
            .n88-icon-alert::before { content: "⚠"; }

            .n88-icon-dashboard,
            .n88-icon-form,
            .n88-icon-search,
            .n88-icon-folder,
            .n88-icon-edit,
            .n88-icon-send,
            .n88-icon-check,
            .n88-icon-checkmark,
            .n88-icon-alert {
                font-style: normal;
                display: inline-block;
                font-weight: bold;
                line-height: 1;
            }

            /* Create Buttons */
            .n88-create-buttons {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
            }

            .n88-btn-create {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 12px 20px;
                border-radius: 6px;
                text-decoration: none;
                font-weight: 600;
                font-size: 0.95em;
                transition: all 0.3s ease;
                border: 2px solid transparent;
                cursor: pointer;
            }

            .n88-btn-rfq {
                background: #e8f1f9;
                color: #2c5aa0;
                border: 2px solid #2c5aa0;
            }

            .n88-btn-rfq:hover {
                background: #2c5aa0;
                color: white;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(44, 90, 160, 0.2);
            }

            .n88-btn-sourcing {
                background: #f0e8f9;
                color: #7c3aed;
                border: 2px solid #7c3aed;
            }

            .n88-btn-sourcing:hover {
                background: #7c3aed;
                color: white;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(124, 58, 237, 0.2);
            }

            .n88-btn-icon {
                font-size: 1.1em;
            }

            /* Dashboard Stats */
            .n88-dashboard-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 16px;
                margin-top: 20px;
            }

            .n88-stat-card {
                background: #ffffff;
                border-radius: 8px;
                padding: 20px;
                display: flex;
                gap: 15px;
                align-items: flex-start;
                transition: all 0.3s ease;
                border: 1px solid #e0e0e0;
                position: relative;
                overflow: hidden;
            }

            .n88-stat-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 3px;
                background: currentColor;
            }

            .n88-stat-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            }

            .n88-stat-total {
                color: #2c5aa0;
                background: #f0f6ff;
            }

            .n88-stat-draft {
                color: #d97706;
                background: #fef3f0;
            }

            .n88-stat-submitted {
                color: #ea580c;
                background: #fff5f2;
            }

            .n88-stat-quoted {
                color: #059669;
                background: #f0fdf5;
            }

            .n88-stat-icon {
                font-size: 2em;
                display: flex;
                justify-content: center;
                align-items: center;
                width: 50px;
                height: 50px;
                background: rgba(0, 0, 0, 0.05);
                border-radius: 6px;
                flex-shrink: 0;
                color: inherit;
            }

            .n88-stat-content {
                flex: 1;
            }

            .n88-stat-number {
                font-size: 2em;
                font-weight: 700;
                line-height: 1;
                margin-bottom: 5px;
                color: inherit;
            }

            .n88-stat-label {
                font-size: 0.9em;
                opacity: 0.8;
                font-weight: 500;
                color: inherit;
            }

            .n88-stat-link {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                cursor: pointer;
            }

            /* Messages */
            .n88-message {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 16px;
                border-radius: 6px;
                margin-bottom: 20px;
                font-weight: 500;
                animation: slideDown 0.3s ease-out;
            }

            .n88-message-success {
                background-color: #f0fdf5;
                border: 1px solid #86efac;
                color: #059669;
            }

            .n88-message-error {
                background-color: #fef2f2;
                border: 1px solid #fca5a5;
                color: #dc2626;
            }

            .n88-message-icon {
                font-size: 1.2em;
                font-weight: bold;
                display: flex;
                align-items: center;
            }

            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            /* Status Filter Pills */
            .n88-status-filters {
                display: flex;
                gap: 12px;
                margin-bottom: 30px;
                flex-wrap: wrap;
                padding: 0;
            }

            .n88-status-pill {
                display: inline-block;
                padding: 10px 20px;
                border: 2px solid #ddd;
                background-color: #ffffff;
                color: #666;
                text-decoration: none;
                border-radius: 6px;
                transition: all 0.3s ease;
                font-weight: 500;
                cursor: pointer;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            }

            .n88-status-pill:hover {
                border-color: #2c5aa0;
                color: #2c5aa0;
                background-color: #f0f6ff;
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(44, 90, 160, 0.15);
            }

            .n88-status-pill.active {
                background-color: #2c5aa0;
                color: white;
                border-color: #2c5aa0;
                box-shadow: 0 2px 8px rgba(44, 90, 160, 0.2);
            }

            /* Table */
            .n88-projects-table-container {
                overflow-x: auto;
                margin-bottom: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }

            .n88-projects-table {
                width: 100%;
                border-collapse: collapse;
                background-color: #ffffff;
            }

            .n88-projects-table thead {
                background: #f8f9fa;
                border-bottom: 2px solid #e0e0e0;
            }

            .n88-projects-table th {
                padding: 15px 12px;
                text-align: left;
                font-weight: 700;
                color: #333;
                font-size: 0.9em;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .n88-projects-table td {
                padding: 15px 12px;
                border-bottom: 1px solid #e8e8e8;
                color: #666;
                font-size: 0.95em;
            }

            .n88-projects-table tbody tr {
                transition: all 0.2s ease;
            }

            .n88-projects-table tbody tr:hover {
                background-color: #f8f9fa;
                box-shadow: inset 0 -2px 4px rgba(0, 0, 0, 0.02);
            }

            .n88-projects-table tbody tr:last-child td {
                border-bottom: none;
            }

            /* Status Badges */
            .n88-status-badge {
                display: inline-block;
                padding: 5px 12px;
                border-radius: 6px;
                font-size: 0.85em;
                font-weight: 600;
                text-align: center;
                min-width: 70px;
            }

            .n88-status-draft {
                background-color: #fef3f0;
                color: #d97706;
            }

            .n88-status-submitted {
                background-color: #f0fdf5;
                color: #059669;
            }

            /* Quote Type Badges */
            .n88-quote-type-badge {
                display: inline-block;
                padding: 5px 10px;
                border-radius: 6px;
                font-size: 0.85em;
                font-weight: 500;
                text-align: center;
                min-width: 70px;
            }

            .n88-quote-type-24hr {
                background-color: #f0f6ff;
                color: #2c5aa0;
            }

            .n88-quote-type-sourcing {
                background-color: #f0e8f9;
                color: #7c3aed;
            }

            /* Action Buttons */
            .n88-action-btn {
                display: inline-block;
                padding: 8px 16px;
                border-radius: 6px;
                text-decoration: none;
                font-size: 0.9em;
                font-weight: 600;
                transition: all 0.3s ease;
                border: none;
                cursor: pointer;
            }

            .n88-btn-view {
                background-color: #2c5aa0;
                color: white;
            }

            .n88-btn-view:hover {
                background-color: #1e3f66;
                color: white;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(44, 90, 160, 0.2);
            }

            /* No Projects */
            .n88-no-projects {
                text-align: center;
                padding: 60px 20px;
                background: #ffffff;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }

            .n88-no-projects p {
                font-size: 1.1em;
                color: #666;
                margin-bottom: 20px;
                font-weight: 500;
            }

            .btn-primary {
                display: inline-block;
                padding: 12px 24px;
                background: #2c5aa0;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
                transition: all 0.3s ease;
                border: none;
                cursor: pointer;
                box-shadow: 0 2px 8px rgba(44, 90, 160, 0.15);
            }

            .btn-primary:hover {
                background: #1e3f66;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(44, 90, 160, 0.25);
            }

            /* Responsive Design */
            @media (max-width: 768px) {
                .n88-my-projects-wrapper {
                    padding: 12px;
                    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                }

                .n88-projects-header {
                    padding: 20px;
                    border-radius: 10px;
                }

                .n88-header-top {
                    flex-direction: column;
                    align-items: flex-start;
                }

                .n88-my-projects-wrapper h1 {
                    font-size: 1.5em;
                }

                .n88-create-buttons {
                    width: 100%;
                }

                .n88-btn-create {
                    flex: 1;
                    justify-content: center;
                    padding: 10px 16px;
                    font-size: 0.9em;
                }

                .n88-dashboard-stats {
                    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                    gap: 12px;
                }

                .n88-stat-card {
                    padding: 16px;
                    gap: 10px;
                }

                .n88-stat-icon {
                    width: 45px;
                    height: 45px;
                    font-size: 1.8em;
                }

                .n88-stat-number {
                    font-size: 1.5em;
                }

                .n88-status-filters {
                    flex-wrap: wrap;
                    gap: 8px;
                }

                .n88-status-pill {
                    flex: 0 1 auto;
                    padding: 8px 16px;
                    font-size: 0.9em;
                }

                .n88-projects-table {
                    font-size: 0.85em;
                }

                .n88-projects-table th,
                .n88-projects-table td {
                    padding: 10px 8px;
                }

                .n88-projects-table th {
                    font-size: 0.8em;
                }

                .n88-action-btn {
                    padding: 6px 12px;
                    font-size: 0.8em;
                }

                .n88-no-projects {
                    padding: 40px 20px;
                }

                .n88-no-projects p {
                    font-size: 1em;
                }
            }

            @media (max-width: 480px) {
                .n88-my-projects-wrapper {
                    padding: 8px;
                }

                .n88-projects-header {
                    padding: 15px;
                }

                .n88-my-projects-wrapper h1 {
                    font-size: 1.3em;
                    margin-bottom: 15px;
                }

                .n88-btn-icon {
                    font-size: 1em;
                }

                .n88-btn-create span:last-child {
                    display: none;
                }

                .n88-dashboard-stats {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 10px;
                }

                .n88-stat-card {
                    padding: 12px;
                    border-radius: 8px;
                }

                .n88-stat-number {
                    font-size: 1.3em;
                }

                .n88-stat-label {
                    font-size: 0.8em;
                }

                .n88-stat-icon {
                    width: 40px;
                    height: 40px;
                    font-size: 1.5em;
                }

                .n88-projects-table-container {
                    border-radius: 6px;
                }

                .n88-projects-table th {
                    font-size: 0.7em;
                    padding: 8px 4px;
                }

                .n88-projects-table td {
                    padding: 8px 4px;
                    font-size: 0.75em;
                }

                .n88-status-badge {
                    padding: 3px 8px;
                    font-size: 0.7em;
                }

                .n88-action-btn {
                    padding: 5px 10px;
                    font-size: 0.75em;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the project detail shortcode.
     */
    public function render_project_detail_shortcode( $atts = array() ) {
        if ( ! is_user_logged_in() ) {
                $login_url = wp_login_url( esc_url( add_query_arg( array( 'project_id' => $_GET['project_id'] ?? 0 ), home_url( '/project-detail/' ) ) ) );
                return sprintf(
                    '<p>You must be logged in to view this project. <a href="%s">Log in now</a>.</p>',
                    esc_url( $login_url )
                );
        }

        $user_id    = get_current_user_id();
        $project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;

        if ( ! $project_id ) {
            return '<p>No project specified.</p>';
        }

            // Ensure scripts are enqueued for this page
            $this->enqueue_form_styles();

        // Load project and verify ownership
        $projects_class = new N88_RFQ_Projects();
        $project        = $projects_class->get_project( $project_id, $user_id );

        if ( ! $project ) {
            return '<p>Project not found or you do not have permission to view it.</p>';
        }

            // Get plugin URL for script
            if ( defined( 'N88_RFQ_PLUGIN_URL' ) ) {
                $plugin_url = trailingslashit( N88_RFQ_PLUGIN_URL );
            } else {
                $plugin_file = dirname( dirname( __FILE__ ) ) . '/n88-rfq-platform.php';
                $plugin_url = trailingslashit( plugin_dir_url( $plugin_file ) );
            }
            $js_url = $plugin_url . 'assets/n88-rfq-modal.js';

            // Render project detail view
            $output = $this->render_project_detail_view( $project );
            
            // Add script tag directly to ensure it loads (fallback if wp_enqueue_script didn't work)
            $output .= '<script type="text/javascript">
            (function() {
                // Check if script is already loaded
                var scriptAlreadyLoaded = false;
                var scripts = document.getElementsByTagName("script");
                for (var i = 0; i < scripts.length; i++) {
                    if (scripts[i].src && scripts[i].src.indexOf("n88-rfq-modal.js") !== -1) {
                        scriptAlreadyLoaded = true;
                        break;
                    }
                }
                
                // Ensure n88 AJAX object exists (needed by the script)
                if (typeof window.n88 === "undefined") {
                    window.n88 = {
                        ajaxUrl: "' . esc_js( admin_url( 'admin-ajax.php' ) ) . '",
                        nonce: "' . esc_js( N88_RFQ_Helpers::create_ajax_nonce() ) . '"
                    };
                }
                
                // If script not loaded, load it dynamically
                if (!scriptAlreadyLoaded && typeof window.N88ProjectDetails === "undefined") {
                    console.log("Loading n88-rfq-modal.js dynamically...");
                    var script = document.createElement("script");
                    script.type = "text/javascript";
                    script.src = "' . esc_js( $js_url ) . '?v=' . N88_RFQ_VERSION . '";
                    script.onload = function() {
                        console.log("n88-rfq-modal.js loaded successfully");
                        // Wait a moment for the script to execute
                        setTimeout(function() {
                            if (typeof window.N88ProjectDetails !== "undefined") {
                                console.log("✓ N88ProjectDetails is now available!");
                                if (typeof window.N88ProjectDetails.init === "function") {
                                    window.N88ProjectDetails.init();
                                }
                            } else {
                                console.error("✗ Script loaded but N88ProjectDetails is still undefined");
                                console.error("Check browser console for JavaScript errors in n88-rfq-modal.js");
                            }
                        }, 200);
                    };
                    script.onerror = function() {
                        console.error("✗ Failed to load n88-rfq-modal.js from: ' . esc_js( $js_url ) . '");
                        console.error("Please check that the file exists and is accessible");
                    };
                    document.head.appendChild(script);
                } else if (typeof window.N88ProjectDetails !== "undefined") {
                    console.log("N88ProjectDetails already available");
                    if (typeof window.N88ProjectDetails.init === "function") {
                        window.N88ProjectDetails.init();
                    }
                } else {
                    // Script tag exists but object not available - wait for it
                    console.log("Waiting for N88ProjectDetails to become available...");
                    var attempts = 0;
                    var maxAttempts = 50;
                    var checkInterval = setInterval(function() {
                        attempts++;
                        if (typeof window.N88ProjectDetails !== "undefined") {
                            clearInterval(checkInterval);
                            console.log("N88ProjectDetails loaded!");
                            if (typeof window.N88ProjectDetails.init === "function") {
                                window.N88ProjectDetails.init();
                            }
                        } else if (attempts >= maxAttempts) {
                            clearInterval(checkInterval);
                            console.error("N88ProjectDetails failed to load after 5 seconds");
                        }
                    }, 100);
                }
            })();
            </script>';
            
            return $output;
    }

    /**
     * Render the project detail view.
     *
     * @param array $project Project data with metadata.
     */
    private function render_project_detail_view( $project ) {
        global $wpdb;

        $project_id  = $project['id'];
        $status      = (int) $project['status'];
        $metadata    = $project['metadata'] ?? array();
        $has_client_updates = ! empty( $metadata['n88_has_client_updates'] ) && '1' === $metadata['n88_has_client_updates'];
        $has_admin_updates = ! empty( $metadata['n88_has_admin_updates'] ) && '1' === $metadata['n88_has_admin_updates'];
        $last_admin_update = $metadata['n88_last_admin_update'] ?? '';
        $last_admin_update_by = $metadata['n88_last_admin_update_by'] ?? '';
            $last_client_update = $metadata['n88_last_client_update'] ?? '';
            $last_client_update_by = $metadata['n88_last_client_update_by'] ?? '';
            $last_client_updater = '';
            if ( $last_client_update_by ) {
                $client_user = get_userdata( (int) $last_client_update_by );
                $last_client_updater = $client_user ? $client_user->display_name : '';
            }

        // Parse repeater items
        $repeater_json = $metadata['n88_repeater_raw'] ?? '[]';
        $repeater_items = is_string( $repeater_json ) ? json_decode( $repeater_json, true ) : array();

        // Get attachment IDs
        $files_json = $metadata['n88_files'] ?? '[]';
        $attachment_ids = is_string( $files_json ) ? json_decode( $files_json, true ) : array();

        // Get file URLs and titles
        $files_data = array();
        if ( ! empty( $attachment_ids ) ) {
            foreach ( $attachment_ids as $attachment_id ) {
                $file_url = wp_get_attachment_url( $attachment_id );
                $file_title = get_the_title( $attachment_id );
                $file_type = get_post_mime_type( $attachment_id );
                
                if ( $file_url ) {
                    $files_data[] = array(
                        'id'    => $attachment_id,
                        'url'   => $file_url,
                        'title' => $file_title ?: 'Attachment',
                        'type'  => $file_type,
                    );
                }
            }
        }

        // Get latest quote for this project
        $quote_table = $wpdb->prefix . 'project_quotes';
        $latest_quote = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$quote_table} WHERE project_id = %d ORDER BY created_at DESC LIMIT 1",
                $project_id
            )
        );

            // Phase 2B: Get flag summary if available
            $flag_summary = array(
                'needs_review' => 0,
                'urgent' => 0,
                'changed' => 0,
            );
            if ( class_exists( 'N88_RFQ_Item_Flags' ) ) {
                $flags_class = new N88_RFQ_Item_Flags();
                $flag_summary = $flags_class->get_flag_summary( $project_id );
            }

            // Check if in extraction mode
            $is_extraction_mode = false;
            if ( class_exists( 'N88_RFQ_PDF_Extractor' ) ) {
                $is_extraction_mode = N88_RFQ_PDF_Extractor::is_extraction_mode( $project_id );
            }

            // Get updated_by user info
            $updated_by_user = null;
            $updated_by_name = 'Client';
            if ( ! empty( $project['updated_by'] ) ) {
                $updated_by_user = get_userdata( $project['updated_by'] );
                $updated_by_name = $updated_by_user ? $updated_by_user->display_name : ( current_user_can( 'manage_options' ) ? 'Admin' : 'Client' );
            } elseif ( current_user_can( 'manage_options' ) ) {
                $updated_by_name = 'Admin';
            }

            // Get production status from metadata
            $production_status = $metadata['n88_production_status'] ?? null;

            // Determine status badge and text
            $status_badge_class = 'n88-status-draft';
            $status_badge_text = 'Draft';
            
            if ( N88_RFQ_STATUS_SUBMITTED === $status ) {
                if ( 'completed' === $production_status ) {
                    $status_badge_class = 'n88-status-completed';
                    $status_badge_text = 'Completed';
                } elseif ( 'in_production' === $production_status ) {
                    $status_badge_class = 'n88-status-production';
                    $status_badge_text = 'In Production';
                } elseif ( $latest_quote && 'sent' === $latest_quote->quote_status ) {
                    $status_badge_class = 'n88-status-quoted';
                    $status_badge_text = 'Quoted';
                } else {
                    $status_badge_class = 'n88-status-submitted';
                    $status_badge_text = 'Needs Quote';
                }
            }

            $status_badge = '<span class="n88-status-badge ' . esc_attr( $status_badge_class ) . '">' . esc_html( $status_badge_text ) . '</span>';

        // Determine status message based on quote status
        if ( N88_RFQ_STATUS_DRAFT === $status ) {
            $status_message = 'This project is saved as a draft. You can continue editing and submit when ready.';
        } elseif ( $latest_quote && $latest_quote->quote_status === 'sent' ) {
            $status_message = 'Your quote is ready! Download it below.';
        } else {
            $status_message = 'We\'re reviewing your quote – you\'ll receive a detailed proposal shortly.';
        }

            // Get timeline steps from metadata
            $timeline_steps = null;
            $step_info = null;
            if ( ! empty( $metadata['n88_timeline_steps'] ) ) {
                $timeline_steps_json = is_string( $metadata['n88_timeline_steps'] ) 
                    ? json_decode( $metadata['n88_timeline_steps'], true ) 
                    : $metadata['n88_timeline_steps'];
                if ( is_array( $timeline_steps_json ) ) {
                    $timeline_steps = $timeline_steps_json;
                    $completed_steps = 0;
                    $current_step = null;
                    foreach ( $timeline_steps as $step ) {
                        if ( ! empty( $step['completed'] ) ) {
                            $completed_steps++;
                        } elseif ( ! $current_step ) {
                            $current_step = $step;
                        }
                    }
                    $total_steps = count( $timeline_steps );
                    if ( $current_step && $total_steps > 0 ) {
                        $step_number = $completed_steps + 1;
                        $step_name = ! empty( $current_step['name'] ) ? $current_step['name'] : 'In Progress';
                        $step_info = sprintf( 'Step %d of %d – %s', $step_number, $total_steps, $step_name );
                    } elseif ( $total_steps > 0 && $completed_steps === $total_steps ) {
                        $step_info = sprintf( 'Step %d of %d – Completed', $total_steps, $total_steps );
                    }
                }
            }

            // Fallback step info if timeline steps not available
            if ( ! $step_info ) {
                if ( N88_RFQ_STATUS_DRAFT === $status ) {
                    $step_info = 'Step 1 of 6 – Draft';
                } elseif ( N88_RFQ_STATUS_SUBMITTED === $status && ! $latest_quote ) {
                    $step_info = 'Step 2 of 6 – Awaiting Quote';
                } elseif ( $latest_quote && 'sent' === $latest_quote->quote_status && 'in_production' !== $production_status && 'completed' !== $production_status ) {
                    $step_info = 'Step 3 of 6 – Quote Sent';
                } elseif ( 'in_production' === $production_status ) {
                    $step_info = 'Step 4 of 6 – In Production';
                } elseif ( 'completed' === $production_status ) {
                    $step_info = 'Step 6 of 6 – Completed';
                }
            }

            // Format updated date/time
            $updated_date_formatted = 'N/A';
            if ( ! empty( $project['updated_at'] ) ) {
                $updated_timestamp = strtotime( $project['updated_at'] );
                $updated_date_formatted = date_i18n( 'M j, Y', $updated_timestamp ) . ' – ' . date_i18n( 'H:i', $updated_timestamp );
        }

        $created_date    = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $project['created_at'] ) );
        $submitted_date  = ! empty( $project['submitted_at'] ) 
            ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $project['submitted_at'] ) )
            : '';

        ob_start();
        ?>
        <div class="n88-project-detail-wrapper">
            <!-- Header -->
            <div class="n88-detail-header">
                <div class="n88-header-top">
                        <div class="n88-header-title">
                    <h1><?php echo esc_html( $project['project_name'] ); ?></h1>
                            <?php if ( $has_client_updates && current_user_can( 'manage_options' ) ) : ?>
                                <span class="n88-badge-new-updates">NEW UPDATES</span>
                            <?php endif; ?>
                            <?php if ( $has_admin_updates && ! current_user_can( 'manage_options' ) ) : ?>
                                <span class="n88-badge-new-updates">NEW UPDATES</span>
                            <?php endif; ?>
                        </div>
                    <div class="n88-header-badges">
                        <?php echo wp_kses_post( $status_badge ); ?>
                    </div>
                </div>
                <div class="n88-header-meta">
                    <p><strong>Type:</strong> <?php echo esc_html( $project['project_type'] ); ?></p>
                    <p><strong>Created:</strong> <?php echo esc_html( $created_date ); ?></p>
                    <?php if ( ! empty( $submitted_date ) ) : ?>
                        <p><strong>Submitted:</strong> <?php echo esc_html( $submitted_date ); ?></p>
                    <?php endif; ?>
                        <?php if ( $has_client_updates && current_user_can( 'manage_options' ) ) : ?>
                            <p><strong>Updated by Client:</strong>
                                <?php
                                    if ( $last_client_updater ) {
                                        echo esc_html( $last_client_updater ) . ' • ';
                                    }
                                    if ( $last_client_update ) {
                                        echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_client_update ) ) );
                                    }
                                ?>
                            </p>
                        <?php endif; ?>
                        <?php if ( $has_admin_updates && ! current_user_can( 'manage_options' ) ) : ?>
                            <p><strong>Updated by Admin:</strong>
                                <?php
                                    $last_admin_updater = '';
                                    if ( $last_admin_update_by ) {
                                        $admin_user = get_userdata( (int) $last_admin_update_by );
                                        $last_admin_updater = $admin_user ? $admin_user->display_name : '';
                                    }
                                    if ( $last_admin_updater ) {
                                        echo esc_html( $last_admin_updater ) . ' • ';
                                    }
                                    if ( $last_admin_update ) {
                                        echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_admin_update ) ) );
                                    }
                                ?>
                            </p>
                    <?php endif; ?>
                    </div>
                </div>

                <!-- Status Summary Block -->
                <div class="n88-status-summary-block">
                    <div class="n88-status-summary-content">
                        <div class="n88-status-badge <?php echo esc_attr( $status_badge_class ); ?>"><?php echo esc_html( $status_badge_text ); ?></div>
                        <div class="n88-status-meta">
                            <div class="n88-status-meta-item">
                                <span class="n88-meta-label">Last updated:</span>
                                <span class="n88-meta-value"><?php echo esc_html( $updated_date_formatted ); ?></span>
                            </div>
                            <div class="n88-status-meta-item">
                                <span class="n88-meta-label">Updated by:</span>
                                <span class="n88-meta-value"><?php echo esc_html( $updated_by_name ); ?></span>
                            </div>
                            <?php if ( $step_info ) : ?>
                            <div class="n88-status-meta-item">
                                <span class="n88-meta-label">Step:</span>
                                <span class="n88-meta-value"><?php echo esc_html( $step_info ); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                </div>
            </div>

            <!-- Status Info Panel -->
            <div class="n88-status-info-panel <?php echo N88_RFQ_STATUS_DRAFT === $status ? 'n88-status-draft' : 'n88-status-submitted'; ?>">
                <p><?php echo esc_html( $status_message ); ?></p>
            </div>

            <!-- Project Summary Section -->
            <div class="n88-detail-section">
                <h2>Project Summary</h2>
                <div class="n88-summary-grid">
                    <div class="n88-summary-item">
                        <label>Timeline</label>
                        <p><?php echo esc_html( $project['timeline'] ); ?></p>
                    </div>
                    <div class="n88-summary-item">
                        <label>Budget Range</label>
                        <p><?php echo esc_html( $project['budget_range'] ); ?></p>
                    </div>
                    <?php if ( ! empty( $metadata['n88_sourcing_category'] ) ) : ?>
                        <div class="n88-summary-item">
                            <label>Sourcing Category</label>
                            <p><?php echo esc_html( $metadata['n88_sourcing_category'] ); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                    
                    <?php if ( $is_extraction_mode || array_sum( $flag_summary ) > 0 ) : ?>
                        <!-- Phase 2B: Extraction Status & Flags Summary -->
                        <div class="n88-extraction-status-section" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 6px; border: 1px solid #ddd;">
                            <h3 style="margin-top: 0; font-size: 16px; color: #333; margin-bottom: 15px;">Extraction Status & Flags</h3>
                            <div class="n88-status-flags-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 10px;">
                                <?php
                                // Count extracted vs needs review items
                                $extracted_count = 0;
                                $needs_review_count = 0;
                                foreach ( $repeater_items as $item ) {
                                    if ( ! empty( $item['extracted'] ) ) {
                                        if ( isset( $item['extraction_status'] ) && 'needs_review' === $item['extraction_status'] ) {
                                            $needs_review_count++;
                                        } else {
                                            $extracted_count++;
                                        }
                                    }
                                }
                                ?>
                                <?php if ( $extracted_count > 0 ) : ?>
                                    <div class="n88-status-flag-item" style="padding: 12px; background: #e8f5e9; border-radius: 4px; border-left: 4px solid #2e7d32;">
                                        <strong style="color: #2e7d32; display: block; margin-bottom: 5px;">✔ Extracted</strong>
                                        <span style="font-size: 24px; font-weight: bold; color: #2e7d32;"><?php echo esc_html( $extracted_count ); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $needs_review_count > 0 ) : ?>
                                    <div class="n88-status-flag-item" style="padding: 12px; background: #fff3e0; border-radius: 4px; border-left: 4px solid #e65100;">
                                        <strong style="color: #e65100; display: block; margin-bottom: 5px;">■ Needs Review</strong>
                                        <span style="font-size: 24px; font-weight: bold; color: #e65100;"><?php echo esc_html( $needs_review_count ); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $flag_summary['changed'] > 0 ) : ?>
                                    <div class="n88-status-flag-item" style="padding: 12px; background: #e3f2fd; border-radius: 4px; border-left: 4px solid #1976d2;">
                                        <strong style="color: #1976d2; display: block; margin-bottom: 5px;">◆ Change Flags</strong>
                                        <span style="font-size: 24px; font-weight: bold; color: #1976d2;"><?php echo esc_html( $flag_summary['changed'] ); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $flag_summary['urgent'] > 0 ) : ?>
                                    <div class="n88-status-flag-item" style="padding: 12px; background: #ffebee; border-radius: 4px; border-left: 4px solid #c62828;">
                                        <strong style="color: #c62828; display: block; margin-bottom: 5px;">⚠ Urgent Flags</strong>
                                        <span style="font-size: 24px; font-weight: bold; color: #c62828;"><?php echo esc_html( $flag_summary['urgent'] ); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
            </div>

            <!-- Contact Information Section -->
            <div class="n88-detail-section">
                <h2>Contact Information</h2>
                <div class="n88-contact-grid">
                    <div class="n88-contact-item">
                        <label>Company Name</label>
                        <p><?php echo esc_html( $metadata['n88_company_name'] ?? 'N/A' ); ?></p>
                    </div>
                    <div class="n88-contact-item">
                        <label>Contact Name</label>
                        <p><?php echo esc_html( $metadata['n88_contact_name'] ?? 'N/A' ); ?></p>
                    </div>
                    <div class="n88-contact-item">
                        <label>Email</label>
                        <p><a href="mailto:<?php echo esc_attr( $metadata['n88_email'] ?? '' ); ?>"><?php echo esc_html( $metadata['n88_email'] ?? 'N/A' ); ?></a></p>
                    </div>
                    <div class="n88-contact-item">
                        <label>Phone</label>
                        <p><?php echo esc_html( $metadata['n88_phone'] ?? 'N/A' ); ?></p>
                    </div>
                    <div class="n88-contact-item">
                        <label>Location</label>
                        <p><?php echo esc_html( $metadata['n88_location'] ?? 'N/A' ); ?></p>
                    </div>
                </div>
            </div>

            <!-- Items Section -->
            <?php if ( ! empty( $repeater_items ) ) : ?>
                <div class="n88-detail-section">
                    <h2>Items (<?php echo count( $repeater_items ); ?> items)<?php if ( current_user_can( 'manage_options' ) ) : ?> <span style="font-size: 14px; font-weight: normal; color: #666;">(Click fields to edit)</span><?php endif; ?></h2>
                    <div class="n88-items-table-container">
                        <table class="n88-items-detail-table">
                            <thead>
                                <tr>
                                    <th>Item #</th>
                                        <th>Primary Material</th>
                                    <th>Dimensions (L×D×H)</th>
                                        <th>Quantity</th>
                                        <th>Construction Notes</th>
                                        <th>Finishes</th>
                                        <th>Files</th>
                                    <th>Notes</th>
                                    <th>Comments</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $repeater_items as $index => $item ) : ?>
                                            <?php 
                                        $item_id = 'item_' . $index;
                                        $comment_count = N88_RFQ_Comments::get_comment_count( $project_id, $item_id );
                                        $is_admin = current_user_can( 'manage_options' );
                                            
                                            // Get item files
                                            $item_file_ids = $this->get_item_file_attachments( $project_id, $item_id );
                                            $item_files = array();
                                            foreach ( $item_file_ids as $file_id ) {
                                                $file_url = wp_get_attachment_url( $file_id );
                                                $file_name = basename( get_attached_file( $file_id ) );
                                                if ( $file_url ) {
                                                    $item_files[] = array(
                                                        'id' => $file_id,
                                                        'url' => $file_url,
                                                        'name' => $file_name,
                                                    );
                                                }
                                            }
                                            
                                            // Handle dimensions (support both old and new format)
                                                $length = $item['length_in'] ?? '';
                                                $depth = $item['depth_in'] ?? '';
                                                $height = $item['height_in'] ?? '';
                                            if ( isset( $item['dimensions'] ) && is_array( $item['dimensions'] ) ) {
                                                $length = $item['dimensions']['length'] ?? $length;
                                                $depth = $item['dimensions']['depth'] ?? $depth;
                                                $height = $item['dimensions']['height'] ?? $height;
                                            }
                                            
                                            // Handle primary material (support both old and new format)
                                            $primary_material = $item['primary_material'] ?? '';
                                            if ( isset( $item['materials'] ) && is_array( $item['materials'] ) ) {
                                                $primary_material = implode( ', ', $item['materials'] );
                                            }
                                    ?>
                                    <tr class="n88-item-row" data-item-index="<?php echo esc_attr( $index ); ?>" data-project-id="<?php echo esc_attr( $project_id ); ?>" data-edit-mode="false">
                                        <td><?php echo (int) $index + 1; ?></td>
                                        <td>
                                                <span class="n88-item-display n88-item-primary_material"><?php echo esc_html( $primary_material ); ?></span>
                                                <input type="text" class="n88-editable-field" data-field="primary_material" value="<?php echo esc_attr( $primary_material ); ?>" style="display: none; width: 100%; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
                                        </td>
                                        <td>
                                            <span class="n88-item-display n88-item-dimensions"><?php echo esc_html( $length . '×' . $depth . '×' . $height ); ?></span>
                                            <div class="n88-editable-field-group" data-field="dimensions" style="display: none;">
                                                <div style="display: flex; gap: 4px; align-items: center;">
                                                    <input type="text" class="n88-editable-field" data-field="length" placeholder="L" value="<?php echo esc_attr( $length ); ?>" style="width: 60px; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
                                                    <span>×</span>
                                                    <input type="text" class="n88-editable-field" data-field="depth" placeholder="D" value="<?php echo esc_attr( $depth ); ?>" style="width: 60px; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
                                                    <span>×</span>
                                                    <input type="text" class="n88-editable-field" data-field="height" placeholder="H" value="<?php echo esc_attr( $height ); ?>" style="width: 60px; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="n88-item-display n88-item-quantity"><?php echo esc_html( $item['quantity'] ?? '' ); ?></span>
                                            <input type="number" class="n88-editable-field" data-field="quantity" value="<?php echo esc_attr( $item['quantity'] ?? '' ); ?>" style="display: none; width: 80px; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
                                        </td>
                                            <td>
                                                <span class="n88-item-display n88-item-construction_notes"><?php echo nl2br( esc_html( $item['construction_notes'] ?? '' ) ); ?></span>
                                                <textarea class="n88-editable-field" data-field="construction_notes" rows="2" style="display: none; width: 100%; padding: 4px; border: 1px solid #ddd; border-radius: 3px; resize: vertical;"><?php echo esc_textarea( $item['construction_notes'] ?? '' ); ?></textarea>
                                            </td>
                                            <td>
                                                <span class="n88-item-display n88-item-finishes"><?php echo esc_html( $item['finishes'] ?? '' ); ?></span>
                                                <input type="text" class="n88-editable-field" data-field="finishes" value="<?php echo esc_attr( $item['finishes'] ?? '' ); ?>" style="display: none; width: 100%; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
                                            </td>
                                            <td>
                                                <?php if ( ! empty( $item_files ) ) : ?>
                                                    <div class="n88-item-files-list" style="display: flex; flex-wrap: wrap; gap: 8px;">
                                                        <?php foreach ( $item_files as $file ) : ?>
                                            <?php 
                                                                $file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
                                                                $is_image = in_array( $file_ext, array( 'jpg', 'jpeg', 'png', 'gif' ) );
                                                                $is_pdf = ( $file_ext === 'pdf' );
                                                                $is_dwg = ( $file_ext === 'dwg' );
                                                                
                                                                // Get thumbnail for images
                                                                $thumbnail_url = '';
                                                                if ( $is_image && ! empty( $file['id'] ) ) {
                                                                    $thumbnail_url = wp_get_attachment_image_url( $file['id'], 'thumbnail' );
                                                                    if ( ! $thumbnail_url ) {
                                                                        $thumbnail_url = $file['url'];
                                                                    }
                                                                }
                                                            ?>
                                                            <div class="n88-item-file-preview-item" style="position: relative; display: inline-block; margin-bottom: 8px;">
                                                                <a href="<?php echo esc_url( $file['url'] ); ?>" target="_blank" class="n88-item-file-preview-link" title="<?php echo esc_attr( $file['name'] ); ?>" style="display: block; text-decoration: none; color: inherit;">
                                                                    <?php if ( $is_image && $thumbnail_url ) : ?>
                                                                        <div style="width: 60px; height: 60px; border-radius: 4px; overflow: hidden; border: 1px solid #ddd; margin-bottom: 4px;">
                                                                            <img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="<?php echo esc_attr( $file['name'] ); ?>" style="width: 100%; height: 100%; object-fit: cover; cursor: pointer;">
                                                                        </div>
                                                                    <?php elseif ( $is_pdf ) : ?>
                                                                        <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #f44336, #e91e63); border-radius: 4px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 12px; margin-bottom: 4px; cursor: pointer;">
                                                                            PDF
                                                                        </div>
                                                                    <?php elseif ( $is_dwg ) : ?>
                                                                        <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #ff9800, #f57c00); border-radius: 4px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 12px; margin-bottom: 4px; cursor: pointer;">
                                                                            DWG
                                                                        </div>
                                                                    <?php else : ?>
                                                                        <div style="width: 60px; height: 60px; background: #f5f5f5; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #666; font-weight: 600; font-size: 10px; margin-bottom: 4px; border: 1px solid #ddd; cursor: pointer;">
                                                                            FILE
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <div style="font-size: 11px; color: #007cba; max-width: 60px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center;" title="<?php echo esc_attr( $file['name'] ); ?>">
                                                                        <?php echo esc_html( $file['name'] ); ?>
                                                                    </div>
                                                                </a>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else : ?>
                                                    <span style="color: #999;">—</span>
                                                <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="n88-item-display n88-item-notes"><?php echo esc_html( $item['notes'] ?? '' ); ?></span>
                                            <textarea class="n88-editable-field" data-field="notes" rows="2" style="display: none; width: 100%; padding: 4px; border: 1px solid #ddd; border-radius: 3px; resize: vertical;"><?php echo esc_textarea( $item['notes'] ?? '' ); ?></textarea>
                                        </td>
                                        <td>
                                                <button type="button" class="btn-comment-toggle" data-project-id="<?php echo esc_attr( $project_id ); ?>" data-item-id="<?php echo esc_attr( $item_id ); ?>">
                                                💬 <?php echo (int) $comment_count; ?>
                                            </button>
                                        </td>
                                        <td class="n88-item-actions">
                                            <button type="button" class="n88-edit-item-btn button button-small" data-item-index="<?php echo esc_attr( $index ); ?>" style="padding: 4px 12px; font-size: 12px; margin-right: 4px;">Edit</button>
                                            <div class="n88-item-edit-actions" style="display: none;">
                                                <button type="button" class="n88-save-item-btn button button-small button-primary" data-item-index="<?php echo esc_attr( $index ); ?>" data-project-id="<?php echo esc_attr( $project_id ); ?>" style="padding: 4px 12px; font-size: 12px; margin-right: 4px;">Save</button>
                                                <button type="button" class="n88-cancel-item-btn button button-small" data-item-index="<?php echo esc_attr( $index ); ?>" style="padding: 4px 12px; font-size: 12px;">Cancel</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <!-- Comment Section Row -->
                                    <tr class="n88-comment-section-row" id="comment-row-<?php echo esc_attr( $item_id ); ?>" style="display: none;">
                                        <td colspan="10">
                                            <div class="n88-item-comments">
                                                <!-- Comment Form -->
                                                <div class="n88-comment-form">
                                                    <h4>Add Comment</h4>
                                                        <textarea class="n88-comment-input" placeholder="Add your comment here..." data-project-id="<?php echo esc_attr( $project_id ); ?>" data-item-id="<?php echo esc_attr( $item_id ); ?>" data-video-id=""></textarea>
                                                        <div class="n88-comment-form-options" style="display: flex; align-items: center; gap: 15px; margin-top: 10px;">
                                                            <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                                                <input type="checkbox" class="n88-comment-urgent" data-project-id="<?php echo esc_attr( $project_id ); ?>" data-item-id="<?php echo esc_attr( $item_id ); ?>">
                                                                <span style="color: #c62828; font-weight: 600;">⚠ Mark as Urgent</span>
                                                            </label>
                                                        </div>
                                                        <button type="button" class="btn-submit-comment" data-project-id="<?php echo esc_attr( $project_id ); ?>" data-item-id="<?php echo esc_attr( $item_id ); ?>" data-video-id="">Post Comment</button>
                                                </div>
                                                
                                                <!-- Comments List -->
                                                <div class="n88-comments-list" id="comments-list-<?php echo esc_attr( $item_id ); ?>">
                                                    <p class="n88-loading">Loading comments...</p>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

                <!-- Quote Section - Phase 2B: Enhanced with Instant Pricing -->
                <?php if ( $latest_quote ) : 
                    $quote_formatted = N88_RFQ_Quotes::format_quote( $latest_quote );
                    $has_pricing = ! empty( $quote_formatted['unit_price'] ) || ! empty( $quote_formatted['total_price'] );
                ?>
                    <div class="n88-detail-section n88-quote-section" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-left: none;">
                        <h2 style="color: white; margin-top: 0;">✅ Quote Ready</h2>
                        <div class="n88-quote-card" style="background: white; color: #333;">
                        <div class="n88-quote-info">
                                <div class="n88-quote-info-item">
                                    <span class="n88-quote-info-label">Status</span>
                                    <span class="n88-quote-info-value">
                                <?php 
                                    if ( $latest_quote->quote_status === 'sent' ) {
                                        echo '<span style="color: #4CAF50; font-weight: bold;">✓ Quote Sent</span>';
                                    } elseif ( $latest_quote->quote_status === 'quote_updated' ) {
                                        echo '<span style="color: #ff9800; font-weight: bold;">✓ Quote Updated</span>';
                                    } else {
                                        echo '<span style="color: #FF9800; font-weight: bold;">Pending Review</span>';
                                    }
                                ?>
                                    </span>
                                </div>
                                <div class="n88-quote-info-item">
                                    <span class="n88-quote-info-label">Created</span>
                                    <span class="n88-quote-info-value"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $latest_quote->created_at ) ) ); ?></span>
                                </div>
                            <?php if ( $latest_quote->sent_at ) : ?>
                                    <div class="n88-quote-info-item">
                                        <span class="n88-quote-info-label">Sent</span>
                                        <span class="n88-quote-info-value"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $latest_quote->sent_at ) ) ); ?></span>
                                    </div>
                            <?php endif; ?>
                            </div>

                            <?php if ( $has_pricing ) : ?>
                                <!-- Phase 2B: Instant Pricing Display -->
                                <div style="background: #f0f7ff; padding: 20px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #007cba;">
                                    <h3 style="margin-top: 0; color: #007cba; font-size: 18px;">💰 Pricing Details</h3>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                                        <?php if ( ! empty( $quote_formatted['unit_price'] ) ) : ?>
                                            <div style="padding: 12px; background: white; border-radius: 4px; border: 1px solid #ddd;">
                                                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Unit Price</div>
                                                <div style="font-size: 20px; font-weight: bold; color: #007cba;">$<?php echo number_format( $quote_formatted['unit_price'], 2 ); ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $quote_formatted['total_price'] ) ) : ?>
                                            <div style="padding: 12px; background: white; border-radius: 4px; border: 1px solid #ddd;">
                                                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Total Price</div>
                                                <div style="font-size: 24px; font-weight: bold; color: #4CAF50;">$<?php echo number_format( $quote_formatted['total_price'], 2 ); ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $quote_formatted['cbm_volume'] ) ) : ?>
                                            <div style="padding: 12px; background: white; border-radius: 4px; border: 1px solid #ddd;">
                                                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Total Volume (CBM)</div>
                                                <div style="font-size: 18px; font-weight: bold; color: #333;"><?php echo number_format( $quote_formatted['cbm_volume'], 4 ); ?> m³</div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $quote_formatted['lead_time'] ) ) : ?>
                                            <div style="padding: 12px; background: white; border-radius: 4px; border: 1px solid #ddd;">
                                                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Lead Time</div>
                                                <div style="font-size: 18px; font-weight: bold; color: #333;"><?php echo esc_html( $quote_formatted['lead_time'] ); ?></div>
                                            </div>
                            <?php endif; ?>
                        </div>
                        
                                    <?php if ( ! empty( $quote_formatted['volume_rules_applied'] ) && is_array( $quote_formatted['volume_rules_applied'] ) ) : ?>
                                        <div style="margin-top: 15px; padding: 12px; background: #fff3cd; border-radius: 4px; border-left: 4px solid #ffc107;">
                                            <strong style="color: #856404;">Volume Rules Applied:</strong>
                                            <ul style="margin: 8px 0 0 20px; color: #856404;">
                                                <?php foreach ( $quote_formatted['volume_rules_applied'] as $rule ) : ?>
                                                    <li><?php echo esc_html( $rule ); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ( ! empty( $quote_formatted['labor_cost'] ) || ! empty( $quote_formatted['materials_cost'] ) || ! empty( $quote_formatted['overhead_cost'] ) ) : ?>
                                        <div style="margin-top: 15px; padding: 12px; background: #f9f9f9; border-radius: 4px;">
                                            <strong style="color: #555;">Cost Breakdown:</strong>
                                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 10px; font-size: 14px;">
                                                <?php if ( ! empty( $quote_formatted['labor_cost'] ) ) : ?>
                                                    <div>Labor: $<?php echo number_format( $quote_formatted['labor_cost'], 2 ); ?></div>
                                                <?php endif; ?>
                                                <?php if ( ! empty( $quote_formatted['materials_cost'] ) ) : ?>
                                                    <div>Materials: $<?php echo number_format( $quote_formatted['materials_cost'], 2 ); ?></div>
                                                <?php endif; ?>
                                                <?php if ( ! empty( $quote_formatted['overhead_cost'] ) ) : ?>
                                                    <div>Overhead: $<?php echo number_format( $quote_formatted['overhead_cost'], 2 ); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ( ! empty( $quote_formatted['margin_percentage'] ) ) : ?>
                                                <div style="margin-top: 8px; font-size: 14px; color: #666;">
                                                    Margin: <?php echo number_format( $quote_formatted['margin_percentage'], 2 ); ?>%
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Client Message - Shown after pricing box -->
                            <?php if ( ! empty( $latest_quote->client_message ) ) : ?>
                                <div style="margin-top: 20px; padding: 15px; background: #fff8e1; border-radius: 4px; border-left: 4px solid #ff9800;">
                                    <strong style="color: #bf360c;">📢 Important Message from Admin:</strong>
                                    <p style="margin: 8px 0 0 0; color: #856404;"><?php echo nl2br( esc_html( $latest_quote->client_message ) ); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div style="display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap;">
                        <?php 
                            // Use format_quote to get the proper file URL
                            $quote_file_url = '';
                            if ( ! empty( $latest_quote->quote_file_path ) ) {
                                $quote_formatted = N88_RFQ_Quotes::format_quote( $latest_quote );
                                $quote_file_url = $quote_formatted['quote_file_url'] ?? '';
                                
                                // Fallback to direct path if format_quote doesn't return URL
                                if ( empty( $quote_file_url ) ) {
                                    $quote_file_url = wp_get_upload_dir()['baseurl'] . '/' . $latest_quote->quote_file_path;
                                }
                            }
                            
                            if ( ! empty( $quote_file_url ) ) : 
                        ?>
                                <a href="<?php echo esc_url( $quote_file_url ); ?>" 
                                   class="btn btn-primary" 
                                   target="_blank"
                                   style="background: #007cba; color: white; padding: 12px 24px; border-radius: 4px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
                                        📄 View Quote PDF
                                    </a>
                                <?php else : ?>
                                    <span style="padding: 12px 24px; background: #f0f0f0; border-radius: 4px; color: #666;">
                                        Quote file not available
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php 
                            // Admin notes are only visible to admins, not to regular users
                            if ( ! empty( $latest_quote->admin_notes ) && current_user_can( 'manage_options' ) ) : ?>
                                <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px; border-left: 4px solid #007cba;">
                                    <strong>Admin Notes (Internal):</strong>
                                    <p style="margin: 8px 0 0 0; color: #666;"><?php echo nl2br( esc_html( $latest_quote->admin_notes ) ); ?></p>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endif; ?>

            <!-- Files Section -->
            <?php if ( ! empty( $files_data ) ) : ?>
                <div class="n88-detail-section">
                    <h2>Supporting Files (<?php echo count( $files_data ); ?> files)</h2>
                    <div class="n88-files-list">
                        <?php foreach ( $files_data as $file ) : ?>
                            <?php
                                $is_image = in_array( $file['type'], array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true );
                                $is_pdf = 'application/pdf' === $file['type'];
                                    $is_video = in_array( $file['type'], array( 'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime' ), true );
                                    $video_id = $is_video ? 'video-' . $file['id'] : '';
                                    $comment_count = $is_video ? N88_RFQ_Comments::get_comment_count( $project_id, null ) : 0;
                                    // For videos, we need to count comments with this video_id
                                    if ( $is_video ) {
                                        $video_comments = N88_RFQ_Comments::get_comments( $project_id, null, $video_id );
                                        $comment_count = count( $video_comments );
                                    }
                            ?>
                            <div class="n88-file-item">
                                <div class="n88-file-icon">
                                    <?php if ( $is_pdf ) : ?>
                                        <span class="n88-icon n88-icon-pdf">PDF</span>
                                    <?php elseif ( $is_image ) : ?>
                                        <span class="n88-icon n88-icon-image">IMG</span>
                                        <?php elseif ( $is_video ) : ?>
                                            <span class="n88-icon n88-icon-video">VIDEO</span>
                                    <?php else : ?>
                                        <span class="n88-icon n88-icon-file">FILE</span>
                                    <?php endif; ?>
                                </div>
                                <div class="n88-file-info">
                                    <a href="<?php echo esc_url( $file['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html( $file['title'] ); ?>
                                    </a>
                                        <?php if ( $is_video ) : ?>
                                            <button type="button" class="btn-comment-toggle" data-project-id="<?php echo esc_attr( $project_id ); ?>" data-video-id="<?php echo esc_attr( $video_id ); ?>" style="margin-left: 10px; padding: 4px 12px; font-size: 12px;">
                                                💬 <?php echo (int) $comment_count; ?>
                                            </button>
                                        <?php endif; ?>
                                </div>
                            </div>
                                <?php if ( $is_video ) : ?>
                                    <!-- Video Comment Section (hidden by default) -->
                                    <div class="n88-video-comment-section" id="video-comment-section-<?php echo esc_attr( $video_id ); ?>" style="display: none; margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 6px; border: 1px solid #ddd;">
                                        <h4 style="margin-top: 0;">Comments for Video: <?php echo esc_html( $file['title'] ); ?></h4>
                                        
                                        <!-- Comment Form -->
                                        <div class="n88-comment-form">
                                            <textarea class="n88-comment-input" placeholder="Add your comment about this video..." data-project-id="<?php echo esc_attr( $project_id ); ?>" data-video-id="<?php echo esc_attr( $video_id ); ?>"></textarea>
                                            <div class="n88-comment-form-options" style="display: flex; align-items: center; gap: 15px; margin-top: 10px;">
                                                <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                                    <input type="checkbox" class="n88-comment-urgent" data-project-id="<?php echo esc_attr( $project_id ); ?>" data-video-id="<?php echo esc_attr( $video_id ); ?>">
                                                    <span style="color: #c62828; font-weight: 600;">⚠ Mark as Urgent</span>
                                                </label>
                                            </div>
                                            <button type="button" class="btn-submit-comment" data-project-id="<?php echo esc_attr( $project_id ); ?>" data-video-id="<?php echo esc_attr( $video_id ); ?>">Post Comment</button>
                                        </div>
                                        
                                        <!-- Comments List -->
                                        <div class="n88-comments-list" id="comments-list-video-<?php echo esc_attr( $video_id ); ?>" style="margin-top: 20px;">
                                            <p class="n88-loading">Loading comments...</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

                <!-- Project-Level Comments Section -->
                <div class="n88-detail-section">
                    <h2>Project Comments</h2>
                    <?php
                    $project_comment_count = N88_RFQ_Comments::get_comment_count( $project_id, null, null );
                    ?>
                    <p style="color: #666; margin-bottom: 15px;">
                        General comments about this project (<?php echo (int) $project_comment_count; ?> comment<?php echo $project_comment_count !== 1 ? 's' : ''; ?>)
                    </p>
                    
                    <!-- Comment Form -->
                    <div class="n88-comment-form">
                        <textarea class="n88-comment-input" placeholder="Add a general comment about this project..." data-project-id="<?php echo esc_attr( $project_id ); ?>"></textarea>
                        <div class="n88-comment-form-options" style="display: flex; align-items: center; gap: 15px; margin-top: 10px;">
                            <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                <input type="checkbox" class="n88-comment-urgent" data-project-id="<?php echo esc_attr( $project_id ); ?>">
                                <span style="color: #c62828; font-weight: 600;">⚠ Mark as Urgent</span>
                            </label>
                        </div>
                        <button type="button" class="btn-submit-comment" data-project-id="<?php echo esc_attr( $project_id ); ?>">Post Comment</button>
                    </div>
                    
                    <!-- Comments List -->
                    <div class="n88-comments-list" id="comments-list-project-<?php echo esc_attr( $project_id ); ?>" style="margin-top: 20px;">
                        <p class="n88-loading">Loading comments...</p>
                    </div>
                </div>

            <!-- Action Buttons -->
            <div class="n88-detail-actions">
                <a href="<?php echo esc_url( home_url( '/projects/' ) ); ?>" class="btn btn-secondary">Back to Projects</a>
                <?php if ( N88_RFQ_STATUS_DRAFT === $status ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array( 'project_id' => $project_id ), home_url( '/rfq-form/' ) ) ); ?>" class="btn btn-primary">Continue Editing</a>
                <?php endif; ?>
            </div>
        </div>

        <style>
            .n88-project-detail-wrapper {
                max-width: 1000px;
                margin: 0 auto;
                padding: 20px;
            }

            /* Header */
            .n88-detail-header {
                background-color: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }

            .n88-header-top {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
                gap: 20px;
            }

                .n88-header-title {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    flex-wrap: wrap;
            }

            .n88-header-top h1 {
                margin: 0;
                font-size: 2em;
                color: #333;
                flex: 1;
            }

                .n88-badge-new-updates {
                    background: linear-gradient(90deg, #ff7043, #d84315);
                    color: #fff;
                    padding: 4px 12px;
                    border-radius: 999px;
                    font-size: 0.8em;
                    font-weight: 700;
                    letter-spacing: 0.5px;
                    text-transform: uppercase;
            }

            .n88-header-badges {
                white-space: nowrap;
            }

            .n88-header-meta {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }

            .n88-header-meta p {
                margin: 0;
                color: #666;
                font-size: 0.95em;
            }

                /* Status Summary Block */
                .n88-status-summary-block {
                    padding: 16px 20px;
                    background: #f8f9fa;
                    border-bottom: 1px solid #e0e0e0;
                    margin-bottom: 20px;
                    border-radius: 4px;
                    border: 1px solid #ddd;
                }

                .n88-status-summary-content {
                    display: flex;
                    align-items: center;
                    gap: 20px;
                    flex-wrap: wrap;
                }

                .n88-status-badge {
                    padding: 6px 14px;
                    border-radius: 4px;
                    font-size: 13px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }

                .n88-status-badge.n88-status-draft {
                    background: #ffc107;
                    color: #333;
                }

                .n88-status-badge.n88-status-submitted {
                    background: #ff9800;
                    color: white;
                }

                .n88-status-badge.n88-status-quoted {
                    background: #4caf50;
                    color: white;
                }

                .n88-status-badge.n88-status-production {
                    background: #2196F3;
                    color: white;
                }

                .n88-status-badge.n88-status-completed {
                    background: #9c27b0;
                    color: white;
                }

                .n88-status-meta {
                    display: flex;
                    gap: 20px;
                    flex-wrap: wrap;
                    align-items: center;
                }

                .n88-status-meta-item {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    font-size: 13px;
                }

                .n88-meta-label {
                    color: #666;
                    font-weight: 500;
                }

                .n88-meta-value {
                    color: #333;
                    font-weight: 600;
            }

            /* Status Info Panel */
            .n88-status-info-panel {
                padding: 15px 20px;
                margin-bottom: 30px;
                border-radius: 4px;
                font-weight: 500;
            }

            .n88-status-info-panel.n88-status-draft {
                background-color: #fff3cd;
                color: #856404;
                border: 1px solid #ffeaa7;
            }

            .n88-status-info-panel.n88-status-submitted {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }

            .n88-status-info-panel p {
                margin: 0;
            }

            /* Detail Sections */
            .n88-detail-section {
                background-color: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }

            .n88-detail-section h2 {
                font-size: 1.5em;
                margin-top: 0;
                margin-bottom: 20px;
                color: #333;
                border-bottom: 2px solid #007cba;
                padding-bottom: 10px;
            }

            /* Summary Grid */
            .n88-summary-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
            }

            .n88-summary-item label {
                display: block;
                font-weight: 600;
                color: #333;
                margin-bottom: 8px;
                font-size: 0.9em;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .n88-summary-item p {
                margin: 0;
                color: #666;
                font-size: 1em;
            }

            /* Contact Grid */
            .n88-contact-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
            }

            .n88-contact-item label {
                display: block;
                font-weight: 600;
                color: #333;
                margin-bottom: 8px;
                font-size: 0.9em;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .n88-contact-item p {
                margin: 0;
                color: #666;
                font-size: 1em;
            }

            .n88-contact-item a {
                color: #007cba;
                text-decoration: none;
            }

            .n88-contact-item a:hover {
                text-decoration: underline;
            }

            /* Items Table */
            .n88-items-table-container {
                overflow-x: auto;
            }

            .n88-items-detail-table {
                width: 100%;
                border-collapse: collapse;
                background-color: #fff;
            }

            .n88-items-detail-table thead {
                background-color: #f0f0f0;
                border-bottom: 2px solid #ddd;
            }

            .n88-items-detail-table th {
                padding: 10px;
                text-align: left;
                font-weight: 600;
                color: #333;
                font-size: 0.9em;
            }

            .n88-items-detail-table td {
                padding: 10px;
                border-bottom: 1px solid #eee;
                color: #666;
                font-size: 0.95em;
            }

            .n88-items-detail-table tbody tr:hover {
                background-color: #f9f9f9;
                }
            .n88-item-files-list {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .n88-item-file-preview-item {
                position: relative;
                display: inline-block;
                margin-bottom: 8px;
            }
            .n88-item-file-preview-link {
                display: block;
                text-decoration: none;
                color: inherit;
                transition: transform 0.2s ease;
            }
            .n88-item-file-preview-link:hover {
                transform: translateY(-2px);
            }
            .n88-item-file-preview-link:hover img,
            .n88-item-file-preview-link:hover > div {
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            }
            .n88-item-file-link {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                color: #007cba;
                text-decoration: none;
                font-size: 13px;
                padding: 4px 8px;
                border-radius: 3px;
                transition: background-color 0.2s;
            }
            .n88-item-file-link:hover {
                background-color: #f0f7ff;
                text-decoration: underline;
            }

            /* Files Section */
            .n88-files-list {
                display: grid;
                gap: 12px;
            }

            .n88-file-item {
                display: flex;
                align-items: center;
                padding: 12px;
                background-color: #f9f9f9;
                border: 1px solid #eee;
                border-radius: 4px;
                transition: all 0.3s ease;
            }

            .n88-file-item:hover {
                background-color: #f0f0f0;
                border-color: #ddd;
            }

            .n88-file-icon {
                margin-right: 12px;
                flex-shrink: 0;
            }

            .n88-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border-radius: 4px;
                font-size: 0.7em;
                font-weight: 600;
                color: white;
                text-align: center;
            }

            .n88-icon-pdf {
                background-color: #d32f2f;
            }

            .n88-icon-image {
                background-color: #1976d2;
            }

            .n88-icon-file {
                background-color: #616161;
            }

            .n88-file-info {
                flex: 1;
                min-width: 0;
            }

            .n88-file-info a {
                display: inline-block;
                color: #007cba;
                text-decoration: none;
                word-break: break-word;
                max-width: 100%;
            }

            .n88-file-info a:hover {
                text-decoration: underline;
            }

            /* Action Buttons */
            .n88-detail-actions {
                display: flex;
                gap: 12px;
                margin-top: 30px;
                flex-wrap: wrap;
            }

            .btn {
                display: inline-block;
                padding: 12px 24px;
                border-radius: 4px;
                text-decoration: none;
                font-weight: 600;
                transition: all 0.3s ease;
                cursor: pointer;
                border: none;
            }

            .btn-primary {
                background-color: #007cba;
                color: white;
            }

            .btn-primary:hover {
                background-color: #005a87;
            }

            .btn-secondary {
                background-color: #6c757d;
                color: white;
            }

            .btn-secondary:hover {
                background-color: #5a6268;
            }

            /* PHASE 2B: PDF Upload Mode Styles */
            .n88-entry-mode-selector {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 30px;
            }

            .n88-mode-toggle {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
            }

            .n88-toggle-label {
                display: flex;
                align-items: center;
                gap: 10px;
                cursor: pointer;
                padding: 12px 16px;
                border-radius: 6px;
                border: 2px solid #dee2e6;
                background: white;
                transition: all 0.3s ease;
                flex: 1;
                min-width: 250px;
            }

            .n88-toggle-label:hover {
                border-color: #2c5aa0;
                background-color: #f0f6ff;
            }

            .entry-mode-radio {
                cursor: pointer;
                margin: 0;
                width: 18px;
                height: 18px;
            }

            .n88-toggle-label input[type="radio"]:checked + .n88-toggle-text {
                color: #2c5aa0;
                font-weight: 600;
            }

            .n88-toggle-label input[type="radio"]:checked {
                accent-color: #2c5aa0;
            }

            .n88-toggle-text {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 0.95em;
            }

            .n88-toggle-text i {
                font-size: 1.2em;
            }

            .n88-entry-mode-content {
                animation: fadeIn 0.3s ease;
            }

            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }

            /* PDF Upload Styles */
            .n88-pdf-upload-section {
                background: #fafbfc;
                border: 1px solid #e1e4e8;
                border-radius: 8px;
                padding: 25px;
            }

            .n88-pdf-upload-info {
                background: #e3f2fd;
                border-left: 4px solid #2196F3;
                padding: 12px 16px;
                border-radius: 4px;
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                gap: 10px;
                color: #1565c0;
                font-size: 0.95em;
            }

            .n88-pdf-dropzone {
                border: 2px dashed #2c5aa0;
                border-radius: 8px;
                padding: 50px 20px;
                text-align: center;
                cursor: pointer;
                background-color: #f0f6ff;
                transition: all 0.3s ease;
                margin-bottom: 20px;
            }

            .n88-pdf-dropzone:hover {
                border-color: #2c5aa0;
                background-color: #e3f2fd;
                box-shadow: 0 4px 12px rgba(44, 90, 160, 0.15);
            }

            .n88-pdf-dropzone.dragover {
                border-color: #2c5aa0;
                background-color: #e3f2fd;
                box-shadow: 0 8px 20px rgba(44, 90, 160, 0.2);
            }

            .n88-pdf-dropzone-content {
                pointer-events: none;
            }

            .n88-pdf-upload-icon {
                width: 60px;
                height: 60px;
                color: #2c5aa0;
                margin-bottom: 15px;
                opacity: 0.7;
            }

            .n88-pdf-dropzone-text {
                font-size: 1.1em;
                font-weight: 600;
                color: #333;
                margin: 0 0 8px 0;
            }

            .n88-pdf-dropzone small {
                color: #666;
                font-size: 0.9em;
            }

            /* Upload Progress */
            .n88-pdf-upload-progress {
                background: white;
                border: 1px solid #dee2e6;
                border-radius: 6px;
                padding: 20px;
                margin-bottom: 20px;
            }

            .progress-bar {
                width: 100%;
                height: 6px;
                background: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
                margin-bottom: 10px;
            }

            .progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #2c5aa0, #7c3aed);
                transition: width 0.3s ease;
                border-radius: 3px;
            }

            .progress-text {
                font-size: 0.9em;
                color: #666;
                margin: 0;
                text-align: center;
            }

            /* Extraction Preview */
            .n88-extraction-preview {
                background: white;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 20px;
                margin-top: 20px;
            }

            .preview-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #e9ecef;
            }

            .preview-header h3 {
                margin: 0;
                color: #333;
                font-size: 1.1em;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .preview-header p {
                margin: 0;
                color: #666;
                font-size: 0.95em;
            }

            .preview-table-container {
                overflow-x: auto;
                    overflow-y: auto;
                    max-height: 500px;
                margin-bottom: 20px;
                    border: 1px solid #dee2e6;
                    border-radius: 4px;
            }

            .extraction-preview-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.9em;
                    min-width: 1000px;
            }

            .extraction-preview-table thead {
                background: #f8f9fa;
                border-bottom: 2px solid #dee2e6;
                    position: sticky;
                    top: 0;
                    z-index: 10;
            }

            .extraction-preview-table th {
                    padding: 10px 8px;
                text-align: left;
                font-weight: 600;
                color: #333;
                    white-space: nowrap;
                    font-size: 0.9em;
            }

            .extraction-preview-table td {
                    padding: 10px 8px;
                border-bottom: 1px solid #e9ecef;
                color: #666;
                    white-space: nowrap;
                    max-width: 150px;
                    overflow: hidden;
                    text-overflow: ellipsis;
            }

            .extraction-preview-table tbody tr:hover {
                background-color: #f8f9fa;
            }

            .status-badge-extracted {
                display: inline-block;
                background: #e8f5e9;
                color: #2e7d32;
                padding: 4px 10px;
                border-radius: 4px;
                font-weight: 500;
                font-size: 0.85em;
            }

            .status-badge-review {
                display: inline-block;
                background: #fff3e0;
                color: #e65100;
                padding: 4px 10px;
                border-radius: 4px;
                font-weight: 500;
                font-size: 0.85em;
            }

            .preview-actions {
                display: flex;
                gap: 10px;
                justify-content: flex-end;
            }

            .btn-success {
                background-color: #28a745;
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 600;
                transition: all 0.3s ease;
            }

            .btn-success:hover {
                background-color: #218838;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);
            }

            /* Responsive */
            @media (max-width: 768px) {
                .n88-project-detail-wrapper {
                    padding: 10px;
                }

                .n88-header-top {
                    flex-direction: column;
                    align-items: flex-start;
                }

                .n88-header-top h1 {
                    font-size: 1.5em;
                }

                .n88-summary-grid,
                .n88-contact-grid {
                    grid-template-columns: 1fr;
                }

                .n88-items-detail-table {
                    font-size: 0.85em;
                }

                .n88-items-detail-table th,
                .n88-items-detail-table td {
                    padding: 8px;
                }

                .n88-detail-actions {
                    flex-direction: column;
                }

                .n88-detail-actions .btn {
                    width: 100%;
                    text-align: center;
                }
            }

            /* Quote Section Styling */
            .n88-quote-section {
                margin: 30px 0;
                padding: 20px;
                background: #f9f9f9;
                border-left: 4px solid #4CAF50;
                border-radius: 4px;
            }

            .n88-quote-section h2 {
                margin-top: 0;
                color: #333;
                font-size: 18px;
            }

            .n88-quote-card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 15px;
            }

            .n88-quote-info {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
                margin-bottom: 15px;
                font-size: 14px;
            }

            .n88-quote-info-item {
                display: flex;
                flex-direction: column;
            }

            .n88-quote-info-label {
                font-weight: 600;
                color: #555;
                margin-bottom: 5px;
            }

            .n88-quote-info-value {
                color: #333;
                word-break: break-word;
            }

            .n88-quote-file {
                display: flex;
                align-items: center;
                gap: 10px;
                padding-top: 15px;
                border-top: 1px solid #eee;
            }

            .n88-quote-file .btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 15px;
                background: #4CAF50;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                text-decoration: none;
                font-weight: 500;
            }

            .n88-quote-file .btn:hover {
                background: #45a049;
            }

            @media (max-width: 600px) {
                .n88-quote-info {
                    grid-template-columns: 1fr;
                }

                .n88-quote-section {
                    margin: 20px 0;
                    padding: 15px;
                }

                .n88-quote-card {
                    padding: 10px;
                }

                .n88-quote-file .btn {
                    width: 100%;
                    justify-content: center;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Get project data for modal
     */
    public function ajax_get_project_modal() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
        if ( ! $project_id ) {
            wp_send_json_error( 'Invalid project ID' );
        }

        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project( $project_id, get_current_user_id() );

        if ( ! $project ) {
            wp_send_json_error( 'Project not found or unauthorized' );
        }

        $metadata = $project['metadata'] ?? array();
        
        // Phase 3: Use get_project_items() which ensures timeline_structure
        $projects_class = new N88_RFQ_Projects();
        $items = $projects_class->get_project_items( $project_id );
        
        // Add index as ID for each item (for modal compatibility)
        foreach ( $items as $index => &$item ) {
            if ( ! isset( $item['id'] ) ) {
                $item['id'] = $index;
            }
        }
        unset( $item );

        $quotes = N88_RFQ_Quotes::get_project_quotes( $project_id );
            
            // Get latest quote status
            $latest_quote = N88_RFQ_Quotes::get_latest_quote( $project_id );
            $quote_status = $latest_quote ? $latest_quote->quote_status : null;
            
            // Get production status from metadata
            $production_status = $metadata['n88_production_status'] ?? null;
            
            // Get timeline steps from metadata if available
            $timeline_steps = null;
            if ( ! empty( $metadata['n88_timeline_steps'] ) ) {
                $timeline_steps_json = is_string( $metadata['n88_timeline_steps'] ) 
                    ? json_decode( $metadata['n88_timeline_steps'], true ) 
                    : $metadata['n88_timeline_steps'];
                if ( is_array( $timeline_steps_json ) ) {
                    $timeline_steps = $timeline_steps_json;
                }
            }
        
        // Get updated_by user info
        $updated_by_user = null;
        if ( ! empty( $project['updated_by'] ) ) {
            $updated_by_user = get_userdata( $project['updated_by'] );
        }
        
        // Get notifications
        $notifications = N88_RFQ_Notifications::get_project_notifications( $project_id, 10 );

        wp_send_json_success( array(
            'id' => $project_id,
            'name' => $project['project_name'],
            'status' => $project['status'],
            'type' => $project['project_type'],
            'timeline' => $project['timeline'],
            'budget' => $project['budget_range'],
            'quote_type' => $project['quote_type'] ?? '',
            'created_at' => $project['created_at'],
            'updated_at' => $project['updated_at'],
            'updated_by' => $project['updated_by'] ?? 0,
            'updated_by_name' => $updated_by_user ? $updated_by_user->display_name : '',
                'quote_status' => $quote_status,
                'production_status' => $production_status,
                'timeline_steps' => $timeline_steps,
            'items' => $items,
            'item_count' => count( $items ),
            'quotes' => $quotes,
            'notifications' => $notifications,
            'metadata' => $metadata,
        ) );
    }

    /**
     * AJAX: Add comment to item
     */
        /**
         * AJAX: Add comment (Phase 2B: Enhanced with urgent flag and video support)
     */
    public function ajax_add_comment() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( $_POST['item_id'] ) : null;
            $video_id = isset( $_POST['video_id'] ) ? sanitize_text_field( $_POST['video_id'] ) : null;
        $comment_text = isset( $_POST['comment_text'] ) ? sanitize_textarea_field( $_POST['comment_text'] ) : '';
            $is_urgent = isset( $_POST['is_urgent'] ) ? (bool) $_POST['is_urgent'] : false;
            $parent_comment_id = isset( $_POST['parent_comment_id'] ) ? intval( $_POST['parent_comment_id'] ) : null;
            $user_id = get_current_user_id();

            // Enhanced validation with logging
            if ( ! $project_id ) {
                error_log( 'N88 RFQ: ajax_add_comment - Missing project_id. POST data: ' . print_r( $_POST, true ) );
                wp_send_json_error( 'Missing project ID' );
            }

            if ( empty( $comment_text ) ) {
                error_log( 'N88 RFQ: ajax_add_comment - Missing comment_text. POST data: ' . print_r( $_POST, true ) );
                wp_send_json_error( 'Comment text is required' );
            }

            if ( ! $user_id ) {
                error_log( 'N88 RFQ: ajax_add_comment - Invalid user_id: ' . $user_id );
                wp_send_json_error( 'Invalid user' );
        }

            error_log( 'N88 RFQ: ajax_add_comment - Attempting to add comment. Project: ' . $project_id . ', User: ' . $user_id . ', Item: ' . ( $item_id ? $item_id : 'null' ) . ', Video: ' . ( $video_id ? $video_id : 'null' ) );

        $comment_id = N88_RFQ_Comments::add_comment( array(
            'project_id' => $project_id,
            'item_id' => $item_id,
                'video_id' => $video_id,
                'user_id' => $user_id,
            'comment_text' => $comment_text,
                'is_urgent' => $is_urgent,
                'parent_comment_id' => $parent_comment_id,
        ) );

        if ( $comment_id ) {
                error_log( 'N88 RFQ: ajax_add_comment - Success. Comment ID: ' . $comment_id );
                
                // Update project timestamp when comment is added
                $projects_class = new N88_RFQ_Projects();
                $projects_class->update_project_timestamp( $project_id, $user_id );
                
            wp_send_json_success( array( 'comment_id' => $comment_id ) );
        } else {
                // Enhanced error logging and specific error messages
                global $wpdb;
                $error_msg = 'Failed to add comment';
                $error_details = array();
                
                // Check permission first
                $can_comment = N88_RFQ_Comments::user_can_comment( $project_id, $user_id );
                if ( ! $can_comment ) {
                    $error_msg = 'You do not have permission to comment on this project';
                    $error_details['reason'] = 'permission_denied';
                    error_log( 'N88 RFQ: ajax_add_comment - Permission denied. Project: ' . $project_id . ', User: ' . $user_id );
                    
                    // Get project to check ownership
                    $projects_class = new N88_RFQ_Projects();
                    $project = $projects_class->get_project_admin( $project_id );
                    if ( $project ) {
                        error_log( 'N88 RFQ: ajax_add_comment - Project owner: ' . $project['user_id'] . ', Current user: ' . $user_id );
                        error_log( 'N88 RFQ: ajax_add_comment - Is admin: ' . ( current_user_can( 'manage_options' ) ? 'yes' : 'no' ) );
                    }
                }
                
                // Check database errors
                if ( $wpdb->last_error ) {
                    $error_msg .= ': Database error - ' . $wpdb->last_error;
                    $error_details['db_error'] = $wpdb->last_error;
                    $error_details['db_query'] = $wpdb->last_query;
                    error_log( 'N88 RFQ: ajax_add_comment - Database error: ' . $wpdb->last_error );
                    error_log( 'N88 RFQ: ajax_add_comment - Last query: ' . $wpdb->last_query );
                }
                
                // Check if comment_text was empty after sanitization
                if ( empty( $comment_text ) && ! empty( $_POST['comment_text'] ) ) {
                    $error_msg = 'Comment text was removed during sanitization (may contain invalid content)';
                    $error_details['reason'] = 'sanitization_failed';
                    error_log( 'N88 RFQ: ajax_add_comment - Comment text was empty after sanitization. Original length: ' . strlen( $_POST['comment_text'] ) );
                }
                
                error_log( 'N88 RFQ: ajax_add_comment - Failed. Project: ' . $project_id . ', User: ' . $user_id . ', Comment text length: ' . strlen( $comment_text ) );
                error_log( 'N88 RFQ: ajax_add_comment - Error details: ' . print_r( $error_details, true ) );
                
                wp_send_json_error( array( 
                    'message' => $error_msg,
                    'details' => $error_details
                ) );
        }
    }

    /**
     * AJAX: Get comments for item
     */
    public function ajax_get_comments() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( $_POST['item_id'] ) : null;
            $video_id = isset( $_POST['video_id'] ) ? sanitize_text_field( $_POST['video_id'] ) : null;

        if ( ! $project_id ) {
            wp_send_json_error( 'Invalid project ID' );
        }

            $comments = N88_RFQ_Comments::get_comments( $project_id, $item_id, $video_id );
        $formatted = array_map( array( 'N88_RFQ_Comments', 'format_comment' ), $comments );

        wp_send_json_success( $formatted );
    }

    /**
     * AJAX: Get all comments for project
     */
    public function ajax_get_project_comments() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;

        if ( ! $project_id ) {
            wp_send_json_error( 'Invalid project ID' );
        }

        $comments = N88_RFQ_Comments::get_comments( $project_id );
        $formatted = array_map( array( 'N88_RFQ_Comments', 'format_comment' ), $comments );

        wp_send_json_success( $formatted );
    }

    /**
     * AJAX: Delete comment
     */
    public function ajax_delete_comment() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $comment_id = isset( $_POST['comment_id'] ) ? intval( $_POST['comment_id'] ) : 0;
        $user_id = get_current_user_id();

        if ( ! $comment_id ) {
            wp_send_json_error( 'Invalid comment ID' );
        }

        $deleted = N88_RFQ_Comments::delete_comment( $comment_id, $user_id );

        if ( $deleted ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( 'Failed to delete comment' );
        }
    }

    /**
     * AJAX: Get quote for project
     */
    public function ajax_get_project_quote() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;

        if ( ! $project_id ) {
            wp_send_json_error( 'Invalid project ID' );
        }

        $quote = N88_RFQ_Quotes::get_latest_quote( $project_id );

        if ( $quote ) {
            $formatted = N88_RFQ_Quotes::format_quote( $quote );
            wp_send_json_success( $formatted );
        } else {
            wp_send_json_success( null );
        }
    }

    /**
     * AJAX: Update client quote (for clients to edit quote items)
     */
    public function ajax_update_client_quote() {
        N88_RFQ_Helpers::verify_quote_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $quote_id = isset( $_POST['quote_id'] ) ? intval( $_POST['quote_id'] ) : 0;
        if ( ! $quote_id ) {
            wp_send_json_error( 'Invalid quote ID' );
        }

        // Get quote and verify ownership
        $quote = N88_RFQ_Quotes::get_quote( $quote_id );
        if ( ! $quote ) {
            wp_send_json_error( 'Quote not found' );
        }

        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project( $quote->project_id );
        
        // Verify user owns the project
        if ( ! $project || (int) $project['user_id'] !== get_current_user_id() ) {
            wp_send_json_error( 'Permission denied' );
        }

        $update_data = array();

        // Handle quote items
        if ( isset( $_POST['quote_items'] ) && is_array( $_POST['quote_items'] ) ) {
            $quote_items = array();
            foreach ( $_POST['quote_items'] as $item_data ) {
                $quote_items[] = array(
                    'item_id' => isset( $item_data['item_id'] ) ? sanitize_text_field( $item_data['item_id'] ) : '',
                    'unit_price' => isset( $item_data['unit_price'] ) ? (float) $item_data['unit_price'] : 0,
                    'quantity' => isset( $item_data['quantity'] ) ? (int) $item_data['quantity'] : 0,
                    'total_price' => isset( $item_data['total_price'] ) ? (float) $item_data['total_price'] : 0,
                    'dimensions' => isset( $item_data['dimensions'] ) ? sanitize_text_field( $item_data['dimensions'] ) : '',
                    'material' => isset( $item_data['material'] ) ? sanitize_text_field( $item_data['material'] ) : '',
                    'notes' => isset( $item_data['notes'] ) ? sanitize_textarea_field( $item_data['notes'] ) : '',
                );
            }
            $update_data['quote_items'] = wp_json_encode( $quote_items );
        }

        // Handle client message (optional)
        if ( isset( $_POST['client_quote_message'] ) ) {
            $update_data['client_message'] = sanitize_textarea_field( $_POST['client_quote_message'] );
        }

        // Update quote
        if ( ! empty( $update_data ) ) {
            if ( N88_RFQ_Quotes::update_quote( $quote_id, $update_data ) ) {
                // Track client updates
                $projects_class->save_project_metadata( $quote->project_id, array(
                    'n88_has_client_updates' => '1',
                    'n88_last_client_update' => current_time( 'mysql' ),
                    'n88_last_client_update_by' => (string) get_current_user_id(),
                ) );

                // Notify admin
                if ( class_exists( 'N88_RFQ_Notifications' ) ) {
                    $admin_users = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
                    foreach ( $admin_users as $admin_id ) {
                        N88_RFQ_Notifications::create_notification(
                            $quote->project_id,
                            $admin_id,
                            'quote_updated_by_client',
                            'Client updated quote items. Please review.',
                            $quote_id
                        );
                    }
                }

                wp_send_json_success( 'Quote updated successfully' );
            } else {
                wp_send_json_error( 'Failed to update quote' );
            }
        } else {
            wp_send_json_error( 'No data to update' );
        }
    }

    /**
     * AJAX: Save item edit from project detail view (Both Admin and Users)
     */
    public function ajax_save_item_edit() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Permission denied' );
        }

        $current_user_id = get_current_user_id();
        $is_admin = current_user_can( 'manage_options' );

        $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
        $item_index = isset( $_POST['item_index'] ) ? intval( $_POST['item_index'] ) : -1;
        
        if ( ! $project_id || $item_index < 0 ) {
            wp_send_json_error( 'Invalid project ID or item index' );
        }

        // Get current project items
        $projects_class = new N88_RFQ_Projects();
        $items_json = $projects_class->get_project_metadata( $project_id, 'n88_repeater_raw' );
        $repeater_items = ! empty( $items_json ) ? json_decode( $items_json, true ) : array();

        if ( ! isset( $repeater_items[ $item_index ] ) ) {
            wp_send_json_error( 'Item not found' );
        }

        $item = &$repeater_items[ $item_index ];

        // Update item fields
        if ( isset( $_POST['primary_material'] ) ) {
            $item['primary_material'] = sanitize_text_field( $_POST['primary_material'] );
        }

        if ( isset( $_POST['length'] ) || isset( $_POST['depth'] ) || isset( $_POST['height'] ) ) {
            if ( ! isset( $item['dimensions'] ) || ! is_array( $item['dimensions'] ) ) {
                $item['dimensions'] = array();
            }
            if ( isset( $_POST['length'] ) ) {
                $item['dimensions']['length'] = sanitize_text_field( $_POST['length'] );
                $item['length_in'] = $item['dimensions']['length'];
            }
            if ( isset( $_POST['depth'] ) ) {
                $item['dimensions']['depth'] = sanitize_text_field( $_POST['depth'] );
                $item['depth_in'] = $item['dimensions']['depth'];
            }
            if ( isset( $_POST['height'] ) ) {
                $item['dimensions']['height'] = sanitize_text_field( $_POST['height'] );
                $item['height_in'] = $item['dimensions']['height'];
            }
        }

        if ( isset( $_POST['quantity'] ) ) {
            $item['quantity'] = sanitize_text_field( $_POST['quantity'] );
        }

        if ( isset( $_POST['construction_notes'] ) ) {
            $item['construction_notes'] = sanitize_textarea_field( $_POST['construction_notes'] );
        }

        if ( isset( $_POST['finishes'] ) ) {
            $item['finishes'] = sanitize_text_field( $_POST['finishes'] );
        }

        if ( isset( $_POST['notes'] ) ) {
            $item['notes'] = sanitize_textarea_field( $_POST['notes'] );
        }

        // Save updated items
        $save_result = $projects_class->save_repeater_items( $project_id, $repeater_items );

        if ( $save_result ) {
            // Track updates based on who edited
            if ( $is_admin ) {
                // Track admin updates
                $projects_class->save_project_metadata( $project_id, array(
                    'n88_has_admin_updates'   => '1',
                    'n88_last_admin_update'   => current_time( 'mysql' ),
                    'n88_last_admin_update_by'=> (string) $current_user_id,
                ) );
            } else {
                // Track client updates
                $projects_class->save_project_metadata( $project_id, array(
                    'n88_has_client_updates'   => '1',
                    'n88_last_client_update'   => current_time( 'mysql' ),
                    'n88_last_client_update_by'=> (string) $current_user_id,
                ) );
            }

            // Update project timestamp
            $projects_class->update_project_timestamp( $project_id, $current_user_id );

            // Notify based on who edited
            if ( class_exists( 'N88_RFQ_Notifications' ) ) {
                N88_RFQ_Notifications::notify_item_edited(
                    $project_id,
                    $item_index,
                    $current_user_id
                );
            }

            wp_send_json_success( array(
                'message' => 'Item updated successfully',
                'item_index' => $item_index,
            ) );
        } else {
            wp_send_json_error( 'Failed to save item' );
        }
    }

    /**
     * AJAX: Calculate instant pricing (Phase 2B)
     */
    public function ajax_calculate_pricing() {
            N88_RFQ_Helpers::verify_ajax_nonce();

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Permission denied' );
            }

            $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
            $labor_cost = isset( $_POST['labor_cost'] ) ? (float) $_POST['labor_cost'] : 0;
            $materials_cost = isset( $_POST['materials_cost'] ) ? (float) $_POST['materials_cost'] : 0;
            $overhead_cost = isset( $_POST['overhead_cost'] ) ? (float) $_POST['overhead_cost'] : 0;
            $margin_percentage = isset( $_POST['margin_percentage'] ) ? (float) $_POST['margin_percentage'] : 0;
            $shipping_zone = isset( $_POST['shipping_zone'] ) ? sanitize_text_field( $_POST['shipping_zone'] ) : '';

            if ( ! $project_id ) {
                wp_send_json_error( 'Invalid project ID' );
            }

            if ( ! class_exists( 'N88_RFQ_Pricing' ) ) {
                wp_send_json_error( 'Pricing calculator not available' );
            }

            $pricing_result = N88_RFQ_Pricing::calculate_project_pricing(
                $project_id,
                $labor_cost,
                $materials_cost,
                $overhead_cost,
                $margin_percentage,
                $shipping_zone
            );

            if ( $pricing_result ) {
                wp_send_json_success( $pricing_result );
            } else {
                wp_send_json_error( 'Failed to calculate pricing' );
        }
    }

    /**
     * AJAX: Upload file for item
     */
    public function ajax_upload_item_file() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $user_id = get_current_user_id();
        
        // Rate limiting: 10 uploads per hour
        $rate_limit = N88_RFQ_Helpers::check_rate_limit( 'upload', 10, 3600, $user_id ); // 3600 seconds = 1 hour
        if ( $rate_limit ) {
            $retry_minutes = ceil( $rate_limit['retry_after'] / 60 );
            status_header( 429 ); // Too Many Requests
            wp_send_json_error( array(
                'message' => sprintf( 
                    'Rate limit exceeded. You have uploaded too many files. Please try again in %d minute(s).',
                    $retry_minutes
                ),
                'retry_after' => $rate_limit['retry_after'],
                'limit' => $rate_limit['limit'],
                'window' => $rate_limit['window'],
            ) );
        }

        $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( $_POST['item_id'] ) : '';

        if ( ! $project_id || ! $item_id ) {
            wp_send_json_error( 'Missing required fields' );
        }

        if ( empty( $_FILES['files'] ) ) {
            wp_send_json_error( 'No files uploaded' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $file_ids = array();
        $files = $_FILES['files'];
        // Get allowed file types from helper
        $allowed_types = N88_RFQ_Helpers::get_allowed_file_types();

        $count = is_array( $files['name'] ) ? count( $files['name'] ) : 1;

        for ( $i = 0; $i < $count; $i++ ) {
            if ( is_array( $files['name'] ) ) {
                $_FILES['item_file'] = array(
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                );
            } else {
                $_FILES['item_file'] = $files;
            }

            // Validate file using MIME type and file header checks
            if ( ! empty( $_FILES['item_file']['tmp_name'] ) ) {
                $is_valid = N88_RFQ_Helpers::validate_file_mime_type(
                    $_FILES['item_file']['tmp_name'],
                    $_FILES['item_file']['type'],
                    $allowed_types
                );
                
                if ( ! $is_valid ) {
                    continue;
                }
            } else {
                // Check file type - also check by extension for DWG files (fallback)
            $file_ext = strtolower( pathinfo( $_FILES['item_file']['name'], PATHINFO_EXTENSION ) );
            $is_dwg = ( $file_ext === 'dwg' );
            $is_allowed_type = in_array( $_FILES['item_file']['type'], $allowed_types );
            
            if ( ! $is_allowed_type && ! $is_dwg ) {
                continue;
                }
            }

            $attachment_id = media_handle_upload( 'item_file', 0 );

            if ( ! is_wp_error( $attachment_id ) ) {
                $file_ids[] = $attachment_id;
                $this->save_item_file_attachment( $project_id, $item_id, $attachment_id );
            }
        }

        if ( ! empty( $file_ids ) ) {
                // Update project timestamp when item files are uploaded
                $projects_class = new N88_RFQ_Projects();
                $projects_class->update_project_timestamp( $project_id, get_current_user_id() );
                
            wp_send_json_success( array( 'file_ids' => $file_ids ) );
        } else {
            wp_send_json_error( 'No files uploaded successfully' );
        }
    }

    /**
     * AJAX: Get files for item
     */
    public function ajax_get_item_files() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( $_POST['item_id'] ) : '';

        if ( ! $project_id || ! $item_id ) {
            wp_send_json_error( 'Missing required fields' );
        }

        $file_ids = $this->get_item_file_attachments( $project_id, $item_id );
        $files = array();

        foreach ( $file_ids as $file_id ) {
            $file_url = wp_get_attachment_url( $file_id );
            $file_name = basename( get_attached_file( $file_id ) );

            if ( $file_url ) {
                $files[] = array(
                    'id' => $file_id,
                    'url' => $file_url,
                    'name' => $file_name,
                );
            }
        }

        wp_send_json_success( $files );
    }

    /**
     * Save item file attachment to metadata
     */
    private function save_item_file_attachment( $project_id, $item_id, $attachment_id ) {
        global $wpdb;
        $meta_key = 'n88_item_files_' . $item_id;
        $meta_table = $wpdb->prefix . 'project_metadata';
        
        $file_ids_json = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$meta_table} WHERE project_id = %d AND meta_key = %s",
            $project_id,
            $meta_key
        ) );
        
        $file_ids = ! empty( $file_ids_json ) ? json_decode( $file_ids_json, true ) : array();
        if ( ! is_array( $file_ids ) ) {
            $file_ids = array();
        }

        if ( ! in_array( $attachment_id, $file_ids ) ) {
        $file_ids[] = $attachment_id;
        }
        
        // Update or insert
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$meta_table} WHERE project_id = %d AND meta_key = %s",
            $project_id,
            $meta_key
        ) );
        
        if ( $existing ) {
            $wpdb->update(
                $meta_table,
                array( 'meta_value' => wp_json_encode( $file_ids ) ),
                array( 'id' => $existing ),
                array( '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $meta_table,
                array(
                    'project_id' => $project_id,
                    'meta_key' => $meta_key,
                    'meta_value' => wp_json_encode( $file_ids ),
                ),
                array( '%d', '%s', '%s' )
            );
        }
    }

    /**
     * Get item file attachments
     */
    private function get_item_file_attachments( $project_id, $item_id ) {
        global $wpdb;
        $meta_key = 'n88_item_files_' . $item_id;
        $meta_table = $wpdb->prefix . 'project_metadata';
        
        $file_ids_json = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$meta_table} WHERE project_id = %d AND meta_key = %s",
            $project_id,
            $meta_key
        ) );
        
        if ( empty( $file_ids_json ) ) {
            return array();
        }
        
        $file_ids = json_decode( $file_ids_json, true );
        return is_array( $file_ids ) ? $file_ids : array();
    }

    /**
     * AJAX: Delete item file
     */
    public function ajax_delete_item_file() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $file_id = isset( $_POST['file_id'] ) ? intval( $_POST['file_id'] ) : 0;
        $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( $_POST['item_id'] ) : '';

        if ( ! $file_id || ! $project_id || ! $item_id ) {
            wp_send_json_error( 'Missing required fields' );
        }

        // Verify user owns project or is admin
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project( $project_id, get_current_user_id() );
        
        if ( ! $project && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Get current file list
        $file_ids = $this->get_item_file_attachments( $project_id, $item_id );
        
        // Remove file ID from array
        $file_ids = array_diff( $file_ids, array( $file_id ) );
        $file_ids = array_values( $file_ids ); // Re-index array

        // Update metadata
        global $wpdb;
        $meta_key = 'n88_item_files_' . $item_id;
        $meta_table = $wpdb->prefix . 'project_metadata';
        
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$meta_table} WHERE project_id = %d AND meta_key = %s",
            $project_id,
            $meta_key
        ) );
        
        if ( $existing ) {
            $wpdb->update(
                $meta_table,
                array( 'meta_value' => wp_json_encode( $file_ids ) ),
                array( 'id' => $existing ),
                array( '%s' ),
                array( '%d' )
            );
        }

        // Delete attachment (WordPress will handle file deletion)
        wp_delete_attachment( $file_id, true );

        wp_send_json_success( array( 'message' => 'File deleted successfully' ) );
    }

    /**
     * Render modal markup (added to footer)
     */
    public function render_modal_markup() {
        ?>
        <style>
            /* Modal Container */
            .n88-modal {
                display: none;
                position: fixed;
                z-index: 10000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                align-items: center;
                justify-content: center;
                animation: fadeIn 0.3s ease;
            }

            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }

            .n88-modal-content {
                background: white;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
                width: 90%;
                max-width: 1000px;
                max-height: 85vh;
                display: flex;
                flex-direction: column;
                animation: slideUp 0.3s ease;
            }

            @keyframes slideUp {
                from {
                    transform: translateY(30px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }

            /* Modal Header */
            .n88-modal-header {
                padding: 24px;
                border-bottom: 1px solid #eee;
                position: relative;
            }

            .n88-modal-header h2 {
                margin: 0;
                font-size: 1.8em;
                color: #333;
                word-break: break-word;
            }

            .n88-modal-header-meta {
                font-size: 12px;
                color: #999;
                margin-top: 8px;
                padding-top: 8px;
                border-top: 1px solid #eee;
            }

            .n88-modal-header-meta div {
                margin: 4px 0;
            }

            .n88-modal-close {
                position: absolute;
                right: 24px;
                top: 24px;
                background: none;
                border: none;
                font-size: 28px;
                cursor: pointer;
                color: #999;
                padding: 0;
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 4px;
                transition: all 0.2s ease;
            }

            .n88-modal-close:hover {
                background-color: #f0f0f0;
                color: #333;
            }

            /* Modal Tabs */
            .n88-modal-tabs {
                display: flex;
                border-bottom: 1px solid #ddd;
                background-color: #fafafa;
                flex-wrap: wrap;
            }

            .n88-modal-tab {
                flex: 1;
                min-width: 120px;
                padding: 12px 16px;
                background: none;
                border: none;
                border-bottom: 3px solid transparent;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                color: #666;
                transition: all 0.2s ease;
                text-align: center;
            }

            .n88-modal-tab:hover {
                color: #333;
                background-color: #f0f0f0;
            }

            .n88-modal-tab.active {
                color: #007cba;
                border-bottom-color: #007cba;
            }

            /* Modal Body */
            .n88-modal-body {
                flex: 1;
                overflow-y: auto;
                padding: 24px;
            }

            .n88-modal-content-section {
                animation: fadeIn 0.2s ease;
            }

            /* Summary Section */
            .n88-summary-block {
                background: white;
            }

            .n88-summary-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-bottom: 20px;
            }

            .n88-summary-item {
                background: #f9f9f9;
                padding: 16px;
                border-radius: 6px;
                border-left: 4px solid #007cba;
            }

            .n88-summary-item label {
                display: block;
                font-weight: 600;
                color: #666;
                font-size: 12px;
                text-transform: uppercase;
                margin-bottom: 8px;
                letter-spacing: 0.5px;
            }

            .n88-summary-item p {
                margin: 0;
                font-size: 14px;
                color: #333;
            }

            /* Items Section */
            .n88-items-cards {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 16px;
            }

            .n88-item-card {
                border: 1px solid #ddd;
                border-radius: 6px;
                overflow: hidden;
                background: white;
                transition: all 0.2s ease;
            }

            .n88-item-card:hover {
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            .n88-item-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px;
                background-color: #f9f9f9;
                border-bottom: 1px solid #eee;
                cursor: pointer;
                user-select: none;
            }

            .n88-item-header h3 {
                margin: 0;
                font-size: 16px;
                color: #333;
            }

            .n88-expand-icon {
                font-size: 20px;
                color: #666;
                transition: transform 0.2s ease;
            }

            .n88-item-header:hover {
                background-color: #f0f0f0;
            }

            .n88-item-content {
                padding: 16px;
            }

            .n88-item-field {
                margin-bottom: 12px;
            }

            .n88-item-field label {
                display: block;
                font-weight: 600;
                font-size: 12px;
                color: #666;
                text-transform: uppercase;
                margin-bottom: 4px;
            }

            .n88-item-field p {
                margin: 0;
                font-size: 14px;
                color: #333;
            }

            /* Timeline Section */
            .n88-timeline-block {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 16px;
            }

            .n88-timeline-item {
                background: #f9f9f9;
                padding: 16px;
                border-radius: 6px;
                border-left: 4px solid #007cba;
            }

            .n88-timeline-item label {
                display: block;
                font-weight: 600;
                color: #666;
                font-size: 12px;
                text-transform: uppercase;
                margin-bottom: 8px;
            }

            .n88-timeline-item p {
                margin: 0;
                font-size: 14px;
                color: #333;
            }

            /* Files Section */
            .n88-files-container {
                background: white;
            }

            .n88-no-files {
                text-align: center;
                padding: 40px 20px;
                color: #999;
                font-style: italic;
            }

            .n88-files-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 16px;
            }

            .n88-file-item {
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 16px;
                background: #f9f9f9;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .n88-file-item:hover {
                border-color: #007cba;
                background: #f0f7ff;
                box-shadow: 0 2px 8px rgba(0, 124, 186, 0.1);
            }

            .n88-file-icon {
                font-size: 32px;
                flex-shrink: 0;
            }

            .n88-file-info {
                flex: 1;
                min-width: 0;
            }

            .n88-file-name {
                margin: 0;
                font-size: 14px;
                font-weight: 600;
                color: #333;
                word-break: break-word;
                overflow: hidden;
                text-overflow: ellipsis;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
            }

            .n88-file-meta {
                margin: 4px 0;
                font-size: 12px;
                color: #666;
            }

            .n88-file-date {
                margin: 4px 0;
                font-size: 12px;
                color: #999;
            }

            /* Quote Panel */
            .n88-quote-panel {
                background: white;
            }

            .n88-quote-empty {
                text-align: center;
                padding: 40px 20px;
                background: #f9f9f9;
                border-radius: 6px;
            }

            .n88-quote-empty p {
                margin: 8px 0;
                color: #666;
                font-size: 14px;
            }

            .n88-quote-detail {
                border: 1px solid #e0e0e0;
                border-radius: 6px;
                padding: 20px;
                background: #f9f9f9;
            }

            .n88-quote-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 12px;
                border-bottom: 1px solid #ddd;
            }

            .n88-quote-header h3 {
                margin: 0;
                font-size: 18px;
                color: #333;
            }

            .n88-badge {
                display: inline-block;
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }

            .n88-badge-success {
                background-color: #d4edda;
                color: #155724;
            }

            .n88-badge-warning {
                background-color: #fff3cd;
                color: #856404;
            }

            .n88-quote-info {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 16px;
                margin-bottom: 20px;
            }

            .n88-quote-field {
                background: white;
                padding: 12px;
                border-radius: 4px;
            }

            .n88-quote-field label {
                display: block;
                font-weight: 600;
                font-size: 11px;
                color: #666;
                text-transform: uppercase;
                margin-bottom: 6px;
            }

            .n88-quote-field p {
                margin: 0;
                font-size: 14px;
                color: #333;
            }

            .n88-quote-download {
                text-align: center;
                padding: 16px;
                background: white;
                border-radius: 4px;
            }

            /* Notifications Section */
            .n88-notifications-list {
                background: white;
            }

            .n88-notification-item {
                border-left: 4px solid #007cba;
                padding: 16px;
                margin-bottom: 12px;
                background: #f9f9f9;
                border-radius: 4px;
                transition: all 0.2s ease;
            }

            .n88-notification-item.unread {
                background: #f0f7ff;
                border-left-color: #ff9800;
            }

            .n88-notification-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }

            .n88-notification-header strong {
                color: #333;
                font-size: 14px;
            }

            .n88-notification-time {
                font-size: 12px;
                color: #999;
            }

            .n88-notification-item p {
                margin: 0;
                font-size: 13px;
                color: #666;
                line-height: 1.4;
            }

            /* Responsive */
            @media (max-width: 768px) {
                .n88-modal-content {
                    width: 95%;
                    max-height: 90vh;
                }

                .n88-modal-tabs {
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                }

                .n88-modal-tab {
                    white-space: nowrap;
                    flex: 0 0 auto;
                }

                .n88-modal-body {
                    padding: 16px;
                }

                .n88-summary-grid {
                    grid-template-columns: 1fr;
                }

                .n88-items-cards {
                    grid-template-columns: 1fr;
                }

                .n88-files-grid {
                    grid-template-columns: 1fr;
                }

                .n88-timeline-block {
                    grid-template-columns: 1fr;
                }

                .n88-file-item {
                    flex-direction: column;
                    align-items: flex-start;
                }

                .n88-quote-info {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <div id="n88-project-modal" class="n88-modal">
            <div class="n88-modal-content">
                <div class="n88-modal-header">
                    <h2>Project Details</h2>
                    <button class="n88-modal-close" onclick="N88Modal.closeModal()">&times;</button>
                </div>

                <div class="n88-modal-tabs">
                    <button class="n88-modal-tab" data-tab="summary">Summary</button>
                    <button class="n88-modal-tab" data-tab="items">Items</button>
                    <button class="n88-modal-tab" data-tab="timeline">Timeline</button>
                    <button class="n88-modal-tab" data-tab="files">Files</button>
                    <button class="n88-modal-tab" data-tab="comments">Comments</button>
                    <button class="n88-modal-tab" data-tab="quote">Quote</button>
                    <button class="n88-modal-tab" data-tab="notifications">Notifications</button>
                </div>

                <div class="n88-modal-body">
                    <div id="summary-content" class="n88-modal-content-section"></div>
                    <div id="items-content" class="n88-modal-content-section" style="display:none;"></div>
                    <div id="timeline-content" class="n88-modal-content-section" style="display:none;"></div>
                    <div id="files-content" class="n88-modal-content-section" style="display:none;"></div>
                    <div id="comments-content" class="n88-modal-content-section" style="display:none;"></div>
                    <div id="quote-content" class="n88-modal-content-section" style="display:none;"></div>
                    <div id="notifications-content" class="n88-modal-content-section" style="display:none;"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Get user notifications
     */
    public function ajax_get_notifications() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        N88_RFQ_Helpers::verify_ajax_nonce();

        $user_id = get_current_user_id();
            $limit = isset( $_REQUEST['limit'] ) ? intval( $_REQUEST['limit'] ) : 20;
            $unread_only = isset( $_REQUEST['unread_only'] ) ? (bool) $_REQUEST['unread_only'] : false;

        if ( ! class_exists( 'N88_RFQ_Notifications' ) ) {
            wp_send_json_error( 'Notifications not available' );
        }

        $notifications = N88_RFQ_Notifications::get_user_notifications( $user_id, $limit, 0, $unread_only );
        $formatted = array();

        foreach ( $notifications as $notif ) {
            $formatted[] = N88_RFQ_Notifications::format_notification( $notif );
        }

        wp_send_json_success( array(
            'notifications' => $formatted,
            'count' => count( $formatted ),
        ) );
    }

    /**
     * AJAX: Get unread notification count
     */
    public function ajax_get_unread_count() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! class_exists( 'N88_RFQ_Notifications' ) ) {
            wp_send_json_error( 'Notifications not available' );
        }

        $user_id = get_current_user_id();
        $count = N88_RFQ_Notifications::get_unread_count( $user_id );

        wp_send_json_success( array( 'unread_count' => $count ) );
    }

    /**
     * AJAX: Mark notification as read
     */
    public function ajax_mark_notification_read() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        N88_RFQ_Helpers::verify_ajax_nonce();

        $notification_id = isset( $_POST['notification_id'] ) ? intval( $_POST['notification_id'] ) : 0;

        if ( ! $notification_id ) {
            wp_send_json_error( 'Invalid notification ID' );
        }

        if ( ! class_exists( 'N88_RFQ_Notifications' ) ) {
            wp_send_json_error( 'Notifications not available' );
        }

        N88_RFQ_Notifications::mark_as_read( $notification_id );
        wp_send_json_success( array( 'message' => 'Notification marked as read' ) );
    }

    /**
     * AJAX: Mark all notifications as read
     */
    public function ajax_mark_all_notifications_read() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! class_exists( 'N88_RFQ_Notifications' ) ) {
            wp_send_json_error( 'Notifications not available' );
        }

        $user_id = get_current_user_id();
        N88_RFQ_Notifications::mark_all_as_read( $user_id );

        wp_send_json_success( array( 'message' => 'All notifications marked as read' ) );
    }

    /**
         * AJAX: Upload and extract PDF (no project_id required)
     */
    public function ajax_extract_pdf() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Not logged in' ) );
        }

        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( empty( $_FILES['pdf_file'] ) ) {
            wp_send_json_error( array( 'message' => 'No file uploaded' ) );
        }

        // Validate PDF file - check both MIME type and file header
        $file = $_FILES['pdf_file'];
        $file_name = $file['name'] ?? '';
        $file_type = $file['type'] ?? '';
        $file_tmp = $file['tmp_name'] ?? '';
        
        // Check file extension
        $file_ext = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
        if ( 'pdf' !== $file_ext ) {
            wp_send_json_error( array( 'message' => 'Invalid file type. Only PDF files are allowed.' ) );
        }
        
        // Use helper function for MIME type and file header validation
        $allowed_pdf_types = array( 'application/pdf' );
            if ( ! empty( $file_tmp ) && file_exists( $file_tmp ) ) {
            $is_valid = N88_RFQ_Helpers::validate_file_mime_type(
                $file_tmp,
                $file_type,
                $allowed_pdf_types
            );
            
            if ( ! $is_valid ) {
                    wp_send_json_error( array( 'message' => 'Invalid file type. Only PDF files are allowed.' ) );
                }
            } else {
            // Fallback: Check MIME type
            $allowed_mime_types = array( 'application/pdf' );
            if ( ! in_array( $file_type, $allowed_mime_types, true ) ) {
                wp_send_json_error( array( 'message' => 'Invalid file type. Only PDF files are allowed.' ) );
            }
        }

            // Project ID is optional - we can extract without it
        $project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;

            // Handle file upload (temporary storage)
            $upload_result = wp_handle_upload( $_FILES['pdf_file'], array( 
                'test_form' => false,
                'mimes' => array( 'pdf' => 'application/pdf' ), // Explicitly allow only PDF
                'unique_filename_callback' => function( $dir, $name, $ext ) {
                    // Use timestamp to make unique
                    return 'pdf-extract-' . time() . '-' . wp_generate_password( 8, false ) . $ext;
                }
            ) );

        if ( is_wp_error( $upload_result ) ) {
            wp_send_json_error( array( 'message' => $upload_result->get_error_message() ) );
        }

            // Create temporary attachment (not linked to project yet)
        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => $upload_result['type'],
                    'post_title'     => 'PDF Extraction - ' . date( 'Y-m-d H:i:s' ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ),
            $upload_result['file']
        );

            if ( is_wp_error( $attachment_id ) ) {
                wp_send_json_error( array( 'message' => 'Failed to create attachment' ) );
            }

            // Trigger PDF extraction (without project_id - extraction happens first)
        if ( ! class_exists( 'N88_RFQ_PDF_Extractor' ) ) {
            wp_send_json_error( array( 'message' => 'PDF extraction not available' ) );
        }

            // Extract from PDF (project_id can be 0 for new projects)
        $extraction_result = N88_RFQ_PDF_Extractor::extract_from_pdf( $attachment_id, $project_id );

        if ( is_wp_error( $extraction_result ) ) {
            // Clean up attachment if extraction failed
            wp_delete_attachment( $attachment_id, true );
            wp_send_json_error( array( 'message' => $extraction_result->get_error_message() ) );
        }

            // Add attachment_id to result so we can clean it up later if needed
            $extraction_result['attachment_id'] = $attachment_id;
            $extraction_result['temp_file'] = $upload_result['file'];

        wp_send_json_success( $extraction_result );
    }

    /**
         * AJAX: Confirm extraction - create project and save items
     */
    public function ajax_confirm_extraction() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Not logged in' ) );
        }

            // Verify nonce (with fallback for backward compatibility)
            if ( ! N88_RFQ_Helpers::verify_nonce_with_fallback( 'nonce', true ) ) {
                wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        if ( ! class_exists( 'N88_RFQ_PDF_Extractor' ) ) {
            wp_send_json_error( array( 'message' => 'PDF extraction not available' ) );
        }

            // Get form data for project creation
            $user_id = get_current_user_id();
            $form_type = sanitize_text_field( $_POST['form_type'] ?? 'rfq' );
            
            // Get project_id if provided (for updating existing project)
        $project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
            
            $project_name = sanitize_text_field( $_POST['project_name'] ?? '' );
            $project_type = sanitize_text_field( $_POST['project_type'] ?? '' );
            $timeline = sanitize_text_field( $_POST['timeline'] ?? '' );
            $budget_range = sanitize_text_field( $_POST['budget_range'] ?? '' );
            $email = sanitize_email( $_POST['email'] ?? '' );

            // Validate required fields
            if ( empty( $project_name ) || empty( $project_type ) || empty( $timeline ) || empty( $budget_range ) || empty( $email ) ) {
                wp_send_json_error( array( 'message' => 'Please fill in all required fields: Project Name, Project Type, Timeline, Budget Range, and Email' ) );
        }

            if ( ! is_email( $email ) ) {
                wp_send_json_error( array( 'message' => 'Invalid email address' ) );
            }

            // Get items from extraction - sanitize input first
            $items_raw = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
            $items = array();
            
            if ( ! empty( $items_raw ) ) {
                // Sanitize: ensure it's a string and limit length to prevent DoS
                $items_raw = sanitize_text_field( $items_raw );
                if ( strlen( $items_raw ) > 1000000 ) { // 1MB limit for JSON
                    wp_send_json_error( array( 'message' => 'Items data too large. Please try again.' ) );
                }
                
                // Try decoding JSON
                $items = json_decode( $items_raw, true );
                
                // If that failed, log detailed error information
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    $error_msg = json_last_error_msg();
                    error_log( sprintf(
                        'N88 RFQ: Failed to decode items JSON in ajax_confirm_extraction. Error: %s',
                        $error_msg
                    ) );
                    
                    wp_send_json_error( array( 
                        'message' => 'Invalid items data. Please try uploading the PDF again.',
                        'debug' => 'JSON decode error: ' . $error_msg
                    ) );
                }
                
                // Sanitize decoded array data
                if ( is_array( $items ) ) {
                    $items = array_map( function( $item ) {
                        if ( is_array( $item ) ) {
                            return array_map( 'sanitize_text_field', $item );
                        }
                        return sanitize_text_field( $item );
                    }, $items );
                }
            }
            
            if ( empty( $items ) || ! is_array( $items ) ) {
                wp_send_json_error( array( 'message' => 'No items to save' ) );
            }

            // Determine quote type based on form type
            // RFQ form = '24-hour', Sourcing form = 'sourcing'
            if ( 'sourcing' === $form_type ) {
                $quote_type = 'sourcing';
            } else {
                // Default to '24-hour' for RFQ form
                $quote_type = '24-hour';
            }

            // Prepare project data
            $project_data = array(
                'project_name' => $project_name,
                'project_type' => $project_type,
                'timeline' => $timeline,
                'budget_range' => $budget_range,
                'quote_type' => $quote_type,
            );

            // Create or update project with extracted items
            $projects_class = new N88_RFQ_Projects();
            $item_count = count( $items );
            
            // If project_id provided, update existing project; otherwise create new
            if ( $project_id > 0 ) {
                // Update existing project
                $updated = $projects_class->save_project( $user_id, $project_data, N88_RFQ_STATUS_DRAFT, $project_id, $item_count );
                if ( ! $updated ) {
                    error_log( 'N88 RFQ: Failed to update project during extraction confirmation' );
                    wp_send_json_error( array( 'message' => 'Failed to update project. Please try again.' ) );
                }
            } else {
                // Create new project
                $project_id = $projects_class->save_project( $user_id, $project_data, N88_RFQ_STATUS_DRAFT, 0, $item_count );
                
                if ( ! $project_id || $project_id === 0 ) {
                    error_log( 'N88 RFQ: Failed to create project during extraction confirmation' );
                    wp_send_json_error( array( 'message' => 'Failed to create project. Please try again.' ) );
                }
            }

            // Save metadata
            $meta_fields = array(
                'n88_email' => $email,
            );

            // Add optional fields
            if ( ! empty( $_POST['company_name'] ) ) {
                $meta_fields['n88_company_name'] = sanitize_text_field( $_POST['company_name'] );
            }
            if ( ! empty( $_POST['contact_name'] ) ) {
                $meta_fields['n88_contact_name'] = sanitize_text_field( $_POST['contact_name'] );
            }
            if ( ! empty( $_POST['phone'] ) ) {
                $meta_fields['n88_phone'] = sanitize_text_field( $_POST['phone'] );
        }
            if ( ! empty( $_POST['location'] ) ) {
                $meta_fields['n88_location'] = sanitize_text_field( $_POST['location'] );
            }

            $projects_class->save_project_metadata( $project_id, $meta_fields );

            // Convert extracted items to repeater format and save
            $repeater_items = array();
            foreach ( $items as $item ) {
                // Get dimensions from various possible formats
                $length = 0;
                $depth = 0;
                $height = 0;
                
                if ( isset( $item['dimensions'] ) && is_array( $item['dimensions'] ) ) {
                    $length = (float) ( $item['dimensions']['length'] ?? 0 );
                    $depth = (float) ( $item['dimensions']['depth'] ?? 0 );
                    $height = (float) ( $item['dimensions']['height'] ?? 0 );
                } else {
                    $length = (float) ( $item['length'] ?? $item['length_in'] ?? 0 );
                    $depth = (float) ( $item['depth'] ?? $item['depth_in'] ?? 0 );
                    $height = (float) ( $item['height'] ?? $item['height_in'] ?? 0 );
                }
                
                $repeater_item = array(
                    'length_in' => $length,
                    'depth_in' => $depth,
                    'height_in' => $height,
                    'quantity' => isset( $item['quantity'] ) ? (int) $item['quantity'] : 1,
                    'title' => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : 'Item',
                    'notes' => isset( $item['notes'] ) ? sanitize_textarea_field( $item['notes'] ) : '',
                );

                // Handle primary material
                if ( isset( $item['primary_material'] ) ) {
                    $repeater_item['primary_material'] = sanitize_text_field( $item['primary_material'] );
                } elseif ( isset( $item['materials'] ) && is_array( $item['materials'] ) && ! empty( $item['materials'] ) ) {
                    $repeater_item['primary_material'] = sanitize_text_field( $item['materials'][0] );
                }

                // Handle frame material
                if ( isset( $item['frame_material'] ) ) {
                    $repeater_item['frame_material'] = sanitize_text_field( $item['frame_material'] );
                } elseif ( isset( $item['materials'] ) && is_array( $item['materials'] ) && ! empty( $item['materials'] ) ) {
                    $repeater_item['frame_material'] = sanitize_text_field( $item['materials'][0] );
                }

                // Handle finishes
                if ( isset( $item['finishes'] ) && is_array( $item['finishes'] ) && ! empty( $item['finishes'] ) ) {
                    $repeater_item['finish'] = sanitize_text_field( $item['finishes'][0] );
                } elseif ( isset( $item['finish'] ) ) {
                    $repeater_item['finish'] = sanitize_text_field( $item['finish'] );
                }

                // Handle cushions
                if ( isset( $item['cushions'] ) ) {
                    $repeater_item['cushions'] = sanitize_text_field( $item['cushions'] );
                }

                // Handle fabric category
                if ( isset( $item['fabric_category'] ) ) {
                    $repeater_item['fabric_category'] = sanitize_text_field( $item['fabric_category'] );
                }

                // Handle construction notes
                if ( isset( $item['construction_notes'] ) ) {
                    $repeater_item['construction_notes'] = sanitize_textarea_field( $item['construction_notes'] );
                }

                // Handle thumbnail/image
                if ( isset( $item['thumbnail'] ) ) {
                    $repeater_item['image'] = esc_url_raw( $item['thumbnail'] );
                }

                $repeater_items[] = $repeater_item;
            }

            // Save repeater items
            $save_result = $projects_class->save_repeater_items( $project_id, $repeater_items );
            
            if ( false === $save_result ) {
                error_log( 'N88 RFQ: Failed to save repeater items for project ' . $project_id );
            }

            // Mark project as extraction mode if needed
            if ( class_exists( 'N88_RFQ_PDF_Extractor' ) ) {
                N88_RFQ_PDF_Extractor::set_extraction_mode( $project_id, true );
        }

            // Link PDF attachment to project if attachment_id was provided
            $attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
            if ( $attachment_id > 0 ) {
                // Verify attachment exists and update its title to remove "PDF Extraction -" prefix
                $attachment = get_post( $attachment_id );
                if ( $attachment && 'attachment' === $attachment->post_type ) {
                    // Update attachment title to be more descriptive
                    wp_update_post( array(
                        'ID' => $attachment_id,
                        'post_title' => 'Project PDF - ' . sanitize_text_field( $project_name ),
                        'post_parent' => 0, // Keep as orphan for now, or link to project if using post type
                    ) );
                    
                    // Save attachment ID to project metadata
                    $existing_files = $projects_class->get_project_files( $project_id );
                    if ( ! in_array( $attachment_id, $existing_files, true ) ) {
                        $existing_files[] = $attachment_id;
                        $projects_class->save_project_files( $project_id, $existing_files );
                    }
                }
            }

            wp_send_json_success( array( 
                'message' => 'Project created successfully with ' . count( $items ) . ' items',
                'project_id' => $project_id,
                'items_saved' => count( $repeater_items )
            ) );
    }

    /**
     * AJAX: Add item flag
     */
    public function ajax_add_item_flag() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Not logged in' ) );
        }

        N88_RFQ_Helpers::verify_ajax_nonce();

        $project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
        $item_index = isset( $_POST['item_index'] ) ? (int) $_POST['item_index'] : -1;
        $flag_type = isset( $_POST['flag_type'] ) ? sanitize_text_field( $_POST['flag_type'] ) : '';
        $reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( $_POST['reason'] ) : '';

        if ( ! $project_id || $item_index < 0 || ! $flag_type ) {
            wp_send_json_error( array( 'message' => 'Invalid parameters' ) );
        }

        if ( ! class_exists( 'N88_RFQ_Projects' ) ) {
            wp_send_json_error( array( 'message' => 'Projects class not available' ) );
        }

        $projects = new N88_RFQ_Projects();
        
        // Try to get project as owner first
        $project = $projects->get_project( $project_id, get_current_user_id() );
        
        // If not found and user is admin, allow admin access
        if ( ! $project && current_user_can( 'manage_options' ) ) {
            $project = $projects->get_project_admin( $project_id );
        }

        if ( ! $project ) {
            wp_send_json_error( array( 'message' => 'Project not found or access denied' ) );
        }

        if ( ! class_exists( 'N88_RFQ_Item_Flags' ) ) {
            wp_send_json_error( array( 'message' => 'Flags not available' ) );
        }

        $flags = new N88_RFQ_Item_Flags();
        $result = $flags->add_flag( $project_id, $item_index, $flag_type, $reason );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => 'Failed to add flag' ) );
        }

            // Phase 2B: Send notification if urgent flag was added
            if ( $flag_type === 'urgent' && class_exists( 'N88_RFQ_Notifications' ) ) {
                N88_RFQ_Notifications::notify_urgent_flag_triggered( $project_id, $item_index, $reason );
        }

        wp_send_json_success( array( 'message' => 'Flag added successfully' ) );
    }

    /**
     * AJAX: Remove item flag
     */
    public function ajax_remove_item_flag() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Not logged in' ) );
        }

        N88_RFQ_Helpers::verify_ajax_nonce();

        $project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
        $item_index = isset( $_POST['item_index'] ) ? (int) $_POST['item_index'] : -1;
        $flag_type = isset( $_POST['flag_type'] ) ? sanitize_text_field( $_POST['flag_type'] ) : '';

        if ( ! $project_id || $item_index < 0 || ! $flag_type ) {
            wp_send_json_error( array( 'message' => 'Invalid parameters' ) );
        }

        if ( ! class_exists( 'N88_RFQ_Projects' ) ) {
            wp_send_json_error( array( 'message' => 'Projects class not available' ) );
        }

        $projects = new N88_RFQ_Projects();
        
        // Try to get project as owner first
        $project = $projects->get_project( $project_id, get_current_user_id() );
        
        // If not found and user is admin, allow admin access
        if ( ! $project && current_user_can( 'manage_options' ) ) {
            $project = $projects->get_project_admin( $project_id );
        }

        if ( ! $project ) {
            wp_send_json_error( array( 'message' => 'Project not found or access denied' ) );
        }

        if ( ! class_exists( 'N88_RFQ_Item_Flags' ) ) {
            wp_send_json_error( array( 'message' => 'Flags not available' ) );
        }

        $flags = new N88_RFQ_Item_Flags();
        $result = $flags->remove_flag( $project_id, $item_index, $flag_type );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => 'Failed to remove flag' ) );
        }

        wp_send_json_success( array( 'message' => 'Flag removed successfully' ) );
    }

        /**
         * AJAX: Create draft project for PDF extraction (when no project_id exists)
         */
        public function ajax_create_draft_for_pdf() {
            if ( ! is_user_logged_in() ) {
                wp_send_json_error( array( 'message' => 'Not logged in' ) );
            }

            // Verify nonce (with fallback for backward compatibility)
            if ( ! N88_RFQ_Helpers::verify_nonce_with_fallback( 'nonce', true ) ) {
                wp_send_json_error( array( 
                    'message' => 'Security check failed. Please refresh the page and try again.',
                    'debug' => 'Nonce verification failed'
                ) );
            }

            $user_id = get_current_user_id();
            $form_type = sanitize_text_field( $_POST['form_type'] ?? 'rfq' );

            // Get required fields
            $project_name = sanitize_text_field( $_POST['project_name'] ?? '' );
            $project_type = sanitize_text_field( $_POST['project_type'] ?? '' );
            $timeline = sanitize_text_field( $_POST['timeline'] ?? '' );
            $budget_range = sanitize_text_field( $_POST['budget_range'] ?? '' );
            $email = sanitize_email( $_POST['email'] ?? '' );

            // Validate required fields
            if ( empty( $project_name ) ) {
                wp_send_json_error( array( 'message' => 'Project Name is required' ) );
            }
            if ( empty( $project_type ) ) {
                wp_send_json_error( array( 'message' => 'Project Type is required' ) );
            }
            if ( empty( $timeline ) ) {
                wp_send_json_error( array( 'message' => 'Timeline is required' ) );
            }
            if ( empty( $budget_range ) ) {
                wp_send_json_error( array( 'message' => 'Budget Range is required' ) );
            }
            if ( empty( $email ) ) {
                wp_send_json_error( array( 'message' => 'Email is required' ) );
            }

            if ( ! is_email( $email ) ) {
                wp_send_json_error( array( 'message' => 'Invalid email address' ) );
            }

            // Determine quote type based on form type
            // RFQ form = '24-hour', Sourcing form = 'sourcing'
            if ( 'sourcing' === $form_type ) {
                $quote_type = 'sourcing';
            } else {
                // Default to '24-hour' for RFQ form
                $quote_type = '24-hour';
            }

            // Prepare project data
            $project_data = array(
                'project_name' => $project_name,
                'project_type' => $project_type,
                'timeline' => $timeline,
                'budget_range' => $budget_range,
                'quote_type' => $quote_type,
            );

            // Save project as draft (item_count = 0 is fine for drafts)
            $projects_class = new N88_RFQ_Projects();
            
            // Log before save
            error_log( 'N88 RFQ: Attempting to create draft project. User ID: ' . $user_id . ', Data: ' . print_r( $project_data, true ) );
            
            $project_id = $projects_class->save_project( $user_id, $project_data, N88_RFQ_STATUS_DRAFT, 0, 0 );

            // Check for database errors
            global $wpdb;
            if ( ! empty( $wpdb->last_error ) ) {
                error_log( 'N88 RFQ: Database error: ' . $wpdb->last_error );
                error_log( 'N88 RFQ: Last query: ' . $wpdb->last_query );
                wp_send_json_error( array( 
                    'message' => 'Database error occurred. Please try again or contact support.',
                    'debug' => 'DB Error: ' . $wpdb->last_error
                ) );
            }

            if ( ! $project_id || $project_id === 0 || $project_id === false ) {
                error_log( 'N88 RFQ: Failed to create draft project. User ID: ' . $user_id );
                error_log( 'N88 RFQ: Project data: ' . print_r( $project_data, true ) );
                error_log( 'N88 RFQ: save_project returned: ' . var_export( $project_id, true ) );
                error_log( 'N88 RFQ: Last query: ' . $wpdb->last_query );
                error_log( 'N88 RFQ: Last error: ' . $wpdb->last_error );
                wp_send_json_error( array( 
                    'message' => 'Failed to create project. Please check that all required fields are filled and try again.',
                    'debug' => 'save_project returned: ' . var_export( $project_id, true ) . ', Last error: ' . $wpdb->last_error
                ) );
            }
            
            error_log( 'N88 RFQ: Project created successfully with ID: ' . $project_id );

            // Save metadata
            $meta_fields = array(
                'n88_email' => $email,
            );

            // Add optional fields
            if ( ! empty( $_POST['company_name'] ) ) {
                $meta_fields['n88_company_name'] = sanitize_text_field( $_POST['company_name'] );
            }
            if ( ! empty( $_POST['contact_name'] ) ) {
                $meta_fields['n88_contact_name'] = sanitize_text_field( $_POST['contact_name'] );
            }
            if ( ! empty( $_POST['phone'] ) ) {
                $meta_fields['n88_phone'] = sanitize_text_field( $_POST['phone'] );
            }
            if ( ! empty( $_POST['location'] ) ) {
                $meta_fields['n88_location'] = sanitize_text_field( $_POST['location'] );
            }

            // Save empty repeater items (for PDF extraction mode)
            $meta_fields['n88_repeater_raw'] = '[]';

            $metadata_result = $projects_class->save_project_metadata( $project_id, $meta_fields );

            if ( ! $metadata_result ) {
                error_log( 'N88 RFQ: Failed to save metadata for project ' . $project_id );
            }

            wp_send_json_success( array( 
                'message' => 'Draft project created successfully',
                'project_id' => (int) $project_id
            ) );
        }

        /**
         * AJAX: Verify project exists
         */
        public function ajax_verify_project() {
            if ( ! is_user_logged_in() ) {
                wp_send_json_error( array( 'message' => 'Not logged in' ) );
            }

            N88_RFQ_Helpers::verify_ajax_nonce();

            $project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;

            if ( ! $project_id || $project_id <= 0 ) {
                wp_send_json_error( array( 'message' => 'Invalid project ID' ) );
            }

            if ( ! class_exists( 'N88_RFQ_Projects' ) ) {
                wp_send_json_error( array( 'message' => 'Projects class not available' ) );
            }

            $projects = new N88_RFQ_Projects();
            $project = $projects->get_project( $project_id, get_current_user_id() );

            if ( ! $project ) {
                // Try admin method as fallback
                $project = $projects->get_project_admin( $project_id );
                if ( ! $project || (int) $project['user_id'] !== get_current_user_id() ) {
                    wp_send_json_error( array( 'message' => 'Project not found or access denied' ) );
                }
            }

            wp_send_json_success( array( 
                'message' => 'Project verified',
                'project_id' => $project_id
            ) );
        }

    /**
     * Cleanup abandoned PDF extraction attachments.
     * 
     * Removes PDF attachments that were created for extraction but never linked to a project.
     * Attachments older than 24 hours with title starting with "PDF Extraction -" are considered abandoned.
     * 
     * @return int Number of attachments deleted.
     */
    public function cleanup_abandoned_pdf_extractions() {
        global $wpdb;

        // Find all attachments with title starting with "PDF Extraction -" that are older than 24 hours
        $cutoff_time = date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
        
        $abandoned_attachments = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_title LIKE %s 
            AND post_date < %s
            AND post_mime_type = 'application/pdf'",
            'PDF Extraction -%',
            $cutoff_time
        ) );

        if ( empty( $abandoned_attachments ) ) {
            return 0;
        }

        // Check if any of these attachments are linked to projects
        $meta_table = $wpdb->prefix . 'project_metadata';
        $deleted_count = 0;

        foreach ( $abandoned_attachments as $attachment ) {
            $attachment_id = (int) $attachment->ID;
            
            // Check if this attachment is linked to any project via n88_files metadata
            $linked = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$meta_table} 
                WHERE meta_key = 'n88_files' 
                AND meta_value LIKE %s",
                '%"' . $attachment_id . '"%'
            ) );

            // If not linked to any project, delete it
            if ( 0 === (int) $linked ) {
                $deleted = wp_delete_attachment( $attachment_id, true ); // true = force delete (removes file)
                if ( $deleted ) {
                    $deleted_count++;
                }
            }
        }

        // Log cleanup activity
        if ( $deleted_count > 0 ) {
            error_log( sprintf( 
                'N88 RFQ: Cleaned up %d abandoned PDF extraction attachments older than 24 hours.',
                $deleted_count
            ) );
        }

        return $deleted_count;
    }
}

