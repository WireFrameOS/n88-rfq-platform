<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Authentication and User Management
 * Handles signup, login, and designer role management
 */
class N88_RFQ_Auth {

    /**
     * Commit 2.3.7.1: System-invited route expiry window (in seconds)
     * Production: 172800 (48 hours)
     * Testing: 3600 (60 minutes = 1 hour)
     * 
     * To change from testing to production, update this constant to 172800
     */
    const N88_SYSTEM_INVITED_EXPIRY_SECONDS = 120; // 60 minutes for testing (change to 172800 for 48 hours)

    public function __construct() {
        // Register shortcodes
        add_shortcode( 'n88_signup', array( $this, 'render_signup_form' ) );
        add_shortcode( 'n88_login', array( $this, 'render_login_form' ) );
        add_shortcode( 'n88_designer_dashboard', array( $this, 'render_designer_dashboard' ) );

        // Commit 2.2.1: Register new route shortcodes
        add_shortcode( 'n88_workspace', array( $this, 'render_workspace' ) );
        add_shortcode( 'n88_supplier_queue', array( $this, 'render_supplier_queue' ) );
        add_shortcode( 'n88_admin_queue', array( $this, 'render_admin_queue' ) );
        
        // Commit 2.2.7: Supplier onboarding
        add_shortcode( 'n88_supplier_onboarding', array( $this, 'render_supplier_onboarding' ) );
        
        // Commit 2.2.8: Designer onboarding
        add_shortcode( 'n88_designer_onboarding', array( $this, 'render_designer_onboarding' ) );

        // AJAX handlers
        add_action( 'wp_ajax_n88_register_user', array( $this, 'ajax_register_user' ) );
        add_action( 'wp_ajax_nopriv_n88_register_user', array( $this, 'ajax_register_user' ) );
        add_action( 'wp_ajax_n88_login_user', array( $this, 'ajax_login_user' ) );
        add_action( 'wp_ajax_nopriv_n88_login_user', array( $this, 'ajax_login_user' ) );

        // Commit 2.2.7: Supplier profile save handler
        add_action( 'wp_ajax_n88_save_supplier_profile', array( $this, 'ajax_save_supplier_profile' ) );
        
        // Commit 2.2.7: AJAX handler to fetch keywords by category
        add_action( 'wp_ajax_n88_get_keywords_by_category', array( $this, 'ajax_get_keywords_by_category' ) );
        
        // Commit 2.2.8: Designer profile save handler
        add_action( 'wp_ajax_n88_save_designer_profile', array( $this, 'ajax_save_designer_profile' ) );
        
        // Commit 2.3.2: Supplier RFQ detail view (read-only)
        add_action( 'wp_ajax_n88_get_supplier_item_details', array( $this, 'ajax_get_supplier_item_details' ) );
        add_action( 'wp_ajax_n88_get_item_rfq_state', array( $this, 'ajax_get_item_rfq_state' ) );
        
        // Commit 2.3.3: Supplier bid validation (no persistence)
        add_action( 'wp_ajax_n88_validate_supplier_bid', array( $this, 'ajax_validate_supplier_bid' ) );
        // Commit 2.3.5: Supplier bid submission (persistence)
        add_action( 'wp_ajax_n88_submit_supplier_bid', array( $this, 'ajax_submit_supplier_bid' ) );
        // Commit 2.3.5: Withdraw bid (optional)
        add_action( 'wp_ajax_n88_withdraw_supplier_bid', array( $this, 'ajax_withdraw_supplier_bid' ) );
        
        // Commit 2.3.6: Save bid draft
        add_action( 'wp_ajax_n88_save_bid_draft', array( $this, 'ajax_save_bid_draft' ) );
        add_action( 'wp_ajax_n88_get_bid_draft', array( $this, 'ajax_get_bid_draft' ) );
        
        // G) Update bid to match new specs (create new draft from stale bid)
        add_action( 'wp_ajax_n88_update_bid_to_match_new_specs', array( $this, 'ajax_update_bid_to_match_new_specs' ) );
        
        // Commit 2.3.4: RFQ submission routing
        add_action( 'wp_ajax_n88_submit_rfq', array( $this, 'ajax_submit_rfq' ) );

        // Create custom roles on activation
        add_action( 'init', array( $this, 'create_custom_roles' ) );

        // Redirect designers to custom dashboard after login
        add_filter( 'login_redirect', array( $this, 'redirect_designer_after_login' ), 10, 3 );
        add_action( 'wp_login', array( $this, 'handle_designer_login' ), 10, 2 );

        // Redirect logout to custom login page
        add_filter( 'logout_url', array( $this, 'custom_logout_url' ), 10, 2 );
        add_action( 'wp_logout', array( $this, 'redirect_after_logout' ) );
        add_action( 'init', array( $this, 'handle_custom_logout' ) );

        // Hide WordPress admin menus for designers
        add_action( 'admin_menu', array( $this, 'hide_wp_menus_for_designer' ), 999 );
        add_action( 'admin_bar_menu', array( $this, 'remove_wp_admin_bar_items' ), 999 );
        
        // Hide WordPress admin bar completely for designers and suppliers
        add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_for_custom_roles' ) );

        // Redirect designers away from WordPress admin pages
        add_action( 'admin_init', array( $this, 'redirect_designer_from_wp_admin' ) );

        // Enqueue styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_auth_styles' ) );

        // Route guards (Commit 2.2.1)
        add_action( 'template_redirect', array( $this, 'enforce_route_guards' ) );
        add_action( 'admin_init', array( $this, 'enforce_admin_route_guards' ) );
    }

    /**
     * Create custom roles with appropriate capabilities (Commit 2.2.1)
     */
    public function create_custom_roles() {
        // Create n88_designer role
        if ( ! get_role( 'n88_designer' ) ) {
        add_role(
                'n88_designer',
            __( 'Creator', 'n88-rfq' ),
            array(
                'read' => true,
                'upload_files' => true,
                    // Legacy capabilities for backward compatibility
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
                __( 'Maker', 'n88-rfq' ),
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
                    'manage_options' => true, // Full admin access
                )
            );
        }

        // Migrate existing 'designer' role to 'n88_designer' if it exists
        $old_designer_role = get_role( 'designer' );
        if ( $old_designer_role ) {
            // Get all users with old designer role
            $users = get_users( array( 'role' => 'designer' ) );
            foreach ( $users as $user ) {
                $user_obj = new WP_User( $user->ID );
                $user_obj->remove_role( 'designer' );
                $user_obj->add_role( 'n88_designer' );
            }
            // Remove old role (optional - keeping for backward compatibility during migration)
            // remove_role( 'designer' );
        }
    }

    /**
     * Enqueue authentication styles
     */
    public function enqueue_auth_styles() {
        if ( defined( 'N88_RFQ_PLUGIN_URL' ) ) {
            $plugin_url = trailingslashit( N88_RFQ_PLUGIN_URL );
        } else {
            $plugin_file = dirname( dirname( __FILE__ ) ) . '/n88-rfq-platform.php';
            $plugin_url = trailingslashit( plugin_dir_url( $plugin_file ) );
        }

        wp_enqueue_style(
            'n88-rfq-auth',
            $plugin_url . 'assets/css/n88-rfq-auth.css',
            array(),
            N88_RFQ_VERSION
        );
    }

    /**
     * Render signup form shortcode
     */
    public function render_signup_form( $atts = array() ) {
        // If user is already logged in, redirect or show message
        if ( is_user_logged_in() ) {
            $current_user = wp_get_current_user();
            if ( in_array( 'n88_designer', $current_user->roles ) || in_array( 'designer', $current_user->roles ) ) {
                return '<p>You are already logged in. <a href="' . esc_url( home_url( '/workspace' ) ) . '">Go to Workspace</a></p>';
            }
            return '<p>You are already logged in.</p>';
        }

        $message = '';
        $message_type = '';

        // Check for messages from redirect
        if ( isset( $_GET['n88_signup_error'] ) ) {
            $message_type = 'error';
            $message = isset( $_GET['n88_error_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['n88_error_msg'] ) ) : 'Registration failed. Please try again.';
        }

        if ( isset( $_GET['n88_signup_success'] ) ) {
            $message_type = 'success';
            $message = 'Registration successful! Please log in.';
        }

        ob_start();
        ?>
        <div class="n88-auth-container n88-signup-container">
            <div class="n88-auth-form-wrapper">
                <h2 class="n88-auth-title">Create Account</h2>
                
                <?php if ( $message ) : ?>
                    <div class="n88-auth-message n88-auth-message-<?php echo esc_attr( $message_type ); ?>">
                        <?php echo esc_html( $message ); ?>
                    </div>
                <?php endif; ?>

                <form id="n88-signup-form" class="n88-auth-form" method="post">
                    <?php wp_nonce_field( 'n88_register_user', 'n88_signup_nonce' ); ?>
                    
                    <div class="n88-form-group">
                        <label>Account Type <span class="required">*</span></label>
                        <div style="display: flex; gap: 20px; margin-top: 8px;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
                                <input type="radio" name="user_role" value="n88_designer" checked required style="margin: 0;">
                                <span>Creator</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
                                <input type="radio" name="user_role" value="n88_supplier_admin" required style="margin: 0;">
                                <span>Maker</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="n88-form-group">
                        <label for="n88_signup_name">Full Name <span class="required">*</span></label>
                        <input type="text" id="n88_signup_name" name="name" required>
                    </div>

                    <div class="n88-form-group">
                        <label for="n88_signup_username">Username <span class="required">*</span></label>
                        <input type="text" id="n88_signup_username" name="username" required>
                    </div>

                    <div class="n88-form-group">
                        <label for="n88_signup_email">Email <span class="required">*</span></label>
                        <input type="email" id="n88_signup_email" name="email" required>
                    </div>

                    <div class="n88-form-group">
                        <label for="n88_signup_password">Password <span class="required">*</span></label>
                        <input type="password" id="n88_signup_password" name="password" required minlength="6">
                    </div>

                    <div class="n88-form-group">
                        <label for="n88_signup_company">Company Name <span class="required">*</span></label>
                        <input type="text" id="n88_signup_company" name="company_name" required>
                    </div>

                    <div class="n88-form-group">
                        <label for="n88_signup_country">Country <span class="required">*</span></label>
                        <select id="n88_signup_country" name="country" required>
                            <option value="">Select Country</option>
                            <?php
                            $countries = $this->get_countries_list();
                            foreach ( $countries as $code => $name ) {
                                echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $name ) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="n88-form-group">
                        <button type="submit" class="n88-auth-submit">Sign Up</button>
                    </div>

                    <div class="n88-auth-footer">
                        <p>Already have an account? <a href="<?php echo esc_url( home_url( '/login/' ) ); ?>">Log In</a></p>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function() {
            const form = document.getElementById('n88-signup-form');
            if (!form) return;

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(form);
                formData.append('action', 'n88_register_user');
                
                // Debug: Check if nonce is included
                if (!formData.has('n88_signup_nonce')) {
                    console.error('N88 RFQ: Nonce field missing from form data');
                    alert('Form error: Security token missing. Please refresh the page and try again.');
                    return;
                }

                const submitBtn = form.querySelector('.n88-auth-submit');
                const originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.textContent = 'Signing Up...';

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = data.data.redirect_url || '<?php echo esc_url( home_url( '/login/?n88_signup_success=1' ) ); ?>';
                    } else {
                        alert(data.data.message || 'Registration failed. Please try again.');
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle logout on custom login page
     */
    public function handle_custom_logout() {
        // Only process on /login page
        if ( ! is_admin() && isset( $_GET['action'] ) && $_GET['action'] === 'logout' ) {
            // Check if we're on the login page (you may need to adjust this check based on your permalink structure)
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            
            // Check if URL contains /login/
            if ( strpos( $request_uri, '/login/' ) !== false || strpos( $request_uri, '/login' ) !== false ) {
                // Verify nonce
                if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'log-out' ) ) {
                    wp_logout();
                    wp_safe_redirect( home_url( '/login/' ) );
                    exit;
                }
            }
        }
    }

    /**
     * Render login form shortcode
     */
    public function render_login_form( $atts = array() ) {
        // If user is already logged in, redirect based on role (Commit 2.2.1)
        if ( is_user_logged_in() ) {
            $current_user = wp_get_current_user();
            $redirect_url = $this->get_role_redirect_url( $current_user );
            if ( $redirect_url ) {
                wp_redirect( $redirect_url );
                exit;
            }
            return '<p>You are already logged in.</p>';
        }

        $message = '';
        $message_type = '';

        // Check for messages
        if ( isset( $_GET['n88_login_error'] ) ) {
            $message_type = 'error';
            $message = isset( $_GET['n88_error_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['n88_error_msg'] ) ) : 'Login failed. Please try again.';
        }

        if ( isset( $_GET['n88_signup_success'] ) ) {
            $message_type = 'success';
            $message = 'Registration successful! Please log in.';
        }

        ob_start();
        ?>
        <div class="n88-auth-container n88-login-container">
            <div class="n88-auth-form-wrapper">
                <h2 class="n88-auth-title">Login Your Account</h2>
                
                <?php if ( $message ) : ?>
                    <div class="n88-auth-message n88-auth-message-<?php echo esc_attr( $message_type ); ?>">
                        <?php echo esc_html( $message ); ?>
                    </div>
                <?php endif; ?>

                <form id="n88-login-form" class="n88-auth-form" method="post">
                    <?php wp_nonce_field( 'n88_login_user', 'n88_login_nonce' ); ?>
                    
                    <div class="n88-form-group">
                        <label for="n88_login_username">Username or Email</label>
                        <input type="text" id="n88_login_username" name="username" required>
                    </div>

                    <div class="n88-form-group">
                        <label for="n88_login_password">Password</label>
                        <input type="password" id="n88_login_password" name="password" required>
                    </div>

                    <div class="n88-form-group">
                        <label class="n88-checkbox-label">
                            <input type="checkbox" name="remember" value="1"> Remember me
                        </label>
                    </div>

                    <div class="n88-form-group">
                        <button type="submit" class="n88-auth-submit">Log In</button>
                    </div>

                    <div class="n88-auth-footer">
                        <p>Don't have an account? <a href="<?php echo esc_url( home_url( '/signup/' ) ); ?>">Sign Up</a></p>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function() {
            const form = document.getElementById('n88-login-form');
            if (!form) return;

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(form);
                formData.append('action', 'n88_login_user');
                
                // Debug: Check if nonce is included
                if (!formData.has('n88_login_nonce')) {
                    console.error('N88 RFQ: Nonce field missing from form data');
                    alert('Form error: Security token missing. Please refresh the page and try again.');
                    return;
                }

                const submitBtn = form.querySelector('.n88-auth-submit');
                const originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.textContent = 'Logging In...';

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = data.data.redirect_url || '<?php echo esc_url( home_url( '/workspace' ) ); ?>';
                    } else {
                        alert(data.data.message || 'Login failed. Please check your credentials.');
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler for user registration
     */
    public function ajax_register_user() {
        // Verify nonce - check both POST and GET
        $nonce = isset( $_POST['n88_signup_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['n88_signup_nonce'] ) ) : '';
        
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'n88_register_user' ) ) {
            // Log for debugging
            error_log( 'N88 RFQ: Registration nonce verification failed. Nonce: ' . ( empty( $nonce ) ? 'empty' : 'present' ) );
            wp_send_json_error( array( 
                'message' => 'Security check failed. Please refresh the page and try again.',
                'debug' => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'Nonce verification failed' : ''
            ) );
        }

        // Get and sanitize form data
        $user_role = isset( $_POST['user_role'] ) ? sanitize_text_field( wp_unslash( $_POST['user_role'] ) ) : '';
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $password = isset( $_POST['password'] ) ? $_POST['password'] : '';
        $company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
        $country = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';

        // Validate required fields
        if ( empty( $user_role ) || empty( $name ) || empty( $username ) || empty( $email ) || empty( $password ) || empty( $company_name ) || empty( $country ) ) {
            wp_send_json_error( array( 'message' => 'All fields are required.' ) );
        }

        // Validate role
        $allowed_roles = array( 'n88_designer', 'n88_supplier_admin' );
        if ( ! in_array( $user_role, $allowed_roles, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid account type selected.' ) );
        }

        // Validate email
        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Invalid email address.' ) );
        }

        // Validate password length
        if ( strlen( $password ) < 6 ) {
            wp_send_json_error( array( 'message' => 'Password must be at least 6 characters long.' ) );
        }

        // Check if username already exists
        if ( username_exists( $username ) ) {
            wp_send_json_error( array( 'message' => 'Username already exists. Please choose another.' ) );
        }

        // Check if email already exists
        if ( email_exists( $email ) ) {
            wp_send_json_error( array( 'message' => 'Email already registered. Please use a different email or log in.' ) );
        }

        // Create user
        $user_id = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
        }

        // Update user display name
        wp_update_user( array(
            'ID' => $user_id,
            'display_name' => $name,
        ) );

        // Assign role based on user selection (Commit 2.2.1)
        $user = new WP_User( $user_id );
        $user->set_role( $user_role );

        // Save custom user meta
        update_user_meta( $user_id, 'company_name', $company_name );
        update_user_meta( $user_id, 'country', $country );

        // Log event if function exists
        if ( function_exists( 'n88_log_event' ) ) {
            n88_log_event(
                'user_registered',
                'user',
                array(
                    'object_id' => $user_id,
                    'user_id' => $user_id,
                )
            );
        }

        wp_send_json_success( array(
            'message' => 'Registration successful!',
            'redirect_url' => home_url( '/login/?n88_signup_success=1' ),
        ) );
    }

    /**
     * AJAX handler for user login
     */
    public function ajax_login_user() {
        // Verify nonce - check both POST and GET
        $nonce = isset( $_POST['n88_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['n88_login_nonce'] ) ) : '';
        
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'n88_login_user' ) ) {
            // Log for debugging
            error_log( 'N88 RFQ: Login nonce verification failed. Nonce: ' . ( empty( $nonce ) ? 'empty' : 'present' ) );
            wp_send_json_error( array( 
                'message' => 'Security check failed. Please refresh the page and try again.',
                'debug' => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'Nonce verification failed' : ''
            ) );
        }

        $username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
        $password = isset( $_POST['password'] ) ? $_POST['password'] : '';
        $remember = isset( $_POST['remember'] ) && $_POST['remember'] === '1';

        if ( empty( $username ) || empty( $password ) ) {
            wp_send_json_error( array( 'message' => 'Username and password are required.' ) );
        }

        // Attempt login
        $user = wp_authenticate( $username, $password );

        if ( is_wp_error( $user ) ) {
            wp_send_json_error( array( 'message' => 'Invalid username or password.' ) );
        }

        // Check if user has one of our custom roles (Commit 2.2.1)
        $allowed_roles = array( 'n88_designer', 'n88_supplier_admin', 'n88_system_operator', 'designer' ); // Include 'designer' for backward compatibility
        $user_has_role = false;
        foreach ( $allowed_roles as $role ) {
            if ( in_array( $role, $user->roles, true ) ) {
                $user_has_role = true;
                break;
            }
        }

        if ( ! $user_has_role ) {
            wp_send_json_error( array( 'message' => 'Access denied. Valid account required.' ) );
        }

        // Log the user in
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, $remember );

        // Determine redirect URL based on role (Commit 2.2.1)
        $redirect_url = $this->get_role_redirect_url( $user );

        wp_send_json_success( array(
            'message' => 'Login successful!',
            'redirect_url' => $redirect_url,
        ) );
    }

    /**
     * Get redirect URL based on user role (Commit 2.2.1, updated 2.2.7)
     */
    private function get_role_redirect_url( $user ) {
        if ( ! $user || ! isset( $user->roles ) ) {
            return null;
        }

        // Check roles in priority order
        if ( in_array( 'n88_system_operator', $user->roles, true ) ) {
            return home_url( '/admin/queue?scope=global' );
        }
        
        if ( in_array( 'n88_supplier_admin', $user->roles, true ) ) {
            // Commit 2.2.7: Check if supplier profile is incomplete
            global $wpdb;
            $supplier_profiles_table = $wpdb->prefix . 'n88_supplier_profiles';
            $profile_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT supplier_id FROM {$supplier_profiles_table} WHERE supplier_id = %d",
                $user->ID
            ) );
            
            // If profile doesn't exist, redirect to onboarding
            if ( ! $profile_exists ) {
                return home_url( '/supplier/onboarding' );
            }
            
            // Otherwise, redirect to queue
            return home_url( '/supplier/queue' );
        }
        
        if ( in_array( 'n88_designer', $user->roles, true ) || in_array( 'designer', $user->roles, true ) ) {
            // Commit 2.2.8: Check if designer profile is incomplete
            global $wpdb;
            $designer_profiles_table = $wpdb->prefix . 'n88_designer_profiles_v2';
            
            // Check if profile exists - use COUNT for more reliable check
            // Suppress errors in case table doesn't exist yet
            $wpdb->suppress_errors( true );
            $profile_count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$designer_profiles_table} WHERE designer_id = %d",
                $user->ID
            ) );
            $wpdb->suppress_errors( false );
            
            // If profile doesn't exist (count is false, null, or 0), redirect to onboarding
            if ( $profile_count === false || $profile_count === null || (int) $profile_count === 0 ) {
                return home_url( '/designer/onboarding' );
            }
            
            // Otherwise, redirect to workspace
            return home_url( '/workspace' );
        }

        return null;
    }

    /**
     * Redirect user after login based on role (Commit 2.2.1)
     */
    public function redirect_designer_after_login( $redirect_to, $requested_redirect_to, $user ) {
        $role_redirect = $this->get_role_redirect_url( $user );
        if ( $role_redirect ) {
            return $role_redirect;
        }
        return $redirect_to;
    }

    /**
     * Handle user login (updated for all roles)
     */
    public function handle_designer_login( $user_login, $user ) {
        // Additional logic on login if needed
        // Can be used for logging, notifications, etc.
    }

    /**
     * Custom logout URL - redirect to /login instead of wp-login.php
     */
    public function custom_logout_url( $logout_url, $redirect ) {
        // Build custom logout URL pointing to /login
        $logout_url = wp_nonce_url( home_url( '/login/?action=logout' ), 'log-out' );
        
        // If a redirect was specified, append it
        if ( $redirect ) {
            $logout_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $logout_url );
        }
        
        return $logout_url;
    }

    /**
     * Redirect after logout to custom login page
     */
    public function redirect_after_logout() {
        // Redirect to custom login page
        wp_safe_redirect( home_url( '/login/' ) );
        exit;
    }

    /**
     * Hide WordPress admin menus for designers (Commit 2.2.1)
     */
    public function hide_wp_menus_for_designer() {
        $current_user = wp_get_current_user();
        if ( ! $current_user || ! ( in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true ) ) ) {
            return;
        }

        // Remove all default WordPress menus except our plugin menus
        global $menu, $submenu;

        // Keep only our plugin menus
        $allowed_menus = array(
            'n88-rfq-dashboard',
            'n88-rfq-board-demo',
            'n88-rfq-items-boards-test',
        );

        if ( is_array( $menu ) ) {
            foreach ( $menu as $key => $item ) {
                if ( ! in_array( $item[2], $allowed_menus, true ) ) {
                    remove_menu_page( $item[2] );
                }
            }
        }
    }

    /**
     * Hide WordPress admin bar completely for designers and suppliers
     */
    public function hide_admin_bar_for_custom_roles( $show ) {
        if ( ! is_user_logged_in() ) {
            return $show;
        }
        
        $current_user = wp_get_current_user();
        if ( ! $current_user ) {
            return $show;
        }
        
        // Check if user is a designer/creator
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        
        // Check if user is a supplier/maker
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        
        // Check if user is a system operator (allow admin bar for system operators)
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        // Hide admin bar for designers and suppliers, but not for system operators
        if ( ( $is_designer || $is_supplier ) && ! $is_system_operator ) {
            // Also add inline CSS to forcefully hide admin bar
            add_action( 'wp_head', function() {
                echo '<style>#wpadminbar { display: none !important; visibility: hidden !important; opacity: 0 !important; height: 0 !important; overflow: hidden !important; } html { margin-top: 0 !important; } body.admin-bar { margin-top: 0 !important; }</style>';
            }, 999 );
            add_action( 'admin_head', function() {
                echo '<style>#wpadminbar { display: none !important; visibility: hidden !important; opacity: 0 !important; height: 0 !important; overflow: hidden !important; } html { margin-top: 0 !important; } body.admin-bar { margin-top: 0 !important; }</style>';
            }, 999 );
            return false;
        }
        
        return $show;
    }

    /**
     * Remove WordPress admin bar items for designers (Commit 2.2.1)
     */
    public function remove_wp_admin_bar_items( $wp_admin_bar ) {
        $current_user = wp_get_current_user();
        if ( ! $current_user || ! ( in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true ) ) ) {
            return;
        }

        // Remove WordPress logo
        $wp_admin_bar->remove_node( 'wp-logo' );

        // Remove comments
        $wp_admin_bar->remove_node( 'comments' );

        // Remove "New" menu items except for our plugin
        $wp_admin_bar->remove_node( 'new-content' );
        $wp_admin_bar->remove_node( 'new-post' );
        $wp_admin_bar->remove_node( 'new-page' );
        $wp_admin_bar->remove_node( 'new-media' );
    }

    /**
     * Redirect designers away from WordPress admin pages (Commit 2.2.1)
     */
    public function redirect_designer_from_wp_admin() {
        $current_user = wp_get_current_user();
        if ( ! $current_user || ! ( in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true ) ) ) {
            return;
        }

        // Get current screen
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        // Allow access to our plugin pages
        $allowed_pages = array(
            'n88-rfq-dashboard',
            'n88-rfq-board-demo',
            'n88-rfq-items-boards-test',
        );

        // If trying to access a non-allowed page, redirect to dashboard
        if ( ! in_array( $screen->id, $allowed_pages, true ) && strpos( $screen->id, 'n88-rfq' ) === false ) {
            wp_redirect( admin_url( 'admin.php?page=n88-rfq-dashboard' ) );
            exit;
        }
    }

    /**
     * Get designer's board (designers can only have 1 board)
     */
    private function get_designer_board( $user_id ) {
        global $wpdb;
        $boards_table = $wpdb->prefix . 'n88_boards';
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$boards_table} WHERE owner_user_id = %d AND deleted_at IS NULL ORDER BY created_at ASC LIMIT 1",
                $user_id
            )
        );
    }

    /**
     * Check if designer has any items
     */
    private function designer_has_items( $user_id ) {
        global $wpdb;
        $items_table = $wpdb->prefix . 'n88_items';
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$items_table} WHERE owner_user_id = %d AND deleted_at IS NULL",
                $user_id
            )
        );
        return $count > 0;
    }

    /**
     * Enforce route guards for frontend routes (Commit 2.2.1)
     * Only applies to frontend pages, not WordPress admin
     */
    public function enforce_route_guards() {
        // Only apply to frontend, not admin
        if ( is_admin() ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        $current_user = wp_get_current_user();
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $parsed_url = parse_url( $request_uri );
        $path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';

        // Skip if this is a WordPress admin path (wp-admin, wp-login, etc.)
        if ( strpos( $path, '/wp-admin' ) !== false || strpos( $path, '/wp-login' ) !== false ) {
            return;
        }

        // Check if user is a designer
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        
        // Check if user is a supplier
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        
        // Check if user is a system operator
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );

        // Designers blocked from frontend /admin/* and /supplier/* routes (not WordPress admin)
        if ( $is_designer && ! $is_system_operator ) {
            // Only block if it's a frontend route, not WordPress admin
            if ( ( strpos( $path, '/admin/' ) !== false || strpos( $path, '/supplier/' ) !== false ) && strpos( $path, '/wp-admin' ) === false ) {
                self::render_403_error( 'Access Denied', 'You do not have permission to access this page. Creators are restricted from accessing super admin and maker areas.' );
                exit;
            }
        }

        // Suppliers blocked from global queue scope on frontend
        if ( $is_supplier && ! $is_system_operator ) {
            if ( strpos( $path, '/admin/queue' ) !== false && strpos( $path, '/wp-admin' ) === false ) {
                $query_params = isset( $parsed_url['query'] ) ? $parsed_url['query'] : '';
                parse_str( $query_params, $query_vars );
                if ( isset( $query_vars['scope'] ) && $query_vars['scope'] === 'global' ) {
                    self::render_403_error( 'Access Denied', 'You do not have permission to access the global queue. Makers can only access the maker queue.' );
                    exit;
                }
            }
        }
    }

    /**
     * Enforce route guards for admin routes (Commit 2.2.1)
     * Only applies to our plugin admin pages, not WordPress core admin (posts, pages, etc.)
     */
    public function enforce_admin_route_guards() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $current_user = wp_get_current_user();
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $parsed_url = parse_url( $request_uri );
        $path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
        $query_params = isset( $parsed_url['query'] ) ? $parsed_url['query'] : '';
        parse_str( $query_params, $query_vars );

        // Only apply guards to our plugin admin pages (admin.php?page=n88-rfq-*)
        // Allow all WordPress core admin operations (post.php, post-new.php, edit.php, etc.)
        if ( strpos( $path, '/wp-admin/admin.php' ) === false ) {
            return; // Not our plugin admin page, allow it
        }

        // If it's our plugin page, check the page parameter
        if ( ! isset( $query_vars['page'] ) ) {
            return; // No page parameter, allow it
        }

        // Check if user is a designer
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        
        // Check if user is a supplier
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        
        // Check if user is a system operator
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );

        // Designers blocked from our plugin admin pages that aren't their workspace
        if ( $is_designer && ! $is_system_operator ) {
            // Allow access to their workspace board pages
            $allowed_pages = array( 'n88-rfq-dashboard', 'n88-rfq-board-demo', 'n88-rfq-items-boards-test', 'n88-rfq-materials' );
            if ( ! in_array( $query_vars['page'], $allowed_pages, true ) ) {
                // Check if it's a supplier or admin queue page
                if ( strpos( $query_vars['page'], 'supplier' ) !== false || strpos( $query_vars['page'], 'admin-queue' ) !== false ) {
                    self::render_403_error( 'Access Denied', 'You do not have permission to access this page. Creators are restricted from accessing super admin and maker areas.' );
                    exit;
                }
            }
        }

        // Suppliers blocked from global queue scope in our plugin admin
        if ( $is_supplier && ! $is_system_operator ) {
            if ( strpos( $query_vars['page'], 'admin-queue' ) !== false || strpos( $query_vars['page'], 'n88-rfq-role-management' ) !== false ) {
                if ( isset( $query_vars['scope'] ) && $query_vars['scope'] === 'global' ) {
                    self::render_403_error( 'Access Denied', 'You do not have permission to access the global queue. Makers can only access the maker queue.' );
                    exit;
                }
            }
        }
    }

    /**
     * Render designer dashboard
     */
    public function render_designer_dashboard( $atts = array() ) {
        // Check if user is logged in
        if ( ! is_user_logged_in() ) {
            return '<p>Please <a href="' . esc_url( home_url( '/login/' ) ) . '">log in</a> to access the dashboard.</p>';
        }

        $current_user = wp_get_current_user();
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        // Allow designers and system operators
        if ( ! $is_designer && ! $is_system_operator ) {
            return '<p>Access denied. Creator or System Operator account required.</p>';
        }

        $user_id = $current_user->ID;
        $designer_board = $this->get_designer_board( $user_id );
        $has_items = $this->designer_has_items( $user_id );
        $has_board_and_items = $designer_board && $has_items;

        ob_start();
        ?>
        <div class="n88-designer-dashboard" style="max-width: 600px; margin: 50px auto; padding: 20px;">
            <div style="text-align: center;">
                
                <div style="margin-bottom: 20px;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=n88-rfq-dashboard' ) ); ?>" 
                       class="button button-primary button-large" 
                       style="padding: 15px 30px; font-size: 16px; text-decoration: none; display: inline-block;">
                        Create My Workspace
                    </a>
                </div>

                <?php if ( $has_board_and_items ) : ?>
                    <div style="margin-top: 20px;">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=n88-rfq-board-demo&board_id=' . $designer_board->id ) ); ?>" 
                           class="button button-secondary button-large" 
                           style="padding: 15px 30px; font-size: 16px; text-decoration: none; display: inline-block;">
                            Go to My Workspace
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render workspace page for designers (Commit 2.2.1)
     */
    public function render_workspace( $atts = array() ) {
        // Allow admins to edit pages even if they don't have designer role
        if ( is_admin() && current_user_can( 'edit_pages' ) ) {
            return '<p><em>Workspace page - This shortcode will redirect designers to their workspace.</em></p>';
        }

        // Check if user is logged in
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url( '/login/' ) );
            exit;
        }

        $current_user = wp_get_current_user();
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        // Allow designers and system operators
        if ( ! $is_designer && ! $is_system_operator ) {
            wp_die( 'Access denied. Creator or System Operator account required.', 'Access Denied', array( 'response' => 403 ) );
        }

        // Show workspace page (no automatic redirect to board)
        return $this->render_designer_dashboard( $atts );
    }

    /**
     * Determine action badge for a route item (M2)
     * Returns: 'submit_bid', 'continue_draft', 'specs_changed', 'submitted', or 'expired'
     */
    private function determine_action_badge( $item_data, $route_status, $item_current_revision, $has_revision_column = true ) {
        // M6: If expired, return expired (read-only)
        if ( $route_status === 'expired' ) {
            return 'expired';
        }
        
        // Check if route is actionable
        if ( ! in_array( $route_status, array( 'queued', 'sent', 'viewed', 'bid_submitted' ), true ) ) {
            return 'expired'; // Treat non-actionable as expired
        }
        
        // Check for specs changed (M2.3)
        $has_stale_bid = false;
        $has_current_draft = false;
        $has_current_submitted = false;
        
        if ( ! empty( $item_data['bids'] ) ) {
            // If revision column doesn't exist, treat any submitted/draft bid as "current"
            // (since we can't determine if it's stale or not)
            if ( ! $has_revision_column || $item_current_revision === null ) {
                foreach ( $item_data['bids'] as $bid ) {
                    if ( $bid['status'] === 'draft' ) {
                        $has_current_draft = true;
                        break; // Draft takes priority
                    } elseif ( $bid['status'] === 'submitted' ) {
                        $has_current_submitted = true;
                        // Don't break - check for draft first
                    }
                }
            } else {
                // Revision column exists - use revision-based logic
                foreach ( $item_data['bids'] as $bid ) {
                    $bid_revision = isset( $bid['revision'] ) ? intval( $bid['revision'] ) : null;
                    
                    // Check for current revision bid FIRST (priority)
                    if ( $bid_revision === $item_current_revision ) {
                        if ( $bid['status'] === 'draft' ) {
                            $has_current_draft = true;
                        } elseif ( $bid['status'] === 'submitted' ) {
                            $has_current_submitted = true;
                        }
                    }
                    
                    // Check for stale bid (old revision OR NULL revision - when dims/qty changed, revision is set to NULL)
                    // Only if we don't already have a current revision bid
                    if ( ! $has_current_submitted && ! $has_current_draft ) {
                        if ( $bid_revision === null || ( $bid_revision !== null && $bid_revision < $item_current_revision ) ) {
                            if ( $bid['status'] === 'submitted' || $bid['status'] === 'draft' ) {
                                $has_stale_bid = true;
                            }
                        }
                    }
                }
            }
        }
        
        // Priority 1: M2.2: Continue Draft (draft exists for current revision)
        if ( $has_current_draft ) {
            return 'continue_draft';
        }
        
        // Priority 2: M2.1: Submitted (submitted bid exists for current revision)
        if ( $has_current_submitted ) {
            return 'submitted';
        }
        
        // Priority 3: M2.3: Specs Changed - Update Bid (stale bid exists but no current revision bid)
        // Only show resubmit when designer actually changed specs (stale bid exists)
        if ( $has_stale_bid ) {
            return 'specs_changed';
        }
        
        // Default: Submit Bid (no bids at all)
        return 'submit_bid';
    }
    
    /**
     * Calculate time remaining for a route (for system_invited routes)
     * Returns formatted string like "18h" or "2d 4h" or null if not applicable
     */
    private function calculate_time_remaining( $route_type, $eligible_after ) {
        // Only show for system_invited routes
        if ( $route_type !== 'system_invited' || ! $eligible_after ) {
            return null;
        }
        
        $now = current_time( 'timestamp' );
        $eligible_timestamp = strtotime( $eligible_after );
        
        if ( ! $eligible_timestamp || $eligible_timestamp <= $now ) {
            return null; // Already eligible or invalid
        }
        
        $diff_seconds = $eligible_timestamp - $now;
        $diff_hours = floor( $diff_seconds / 3600 );
        $diff_days = floor( $diff_hours / 24 );
        $remaining_hours = $diff_hours % 24;
        
        if ( $diff_days > 0 ) {
            return $diff_days . 'd ' . $remaining_hours . 'h';
        } else {
            return $diff_hours . 'h';
        }
    }
    
    /**
     * Parse time remaining string to hours for filtering
     */
    private function parse_time_remaining_hours( $time_str ) {
        if ( ! $time_str ) {
            return null;
        }
        
        $hours = 0;
        if ( preg_match( '/(\d+)d/', $time_str, $days_match ) ) {
            $hours += intval( $days_match[1] ) * 24;
        }
        if ( preg_match( '/(\d+)h/', $time_str, $hours_match ) ) {
            $hours += intval( $hours_match[1] );
        }
        
        return $hours > 0 ? $hours : null;
    }
    
    /**
     * Commit 2.3.7.1: Check and update expired routes (lazy evaluation)
     * 
     * For each system_invited or designer_invited route:
     * - If eligible_after IS NOT NULL
     * - AND NOW() > eligible_after + INTERVAL (N88_SYSTEM_INVITED_EXPIRY_SECONDS)
     * - OR if eligible_after IS NULL AND routed_at IS NOT NULL
     * - AND NOW() > routed_at + INTERVAL (N88_SYSTEM_INVITED_EXPIRY_SECONDS)
     * - AND status IN ('queued','sent','viewed')
     * - Then set status = 'expired'
     * 
     * @param int|null $item_id Optional item_id to check specific item, or null to check all
     * @param int|null $supplier_id Optional supplier_id to check specific supplier, or null to check all
     * @return int Number of routes updated to expired
     */
    private function check_and_update_expired_routes( $item_id = null, $supplier_id = null ) {
        global $wpdb;
        
        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
        
        // Build WHERE clause - include both system_invited and designer_invited routes
        $where_conditions = array( "route_type IN ('system_invited', 'designer_invited')" );
        $where_conditions[] = "status IN ('queued', 'sent', 'viewed')";
        
        // Calculate expiry timestamp
        // Route expires if: NOW() > eligible_after + INTERVAL (expiry_seconds) OR NOW() > routed_at + INTERVAL (expiry_seconds)
        // For routes with eligible_after (delayed routes): use eligible_after + expiry_seconds (expiry starts when route becomes eligible)
        // For routes without eligible_after (immediate routes): use routed_at + expiry_seconds (expiry starts when route is created)
        $expiry_seconds = self::N88_SYSTEM_INVITED_EXPIRY_SECONDS;
        
        // Handle both cases: routes with eligible_after and routes without (using routed_at)
        // Priority: If eligible_after exists, use it; otherwise use routed_at
        $expiry_condition = $wpdb->prepare(
            "(eligible_after IS NOT NULL AND DATE_ADD(eligible_after, INTERVAL %d SECOND) < NOW()) OR (eligible_after IS NULL AND routed_at IS NOT NULL AND DATE_ADD(routed_at, INTERVAL %d SECOND) < NOW())",
            $expiry_seconds,
            $expiry_seconds
        );
        $where_conditions[] = $expiry_condition;
        
        if ( $item_id !== null ) {
            $where_conditions[] = $wpdb->prepare( "item_id = %d", $item_id );
        }
        
        if ( $supplier_id !== null ) {
            $where_conditions[] = $wpdb->prepare( "supplier_id = %d", $supplier_id );
        }
        
        $where_clause = implode( ' AND ', $where_conditions );
        
        // Update expired routes
        $updated = $wpdb->query(
            "UPDATE {$rfq_routes_table} 
            SET status = 'expired' 
            WHERE {$where_clause}"
        );
        
        if ( $updated > 0 ) {
            error_log( sprintf( 
                'N88 RFQ: Updated %d route(s) (system_invited/designer_invited) to expired status (expiry window: %d seconds)',
                $updated,
                $expiry_seconds
            ) );
        }
        
        return $updated !== false ? intval( $updated ) : 0;
    }
    
    /**
     * Commit 2.3.7.1: Check if a route is expired
     * 
     * @param int $item_id Item ID
     * @param int $supplier_id Supplier ID
     * @return bool True if route is expired, false otherwise
     */
    private function is_route_expired( $item_id, $supplier_id ) {
        global $wpdb;
        
        // First, check and update expired routes (lazy evaluation)
        $this->check_and_update_expired_routes( $item_id, $supplier_id );
        
        // Then check if this specific route is expired
        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
        $route = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, route_type, eligible_after 
            FROM {$rfq_routes_table} 
            WHERE item_id = %d AND supplier_id = %d",
            $item_id,
            $supplier_id
        ) );
        
        if ( ! $route ) {
            return false; // Route doesn't exist
        }
        
        // Both system_invited and designer_invited routes can expire
        if ( ! in_array( $route->route_type, array( 'system_invited', 'designer_invited' ), true ) ) {
            return false;
        }
        
        return $route->status === 'expired';
    }
    
    /**
     * Render supplier queue page (M) - Redesigned to match wireframe
     */
    public function render_supplier_queue( $atts = array() ) {
        // Allow admins to edit pages even if they don't have supplier role
        if ( is_admin() && current_user_can( 'edit_pages' ) ) {
            return '<p><em>Maker Queue page - This shortcode will display the maker queue for authorized users.</em></p>';
        }

        // Check if user is logged in and is a supplier or system operator
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url( '/login/' ) );
            exit;
        }

        $current_user = wp_get_current_user();
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        if ( ! $is_supplier && ! $is_system_operator ) {
            wp_die( 'Access denied. Maker or System Operator account required.', 'Access Denied', array( 'response' => 403 ) );
        }

        // Read filter values from URL query parameters (M5)
        $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'needs_action';
        $time_remaining_filter = isset( $_GET['time_remaining'] ) ? sanitize_text_field( wp_unslash( $_GET['time_remaining'] ) ) : 'all';
        $category_filter = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : 'all';
        $search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

        // Pagination: 6 items per page
        $items_per_page = 6;
        $current_page = isset( $_GET['page'] ) ? max( 1, intval( $_GET['page'] ) ) : 1;

        // M3: Fetch routed items with proper eligibility rules
                        global $wpdb;
                        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
                        $items_table = $wpdb->prefix . 'n88_items';
                        $categories_table = $wpdb->prefix . 'n88_categories';
                        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
                        
        // Commit 2.3.7.1: Check and update expired routes (system_invited and designer_invited) (lazy evaluation)
        $this->check_and_update_expired_routes( null, $current_user->ID );
                        
        // M3: Build query with proper status filtering (include expired when needed)
        // M3.3: Exclude cancelled/closed routes
        $status_conditions = array();
        $status_conditions[] = "r.status NOT IN ('cancelled', 'closed')";
        
        // Commit 2.3.7.1: Always exclude expired routes by default (unless explicitly filtering for expired)
        if ( $status_filter === 'expired' ) {
            $status_conditions[] = "r.status = 'expired'";
        } else {
            // For all other filters (including 'all'), exclude expired routes
            // Expired routes should only appear when explicitly filtering for 'expired'
            $status_conditions[] = "r.status != 'expired'";
        }
        
        $status_where = ! empty( $status_conditions ) ? 'AND ' . implode( ' AND ', $status_conditions ) : '';
        
        // Check if rfq_revision_at_submit column exists in bids table
        $bids_columns = $wpdb->get_col( "DESCRIBE {$item_bids_table}" );
        $has_revision_column = in_array( 'rfq_revision_at_submit', $bids_columns, true );
        
        // Get routed items with eligible_after for time calculation
                        $routed_items_base = $wpdb->get_results( $wpdb->prepare(
                            "SELECT DISTINCT
                                r.item_id,
                                r.status as route_status,
                                r.route_type,
                r.eligible_after,
                                i.id,
                                i.title,
                                i.item_type,
                                i.status as item_status,
                                i.meta_json,
                i.primary_image_id,
                                c.name as category_name
                            FROM {$rfq_routes_table} r
                            INNER JOIN {$items_table} i ON r.item_id = i.id
                            LEFT JOIN {$categories_table} c ON i.item_type = c.category_id OR i.item_type = c.name
                            WHERE r.supplier_id = %d
                            AND i.deleted_at IS NULL
            {$status_where}
                            ORDER BY r.route_id DESC",
                            $current_user->ID
                        ), ARRAY_A );
                        
        // Get all bids for these items
                        $routed_items = array();
                        foreach ( $routed_items_base as $item ) {
                            $item_id = intval( $item['id'] );
                            
                            // Get all bids for this item and supplier
                            // Only include revision column if it exists
                            $bid_select_fields = "status";
                            if ( $has_revision_column ) {
                                $bid_select_fields .= ", rfq_revision_at_submit";
                            }
                            
                            $bids = $wpdb->get_results( $wpdb->prepare(
                                "SELECT {$bid_select_fields}
                                FROM {$item_bids_table}
                                WHERE item_id = %d
                                AND supplier_id = %d",
                                $item_id,
                                $current_user->ID
                            ), ARRAY_A );
                            
                            // Add bids to item data
                            $item['bids'] = $bids;
                            $routed_items[] = $item;
                        }
                        
        // Process items and determine action badges
                            $items_by_id = array();
                            foreach ( $routed_items as $item ) {
                                $item_id = intval( $item['id'] );
                                
                                // Convert bids array to expected format
                                $bids_formatted = array();
                                if ( ! empty( $item['bids'] ) && is_array( $item['bids'] ) ) {
                                    foreach ( $item['bids'] as $bid ) {
                                        $bids_formatted[] = array(
                                            'status' => $bid['status'],
                                            'revision' => isset( $bid['rfq_revision_at_submit'] ) ? $bid['rfq_revision_at_submit'] : null,
                                        );
                                    }
                                }
                                
            $item_meta = ! empty( $item['meta_json'] ) ? json_decode( $item['meta_json'], true ) : array();
            $item_current_revision = isset( $item_meta['rfq_revision_current'] ) ? intval( $item_meta['rfq_revision_current'] ) : 1;
            
            $item_data = array(
                                    'id' => $item_id,
                                    'title' => $item['title'],
                                    'item_type' => $item['item_type'],
                                    'category_name' => $item['category_name'],
                                    'route_status' => $item['route_status'],
                'route_type' => $item['route_type'],
                'eligible_after' => $item['eligible_after'],
                                    'meta_json' => $item['meta_json'],
                'primary_image_id' => $item['primary_image_id'],
                                    'bids' => $bids_formatted,
                'item_current_revision' => $item_current_revision,
            );
            
            // Determine action badge (pass has_revision_column flag)
            $action_badge = $this->determine_action_badge( $item_data, $item['route_status'], $item_current_revision, $has_revision_column );
            $item_data['action_badge'] = $action_badge;
            
            // Calculate time remaining
            $time_remaining = $this->calculate_time_remaining( $item['route_type'], $item['eligible_after'] );
            $item_data['time_remaining'] = $time_remaining;
            
            $items_by_id[ $item_id ] = $item_data;
        }
        
        // Apply filters (M5)
        $filtered_items = array();
        foreach ( $items_by_id as $item_id => $item_data ) {
            // Status filter
            $passes_status = false;
            if ( $status_filter === 'all' ) {
                $passes_status = true;
            } elseif ( $status_filter === 'needs_action' ) {
                $passes_status = in_array( $item_data['action_badge'], array( 'submit_bid', 'continue_draft', 'specs_changed' ), true );
            } elseif ( $status_filter === 'draft_saved' ) {
                $passes_status = $item_data['action_badge'] === 'continue_draft';
            } elseif ( $status_filter === 'submitted' ) {
                $passes_status = $item_data['action_badge'] === 'submitted';
            } elseif ( $status_filter === 'expired' ) {
                $passes_status = $item_data['action_badge'] === 'expired';
            }
            
            if ( ! $passes_status ) {
                continue;
            }
            
            // Time remaining filter
            if ( $time_remaining_filter !== 'all' && $item_data['time_remaining'] ) {
                $hours_remaining = $this->parse_time_remaining_hours( $item_data['time_remaining'] );
                if ( $time_remaining_filter === 'due_soon' && $hours_remaining > 24 ) {
                    continue;
                } elseif ( $time_remaining_filter === 'active' && ( $hours_remaining <= 24 || $hours_remaining > 72 ) ) {
                    continue;
                } elseif ( $time_remaining_filter === 'later' && $hours_remaining <= 72 ) {
                    continue;
                }
            }
            
            // Category filter
            if ( $category_filter !== 'all' ) {
                $category_name_lower = strtolower( $item_data['category_name'] ?: '' );
                if ( $category_name_lower !== strtolower( $category_filter ) ) {
                    continue;
                }
            }
            
            // Search filter
            if ( ! empty( $search ) ) {
                $search_lower = strtolower( $search );
                $item_title_lower = strtolower( $item_data['title'] ?: '' );
                $item_id_str = (string) $item_id;
                if ( strpos( $item_title_lower, $search_lower ) === false && strpos( $item_id_str, $search ) === false ) {
                    continue;
                }
            }
            
            $filtered_items[ $item_id ] = $item_data;
        }
        
        // Pagination: Calculate total pages and slice items
        $total_items = count( $filtered_items );
        $total_pages = ceil( $total_items / $items_per_page );
        $offset = ( $current_page - 1 ) * $items_per_page;
        
        // Slice items for current page (preserve array keys for item IDs)
        $paginated_items = array_slice( $filtered_items, $offset, $items_per_page, true );
        
        // Get all categories for dropdown
        $all_categories = $wpdb->get_results( "SELECT DISTINCT name FROM {$categories_table} WHERE name IS NOT NULL ORDER BY name", ARRAY_A );
        
        ob_start();
        ?>
        <!-- Supplier Queue - Dark Theme Terminal Style (M) -->
        <div class="n88-supplier-queue" style="background-color: #000; color: #fff; font-family: 'Courier New', Courier, monospace; min-height: 100vh; padding: 20px;">
            <!-- Header Section -->
            <div style="border: 2px dashed #fff; padding: 15px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h1 style="margin: 0; font-size: 18px; font-weight: normal; color: #fff; font-family: 'Courier New', Courier, monospace;">Supplier Queue — RFQs Requiring Action</h1>
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span style="font-size: 14px; color: #fff;">Logged in as: <?php echo esc_html( strtolower( $current_user->display_name ) ); ?></span>
                        <a href="<?php echo esc_url( wp_logout_url( home_url( '/login/' ) ) ); ?>" style="padding: 4px 8px; border: 1px solid #fff; color: #fff; text-decoration: none; font-size: 12px; font-family: 'Courier New', Courier, monospace;" onmouseover="this.style.backgroundColor='#333';" onmouseout="this.style.backgroundColor='transparent';">[ Logout ]</a>
                    </div>
                </div>
            </div>
            
            <!-- Filters Section -->
            <div style="border: 2px dashed #fff; padding: 15px; margin-bottom: 20px;">
                <div style="font-size: 14px; font-weight: bold; margin-bottom: 10px; color: #fff; font-family: 'Courier New', Courier, monospace;">FILTERS</div>
                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center;">
                    <div>
                        <label style="font-size: 12px; color: #fff; margin-right: 5px;">Status:</label>
                        <select id="n88-supplier-status" style="padding: 4px 8px; background-color: #000; color: #fff; border: 1px solid #fff; font-family: 'Courier New', Courier, monospace; font-size: 12px; cursor: pointer;">
                            <option value="needs_action" <?php selected( $status_filter, 'needs_action' ); ?>>Needs Action</option>
                            <option value="draft_saved" <?php selected( $status_filter, 'draft_saved' ); ?>>Draft Saved</option>
                            <option value="submitted" <?php selected( $status_filter, 'submitted' ); ?>>Submitted</option>
                            <option value="expired" <?php selected( $status_filter, 'expired' ); ?>>Expired</option>
                            <option value="all" <?php selected( $status_filter, 'all' ); ?>>All</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 12px; color: #fff; margin-right: 5px;">Time Remaining:</label>
                        <select id="n88-supplier-time-remaining" style="padding: 4px 8px; background-color: #000; color: #fff; border: 1px solid #fff; font-family: 'Courier New', Courier, monospace; font-size: 12px; cursor: pointer;">
                            <option value="all" <?php selected( $time_remaining_filter, 'all' ); ?>>All</option>
                            <option value="due_soon" <?php selected( $time_remaining_filter, 'due_soon' ); ?>>Due Soon (≤24h)</option>
                            <option value="active" <?php selected( $time_remaining_filter, 'active' ); ?>>Active (24–72h)</option>
                            <option value="later" <?php selected( $time_remaining_filter, 'later' ); ?>>Later (>72h)</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 12px; color: #fff; margin-right: 5px;">Category:</label>
                        <select id="n88-supplier-category" style="padding: 4px 8px; background-color: #000; color: #fff; border: 1px solid #fff; font-family: 'Courier New', Courier, monospace; font-size: 12px; cursor: pointer;">
                            <option value="all" <?php selected( $category_filter, 'all' ); ?>>All</option>
                            <?php foreach ( $all_categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( $cat['name'] ); ?>" <?php selected( $category_filter, $cat['name'] ); ?>><?php echo esc_html( $cat['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <label style="font-size: 12px; color: #fff; margin-right: 5px;">Search:</label>
                        <input type="text" id="n88-supplier-search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search item label / Item #" style="padding: 4px 8px; background-color: #000; color: #fff; border: 1px solid #fff; font-family: 'Courier New', Courier, monospace; font-size: 12px; width: 100%; max-width: 300px;">
                    </div>
                </div>
                <div style="margin-top: 10px; font-size: 11px; color: #ccc; font-style: italic;">
                    Needs Action = Submit Bid | Continue Draft | Specs Changed – Update Bid
                </div>
            </div>
            
            <!-- Routed Items Section -->
            <div style="border: 2px dashed #fff; padding: 15px; margin-bottom: 20px;">
                <div style="font-size: 14px; font-weight: bold; margin-bottom: 10px; color: #fff; font-family: 'Courier New', Courier, monospace;">ROUTED ITEMS (ANONYMOUS RFQs)</div>
                <div style="font-size: 11px; color: #ccc; margin-bottom: 5px;">Supplier sees ONLY items routed to them</div>
                <div style="font-size: 11px; color: #ccc;">No designer identity shown anywhere</div>
            </div>
            
            <!-- Items Table -->
            <div style="border: 2px solid #fff; overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-family: 'Courier New', Courier, monospace;">
                    <thead>
                        <tr style="border-bottom: 2px solid #fff;">
                            <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: bold; color: #fff; border-right: 1px solid #fff;">Thumbnail</th>
                            <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: bold; color: #fff; border-right: 1px solid #fff;">Item Label</th>
                            <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: bold; color: #fff; border-right: 1px solid #fff;">Category</th>
                            <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: bold; color: #fff;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $paginated_items ) ) : ?>
                            <tr>
                                <td colspan="4" style="padding: 20px; text-align: center; color: #999; font-size: 12px;">No items found matching the selected filters.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $paginated_items as $item_id => $item_data ) : 
                                $item_title = $item_data['title'] ?: 'Item #' . $item_id;
                                $category = $item_data['category_name'] ?: $item_data['item_type'] ?: 'Uncategorized';
                                
                                // Get thumbnail
                                $thumbnail_url = '';
                                if ( ! empty( $item_data['primary_image_id'] ) ) {
                                    $thumbnail_url = wp_get_attachment_image_url( $item_data['primary_image_id'], 'thumbnail' );
                                    if ( ! $thumbnail_url ) {
                                        $thumbnail_url = wp_get_attachment_url( $item_data['primary_image_id'] );
                                    }
                                }
                                
                                // Determine action button text and badge
                                $action_button_text = '';
                                $action_badge_text = '';
                                $is_expired = $item_data['action_badge'] === 'expired';
                                
                                switch ( $item_data['action_badge'] ) {
                                    case 'submit_bid':
                                        $action_button_text = 'Submit Bid ►';
                                        $action_badge_text = 'Needs Action';
                                        break;
                                    case 'continue_draft':
                                        $action_button_text = 'Continue Draft ►';
                                        $action_badge_text = 'Draft Saved';
                                        break;
                                    case 'specs_changed':
                                        $action_button_text = 'Update Bid ►';
                                        $action_badge_text = 'Specs Changed';
                                        break;
                                    case 'submitted':
                                        $action_button_text = 'View Bid ►';
                                        $action_badge_text = 'Submitted';
                                        break;
                                    case 'expired':
                                        $action_button_text = 'Expired';
                                        $action_badge_text = 'Expired';
                                        break;
                                }
                            ?>
                                <tr style="border-bottom: 1px dashed #666;">
                                    <td style="padding: 12px; border-right: 1px solid #fff;">
                                        <?php if ( $thumbnail_url ) : ?>
                                            <img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="Thumbnail" style="width: 60px; height: 60px; object-fit: cover; border: 1px solid #fff;">
                                        <?php else : ?>
                                            <div style="width: 60px; height: 60px; border: 1px solid #fff; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #999;">[ img ]</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px; border-right: 1px solid #fff; font-size: 12px; color: #fff;">
                                        <?php echo esc_html( $item_title ); ?> (Item #<?php echo esc_html( $item_id ); ?>)
                                    </td>
                                    <td style="padding: 12px; border-right: 1px solid #fff; font-size: 12px; color: #fff;">
                                        <?php echo esc_html( $category ); ?>
                                    </td>
                                    <td style="padding: 12px; font-size: 12px;">
                                        <?php if ( ! $is_expired ) : ?>
                                            <button class="n88-open-bid-modal" 
                                                    data-item-id="<?php echo esc_attr( $item_id ); ?>" 
                                                    data-item-title="<?php echo esc_attr( $item_title ); ?>" 
                                                    data-category="<?php echo esc_attr( $category ); ?>"
                                                    data-action-badge="<?php echo esc_attr( $item_data['action_badge'] ); ?>"
                                                    style="padding: 4px 8px; background-color: #000; color: #fff; border: 1px solid #fff; font-family: 'Courier New', Courier, monospace; font-size: 11px; cursor: pointer; margin-right: 10px;" 
                                                    onmouseover="this.style.backgroundColor='#333';" 
                                                    onmouseout="this.style.backgroundColor='#000';">
                                                [ <?php echo esc_html( $action_button_text ); ?> ]
                                        </button>
                                        <?php else : ?>
                                            <span style="color: #999; font-size: 11px;">[ <?php echo esc_html( $action_button_text ); ?> ]</span>
                                        <?php endif; ?>
                                        <span style="color: #fff; font-size: 11px; margin-left: 5px;">Badge: <?php echo esc_html( $action_badge_text ); ?></span>
                                        <?php if ( $item_data['action_badge'] === 'specs_changed' ) : ?>
                                            <div style="color: #ff0; font-size: 10px; margin-top: 3px;">Revision mismatch</div>
                                        <?php endif; ?>
                                        <?php if ( $item_data['time_remaining'] ) : ?>
                                            <div style="color: #fff; font-size: 11px; margin-top: 3px;">Expires in: <?php echo esc_html( $item_data['time_remaining'] ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination Controls -->
            <?php if ( $total_pages > 1 ) : ?>
                <div style="border: 2px dashed #fff; padding: 15px; margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-size: 12px; color: #fff;">
                        Showing <?php echo esc_html( $offset + 1 ); ?>-<?php echo esc_html( min( $offset + $items_per_page, $total_items ) ); ?> of <?php echo esc_html( $total_items ); ?> items
            </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                                <?php
                        // Build pagination URLs preserving all query parameters
                        $query_params = array();
                        if ( $status_filter !== 'needs_action' ) {
                            $query_params['status'] = $status_filter;
                        }
                        if ( $time_remaining_filter !== 'all' ) {
                            $query_params['time_remaining'] = $time_remaining_filter;
                        }
                        if ( $category_filter !== 'all' ) {
                            $query_params['category'] = $category_filter;
                        }
                        if ( ! empty( $search ) ) {
                            $query_params['search'] = $search;
                        }
                        
                        if ( $current_page > 1 ) : 
                            $query_params['page'] = $current_page - 1;
                            $prev_url = add_query_arg( $query_params, home_url( '/supplier/queue' ) );
                        ?>
                            <a href="<?php echo esc_url( $prev_url ); ?>" 
                               style="padding: 4px 8px; border: 1px solid #fff; color: #fff; text-decoration: none; font-size: 12px; font-family: 'Courier New', Courier, monospace;" 
                               onmouseover="this.style.backgroundColor='#333';" 
                               onmouseout="this.style.backgroundColor='transparent';">
                                [ Previous ]
                            </a>
                        <?php else : ?>
                            <span style="padding: 4px 8px; border: 1px solid #666; color: #666; font-size: 12px; font-family: 'Courier New', Courier, monospace; cursor: not-allowed;">[ Previous ]</span>
                        <?php endif; ?>
                        
                        <span style="font-size: 12px; color: #fff; font-family: 'Courier New', Courier, monospace;">
                            Page <?php echo esc_html( $current_page ); ?> of <?php echo esc_html( $total_pages ); ?>
                        </span>
                        
                        <?php if ( $current_page < $total_pages ) : 
                            $query_params['page'] = $current_page + 1;
                            $next_url = add_query_arg( $query_params, home_url( '/supplier/queue' ) );
                        ?>
                            <a href="<?php echo esc_url( $next_url ); ?>" 
                               style="padding: 4px 8px; border: 1px solid #fff; color: #fff; text-decoration: none; font-size: 12px; font-family: 'Courier New', Courier, monospace;" 
                               onmouseover="this.style.backgroundColor='#333';" 
                               onmouseout="this.style.backgroundColor='transparent';">
                                [ Next ]
                            </a>
                        <?php else : ?>
                            <span style="padding: 4px 8px; border: 1px solid #666; color: #666; font-size: 12px; font-family: 'Courier New', Courier, monospace; cursor: not-allowed;">[ Next ]</span>
                        <?php endif; ?>
            </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Supplier RFQ Detail Modal (Commit 2.3.5.2: Dark theme) -->
        <div id="n88-supplier-bid-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.8); z-index: 10000; overflow: hidden;">
            <div id="n88-supplier-bid-modal-content" style="position: fixed; top: 0; right: 0; width: 480px; max-width: 90vw; height: 100vh; background-color: #000 !important; z-index: 10001; display: flex; flex-direction: column; overflow: hidden; border-left: 1px solid #00ff00 !important;">
                <!-- Modal content will be populated by JavaScript -->
            </div>
        </div>
        
        <!-- Supplier Bid Form Modal (Commit 2.3.5.2: Dark theme with green accents) -->
        <div id="n88-supplier-bid-form-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.8); z-index: 10002; overflow: hidden;">
            <div id="n88-supplier-bid-form-modal-content" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 700px; max-width: 95vw; max-height: 95vh; background-color: #000; box-shadow: 0 4px 20px rgba(0,255,0,0.3); z-index: 10003; display: flex; flex-direction: column; overflow: hidden; border: none; border-radius: 4px;">
                <!-- Modal content will be populated by JavaScript -->
            </div>
        </div>
        
        <!-- Supplier Image Lightbox (Commit 2.3.5.1) -->
        <div id="n88-supplier-image-lightbox" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.9); z-index: 10004; overflow: hidden; cursor: pointer;" onclick="closeSupplierImageLightbox(event);">
            <div style="position: absolute; top: 20px; right: 20px; z-index: 10005;">
                <button onclick="closeSupplierImageLightbox(event); event.stopPropagation();" style="background: rgba(255, 255, 255, 0.9); border: none; width: 40px; height: 40px; border-radius: 50%; font-size: 24px; cursor: pointer; color: #333; display: flex; align-items: center; justify-content: center; line-height: 1; box-shadow: 0 2px 8px rgba(0,0,0,0.3);" onmouseover="this.style.background='rgba(255, 255, 255, 1)';" onmouseout="this.style.background='rgba(255, 255, 255, 0.9)';" title="Close">×</button>
            </div>
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: 90vw; max-height: 90vh; display: flex; align-items: center; justify-content: center;" onclick="event.stopPropagation();">
                <img id="n88-supplier-lightbox-image" src="" style="max-width: 90vw; max-height: 90vh; object-fit: contain; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.5);" alt="Enlarged image" />
            </div>
        </div>
        
        <script>
        (function() {
            // Filter persistence via URL query parameters (M5)
            var searchTimeout;
            function updateSupplierQueueURL() {
                var status = document.getElementById('n88-supplier-status')?.value || 'needs_action';
                var timeRemaining = document.getElementById('n88-supplier-time-remaining')?.value || 'all';
                var category = document.getElementById('n88-supplier-category')?.value || 'all';
                var search = document.getElementById('n88-supplier-search')?.value || '';
                
                var params = new URLSearchParams(window.location.search);
                
                if (status && status !== 'needs_action') {
                    params.set('status', status);
                } else {
                    params.delete('status');
                }
                
                if (timeRemaining && timeRemaining !== 'all') {
                    params.set('time_remaining', timeRemaining);
                } else {
                    params.delete('time_remaining');
                }
                
                if (category && category !== 'all') {
                    params.set('category', category);
                } else {
                    params.delete('category');
                }
                
                if (search && search.trim()) {
                    params.set('search', search.trim());
                } else {
                    params.delete('search');
                }
                
                // Reset to page 1 when filters change
                params.delete('page');
                
                // Reload page to apply filters
                window.location.href = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            }
            
            // Attach event listeners to filter elements
            document.addEventListener('DOMContentLoaded', function() {
                var statusSelect = document.getElementById('n88-supplier-status');
                var timeRemainingSelect = document.getElementById('n88-supplier-time-remaining');
                var categorySelect = document.getElementById('n88-supplier-category');
                var searchInput = document.getElementById('n88-supplier-search');
                
                if (statusSelect) {
                    statusSelect.addEventListener('change', updateSupplierQueueURL);
                }
                if (timeRemainingSelect) {
                    timeRemainingSelect.addEventListener('change', updateSupplierQueueURL);
                }
                if (categorySelect) {
                    categorySelect.addEventListener('change', updateSupplierQueueURL);
                }
                if (searchInput) {
                    // Debounce search input
                    searchInput.addEventListener('input', function() {
                        clearTimeout(searchTimeout);
                        searchTimeout = setTimeout(updateSupplierQueueURL, 500);
                    });
                }
                
            });
            
            function openBidModal(itemId) {
                // Ensure lightbox functions are available before creating modal HTML
                if (typeof window.openSupplierImageLightbox !== 'function') {
                    window.openSupplierImageLightbox = openSupplierImageLightbox;
                }
                if (typeof window.closeSupplierImageLightbox !== 'function') {
                    window.closeSupplierImageLightbox = closeSupplierImageLightbox;
                }
                
                var modal = document.getElementById('n88-supplier-bid-modal');
                var modalContent = document.getElementById('n88-supplier-bid-modal-content');
                
                if (!modal || !modalContent) return;
                
                // Show loading state
                modalContent.innerHTML = '<div style="padding: 40px; text-align: center; color: #666;">Loading item details...</div>';
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
                
                // Fetch item details via AJAX (Commit 2.3.2)
                var formData = new FormData();
                formData.append('action', 'n88_get_supplier_item_details');
                formData.append('item_id', itemId);
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_get_supplier_item_details' ); ?>');
                
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        modalContent.innerHTML = '<div style="padding: 40px; text-align: center; color: #d32f2f;">' + 
                            '<p style="margin-bottom: 20px;">' + (data.data && data.data.message ? data.data.message : 'Error loading item details') + '</p>' +
                            '<button onclick="closeBidModal()" style="padding: 8px 16px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Close</button>' +
                            '</div>';
                        return;
                    }
                    
                    var item = data.data;
                    
                    // Format dimensions (Commit 2.3.5.4: Format as W × D × H + unit)
                    var dimsText = '—';
                    if (item.dimensions) {
                        // Support both formats: {w, d, h, unit} and {width, depth, height, unit}
                        var w = item.dimensions.width || item.dimensions.w || '—';
                        var d = item.dimensions.depth || item.dimensions.d || '—';
                        var h = item.dimensions.height || item.dimensions.h || '—';
                        var unit = item.dimensions.unit || '';
                        if (w !== '—' && d !== '—' && h !== '—') {
                            var unitStr = unit === 'in' ? '"' : unit;
                            dimsText = w + unitStr + 'W × ' + d + unitStr + 'D × ' + h + unitStr + 'H';
                        }
                    }
                    
                    // Format keywords (Commit 2.3.5.4: Display keywords or "—")
                    var keywordsText = '—';
                    if (item.keywords && Array.isArray(item.keywords) && item.keywords.length > 0) {
                        keywordsText = item.keywords.join(', ');
                    }
                    
                    // Format sourcing_type and timeline_type
                    var sourcingTypeText = item.sourcing_type || '—';
                    var timelineTypeText = item.timeline_type || '—';
                    
                    // Build reference images HTML - improved with better click handling
                    var refImagesHTML = '';
                    var refImages = item.reference_images || item.inspiration_images || [];
                    if (refImages && refImages.length > 0) {
                        refImagesHTML = '<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 8px;">';
                        refImages.forEach(function(img, index) {
                            // Handle both object format {url, full_url} and string format
                            var imgUrl = '';
                            var fullUrl = '';
                            
                            if (typeof img === 'string') {
                                imgUrl = img;
                                fullUrl = img;
                            } else if (typeof img === 'object') {
                                imgUrl = img.url || img.thumbnail || img.thumb_url || '';
                                fullUrl = img.full_url || img.url || img.thumbnail || img.thumb_url || '';
                            }
                            
                            // Only add image if we have a valid URL
                            if (imgUrl && imgUrl.trim() !== '' && (imgUrl.startsWith('http://') || imgUrl.startsWith('https://'))) {
                                var imgId = 'n88-ref-img-view-' + item.item_id + '-' + index;
                                // Commit 2.3.5.1: Use lightbox instead of opening in new tab
                                var escapedFullUrl = (fullUrl || imgUrl).replace(/'/g, "\\'").replace(/\\/g, '\\\\').replace(/"/g, '&quot;');
                                refImagesHTML += '<div style="position: relative;">' +
                                    '<img id="' + imgId + '" ' +
                                    'src="' + imgUrl.replace(/"/g, '&quot;') + '" ' +
                                    'data-full-url="' + (fullUrl || imgUrl).replace(/"/g, '&quot;') + '" ' +
                                    'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\'%3E%3Crect fill=\'%23f0f0f0\' width=\'100\' height=\'100\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\' font-size=\'12\'%3EImage%3C/text%3E%3C/svg%3E\';" ' +
                                    'style="width: 100px; height: 100px; object-fit: cover; border-radius: 4px; border: 2px solid #e0e0e0; cursor: pointer; transition: all 0.2s; background-color: #f0f0f0;" ' +
                                    'onmouseover="this.style.borderColor=\'#0073aa\'; this.style.transform=\'scale(1.05)\';" ' +
                                    'onmouseout="this.style.borderColor=\'#e0e0e0\'; this.style.transform=\'scale(1)\';" ' +
                                    'onclick="event.preventDefault();event.stopPropagation();(function(elem){var url=elem.getAttribute(\'data-full-url\')||elem.src;if(url&&url.trim()){if(typeof window.openSupplierImageLightbox === \'function\'){window.openSupplierImageLightbox(url);}else{console.error(\'openSupplierImageLightbox not available\');}}else{console.error(\'No URL found for image\');}})(this);return false;" ' +
                                    'title="Click to enlarge" ' +
                                    'alt="Reference image ' + (index + 1) + '" />' +
                                    '</div>';
                            }
                        });
                        refImagesHTML += '</div>';
                        if (refImagesHTML === '<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 8px;"></div>') {
                            refImagesHTML = '<div style="color: #999; font-size: 12px; padding: 8px;">No valid reference images available</div>';
                        }
                    } else {
                        refImagesHTML = '<div style="color: #999; font-size: 12px; padding: 8px;">No reference images available</div>';
                    }
                    
                    // Build media links HTML
                    var mediaLinksHTML = '';
                    if (item.media_links && item.media_links.length > 0) {
                        mediaLinksHTML = '<div style="display: flex; flex-direction: column; gap: 8px;">';
                        item.media_links.forEach(function(link) {
                            var url = link.url || link;
                            var provider = link.provider || 'external';
                            mediaLinksHTML += '<a href="' + url + '" target="_blank" style="color: #0073aa; text-decoration: none; font-size: 13px;">' + 
                                (provider === 'youtube' ? '▶ YouTube' : provider === 'vimeo' ? '▶ Vimeo' : provider === 'loom' ? '▶ Loom' : '▶ Media Link') + 
                                '</a>';
                        });
                        mediaLinksHTML += '</div>';
                    } else {
                        mediaLinksHTML = '<div style="color: #999; font-size: 12px;">No media links available</div>';
                    }
                    
                    // Build reference images and PDFs HTML (Commit 2.3.5.4: Handle PDFs)
                    var refImagesHTMLDark = '';
                    if (refImages && refImages.length > 0) {
                        refImagesHTMLDark = '<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 8px;">';
                        refImages.forEach(function(img, index) {
                            var imgUrl = '';
                            var fullUrl = '';
                            var imgType = 'image';
                            var filename = '';
                            
                            if (typeof img === 'string') {
                                imgUrl = img;
                                fullUrl = img;
                                if (img.toLowerCase().endsWith('.pdf')) {
                                    imgType = 'pdf';
                                    filename = img.split('/').pop();
                                }
                            } else if (typeof img === 'object') {
                                imgUrl = img.url || img.thumbnail || img.thumb_url || '';
                                fullUrl = img.full_url || img.url || img.thumbnail || img.thumb_url || '';
                                imgType = img.type || (imgUrl.toLowerCase().endsWith('.pdf') ? 'pdf' : 'image');
                                filename = img.filename || img.title || (imgUrl ? imgUrl.split('/').pop() : '');
                            }
                            
                            if (imgUrl && imgUrl.trim() !== '' && (imgUrl.startsWith('http://') || imgUrl.startsWith('https://'))) {
                                var imgId = 'n88-ref-img-view-dark-' + item.item_id + '-' + index;
                                var escapedFullUrl = (fullUrl || imgUrl).replace(/'/g, "\\'").replace(/\\/g, '\\\\').replace(/"/g, '&quot;');
                                
                                // Commit 2.3.5.4: Handle PDFs differently - show as [PDF] filename.pdf link
                                if (imgType === 'pdf' || imgUrl.toLowerCase().endsWith('.pdf')) {
                                    refImagesHTMLDark += '<div style="padding: 8px 12px; background-color: #1a1a1a; border: 1px solid #00ff00; border-radius: 2px; cursor: pointer; font-family: monospace; font-size: 11px; color: #00ff00;" ' +
                                        'onclick="window.open(\'' + escapedFullUrl + '\', \'_blank\');" ' +
                                        'onmouseover="this.style.backgroundColor=\'#222\';" ' +
                                        'onmouseout="this.style.backgroundColor=\'#1a1a1a\';" ' +
                                        'title="Click to open PDF">' +
                                        '[PDF] ' + (filename || 'document.pdf') +
                                        '</div>';
                                } else {
                                    // Regular image
                                    refImagesHTMLDark += '<div style="position: relative;">' +
                                        '<img id="' + imgId + '" ' +
                                        'src="' + imgUrl.replace(/"/g, '&quot;') + '" ' +
                                        'data-full-url="' + (fullUrl || imgUrl).replace(/"/g, '&quot;') + '" ' +
                                        'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\'%3E%3Crect fill=\'%23000\' width=\'100\' height=\'100\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23fff\' font-size=\'12\'%3EImage%3C/text%3E%3C/svg%3E\';" ' +
                                        'style="width: 100px; height: 100px; object-fit: cover; border-radius: 2px; border: 2px solid #00ff00; cursor: pointer; transition: all 0.2s; background-color: #1a1a1a;" ' +
                                        'onmouseover="this.style.borderColor=\'#00ff00\'; this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 2px 8px rgba(0,255,0,0.5)\';" ' +
                                        'onmouseout="this.style.borderColor=\'#00ff00\'; this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\';" ' +
                                        'onclick="event.preventDefault();event.stopPropagation();(function(elem){var url=elem.getAttribute(\'data-full-url\')||elem.src;if(url&&url.trim()){if(typeof window.openSupplierImageLightbox === \'function\'){window.openSupplierImageLightbox(url);}else{console.error(\'openSupplierImageLightbox not available\');}}else{console.error(\'No URL found for image\');}})(this);return false;" ' +
                                        'title="Click to enlarge" ' +
                                        'alt="Reference image ' + (index + 1) + '" />' +
                                        '</div>';
                                }
                            }
                        });
                        refImagesHTMLDark += '</div>';
                        if (refImagesHTMLDark === '<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 8px;"></div>') {
                            refImagesHTMLDark = '<div style="color: #999; font-size: 12px; padding: 8px; font-family: monospace;">No valid reference images available</div>';
                        }
                    } else {
                        refImagesHTMLDark = '<div style="color: #999; font-size: 12px; padding: 8px; font-family: monospace;">No reference images available</div>';
                    }
                    
                    // Build modal HTML - Commit 2.3.5.4: Clean header (item title only)
                    var modalHTML = '<div style="padding: 16px 20px; border-bottom: 1px solid #00ff00; background-color: #000; display: flex; justify-content: space-between; align-items: center;">' +
                        '<h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #fff; font-family: monospace;">' + (item.title || 'Untitled Item') + '</h2>' +
                        '<button onclick="closeBidModal()" style="background: none; border: none; font-size: 18px; cursor: pointer; padding: 4px 8px; color: #00ff00; font-family: monospace; line-height: 1;">[x Close]</button>' +
                        '</div>' +
                        '<div style="flex: 1; overflow-y: auto; padding: 0; background-color: #000;">' +
                        '<div style="padding: 20px; font-family: monospace;">' +
                        
                        // Item Image
                        (item.image_url || item.primary_image_url ? (function() {
                            var imgUrl = (item.primary_image_url || item.image_url).replace(/'/g, "\\'").replace(/\\/g, '\\\\').replace(/"/g, '&quot;');
                            return '<div style="margin-bottom: 16px; text-align: center;">' +
                                '<img src="' + (item.primary_image_url || item.image_url) + '" onclick="event.preventDefault();event.stopPropagation();if(typeof window.openSupplierImageLightbox === \'function\'){window.openSupplierImageLightbox(\'' + imgUrl + '\');}return false;" style="max-width: 100%; max-height: 250px; width: auto; height: auto; border-radius: 2px; border: 2px solid #00ff00; object-fit: contain; cursor: pointer; transition: all 0.2s; background-color: #1a1a1a; box-shadow: 0 2px 8px rgba(0,255,0,0.3);" onmouseover="this.style.opacity=\'0.9\'; this.style.borderColor=\'#00ff00\'; this.style.boxShadow=\'0 4px 12px rgba(0,255,0,0.5)\';" onmouseout="this.style.opacity=\'1\'; this.style.borderColor=\'#00ff00\'; this.style.boxShadow=\'0 2px 8px rgba(0,255,0,0.3)\';" title="Click to enlarge" />' +
                                '</div>';
                        })() : '') +
                        
                        // Commit 2.3.5.4: Inspiration/Reference Images + PDFs (only at top, no duplicates)
                        (refImages && refImages.length > 0 ? 
                            '<div style="margin-bottom: 24px;">' +
                            '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #fff; font-family: monospace;">Inspiration / References / Sketch Drawings:</label>' +
                            refImagesHTMLDark +
                            '</div>' : ''
                        ) +
                        
                        // Warning Banner
                        (item.show_dims_qty_warning ? '<div style="padding: 12px; background-color: #1a1a1a; border: 1px solid #ffc107; border-radius: 2px; font-size: 12px; color: #ffc107; margin-bottom: 16px; font-weight: 500; font-family: monospace;">' +
                            '⚠️ Dims/Qty changed after you submitted your bid. Your bid reflects the previous specs.' +
                            '</div>' : '') +
                        
                        // Commit 2.3.5.4: Item Context Block (exact order as specified)
                        '<div style="padding: 16px; background-color: #1a1a1a; border-radius: 2px; border: 1px solid #00ff00; margin-bottom: 24px; font-family: monospace;">' +
                        '<div style="font-size: 12px; font-weight: 600; color: #00ff00; margin-bottom: 12px; text-transform: uppercase;">Item</div>' +
                        '<div style="font-size: 14px; color: #fff; line-height: 1.8;">' +
                        // 1. Item:
                        '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Item:</strong> <span style="color: #fff;">' + (item.title || '—') + '</span></div>' +
                        // 2. Category:
                        '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Category:</strong> <span style="color: #fff;">' + (item.category || '—') + '</span></div>' +
                        // 3. Dims:
                        '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Dims:</strong> <span style="color: #fff;">' + dimsText + '</span></div>' +
                        // 4. Quantity:
                        '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Quantity:</strong> <span style="color: #fff;">' + (item.quantity || '—') + '</span></div>' +
                        // 5. Routing:
                        (item.route_label ? '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Routing:</strong> <span style="color: #00ff00;">' + item.route_label + '</span></div>' : '') +
                        // 6. Delivery: (Commit 2.3.5.4: Country only, no postal/zip, no shipping estimate)
                        '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Delivery:</strong> <span style="color: #fff;">' + (item.delivery_country || '—') + '</span></div>' +
                        // 7. Description:
                        '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #00ff00;"><strong style="color: #00ff00;">Description:</strong></div>' +
                        '<div style="margin-top: 8px; color: #fff; white-space: pre-wrap; font-size: 13px;">' + (item.description || '—') + '</div>' +
                        // 8. Designer notes: (Commit 2.3.5.4: smart_alternatives_note)
                        (item.smart_alternatives_note && item.smart_alternatives_note.trim() ? 
                            '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #00ff00;"><strong style="color: #00ff00;">Designer notes:</strong></div>' +
                            '<div style="margin-top: 8px; color: #fff; white-space: pre-wrap; font-size: 13px;">' + item.smart_alternatives_note.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>' :
                            '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #00ff00;"><strong style="color: #00ff00;">Designer notes:</strong> <span style="color: #fff;">—</span></div>'
                        ) +
                        '</div>' +
                        '</div>' +
                        
                        (item.route_label ? '<div style="margin-top: -16px; margin-bottom: 16px; font-size: 11px; color: #999; font-style: italic; font-family: monospace; padding-left: 16px;">Creator identity remains hidden until award.</div>' : '') +
                        
                        // Bid Details Box (only shown when bid is submitted)
                        (item.bid_status === 'submitted' && item.bid_data ? (function() {
                            var bid = item.bid_data;
                            var videoLinksHTML = '';
                            if (bid.video_links && bid.video_links.length > 0) {
                                bid.video_links.forEach(function(link, index) {
                                    videoLinksHTML += '<div style="margin-bottom: 4px;"><a href="' + link.replace(/"/g, '&quot;') + '" target="_blank" style="color: #00ff00; text-decoration: none;">' + link + '</a></div>';
                                });
                            } else {
                                videoLinksHTML = '<span style="color: #999;">None</span>';
                            }
                            
                            var bidPhotosHTML = '';
                            if (bid.bid_photos && bid.bid_photos.length > 0) {
                                bidPhotosHTML = '<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px;">';
                                bid.bid_photos.forEach(function(photoUrl) {
                                    bidPhotosHTML += '<img src="' + photoUrl.replace(/"/g, '&quot;') + '" style="width: 80px; height: 80px; object-fit: cover; border-radius: 2px; border: none; cursor: pointer;" onclick="if(typeof window.openSupplierImageLightbox === \'function\'){window.openSupplierImageLightbox(\'' + photoUrl.replace(/'/g, "\\'").replace(/\\/g, '\\\\').replace(/"/g, '&quot;') + '\');}" title="Click to enlarge" />';
                                });
                                bidPhotosHTML += '</div>';
                            } else {
                                bidPhotosHTML = '<span style="color: #999;">None</span>';
                            }
                            
                            // Build Smart Alternatives HTML if present
                            var smartAltHTML = '';
                            // Debug: Log bid data to see what we have
                            console.log('Bid data for Smart Alt check:', {
                                has_smart_alt: !!bid.smart_alternatives_suggestion,
                                smart_alt_type: typeof bid.smart_alternatives_suggestion,
                                smart_alt_value: bid.smart_alternatives_suggestion
                            });
                            
                            // Handle both string and object formats
                            var smartAltData = bid.smart_alternatives_suggestion;
                            if (!smartAltData) {
                                console.log('No smart_alternatives_suggestion in bid data');
                            } else {
                                if (typeof smartAltData === 'string') {
                                    try {
                                        smartAltData = JSON.parse(smartAltData);
                                        console.log('Parsed Smart Alt from string:', smartAltData);
                                    } catch(e) {
                                        console.error('Failed to parse Smart Alt JSON:', e);
                                        smartAltData = null;
                                    }
                                }
                                
                                if (smartAltData && typeof smartAltData === 'object' && smartAltData !== null) {
                                    console.log('Processing Smart Alt data:', smartAltData);
                                    // Check if ANY field has data (not just category)
                                    var hasCategory = smartAltData.category && String(smartAltData.category).trim() !== '';
                                    var hasFrom = smartAltData.from && String(smartAltData.from).trim() !== '';
                                    var hasTo = smartAltData.to && String(smartAltData.to).trim() !== '';
                                    var hasPrice = smartAltData.price_impact && String(smartAltData.price_impact).trim() !== '';
                                    var hasLeadTime = smartAltData.lead_time_impact && String(smartAltData.lead_time_impact).trim() !== '';
                                    var hasComparisons = smartAltData.comparison_points && Array.isArray(smartAltData.comparison_points) && smartAltData.comparison_points.length > 0;
                                    
                                    console.log('Smart Alt field checks:', {
                                        hasCategory: hasCategory,
                                        hasFrom: hasFrom,
                                        hasTo: hasTo,
                                        hasPrice: hasPrice,
                                        hasLeadTime: hasLeadTime,
                                        hasComparisons: hasComparisons
                                    });
                                    
                                    // Always show if we have any data, even if all checks fail (fallback)
                                    var shouldShow = hasCategory || hasFrom || hasTo || hasPrice || hasLeadTime || hasComparisons || Object.keys(smartAltData).length > 0;
                                    
                                    if (shouldShow) {
                                        console.log('Displaying Smart Alt HTML');
                                        var smartAlt = smartAltData;
                                        var categoryLabels = {
                                            'material': 'Material',
                                            'finish': 'Finish',
                                            'hardware': 'Hardware',
                                            'dimensions': 'Dimensions',
                                            'construction': 'Construction Method',
                                            'packaging': 'Packaging'
                                        };
                                        var fromLabels = {
                                            'solid-wood': 'Solid Wood', 'plywood': 'Plywood', 'mdf': 'MDF',
                                            'metal': 'Metal', 'plastic': 'Plastic', 'glass': 'Glass',
                                            'fabric': 'Fabric', 'leather': 'Leather', 'other': 'Other'
                                        };
                                        var toLabels = fromLabels;
                                        var comparisonLabels = {
                                            'cost-reduction': 'Cost Reduction',
                                            'faster-production': 'Faster Production',
                                            'better-durability': 'Better Durability',
                                            'easier-sourcing': 'Easier Sourcing',
                                            'lighter-weight': 'Lighter Weight',
                                            'eco-friendly': 'Eco-Friendly'
                                        };
                                        var priceLabels = {
                                            'reduces-10-20': 'Reduces 10-20%',
                                            'reduces-20-30': 'Reduces 20-30%',
                                            'reduces-30-plus': 'Reduces 30%+',
                                            'similar': 'Similar Price',
                                            'increases-10-20': 'Increases 10-20%',
                                            'increases-20-plus': 'Increases 20%+'
                                        };
                                        var leadTimeLabels = {
                                            'reduces-1-2w': 'Reduces 1-2 weeks',
                                            'reduces-2-4w': 'Reduces 2-4 weeks',
                                            'reduces-4w-plus': 'Reduces 4+ weeks',
                                            'similar': 'Similar Lead Time',
                                            'increases-1-2w': 'Increases 1-2 weeks',
                                            'increases-2w-plus': 'Increases 2+ weeks'
                                        };
                                        
                                        smartAltHTML = '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #00ff00;">' +
                                            '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Smart Alternative Suggestion:</strong></div>' +
                                            '<div style="padding-left: 12px; font-size: 13px; color: #fff; line-height: 1.6;">' +
                                            (hasCategory ? '<div style="margin-bottom: 4px;"><strong style="color: #00ff00;">Category:</strong> <span style="color: #fff;">' + (categoryLabels[smartAlt.category] || smartAlt.category) + '</span></div>' : '') +
                                            (hasFrom && hasTo ? '<div style="margin-bottom: 4px;"><strong style="color: #00ff00;">From:</strong> <span style="color: #fff;">' + (fromLabels[smartAlt.from] || smartAlt.from) + '</span> <strong style="color: #00ff00;">To:</strong> <span style="color: #fff;">' + (toLabels[smartAlt.to] || smartAlt.to) + '</span></div>' : '') +
                                            (hasComparisons ? '<div style="margin-bottom: 4px;"><strong style="color: #00ff00;">Comparison Points:</strong> <span style="color: #fff;">' + smartAlt.comparison_points.map(function(cp) { return comparisonLabels[cp] || cp; }).join(', ') + '</span></div>' : '') +
                                            (hasPrice ? '<div style="margin-bottom: 4px;"><strong style="color: #00ff00;">Price Impact:</strong> <span style="color: #fff;">' + (priceLabels[smartAlt.price_impact] || smartAlt.price_impact) + '</span></div>' : '') +
                                            (hasLeadTime ? '<div style="margin-bottom: 4px;"><strong style="color: #00ff00;">Lead Time Impact:</strong> <span style="color: #fff;">' + (leadTimeLabels[smartAlt.lead_time_impact] || smartAlt.lead_time_impact) + '</span></div>' : '') +
                                            '</div>' +
                                            '</div>';
                                    }
                                }
                            }
                            
                            return '<div style="padding: 16px; background-color: #1a1a1a; border-radius: 2px; border: 1px solid #00ff00; margin-bottom: 24px; font-family: monospace;">' +
                                '<div style="font-size: 16px; font-weight: 600; color: #00ff00; margin-bottom: 16px; border-bottom: 1px solid #00ff00; padding-bottom: 8px;">Your Submitted Bid</div>' +
                                '<div style="font-size: 14px; color: #fff; line-height: 1.8;">' +
                                '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Video Links:</strong> <div style="margin-top: 4px;">' + videoLinksHTML + '</div></div>' +
                                '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Bid Photos:</strong> <div style="margin-top: 4px;">' + bidPhotosHTML + '</div></div>' +
                                '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Prototype:</strong> <span style="color: #fff;">' + (bid.prototype_video_yes ? 'YES' : 'NO') + '</span></div>' +
                                (bid.prototype_timeline ? '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Prototype Timeline:</strong> <span style="color: #fff;">' + bid.prototype_timeline + '</span></div>' : '') +
                                (bid.prototype_cost !== null && bid.prototype_cost !== undefined ? '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Prototype Cost:</strong> <span style="color: #fff;">$' + parseFloat(bid.prototype_cost).toFixed(2) + '</span></div>' : '') +
                                (bid.production_lead_time ? '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Production Lead Time:</strong> <span style="color: #fff;">' + bid.production_lead_time + '</span></div>' : '') +
                                (bid.unit_price !== null && bid.unit_price !== undefined ? '<div style="margin-bottom: 8px;"><strong style="color: #00ff00;">Unit Price:</strong> <span style="color: #00ff00; font-weight: 600;">$' + parseFloat(bid.unit_price).toFixed(2) + '</span></div>' : '') +
                                smartAltHTML +
                            '</div>' +
                                '</div>';
                        })() : '') +
                        
                        '</div>' +
                        // Footer - Start Bid / Continue Bid / Withdraw Bid / Resubmit Bid button
                        '<div style="padding: 20px; border-top: 1px solid #00ff00; background-color: #000; display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">' +
                        (item.bid_status === 'submitted' && !item.has_revision_mismatch ? 
                            // Normal submitted state - check if resubmission
                            '<div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">' +
                            '<div style="padding: 12px 24px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; font-size: 14px; font-weight: 600; font-family: monospace;">✓ ' + (item.is_resubmission ? 'Bid Already Resubmitted' : 'Bid Already Submitted') + '</div>' +
                            '<button onclick="withdrawBid(' + item.item_id + ')" style="padding: 12px 24px; background-color: #dc3545; color: #fff; border: none; border-radius: 2px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: monospace;">Withdraw Bid</button>' +
                            '</div>' :
                            (item.bid_status === 'submitted' && item.has_revision_mismatch ?
                                // Specs changed - show resubmit button (opens form inside modal)
                                '<button onclick="toggleBidForm(' + item.item_id + ')" id="n88-resubmit-bid-btn-' + item.item_id + '" style="padding: 12px 24px; background-color: #ff9800; color: #000; border: none; border-radius: 2px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: monospace;">[ Resubmit Bid ]</button>' :
                                (item.bid_status === 'draft' && item.bid_status !== null && item.bid_status !== undefined ?
                                    // Draft exists - show continue bid button (only when valid draft is saved)
                                    '<button onclick="toggleBidForm(' + item.item_id + ')" id="n88-toggle-bid-form-btn-' + item.item_id + '" style="padding: 12px 24px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: monospace;">[ Continue Bid ]</button>' :
                                    // No bid yet or no valid draft - show start bid button (default)
                                    '<button onclick="toggleBidForm(' + item.item_id + ')" id="n88-toggle-bid-form-btn-' + item.item_id + '" style="padding: 12px 24px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: monospace;">[ Start Bid ]</button>'
                                )
                            )
                        ) +
                        '</div>' +
                        // Expandable Bid Form Section (hidden by default)
                        '<div id="n88-bid-form-section-' + item.item_id + '" style="display: none; padding: 20px; background-color: #000; border-top: 1px solid #00ff00;">' +
                        '<div id="n88-bid-form-content-' + item.item_id + '"></div>' +
                        '</div>';
                    
                    modalContent.innerHTML = modalHTML;
                    
                    // Commit 2.3.5.4: Ensure bid form visible without scrolling - scroll to top on modal open
                    setTimeout(function() {
                        var modalScrollContainer = modalContent.querySelector('div[style*="overflow-y: auto"]');
                        if (modalScrollContainer) {
                            modalScrollContainer.scrollTop = 0;
                        }
                    }, 100);
                    
                    // Store item data for inline bid form
                    window.currentItemData = item;
                })
                .catch(function(error) {
                    modalContent.innerHTML = '<div style="padding: 40px; text-align: center; color: #d32f2f;">' +
                        '<p style="margin-bottom: 20px;">Error loading item details. Please try again.</p>' +
                        '<button onclick="closeBidModal()" style="padding: 8px 16px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Close</button>' +
                        '</div>';
                });
            }
            
            function closeBidModal() {
                var modal = document.getElementById('n88-supplier-bid-modal');
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }
            
            // Toggle bid form section inside the modal
            function toggleBidForm(itemId) {
                var formSection = document.getElementById('n88-bid-form-section-' + itemId);
                var formContent = document.getElementById('n88-bid-form-content-' + itemId);
                var toggleBtn = document.getElementById('n88-toggle-bid-form-btn-' + itemId);
                var resubmitBtn = document.getElementById('n88-resubmit-bid-btn-' + itemId);
                var specsBanner = document.getElementById('n88-specs-changed-banner');
                var bidForm = document.getElementById('n88-bid-form');
                
                if (!formSection || !formContent) return;
                
                if (formSection.style.display === 'none' || !formSection.style.display) {
                    // Show form section
                    formSection.style.display = 'block';
                    if (toggleBtn) {
                        toggleBtn.textContent = '[ Hide Bid Form ]';
                        toggleBtn.style.backgroundColor = '#1a1a1a';
                        toggleBtn.style.color = '#00ff00';
                        toggleBtn.style.borderColor = '#00ff00';
                    }
                    if (resubmitBtn) {
                        resubmitBtn.textContent = '[ Hide Bid Form ]';
                        resubmitBtn.style.backgroundColor = '#1a1a1a';
                        resubmitBtn.style.color = '#00ff00';
                    }
                    
                    // Hide specs changed banner and show form when resubmitting
                    if (specsBanner) {
                        specsBanner.style.display = 'none';
                    }
                    if (bidForm) {
                        bidForm.style.display = 'block';
                    }
                    
                    // Commit 2.3.5.4: Ensure bid form visible without scrolling - scroll to top of form section
                    formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    
                    // Load bid form content if not already loaded
                    if (!formContent.innerHTML || formContent.innerHTML.trim() === '') {
                        loadBidFormContent(itemId, formContent);
                    }
                } else {
                    // Hide form section
                    formSection.style.display = 'none';
                    if (toggleBtn) {
                        // Check if there's a valid draft bid to show "Continue Bid" instead
                        // Only show "Continue Bid" when bid_status is explicitly 'draft' (not null or undefined)
                        var item = window.currentItemData;
                        var hasDraft = item && item.bid_status === 'draft' && item.bid_status !== null && item.bid_status !== undefined;
                        toggleBtn.textContent = hasDraft ? '[ Continue Bid ]' : '[ Start Bid ]';
                        toggleBtn.style.backgroundColor = '#1a1a1a';
                        toggleBtn.style.color = '#00ff00';
                        toggleBtn.style.borderColor = '#00ff00';
                    }
                    if (resubmitBtn) {
                        resubmitBtn.textContent = '[ Resubmit Bid ]';
                        resubmitBtn.style.backgroundColor = '#ff9800';
                        resubmitBtn.style.color = '#000';
                    }
                }
            }
            
            // Load bid form content into the embedded section
            function loadBidFormContent(itemId, container) {
                container.innerHTML = '<div style="padding: 20px; text-align: center; color: #fff; font-family: monospace;">Loading bid form...</div>';
                
                // Fetch item details
                var formData = new FormData();
                formData.append('action', 'n88_get_supplier_item_details');
                formData.append('item_id', itemId);
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_get_supplier_item_details' ); ?>');
                
                // Add timeout to prevent hanging
                var timeoutPromise = new Promise(function(resolve, reject) {
                    setTimeout(function() {
                        reject(new Error('Request timeout'));
                    }, 30000); // 30 second timeout
                });
                
                Promise.race([
                    fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    }),
                    timeoutPromise
                ])
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    // Check if response is JSON
                    var contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        // If not JSON, try to get text to see what we got
                        return response.text().then(function(text) {
                            throw new Error('Response is not JSON. Got: ' + text.substring(0, 100));
                        });
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (!data || !data.success) {
                        container.innerHTML = '<div style="padding: 20px; text-align: center; color: #ff0000; font-family: monospace;">Error loading item details: ' + (data && data.data && data.data.message ? data.data.message : 'Unknown error') + '</div>';
                        return;
                    }
                    
                    var item = data.data;
                    var bidFormHTML = buildEmbeddedBidFormHTML(item, itemId);
                    container.innerHTML = bidFormHTML;
                    
                    // Initialize form validation first
                    setTimeout(function() {
                        validateBidFormEmbedded(itemId);
                    }, 100);
                    
                    // Load draft or stale bid data if available (for resubmission when specs changed)
                    setTimeout(function() {
                        // First try to load from stale bid data if has_revision_mismatch
                        if (item.has_revision_mismatch && (item.latest_stale_bid_data || item.bid_data)) {
                            var staleBidData = item.latest_stale_bid_data || item.bid_data;
                            var form = document.getElementById('n88-bid-form-embedded-' + itemId);
                            if (form && staleBidData) {
                                restoreBidDataToFormEmbedded(form, staleBidData, itemId);
                            }
                        } else {
                            // Otherwise load from draft
                            loadBidDraft(itemId);
                        }
                    }, 200);
                })
                .catch(function(error) {
                    console.error('Error loading bid form:', error);
                    // Check if error is due to JSON parsing (504 timeout returns HTML)
                    if (error.message && error.message.includes('JSON')) {
                        container.innerHTML = '<div style="padding: 20px; text-align: center; color: #ff0000; font-family: monospace;">Server timeout. Please refresh and try again.</div>';
                    } else {
                        container.innerHTML = '<div style="padding: 20px; text-align: center; color: #ff0000; font-family: monospace;">Network error. Please try again.</div>';
                    }
                });
            }
            
            // Build embedded bid form HTML (adapted from openBidFormModalInternal)
            function buildEmbeddedBidFormHTML(item, itemId) {
                // Get primary image URL
                var primaryImageUrl = item.primary_image_url || item.image_url || '';
                
                // Get inspiration images
                var inspirationImages = item.inspiration_images || item.reference_images || [];
                
                // Filter and prepare valid reference images
                var validReferenceImages = [];
                if (inspirationImages && inspirationImages.length > 0) {
                    inspirationImages.forEach(function(img) {
                        var imgUrl = '';
                        var fullUrl = '';
                        
                        if (typeof img === 'string') {
                            imgUrl = img;
                            fullUrl = img;
                        } else if (typeof img === 'object') {
                            imgUrl = img.url || img.thumbnail || img.thumb_url || '';
                            fullUrl = img.full_url || img.url || img.thumbnail || img.thumb_url || '';
                        }
                        
                        if (imgUrl && imgUrl.trim() !== '' && (imgUrl.startsWith('http://') || imgUrl.startsWith('https://'))) {
                            validReferenceImages.push({
                                url: imgUrl,
                                fullUrl: fullUrl || imgUrl
                            });
                        }
                    });
                }
                
                // Build image gallery layout
                var imageGalleryHTML = '';
                if (primaryImageUrl || validReferenceImages.length > 0) {
                    var leftImages = [];
                    var rightImages = [];
                    validReferenceImages.forEach(function(img, index) {
                        if (index % 2 === 0) {
                            leftImages.push(img);
                        } else {
                            rightImages.push(img);
                        }
                    });
                    
                    var leftColumnHTML = '<div style="display: flex; flex-direction: column; gap: 12px; align-items: center; justify-content: center; min-width: 120px;">';
                    leftImages.forEach(function(img, index) {
                        var imgId = 'n88-ref-left-embedded-' + itemId + '-' + index;
                        leftColumnHTML += '<div style="position: relative; width: 100px; height: 100px;">' +
                            '<img id="' + imgId + '" ' +
                            'src="' + img.url.replace(/"/g, '&quot;') + '" ' +
                            'data-full-url="' + img.fullUrl.replace(/"/g, '&quot;') + '" ' +
                            'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\'%3E%3Crect fill=\'%23000\' width=\'100\' height=\'100\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23fff\' font-size=\'12\'%3Ereference photo%3C/text%3E%3C/svg%3E\';" ' +
                            'style="width: 100px; height: 100px; object-fit: cover; border-radius: 2px; border: 2px solid #00ff00; cursor: pointer; transition: all 0.2s; background-color: #1a1a1a;" ' +
                            'onmouseover="this.style.borderColor=\'#00ff00\'; this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 2px 8px rgba(0,255,0,0.5)\';" ' +
                            'onmouseout="this.style.borderColor=\'#00ff00\'; this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\';" ' +
                            'onclick="(function(elem){var url=elem.getAttribute(\'data-full-url\')||elem.src;if(url&&url.trim()){openSupplierImageLightbox(url);}else{console.error(\'No URL found for image\');}})(this);" ' +
                            'title="Click to view full size" ' +
                            'alt="Reference photo" />' +
                            '</div>';
                    });
                    leftColumnHTML += '</div>';
                    
                    var centerColumnHTML = '<div style="flex: 1; display: flex; align-items: center; justify-content: center; min-height: 300px; padding: 0 20px; max-width: 500px;">';
                    if (primaryImageUrl) {
                        centerColumnHTML += '<img src="' + primaryImageUrl.replace(/"/g, '&quot;') + '" ' +
                            'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'400\' height=\'300\'%3E%3Crect fill=\'%23f0f0f0\' width=\'400\' height=\'300\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\' font-size=\'14\'%3EItem Image%3C/text%3E%3C/svg%3E\';" ' +
                            'style="max-width: 100%; max-height: 350px; width: auto; height: auto; border-radius: 2px; border: 2px solid #00ff00; object-fit: contain; box-shadow: 0 2px 8px rgba(0,255,0,0.3); cursor: pointer; transition: all 0.2s; background-color: #1a1a1a;" ' +
                            'onclick="event.preventDefault();event.stopPropagation();if(typeof window.openSupplierImageLightbox === \'function\'){window.openSupplierImageLightbox(\'' + primaryImageUrl.replace(/'/g, "\\'").replace(/\\/g, '\\\\').replace(/"/g, '&quot;') + '\');}return false;" ' +
                            'onmouseover="this.style.opacity=\'0.9\'; this.style.borderColor=\'#00ff00\'; this.style.boxShadow=\'0 4px 12px rgba(0,255,0,0.5)\';" ' +
                            'onmouseout="this.style.opacity=\'1\'; this.style.borderColor=\'#00ff00\'; this.style.boxShadow=\'0 2px 8px rgba(0,255,0,0.3)\';" ' +
                            'title="Click to enlarge" ' +
                            'alt="Item main image" />';
                    } else {
                        centerColumnHTML += '<div style="width: 100%; height: 300px; background-color: #1a1a1a; border-radius: 2px; border: none; display: flex; align-items: center; justify-content: center; color: #00ff00; font-family: monospace; font-size: 12px;">No main image available</div>';
                    }
                    centerColumnHTML += '</div>';
                    
                    var rightColumnHTML = '<div style="display: flex; flex-direction: column; gap: 12px; align-items: center; justify-content: center; min-width: 120px;">';
                    rightImages.forEach(function(img, index) {
                        var imgId = 'n88-ref-right-embedded-' + itemId + '-' + index;
                        rightColumnHTML += '<div style="position: relative; width: 100px; height: 100px;">' +
                            '<img id="' + imgId + '" ' +
                            'src="' + img.url.replace(/"/g, '&quot;') + '" ' +
                            'data-full-url="' + img.fullUrl.replace(/"/g, '&quot;') + '" ' +
                            'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\'%3E%3Crect fill=\'%23000\' width=\'100\' height=\'100\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23fff\' font-size=\'12\'%3Ereference photo%3C/text%3E%3C/svg%3E\';" ' +
                            'style="width: 100px; height: 100px; object-fit: cover; border-radius: 2px; border: 2px solid #00ff00; cursor: pointer; transition: all 0.2s; background-color: #1a1a1a;" ' +
                            'onmouseover="this.style.borderColor=\'#00ff00\'; this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 2px 8px rgba(0,255,0,0.5)\';" ' +
                            'onmouseout="this.style.borderColor=\'#00ff00\'; this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\';" ' +
                            'onclick="(function(elem){var url=elem.getAttribute(\'data-full-url\')||elem.src;if(url&&url.trim()){openSupplierImageLightbox(url);}else{console.error(\'No URL found for image\');}})(this);" ' +
                            'title="Click to view full size" ' +
                            'alt="Reference photo" />' +
                            '</div>';
                    });
                    rightColumnHTML += '</div>';
                    
                    imageGalleryHTML = '<div style="margin-bottom: 24px; display: flex; gap: 16px; align-items: flex-start; justify-content: center; padding: 16px; background-color: #1a1a1a; border-radius: 4px; border: none;">' +
                        leftColumnHTML +
                        centerColumnHTML +
                        rightColumnHTML +
                        '</div>';
                }
                
                // Commit 2.3.5.4: Build bid form HTML - Remove images (shown only at top), remove Anonymous/No contact text
                var bidFormHTML = '<form id="n88-bid-form-embedded-' + itemId + '" style="font-family: monospace;" onsubmit="return validateAndSubmitBidEmbedded(event, ' + itemId + ');">' +
                    // Commit 2.3.5.4: Images removed from bid form (only shown at top of modal)
                    
                    // BID FORM Title (Commit 2.3.5.4: Remove "Anonymous • No contact info allowed")
                    '<div style="margin-bottom: 24px; padding: 12px 0; border-bottom: 1px solid #00ff00;">' +
                    '<h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #00ff00; font-family: monospace;">BID FORM</h2>' +
                        '</div>' +
                        
                    // 1. Video links (0-3, optional) - Commit 2.3.5.4: Field order 1
                        '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #fff; font-family: monospace;">VIDEO LINKS (Optional)</label>' +
                    '<div style="font-size: 11px; color: #fff; margin-bottom: 8px; font-family: monospace;">Min 0, Max 3. Allowed: YouTube / Vimeo / Loom</div>' +
                    '<div id="n88-video-links-container-embedded-' + itemId + '">' +
                    '<div style="margin-bottom: 8px; display: flex; gap: 8px; align-items: center;">' +
                    '<span style="color: #00ff00; font-family: monospace; font-size: 12px;">1)</span>' +
                    '<input type="url" name="video_links[]" class="n88-video-link-input-embedded" placeholder="https://youtube.com/watch?v=..." style="flex: 1; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; font-family: monospace;" onblur="validateVideoLinkEmbedded(this, ' + itemId + ');" oninput="validateBidFormEmbedded(' + itemId + ');" />' +
                    '<button type="button" onclick="removeVideoLinkEmbedded(this, ' + itemId + ')" style="padding: 8px 12px; background-color: #dc3545; color: #fff; border: none; border-radius: 2px; cursor: pointer; display: none; font-family: monospace; font-size: 11px;">Remove</button>' +
                        '</div>' +
                        '</div>' +
                    '<button type="button" onclick="addVideoLinkEmbedded(' + itemId + ')" id="n88-add-video-link-btn-embedded-' + itemId + '" style="margin-top: 8px; padding: 6px 12px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; cursor: pointer; font-size: 11px; font-family: monospace;">+ Add Another Link</button>' +
                    '<div id="n88-video-links-error-embedded-' + itemId + '" style="margin-top: 6px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                        
                    // 2. Reference photo(s) (required, min 1, max 5) - Commit 2.3.5.4: Renamed from "BID PHOTOS" to "Reference photo(s)"
                        '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #fff; font-family: monospace;">Reference photo(s) <span style="color: #ff0000;">*</span></label>' +
                    '<div style="font-size: 11px; color: #fff; margin-bottom: 8px; font-family: monospace;">Upload photos of similar items or your work. Minimum 1 photo required (recommended 1-5).</div>' +
                    '<input type="file" id="n88-bid-photos-input-embedded-' + itemId + '" name="bid_photos[]" accept="image/*" multiple style="display: none;" onchange="handleBidPhotosChangeEmbedded(this, ' + itemId + ');" />' +
                    '<button type="button" onclick="document.getElementById(\'n88-bid-photos-input-embedded-' + itemId + '\').click();" style="padding: 8px 16px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; cursor: pointer; font-size: 12px; margin-bottom: 12px; font-family: monospace;">+ Add Photos</button>' +
                    '<div id="n88-bid-photos-preview-embedded-' + itemId + '" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;"></div>' +
                    '<div id="n88-bid-photos-error-embedded-' + itemId + '" style="margin-top: 6px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                        
                    // 3. Prototype (Yes/No or required commitment) - Commit 2.3.5.4: Field order 3
                        '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #fff; font-family: monospace;">PROTOTYPE (Required)</label>' +
                    '<div style="font-size: 11px; color: #fff; margin-bottom: 8px; font-family: monospace;">Will you prepare and video a prototype?</div>' +
                    '<div style="display: flex; gap: 16px; margin-bottom: 8px;">' +
                    '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">' +
                    '<input type="radio" name="prototype_video_yes" value="1" required style="width: 16px; height: 16px; cursor: pointer; accent-color: #00ff00;" onchange="validateBidFormEmbedded(' + itemId + ');" />' +
                    '<span style="font-size: 12px; color: #fff; font-family: monospace;">(●) YES</span>' +
                    '</label>' +
                    '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">' +
                    '<input type="radio" name="prototype_video_yes" value="0" style="width: 16px; height: 16px; cursor: pointer; accent-color: #00ff00;" onchange="validateBidFormEmbedded(' + itemId + ');" />' +
                    '<span style="font-size: 12px; color: #fff; font-family: monospace;">() NO</span>' +
                    '</label>' +
                        '</div>' +
                    '<div style="font-size: 10px; color: #00ff00; margin-bottom: 12px; font-family: monospace; font-style: italic;">Helper: YES is required for this platform.</div>' +
                    '<div id="n88-prototype-video-error-embedded-' + itemId + '" style="margin-top: 6px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                    // Prototype timeline - white subheading
                        '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">Prototype timeline (Required):</label>' +
                    '<select name="prototype_timeline_option" required style="width: 100%; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="validateBidFormEmbedded(' + itemId + ');">' +
                    '<option value="">[ Select timeline... ▼ ]</option>' +
                    '<option value="1-2w">1–2w</option>' +
                    '<option value="2-4w">2–4w</option>' +
                    '<option value="4-6w">4–6w</option>' +
                    '<option value="6-8w">6–8w</option>' +
                    '<option value="8-10w">8–10w</option>' +
                    '</select>' +
                    '<div id="n88-prototype-timeline-error-embedded-' + itemId + '" style="margin-top: 4px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                    // Prototype cost - white subheading
                    '<div style="margin-bottom: 0;">' +
                    '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">Prototype cost (Required):</label>' +
                    '<input type="number" name="prototype_cost" step="0.01" min="0" required placeholder="0.00" style="width: 100%; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; font-family: monospace;" oninput="validateBidFormEmbedded(' + itemId + ');" />' +
                    '<div id="n88-prototype-cost-error-embedded-' + itemId + '" style="margin-top: 4px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                        '</div>' +
                        
                    // 6. Production lead time (dropdown) - Commit 2.3.5.4: Field order 6
                        '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #fff; font-family: monospace;">Production lead time (Required) — Dropdown</label>' +
                    '<select name="production_lead_time_text" required style="width: 100%; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="validateBidFormEmbedded(' + itemId + ');">' +
                    '<option value="">[ Select lead time... ▼ ]</option>' +
                    '<option value="2-4 weeks">2–4 weeks</option>' +
                    '<option value="4-6 weeks">4–6 weeks</option>' +
                    '<option value="6-8 weeks">6–8 weeks</option>' +
                    '<option value="8-12 weeks">8–12 weeks</option>' +
                    '<option value="12-16 weeks">12–16 weeks</option>' +
                    '</select>' +
                    '<div id="n88-lead-time-error-embedded-' + itemId + '" style="margin-top: 6px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                        
                    // 7. Unit price - Commit 2.3.5.4: Field order 7
                        '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #fff; font-family: monospace;">Unit price (Required)</label>' +
                    '<input type="number" name="unit_price" step="0.01" min="0.01" required placeholder="0.00" style="width: 100%; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; font-family: monospace;" oninput="validateBidFormEmbedded(' + itemId + ');" />' +
                    '<div id="n88-unit-price-error-embedded-' + itemId + '" style="margin-top: 6px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                            '</div>' +
                    
                    // 8. SMART ALTERNATIVE (DFM) - Commit 2.3.5.5: Remove designer notes from Smart Alternatives (they belong in Item Context)
                    ((item.smart_alternatives_enabled) ? 
                    '<div style="margin-bottom: 24px; padding: 16px; background-color: #1a1a1a; border-radius: 2px; border: none;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #00ff00; font-family: monospace;">SMART ALTERNATIVE (DFM)</label>' +
                    // C1: Display designer's Smart Alternatives setting (read-only) - NO designer notes here
                    '<div style="padding: 12px; background-color: #000; border-radius: 2px; border: 1px solid #00ff00; margin-bottom: 12px;">' +
                    '<div style="font-size: 11px; color: #00ff00; font-family: monospace; margin-bottom: 8px;">' +
                    '<strong>Smart Alternatives:</strong> <span style="color: ' + (item.smart_alternatives_enabled ? '#00ff00' : '#666') + ';">' + (item.smart_alternatives_enabled ? 'Enabled' : 'Disabled') + '</span>' +
                        '</div>' +
                    (item.smart_alternatives_enabled ? '<div style="font-size: 11px; color: #00ff00; font-family: monospace; margin-bottom: 8px;">Creator is open to comparable material/spec alternatives.</div>' : '') +
                        '</div>' +
                    // C2: Supplier can add ONE structured Smart Alternative suggestion (only if enabled)
                    (item.smart_alternatives_enabled ? 
                    '<div style="padding: 12px; background-color: #000; border-radius: 2px; border: 1px solid #00ff00; margin-top: 12px;">' +
                    '<div style="font-size: 11px; color: #00ff00; font-family: monospace; margin-bottom: 12px; font-weight: 600;">Propose Smart Alternative (Optional):</div>' +
                    // Category dropdown
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">Category:</label>' +
                    '<select name="smart_alt_category" id="n88-smart-alt-category-' + itemId + '" style="width: 100%; padding: 6px 10px; border: none; border-radius: 2px; font-size: 11px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="updateSmartAltPreview(' + itemId + ');">' +
                    '<option value="">[ Select category... ]</option>' +
                    '<option value="material">Material</option>' +
                    '<option value="finish">Finish</option>' +
                    '<option value="hardware">Hardware</option>' +
                    '<option value="dimensions">Dimensions</option>' +
                    '<option value="construction">Construction Method</option>' +
                    '<option value="packaging">Packaging</option>' +
                    '</select>' +
                        '</div>' +
                    // From dropdown
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">From:</label>' +
                    '<select name="smart_alt_from" id="n88-smart-alt-from-' + itemId + '" style="width: 100%; padding: 6px 10px; border: none; border-radius: 2px; font-size: 11px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="updateSmartAltPreview(' + itemId + ');">' +
                    '<option value="">[ Select from... ]</option>' +
                    '<option value="solid-wood">Solid Wood</option>' +
                    '<option value="plywood">Plywood</option>' +
                    '<option value="mdf">MDF</option>' +
                    '<option value="metal">Metal</option>' +
                    '<option value="plastic">Plastic</option>' +
                    '<option value="glass">Glass</option>' +
                    '<option value="fabric">Fabric</option>' +
                    '<option value="leather">Leather</option>' +
                    '<option value="other">Other</option>' +
                    '</select>' +
                        '</div>' +
                    // To dropdown
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">To:</label>' +
                    '<select name="smart_alt_to" id="n88-smart-alt-to-' + itemId + '" style="width: 100%; padding: 6px 10px; border: none; border-radius: 2px; font-size: 11px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="updateSmartAltPreview(' + itemId + ');">' +
                    '<option value="">[ Select to... ]</option>' +
                    '<option value="solid-wood">Solid Wood</option>' +
                    '<option value="plywood">Plywood</option>' +
                    '<option value="mdf">MDF</option>' +
                    '<option value="metal">Metal</option>' +
                    '<option value="plastic">Plastic</option>' +
                    '<option value="glass">Glass</option>' +
                    '<option value="fabric">Fabric</option>' +
                    '<option value="leather">Leather</option>' +
                    '<option value="other">Other</option>' +
                    '</select>' +
                    '</div>' +
                    // Comparison points (checkboxes, max 3)
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 11px; margin-bottom: 6px; color: #fff; font-family: monospace;">Comparison Points (max 3):</label>' +
                    '<div style="display: flex; flex-direction: column; gap: 6px;">' +
                    '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="cost-reduction" class="n88-smart-alt-checkbox" onchange="updateSmartAltPreview(' + itemId + ');" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Cost Reduction</span></label>' +
                    '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="faster-production" class="n88-smart-alt-checkbox" onchange="updateSmartAltPreview(' + itemId + ');" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Faster Production</span></label>' +
                    '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="better-durability" class="n88-smart-alt-checkbox" onchange="updateSmartAltPreview(' + itemId + ');" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Better Durability</span></label>' +
                    '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="easier-sourcing" class="n88-smart-alt-checkbox" onchange="updateSmartAltPreview(' + itemId + ');" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Easier Sourcing</span></label>' +
                    '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="lighter-weight" class="n88-smart-alt-checkbox" onchange="updateSmartAltPreview(' + itemId + ');" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Lighter Weight</span></label>' +
                    '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="eco-friendly" class="n88-smart-alt-checkbox" onchange="updateSmartAltPreview(' + itemId + ');" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Eco-Friendly</span></label>' +
                    '</div>' +
                    '</div>' +
                    // Price impact dropdown
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">Price Impact:</label>' +
                    '<select name="smart_alt_price_impact" id="n88-smart-alt-price-' + itemId + '" style="width: 100%; padding: 6px 10px; border: none; border-radius: 2px; font-size: 11px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="updateSmartAltPreview(' + itemId + ');">' +
                    '<option value="">[ Select impact... ]</option>' +
                    '<option value="reduces-10-20">Reduces 10-20%</option>' +
                    '<option value="reduces-20-30">Reduces 20-30%</option>' +
                    '<option value="reduces-30-plus">Reduces 30%+</option>' +
                    '<option value="similar">Similar Price</option>' +
                    '<option value="increases-10-20">Increases 10-20%</option>' +
                    '<option value="increases-20-plus">Increases 20%+</option>' +
                    '</select>' +
                    '</div>' +
                    // Lead time impact dropdown
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">Lead Time Impact:</label>' +
                    '<select name="smart_alt_lead_time_impact" id="n88-smart-alt-leadtime-' + itemId + '" style="width: 100%; padding: 6px 10px; border: none; border-radius: 2px; font-size: 11px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="updateSmartAltPreview(' + itemId + ');">' +
                    '<option value="">[ Select impact... ]</option>' +
                    '<option value="reduces-1-2w">Reduces 1-2 weeks</option>' +
                    '<option value="reduces-2-4w">Reduces 2-4 weeks</option>' +
                    '<option value="reduces-4w-plus">Reduces 4+ weeks</option>' +
                    '<option value="similar">Similar Lead Time</option>' +
                    '<option value="increases-1-2w">Increases 1-2 weeks</option>' +
                    '<option value="increases-2w-plus">Increases 2+ weeks</option>' +
                    '</select>' +
                    '</div>' +
                    // Preview sentence (read-only)
                    '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #00ff00;">' +
                    '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #00ff00; font-family: monospace; font-weight: 600;">Preview:</label>' +
                    '<div id="n88-smart-alt-preview-' + itemId + '" style="padding: 8px; background-color: #1a1a1a; border: none; border-radius: 2px; font-size: 11px; color: #999; font-family: monospace; min-height: 40px; font-style: italic;">Fill in the fields above to generate preview...</div>' +
                    '</div>' +
                    '</div>' : '') +
                    '</div>' : '') +
                    
                    // Footer buttons
                    '<div style="padding: 20px 0; border-top: 1px solid #00ff00; display: flex; justify-content: flex-end; gap: 12px; flex-wrap: wrap;">' +
                    '<button type="button" id="n88-validate-bid-btn-embedded-' + itemId + '" onclick="validateAndSubmitBidEmbedded(event, ' + itemId + ')" disabled style="padding: 10px 20px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; font-size: 12px; font-weight: 600; cursor: not-allowed; font-family: monospace; opacity: 0.5;">[ Validate Bid ]</button>' +
                    // Commit 2.3.5.4: Buttons row - Validate, Cancel, Save for later
                    '<button type="button" id="n88-submit-bid-btn-embedded-' + itemId + '" onclick="submitBidEmbedded(event, ' + itemId + ')" disabled style="display: none; padding: 10px 20px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: monospace;">[ Submit Bid ]</button>' +
                    '<button type="button" onclick="toggleBidForm(' + itemId + ')" style="padding: 10px 20px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; font-size: 12px; cursor: pointer; font-family: monospace;">[ Cancel ]</button>' +
                    '<button type="button" onclick="saveBidDraftEmbedded(' + itemId + ')" style="padding: 10px 20px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; font-size: 12px; cursor: pointer; font-family: monospace;">[ Save for later ]</button>' +
                        '</div>' +
                    '</form>';
                
                return bidFormHTML;
            }
            
            // Commit 2.3.3: Open bid form modal
            function openBidFormModal(itemId) {
                // Check if bid already submitted (Commit 2.3.5)
                var formData = new FormData();
                formData.append('action', 'n88_get_supplier_item_details');
                formData.append('item_id', itemId);
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_get_supplier_item_details' ); ?>');
                
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    // Allow opening form if:
                    // 1. No bid exists yet
                    // 2. Bid is stale (has_revision_mismatch) - dims/qty changed, can resubmit
                    // 3. Bid is draft (can continue/update)
                    // Only block if bid is submitted for current revision (no changes needed)
                    if (data.success && data.data.bid_status === 'submitted' && !data.data.has_revision_mismatch && !data.data.is_resubmission) {
                        alert('You\'ve already submitted a bid for this item.');
                        return;
                    }
                    // Continue with opening modal (allows resubmission when dims/qty changed)
                    openBidFormModalInternal(itemId);
                })
                .catch(function(error) {
                    console.error('Error checking bid status:', error);
                    // Continue with opening modal if check fails
                    openBidFormModalInternal(itemId);
                });
            }
            
            function openBidFormModalInternal(itemId) {
                var modal = document.getElementById('n88-supplier-bid-form-modal');
                var modalContent = document.getElementById('n88-supplier-bid-form-modal-content');
                
                if (!modal || !modalContent) return;
                
                // Store item ID for form submission
                modal.setAttribute('data-item-id', itemId);
                
                // Show loading state
                modalContent.innerHTML = '<div style="padding: 40px; text-align: center; color: #666;">Loading item details...</div>';
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
                
                // Fetch item details to get images
                var formData = new FormData();
                formData.append('action', 'n88_get_supplier_item_details');
                formData.append('item_id', itemId);
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_get_supplier_item_details' ); ?>');
                
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        modalContent.innerHTML = '<div style="padding: 40px; text-align: center; color: #d32f2f;">' + 
                            '<p style="margin-bottom: 20px;">' + (data.data && data.data.message ? data.data.message : 'Error loading item details') + '</p>' +
                            '<button onclick="closeBidFormModal()" style="padding: 8px 16px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Close</button>' +
                            '</div>';
                        return;
                    }
                    
                    var item = data.data;
                    
                    // Format dimensions for item header (Commit 2.3.5.2)
                    var dimsText = '—';
                    if (item.dimensions) {
                        var w = item.dimensions.width || item.dimensions.w || '—';
                        var d = item.dimensions.depth || item.dimensions.d || '—';
                        var h = item.dimensions.height || item.dimensions.h || '—';
                        var unit = item.dimensions.unit || '';
                        if (w !== '—' && d !== '—' && h !== '—') {
                            dimsText = w + '"W × ' + d + '"D × ' + h + '"H';
                            if (unit && unit !== 'in') {
                                dimsText = w + unit + 'W × ' + d + unit + 'D × ' + h + unit + 'H';
                            }
                        }
                    }
                    
                    // Format delivery location
                    var deliveryText = '—';
                    if (item.delivery_country && item.delivery_postal_code) {
                        deliveryText = item.delivery_country + ' ' + item.delivery_postal_code;
                    } else if (item.delivery_country) {
                        deliveryText = item.delivery_country;
                    }
                    
                    // Format item title with category
                    var itemTitleText = (item.title || 'Item #' + itemId);
                    if (item.category) {
                        itemTitleText += ' (' + item.category + ')';
                    }
                    
                    // Get primary image URL (use standardized key, fallback to legacy)
                    var primaryImageUrl = item.primary_image_url || item.image_url || '';
                    
                    // Get inspiration images (use standardized key, fallback to legacy)
                    var inspirationImages = item.inspiration_images || item.reference_images || [];
                    
                    // Filter and prepare valid reference images
                    var validReferenceImages = [];
                    if (inspirationImages && inspirationImages.length > 0) {
                        inspirationImages.forEach(function(img) {
                            var imgUrl = '';
                            var fullUrl = '';
                            
                            if (typeof img === 'string') {
                                imgUrl = img;
                                fullUrl = img;
                            } else if (typeof img === 'object') {
                                imgUrl = img.url || img.thumbnail || img.thumb_url || '';
                                fullUrl = img.full_url || img.url || img.thumbnail || img.thumb_url || '';
                            }
                            
                            // Only add image if we have a valid HTTP/HTTPS URL
                            if (imgUrl && imgUrl.trim() !== '' && (imgUrl.startsWith('http://') || imgUrl.startsWith('https://'))) {
                                validReferenceImages.push({
                                    url: imgUrl,
                                    fullUrl: fullUrl || imgUrl
                                });
                            }
                        });
                    }
                    
                    // Build image gallery layout: left reference images, center main image, right reference images
                    var imageGalleryHTML = '';
                    if (primaryImageUrl || validReferenceImages.length > 0) {
                        // Split reference images into left and right
                        var leftImages = [];
                        var rightImages = [];
                        validReferenceImages.forEach(function(img, index) {
                            if (index % 2 === 0) {
                                leftImages.push(img);
                            } else {
                                rightImages.push(img);
                            }
                        });
                        
                        // Build left column (reference images)
                        var leftColumnHTML = '<div style="display: flex; flex-direction: column; gap: 12px; align-items: center; justify-content: center; min-width: 120px;">';
                        if (leftImages.length > 0) {
                            leftImages.forEach(function(img, index) {
                                var imgId = 'n88-ref-left-' + itemId + '-' + index;
                                leftColumnHTML += '<div style="position: relative; width: 100px; height: 100px;">' +
                                    '<img id="' + imgId + '" ' +
                                    'src="' + img.url.replace(/"/g, '&quot;') + '" ' +
                                    'data-full-url="' + img.fullUrl.replace(/"/g, '&quot;') + '" ' +
                                    'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\'%3E%3Crect fill=\'%23000\' width=\'100\' height=\'100\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23fff\' font-size=\'12\'%3Ereference photo%3C/text%3E%3C/svg%3E\';" ' +
                                    'style="width: 100px; height: 100px; object-fit: cover; border-radius: 2px; border: 2px solid #00ff00; cursor: pointer; transition: all 0.2s; background-color: #1a1a1a;" ' +
                                    'onmouseover="this.style.borderColor=\'#00ff00\'; this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 2px 8px rgba(0,255,0,0.5)\';" ' +
                                    'onmouseout="this.style.borderColor=\'#00ff00\'; this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\';" ' +
                                    'onclick="(function(elem){var url=elem.getAttribute(\'data-full-url\')||elem.src;if(url&&url.trim()){openSupplierImageLightbox(url);}else{console.error(\'No URL found for image\');}})(this);" ' +
                                    'title="Click to view full size" ' +
                                    'alt="Reference photo" />' +
                                    '</div>';
                            });
                        } else {
                            // Placeholder if no left images
                            // leftColumnHTML += '<div style="width: 100px; height: 100px; background-color: #000; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; text-align: center; padding: 4px;">reference photo</div>';
                        }
                        leftColumnHTML += '</div>';
                        
                        // Build center column (main image) - sized appropriately
                        var centerColumnHTML = '<div style="flex: 1; display: flex; align-items: center; justify-content: center; min-height: 300px; padding: 0 20px; max-width: 500px;">';
                        if (primaryImageUrl) {
                            centerColumnHTML += '<img src="' + primaryImageUrl.replace(/"/g, '&quot;') + '" ' +
                                'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'400\' height=\'300\'%3E%3Crect fill=\'%23f0f0f0\' width=\'400\' height=\'300\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\' font-size=\'14\'%3EItem Image%3C/text%3E%3C/svg%3E\';" ' +
                                'style="max-width: 100%; max-height: 350px; width: auto; height: auto; border-radius: 2px; border: 2px solid #00ff00; object-fit: contain; box-shadow: 0 2px 8px rgba(0,255,0,0.3); cursor: pointer; transition: all 0.2s; background-color: #1a1a1a;" ' +
                                'onclick="event.preventDefault();event.stopPropagation();if(typeof window.openSupplierImageLightbox === \'function\'){window.openSupplierImageLightbox(\'' + primaryImageUrl.replace(/'/g, "\\'").replace(/\\/g, '\\\\').replace(/"/g, '&quot;') + '\');}return false;" ' +
                                'onmouseover="this.style.opacity=\'0.9\'; this.style.borderColor=\'#00ff00\'; this.style.boxShadow=\'0 4px 12px rgba(0,255,0,0.5)\';" ' +
                                'onmouseout="this.style.opacity=\'1\'; this.style.borderColor=\'#00ff00\'; this.style.boxShadow=\'0 2px 8px rgba(0,255,0,0.3)\';" ' +
                                'title="Click to enlarge" ' +
                                'alt="Item main image" />';
                        } else {
                            centerColumnHTML += '<div style="width: 100%; height: 300px; background-color: #1a1a1a; border-radius: 2px; border: none; display: flex; align-items: center; justify-content: center; color: #00ff00; font-family: monospace; font-size: 12px;">No main image available</div>';
                        }
                        centerColumnHTML += '</div>';
                        
                        // Build right column (reference images)
                        var rightColumnHTML = '<div style="display: flex; flex-direction: column; gap: 12px; align-items: center; justify-content: center; min-width: 120px;">';
                        if (rightImages.length > 0) {
                            rightImages.forEach(function(img, index) {
                                var imgId = 'n88-ref-right-' + itemId + '-' + index;
                                rightColumnHTML += '<div style="position: relative; width: 100px; height: 100px;">' +
                                    '<img id="' + imgId + '" ' +
                                    'src="' + img.url.replace(/"/g, '&quot;') + '" ' +
                                    'data-full-url="' + img.fullUrl.replace(/"/g, '&quot;') + '" ' +
                                    'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\'%3E%3Crect fill=\'%23000\' width=\'100\' height=\'100\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23fff\' font-size=\'12\'%3Ereference photo%3C/text%3E%3C/svg%3E\';" ' +
                                    'style="width: 100px; height: 100px; object-fit: cover; border-radius: 2px; border: 2px solid #00ff00; cursor: pointer; transition: all 0.2s; background-color: #1a1a1a;" ' +
                                    'onmouseover="this.style.borderColor=\'#00ff00\'; this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 2px 8px rgba(0,255,0,0.5)\';" ' +
                                    'onmouseout="this.style.borderColor=\'#00ff00\'; this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\';" ' +
                                    'onclick="(function(elem){var url=elem.getAttribute(\'data-full-url\')||elem.src;if(url&&url.trim()){openSupplierImageLightbox(url);}else{console.error(\'No URL found for image\');}})(this);" ' +
                                    'title="Click to view full size" ' +
                                    'alt="Reference photo" />' +
                                    '</div>';
                            });
                        } else {
                            // Placeholder if no right images
                            // rightColumnHTML += '<div style="width: 100px; height: 100px; background-color: #000; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; text-align: center; padding: 4px;">reference photo</div>';
                        }
                        rightColumnHTML += '</div>';
                        
                        // Combine into gallery layout (Commit 2.3.5.2: Dark theme)
                        imageGalleryHTML = '<div style="margin-bottom: 24px; display: flex; gap: 16px; align-items: flex-start; justify-content: center; padding: 16px; background-color: #1a1a1a; border-radius: 4px; border: 1px solid #00ff00;">' +
                            leftColumnHTML +
                            centerColumnHTML +
                            rightColumnHTML +
                            '</div>';
                    }
                    
                    // Build bid form modal HTML (Commit 2.3.5.2: Dark theme with green accents - terminal style)
                    var modalHTML = 
                        // Item Header Section (RFQ, Qty, Dims, Delivery)
                        '<div style="padding: 16px 20px; border-bottom: 1px solid #00ff00; background-color: #000; display: flex; justify-content: space-between; align-items: center;">' +
                        '<div style="flex: 1;">' +
                        '<div style="font-size: 12px; color: #00ff00; font-family: monospace; margin-bottom: 4px;">RFQ: <span style="color: #fff;">' + itemTitleText + '</span></div>' +
                        '<div style="display: flex; gap: 16px; font-size: 11px; color: #00ff00; font-family: monospace; flex-wrap: wrap;">' +
                        '<span>Qty: <span style="color: #fff;">' + (item.quantity || '—') + '</span></span>' +
                        '<span>Dims: <span style="color: #fff;">' + dimsText + '</span></span>' +
                        '<span>Delivery: <span style="color: #fff;">' + deliveryText + '</span></span>' +
                        '</div>' +
                        '</div>' +
                        '<button onclick="closeBidFormModal()" style="background: none; border: none; font-size: 18px; cursor: pointer; padding: 4px 8px; color: #00ff00; font-family: monospace; line-height: 1;">[x Close]</button>' +
                        '</div>' +
                        '<div style="flex: 1; overflow-y: auto; padding: 0; background-color: #000;">' +
                        
                        // G) Specs Changed Warning Banner (show but don't block form)
                        (item.has_revision_mismatch ? 
                            '<div id="n88-specs-changed-banner" style="margin: 20px; padding: 16px; background-color: #331100; border: 2px solid #ff9800; border-radius: 4px;">' +
                            '<div style="font-size: 14px; font-weight: 600; color: #ff9800; margin-bottom: 12px; font-family: monospace;">⚠️ Specs changed since your last bid.</div>' +
                            '<div style="font-size: 12px; color: #fff; margin-bottom: 16px; font-family: monospace; line-height: 1.5;">' +
                            'The item specifications have been updated. Please review and update your bid to match the new specs before submitting.' +
                            '</div>' +
                            '</div>' : '') +
                        
                        '<form id="n88-bid-form" style="padding: 20px; font-family: monospace;" onsubmit="return validateAndSubmitBid(event);">' +
                        
                        // Image gallery: left reference images, center main image, right reference images
                        imageGalleryHTML +
                        
                        // Commit 2.3.5.4: BID FORM Title - Remove "Anonymous • No contact info allowed"
                        '<div style="margin-bottom: 24px; padding: 12px 0; border-bottom: 1px solid #00ff00;">' +
                        '<h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #00ff00; font-family: monospace;">BID FORM</h2>' +
                        '</div>' +
                        
                        // 1. Video links (optional, 0-3) - Commit 2.3.5.4: Field order 1
                        '<div style="margin-bottom: 24px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #00ff00; font-family: monospace;">VIDEO LINKS (Optional)</label>' +
                        '<div style="font-size: 11px; color: #fff; margin-bottom: 8px; font-family: monospace;">Min 0, Max 3. Allowed: YouTube / Vimeo / Loom</div>' +
                        '<div id="n88-video-links-container">' +
                        '<div style="margin-bottom: 8px; display: flex; gap: 8px; align-items: center;">' +
                        '<span style="color: #00ff00; font-family: monospace; font-size: 12px;">1)</span>' +
                        '<input type="url" name="video_links[]" class="n88-video-link-input" placeholder="https://youtube.com/watch?v=..." style="flex: 1; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; font-family: monospace;" onblur="validateVideoLink(this);" oninput="validateBidForm();" />' +
                        '<button type="button" onclick="removeVideoLink(this)" style="padding: 8px 12px; background-color: #dc3545; color: #fff; border: none; border-radius: 2px; cursor: pointer; display: none; font-family: monospace; font-size: 11px;">Remove</button>' +
                        '</div>' +
                        '</div>' +
                        '<button type="button" onclick="addVideoLink()" id="n88-add-video-link-btn" style="margin-top: 8px; padding: 6px 12px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; cursor: pointer; font-size: 11px; font-family: monospace;">+ Add Another Link</button>' +
                        '<div id="n88-video-links-error" style="margin-top: 6px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                        
                        // 2. Reference photo(s) (required, min 1, max 5) - Commit 2.3.5.4: Renamed from "BID PHOTOS"
                        '<div style="margin-bottom: 24px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #fff; font-family: monospace;">Reference photo(s) <span style="color: #ff0000;">*</span></label>' +
                        '<div style="font-size: 11px; color: #fff; margin-bottom: 8px; font-family: monospace;">Upload photos of similar items or your work. Minimum 1 photo required (recommended 1-5).</div>' +
                        '<input type="file" id="n88-bid-photos-input" name="bid_photos[]" accept="image/*" multiple style="display: none;" onchange="handleBidPhotosChange(this);" />' +
                        '<button type="button" onclick="document.getElementById(\'n88-bid-photos-input\').click();" style="padding: 8px 16px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; cursor: pointer; font-size: 12px; margin-bottom: 12px; font-family: monospace;">+ Add Photos</button>' +
                        '<div id="n88-bid-photos-preview" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;"></div>' +
                        '<div id="n88-bid-photos-error" style="margin-top: 6px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                    
                        // 2. Prototype video commitment (must be YES) - Commit 2.3.5.2: Dark theme
                        '<div style="margin-bottom: 24px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #fff; font-family: monospace;">PROTOTYPE (Required)</label>' +
                        '<div style="font-size: 11px; color: #fff; margin-bottom: 8px; font-family: monospace;">Will you prepare and video a prototype?</div>' +
                        '<div style="display: flex; gap: 16px; margin-bottom: 8px;">' +
                        '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">' +
                        '<input type="radio" name="prototype_video_yes" value="1" required style="width: 16px; height: 16px; cursor: pointer; accent-color: #00ff00;" onchange="validateBidForm();" />' +
                        '<span style="font-size: 12px; color: #fff; font-family: monospace;">(●) YES</span>' +
                        '</label>' +
                        '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">' +
                        '<input type="radio" name="prototype_video_yes" value="0" style="width: 16px; height: 16px; cursor: pointer; accent-color: #00ff00;" onchange="validateBidForm();" />' +
                        '<span style="font-size: 12px; color: #fff; font-family: monospace;">() NO</span>' +
                        '</label>' +
                        '</div>' +
                        '<div style="font-size: 10px; color: #00ff00; margin-bottom: 12px; font-family: monospace; font-style: italic;">Helper: YES is required for this platform.</div>' +
                        '<div id="n88-prototype-video-error" style="margin-top: 6px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        // Prototype timeline
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #00ff00; font-family: monospace;">Prototype timeline (Required):</label>' +
                        '<select name="prototype_timeline_option" required style="width: 100%; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="validateBidForm();">' +
                        '<option value="">[ Select timeline... ▼ ]</option>' +
                        '<option value="1-2w">1–2w</option>' +
                        '<option value="2-4w">2–4w</option>' +
                        '<option value="4-6w">4–6w</option>' +
                        '<option value="6-8w">6–8w</option>' +
                        '<option value="8-10w">8–10w</option>' +
                        '</select>' +
                        '<div id="n88-prototype-timeline-error" style="margin-top: 4px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                        // Prototype cost
                        '<div style="margin-bottom: 0;">' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #00ff00; font-family: monospace;">Prototype cost (Required):</label>' +
                        '<input type="number" name="prototype_cost" step="0.01" min="0" required placeholder="0.00" style="width: 100%; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; font-family: monospace;" oninput="validateBidForm();" />' +
                        '<div id="n88-prototype-cost-error" style="margin-top: 4px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                        '</div>' +
                        
                        // 3. Production lead time - Commit 2.3.5.2: Dark theme
                        '<div style="margin-bottom: 24px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #fff; font-family: monospace;">Production lead time (Required) — Dropdown</label>' +
                        '<select name="production_lead_time_text" required style="width: 100%; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="validateBidForm();">' +
                        '<option value="">[ Select lead time... ▼ ]</option>' +
                        '<option value="2-4 weeks">2–4 weeks</option>' +
                        '<option value="4-6 weeks">4–6 weeks</option>' +
                        '<option value="6-8 weeks">6–8 weeks</option>' +
                        '<option value="8-12 weeks">8–12 weeks</option>' +
                        '<option value="12-16 weeks">12–16 weeks</option>' +
                        '</select>' +
                        '<div id="n88-lead-time-error" style="margin-top: 6px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                        
                        // 4. Unit price - Commit 2.3.5.2: Dark theme
                        '<div style="margin-bottom: 24px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #fff; font-family: monospace;">Unit price (Required)</label>' +
                        '<input type="number" name="unit_price" step="0.01" min="0.01" required placeholder="0.00" style="width: 100%; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; font-family: monospace;" oninput="validateBidForm();" />' +
                        '<div id="n88-unit-price-error" style="margin-top: 6px; font-size: 11px; color: #ff0000; display: none; font-family: monospace;"></div>' +
                        '</div>' +
                        
                        // 8. SMART ALTERNATIVE (DFM) - Commit 2.3.5.5: Remove designer notes from Smart Alternatives (they belong in Item Context)
                        ((item.smart_alternatives_enabled) ? 
                        '<div style="margin-bottom: 24px; padding: 16px; background-color: #1a1a1a; border-radius: 2px; border: none;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #00ff00; font-family: monospace;">SMART ALTERNATIVE (DFM)</label>' +
                        // C1: Display designer's Smart Alternatives setting (read-only) - NO designer notes here
                        '<div style="padding: 12px; background-color: #000; border-radius: 2px; border: 1px solid #00ff00; margin-bottom: 12px;">' +
                        '<div style="font-size: 11px; color: #00ff00; font-family: monospace; margin-bottom: 8px;">' +
                        '<strong>Smart Alternatives:</strong> <span style="color: ' + (item.smart_alternatives_enabled ? '#00ff00' : '#666') + ';">' + (item.smart_alternatives_enabled ? 'Enabled' : 'Disabled') + '</span>' +
                        '</div>' +
                        (item.smart_alternatives_enabled ? '<div style="font-size: 11px; color: #00ff00; font-family: monospace; margin-bottom: 8px;">Creator is open to comparable material/spec alternatives.</div>' : '') +
                        '</div>' +
                        // C2: Supplier can add ONE structured Smart Alternative suggestion (only if enabled)
                        (item.smart_alternatives_enabled ? 
                        '<div style="padding: 12px; background-color: #000; border-radius: 2px; border: 1px solid #00ff00; margin-top: 12px;">' +
                        '<div style="font-size: 11px; color: #00ff00; font-family: monospace; margin-bottom: 12px; font-weight: 600;">Propose Smart Alternative (Optional):</div>' +
                        // Category dropdown
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">Category:</label>' +
                        '<select name="smart_alt_category" id="n88-smart-alt-category-modal" style="width: 100%; padding: 6px 10px; border: none; border-radius: 2px; font-size: 11px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="updateSmartAltPreviewModal();">' +
                        '<option value="">[ Select category... ]</option>' +
                        '<option value="material">Material</option>' +
                        '<option value="finish">Finish</option>' +
                        '<option value="hardware">Hardware</option>' +
                        '<option value="dimensions">Dimensions</option>' +
                        '<option value="construction">Construction Method</option>' +
                        '<option value="packaging">Packaging</option>' +
                        '</select>' +
                        '</div>' +
                        // From dropdown
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">From:</label>' +
                        '<select name="smart_alt_from" id="n88-smart-alt-from-modal" style="width: 100%; padding: 6px 10px; border: none; border-radius: 2px; font-size: 11px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="updateSmartAltPreviewModal();">' +
                        '<option value="">[ Select from... ]</option>' +
                        '<option value="solid-wood">Solid Wood</option>' +
                        '<option value="plywood">Plywood</option>' +
                        '<option value="mdf">MDF</option>' +
                        '<option value="metal">Metal</option>' +
                        '<option value="plastic">Plastic</option>' +
                        '<option value="glass">Glass</option>' +
                        '<option value="fabric">Fabric</option>' +
                        '<option value="leather">Leather</option>' +
                        '<option value="other">Other</option>' +
                        '</select>' +
                        '</div>' +
                        // To dropdown
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">To:</label>' +
                        '<select name="smart_alt_to" id="n88-smart-alt-to-modal" style="width: 100%; padding: 6px 10px; border: none; border-radius: 2px; font-size: 11px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="updateSmartAltPreviewModal();">' +
                        '<option value="">[ Select to... ]</option>' +
                        '<option value="solid-wood">Solid Wood</option>' +
                        '<option value="plywood">Plywood</option>' +
                        '<option value="mdf">MDF</option>' +
                        '<option value="metal">Metal</option>' +
                        '<option value="plastic">Plastic</option>' +
                        '<option value="glass">Glass</option>' +
                        '<option value="fabric">Fabric</option>' +
                        '<option value="leather">Leather</option>' +
                        '<option value="other">Other</option>' +
                        '</select>' +
                        '</div>' +
                        // Comparison points (checkboxes, max 3)
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 6px; color: #fff; font-family: monospace;">Comparison Points (max 3):</label>' +
                        '<div style="display: flex; flex-direction: column; gap: 6px;">' +
                        '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="cost-reduction" class="n88-smart-alt-checkbox-modal" onchange="updateSmartAltPreviewModal();" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Cost Reduction</span></label>' +
                        '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="faster-production" class="n88-smart-alt-checkbox-modal" onchange="updateSmartAltPreviewModal();" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Faster Production</span></label>' +
                        '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="better-durability" class="n88-smart-alt-checkbox-modal" onchange="updateSmartAltPreviewModal();" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Better Durability</span></label>' +
                        '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="easier-sourcing" class="n88-smart-alt-checkbox-modal" onchange="updateSmartAltPreviewModal();" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Easier Sourcing</span></label>' +
                        '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="lighter-weight" class="n88-smart-alt-checkbox-modal" onchange="updateSmartAltPreviewModal();" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Lighter Weight</span></label>' +
                        '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" name="smart_alt_comparison[]" value="eco-friendly" class="n88-smart-alt-checkbox-modal" onchange="updateSmartAltPreviewModal();" style="width: 14px; height: 14px; cursor: pointer; accent-color: #00ff00;" /><span style="font-size: 11px; color: #fff; font-family: monospace;">Eco-Friendly</span></label>' +
                        '</div>' +
                        '</div>' +
                        // Price impact dropdown
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">Price Impact:</label>' +
                        '<select name="smart_alt_price_impact" id="n88-smart-alt-price-modal" style="width: 100%; padding: 6px 10px; border: none; border-radius: 2px; font-size: 11px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="updateSmartAltPreviewModal();">' +
                        '<option value="">[ Select impact... ]</option>' +
                        '<option value="reduces-10-20">Reduces 10-20%</option>' +
                        '<option value="reduces-20-30">Reduces 20-30%</option>' +
                        '<option value="reduces-30-plus">Reduces 30%+</option>' +
                        '<option value="similar">Similar Price</option>' +
                        '<option value="increases-10-20">Increases 10-20%</option>' +
                        '<option value="increases-20-plus">Increases 20%+</option>' +
                        '</select>' +
                        '</div>' +
                        // Lead time impact dropdown
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #fff; font-family: monospace;">Lead Time Impact:</label>' +
                        '<select name="smart_alt_lead_time_impact" id="n88-smart-alt-leadtime-modal" style="width: 100%; padding: 6px 10px; border: none; border-radius: 2px; font-size: 11px; background-color: #1a1a1a; color: #fff; cursor: pointer; font-family: monospace;" onchange="updateSmartAltPreviewModal();">' +
                        '<option value="">[ Select impact... ]</option>' +
                        '<option value="reduces-1-2w">Reduces 1-2 weeks</option>' +
                        '<option value="reduces-2-4w">Reduces 2-4 weeks</option>' +
                        '<option value="reduces-4w-plus">Reduces 4+ weeks</option>' +
                        '<option value="similar">Similar Lead Time</option>' +
                        '<option value="increases-1-2w">Increases 1-2 weeks</option>' +
                        '<option value="increases-2w-plus">Increases 2+ weeks</option>' +
                        '</select>' +
                        '</div>' +
                        // Preview sentence (read-only)
                        '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #00ff00;">' +
                        '<label style="display: block; font-size: 11px; margin-bottom: 4px; color: #00ff00; font-family: monospace; font-weight: 600;">Preview:</label>' +
                        '<div id="n88-smart-alt-preview-modal" style="padding: 8px; background-color: #1a1a1a; border: none; border-radius: 2px; font-size: 11px; color: #999; font-family: monospace; min-height: 40px; font-style: italic;">Fill in the fields above to generate preview...</div>' +
                        '</div>' +
                        '</div>' : '') +
                        '</div>' : '') +
                    
                        '</form>' +
                        '</div>' +
                        // Footer with submit button - Commit 2.3.5.2: Dark theme with green buttons
                        '<div style="padding: 20px; border-top: 1px solid #00ff00; background-color: #000; display: flex; justify-content: flex-end; gap: 12px; flex-wrap: wrap;">' +
                        '<div style="flex: 1; min-width: 200px;">' +
                        '<div style="font-size: 11px; color: #00ff00; font-family: monospace; margin-bottom: 8px;">Rules:</div>' +
                        '<div style="font-size: 10px; color: #fff; font-family: monospace;">Rules: No emails / phones / URLs / contact text. No uploads. No links here.</div>' +
                        '</div>' +
                        '<div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">' +
                        '<div style="font-size: 11px; color: #00ff00; font-family: monospace; margin-right: 8px;">ACTIONS:</div>' +
                        '<button type="button" id="n88-validate-bid-btn" onclick="validateAndSubmitBid(event)" disabled style="padding: 10px 20px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; font-size: 12px; font-weight: 600; cursor: not-allowed; font-family: monospace; opacity: 0.5;">[ Validate Bid ]</button>' +
                        '<button type="button" id="n88-submit-bid-btn" onclick="submitBid(event)" disabled style="display: none; padding: 10px 20px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: monospace;">[ Submit Bid ]</button>' +
                        '<button type="button" onclick="closeBidFormModal()" style="padding: 10px 20px; background-color: #1a1a1a; color: #00ff00; border: none; border-radius: 2px; font-size: 12px; cursor: pointer; font-family: monospace;">[ Cancel ]</button>' +
                        '</div>' +
                        '</div>';
                    
                    modalContent.innerHTML = modalHTML;
                    
                    // H) Load draft or submitted bid data for modal form
                    setTimeout(function() {
                        loadBidDraftModal(itemId, item);
                    }, 200);
                    
                    // Initial validation - use setTimeout to ensure DOM is ready
                    setTimeout(function() {
                        validateBidForm();
                    }, 100);
                    
                    // Commit 2.3.5.5: Reset button states - Validate button visible, Submit button hidden
                    setTimeout(function() {
                        var validateBtn = document.getElementById('n88-validate-bid-btn');
                        var submitBtn = document.getElementById('n88-submit-bid-btn');
                        if (validateBtn) {
                            validateBtn.style.display = 'inline-block';
                            validateBtn.disabled = true;
                            validateBtn.style.opacity = '0.5';
                        }
                        if (submitBtn) {
                            submitBtn.style.display = 'none';
                            submitBtn.disabled = true;
                        }
                        // Run validation to enable/disable validate button
                        if (typeof validateBidForm === 'function') {
                            validateBidForm();
                        }
                    }, 150);
                })
                .catch(function(error) {
                    console.error('Error loading item details:', error);
                    modalContent.innerHTML = '<div style="padding: 40px; text-align: center; color: #d32f2f;">' + 
                        '<p style="margin-bottom: 20px;">Network error. Failed to load item details.</p>' +
                        '<button onclick="closeBidFormModal()" style="padding: 8px 16px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Close</button>' +
                        '</div>';
                });
            }
            
            // Close bid form modal
            function closeBidFormModal() {
                var modal = document.getElementById('n88-supplier-bid-form-modal');
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }
            
            // Commit 2.3.5.1: Supplier image lightbox functions
            function openSupplierImageLightbox(imageUrl) {
                var lightbox = document.getElementById('n88-supplier-image-lightbox');
                var lightboxImage = document.getElementById('n88-supplier-lightbox-image');
                if (lightbox && lightboxImage) {
                    lightboxImage.src = imageUrl;
                    lightbox.style.display = 'block';
                    document.body.style.overflow = 'hidden';
                }
            }
            
            function closeSupplierImageLightbox(event) {
                if (event) {
                    event.stopPropagation();
                }
                var lightbox = document.getElementById('n88-supplier-image-lightbox');
                if (lightbox) {
                    lightbox.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }
            
            // Commit 2.3.5.1: Handle bid photos upload
            function handleBidPhotosChange(input) {
                var files = input.files;
                if (!files || files.length === 0) {
                    return;
                }
                
                // Validate file count (max 5)
                if (files.length > 5) {
                    alert('Maximum 5 photos allowed. Please select up to 5 photos.');
                    input.value = '';
                    return;
                }
                
                // Validate file types
                var imageFiles = Array.from(files).filter(function(file) {
                    return file.type.startsWith('image/');
                });
                
                if (imageFiles.length !== files.length) {
                    alert('Please select image files only.');
                    input.value = '';
                    return;
                }
                
                var previewContainer = document.getElementById('n88-bid-photos-preview');
                var errorDiv = document.getElementById('n88-bid-photos-error');
                
                if (!previewContainer) {
                    console.error('Bid photos preview container not found');
                    return;
                }
                
                // Clear previous previews
                previewContainer.innerHTML = '';
                
                // Get nonce for AJAX
                var nonce = '<?php echo wp_create_nonce( 'n88_upload_inspiration_image' ); ?>';
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                
                // Upload each file
                var uploadPromises = imageFiles.map(function(file, index) {
                    return new Promise(function(resolve, reject) {
                        var formData = new FormData();
                        formData.append('action', 'n88_upload_inspiration_image');
                        formData.append('inspiration_image', file);
                        formData.append('nonce', nonce);
                        
                        // Show loading placeholder
                        var placeholderId = 'n88-bid-photo-placeholder-' + index;
                        var placeholder = document.createElement('div');
                        placeholder.id = placeholderId;
                        placeholder.style.cssText = 'position: relative; width: 100px; height: 100px; border: 2px dashed #ddd; border-radius: 4px; display: flex; align-items: center; justify-content: center; background-color: #f9f9f9;';
                        placeholder.innerHTML = '<div style="font-size: 11px; color: #999;">Uploading...</div>';
                        previewContainer.appendChild(placeholder);
                        
                        fetch(ajaxUrl, {
                            method: 'POST',
                            body: formData
                        })
                        .then(function(response) {
                            if (!response.ok) {
                                throw new Error('HTTP error! status: ' + response.status);
                            }
                            return response.json();
                        })
                        .then(function(data) {
                            // Remove placeholder
                            var placeholderEl = document.getElementById(placeholderId);
                            if (placeholderEl) {
                                placeholderEl.remove();
                            }
                            
                            if (data.success && data.data && data.data.id && data.data.url) {
                                // Create thumbnail
                                var thumbDiv = document.createElement('div');
                                thumbDiv.style.cssText = 'position: relative; width: 100px; height: 100px; border: 2px solid #ddd; border-radius: 4px; overflow: hidden;';
                                thumbDiv.innerHTML = '<img src="' + data.data.url.replace(/"/g, '&quot;') + '" style="width: 100%; height: 100%; object-fit: cover;" alt="Bid photo" />' +
                                    '<button type="button" onclick="removeBidPhoto(this, ' + data.data.id + ');" style="position: absolute; top: 4px; right: 4px; background: rgba(255, 0, 0, 0.8); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center;" title="Remove">×</button>';
                                
                                // Add hidden input INSIDE THE FORM (not in preview container)
                                var form = document.getElementById('n88-bid-form');
                                if (form) {
                                    var hiddenInput = document.createElement('input');
                                    hiddenInput.type = 'hidden';
                                    hiddenInput.name = 'bid_photo_ids[]';
                                    hiddenInput.value = data.data.id;
                                    hiddenInput.setAttribute('data-photo-id', data.data.id);
                                    hiddenInput.setAttribute('data-thumb-div', 'photo-' + data.data.id); // Link to thumbDiv
                                    form.appendChild(hiddenInput);
                                    console.log('✓ Added hidden input to form - Photo ID:', data.data.id, 'Total inputs:', form.querySelectorAll('input[name="bid_photo_ids[]"]').length);
                                } else {
                                    console.error('✗ Form #n88-bid-form not found when adding photo!');
                                }
                                
                                // Store reference in thumbDiv for easy removal
                                thumbDiv.setAttribute('data-photo-id', data.data.id);
                                previewContainer.appendChild(thumbDiv);
                                
                                resolve({ id: data.data.id, url: data.data.url });
                            } else {
                                var errorMsg = data.data && data.data.message ? data.data.message : 'Upload failed';
                                throw new Error(errorMsg);
                            }
                        })
                        .catch(function(error) {
                            // Remove placeholder
                            var placeholderEl = document.getElementById(placeholderId);
                            if (placeholderEl) {
                                placeholderEl.remove();
                            }
                            
                            console.error('Error uploading photo:', error);
                            if (errorDiv) {
                                errorDiv.textContent = 'Failed to upload ' + file.name + ': ' + error.message;
                                errorDiv.style.display = 'block';
                            }
                            reject(error);
                        });
                    });
                });
                
                // Clear error on success
                Promise.all(uploadPromises).then(function(results) {
                    if (errorDiv) {
                        errorDiv.style.display = 'none';
                    }
                    
                    // Verify inputs were added before validating
                    var form = document.getElementById('n88-bid-form');
                    if (form) {
                        var inputs = form.querySelectorAll('input[name="bid_photo_ids[]"]');
                        console.log('After upload - Total photo inputs in form:', inputs.length);
                        if (inputs.length > 0) {
                            inputs.forEach(function(input, idx) {
                                console.log('  Input ' + idx + ':', input.value);
                            });
                        }
                    }
                    
                    // Force validation after a delay to ensure DOM is fully updated
                    setTimeout(function() {
                        if (typeof validateBidForm === 'function') {
                            var validationResult = validateBidForm();
                            console.log('Validation result after upload:', validationResult);
                        }
                    }, 300);
                }).catch(function() {
                    // Errors already handled above
                });
            }
            
            // Remove bid photo
            function removeBidPhoto(button, photoId) {
                if (!confirm('Remove this photo?')) {
                    return;
                }
                
                var thumbDiv = button.parentElement;
                thumbDiv.remove();
                
                // Remove the hidden input from form
                var form = document.getElementById('n88-bid-form');
                if (form) {
                    var hiddenInput = form.querySelector('input[data-photo-id="' + photoId + '"]');
                    if (hiddenInput) {
                        hiddenInput.remove();
                    }
                }
                
                // Clear file input if no photos left
                var previewContainer = document.getElementById('n88-bid-photos-preview');
                var remainingPhotos = previewContainer.querySelectorAll('div[style*="position: relative"]').length;
                if (remainingPhotos === 0) {
                    var input = document.getElementById('n88-bid-photos-input');
                    if (input) {
                        input.value = '';
                    }
                }
                
                validateBidForm();
            }
            
            // Add video link input
            function addVideoLink() {
                var container = document.getElementById('n88-video-links-container');
                var linkCount = container.querySelectorAll('.n88-video-link-input').length;
                
                if (linkCount >= 3) {
                    alert('Maximum 3 video links allowed.');
                    return;
                }
                
                var newLinkDiv = document.createElement('div');
                newLinkDiv.style.cssText = 'margin-bottom: 8px; display: flex; gap: 8px;';
                newLinkDiv.innerHTML = '<input type="url" name="video_links[]" class="n88-video-link-input" placeholder="https://youtube.com/watch?v=..." style="flex: 1; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" onblur="validateVideoLink(this);" />' +
                    '<button type="button" onclick="removeVideoLink(this)" style="padding: 10px 16px; background-color: #dc3545; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Remove</button>';
                
                container.appendChild(newLinkDiv);
                
                // Show remove buttons if more than 1 link
                updateVideoLinkButtons();
                validateBidForm();
            }
            
            // Remove video link input
            function removeVideoLink(button) {
                var container = document.getElementById('n88-video-links-container');
                var linkDiv = button.parentElement;
                container.removeChild(linkDiv);
                updateVideoLinkButtons();
                validateBidForm();
            }
            
            // Update video link remove buttons visibility
            function updateVideoLinkButtons() {
                var container = document.getElementById('n88-video-links-container');
                var links = container.querySelectorAll('.n88-video-link-input');
                var removeButtons = container.querySelectorAll('button[onclick*="removeVideoLink"]');
                
                if (links.length > 1) {
                    removeButtons.forEach(function(btn) { btn.style.display = 'block'; });
                } else {
                    removeButtons.forEach(function(btn) { btn.style.display = 'none'; });
                }
                
                // Show/hide add button
                var addBtn = document.getElementById('n88-add-video-link-btn');
                if (addBtn) {
                    addBtn.style.display = links.length >= 3 ? 'none' : 'block';
                }
            }
            
            // Validate individual video link
            function validateVideoLink(input) {
                var url = input.value.trim();
                var errorDiv = document.getElementById('n88-video-links-error');
                
                if (!url) {
                    input.style.borderColor = '#ddd';
                    return true; // Empty is OK if not the only link
                }
                
                // Allowed providers: YouTube, Vimeo, Loom
                var allowedDomains = [
                    'youtube.com', 'youtu.be', 'www.youtube.com',
                    'vimeo.com', 'www.vimeo.com',
                    'loom.com', 'www.loom.com'
                ];
                
                var urlObj;
                try {
                    urlObj = new URL(url);
                } catch (e) {
                    input.style.borderColor = '#d32f2f';
                    if (errorDiv) {
                        errorDiv.textContent = 'Invalid URL format.';
                        errorDiv.style.display = 'block';
                    }
                    return false;
                }
                
                var hostname = urlObj.hostname.toLowerCase().replace('www.', '');
                var isValid = allowedDomains.some(function(domain) {
                    return hostname === domain || hostname.endsWith('.' + domain);
                });
                
                if (!isValid) {
                    input.style.borderColor = '#d32f2f';
                    if (errorDiv) {
                        errorDiv.textContent = 'Only YouTube, Vimeo, or Loom links are allowed.';
                        errorDiv.style.display = 'block';
                    }
                    return false;
                }
                
                input.style.borderColor = '#28a745';
                if (errorDiv) {
                    errorDiv.style.display = 'none';
                }
                // Don't call validateBidForm() here to avoid infinite recursion
                // Validation will be triggered by oninput/onchange events
                return true;
            }
            
            // Commit 2.3.5.1: Handle bid photos change - UPLOAD TO WORDPRESS MEDIA
            // This function is already defined above, but keeping this comment for reference
            
            // Validate entire bid form (client-side) - works with both modal and embedded forms
            function validateBidForm() {
                var form = document.getElementById('n88-bid-form');
                var isEmbedded = false;
                var itemId = null;
                
                // Check if it's an embedded form
                if (!form) {
                    var embeddedForms = document.querySelectorAll('[id^="n88-bid-form-embedded-"]');
                    if (embeddedForms.length > 0) {
                        form = embeddedForms[0];
                        isEmbedded = true;
                        var formId = form.id;
                        itemId = formId.replace('n88-bid-form-embedded-', '');
                    }
                }
                
                if (!form) return false;
                
                var isValid = true;
                // Commit 2.3.5.5: Get the correct button - Validate button for enabling/disabling, Submit button for after validation
                var validateBtn = isEmbedded ? 
                    document.getElementById('n88-validate-bid-btn-embedded-' + itemId) : 
                    document.getElementById('n88-validate-bid-btn');
                var submitBtn = isEmbedded ? 
                    document.getElementById('n88-submit-bid-btn-embedded-' + itemId) : 
                    document.getElementById('n88-submit-bid-btn');
                
                // 1. Video links: optional, max 3, all valid (Commit 2.3.5.1: Remove mandatory requirement)
                var videoLinks = form.querySelectorAll('.n88-video-link-input');
                var validVideoLinks = 0;
                var allowedDomains = [
                    'youtube.com', 'youtu.be', 'www.youtube.com',
                    'vimeo.com', 'www.vimeo.com',
                    'loom.com', 'www.loom.com'
                ];
                
                videoLinks.forEach(function(input) {
                    var url = input.value.trim();
                    if (url) {
                        // Validate URL format and domain
                        try {
                            var urlObj = new URL(url);
                            var hostname = urlObj.hostname.toLowerCase().replace('www.', '');
                            var isAllowed = allowedDomains.some(function(domain) {
                                var domainClean = domain.replace('www.', '');
                                return hostname === domainClean || hostname.endsWith('.' + domainClean);
                            });
                            if (isAllowed) {
                                validVideoLinks++;
                            }
                        } catch (e) {
                            // Invalid URL format - don't count
                        }
                    }
                });
                
                // Video links are optional now, but if provided, max 3
                if (validVideoLinks > 3) {
                    isValid = false;
                }
                
                // 1.5. Bid Photos: required, min 1, max 5 (Commit 2.3.5.1) - check uploaded photo IDs
                // Check in form first, then fallback to preview container, then check thumbnails
                var photoIdInputs = [];
                if (form) {
                    var formInputs = form.querySelectorAll('input[name="bid_photo_ids[]"]');
                    photoIdInputs = Array.from(formInputs);
                }
                
                // Also check preview container as fallback
                var previewContainer = isEmbedded ? 
                    document.getElementById('n88-bid-photos-preview-embedded-' + itemId) : 
                    document.getElementById('n88-bid-photos-preview');
                if (previewContainer) {
                    var previewInputs = previewContainer.querySelectorAll('input[name="bid_photo_ids[]"]');
                    Array.from(previewInputs).forEach(function(input) {
                        // Only add if not already in array
                        var alreadyExists = photoIdInputs.some(function(existing) {
                            return existing === input || existing.value === input.value;
                        });
                        if (!alreadyExists) {
                            photoIdInputs.push(input);
                        }
                    });
                }
                
                // Also count thumbnails as a last resort (if inputs somehow not found)
                var bidPhotosCount = 0;
                if (photoIdInputs.length > 0) {
                    photoIdInputs.forEach(function(input) {
                        var photoId = parseInt(input.value);
                        if (!isNaN(photoId) && photoId > 0) {
                            bidPhotosCount++;
                        }
                    });
                } else {
                    // Fallback: count thumbnails in preview container
                    if (previewContainer) {
                        var thumbnails = previewContainer.querySelectorAll('div[data-photo-id]');
                        bidPhotosCount = thumbnails.length;
                    }
                }
                
                var photosError = isEmbedded ? 
                    document.getElementById('n88-bid-photos-error-embedded-' + itemId) : 
                    document.getElementById('n88-bid-photos-error');
                var previewContainer = isEmbedded ? 
                    document.getElementById('n88-bid-photos-preview-embedded-' + itemId) : 
                    document.getElementById('n88-bid-photos-preview');
                
                console.log('Photo validation - Inputs found:', photoIdInputs.length, 'Photos counted:', bidPhotosCount, 'Form:', !!form, 'Preview container:', !!previewContainer);
                
                if (bidPhotosCount < 1) {
                    isValid = false;
                    if (photosError) {
                        photosError.textContent = 'At least 1 photo is required.';
                        photosError.style.display = 'block';
                    }
                    console.log('✗ Photo validation FAILED - Need at least 1 photo');
                } else if (bidPhotosCount > 5) {
                    isValid = false;
                    if (photosError) {
                        photosError.textContent = 'Maximum 5 photos allowed.';
                        photosError.style.display = 'block';
                    }
                    console.log('✗ Photo validation FAILED - Maximum 5 photos allowed');
                } else {
                    if (photosError) {
                        photosError.style.display = 'none';
                    }
                    console.log('✓ Photo validation PASSED -', bidPhotosCount, 'photo(s)');
                }
                
                // 2. Prototype video (optional - YES or NO)
                var prototypeYes = form.querySelector('input[name="prototype_video_yes"][value="1"]');
                var prototypeNo = form.querySelector('input[name="prototype_video_yes"][value="0"]');
                var isPrototypeYes = prototypeYes && prototypeYes.checked;
                var isPrototypeNo = prototypeNo && prototypeNo.checked;
                
                // Clear any previous error
                var prototypeError = isEmbedded ? 
                    document.getElementById('n88-prototype-video-error-embedded-' + itemId) : 
                    document.getElementById('n88-prototype-video-error');
                if (prototypeError) {
                    prototypeError.style.display = 'none';
                }
                
                // 3. Prototype timeline required ONLY if prototype is YES
                var timeline = form.querySelector('select[name="prototype_timeline_option"]');
                if (isPrototypeYes) {
                    if (!timeline || !timeline.value) {
                        isValid = false;
                        var timelineError = isEmbedded ? 
                            document.getElementById('n88-prototype-timeline-error-embedded-' + itemId) : 
                            document.getElementById('n88-prototype-timeline-error');
                        if (timelineError) {
                            timelineError.textContent = 'Prototype timeline is required.';
                            timelineError.style.display = 'block';
                        }
                    } else {
                        var timelineError = isEmbedded ? 
                            document.getElementById('n88-prototype-timeline-error-embedded-' + itemId) : 
                            document.getElementById('n88-prototype-timeline-error');
                        if (timelineError) {
                            timelineError.style.display = 'none';
                        }
                    }
                } else {
                    // Clear timeline error if prototype is NO
                    var timelineError = isEmbedded ? 
                        document.getElementById('n88-prototype-timeline-error-embedded-' + itemId) : 
                        document.getElementById('n88-prototype-timeline-error');
                    if (timelineError) {
                        timelineError.style.display = 'none';
                    }
                }
                
                // 4. Prototype cost required ONLY if prototype is YES
                var prototypeCost = form.querySelector('input[name="prototype_cost"]');
                if (isPrototypeYes) {
                    if (prototypeCost && prototypeCost.value) {
                        var costValue = parseFloat(prototypeCost.value);
                        if (isNaN(costValue) || costValue < 0) {
                            isValid = false;
                            var costError = isEmbedded ? 
                                document.getElementById('n88-prototype-cost-error-embedded-' + itemId) : 
                                document.getElementById('n88-prototype-cost-error');
                            if (costError) {
                                costError.textContent = 'Prototype cost must be a valid number >= 0.';
                                costError.style.display = 'block';
                            }
                        } else {
                            var costError = isEmbedded ? 
                                document.getElementById('n88-prototype-cost-error-embedded-' + itemId) : 
                                document.getElementById('n88-prototype-cost-error');
                            if (costError) {
                                costError.style.display = 'none';
                            }
                        }
                    } else {
                        isValid = false;
                        var costError = isEmbedded ? 
                            document.getElementById('n88-prototype-cost-error-embedded-' + itemId) : 
                            document.getElementById('n88-prototype-cost-error');
                        if (costError) {
                            costError.textContent = 'Prototype cost is required.';
                            costError.style.display = 'block';
                        }
                    }
                } else {
                    // Clear cost error if prototype is NO
                    var costError = isEmbedded ? 
                        document.getElementById('n88-prototype-cost-error-embedded-' + itemId) : 
                        document.getElementById('n88-prototype-cost-error');
                    if (costError) {
                        costError.style.display = 'none';
                    }
                }
                
                // 5. Production lead time: non-empty
                var leadTime = form.querySelector('select[name="production_lead_time_text"]');
                if (!leadTime || !leadTime.value || !leadTime.value.trim()) {
                    isValid = false;
                    var leadTimeError = isEmbedded ? 
                        document.getElementById('n88-lead-time-error-embedded-' + itemId) : 
                        document.getElementById('n88-lead-time-error');
                    if (leadTimeError) {
                        leadTimeError.textContent = 'Production lead time is required.';
                        leadTimeError.style.display = 'block';
                    }
                } else {
                    var leadTimeError = isEmbedded ? 
                        document.getElementById('n88-lead-time-error-embedded-' + itemId) : 
                        document.getElementById('n88-lead-time-error');
                    if (leadTimeError) {
                        leadTimeError.style.display = 'none';
                    }
                }
                
                // 6. Unit price: numeric > 0
                var unitPrice = form.querySelector('input[name="unit_price"]');
                if (unitPrice && unitPrice.value) {
                    var priceValue = parseFloat(unitPrice.value);
                    if (isNaN(priceValue) || priceValue <= 0) {
                        isValid = false;
                        var priceError = isEmbedded ? 
                            document.getElementById('n88-unit-price-error-embedded-' + itemId) : 
                            document.getElementById('n88-unit-price-error');
                        if (priceError) {
                            priceError.textContent = 'Unit price must be a valid number > 0.';
                            priceError.style.display = 'block';
                        }
                    } else {
                        var priceError = isEmbedded ? 
                            document.getElementById('n88-unit-price-error-embedded-' + itemId) : 
                            document.getElementById('n88-unit-price-error');
                        if (priceError) {
                            priceError.style.display = 'none';
                        }
                    }
                } else {
                    isValid = false;
                    var priceError = isEmbedded ? 
                        document.getElementById('n88-unit-price-error-embedded-' + itemId) : 
                        document.getElementById('n88-unit-price-error');
                    if (priceError) {
                        priceError.textContent = 'Unit price is required.';
                        priceError.style.display = 'block';
                    }
                }
                
                // Commit 2.3.5.5: Enable/disable Validate button based on form validity
                if (validateBtn) {
                    if (isValid) {
                        validateBtn.disabled = false;
                        validateBtn.style.opacity = '1';
                        validateBtn.style.cursor = 'pointer';
                    } else {
                        validateBtn.disabled = true;
                        validateBtn.style.opacity = '0.5';
                        validateBtn.style.cursor = 'not-allowed';
                    }
                }
                
                // Debug: Log validation status (remove in production)
                if (window.console && window.console.log) {
                    console.log('Bid form validation:', {
                        validVideoLinks: validVideoLinks,
                        prototypeYes: prototypeYes ? prototypeYes.checked : false,
                        timeline: timeline ? timeline.value : '',
                        prototypeCost: prototypeCost ? prototypeCost.value : '',
                        leadTime: leadTime ? leadTime.value : '',
                        unitPrice: unitPrice ? unitPrice.value : '',
                        isValid: isValid
                    });
                }
                
                return isValid;
            }
            
            // Validate and submit bid (server-side validation)
            function validateAndSubmitBid(event) {
                if (event) {
                    event.preventDefault();
                }
                
                var form = document.getElementById('n88-bid-form');
                if (!form) return false;
                
                // Client-side validation
                if (!validateBidForm()) {
                    alert('Please complete all required fields correctly.');
                    return false;
                }
                
                var modal = document.getElementById('n88-supplier-bid-form-modal');
                var itemId = modal ? modal.getAttribute('data-item-id') : null;
                
                if (!itemId) {
                    alert('Item ID not found.');
                    return false;
                }
                
                // Collect form data
                var formData = new FormData();
                formData.append('action', 'n88_validate_supplier_bid');
                formData.append('item_id', itemId);
                
                // Video links
                var videoLinks = form.querySelectorAll('.n88-video-link-input');
                var videoLinksArray = [];
                videoLinks.forEach(function(input) {
                    var url = input.value.trim();
                    if (url) {
                        videoLinksArray.push(url);
                    }
                });
                formData.append('video_links', JSON.stringify(videoLinksArray));
                
                // Commit 2.3.5.1: Bid photos (required) - use uploaded photo IDs
                var bidPhotoIds = [];
                var photoIdInputs = form.querySelectorAll('input[name="bid_photo_ids[]"]');
                photoIdInputs.forEach(function(input) {
                    var photoId = parseInt(input.value);
                    if (!isNaN(photoId) && photoId > 0) {
                        bidPhotoIds.push(photoId);
                    }
                });
                formData.append('bid_photo_ids', JSON.stringify(bidPhotoIds));
                
                // Other fields
                formData.append('prototype_video_yes', form.querySelector('input[name="prototype_video_yes"]:checked') ? form.querySelector('input[name="prototype_video_yes"]:checked').value : '');
                formData.append('prototype_timeline_option', form.querySelector('select[name="prototype_timeline_option"]').value);
                formData.append('prototype_cost', form.querySelector('input[name="prototype_cost"]').value);
                formData.append('production_lead_time_text', form.querySelector('select[name="production_lead_time_text"]').value);
                formData.append('unit_price', form.querySelector('input[name="unit_price"]').value);
                
                // Smart Alternatives suggestion (if enabled and ANY field is filled)
                var smartAltCategory = form.querySelector('select[name="smart_alt_category"]');
                var smartAltFrom = form.querySelector('select[name="smart_alt_from"]');
                var smartAltTo = form.querySelector('select[name="smart_alt_to"]');
                var smartAltPrice = form.querySelector('select[name="smart_alt_price_impact"]');
                var smartAltLeadTime = form.querySelector('select[name="smart_alt_lead_time_impact"]');
                var smartAltComparisons = form.querySelectorAll('input[name="smart_alt_comparison[]"]:checked');
                
                // Check if ANY Smart Alternatives field has a value
                var hasCategory = smartAltCategory && smartAltCategory.value;
                var hasFrom = smartAltFrom && smartAltFrom.value;
                var hasTo = smartAltTo && smartAltTo.value;
                var hasPrice = smartAltPrice && smartAltPrice.value;
                var hasLeadTime = smartAltLeadTime && smartAltLeadTime.value;
                var hasComparisons = smartAltComparisons && smartAltComparisons.length > 0;
                
                if (hasCategory || hasFrom || hasTo || hasPrice || hasLeadTime || hasComparisons) {
                    var smartAltData = {
                        category: hasCategory ? smartAltCategory.value : '',
                        from: hasFrom ? smartAltFrom.value : '',
                        to: hasTo ? smartAltTo.value : '',
                        comparison_points: hasComparisons ? Array.from(smartAltComparisons).map(function(cb) { return cb.value; }) : [],
                        price_impact: hasPrice ? smartAltPrice.value : '',
                        lead_time_impact: hasLeadTime ? smartAltLeadTime.value : ''
                    };
                    formData.append('smart_alternatives_suggestion', JSON.stringify(smartAltData));
                }
                
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_validate_supplier_bid' ); ?>');
                
                // Disable submit button during validation
                var submitBtn = document.getElementById('n88-validate-bid-btn');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Validating...';
                }
                
                // Submit to server for validation
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Validate Bid';
                    }
                    
                    if (!data.success) {
                        // Show validation errors
                        if (data.data && data.data.errors) {
                            var errorHtml = '<div class="n88-validation-errors" style="padding: 12px; background-color: #fee; border: 1px solid #fcc; border-radius: 4px; margin-bottom: 20px;">' +
                                '<strong style="color: #d32f2f;">Validation Errors:</strong><ul style="margin: 8px 0 0 20px; padding: 0;">';
                            for (var field in data.data.errors) {
                                errorHtml += '<li style="color: #d32f2f; margin: 4px 0;">' + data.data.errors[field] + '</li>';
                            }
                            errorHtml += '</ul></div>';
                            
                            var form = document.getElementById('n88-bid-form');
                            if (form) {
                                var existingError = form.querySelector('.n88-validation-errors');
                                if (existingError) {
                                    existingError.remove();
                                }
                                form.insertAdjacentHTML('afterbegin', errorHtml);
                                
                                // Scroll to top
                                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        } else {
                            alert(data.data && data.data.message ? data.data.message : 'Validation failed. Please check your inputs.');
                        }
                    } else {
                        // Validation passed - show Submit Bid button (Commit 2.3.5)
                        var validateBtn = document.getElementById('n88-validate-bid-btn');
                        var submitBtn = document.getElementById('n88-submit-bid-btn');
                        
                        if (validateBtn && submitBtn) {
                            validateBtn.style.display = 'none';
                            submitBtn.style.display = 'inline-block';
                            submitBtn.disabled = false;
                        }
                        
                        // Show success message
                        var form = document.getElementById('n88-bid-form');
                        if (form) {
                            var existingError = form.querySelector('.n88-validation-errors');
                            if (existingError) {
                                existingError.remove();
                            }
                            var successHtml = '<div class="n88-validation-errors" style="padding: 12px; background-color: #e8f5e9; border: 1px solid #4caf50; border-radius: 4px; margin-bottom: 20px; color: #2e7d32;">' +
                                '<strong>✓ Validation successful!</strong> Click "Submit Bid" to save your bid.' +
                                '</div>';
                            form.insertAdjacentHTML('afterbegin', successHtml);
                            
                            // Scroll to top
                            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }
                })
                .catch(function(error) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Validate Bid';
                    }
                    alert('Error validating bid. Please try again.');
                    console.error('Validation error:', error);
                });
                
                return false;
            }
            
            // Commit 2.3.5: Submit bid function
            function submitBid(e) {
                e.preventDefault();
                
                var form = document.getElementById('n88-bid-form');
                if (!form) return false;
                
                var modal = document.getElementById('n88-supplier-bid-form-modal');
                var itemId = modal ? modal.getAttribute('data-item-id') : null;
                
                if (!itemId) {
                    alert('Item ID not found.');
                    return false;
                }
                
                // Collect form data (same as validation)
                var formData = new FormData();
                formData.append('action', 'n88_submit_supplier_bid');
                formData.append('item_id', itemId);
                
                // Video links
                var videoLinks = form.querySelectorAll('.n88-video-link-input');
                var videoLinksArray = [];
                videoLinks.forEach(function(input) {
                    var url = input.value.trim();
                    if (url) {
                        videoLinksArray.push(url);
                    }
                });
                formData.append('video_links', JSON.stringify(videoLinksArray));
                
                // Commit 2.3.5.1: Bid photos (required) - use uploaded photo IDs
                var bidPhotoIds = [];
                var photoIdInputs = form.querySelectorAll('input[name="bid_photo_ids[]"]');
                photoIdInputs.forEach(function(input) {
                    var photoId = parseInt(input.value);
                    if (!isNaN(photoId) && photoId > 0) {
                        bidPhotoIds.push(photoId);
                    }
                });
                formData.append('bid_photo_ids', JSON.stringify(bidPhotoIds));
                
                // Other fields
                formData.append('prototype_video_yes', form.querySelector('input[name="prototype_video_yes"]:checked') ? form.querySelector('input[name="prototype_video_yes"]:checked').value : '');
                formData.append('prototype_timeline_option', form.querySelector('select[name="prototype_timeline_option"]').value);
                formData.append('prototype_cost', form.querySelector('input[name="prototype_cost"]').value);
                formData.append('production_lead_time_text', form.querySelector('select[name="production_lead_time_text"]').value);
                formData.append('unit_price', form.querySelector('input[name="unit_price"]').value);
                
                // Smart Alternatives suggestion (if enabled and ANY field is filled)
                var smartAltCategory = form.querySelector('select[name="smart_alt_category"]');
                var smartAltFrom = form.querySelector('select[name="smart_alt_from"]');
                var smartAltTo = form.querySelector('select[name="smart_alt_to"]');
                var smartAltPrice = form.querySelector('select[name="smart_alt_price_impact"]');
                var smartAltLeadTime = form.querySelector('select[name="smart_alt_lead_time_impact"]');
                var smartAltComparisons = form.querySelectorAll('input[name="smart_alt_comparison[]"]:checked');
                
                // Check if ANY Smart Alternatives field has a value
                var hasCategory = smartAltCategory && smartAltCategory.value;
                var hasFrom = smartAltFrom && smartAltFrom.value;
                var hasTo = smartAltTo && smartAltTo.value;
                var hasPrice = smartAltPrice && smartAltPrice.value;
                var hasLeadTime = smartAltLeadTime && smartAltLeadTime.value;
                var hasComparisons = smartAltComparisons && smartAltComparisons.length > 0;
                
                if (hasCategory || hasFrom || hasTo || hasPrice || hasLeadTime || hasComparisons) {
                    var smartAltData = {
                        category: hasCategory ? smartAltCategory.value : '',
                        from: hasFrom ? smartAltFrom.value : '',
                        to: hasTo ? smartAltTo.value : '',
                        comparison_points: hasComparisons ? Array.from(smartAltComparisons).map(function(cb) { return cb.value; }) : [],
                        price_impact: hasPrice ? smartAltPrice.value : '',
                        lead_time_impact: hasLeadTime ? smartAltLeadTime.value : ''
                    };
                    formData.append('smart_alternatives_suggestion', JSON.stringify(smartAltData));
                }
                
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_submit_supplier_bid' ); ?>');
                
                // Disable submit button
                var submitBtn = document.getElementById('n88-submit-bid-btn');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Submitting...';
                }
                
                // Submit to server
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Bid';
                    }
                    
                    if (!data.success) {
                        // Show errors
                        if (data.data && data.data.errors) {
                            var errorHtml = '<div style="padding: 12px; background-color: #fee; border: 1px solid #fcc; border-radius: 4px; margin-bottom: 20px;">' +
                                '<strong style="color: #d32f2f;">Submission Errors:</strong><ul style="margin: 8px 0 0 20px; padding: 0;">';
                            for (var field in data.data.errors) {
                                errorHtml += '<li style="color: #d32f2f; margin: 4px 0;">' + data.data.errors[field] + '</li>';
                            }
                            errorHtml += '</ul></div>';
                            
                            var form = document.getElementById('n88-bid-form');
                            if (form) {
                                var existingError = form.querySelector('.n88-validation-errors');
                                if (existingError) {
                                    existingError.remove();
                                }
                                // Add class directly to HTML string
                                errorHtml = errorHtml.replace('<div style="', '<div class="n88-validation-errors" style="');
                                form.insertAdjacentHTML('afterbegin', errorHtml);
                                
                                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        } else {
                            alert(data.data && data.data.message ? data.data.message : 'Failed to submit bid. Please try again.');
                        }
                    } else {
                        // Success - close modal and refresh
                        alert(data.data && data.data.message || 'Bid submitted successfully!');
                        closeBidFormModal();
                        // Refresh the page to show updated status
                        if (window.location.href.indexOf('queue') > -1) {
                            window.location.reload();
                        }
                    }
                })
                .catch(function(error) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Bid';
                    }
                    alert('Error submitting bid. Please try again.');
                    console.error('Submission error:', error);
                });
                
                return false;
            }
            
            // Embedded form helper functions
            function addVideoLinkEmbedded(itemId) {
                var container = document.getElementById('n88-video-links-container-embedded-' + itemId);
                if (!container) return;
                
                var linkCount = container.querySelectorAll('.n88-video-link-input').length;
                
                if (linkCount >= 3) {
                    alert('Maximum 3 video links allowed.');
                    return;
                }
                
                var newLinkDiv = document.createElement('div');
                newLinkDiv.style.cssText = 'margin-bottom: 8px; display: flex; gap: 8px; align-items: center;';
                newLinkDiv.innerHTML = '<input type="url" name="video_links[]" class="n88-video-link-input-embedded" placeholder="https://youtube.com/watch?v=..." style="flex: 1; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; font-family: monospace;" onblur="validateVideoLinkEmbedded(this, ' + itemId + ');" oninput="validateBidFormEmbedded(' + itemId + ');" />' +
                    '<button type="button" onclick="removeVideoLinkEmbedded(this, ' + itemId + ')" style="padding: 8px 12px; background-color: #dc3545; color: #fff; border: none; border-radius: 2px; cursor: pointer; font-family: monospace; font-size: 11px;">Remove</button>';
                
                container.appendChild(newLinkDiv);
                updateVideoLinkButtonsEmbedded(itemId);
                validateBidFormEmbedded(itemId);
            }
            
            function handleBidPhotosChangeEmbedded(input, itemId) {
                var files = input.files;
                if (!files || files.length === 0) {
                    return;
                }
                
                // Validate file count (max 5)
                if (files.length > 5) {
                    alert('Maximum 5 photos allowed. Please select up to 5 photos.');
                    input.value = '';
                    return;
                }
                
                // Validate file types
                var imageFiles = Array.from(files).filter(function(file) {
                    return file.type.startsWith('image/');
                });
                
                if (imageFiles.length !== files.length) {
                    alert('Please select image files only.');
                    input.value = '';
                    return;
                }
                
                var previewContainer = document.getElementById('n88-bid-photos-preview-embedded-' + itemId);
                var errorDiv = document.getElementById('n88-bid-photos-error-embedded-' + itemId);
                
                if (!previewContainer) {
                    console.error('Bid photos preview container not found');
                    return;
                }
                
                // Clear previous previews
                previewContainer.innerHTML = '';
                
                // Get nonce for AJAX
                var nonce = '<?php echo wp_create_nonce( 'n88_upload_inspiration_image' ); ?>';
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                
                // Upload each file
                var uploadPromises = imageFiles.map(function(file, index) {
                    return new Promise(function(resolve, reject) {
                        var formData = new FormData();
                        formData.append('action', 'n88_upload_inspiration_image');
                        formData.append('inspiration_image', file);
                        formData.append('nonce', nonce);
                        
                        // Show loading placeholder
                        var placeholderId = 'n88-bid-photo-placeholder-embedded-' + itemId + '-' + index;
                        var placeholder = document.createElement('div');
                        placeholder.id = placeholderId;
                        placeholder.style.cssText = 'position: relative; width: 100px; height: 100px; border: 2px dashed #00ff00; border-radius: 4px; display: flex; align-items: center; justify-content: center; background-color: #1a1a1a;';
                        placeholder.innerHTML = '<div style="font-size: 11px; color: #00ff00; font-family: monospace;">Uploading...</div>';
                        previewContainer.appendChild(placeholder);
                        
                        fetch(ajaxUrl, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        })
                        .then(function(response) {
                            if (!response.ok) {
                                throw new Error('HTTP error! status: ' + response.status);
                            }
                            return response.json();
                        })
                        .then(function(data) {
                            // Remove placeholder
                            var placeholderEl = document.getElementById(placeholderId);
                            if (placeholderEl) {
                                placeholderEl.remove();
                            }
                            
                            if (!data) {
                                throw new Error('No response data received');
                            }
                            
                            if (data.success && data.data && data.data.id && data.data.url) {
                                // Create thumbnail
                                var thumbDiv = document.createElement('div');
                                thumbDiv.style.cssText = 'position: relative; width: 100px; height: 100px; border: 2px solid #00ff00; border-radius: 4px; overflow: hidden;';
                                thumbDiv.innerHTML = '<img src="' + data.data.url.replace(/"/g, '&quot;') + '" style="width: 100%; height: 100%; object-fit: cover;" alt="Bid photo" />' +
                                    '<button type="button" onclick="removeBidPhotoEmbedded(this, ' + data.data.id + ', ' + itemId + ');" style="position: absolute; top: 4px; right: 4px; background: rgba(220, 53, 69, 0.9); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center;" title="Remove">×</button>';
                                
                                // Add hidden input INSIDE THE FORM
                                var form = document.getElementById('n88-bid-form-embedded-' + itemId);
                                if (form) {
                                    var hiddenInput = document.createElement('input');
                                    hiddenInput.type = 'hidden';
                                    hiddenInput.name = 'bid_photo_ids[]';
                                    hiddenInput.value = data.data.id;
                                    hiddenInput.setAttribute('data-photo-id', data.data.id);
                                    form.appendChild(hiddenInput);
                                    console.log('✓ Added hidden input to embedded form - Photo ID:', data.data.id);
                                } else {
                                    console.error('✗ Embedded form #n88-bid-form-embedded-' + itemId + ' not found when adding photo!');
                                }
                                
                                // Store reference in thumbDiv for easy removal
                                thumbDiv.setAttribute('data-photo-id', data.data.id);
                                previewContainer.appendChild(thumbDiv);
                                
                                resolve({ id: data.data.id, url: data.data.url });
                            } else {
                                var errorMsg = data.data && data.data.message ? data.data.message : 'Upload failed';
                                throw new Error(errorMsg);
                            }
                        })
                        .catch(function(error) {
                            // Remove placeholder
                            var placeholderEl = document.getElementById(placeholderId);
                            if (placeholderEl) {
                                placeholderEl.remove();
                            }
                            
                            console.error('Error uploading photo:', error);
                            if (errorDiv) {
                                errorDiv.textContent = 'Failed to upload ' + file.name + ': ' + error.message;
                                errorDiv.style.display = 'block';
                            }
                            reject(error);
                        });
                    });
                });
                
                // Clear error on success
                Promise.all(uploadPromises).then(function(results) {
                    if (errorDiv) {
                        errorDiv.style.display = 'none';
                    }
                    
                    // Force validation after a delay to ensure DOM is fully updated
                    setTimeout(function() {
                        if (typeof validateBidFormEmbedded === 'function') {
                            validateBidFormEmbedded(itemId);
                        }
                    }, 300);
                }).catch(function() {
                    // Errors already handled above
                });
            }
            
            function removeBidPhotoEmbedded(button, photoId, itemId) {
                var thumbDiv = button.parentElement;
                var form = document.getElementById('n88-bid-form-embedded-' + itemId);
                
                if (form) {
                    var hiddenInput = form.querySelector('input[name="bid_photo_ids[]"][value="' + photoId + '"]');
                    if (hiddenInput) {
                        hiddenInput.remove();
                    }
                }
                
                thumbDiv.remove();
                
                var previewContainer = document.getElementById('n88-bid-photos-preview-embedded-' + itemId);
                var remainingPhotos = previewContainer ? previewContainer.querySelectorAll('div[data-photo-id]').length : 0;
                if (remainingPhotos === 0) {
                    var input = document.getElementById('n88-bid-photos-input-embedded-' + itemId);
                    if (input) {
                        input.value = '';
                    }
                }
                
                validateBidFormEmbedded(itemId);
            }
            
            // Validate embedded bid form
            function validateBidFormEmbedded(itemId) {
                var form = document.getElementById('n88-bid-form-embedded-' + itemId);
                if (!form) return false;
                
                var isValid = true;
                var validateBtn = document.getElementById('n88-validate-bid-btn-embedded-' + itemId);
                
                // 1. Video links: optional, max 3
                var videoLinks = form.querySelectorAll('.n88-video-link-input-embedded');
                var validVideoLinks = 0;
                var allowedDomains = [
                    'youtube.com', 'youtu.be', 'www.youtube.com',
                    'vimeo.com', 'www.vimeo.com',
                    'loom.com', 'www.loom.com'
                ];
                
                videoLinks.forEach(function(input) {
                    var url = input.value.trim();
                    if (url) {
                        try {
                            var urlObj = new URL(url);
                            var hostname = urlObj.hostname.toLowerCase().replace('www.', '');
                            var isAllowed = allowedDomains.some(function(domain) {
                                var domainClean = domain.replace('www.', '');
                                return hostname === domainClean || hostname.endsWith('.' + domainClean);
                            });
                            if (isAllowed) {
                                validVideoLinks++;
                            }
                        } catch (e) {
                            // Invalid URL format
                        }
                    }
                });
                
                if (validVideoLinks > 3) {
                    isValid = false;
                }
                
                // 1.5. Bid Photos: required, min 1, max 5
                var photoIdInputs = form.querySelectorAll('input[name="bid_photo_ids[]"]');
                var bidPhotosCount = 0;
                photoIdInputs.forEach(function(input) {
                    var photoId = parseInt(input.value);
                    if (!isNaN(photoId) && photoId > 0) {
                        bidPhotosCount++;
                    }
                });
                
                var photosError = document.getElementById('n88-bid-photos-error-embedded-' + itemId);
                if (bidPhotosCount < 1) {
                    isValid = false;
                    if (photosError) {
                        photosError.textContent = 'At least 1 photo is required.';
                        photosError.style.display = 'block';
                    }
                } else if (bidPhotosCount > 5) {
                    isValid = false;
                    if (photosError) {
                        photosError.textContent = 'Maximum 5 photos allowed.';
                        photosError.style.display = 'block';
                    }
                } else {
                    if (photosError) {
                        photosError.style.display = 'none';
                    }
                }
                
                // 2. Prototype video (optional - YES or NO)
                var prototypeYes = form.querySelector('input[name="prototype_video_yes"][value="1"]');
                var isPrototypeYes = prototypeYes && prototypeYes.checked;
                
                // 3. Prototype timeline required ONLY if prototype is YES
                var timeline = form.querySelector('select[name="prototype_timeline_option"]');
                if (isPrototypeYes && (!timeline || !timeline.value)) {
                    isValid = false;
                }
                
                // 4. Prototype cost required ONLY if prototype is YES
                var prototypeCost = form.querySelector('input[name="prototype_cost"]');
                if (isPrototypeYes) {
                    if (prototypeCost && prototypeCost.value) {
                        var costValue = parseFloat(prototypeCost.value);
                        if (isNaN(costValue) || costValue < 0) {
                            isValid = false;
                        }
                    } else {
                        isValid = false;
                    }
                }
                
                // 5. Production lead time: non-empty
                var leadTime = form.querySelector('select[name="production_lead_time_text"]');
                if (!leadTime || !leadTime.value || !leadTime.value.trim()) {
                    isValid = false;
                }
                
                // 6. Unit price: numeric > 0
                var unitPrice = form.querySelector('input[name="unit_price"]');
                if (unitPrice && unitPrice.value) {
                    var priceValue = parseFloat(unitPrice.value);
                    if (isNaN(priceValue) || priceValue <= 0) {
                        isValid = false;
                    }
                } else {
                    isValid = false;
                }
                
                // Enable/disable validate button
                if (validateBtn) {
                    if (isValid) {
                        validateBtn.disabled = false;
                        validateBtn.style.opacity = '1';
                        validateBtn.style.cursor = 'pointer';
                    } else {
                        validateBtn.disabled = true;
                        validateBtn.style.opacity = '0.5';
                        validateBtn.style.cursor = 'not-allowed';
                    }
                }
                
                return isValid;
            }
            
            // Add video link for embedded form
            function addVideoLinkEmbedded(itemId) {
                var container = document.getElementById('n88-video-links-container-embedded-' + itemId);
                if (!container) return;
                
                var linkCount = container.querySelectorAll('.n88-video-link-input-embedded').length;
                
                if (linkCount >= 3) {
                    alert('Maximum 3 video links allowed.');
                    return;
                }
                
                var newLinkDiv = document.createElement('div');
                newLinkDiv.style.cssText = 'margin-bottom: 8px; display: flex; gap: 8px; align-items: center;';
                var linkNum = linkCount + 1;
                newLinkDiv.innerHTML = '<span style="color: #00ff00; font-family: monospace; font-size: 12px;">' + linkNum + ')</span>' +
                    '<input type="url" name="video_links[]" class="n88-video-link-input-embedded" placeholder="https://youtube.com/watch?v=..." style="flex: 1; padding: 8px 12px; border: none; border-radius: 2px; font-size: 12px; background-color: #1a1a1a; color: #fff; font-family: monospace;" onblur="validateVideoLinkEmbedded(this, ' + itemId + ');" oninput="validateBidFormEmbedded(' + itemId + ');" />' +
                    '<button type="button" onclick="removeVideoLinkEmbedded(this, ' + itemId + ')" style="padding: 8px 12px; background-color: #dc3545; color: #fff; border: none; border-radius: 2px; cursor: pointer; font-family: monospace; font-size: 11px;">Remove</button>';
                
                container.appendChild(newLinkDiv);
                updateVideoLinkButtonsEmbedded(itemId);
                validateBidFormEmbedded(itemId);
            }
            
            // Remove video link for embedded form
            function removeVideoLinkEmbedded(button, itemId) {
                var container = document.getElementById('n88-video-links-container-embedded-' + itemId);
                if (!container) return;
                
                var linkDiv = button.parentElement;
                container.removeChild(linkDiv);
                updateVideoLinkButtonsEmbedded(itemId);
                validateBidFormEmbedded(itemId);
            }
            
            // Update video link buttons for embedded form
            function updateVideoLinkButtonsEmbedded(itemId) {
                var container = document.getElementById('n88-video-links-container-embedded-' + itemId);
                if (!container) return;
                
                var links = container.querySelectorAll('.n88-video-link-input-embedded');
                var removeButtons = container.querySelectorAll('button[onclick*="removeVideoLinkEmbedded"]');
                
                if (links.length > 1) {
                    removeButtons.forEach(function(btn) { btn.style.display = 'block'; });
                } else {
                    removeButtons.forEach(function(btn) { btn.style.display = 'none'; });
                }
                
                var addBtn = document.getElementById('n88-add-video-link-btn-embedded-' + itemId);
                if (addBtn) {
                    addBtn.style.display = links.length >= 3 ? 'none' : 'block';
                }
            }
            
            // Validate individual video link for embedded form
            function validateVideoLinkEmbedded(input, itemId) {
                var url = input.value.trim();
                var errorDiv = document.getElementById('n88-video-links-error-embedded-' + itemId);
                
                if (!url) {
                    input.style.borderColor = '#00ff00';
                    return true;
                }
                
                var allowedDomains = [
                    'youtube.com', 'youtu.be', 'www.youtube.com',
                    'vimeo.com', 'www.vimeo.com',
                    'loom.com', 'www.loom.com'
                ];
                
                var urlObj;
                try {
                    urlObj = new URL(url);
                } catch (e) {
                    input.style.borderColor = '#ff0000';
                    if (errorDiv) {
                        errorDiv.textContent = 'Invalid URL format.';
                        errorDiv.style.display = 'block';
                    }
                    return false;
                }
                
                var hostname = urlObj.hostname.toLowerCase().replace('www.', '');
                var isValid = allowedDomains.some(function(domain) {
                    return hostname === domain || hostname.endsWith('.' + domain);
                });
                
                if (!isValid) {
                    input.style.borderColor = '#ff0000';
                    if (errorDiv) {
                        errorDiv.textContent = 'Only YouTube, Vimeo, or Loom links are allowed.';
                        errorDiv.style.display = 'block';
                    }
                    return false;
                }
                
                input.style.borderColor = '#00ff00';
                if (errorDiv) {
                    errorDiv.style.display = 'none';
                }
                validateBidFormEmbedded(itemId);
                return true;
            }
            
            // Save bid draft function - stores draft in user meta
            function saveBidDraftEmbedded(itemId) {
                var form = document.getElementById('n88-bid-form-embedded-' + itemId);
                if (!form) {
                    alert('Bid form not found.');
                    return;
                }
                
                // Collect form data
                var formData = new FormData();
                formData.append('action', 'n88_save_bid_draft');
                formData.append('item_id', itemId);
                
                // Video links
                var videoLinks = form.querySelectorAll('.n88-video-link-input-embedded');
                var videoLinksArray = [];
                videoLinks.forEach(function(input) {
                    var url = input.value.trim();
                    if (url) {
                        videoLinksArray.push(url);
                    }
                });
                formData.append('video_links', JSON.stringify(videoLinksArray));
                
                // Bid photos (photo IDs)
                var bidPhotoIds = [];
                var photoIdInputs = form.querySelectorAll('input[name="bid_photo_ids[]"]');
                photoIdInputs.forEach(function(input) {
                    var photoId = parseInt(input.value);
                    if (!isNaN(photoId) && photoId > 0) {
                        bidPhotoIds.push(photoId);
                    }
                });
                formData.append('bid_photo_ids', JSON.stringify(bidPhotoIds));
                
                // Other fields
                var prototypeVideoYes = form.querySelector('input[name="prototype_video_yes"]:checked');
                formData.append('prototype_video_yes', prototypeVideoYes ? prototypeVideoYes.value : '');
                formData.append('prototype_timeline_option', form.querySelector('select[name="prototype_timeline_option"]') ? form.querySelector('select[name="prototype_timeline_option"]').value : '');
                formData.append('prototype_cost', form.querySelector('input[name="prototype_cost"]') ? form.querySelector('input[name="prototype_cost"]').value : '');
                formData.append('production_lead_time_text', form.querySelector('select[name="production_lead_time_text"]') ? form.querySelector('select[name="production_lead_time_text"]').value : '');
                formData.append('unit_price', form.querySelector('input[name="unit_price"]') ? form.querySelector('input[name="unit_price"]').value : '');
                
                // Smart Alternatives (if enabled and ANY field is filled)
                var smartAltCategory = form.querySelector('select[name="smart_alt_category"]');
                var smartAltFrom = form.querySelector('select[name="smart_alt_from"]');
                var smartAltTo = form.querySelector('select[name="smart_alt_to"]');
                var smartAltPrice = form.querySelector('select[name="smart_alt_price_impact"]');
                var smartAltLeadTime = form.querySelector('select[name="smart_alt_lead_time_impact"]');
                var smartAltComparisons = form.querySelectorAll('.n88-smart-alt-checkbox:checked');
                var comparisonValues = [];
                smartAltComparisons.forEach(function(cb) {
                    comparisonValues.push(cb.value);
                });
                
                // Check if ANY Smart Alternatives field has a value
                var hasCategory = smartAltCategory && smartAltCategory.value;
                var hasFrom = smartAltFrom && smartAltFrom.value;
                var hasTo = smartAltTo && smartAltTo.value;
                var hasPrice = smartAltPrice && smartAltPrice.value;
                var hasLeadTime = smartAltLeadTime && smartAltLeadTime.value;
                var hasComparisons = comparisonValues.length > 0;
                
                if (hasCategory || hasFrom || hasTo || hasPrice || hasLeadTime || hasComparisons) {
                    var smartAltData = {
                        category: hasCategory ? smartAltCategory.value : '',
                        from: hasFrom ? smartAltFrom.value : '',
                        to: hasTo ? smartAltTo.value : '',
                        price_impact: hasPrice ? smartAltPrice.value : '',
                        lead_time_impact: hasLeadTime ? smartAltLeadTime.value : '',
                        comparison_points: hasComparisons ? comparisonValues : []
                    };
                    formData.append('smart_alternatives_suggestion', JSON.stringify(smartAltData));
                }
                
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_save_bid_draft' ); ?>');
                
                // Show saving indicator
                var saveBtn = form.querySelector('button[onclick*="saveBidDraftEmbedded"]');
                var originalText = saveBtn ? saveBtn.textContent : '';
                if (saveBtn) {
                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Saving...';
                }
                
                // Save draft via AJAX
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = originalText;
                    }
                    
                    if (data.success) {
                        // Show success message
                        var successMsg = document.createElement('div');
                        successMsg.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 12px 20px; background-color: #00ff00; color: #000; border-radius: 4px; font-family: monospace; font-size: 12px; z-index: 100000; box-shadow: 0 2px 8px rgba(0,0,0,0.3);';
                        successMsg.textContent = '✓ Draft saved successfully';
                        document.body.appendChild(successMsg);
                        
                        // Remove message after 3 seconds
                        setTimeout(function() {
                            if (successMsg.parentNode) {
                                successMsg.parentNode.removeChild(successMsg);
                            }
                        }, 3000);
                        
                        // Update button text from "[ Start Bid ]" to "[ Continue Bid ]" after saving draft
                        var toggleBtn = document.getElementById('n88-toggle-bid-form-btn-' + itemId);
                        if (toggleBtn) {
                            toggleBtn.textContent = '[ Continue Bid ]';
                        }
                        
                        // Update window.currentItemData to reflect draft status for button text logic
                        if (window.currentItemData && window.currentItemData.item_id == itemId) {
                            window.currentItemData.bid_status = 'draft';
                        }
                    } else {
                        alert('Failed to save draft: ' + (data.data && data.data.message ? data.data.message : 'Unknown error'));
                    }
                })
                .catch(function(error) {
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = originalText;
                    }
                    console.error('Error saving draft:', error);
                    alert('Error saving draft. Please try again.');
                });
            }
            
            function validateAndSubmitBidEmbedded(event, itemId) {
                if (event) {
                    event.preventDefault();
                }
                
                var form = document.getElementById('n88-bid-form-embedded-' + itemId);
                if (!form) return false;
                
                // Client-side validation
                if (!validateBidFormEmbedded(itemId)) {
                    alert('Please complete all required fields correctly.');
                    return false;
                }
                
                if (!itemId) {
                    alert('Item ID not found.');
                    return false;
                }
                
                // Collect form data
                var formData = new FormData();
                formData.append('action', 'n88_validate_supplier_bid');
                formData.append('item_id', itemId);
                
                // Video links
                var videoLinks = form.querySelectorAll('.n88-video-link-input');
                var videoLinksArray = [];
                videoLinks.forEach(function(input) {
                    var url = input.value.trim();
                    if (url) {
                        videoLinksArray.push(url);
                    }
                });
                formData.append('video_links', JSON.stringify(videoLinksArray));
                
                // Bid photos
                var bidPhotoIds = [];
                var photoIdInputs = form.querySelectorAll('input[name="bid_photo_ids[]"]');
                photoIdInputs.forEach(function(input) {
                    var photoId = parseInt(input.value);
                    if (!isNaN(photoId) && photoId > 0) {
                        bidPhotoIds.push(photoId);
                    }
                });
                formData.append('bid_photo_ids', JSON.stringify(bidPhotoIds));
                
                // Other fields
                formData.append('prototype_video_yes', form.querySelector('input[name="prototype_video_yes"]:checked') ? form.querySelector('input[name="prototype_video_yes"]:checked').value : '');
                formData.append('prototype_timeline_option', form.querySelector('select[name="prototype_timeline_option"]').value);
                formData.append('prototype_cost', form.querySelector('input[name="prototype_cost"]').value);
                formData.append('production_lead_time_text', form.querySelector('select[name="production_lead_time_text"]').value);
                formData.append('unit_price', form.querySelector('input[name="unit_price"]').value);
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_validate_supplier_bid' ); ?>');
                
                // Disable submit button during validation
                var submitBtn = document.getElementById('n88-validate-bid-btn-embedded-' + itemId);
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Validating...';
                }
                
                // Submit to server for validation
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Validate Bid';
                    }
                    
                    if (!data.success) {
                        if (data.data && data.data.errors) {
                            var errorHtml = '<div class="n88-validation-errors" style="padding: 12px; background-color: #fee; border: 1px solid #fcc; border-radius: 4px; margin-bottom: 20px;">' +
                                '<strong style="color: #d32f2f;">Validation Errors:</strong><ul style="margin: 8px 0 0 20px; padding: 0;">';
                            for (var field in data.data.errors) {
                                errorHtml += '<li style="color: #d32f2f; margin: 4px 0;">' + data.data.errors[field] + '</li>';
                            }
                            errorHtml += '</ul></div>';
                            
                            if (form) {
                                var existingError = form.querySelector('.n88-validation-errors');
                                if (existingError) {
                                    existingError.remove();
                                }
                                form.insertAdjacentHTML('afterbegin', errorHtml);
                                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        } else {
                            alert(data.data && data.data.message ? data.data.message : 'Validation failed. Please check your inputs.');
                        }
                    } else {
                        // Validation passed - show Submit Bid button
                        var validateBtn = document.getElementById('n88-validate-bid-btn-embedded-' + itemId);
                        var submitBtn = document.getElementById('n88-submit-bid-btn-embedded-' + itemId);
                        
                        if (validateBtn && submitBtn) {
                            validateBtn.style.display = 'none';
                            submitBtn.style.display = 'inline-block';
                            submitBtn.disabled = false;
                        }
                        
                        // Show success message
                        if (form) {
                            var existingError = form.querySelector('.n88-validation-errors');
                            if (existingError) {
                                existingError.remove();
                            }
                            var successHtml = '<div class="n88-validation-errors" style="padding: 12px; background-color: #e8f5e9; border: 1px solid #4caf50; border-radius: 4px; margin-bottom: 20px; color: #2e7d32;">' +
                                '<strong>✓ Validation successful!</strong> Click "Submit Bid" to save your bid.' +
                                '</div>';
                            form.insertAdjacentHTML('afterbegin', successHtml);
                            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }
                })
                .catch(function(error) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Validate Bid';
                    }
                    alert('Error validating bid. Please try again.');
                    console.error('Validation error:', error);
                });
                
                return false;
            }
            
            function submitBidEmbedded(event, itemId) {
                if (event) {
                    event.preventDefault();
                }
                
                var form = document.getElementById('n88-bid-form-embedded-' + itemId);
                if (!form) return false;
                
                if (!itemId) {
                    alert('Item ID not found.');
                    return false;
                }
                
                // Collect form data
                var formData = new FormData();
                formData.append('action', 'n88_submit_supplier_bid');
                formData.append('item_id', itemId);
                
                // Video links (use embedded class)
                var videoLinks = form.querySelectorAll('.n88-video-link-input-embedded');
                var videoLinksArray = [];
                videoLinks.forEach(function(input) {
                    var url = input.value.trim();
                    if (url) {
                        videoLinksArray.push(url);
                    }
                });
                formData.append('video_links', JSON.stringify(videoLinksArray));
                
                // Bid photos
                var bidPhotoIds = [];
                var photoIdInputs = form.querySelectorAll('input[name="bid_photo_ids[]"]');
                photoIdInputs.forEach(function(input) {
                    var photoId = parseInt(input.value);
                    if (!isNaN(photoId) && photoId > 0) {
                        bidPhotoIds.push(photoId);
                    }
                });
                formData.append('bid_photo_ids', JSON.stringify(bidPhotoIds));
                
                // Other fields
                formData.append('prototype_video_yes', form.querySelector('input[name="prototype_video_yes"]:checked') ? form.querySelector('input[name="prototype_video_yes"]:checked').value : '');
                formData.append('prototype_timeline_option', form.querySelector('select[name="prototype_timeline_option"]').value);
                formData.append('prototype_cost', form.querySelector('input[name="prototype_cost"]').value);
                formData.append('production_lead_time_text', form.querySelector('select[name="production_lead_time_text"]').value);
                formData.append('unit_price', form.querySelector('input[name="unit_price"]').value);
                
                // Smart Alternatives suggestion (if enabled and ANY field is filled)
                var smartAltCategory = form.querySelector('select[name="smart_alt_category"]');
                var smartAltFrom = form.querySelector('select[name="smart_alt_from"]');
                var smartAltTo = form.querySelector('select[name="smart_alt_to"]');
                var smartAltPrice = form.querySelector('select[name="smart_alt_price_impact"]');
                var smartAltLeadTime = form.querySelector('select[name="smart_alt_lead_time_impact"]');
                var smartAltComparisons = form.querySelectorAll('input[name="smart_alt_comparison[]"]:checked');
                
                // Check if ANY Smart Alternatives field has a value
                var hasCategory = smartAltCategory && smartAltCategory.value;
                var hasFrom = smartAltFrom && smartAltFrom.value;
                var hasTo = smartAltTo && smartAltTo.value;
                var hasPrice = smartAltPrice && smartAltPrice.value;
                var hasLeadTime = smartAltLeadTime && smartAltLeadTime.value;
                var hasComparisons = smartAltComparisons && smartAltComparisons.length > 0;
                
                if (hasCategory || hasFrom || hasTo || hasPrice || hasLeadTime || hasComparisons) {
                    var smartAltData = {
                        category: hasCategory ? smartAltCategory.value : '',
                        from: hasFrom ? smartAltFrom.value : '',
                        to: hasTo ? smartAltTo.value : '',
                        comparison_points: hasComparisons ? Array.from(smartAltComparisons).map(function(cb) { return cb.value; }) : [],
                        price_impact: hasPrice ? smartAltPrice.value : '',
                        lead_time_impact: hasLeadTime ? smartAltLeadTime.value : ''
                    };
                    formData.append('smart_alternatives_suggestion', JSON.stringify(smartAltData));
                }
                
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_submit_supplier_bid' ); ?>');
                
                // Disable submit button
                var submitBtn = document.getElementById('n88-submit-bid-btn-embedded-' + itemId);
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Submitting...';
                }
                
                // Submit to server
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Bid';
                    }
                    
                    if (!data.success) {
                        if (data.data && data.data.errors) {
                            var errorHtml = '<div style="padding: 12px; background-color: #fee; border: 1px solid #fcc; border-radius: 4px; margin-bottom: 20px;">' +
                                '<strong style="color: #d32f2f;">Submission Errors:</strong><ul style="margin: 8px 0 0 20px; padding: 0;">';
                            for (var field in data.data.errors) {
                                errorHtml += '<li style="color: #d32f2f; margin: 4px 0;">' + data.data.errors[field] + '</li>';
                            }
                            errorHtml += '</ul></div>';
                            
                            if (form) {
                                var existingError = form.querySelector('.n88-validation-errors');
                                if (existingError) {
                                    existingError.remove();
                                }
                                errorHtml = errorHtml.replace('<div style="', '<div class="n88-validation-errors" style="');
                                form.insertAdjacentHTML('afterbegin', errorHtml);
                                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        } else {
                            alert(data.data && data.data.message ? data.data.message : 'Failed to submit bid. Please try again.');
                        }
                    } else {
                        // Success - close bid form section and refresh
                        alert(data.data && data.data.message || 'Bid submitted successfully!');
                        toggleBidForm(itemId);
                        // Refresh the modal to show updated status
                        openBidModal(itemId);
                    }
                })
                .catch(function(error) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Bid';
                    }
                    alert('Error submitting bid. Please try again.');
                    console.error('Submission error:', error);
                });
                
                return false;
            }
            
            // Load bid draft from user meta
            function loadBidDraft(itemId) {
                // Wait a bit to ensure form is fully rendered
                setTimeout(function() {
                    var form = document.getElementById('n88-bid-form-embedded-' + itemId);
                    if (!form) {
                        console.log('Form not found, skipping draft load');
                        return;
                    }
                    
                    var formData = new FormData();
                    formData.append('action', 'n88_get_bid_draft');
                    formData.append('item_id', itemId);
                    formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_get_bid_draft' ); ?>');
                    
                    // Add timeout to prevent hanging
                    var timeoutPromise = new Promise(function(resolve, reject) {
                        setTimeout(function() {
                            reject(new Error('Request timeout'));
                        }, 10000); // 10 second timeout
                    });
                    
                    Promise.race([
                        fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        }),
                        timeoutPromise
                    ])
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('HTTP error! status: ' + response.status);
                        }
                        // Check if response is JSON
                        var contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            throw new Error('Response is not JSON');
                        }
                        return response.json();
                    })
                    .then(function(data) {
                    if (data.success && data.data && data.data.draft) {
                        var draft = data.data.draft;
                        var form = document.getElementById('n88-bid-form-embedded-' + itemId);
                        if (!form) return;
                        
                        // Restore video links
                        if (draft.video_links && draft.video_links.length > 0) {
                            draft.video_links.forEach(function(url, index) {
                                if (index === 0) {
                                    var firstInput = form.querySelector('.n88-video-link-input-embedded');
                                    if (firstInput) {
                                        firstInput.value = url;
                                    }
                                } else {
                                    addVideoLinkEmbedded(itemId);
                                    var inputs = form.querySelectorAll('.n88-video-link-input-embedded');
                                    if (inputs[index]) {
                                        inputs[index].value = url;
                                    }
                                }
                            });
                        }
                        
                        // Restore form fields
                        if (draft.prototype_video_yes) {
                            var radio = form.querySelector('input[name="prototype_video_yes"][value="' + draft.prototype_video_yes + '"]');
                            if (radio) radio.checked = true;
                        }
                        if (draft.prototype_timeline_option) {
                            var timelineSelect = form.querySelector('select[name="prototype_timeline_option"]');
                            if (timelineSelect) timelineSelect.value = draft.prototype_timeline_option;
                        }
                        if (draft.prototype_cost) {
                            var costInput = form.querySelector('input[name="prototype_cost"]');
                            if (costInput) costInput.value = draft.prototype_cost;
                        }
                        if (draft.production_lead_time_text) {
                            var leadTimeSelect = form.querySelector('select[name="production_lead_time_text"]');
                            if (leadTimeSelect) leadTimeSelect.value = draft.production_lead_time_text;
                        }
                        if (draft.unit_price) {
                            var priceInput = form.querySelector('input[name="unit_price"]');
                            if (priceInput) priceInput.value = draft.unit_price;
                        }
                        
                        // Restore Smart Alternatives
                        if (draft.smart_alternatives_suggestion) {
                            var sa = draft.smart_alternatives_suggestion;
                            if (sa.category) {
                                var catSelect = form.querySelector('select[name="smart_alt_category"]');
                                if (catSelect) catSelect.value = sa.category;
                            }
                            if (sa.from) {
                                var fromSelect = form.querySelector('select[name="smart_alt_from"]');
                                if (fromSelect) fromSelect.value = sa.from;
                            }
                            if (sa.to) {
                                var toSelect = form.querySelector('select[name="smart_alt_to"]');
                                if (toSelect) toSelect.value = sa.to;
                            }
                            if (sa.price_impact) {
                                var priceSelect = form.querySelector('select[name="smart_alt_price_impact"]');
                                if (priceSelect) priceSelect.value = sa.price_impact;
                            }
                            if (sa.lead_time_impact) {
                                var leadSelect = form.querySelector('select[name="smart_alt_lead_time_impact"]');
                                if (leadSelect) leadSelect.value = sa.lead_time_impact;
                            }
                            if (sa.comparisons && sa.comparisons.length > 0) {
                                sa.comparisons.forEach(function(comp) {
                                    var checkbox = form.querySelector('input[type="checkbox"][value="' + comp + '"]');
                                    if (checkbox) checkbox.checked = true;
                                });
                            }
                            if (typeof updateSmartAltPreview === 'function') {
                                updateSmartAltPreview(itemId);
                            }
                        }
                        
                        // Restore photos (H) - restore from URLs with proper image display
                        var previewContainer = document.getElementById('n88-bid-photos-preview-embedded-' + itemId);
                        if (previewContainer) {
                            previewContainer.innerHTML = '';
                            if (draft.bid_photo_urls && draft.bid_photo_urls.length > 0) {
                                draft.bid_photo_urls.forEach(function(photo) {
                                    var thumbDiv = document.createElement('div');
                                    thumbDiv.style.cssText = 'position: relative; width: 100px; height: 100px; border: 2px solid #00ff00; border-radius: 4px; overflow: hidden; margin: 5px; display: inline-block;';
                                    thumbDiv.innerHTML = '<img src="' + photo.url.replace(/"/g, '&quot;') + '" style="width: 100%; height: 100%; object-fit: cover;" alt="Bid photo" />' +
                                        '<button type="button" onclick="removeBidPhotoEmbedded(this, ' + (photo.id || 'null') + ', ' + itemId + ');" style="position: absolute; top: 4px; right: 4px; background: rgba(255, 0, 0, 0.8); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center;" title="Remove">×</button>';
                                    thumbDiv.setAttribute('data-photo-id', photo.id || '');
                                    previewContainer.appendChild(thumbDiv);
                                    
                                    // Add hidden input for photo ID
                                    if (photo.id) {
                                        var hiddenInput = document.createElement('input');
                                        hiddenInput.type = 'hidden';
                                        hiddenInput.name = 'bid_photo_ids[]';
                                        hiddenInput.value = photo.id;
                                        hiddenInput.setAttribute('data-photo-id', photo.id);
                                        form.appendChild(hiddenInput);
                                    }
                                });
                            } else if (draft.bid_photos && draft.bid_photos.length > 0) {
                                // Fallback: restore from bid_photos URLs
                                draft.bid_photos.forEach(function(photoUrl) {
                                    var thumbDiv = document.createElement('div');
                                    thumbDiv.style.cssText = 'position: relative; width: 100px; height: 100px; border: 2px solid #00ff00; border-radius: 4px; overflow: hidden; margin: 5px; display: inline-block;';
                                    thumbDiv.innerHTML = '<img src="' + photoUrl.replace(/"/g, '&quot;') + '" style="width: 100%; height: 100%; object-fit: cover;" alt="Bid photo" />' +
                                        '<button type="button" onclick="removeBidPhotoEmbedded(this, null, ' + itemId + ');" style="position: absolute; top: 4px; right: 4px; background: rgba(255, 0, 0, 0.8); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center;" title="Remove">×</button>';
                                    previewContainer.appendChild(thumbDiv);
                                });
                            }
                        }
                        
                        // Trigger validation
                        if (typeof validateBidFormEmbedded === 'function') {
                            validateBidFormEmbedded(itemId);
                        }
                        
                        // Show notification that draft was loaded
                        var notification = document.createElement('div');
                        notification.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 12px 20px; background-color: #00ff00; color: #000; border-radius: 4px; font-family: monospace; font-size: 12px; z-index: 100000; box-shadow: 0 2px 8px rgba(0,0,0,0.3);';
                        notification.textContent = '✓ Draft loaded (saved ' + (draft.saved_at ? new Date(draft.saved_at).toLocaleString() : 'previously') + ')';
                        document.body.appendChild(notification);
                        setTimeout(function() {
                            if (notification.parentNode) {
                                notification.parentNode.removeChild(notification);
                            }
                        }, 4000);
                    }
                })
                .catch(function(error) {
                    // Silently fail - draft loading is optional and shouldn't break the form
                    console.log('Draft not available or error loading draft:', error.message || error);
                });
                }, 500); // Wait 500ms for form to be fully rendered
            }
            
            // H) Load draft for modal form (n88-bid-form)
            function loadBidDraftModal(itemId, itemData) {
                var form = document.getElementById('n88-bid-form');
                if (!form) {
                    console.log('Modal form not found, skipping draft load');
                    return;
                }
                
                // First, try to load from submitted bid data (if specs changed and supplier needs to resubmit)
                if (itemData && itemData.has_revision_mismatch) {
                    // Use latest_stale_bid_data if available, otherwise use bid_data
                    var staleBidData = itemData.latest_stale_bid_data || itemData.bid_data;
                    if (staleBidData) {
                        restoreBidDataToForm(form, staleBidData, itemId);
                    return;
                    }
                }
                
                // Otherwise, load from draft
                var formData = new FormData();
                formData.append('action', 'n88_get_bid_draft');
                formData.append('item_id', itemId);
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_get_bid_draft' ); ?>');
                
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (data.success && data.data && data.data.draft) {
                        var draft = data.data.draft;
                        restoreBidDataToForm(form, draft, itemId);
                        
                        // Show notification
                        var notification = document.createElement('div');
                        notification.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 12px 20px; background-color: #00ff00; color: #000; border-radius: 4px; font-family: monospace; font-size: 12px; z-index: 100000; box-shadow: 0 2px 8px rgba(0,0,0,0.3);';
                        notification.textContent = '✓ Draft loaded (saved ' + (draft.saved_at ? new Date(draft.saved_at).toLocaleString() : 'previously') + ')';
                        document.body.appendChild(notification);
                        setTimeout(function() {
                            if (notification.parentNode) {
                                notification.parentNode.removeChild(notification);
                            }
                        }, 4000);
                    }
                })
                .catch(function(error) {
                    console.log('Draft not available or error loading draft:', error.message || error);
                });
            }
            
            // H) Restore bid data to form (works for both draft and submitted bid)
            function restoreBidDataToForm(form, bidData, itemId) {
                if (!form || !bidData) return;
                
                // Restore video links
                if (bidData.video_links && bidData.video_links.length > 0) {
                    var videoContainer = document.getElementById('n88-video-links-container');
                    if (videoContainer) {
                        // Clear existing inputs except first
                        var existingInputs = videoContainer.querySelectorAll('.n88-video-link-input');
                        for (var i = 1; i < existingInputs.length; i++) {
                            var parent = existingInputs[i].closest('div');
                            if (parent) parent.remove();
                        }
                        
                        // Set first input
                        var firstInput = videoContainer.querySelector('.n88-video-link-input');
                        if (firstInput && bidData.video_links[0]) {
                            firstInput.value = bidData.video_links[0];
                        }
                        
                        // Add additional inputs
                        for (var i = 1; i < bidData.video_links.length; i++) {
                            if (typeof addVideoLink === 'function') {
                                addVideoLink();
                                var inputs = videoContainer.querySelectorAll('.n88-video-link-input');
                                if (inputs[i]) {
                                    inputs[i].value = bidData.video_links[i];
                                }
                            }
                        }
                    }
                }
                
                // Restore photos (H) - restore from URLs
                if (bidData.bid_photo_urls && bidData.bid_photo_urls.length > 0) {
                    var previewContainer = document.getElementById('n88-bid-photos-preview');
                    if (previewContainer) {
                        previewContainer.innerHTML = '';
                        bidData.bid_photo_urls.forEach(function(photo) {
                            var thumbDiv = document.createElement('div');
                            thumbDiv.style.cssText = 'position: relative; width: 100px; height: 100px; border: 2px solid #00ff00; border-radius: 4px; overflow: hidden;';
                            thumbDiv.innerHTML = '<img src="' + photo.url.replace(/"/g, '&quot;') + '" style="width: 100%; height: 100%; object-fit: cover;" alt="Bid photo" />' +
                                '<button type="button" onclick="removeBidPhoto(this, ' + photo.id + ');" style="position: absolute; top: 4px; right: 4px; background: rgba(255, 0, 0, 0.8); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center;" title="Remove">×</button>';
                            thumbDiv.setAttribute('data-photo-id', photo.id);
                            previewContainer.appendChild(thumbDiv);
                            
                            // Add hidden input
                            var hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.name = 'bid_photo_ids[]';
                            hiddenInput.value = photo.id;
                            hiddenInput.setAttribute('data-photo-id', photo.id);
                            hiddenInput.setAttribute('data-thumb-div', 'photo-' + photo.id);
                            form.appendChild(hiddenInput);
                        });
                    }
                } else if (bidData.bid_photos && bidData.bid_photos.length > 0) {
                    // Fallback: restore from URLs if photo_urls not available
                    var previewContainer = document.getElementById('n88-bid-photos-preview');
                    if (previewContainer) {
                        previewContainer.innerHTML = '';
                        bidData.bid_photos.forEach(function(photoUrl, index) {
                            var thumbDiv = document.createElement('div');
                            thumbDiv.style.cssText = 'position: relative; width: 100px; height: 100px; border: 2px solid #00ff00; border-radius: 4px; overflow: hidden;';
                            thumbDiv.innerHTML = '<img src="' + photoUrl.replace(/"/g, '&quot;') + '" style="width: 100%; height: 100%; object-fit: cover;" alt="Bid photo" />' +
                                '<button type="button" onclick="removeBidPhoto(this, null);" style="position: absolute; top: 4px; right: 4px; background: rgba(255, 0, 0, 0.8); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center;" title="Remove">×</button>';
                            previewContainer.appendChild(thumbDiv);
                        });
                    }
                }
                
                // Restore form fields
                if (bidData.prototype_video_yes !== undefined && bidData.prototype_video_yes !== null) {
                    var radio = form.querySelector('input[name="prototype_video_yes"][value="' + bidData.prototype_video_yes + '"]');
                    if (radio) radio.checked = true;
                }
                if (bidData.prototype_timeline_option) {
                    var timelineSelect = form.querySelector('select[name="prototype_timeline_option"]');
                    if (timelineSelect) timelineSelect.value = bidData.prototype_timeline_option;
                }
                if (bidData.prototype_cost) {
                    var costInput = form.querySelector('input[name="prototype_cost"]');
                    if (costInput) costInput.value = bidData.prototype_cost;
                }
                if (bidData.production_lead_time_text) {
                    var leadTimeSelect = form.querySelector('select[name="production_lead_time_text"]');
                    if (leadTimeSelect) leadTimeSelect.value = bidData.production_lead_time_text;
                }
                if (bidData.unit_price) {
                    var priceInput = form.querySelector('input[name="unit_price"]');
                    if (priceInput) priceInput.value = bidData.unit_price;
                }
                
                // Restore Smart Alternatives (H) - fix comparison points
                if (bidData.smart_alternatives_suggestion) {
                    var sa = bidData.smart_alternatives_suggestion;
                    if (sa.category) {
                        var catSelect = form.querySelector('select[name="smart_alt_category"]');
                        if (catSelect) catSelect.value = sa.category;
                    }
                    if (sa.from) {
                        var fromSelect = form.querySelector('select[name="smart_alt_from"]');
                        if (fromSelect) fromSelect.value = sa.from;
                    }
                    if (sa.to) {
                        var toSelect = form.querySelector('select[name="smart_alt_to"]');
                        if (toSelect) toSelect.value = sa.to;
                    }
                    if (sa.price_impact) {
                        var priceSelect = form.querySelector('select[name="smart_alt_price_impact"]');
                        if (priceSelect) priceSelect.value = sa.price_impact;
                    }
                    if (sa.lead_time_impact) {
                        var leadSelect = form.querySelector('select[name="smart_alt_lead_time_impact"]');
                        if (leadSelect) leadSelect.value = sa.lead_time_impact;
                    }
                    // H) Fix comparison points restoration - use correct selector
                    if (sa.comparison_points && sa.comparison_points.length > 0) {
                        sa.comparison_points.forEach(function(comp) {
                            var checkbox = form.querySelector('input[type="checkbox"][value="' + comp + '"].n88-smart-alt-checkbox-modal');
                            if (!checkbox) {
                                // Try without class
                                checkbox = form.querySelector('input[type="checkbox"][value="' + comp + '"]');
                            }
                            if (checkbox) {
                                checkbox.checked = true;
                            }
                        });
                    }
                    // Update preview
                    if (typeof updateSmartAltPreviewModal === 'function') {
                        updateSmartAltPreviewModal();
                    }
                }
                
                // Trigger validation
                if (typeof validateBidForm === 'function') {
                    setTimeout(function() {
                        validateBidForm();
                    }, 100);
                }
            }
            
            // Restore bid data to embedded form (for resubmission)
            function restoreBidDataToFormEmbedded(form, bidData, itemId) {
                if (!form || !bidData) return;
                
                // Restore video links (embedded form uses different container ID)
                if (bidData.video_links && bidData.video_links.length > 0) {
                    var videoContainer = document.getElementById('n88-video-links-container-embedded-' + itemId);
                    if (videoContainer) {
                        // Clear existing inputs except first
                        var existingInputs = videoContainer.querySelectorAll('.n88-video-link-input-embedded');
                        for (var i = 1; i < existingInputs.length; i++) {
                            var parent = existingInputs[i].closest('div');
                            if (parent) parent.remove();
                        }
                        
                        // Set first input
                        var firstInput = videoContainer.querySelector('.n88-video-link-input-embedded');
                        if (firstInput && bidData.video_links[0]) {
                            firstInput.value = bidData.video_links[0];
                        }
                        
                        // Add additional inputs
                        for (var i = 1; i < bidData.video_links.length; i++) {
                            if (typeof addVideoLinkEmbedded === 'function') {
                                addVideoLinkEmbedded(itemId);
                                var inputs = videoContainer.querySelectorAll('.n88-video-link-input-embedded');
                                if (inputs[i]) {
                                    inputs[i].value = bidData.video_links[i];
                                }
                            }
                        }
                    }
                }
                
                // Restore photos (embedded form uses different preview container ID)
                var previewContainer = document.getElementById('n88-bid-photos-preview-embedded-' + itemId);
                if (previewContainer) {
                    previewContainer.innerHTML = '';
                    if (bidData.bid_photo_urls && bidData.bid_photo_urls.length > 0) {
                        bidData.bid_photo_urls.forEach(function(photo) {
                            var thumbDiv = document.createElement('div');
                            thumbDiv.style.cssText = 'position: relative; width: 100px; height: 100px; border: 2px solid #00ff00; border-radius: 4px; overflow: hidden;';
                            thumbDiv.innerHTML = '<img src="' + photo.url.replace(/"/g, '&quot;') + '" style="width: 100%; height: 100%; object-fit: cover;" alt="Bid photo" />' +
                                '<button type="button" onclick="removeBidPhotoEmbedded(this, ' + (photo.id || 'null') + ', ' + itemId + ');" style="position: absolute; top: 4px; right: 4px; background: rgba(255, 0, 0, 0.8); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center;" title="Remove">×</button>';
                            thumbDiv.setAttribute('data-photo-id', photo.id || '');
                            previewContainer.appendChild(thumbDiv);
                            
                            // Add hidden input
                            var hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.name = 'bid_photo_ids[]';
                            hiddenInput.value = photo.id || '';
                            hiddenInput.setAttribute('data-photo-id', photo.id || '');
                            form.appendChild(hiddenInput);
                        });
                    } else if (bidData.bid_photos && bidData.bid_photos.length > 0) {
                        bidData.bid_photos.forEach(function(photoUrl) {
                            var thumbDiv = document.createElement('div');
                            thumbDiv.style.cssText = 'position: relative; width: 100px; height: 100px; border: 2px solid #00ff00; border-radius: 4px; overflow: hidden;';
                            thumbDiv.innerHTML = '<img src="' + photoUrl.replace(/"/g, '&quot;') + '" style="width: 100%; height: 100%; object-fit: cover;" alt="Bid photo" />' +
                                '<button type="button" onclick="removeBidPhotoEmbedded(this, null, ' + itemId + ');" style="position: absolute; top: 4px; right: 4px; background: rgba(255, 0, 0, 0.8); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center;" title="Remove">×</button>';
                            previewContainer.appendChild(thumbDiv);
                        });
                    }
                }
                
                // Restore form fields (same selectors work for embedded forms)
                if (bidData.prototype_video_yes !== undefined && bidData.prototype_video_yes !== null) {
                    var radio = form.querySelector('input[name="prototype_video_yes"][value="' + (bidData.prototype_video_yes ? '1' : '0') + '"]');
                    if (radio) radio.checked = true;
                }
                if (bidData.prototype_timeline_option) {
                    var timelineSelect = form.querySelector('select[name="prototype_timeline_option"]');
                    if (timelineSelect) timelineSelect.value = bidData.prototype_timeline_option;
                }
                if (bidData.prototype_cost) {
                    var costInput = form.querySelector('input[name="prototype_cost"]');
                    if (costInput) costInput.value = bidData.prototype_cost;
                }
                if (bidData.production_lead_time_text) {
                    var leadTimeSelect = form.querySelector('select[name="production_lead_time_text"]');
                    if (leadTimeSelect) leadTimeSelect.value = bidData.production_lead_time_text;
                }
                if (bidData.unit_price) {
                    var priceInput = form.querySelector('input[name="unit_price"]');
                    if (priceInput) priceInput.value = bidData.unit_price;
                }
                
                // Restore Smart Alternatives
                if (bidData.smart_alternatives_suggestion) {
                    var sa = bidData.smart_alternatives_suggestion;
                    if (sa.category) {
                        var catSelect = form.querySelector('select[name="smart_alt_category"]');
                        if (catSelect) catSelect.value = sa.category;
                    }
                    if (sa.from) {
                        var fromSelect = form.querySelector('select[name="smart_alt_from"]');
                        if (fromSelect) fromSelect.value = sa.from;
                    }
                    if (sa.to) {
                        var toSelect = form.querySelector('select[name="smart_alt_to"]');
                        if (toSelect) toSelect.value = sa.to;
                    }
                    if (sa.price_impact) {
                        var priceSelect = form.querySelector('select[name="smart_alt_price_impact"]');
                        if (priceSelect) priceSelect.value = sa.price_impact;
                    }
                    if (sa.lead_time_impact) {
                        var leadSelect = form.querySelector('select[name="smart_alt_lead_time_impact"]');
                        if (leadSelect) leadSelect.value = sa.lead_time_impact;
                    }
                    if (sa.comparison_points && sa.comparison_points.length > 0) {
                        sa.comparison_points.forEach(function(comp) {
                            var checkbox = form.querySelector('input[type="checkbox"][value="' + comp + '"]');
                            if (checkbox) {
                                checkbox.checked = true;
                            }
                        });
                    }
                    // Update preview if function exists
                    if (typeof updateSmartAltPreviewModal === 'function') {
                        updateSmartAltPreviewModal();
                    }
                }
                
                // Trigger validation for embedded form
                if (typeof validateBidFormEmbedded === 'function') {
                    setTimeout(function() {
                        validateBidFormEmbedded(itemId);
                    }, 100);
                }
            }
            
            // G) Update bid to match new specs
            function updateBidToMatchNewSpecs(itemId) {
                var formData = new FormData();
                formData.append('action', 'n88_update_bid_to_match_new_specs');
                formData.append('item_id', itemId);
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_update_bid_to_match_new_specs' ); ?>');
                
                // Show loading state
                var banner = document.getElementById('n88-specs-changed-banner');
                if (banner) {
                    banner.innerHTML = '<div style="padding: 16px; text-align: center; color: #fff; font-family: monospace;">Creating new draft...</div>';
                }
                
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        alert(data.data && data.data.message ? data.data.message : 'Failed to create draft. Please try again.');
                        if (banner) {
                            banner.innerHTML = '<div style="font-size: 14px; font-weight: 600; color: #ff9800; margin-bottom: 12px; font-family: monospace;">⚠️ Specs changed since your last bid.</div>' +
                                '<div style="font-size: 12px; color: #fff; margin-bottom: 16px; font-family: monospace; line-height: 1.5;">' +
                                'The item specifications have been updated. Please update your bid to match the new specs before submitting.' +
                                '</div>' +
                                '<button type="button" onclick="updateBidToMatchNewSpecs(' + itemId + ')" style="padding: 10px 20px; background-color: #ff9800; color: #000; border: none; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: monospace;">Update Bid to Match New Specs</button>';
                        }
                        return;
                    }
                    
                    // Hide banner and show form
                    if (banner) {
                        banner.style.display = 'none';
                    }
                    var form = document.getElementById('n88-bid-form');
                    if (form) {
                        form.style.display = 'block';
                    }
                    
                    // Reload item details to get pre-filled draft data
                    openBidFormModalInternal(itemId);
                })
                .catch(function(error) {
                    console.error('Error updating bid:', error);
                    alert('An error occurred. Please try again.');
                    if (banner) {
                        banner.innerHTML = '<div style="font-size: 14px; font-weight: 600; color: #ff9800; margin-bottom: 12px; font-family: monospace;">⚠️ Specs changed since your last bid.</div>' +
                            '<div style="font-size: 12px; color: #fff; margin-bottom: 16px; font-family: monospace; line-height: 1.5;">' +
                            'The item specifications have been updated. Please update your bid to match the new specs before submitting.' +
                            '</div>' +
                            '<button type="button" onclick="updateBidToMatchNewSpecs(' + itemId + ')" style="padding: 10px 20px; background-color: #ff9800; color: #000; border: none; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: monospace;">Update Bid to Match New Specs</button>';
                    }
                });
            }
            
            // Make functions globally accessible
            window.validateAndSubmitBid = validateAndSubmitBid;
            window.submitBid = submitBid;
            window.toggleBidForm = toggleBidForm;
            window.addVideoLinkEmbedded = addVideoLinkEmbedded;
            window.removeVideoLinkEmbedded = removeVideoLinkEmbedded;
            window.validateVideoLinkEmbedded = validateVideoLinkEmbedded;
            window.updateVideoLinkButtonsEmbedded = updateVideoLinkButtonsEmbedded;
            window.handleBidPhotosChangeEmbedded = handleBidPhotosChangeEmbedded;
            window.validateBidFormEmbedded = validateBidFormEmbedded;
            window.validateAndSubmitBidEmbedded = validateAndSubmitBidEmbedded;
            window.submitBidEmbedded = submitBidEmbedded;
            window.saveBidDraftEmbedded = saveBidDraftEmbedded;
            window.updateBidToMatchNewSpecs = updateBidToMatchNewSpecs;
            
            // Smart Alternatives preview update function
            function updateSmartAltPreview(itemId) {
                var category = document.getElementById('n88-smart-alt-category-' + itemId);
                var from = document.getElementById('n88-smart-alt-from-' + itemId);
                var to = document.getElementById('n88-smart-alt-to-' + itemId);
                var priceImpact = document.getElementById('n88-smart-alt-price-' + itemId);
                var leadTimeImpact = document.getElementById('n88-smart-alt-leadtime-' + itemId);
                var checkboxes = document.querySelectorAll('#n88-bid-form-embedded-' + itemId + ' .n88-smart-alt-checkbox:checked');
                var preview = document.getElementById('n88-smart-alt-preview-' + itemId);
                
                if (!preview) return;
                
                // Validate max 3 checkboxes
                if (checkboxes.length > 3) {
                    alert('Maximum 3 comparison points allowed. Please uncheck one.');
                    event.target.checked = false;
                    checkboxes = document.querySelectorAll('#n88-bid-form-embedded-' + itemId + ' .n88-smart-alt-checkbox:checked');
                }
                
                var parts = [];
                
                if (category && category.value) {
                    var categoryLabels = {
                        'material': 'Material',
                        'finish': 'Finish',
                        'hardware': 'Hardware',
                        'dimensions': 'Dimensions',
                        'construction': 'Construction Method',
                        'packaging': 'Packaging'
                    };
                    parts.push('Category: ' + (categoryLabels[category.value] || category.value));
                }
                
                if (from && from.value && to && to.value) {
                    var fromLabels = {
                        'solid-wood': 'Solid Wood', 'plywood': 'Plywood', 'mdf': 'MDF',
                        'metal': 'Metal', 'plastic': 'Plastic', 'glass': 'Glass',
                        'fabric': 'Fabric', 'leather': 'Leather', 'other': 'Other'
                    };
                    var toLabels = fromLabels;
                    parts.push('From ' + (fromLabels[from.value] || from.value) + ' to ' + (toLabels[to.value] || to.value));
                }
                
                if (checkboxes.length > 0) {
                    var comparisonLabels = {
                        'cost-reduction': 'Cost Reduction',
                        'faster-production': 'Faster Production',
                        'better-durability': 'Better Durability',
                        'easier-sourcing': 'Easier Sourcing',
                        'lighter-weight': 'Lighter Weight',
                        'eco-friendly': 'Eco-Friendly'
                    };
                    var comparisons = Array.from(checkboxes).map(function(cb) {
                        return comparisonLabels[cb.value] || cb.value;
                    });
                    parts.push('Benefits: ' + comparisons.join(', '));
                }
                
                if (priceImpact && priceImpact.value) {
                    var priceLabels = {
                        'reduces-10-20': 'Price: Reduces 10-20%',
                        'reduces-20-30': 'Price: Reduces 20-30%',
                        'reduces-30-plus': 'Price: Reduces 30%+',
                        'similar': 'Price: Similar',
                        'increases-10-20': 'Price: Increases 10-20%',
                        'increases-20-plus': 'Price: Increases 20%+'
                    };
                    parts.push(priceLabels[priceImpact.value] || priceImpact.value);
                }
                
                if (leadTimeImpact && leadTimeImpact.value) {
                    var leadTimeLabels = {
                        'reduces-1-2w': 'Lead Time: Reduces 1-2 weeks',
                        'reduces-2-4w': 'Lead Time: Reduces 2-4 weeks',
                        'reduces-4w-plus': 'Lead Time: Reduces 4+ weeks',
                        'similar': 'Lead Time: Similar',
                        'increases-1-2w': 'Lead Time: Increases 1-2 weeks',
                        'increases-2w-plus': 'Lead Time: Increases 2+ weeks'
                    };
                    parts.push(leadTimeLabels[leadTimeImpact.value] || leadTimeImpact.value);
                }
                
                if (parts.length > 0) {
                    preview.textContent = parts.join(' | ');
                    preview.style.color = '#00ff00';
                    preview.style.fontStyle = 'normal';
                } else {
                    preview.textContent = 'Fill in the fields above to generate preview...';
                    preview.style.color = '#999';
                    preview.style.fontStyle = 'italic';
                }
            }
            
            window.updateSmartAltPreview = updateSmartAltPreview;
            
            // Smart Alternatives preview update function for modal form
            function updateSmartAltPreviewModal() {
                var category = document.getElementById('n88-smart-alt-category-modal');
                var from = document.getElementById('n88-smart-alt-from-modal');
                var to = document.getElementById('n88-smart-alt-to-modal');
                var priceImpact = document.getElementById('n88-smart-alt-price-modal');
                var leadTimeImpact = document.getElementById('n88-smart-alt-leadtime-modal');
                var checkboxes = document.querySelectorAll('#n88-bid-form .n88-smart-alt-checkbox-modal:checked');
                var preview = document.getElementById('n88-smart-alt-preview-modal');
                
                if (!preview) return;
                
                // Validate max 3 checkboxes
                if (checkboxes.length > 3) {
                    alert('Maximum 3 comparison points allowed. Please uncheck one.');
                    event.target.checked = false;
                    checkboxes = document.querySelectorAll('#n88-bid-form .n88-smart-alt-checkbox-modal:checked');
                }
                
                var parts = [];
                
                if (category && category.value) {
                    var categoryLabels = {
                        'material': 'Material',
                        'finish': 'Finish',
                        'hardware': 'Hardware',
                        'dimensions': 'Dimensions',
                        'construction': 'Construction Method',
                        'packaging': 'Packaging'
                    };
                    parts.push('Category: ' + (categoryLabels[category.value] || category.value));
                }
                
                if (from && from.value && to && to.value) {
                    var fromLabels = {
                        'solid-wood': 'Solid Wood', 'plywood': 'Plywood', 'mdf': 'MDF',
                        'metal': 'Metal', 'plastic': 'Plastic', 'glass': 'Glass',
                        'fabric': 'Fabric', 'leather': 'Leather', 'other': 'Other'
                    };
                    var toLabels = fromLabels;
                    parts.push('From ' + (fromLabels[from.value] || from.value) + ' to ' + (toLabels[to.value] || to.value));
                }
                
                if (checkboxes.length > 0) {
                    var comparisonLabels = {
                        'cost-reduction': 'Cost Reduction',
                        'faster-production': 'Faster Production',
                        'better-durability': 'Better Durability',
                        'easier-sourcing': 'Easier Sourcing',
                        'lighter-weight': 'Lighter Weight',
                        'eco-friendly': 'Eco-Friendly'
                    };
                    var comparisons = Array.from(checkboxes).map(function(cb) {
                        return comparisonLabels[cb.value] || cb.value;
                    });
                    parts.push('Benefits: ' + comparisons.join(', '));
                }
                
                if (priceImpact && priceImpact.value) {
                    var priceLabels = {
                        'reduces-10-20': 'Price: Reduces 10-20%',
                        'reduces-20-30': 'Price: Reduces 20-30%',
                        'reduces-30-plus': 'Price: Reduces 30%+',
                        'similar': 'Price: Similar',
                        'increases-10-20': 'Price: Increases 10-20%',
                        'increases-20-plus': 'Price: Increases 20%+'
                    };
                    parts.push(priceLabels[priceImpact.value] || priceImpact.value);
                }
                
                if (leadTimeImpact && leadTimeImpact.value) {
                    var leadTimeLabels = {
                        'reduces-1-2w': 'Lead Time: Reduces 1-2 weeks',
                        'reduces-2-4w': 'Lead Time: Reduces 2-4 weeks',
                        'reduces-4w-plus': 'Lead Time: Reduces 4+ weeks',
                        'similar': 'Lead Time: Similar',
                        'increases-1-2w': 'Lead Time: Increases 1-2 weeks',
                        'increases-2w-plus': 'Lead Time: Increases 2+ weeks'
                    };
                    parts.push(leadTimeLabels[leadTimeImpact.value] || leadTimeImpact.value);
                }
                
                if (parts.length > 0) {
                    preview.textContent = parts.join(' | ');
                    preview.style.color = '#00ff00';
                    preview.style.fontStyle = 'normal';
                } else {
                    preview.textContent = 'Fill in the fields above to generate preview...';
                    preview.style.color = '#999';
                    preview.style.fontStyle = 'italic';
                }
            }
            
            window.updateSmartAltPreviewModal = updateSmartAltPreviewModal;
            window.removeBidPhotoEmbedded = removeBidPhotoEmbedded;
            
            // Attach event listeners
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.n88-open-bid-modal').forEach(function(button) {
                    button.addEventListener('click', function(e) {
                        var itemId = this.getAttribute('data-item-id');
                        var actionBadge = this.getAttribute('data-action-badge');
                        
                        // M6: Block if expired
                        if (actionBadge === 'expired') {
                            e.preventDefault();
                            e.stopPropagation();
                            alert('This RFQ is no longer accepting bids.');
                            return false;
                        }
                        
                        openBidModal(itemId);
                    });
                });
                
                // Close modal on backdrop click
                var modal = document.getElementById('n88-supplier-bid-modal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeBidModal();
                        }
                    });
                }
                
                // Close bid form modal on backdrop click
                var bidFormModal = document.getElementById('n88-supplier-bid-form-modal');
                if (bidFormModal) {
                    bidFormModal.addEventListener('click', function(e) {
                        if (e.target === bidFormModal) {
                            closeBidFormModal();
                        }
                    });
                }
            });
            
            // Expose to global scope
            window.openBidModal = openBidModal;
            window.closeBidModal = closeBidModal;
            // Withdraw bid function (Commit 2.3.5)
            function withdrawBid(itemId) {
                if (!confirm('Are you sure you want to withdraw your bid? You will be able to resubmit a new bid after withdrawal.')) {
                    return;
                }
                
                var formData = new FormData();
                formData.append('action', 'n88_withdraw_supplier_bid');
                formData.append('item_id', itemId);
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_withdraw_supplier_bid' ); ?>');
                
                var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        alert(data.data.message || 'Bid withdrawn successfully.');
                        // Commit 2.3.5.5: Refresh the item detail modal immediately to show updated state
                        closeBidModal();
                        setTimeout(function() {
                            openBidModal(itemId);
                        }, 100);
                    } else {
                        alert(data.data.message || 'Failed to withdraw bid. Please try again.');
                    }
                })
                .catch(function(error) {
                    console.error('Error withdrawing bid:', error);
                    alert('An error occurred while withdrawing the bid. Please try again.');
                });
            }
            
            window.openBidFormModal = openBidFormModal;
            window.closeBidFormModal = closeBidFormModal;
            window.addVideoLink = addVideoLink;
            window.removeVideoLink = removeVideoLink;
            window.validateVideoLink = validateVideoLink;
            window.validateBidForm = validateBidForm;
            window.withdrawBid = withdrawBid;
            window.validateAndSubmitBid = validateAndSubmitBid;
            window.openSupplierImageLightbox = openSupplierImageLightbox;
            window.closeSupplierImageLightbox = closeSupplierImageLightbox;
            window.handleBidPhotosChange = handleBidPhotosChange;
            window.removeBidPhoto = removeBidPhoto;
            window.removeBidPhotoEmbedded = removeBidPhotoEmbedded;
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Render admin queue page (Commit 2.2.3)
     */
    public function render_admin_queue( $atts = array() ) {
        // Allow admins to edit pages even if they don't have system operator role
        if ( is_admin() && current_user_can( 'edit_pages' ) ) {
            return '<p><em>Admin Queue page - This shortcode will display the admin queue for system operators.</em></p>';
        }

        // Check if user is logged in and is a system operator
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url( '/login/' ) );
            exit;
        }

        $current_user = wp_get_current_user();
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        if ( ! $is_system_operator ) {
            wp_die( 'Access denied. System Operator account required.', 'Access Denied', array( 'response' => 403 ) );
        }

        $scope = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : 'global';
        
        // Read filter values from URL query parameters
        // These values are restored on page refresh, ensuring filter state persists across page reloads
        // This works for all user types: supplier, admin, and designer
        $queue_type = isset( $_GET['queue_type'] ) ? sanitize_text_field( wp_unslash( $_GET['queue_type'] ) ) : 'pricing';
        $status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'all';
        $supplier_id = isset( $_GET['supplier_id'] ) ? sanitize_text_field( wp_unslash( $_GET['supplier_id'] ) ) : 'all';
        $designer_id = isset( $_GET['designer_id'] ) ? sanitize_text_field( wp_unslash( $_GET['designer_id'] ) ) : 'all';
        $search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

        ob_start();
        ?>
        <div class="n88-admin-queue" style="max-width: 1400px; margin: 0 auto; padding: 40px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;">
            <!-- Header -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0;">
                <h1 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"> Super Admin Assembly Line</h1>
                <div style="font-size: 14px; color: #666;">
                    Logged in: <?php echo esc_html( $current_user->display_name ); ?>
                </div>
            </div>
            
            <!-- Filters Section -->
            <div style="margin-bottom: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 4px;">
                <div style="font-size: 14px; font-weight: 600; margin-bottom: 15px; color: #333;">Filters:</div>
                <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size: 14px; color: #666;">Queue Type</label>
                        <select id="n88-admin-queue-type" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff; cursor: pointer; min-width: 120px;">
                            <option value="pricing" <?php selected( $queue_type, 'pricing' ); ?>>Pricing</option>
                            <option value="prototype" <?php selected( $queue_type, 'prototype' ); ?>>Prototype</option>
                            <option value="shipping" <?php selected( $queue_type, 'shipping' ); ?>>Shipping</option>
                            <option value="all" <?php selected( $queue_type, 'all' ); ?>>All</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size: 14px; color: #666;">Status</label>
                        <select id="n88-admin-status" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff; cursor: pointer; min-width: 120px;">
                            <option value="all" <?php selected( $status, 'all' ); ?>>All</option>
                            <option value="pending" <?php selected( $status, 'pending' ); ?>>Pending</option>
                            <option value="assigned" <?php selected( $status, 'assigned' ); ?>>Assigned</option>
                            <option value="in_progress" <?php selected( $status, 'in_progress' ); ?>>In Progress</option>
                            <option value="completed" <?php selected( $status, 'completed' ); ?>>Completed</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size: 14px; color: #666;">Maker</label>
                        <select id="n88-admin-supplier" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff; cursor: pointer; min-width: 120px;">
                            <option value="all" <?php selected( $supplier_id, 'all' ); ?>>All</option>
                            <option value="supplier_x" <?php selected( $supplier_id, 'supplier_x' ); ?>>Supplier X</option>
                            <option value="supplier_y" <?php selected( $supplier_id, 'supplier_y' ); ?>>Supplier Y</option>
                            <option value="unassigned" <?php selected( $supplier_id, 'unassigned' ); ?>>Unassigned</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size: 14px; color: #666;">Creator</label>
                        <select id="n88-admin-designer" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff; cursor: pointer; min-width: 120px;">
                            <option value="all" <?php selected( $designer_id, 'all' ); ?>>All</option>
                            <option value="sarah" <?php selected( $designer_id, 'sarah' ); ?>>Sarah (Firm A)</option>
                            <option value="vikram" <?php selected( $designer_id, 'vikram' ); ?>>Vikram (Firm B)</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Queue Heading -->
            <div style="margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;">Global Item Queue (item-centric)</h2>
            </div>
            
            <!-- Items Table -->
            <div style="background-color: #fff; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; margin-bottom: 20px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #f5f5f5; border-bottom: 2px solid #e0e0e0;">
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Item</th>
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Item Title</th>
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Request Type</th>
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Creator</th>
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Maker</th>
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Demo data from wireframe -->
                        <tr style="border-bottom: 1px solid #e0e0e0;">
                            <td style="padding: 15px; font-size: 14px; color: #ff6600; font-weight: 500;">#1023</td>
                            <td style="padding: 15px; font-size: 14px; color: #333;">Curved Sofa</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Pricing Req</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Sarah (Firm A)</td>
                            <td style="padding: 15px; font-size: 14px; color: #999;">(Unassigned)</td>
                            <td style="padding: 15px;">
                                <button class="n88-open-admin-modal" data-item-id="1023" data-item-title="Curved Sofa" data-request-type="Pricing Req" data-supplier="Unassigned" style="padding: 6px 12px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                                    Open ►
                                </button>
                            </td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e0e0e0;">
                            <td style="padding: 15px; font-size: 14px; color: #ff6600; font-weight: 500;">#1027</td>
                            <td style="padding: 15px; font-size: 14px; color: #333;">Dining Chair</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Pricing Req</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Sarah (Firm A)</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Supplier X</td>
                            <td style="padding: 15px;">
                                <button class="n88-open-admin-modal" data-item-id="1027" data-item-title="Dining Chair" data-request-type="Pricing Req" data-supplier="Supplier X" style="padding: 6px 12px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                                    Open ►
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 15px; font-size: 14px; color: #ff6600; font-weight: 500;">#1031</td>
                            <td style="padding: 15px; font-size: 14px; color: #333;">Banquette</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Prototype</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Vikram (Firm B)</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Supplier X</td>
                            <td style="padding: 15px;">
                                <button class="n88-open-admin-modal" data-item-id="1031" data-item-title="Banquette" data-request-type="Prototype" data-supplier="Supplier X" style="padding: 6px 12px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                                    Open ►
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Action Bar -->
            <div style="padding: 15px; background-color: #f9f9f9; border-radius: 4px; border-left: 4px solid #0073aa;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 14px; font-weight: 600; color: #333;">Action:</span>
                    <select class="n88-admin-action-select" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff; cursor: pointer; min-width: 150px;">
                        <option value="open" selected>Open</option>
                        <option value="assign">Assign Supplier</option>
                        <option value="reject">Reject</option>
                    </select>
                    <span style="font-size: 12px; color: #666; font-style: italic;">(opens drawer; does not navigate away!)</span>
                </div>
            </div>
        </div>
        
        <!-- Admin Queue Modal -->
        <div id="n88-admin-queue-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 10000; overflow: hidden;">
            <div id="n88-admin-queue-modal-content" style="position: fixed; top: 0; right: 0; width: 480px; max-width: 90vw; height: 100vh; background-color: #fff; box-shadow: -2px 0 10px rgba(0,0,0,0.2); z-index: 10001; display: flex; flex-direction: column; overflow: hidden;">
                <!-- Modal content will be populated by JavaScript -->
            </div>
        </div>
        
        <script>
        (function() {
            // Filter persistence via URL query parameters
            // When filters change, update the URL. On page refresh, PHP reads these params and restores filter values
            function updateAdminQueueURL() {
                var queueType = document.getElementById('n88-admin-queue-type')?.value || 'pricing';
                var status = document.getElementById('n88-admin-status')?.value || 'all';
                var supplier = document.getElementById('n88-admin-supplier')?.value || 'all';
                var designer = document.getElementById('n88-admin-designer')?.value || 'all';
                
                var params = new URLSearchParams(window.location.search);
                
                // Preserve scope parameter
                if (params.has('scope')) {
                    // Keep existing scope
                } else {
                    params.set('scope', '<?php echo esc_js( $scope ); ?>');
                }
                
                // Only add queue_type if it's not the default 'pricing'
                if (queueType && queueType !== 'pricing') {
                    params.set('queue_type', queueType);
                } else {
                    params.delete('queue_type');
                }
                
                if (status && status !== 'all') {
                    params.set('status', status);
                } else {
                    params.delete('status');
                }
                
                if (supplier && supplier !== 'all') {
                    params.set('supplier_id', supplier);
                } else {
                    params.delete('supplier_id');
                }
                
                if (designer && designer !== 'all') {
                    params.set('designer_id', designer);
                } else {
                    params.delete('designer_id');
                }
                
                var newURL = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                window.history.replaceState({}, '', newURL);
            }
            
            // Attach event listeners to filter elements
            document.addEventListener('DOMContentLoaded', function() {
                var queueTypeSelect = document.getElementById('n88-admin-queue-type');
                var statusSelect = document.getElementById('n88-admin-status');
                var supplierSelect = document.getElementById('n88-admin-supplier');
                var designerSelect = document.getElementById('n88-admin-designer');
                
                if (queueTypeSelect) {
                    queueTypeSelect.addEventListener('change', updateAdminQueueURL);
                }
                if (statusSelect) {
                    statusSelect.addEventListener('change', updateAdminQueueURL);
                }
                if (supplierSelect) {
                    supplierSelect.addEventListener('change', updateAdminQueueURL);
                }
                if (designerSelect) {
                    designerSelect.addEventListener('change', updateAdminQueueURL);
                }
            });
            
            // Demo item data for admin queue
            var adminItems = {
                '1023': {
                    id: '1023',
                    title: 'Curved Sofa',
                    category: 'Upholstery',
                    requestType: 'Pricing Request',
                    supplier: 'Unassigned',
                    quantity: '--',
                    dims: { w: '--', d: '--', h: '--', unit: '--' },
                    normalized: { w: '--', d: '--', h: '--' },
                    cbm: '----',
                    sourcing_type: '--------',
                    timeline_type: '--------'
                },
                '1027': {
                    id: '1027',
                    title: 'Dining Chair',
                    category: 'Casegoods',
                    requestType: 'Pricing Request',
                    supplier: 'Supplier X',
                    quantity: '--',
                    dims: { w: '--', d: '--', h: '--', unit: '--' },
                    normalized: { w: '--', d: '--', h: '--' },
                    cbm: '----',
                    sourcing_type: '--------',
                    timeline_type: '--------'
                },
                '1031': {
                    id: '1031',
                    title: 'Banquette',
                    category: 'Upholstery',
                    requestType: 'Prototype',
                    supplier: 'Supplier X',
                    quantity: '--',
                    dims: { w: '--', d: '--', h: '--', unit: '--' },
                    normalized: { w: '--', d: '--', h: '--' },
                    cbm: '----',
                    sourcing_type: '--------',
                    timeline_type: '--------'
                }
            };
            
            function openAdminModal(itemId) {
                var item = adminItems[itemId];
                if (!item) {
                    // Try to get data from button attributes
                    var button = document.querySelector('.n88-open-admin-modal[data-item-id="' + itemId + '"]');
                    if (button) {
                        item = {
                            id: itemId,
                            title: button.getAttribute('data-item-title') || 'Item',
                            requestType: button.getAttribute('data-request-type') || 'Pricing Request',
                            supplier: button.getAttribute('data-supplier') || 'Unassigned',
                            quantity: '--',
                            dims: { w: '--', d: '--', h: '--', unit: '--' },
                            normalized: { w: '--', d: '--', h: '--' },
                            cbm: '----',
                            sourcing_type: '--------',
                            timeline_type: '--------'
                        };
                    } else {
                        return;
                    }
                }
                
                var modal = document.getElementById('n88-admin-queue-modal');
                var modalContent = document.getElementById('n88-admin-queue-modal-content');
                
                if (!modal || !modalContent) return;
                
                // Build modal HTML - Admin Queue Modal (matching wireframe exactly)
                var modalHTML = '<div style="padding: 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; background-color: #fff;">' +
                    '<h2 style="margin: 0; font-size: 20px; font-weight: 600; color: #333;">Item #' + item.id + ' <span style="color: #666; font-weight: 400;">(Operator View)</span></h2>' +
                    '<button onclick="closeAdminModal()" style="background: none; border: none; font-size: 28px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; color: #666; line-height: 1;">×</button>' +
                    '</div>' +
                    '<div style="flex: 1; overflow-y: auto; padding: 0; background-color: #fff;">' +
                    '<div style="padding: 20px;">' +
                    // READ-ONLY PLACEHOLDER text
                    '<div style="margin-bottom: 24px; padding: 12px; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">' +
                    '<div style="font-size: 13px; color: #856404; font-weight: 500;">READ-ONLY PLACEHOLDER <span style="color: #ff6600;">(Phase 2.2.x)</span></div>' +
                    '</div>' +
                    // Supplier
                    '<div style="margin-bottom: 16px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Supplier:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + item.supplier + '</div>' +
                    '</div>' +
                    // Queue Type
                    '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Queue Type:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + item.requestType + '</div>' +
                    '</div>' +
                    // Item Facts (read-only)
                    '<div style="margin-bottom: 24px;">' +
                    '<h3 style="font-size: 14px; font-weight: 600; margin-bottom: 16px; color: #333; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px;">Item Facts (read-only)</h3>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Title:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + item.title + '</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Category:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + (item.category || 'Upholstery') + '</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Qty:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">' + item.quantity + '</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Dims:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">W' + item.dims.w + ' D' + item.dims.d + ' H' + item.dims.h + ' Unit: ' + item.dims.unit + '</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Normalized (cm):</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">W' + item.normalized.w + ' D' + item.normalized.d + ' H' + item.normalized.h + '</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">CBM:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">' + item.cbm + '</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">sourcing_type:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">' + item.sourcing_type + '</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">timeline_type:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">' + item.timeline_type + '</div>' +
                    '</div>' +
                    '</div>' +
                    // References
                    '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">References (thumbs if exist):</label>' +
                    '<div style="display: flex; gap: 12px; flex-wrap: wrap;">' +
                    '<div style="width: 100px; height: 100px; background-color: #f0f0f0; border: 2px dashed #ccc; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #999; font-size: 12px;">[thumb]</div>' +
                    '<div style="width: 100px; height: 100px; background-color: #f0f0f0; border: 2px dashed #ccc; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #999; font-size: 12px;">[thumb]</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    // Footer
                    '<div style="padding: 20px; border-top: 1px solid #e0e0e0; background-color: #fff;">' +
                    '<div style="font-size: 12px; color: #999; font-style: italic; text-align: center;">(No bids UI. No prototype payments UI.)</div>' +
                    '</div>';
                
                modalContent.innerHTML = modalHTML;
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
            
            function closeAdminModal() {
                var modal = document.getElementById('n88-admin-queue-modal');
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }
            
            // Attach event listeners
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.n88-open-admin-modal').forEach(function(button) {
                    button.addEventListener('click', function() {
                        var itemId = this.getAttribute('data-item-id');
                        openAdminModal(itemId);
                    });
                });
                
                // Close modal on backdrop click
                var modal = document.getElementById('n88-admin-queue-modal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeAdminModal();
                        }
                    });
                }
            });
            
            // Expose to global scope
            window.openAdminModal = openAdminModal;
            window.closeAdminModal = closeAdminModal;
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Get countries list
     */
    private function get_countries_list() {
        return array(
            'US' => 'United States',
            'CA' => 'Canada',
            'GB' => 'United Kingdom',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PL' => 'Poland',
            'CZ' => 'Czech Republic',
            'IE' => 'Ireland',
            'PT' => 'Portugal',
            'GR' => 'Greece',
            'IN' => 'India',
            'CN' => 'China',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'SG' => 'Singapore',
            'MY' => 'Malaysia',
            'TH' => 'Thailand',
            'ID' => 'Indonesia',
            'PH' => 'Philippines',
            'VN' => 'Vietnam',
            'AE' => 'United Arab Emirates',
            'SA' => 'Saudi Arabia',
            'ZA' => 'South Africa',
            'BR' => 'Brazil',
            'MX' => 'Mexico',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'CO' => 'Colombia',
            'NZ' => 'New Zealand',
        );
    }

    /**
     * Get country name by code
     */
    private function get_country_name( $code ) {
        $countries = $this->get_countries_list();
        return isset( $countries[ $code ] ) ? $countries[ $code ] : $code;
    }

    /**
     * Render a styled 403 error page (Commit 2.2.1)
     */
    private static function render_403_error( $title, $message ) {
        // Set HTTP status code
        status_header( 403 );
        nocache_headers();

        // Get site name
        $site_name = get_bloginfo( 'name' );
        $home_url = home_url( '/' );

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html( $title ); ?> - <?php echo esc_html( $site_name ); ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .error-container {
                    background: #ffffff;
                    border-radius: 12px;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
                    max-width: 500px;
                    width: 100%;
                    padding: 50px 40px;
                    text-align: center;
                }
                .error-code {
                    font-size: 72px;
                    font-weight: bold;
                    color: #e74c3c;
                    line-height: 1;
                    margin-bottom: 20px;
                }
                .error-title {
                    font-size: 28px;
                    font-weight: 600;
                    color: #2c3e50;
                    margin-bottom: 15px;
                }
                .error-message {
                    font-size: 16px;
                    color: #7f8c8d;
                    line-height: 1.6;
                    margin-bottom: 30px;
                }
                .error-actions {
                    margin-top: 30px;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 30px;
                    background: #667eea;
                    color: #ffffff;
                    text-decoration: none;
                    border-radius: 6px;
                    font-weight: 500;
                    transition: background 0.3s ease;
                }
                .btn:hover {
                    background: #5568d3;
                }
                .btn-secondary {
                    background: #95a5a6;
                    margin-left: 10px;
                }
                .btn-secondary:hover {
                    background: #7f8c8d;
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-code">403</div>
                <h1 class="error-title"><?php echo esc_html( $title ); ?></h1>
                <p class="error-message"><?php echo esc_html( $message ); ?></p>
                <div class="error-actions">
                    <a href="<?php echo esc_url( $home_url ); ?>" class="btn">Go to Homepage</a>
                    <?php if ( is_user_logged_in() ) : ?>
                        <?php
                        $current_user = wp_get_current_user();
                        $redirect_url = self::get_role_redirect_url_static( $current_user );
                        if ( $redirect_url ) :
                        ?>
                            <a href="<?php echo esc_url( $redirect_url ); ?>" class="btn btn-secondary">Go to Dashboard</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * Render supplier onboarding form (Commit 2.2.7)
     */
    public function render_supplier_onboarding( $atts = array() ) {
        // Allow admins to edit pages even if they don't have supplier role
        if ( is_admin() && current_user_can( 'edit_pages' ) ) {
            return '<p><em>Supplier Onboarding page - This shortcode will display the supplier onboarding form.</em></p>';
        }

        // Check if user is logged in and is a supplier admin
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url( '/login/' ) );
            exit;
        }

        $current_user = wp_get_current_user();
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        
        if ( ! $is_supplier ) {
            wp_die( 'Access denied. Maker account required.', 'Access Denied', array( 'response' => 403 ) );
        }

        global $wpdb;
        $categories_table = $wpdb->prefix . 'n88_categories';
        $supplier_profiles_table = $wpdb->prefix . 'n88_supplier_profiles';
        $supplier_keyword_map_table = $wpdb->prefix . 'n88_supplier_keyword_map';
        
        // Fetch active categories
        $categories = $wpdb->get_results(
            "SELECT category_id, name FROM {$categories_table} WHERE is_active = 1 ORDER BY name"
        );

        // Check if profile exists and fetch existing data (Commit 2.2.7)
        $existing_profile = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$supplier_profiles_table} WHERE supplier_id = %d",
            $current_user->ID
        ) );

        // Fetch existing selected keywords
        $existing_keyword_ids = array();
        if ( $existing_profile ) {
            $existing_keyword_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT keyword_id FROM {$supplier_keyword_map_table} WHERE supplier_id = %d",
                $current_user->ID
            ) );
        }

        $is_edit_mode = ! empty( $existing_profile );
        $form_title = $is_edit_mode ? 'Update Supplier Profile' : 'Supplier Onboarding';
        $form_description = $is_edit_mode ? 'Update your profile information.' : 'Complete your profile to enable routing and matching.';

        ob_start();
        ?>
        <div class="n88-supplier-onboarding" style="max-width: 800px; margin: 40px auto; padding: 40px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;">
            <h1 style="margin: 0 0 30px 0; font-size: 28px; font-weight: 600; color: #333;"><?php echo esc_html( $form_title ); ?></h1>
            <p style="margin: 0 0 30px 0; font-size: 14px; color: #666;"><?php echo esc_html( $form_description ); ?></p>
            
            <form id="n88-supplier-onboarding-form" style="background-color: #fff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
                <?php wp_nonce_field( 'n88_save_supplier_profile', 'n88_supplier_profile_nonce' ); ?>
                
                <!-- Primary Category -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #333;">
                        Primary Category <span style="color: #d63638;">*</span>
                    </label>
                    <select id="n88-primary-category" name="primary_category_id" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff; cursor: pointer;">
                        <option value="">Select a category...</option>
                        <?php foreach ( $categories as $category ) : ?>
                            <option value="<?php echo esc_attr( $category->category_id ); ?>" <?php selected( $existing_profile && $existing_profile->primary_category_id == $category->category_id ); ?>>
                                <?php echo esc_html( $category->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Suggested Keywords (filtered by category) -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #333;">
                        Suggested Keywords
                    </label>
                    <p style="margin: 0 0 12px 0; font-size: 13px; color: #666;">Select keywords that match your capabilities. Keywords are filtered by your selected category.</p>
                    <div id="n88-keywords-container" style="display: flex; flex-wrap: wrap; gap: 8px; min-height: 40px; padding: 12px; border: 1px solid #ddd; border-radius: 4px; background-color: #f9f9f9;">
                        <p style="margin: 0; color: #999; font-size: 13px; font-style: italic;">Select a category first to see keywords</p>
                    </div>
                </div>

                <!-- Freeform Keywords -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #333;">
                        Additional Keywords
                    </label>
                    <p style="margin: 0 0 12px 0; font-size: 13px; color: #666;">Enter any additional keywords that describe your capabilities (one per line). These will be reviewed before approval.<?php echo $is_edit_mode ? ' New keywords will be added to your existing ones.' : ''; ?></p>
                    <textarea id="n88-freeform-keywords" name="freeform_keywords" rows="4" placeholder="Enter keywords, one per line..." style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-family: inherit; resize: vertical;"></textarea>
                </div>

                <!-- Capability Flags -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 12px; font-size: 14px; font-weight: 600; color: #333;">
                        Capabilities
                    </label>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="n88-prototype-video-capable" name="prototype_video_capable" value="1" <?php checked( $existing_profile && $existing_profile->prototype_video_capable == 1 ); ?> required style="width: 18px; height: 18px; cursor: pointer;">
                            <span style="font-size: 14px; color: #333;">
                                Prototype Video Capable <span style="color: #d63638;">*</span>
                            </span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="n88-cad-capable" name="cad_capable" value="1" <?php checked( $existing_profile && $existing_profile->cad_capable == 1 ); ?> style="width: 18px; height: 18px; cursor: pointer;">
                            <span style="font-size: 14px; color: #333;">CAD Capable</span>
                        </label>
                    </div>
                </div>

                <!-- Optional Capacity Fields -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 12px; font-size: 14px; font-weight: 600; color: #333;">
                        Capacity (Optional)
                    </label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-size: 13px; color: #666;">Minimum Quantity</label>
                            <input type="number" id="n88-qty-min" name="qty_min" min="0" value="<?php echo $existing_profile && $existing_profile->qty_min ? esc_attr( $existing_profile->qty_min ) : ''; ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-size: 13px; color: #666;">Maximum Quantity</label>
                            <input type="number" id="n88-qty-max" name="qty_max" min="0" value="<?php echo $existing_profile && $existing_profile->qty_max ? esc_attr( $existing_profile->qty_max ) : ''; ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-size: 13px; color: #666;">Lead Time Min (Days)</label>
                            <input type="number" id="n88-lead-time-min" name="lead_time_min_days" min="0" value="<?php echo $existing_profile && $existing_profile->lead_time_min_days ? esc_attr( $existing_profile->lead_time_min_days ) : ''; ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-size: 13px; color: #666;">Lead Time Max (Days)</label>
                            <input type="number" id="n88-lead-time-max" name="lead_time_max_days" min="0" value="<?php echo $existing_profile && $existing_profile->lead_time_max_days ? esc_attr( $existing_profile->lead_time_max_days ) : ''; ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                    <button type="submit" id="n88-submit-profile" style="padding: 12px 24px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; width: 100%;">
                        Save Profile
                    </button>
                    <div id="n88-form-message" style="margin-top: 15px; font-size: 14px; display: none;"></div>
                </div>
            </form>
        </div>

        <script>
        (function() {
            var form = document.getElementById('n88-supplier-onboarding-form');
            var categorySelect = document.getElementById('n88-primary-category');
            var keywordsContainer = document.getElementById('n88-keywords-container');
            var submitButton = document.getElementById('n88-submit-profile');
            var messageDiv = document.getElementById('n88-form-message');

            // Existing keyword IDs for prefilling (Commit 2.2.7)
            var existingKeywordIds = <?php echo json_encode( array_map( 'intval', $existing_keyword_ids ) ); ?>;

            // Function to load keywords
            function loadKeywords(categoryId, prefillIds) {
                if (!categoryId) {
                    keywordsContainer.innerHTML = '<p style="margin: 0; color: #999; font-size: 13px; font-style: italic;">Select a category first to see keywords</p>';
                    return;
                }

                keywordsContainer.innerHTML = '<p style="margin: 0; color: #666; font-size: 13px;">Loading keywords...</p>';

                // Fetch keywords for selected category
                var formData = new FormData();
                formData.append('action', 'n88_get_keywords_by_category');
                formData.append('category_id', categoryId);
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce( 'n88_get_keywords' ); ?>');

                fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data && data.data.keywords) {
                        keywordsContainer.innerHTML = '';
                        data.data.keywords.forEach(function(keyword) {
                            var label = document.createElement('label');
                            label.style.cssText = 'display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background-color: #fff; border: 1px solid #ddd; border-radius: 20px; cursor: pointer; font-size: 13px; margin: 0;';
                            
                            var checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.name = 'selected_keywords[]';
                            checkbox.value = keyword.keyword_id;
                            checkbox.style.cssText = 'width: 16px; height: 16px; cursor: pointer; margin: 0;';
                            
                            // Prefill if this keyword was previously selected
                            if (prefillIds && prefillIds.indexOf(parseInt(keyword.keyword_id)) !== -1) {
                                checkbox.checked = true;
                            }
                            
                            var span = document.createElement('span');
                            span.textContent = keyword.keyword;
                            span.style.color = '#333';
                            
                            label.appendChild(checkbox);
                            label.appendChild(span);
                            keywordsContainer.appendChild(label);
                        });

                        if (data.data.keywords.length === 0) {
                            keywordsContainer.innerHTML = '<p style="margin: 0; color: #999; font-size: 13px; font-style: italic;">No keywords available for this category</p>';
                        }
                    } else {
                        keywordsContainer.innerHTML = '<p style="margin: 0; color: #d63638; font-size: 13px;">Error loading keywords</p>';
                    }
                })
                .catch(error => {
                    keywordsContainer.innerHTML = '<p style="margin: 0; color: #d63638; font-size: 13px;">Error loading keywords</p>';
                });
            }

            // Load keywords when category changes
            categorySelect.addEventListener('change', function() {
                loadKeywords(this.value, existingKeywordIds);
            });

            // Load keywords on page load if category is already selected (edit mode)
            <?php if ( $existing_profile && $existing_profile->primary_category_id ) : ?>
            loadKeywords(<?php echo intval( $existing_profile->primary_category_id ); ?>, existingKeywordIds);
            <?php endif; ?>

            // Handle form submission
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                var formData = new FormData(form);
                formData.append('action', 'n88_save_supplier_profile');

                submitButton.disabled = true;
                submitButton.textContent = 'Saving...';
                messageDiv.style.display = 'none';

                fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        messageDiv.style.cssText = 'margin-top: 15px; font-size: 14px; display: block; padding: 12px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 4px;';
                        messageDiv.textContent = data.data.message || 'Profile saved successfully!';
                        
                        // Redirect after 2 seconds
                        setTimeout(function() {
                            window.location.href = '<?php echo esc_url( home_url( '/supplier/queue' ) ); ?>';
                        }, 2000);
                    } else {
                        messageDiv.style.cssText = 'margin-top: 15px; font-size: 14px; display: block; padding: 12px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;';
                        messageDiv.textContent = data.data.message || 'Error saving profile. Please try again.';
                        submitButton.disabled = false;
                        submitButton.textContent = 'Save Profile';
                    }
                })
                .catch(error => {
                    messageDiv.style.cssText = 'margin-top: 15px; font-size: 14px; display: block; padding: 12px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;';
                    messageDiv.textContent = 'Error saving profile. Please try again.';
                    submitButton.disabled = false;
                    submitButton.textContent = 'Save Profile';
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler to fetch keywords by category (Commit 2.2.7)
     */
    public function ajax_get_keywords_by_category() {
        check_ajax_referer( 'n88_get_keywords', '_ajax_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        if ( ! in_array( 'n88_supplier_admin', $current_user->roles, true ) ) {
            wp_send_json_error( array( 'message' => 'Access denied.' ) );
        }

        $category_id = isset( $_POST['category_id'] ) ? intval( $_POST['category_id'] ) : 0;

        if ( ! $category_id ) {
            wp_send_json_error( array( 'message' => 'Category ID required.' ) );
        }

        global $wpdb;
        $keywords_table = $wpdb->prefix . 'n88_keywords';

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT keyword_id, keyword FROM {$keywords_table} WHERE category_id = %d AND is_active = 1 ORDER BY keyword",
            $category_id
        ) );

        wp_send_json_success( array( 'keywords' => $keywords ) );
    }

    /**
     * AJAX handler to save supplier profile (Commit 2.2.7)
     */
    public function ajax_save_supplier_profile() {
        check_ajax_referer( 'n88_save_supplier_profile', 'n88_supplier_profile_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        if ( ! in_array( 'n88_supplier_admin', $current_user->roles, true ) ) {
            wp_send_json_error( array( 'message' => 'Access denied. Supplier Admin account required.' ) );
        }

        $user_id = $current_user->ID;

        // Get and sanitize form data
        $primary_category_id = isset( $_POST['primary_category_id'] ) ? intval( $_POST['primary_category_id'] ) : 0;
        $selected_keywords = isset( $_POST['selected_keywords'] ) ? array_map( 'intval', (array) $_POST['selected_keywords'] ) : array();
        $freeform_keywords = isset( $_POST['freeform_keywords'] ) ? sanitize_textarea_field( wp_unslash( $_POST['freeform_keywords'] ) ) : '';
        $prototype_video_capable = isset( $_POST['prototype_video_capable'] ) ? 1 : 0;
        $cad_capable = isset( $_POST['cad_capable'] ) ? 1 : 0;
        $qty_min = isset( $_POST['qty_min'] ) ? intval( $_POST['qty_min'] ) : null;
        $qty_max = isset( $_POST['qty_max'] ) ? intval( $_POST['qty_max'] ) : null;
        $lead_time_min_days = isset( $_POST['lead_time_min_days'] ) ? intval( $_POST['lead_time_min_days'] ) : null;
        $lead_time_max_days = isset( $_POST['lead_time_max_days'] ) ? intval( $_POST['lead_time_max_days'] ) : null;

        // Validate required fields
        if ( ! $primary_category_id ) {
            wp_send_json_error( array( 'message' => 'Primary category is required.' ) );
        }

        if ( ! $prototype_video_capable ) {
            wp_send_json_error( array( 'message' => 'Prototype video capability is required.' ) );
        }

        global $wpdb;
        $supplier_profiles_table = $wpdb->prefix . 'n88_supplier_profiles';
        $supplier_keyword_map_table = $wpdb->prefix . 'n88_supplier_keyword_map';
        $supplier_keyword_freeform_table = $wpdb->prefix . 'n88_supplier_keyword_freeform';
        $categories_table = $wpdb->prefix . 'n88_categories';

        // Verify category exists
        $category_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT category_id FROM {$categories_table} WHERE category_id = %d AND is_active = 1",
            $primary_category_id
        ) );

        if ( ! $category_exists ) {
            wp_send_json_error( array( 'message' => 'Invalid category selected.' ) );
        }

        // Verify selected keywords exist and belong to the selected category
        if ( ! empty( $selected_keywords ) ) {
            $keywords_table = $wpdb->prefix . 'n88_keywords';
            $placeholders = implode( ',', array_fill( 0, count( $selected_keywords ), '%d' ) );
            $query = $wpdb->prepare(
                "SELECT keyword_id FROM {$keywords_table} WHERE keyword_id IN ($placeholders) AND category_id = %d AND is_active = 1",
                array_merge( $selected_keywords, array( $primary_category_id ) )
            );
            $valid_keywords = $wpdb->get_col( $query );
            $selected_keywords = array_intersect( $selected_keywords, $valid_keywords );
        }

        // Start transaction (using WordPress transaction pattern)
        $wpdb->query( 'START TRANSACTION' );

        try {
            // Save or update supplier profile
            $existing_profile = $wpdb->get_var( $wpdb->prepare(
                "SELECT supplier_id FROM {$supplier_profiles_table} WHERE supplier_id = %d",
                $user_id
            ) );

            $profile_data = array(
                'supplier_id' => $user_id,
                'primary_category_id' => $primary_category_id,
                'prototype_video_capable' => $prototype_video_capable,
                'cad_capable' => $cad_capable,
                'qty_min' => $qty_min ? $qty_min : null,
                'qty_max' => $qty_max ? $qty_max : null,
                'lead_time_min_days' => $lead_time_min_days ? $lead_time_min_days : null,
                'lead_time_max_days' => $lead_time_max_days ? $lead_time_max_days : null,
            );

            if ( $existing_profile ) {
                // Update existing profile
                $wpdb->update(
                    $supplier_profiles_table,
                    $profile_data,
                    array( 'supplier_id' => $user_id ),
                    array( '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d' ),
                    array( '%d' )
                );
            } else {
                // Insert new profile (minimal required fields)
                $profile_data['company_name'] = $current_user->display_name ? $current_user->display_name : 'Supplier';
                $wpdb->insert(
                    $supplier_profiles_table,
                    $profile_data,
                    array( '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d' )
                );
            }

            // Delete existing keyword mappings for this supplier
            $wpdb->delete(
                $supplier_keyword_map_table,
                array( 'supplier_id' => $user_id ),
                array( '%d' )
            );

            // Insert new keyword mappings
            if ( ! empty( $selected_keywords ) ) {
                foreach ( $selected_keywords as $keyword_id ) {
                    $wpdb->insert(
                        $supplier_keyword_map_table,
                        array(
                            'supplier_id' => $user_id,
                            'keyword_id' => $keyword_id,
                        ),
                        array( '%d', '%d' )
                    );
                }
            }

            // Process freeform keywords
            if ( ! empty( $freeform_keywords ) ) {
                $freeform_lines = array_filter( array_map( 'trim', explode( "\n", $freeform_keywords ) ) );
                foreach ( $freeform_lines as $freeform_keyword ) {
                    if ( ! empty( $freeform_keyword ) ) {
                        $wpdb->insert(
                            $supplier_keyword_freeform_table,
                            array(
                                'supplier_id' => $user_id,
                                'freeform_keyword' => $freeform_keyword,
                                'status' => 'pending',
                                'created_at' => current_time( 'mysql' ),
                            ),
                            array( '%d', '%s', '%s', '%s' )
                        );
                    }
                }
            }

            $wpdb->query( 'COMMIT' );

            wp_send_json_success( array(
                'message' => 'Profile saved successfully!',
            ) );

        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array(
                'message' => 'Error saving profile. Please try again.',
            ) );
        }
    }

    /**
     * Static version of get_role_redirect_url for use in error pages (updated Commit 2.2.7)
     */
    private static function get_role_redirect_url_static( $user ) {
        if ( ! $user || ! isset( $user->roles ) ) {
            return null;
        }

        // Check roles in priority order
        if ( in_array( 'n88_system_operator', $user->roles, true ) ) {
            return home_url( '/admin/queue?scope=global' );
        }
        
        if ( in_array( 'n88_supplier_admin', $user->roles, true ) ) {
            // Commit 2.2.7: Check if supplier profile is incomplete
            global $wpdb;
            $supplier_profiles_table = $wpdb->prefix . 'n88_supplier_profiles';
            $profile_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT supplier_id FROM {$supplier_profiles_table} WHERE supplier_id = %d",
                $user->ID
            ) );
            
            // If profile doesn't exist, redirect to onboarding
            if ( ! $profile_exists ) {
                return home_url( '/supplier/onboarding' );
            }
            
            // Otherwise, redirect to queue
            return home_url( '/supplier/queue' );
        }
        
        if ( in_array( 'n88_designer', $user->roles, true ) || in_array( 'designer', $user->roles, true ) ) {
            // Commit 2.2.8: Check if designer profile is incomplete
            global $wpdb;
            $designer_profiles_table = $wpdb->prefix . 'n88_designer_profiles_v2';
            
            // Check if profile exists - use COUNT for more reliable check
            // Suppress errors in case table doesn't exist yet
            $wpdb->suppress_errors( true );
            $profile_count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$designer_profiles_table} WHERE designer_id = %d",
                $user->ID
            ) );
            $wpdb->suppress_errors( false );
            
            // If profile doesn't exist (count is false, null, or 0), redirect to onboarding
            if ( $profile_count === false || $profile_count === null || (int) $profile_count === 0 ) {
                return home_url( '/designer/onboarding' );
            }
            
            // Otherwise, redirect to workspace
            return home_url( '/workspace' );
        }

        return null;
    }


    /**
     * Render designer onboarding form (Commit 2.2.8)
     */
    public function render_designer_onboarding( $atts = array() ) {
        // Allow admins to edit pages even if they don't have designer role
        if ( is_admin() && current_user_can( 'edit_pages' ) ) {
            return '<p><em>Creator Onboarding page - This shortcode will display the creator onboarding form.</em></p>';
        }

        // Check if user is logged in and is a designer
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url( '/login/' ) );
            exit;
        }

        $current_user = wp_get_current_user();
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        
        if ( ! $is_designer ) {
            wp_die( 'Access denied. Creator account required.', 'Access Denied', array( 'response' => 403 ) );
        }

        global $wpdb;
        $designer_profiles_table = $wpdb->prefix . 'n88_designer_profiles_v2';
        $designer_practice_map_table = $wpdb->prefix . 'n88_designer_practice_map';
        $practice_types_table = $wpdb->prefix . 'n88_practice_types';
        
        // Check if profile exists and fetch existing data
        $existing_profile = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$designer_profiles_table} WHERE designer_id = %d",
            $current_user->ID
        ) );

        // Fetch existing selected practice types
        $existing_practice_ids = array();
        if ( $existing_profile ) {
            $existing_practice_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT practice_id FROM {$designer_practice_map_table} WHERE designer_id = %d",
                $current_user->ID
            ) );
        }

        // Fetch all practice types
        $practice_types = $wpdb->get_results(
            "SELECT practice_id, name FROM {$practice_types_table} ORDER BY name"
        );

        $is_edit_mode = ! empty( $existing_profile );
        $form_title = $is_edit_mode ? 'Update Designer Profile' : 'Designer Onboarding';
        $form_description = $is_edit_mode ? 'Update your profile information.' : 'Complete your profile to enable routing and matching.';

        ob_start();
        ?>
        <div class="n88-designer-onboarding" style="max-width: 800px; margin: 40px auto; padding: 40px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;">
            <h1 style="margin: 0 0 30px 0; font-size: 28px; font-weight: 600; color: #333;"><?php echo esc_html( $form_title ); ?></h1>
            <p style="margin: 0 0 30px 0; font-size: 14px; color: #666;"><?php echo esc_html( $form_description ); ?></p>
            
            <form id="n88-designer-onboarding-form" style="background-color: #fff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
                <?php wp_nonce_field( 'n88_save_designer_profile', 'n88_designer_profile_nonce' ); ?>
                
                <!-- Firm Name -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #333;">
                        Firm Name <span style="color: #d63638;">*</span>
                    </label>
                    <input type="text" id="n88-firm-name" name="firm_name" value="<?php echo $existing_profile ? esc_attr( $existing_profile->firm_name ) : ''; ?>" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                </div>

                <!-- Display Nickname -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #333;">
                        Display Nickname
                    </label>
                    <input type="text" id="n88-display-nickname" name="display_nickname" value="<?php echo $existing_profile ? esc_attr( $existing_profile->display_nickname ) : ''; ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                </div>

                <!-- Contact Name -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #333;">
                        Contact Name <span style="color: #d63638;">*</span>
                    </label>
                    <input type="text" id="n88-contact-name" name="contact_name" value="<?php echo $existing_profile ? esc_attr( $existing_profile->contact_name ) : ''; ?>" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                </div>

                <!-- Email -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #333;">
                        Email <span style="color: #d63638;">*</span>
                    </label>
                    <input type="email" id="n88-email" name="email" value="<?php echo $existing_profile ? esc_attr( $existing_profile->email ) : esc_attr( $current_user->user_email ); ?>" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                </div>

                <!-- Address Fields -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 12px; font-size: 14px; font-weight: 600; color: #333;">
                        Address
                    </label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-size: 13px; color: #666;">Country Code (2 letters)</label>
                            <input type="text" id="n88-country-code" name="country_code" value="<?php echo $existing_profile ? esc_attr( $existing_profile->country_code ) : ''; ?>" maxlength="2" placeholder="US" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-size: 13px; color: #666;">State/Region</label>
                            <input type="text" id="n88-state-region" name="state_region" value="<?php echo $existing_profile ? esc_attr( $existing_profile->state_region ) : ''; ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-size: 13px; color: #666;">City</label>
                            <input type="text" id="n88-city" name="city" value="<?php echo $existing_profile ? esc_attr( $existing_profile->city ) : ''; ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-size: 13px; color: #666;">ZipCommit 2.3.2 — Supplier RFQ Detail View (Read-Only)</label>
                            <input type="text" id="n88-postal-code" name="postal_code" value="<?php echo $existing_profile ? esc_attr( $existing_profile->postal_code ) : ''; ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        </div>
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 6px; font-size: 13px; color: #666;">Address Line 1</label>
                        <input type="text" id="n88-address-line1" name="address_line1" value="<?php echo $existing_profile ? esc_attr( $existing_profile->address_line1 ) : ''; ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                    </div>
                </div>

                <!-- Practice Types -->
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #333;">
                        Practice Types
                    </label>
                    <p style="margin: 0 0 12px 0; font-size: 13px; color: #666;">Select the practice types that apply to your firm.</p>
                    <div style="display: flex; flex-wrap: wrap; gap: 12px; padding: 12px; border: 1px solid #ddd; border-radius: 4px; background-color: #f9f9f9;">
                        <?php foreach ( $practice_types as $practice ) : ?>
                            <label style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background-color: #fff; border: 1px solid #ddd; border-radius: 20px; cursor: pointer; font-size: 14px;">
                                <input type="checkbox" name="practice_types[]" value="<?php echo esc_attr( $practice->practice_id ); ?>" <?php checked( in_array( $practice->practice_id, $existing_practice_ids ) ); ?> style="width: 18px; height: 18px; cursor: pointer; margin: 0;">
                                <span style="color: #333;"><?php echo esc_html( $practice->name ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Default Allow System Invites -->
                <div style="margin-bottom: 25px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" id="n88-default-allow-system-invites" name="default_allow_system_invites" value="1" <?php checked( $existing_profile && $existing_profile->default_allow_system_invites == 1 ); ?> style="width: 18px; height: 18px; cursor: pointer;">
                        <span style="font-size: 14px; color: #333;">Default Allow System Invites</span>
                    </label>
                </div>

                <!-- Submit Button -->
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                    <button type="submit" id="n88-submit-designer-profile" style="padding: 12px 24px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; width: 100%;">
                        Save Profile
                    </button>
                    <div id="n88-designer-form-message" style="margin-top: 15px; font-size: 14px; display: none;"></div>
                </div>
            </form>
        </div>

        <script>
        (function() {
            var form = document.getElementById('n88-designer-onboarding-form');
            var submitButton = document.getElementById('n88-submit-designer-profile');
            var messageDiv = document.getElementById('n88-designer-form-message');

            // Handle form submission
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                var formData = new FormData(form);
                formData.append('action', 'n88_save_designer_profile');

                submitButton.disabled = true;
                submitButton.textContent = 'Saving...';
                messageDiv.style.display = 'none';

                fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        messageDiv.style.cssText = 'margin-top: 15px; font-size: 14px; display: block; padding: 12px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 4px;';
                        messageDiv.textContent = data.data.message || 'Profile saved successfully!';
                        
                        // Redirect after 2 seconds
                        setTimeout(function() {
                            window.location.href = '<?php echo esc_url( home_url( '/workspace' ) ); ?>';
                        }, 2000);
                    } else {
                        messageDiv.style.cssText = 'margin-top: 15px; font-size: 14px; display: block; padding: 12px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;';
                        messageDiv.textContent = data.data.message || 'Error saving profile. Please try again.';
                        submitButton.disabled = false;
                        submitButton.textContent = 'Save Profile';
                    }
                })
                .catch(error => {
                    messageDiv.style.cssText = 'margin-top: 15px; font-size: 14px; display: block; padding: 12px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;';
                    messageDiv.textContent = 'Error saving profile. Please try again.';
                    submitButton.disabled = false;
                    submitButton.textContent = 'Save Profile';
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler to save designer profile (Commit 2.2.8)
     */
    public function ajax_save_designer_profile() {
        check_ajax_referer( 'n88_save_designer_profile', 'n88_designer_profile_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        
        if ( ! $is_designer ) {
            wp_send_json_error( array( 'message' => 'Access denied. Creator account required.' ) );
        }

        $user_id = $current_user->ID;

        // Get and sanitize form data
        $firm_name = isset( $_POST['firm_name'] ) ? sanitize_text_field( wp_unslash( $_POST['firm_name'] ) ) : '';
        $display_nickname = isset( $_POST['display_nickname'] ) ? sanitize_text_field( wp_unslash( $_POST['display_nickname'] ) ) : '';
        $contact_name = isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_name'] ) ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $country_code = isset( $_POST['country_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country_code'] ) ) ) : '';
        $state_region = isset( $_POST['state_region'] ) ? sanitize_text_field( wp_unslash( $_POST['state_region'] ) ) : '';
        $city = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
        $postal_code = isset( $_POST['postal_code'] ) ? sanitize_text_field( wp_unslash( $_POST['postal_code'] ) ) : '';
        $address_line1 = isset( $_POST['address_line1'] ) ? sanitize_text_field( wp_unslash( $_POST['address_line1'] ) ) : '';
        $default_allow_system_invites = isset( $_POST['default_allow_system_invites'] ) ? 1 : 0;
        $practice_types = isset( $_POST['practice_types'] ) ? array_map( 'intval', (array) $_POST['practice_types'] ) : array();

        // Validate required fields
        if ( empty( $firm_name ) ) {
            wp_send_json_error( array( 'message' => 'Firm name is required.' ) );
        }

        if ( empty( $contact_name ) ) {
            wp_send_json_error( array( 'message' => 'Contact name is required.' ) );
        }

        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Valid email is required.' ) );
        }

        global $wpdb;
        $designer_profiles_table = $wpdb->prefix . 'n88_designer_profiles_v2';
        $designer_practice_map_table = $wpdb->prefix . 'n88_designer_practice_map';
        $practice_types_table = $wpdb->prefix . 'n88_practice_types';

        // Verify practice types exist
        if ( ! empty( $practice_types ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $practice_types ), '%d' ) );
            $query = $wpdb->prepare(
                "SELECT practice_id FROM {$practice_types_table} WHERE practice_id IN ($placeholders)",
                $practice_types
            );
            $valid_practice_types = $wpdb->get_col( $query );
            $practice_types = array_intersect( $practice_types, $valid_practice_types );
        }

        // Start transaction
        $wpdb->query( 'START TRANSACTION' );

        try {
            // Save or update designer profile
            $existing_profile = $wpdb->get_var( $wpdb->prepare(
                "SELECT designer_id FROM {$designer_profiles_table} WHERE designer_id = %d",
                $user_id
            ) );

            $profile_data = array(
                'designer_id' => $user_id,
                'firm_name' => $firm_name,
                'display_nickname' => $display_nickname ? $display_nickname : null,
                'contact_name' => $contact_name,
                'email' => $email,
                'country_code' => $country_code ? $country_code : null,
                'state_region' => $state_region ? $state_region : null,
                'city' => $city ? $city : null,
                'postal_code' => $postal_code ? $postal_code : null,
                'address_line1' => $address_line1 ? $address_line1 : null,
                'default_allow_system_invites' => $default_allow_system_invites,
            );

            if ( $existing_profile ) {
                // Update existing profile
                $wpdb->update(
                    $designer_profiles_table,
                    $profile_data,
                    array( 'designer_id' => $user_id ),
                    array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ),
                    array( '%d' )
                );
            } else {
                // Insert new profile
                $wpdb->insert(
                    $designer_profiles_table,
                    $profile_data,
                    array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
                );
            }

            // Delete existing practice type mappings for this designer
            $wpdb->delete(
                $designer_practice_map_table,
                array( 'designer_id' => $user_id ),
                array( '%d' )
            );

            // Insert new practice type mappings
            if ( ! empty( $practice_types ) ) {
                foreach ( $practice_types as $practice_id ) {
                    $wpdb->insert(
                        $designer_practice_map_table,
                        array(
                            'designer_id' => $user_id,
                            'practice_id' => $practice_id,
                        ),
                        array( '%d', '%d' )
                    );
                }
            }

            $wpdb->query( 'COMMIT' );

            wp_send_json_success( array(
                'message' => 'Profile saved successfully!',
            ) );

        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array(
                'message' => 'Error saving profile. Please try again.',
            ) );
        }
    }

    /**
     * AJAX handler to fetch supplier item details (read-only) (Commit 2.3.2)
     * Returns item details only if supplier has a route for the item
     * No database writes - strictly read-only
     */
    public function ajax_get_supplier_item_details() {
        check_ajax_referer( 'n88_get_supplier_item_details', '_ajax_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        if ( ! $is_supplier && ! $is_designer && ! $is_system_operator ) {
            wp_send_json_error( array( 'message' => 'Access denied. Maker, Creator, or Super Admin account required.' ) );
        }

        $item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
        
        if ( ! $item_id ) {
            wp_send_json_error( array( 'message' => 'Invalid item ID.' ) );
        }

        // DEMO ITEM FOR TESTING (Commit 2.3.2/2.3.3) - Remove when routing is implemented
        $demo_item_id = 9999;
        if ( $item_id === $demo_item_id ) {
            // Return demo item data
            $response = array(
                'item_id' => $demo_item_id,
                'title' => 'Demo Curved Sofa',
                'description' => 'Modern curved sofa for reception area. This is a demo item for testing the RFQ detail view and bid modal functionality.',
                'category' => 'Upholstery',
                'image_url' => '', // No image for demo
                'quantity' => 2,
                'dimensions' => array(
                    'w' => 240,
                    'd' => 100,
                    'h' => 85,
                    'unit' => 'cm'
                ),
                'sourcing_type' => 'furniture',
                'timeline_type' => '6-step furniture',
                'delivery_country' => 'US',
                'delivery_postal_code' => '10001',
                'shipping_mode_label' => 'Instant shipping estimate available',
                'route_label' => 'Designer-invited RFQ',
                'reference_images' => array(),
                'media_links' => array(),
            );
            wp_send_json_success( $response );
        }

        global $wpdb;
        $items_table = $wpdb->prefix . 'n88_items';
        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
        $item_delivery_context_table = $wpdb->prefix . 'n88_item_delivery_context';
        $categories_table = $wpdb->prefix . 'n88_categories';

        // CRITICAL: Permission check
        // - System operators can view any item
        // - Designers can view their own items
        // - Suppliers can only view items they have a route for
        if ( ! $is_system_operator ) {
            if ( $is_designer ) {
                // Designer can view their own items - check ownership
                $item_owner = $wpdb->get_var( $wpdb->prepare(
                    "SELECT owner_user_id FROM {$items_table} WHERE id = %d",
                    $item_id
                ) );
                
                if ( ! $item_owner || intval( $item_owner ) !== $current_user->ID ) {
                    wp_send_json_error( array( 'message' => 'Access denied. You can only view your own items.' ), 403 );
                }
            } else if ( $is_supplier ) {
                // Commit 2.3.7.1: Check and update expired routes (lazy evaluation)
                $this->check_and_update_expired_routes( $item_id, $current_user->ID );
                
                // Check if route is expired
                if ( $this->is_route_expired( $item_id, $current_user->ID ) ) {
                    wp_send_json_error( array( 'message' => 'This RFQ is no longer accepting bids.' ), 403 );
                }
                
                // Supplier can only view items they have a route for
                $route_exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$rfq_routes_table} 
                    WHERE item_id = %d 
                    AND supplier_id = %d 
                    AND status IN ('queued', 'sent', 'viewed', 'bid_submitted')",
                    $item_id,
                    $current_user->ID
                ) );

                if ( ! $route_exists || intval( $route_exists ) === 0 ) {
                    wp_send_json_error( array( 'message' => 'Access denied. You do not have permission to view this item.' ), 403 );
                }
            }
        }

        // Fetch item data
        // Check if meta_json column exists
        $items_columns = $wpdb->get_col( "DESCRIBE {$items_table}" );
        $has_meta_json = in_array( 'meta_json', $items_columns, true );
        
        // Build SELECT query - include owner_user_id and meta_json if column exists
        $select_fields = "id, title, description, item_type, primary_image_id, deleted_at, owner_user_id";
        if ( $has_meta_json ) {
            $select_fields .= ", meta_json";
        }
        
        // First check if item exists (even if deleted)
        $item_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$items_table} WHERE id = %d",
            $item_id
        ) );
        
        if ( ! $item_exists || intval( $item_exists ) === 0 ) {
            wp_send_json_error( array( 'message' => 'Item not found. Item ID: ' . $item_id . ' does not exist in the database.' ) );
        }
        
        // Now fetch the item (must not be deleted)
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT {$select_fields}
             FROM {$items_table}
             WHERE id = %d",
            $item_id
        ), ARRAY_A );

        if ( ! $item ) {
            wp_send_json_error( array( 'message' => 'Item not found.' ) );
        }
        
        // Check if item is soft-deleted
        if ( ! empty( $item['deleted_at'] ) ) {
            wp_send_json_error( array( 'message' => 'Item has been deleted. deleted_at: ' . esc_html( $item['deleted_at'] ) ) );
        }
        
        // Initialize meta_json if column doesn't exist
        if ( ! $has_meta_json ) {
            $item['meta_json'] = null;
        }

        // Parse meta_json for dimensions, sourcing_type, timeline_type, etc.
        $meta = array();
        if ( ! empty( $item['meta_json'] ) ) {
            $decoded_meta = json_decode( $item['meta_json'], true );
            if ( is_array( $decoded_meta ) ) {
                $meta = $decoded_meta;
            }
            // Debug: Log meta_json parsing
            error_log( 'Supplier Detail View - Parsed meta_json for item ' . $item_id . ': ' . wp_json_encode( $meta ) );
        } else {
            error_log( 'Supplier Detail View - meta_json is empty for item ' . $item_id );
        }

        // Get item image - ensure absolute URL
        $image_url = '';
        $primary_image_url = '';
        if ( ! empty( $item['primary_image_id'] ) ) {
            $image_url = wp_get_attachment_image_url( $item['primary_image_id'], 'full' );
            if ( ! $image_url ) {
                $image_url = wp_get_attachment_url( $item['primary_image_id'] );
            }
            // Ensure absolute URL (wp_get_attachment_image_url should already return absolute, but double-check)
            if ( $image_url ) {
                // If relative URL, convert to absolute
                if ( ! preg_match( '/^https?:\/\//', $image_url ) ) {
                    $image_url = home_url( $image_url );
                }
                $primary_image_url = $image_url;
            }
        }

        // Get category name
        $category_name = '';
        if ( ! empty( $item['item_type'] ) ) {
            $category = $wpdb->get_row( $wpdb->prepare(
                "SELECT name FROM {$categories_table} WHERE category_id = %d OR name = %s LIMIT 1",
                intval( $item['item_type'] ),
                $item['item_type']
            ) );
            if ( $category ) {
                $category_name = $category->name;
            } else {
                $category_name = $item['item_type'];
            }
        }

        // Get delivery context (including quantity and dimensions from RFQ submission)
        $delivery_columns = $wpdb->get_col( "DESCRIBE {$item_delivery_context_table}" );
        $has_quantity = in_array( 'quantity', $delivery_columns, true );
        $has_dimensions = in_array( 'dimensions_json', $delivery_columns, true );
        
        $select_fields = "delivery_country_code, delivery_postal_code, shipping_estimate_mode";
        if ( $has_quantity ) {
            $select_fields .= ", quantity";
        }
        if ( $has_dimensions ) {
            $select_fields .= ", dimensions_json";
        }
        
        $delivery_context = $wpdb->get_row( $wpdb->prepare(
            "SELECT {$select_fields}
             FROM {$item_delivery_context_table}
             WHERE item_id = %d",
            $item_id
        ), ARRAY_A );

        // Determine shipping mode label
        $shipping_mode_label = 'Shipping info not provided yet';
        if ( $delivery_context ) {
            if ( $delivery_context['shipping_estimate_mode'] === 'auto' ) {
                $shipping_mode_label = 'Instant shipping estimate available';
            } else {
                $shipping_mode_label = 'Shipping will be quoted manually';
            }
        }

        // Get routing context (for supplier only, system operators don't need this)
        $routing_context = null;
        $route_label = '';
        if ( ! $is_system_operator ) {
            $route = $wpdb->get_row( $wpdb->prepare(
                "SELECT route_type FROM {$rfq_routes_table}
                 WHERE item_id = %d AND supplier_id = %d
                 ORDER BY route_id DESC LIMIT 1",
                $item_id,
                $current_user->ID
            ), ARRAY_A );

            if ( $route ) {
                if ( $route['route_type'] === 'designer_invited' ) {
                    $route_label = 'Designer-invited RFQ';
                } elseif ( $route['route_type'] === 'system_invited' ) {
                    $route_label = 'System-invited RFQ';
                }
            }
        }

        // Get reference images (from item attachments or meta) - ensure absolute URLs
        $reference_images = array();
        $inspiration_images = array();
        
        // Debug: Check if inspiration exists in meta
        if ( ! isset( $meta['inspiration'] ) ) {
            error_log( 'Supplier Detail View - No inspiration key in meta for item ' . $item_id );
        } elseif ( ! is_array( $meta['inspiration'] ) ) {
            error_log( 'Supplier Detail View - Inspiration is not an array for item ' . $item_id . ', type: ' . gettype( $meta['inspiration'] ) );
        } elseif ( empty( $meta['inspiration'] ) ) {
            error_log( 'Supplier Detail View - Inspiration array is empty for item ' . $item_id );
        }
        
        if ( isset( $meta['inspiration'] ) && is_array( $meta['inspiration'] ) && ! empty( $meta['inspiration'] ) ) {
            // Debug: Log raw inspiration data
            error_log( 'Supplier Detail View - Processing ' . count( $meta['inspiration'] ) . ' inspiration items for item ' . $item_id );
            error_log( 'Supplier Detail View - Raw inspiration data for item ' . $item_id . ': ' . wp_json_encode( $meta['inspiration'] ) );
            foreach ( $meta['inspiration'] as $insp_item ) {
                $thumb_url = '';
                $full_url = '';
                $img_id = null;
                
                // Priority 1: Use attachment ID if available (most reliable)
                if ( isset( $insp_item['id'] ) && ! empty( $insp_item['id'] ) ) {
                    $img_id = intval( $insp_item['id'] );
                    
                    // Verify attachment exists in database
                    $attachment_exists = get_post( $img_id );
                    if ( $attachment_exists && $attachment_exists->post_type === 'attachment' ) {
                        // Try medium size first (better for thumbnails), fallback to thumbnail, then full
                        $thumb_url = wp_get_attachment_image_url( $img_id, 'medium' );
                        if ( ! $thumb_url ) {
                            $thumb_url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
                        }
                        if ( ! $thumb_url ) {
                            $thumb_url = wp_get_attachment_url( $img_id );
                        }
                        
                        $full_url = wp_get_attachment_image_url( $img_id, 'full' );
                        if ( ! $full_url ) {
                            $full_url = wp_get_attachment_url( $img_id );
                        }
                        
                        // Ensure absolute URLs
                        if ( $thumb_url && ! preg_match( '/^https?:\/\//', $thumb_url ) ) {
                            $thumb_url = home_url( $thumb_url );
                        }
                        if ( $full_url && ! preg_match( '/^https?:\/\//', $full_url ) ) {
                            $full_url = home_url( $full_url );
                        }
                    } else {
                        // Attachment ID doesn't exist, log and try URL fallback
                        error_log( 'Supplier Detail View - Attachment ID ' . $img_id . ' does not exist for item ' . $item_id );
                        $img_id = null; // Reset so we don't include invalid ID
                    }
                }
                
                // Priority 2: Use URL from inspiration item if ID didn't work or URL is provided
                if ( empty( $thumb_url ) && isset( $insp_item['url'] ) && ! empty( $insp_item['url'] ) ) {
                    $img_url = trim( $insp_item['url'] );
                    
                    // Check if URL is incomplete (just domain, with or without trailing slash)
                    // Valid URLs must have a path component (not just domain or domain/)
                    $is_incomplete_url = false;
                    if ( preg_match( '/^https?:\/\/[^\/]+$/', $img_url ) ) {
                        // URL is just domain (no trailing slash)
                        $is_incomplete_url = true;
                    } elseif ( preg_match( '/^https?:\/\/[^\/]+\/$/', $img_url ) ) {
                        // URL is just domain with trailing slash (still incomplete)
                        $is_incomplete_url = true;
                    } elseif ( ! preg_match( '/^https?:\/\/.+\/.+/', $img_url ) && ! preg_match( '/^https?:\/\/.+\.[a-z]{2,4}\//i', $img_url ) ) {
                        // URL doesn't have a proper path component
                        $is_incomplete_url = true;
                    }
                    
                    if ( $is_incomplete_url ) {
                        // URL is incomplete, try to find attachment by other means
                        error_log( 'Supplier Detail View - Incomplete inspiration URL (domain only): ' . $img_url . ' for item ' . $item_id );
                        
                        // Try to find attachment by searching in post meta or by filename if we have title
                        if ( isset( $insp_item['title'] ) && ! empty( $insp_item['title'] ) ) {
                            global $wpdb;
                            $filename = sanitize_file_name( $insp_item['title'] );
                            // Try to find attachment by filename
                            $found_attachment = $wpdb->get_var( $wpdb->prepare(
                                "SELECT ID FROM {$wpdb->posts} 
                                WHERE post_type = 'attachment' 
                                AND (post_title LIKE %s OR guid LIKE %s)
                                LIMIT 1",
                                '%' . $wpdb->esc_like( $filename ) . '%',
                                '%' . $wpdb->esc_like( $filename ) . '%'
                            ) );
                            
                            if ( $found_attachment ) {
                                $img_id = intval( $found_attachment );
                                $thumb_url = wp_get_attachment_image_url( $img_id, 'medium' );
                                if ( ! $thumb_url ) {
                                    $thumb_url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
                                }
                                if ( ! $thumb_url ) {
                                    $thumb_url = wp_get_attachment_url( $img_id );
                                }
                                $full_url = wp_get_attachment_image_url( $img_id, 'full' );
                                if ( ! $full_url ) {
                                    $full_url = wp_get_attachment_url( $img_id );
                                }
                                
                                // Ensure absolute URLs
                                if ( $thumb_url && ! preg_match( '/^https?:\/\//', $thumb_url ) ) {
                                    $thumb_url = home_url( $thumb_url );
                                }
                                if ( $full_url && ! preg_match( '/^https?:\/\//', $full_url ) ) {
                                    $full_url = home_url( $full_url );
                                }
                                
                                error_log( 'Supplier Detail View - Found attachment by filename: ' . $img_id . ' for item ' . $item_id );
                            }
                        }
                        
                        // Also try to find attachment by ID if we have one but URL is incomplete
                        if ( empty( $thumb_url ) && isset( $insp_item['id'] ) && ! empty( $insp_item['id'] ) ) {
                            $try_id = intval( $insp_item['id'] );
                            $try_attachment = get_post( $try_id );
                            if ( $try_attachment && $try_attachment->post_type === 'attachment' ) {
                                $thumb_url = wp_get_attachment_image_url( $try_id, 'medium' );
                                if ( ! $thumb_url ) {
                                    $thumb_url = wp_get_attachment_image_url( $try_id, 'thumbnail' );
                                }
                                if ( ! $thumb_url ) {
                                    $thumb_url = wp_get_attachment_url( $try_id );
                                }
                                $full_url = wp_get_attachment_image_url( $try_id, 'full' );
                                if ( ! $full_url ) {
                                    $full_url = wp_get_attachment_url( $try_id );
                                }
                                
                                // Ensure absolute URLs
                                if ( $thumb_url && ! preg_match( '/^https?:\/\//', $thumb_url ) ) {
                                    $thumb_url = home_url( $thumb_url );
                                }
                                if ( $full_url && ! preg_match( '/^https?:\/\//', $full_url ) ) {
                                    $full_url = home_url( $full_url );
                                }
                                
                                $img_id = $try_id;
                                error_log( 'Supplier Detail View - Found attachment by ID (from incomplete URL): ' . $img_id . ' for item ' . $item_id );
                            }
                        }
                        
                        // If still no valid URL, skip this inspiration item
                        if ( empty( $thumb_url ) ) {
                            error_log( 'Supplier Detail View - Skipping inspiration image with incomplete URL (item_id: ' . $item_id . ', url: ' . $img_url . ')' );
                            continue;
                        }
                    } else {
                        // URL has a proper path, validate and use it
                        $thumb_url = esc_url_raw( $img_url );
                        $full_url = esc_url_raw( $img_url );
                        
                        // Try to get attachment ID from URL if we don't have one
                        if ( ! $img_id ) {
                            $found_id = attachment_url_to_postid( $img_url );
                            if ( $found_id > 0 ) {
                                $img_id = $found_id;
                                // Prefer WordPress-generated URLs if we found the attachment
                                $better_thumb = wp_get_attachment_image_url( $img_id, 'medium' );
                                if ( $better_thumb ) {
                                    $thumb_url = $better_thumb;
                                }
                                $better_full = wp_get_attachment_image_url( $img_id, 'full' );
                                if ( $better_full ) {
                                    $full_url = $better_full;
                                }
                            }
                        }
                    }
                }
                
                // Only add if we have a valid URL with a proper path (not just domain or domain/)
                // Require at least one character after the domain and a slash, then more path
                if ( ! empty( $thumb_url ) && preg_match( '/^https?:\/\/.+\/.+/', $thumb_url ) ) {
                    // Ensure absolute URLs
                    if ( ! preg_match( '/^https?:\/\//', $thumb_url ) ) {
                        $thumb_url = home_url( $thumb_url );
                    }
                    if ( $full_url && ! preg_match( '/^https?:\/\//', $full_url ) ) {
                        $full_url = home_url( $full_url );
                    }
                    
                    $img_data = array();
                    if ( $img_id ) {
                        $img_data['id'] = $img_id;
                    }
                    $img_data['url'] = esc_url_raw( $thumb_url );
                    $img_data['full_url'] = esc_url_raw( $full_url ? $full_url : $thumb_url );
                    // Commit 2.3.5.4: Include type information for PDFs
                    $img_data['type'] = isset( $insp_item['type'] ) ? sanitize_text_field( $insp_item['type'] ) : 'image';
                    // Also check URL extension for PDF
                    if ( $img_data['type'] !== 'pdf' && preg_match( '/\.pdf$/i', $thumb_url ) ) {
                        $img_data['type'] = 'pdf';
                    }
                    // Get filename for PDF display
                    if ( $img_data['type'] === 'pdf' ) {
                        $img_data['filename'] = isset( $insp_item['title'] ) ? sanitize_text_field( $insp_item['title'] ) : ( isset( $insp_item['filename'] ) ? sanitize_text_field( $insp_item['filename'] ) : basename( $thumb_url ) );
                    }
                    
                    $reference_images[] = $img_data;
                    $inspiration_images[] = $img_data;
                    
                    error_log( 'Supplier Detail View - Added inspiration image (item_id: ' . $item_id . ', url: ' . $thumb_url . ', id: ' . ( $img_id ? $img_id : 'none' ) . ')' );
                } else {
                    error_log( 'Supplier Detail View - Skipping invalid inspiration image (item_id: ' . $item_id . ', url: ' . ( isset( $insp_item['url'] ) ? $insp_item['url'] : 'none' ) . ', id: ' . ( isset( $insp_item['id'] ) ? $insp_item['id'] : 'none' ) . ', resolved_thumb_url: ' . ( $thumb_url ? $thumb_url : 'empty' ) . ')' );
                }
            }
        }

        // Get media links (if stored in meta or separate table)
        $media_links = array();
        if ( isset( $meta['media_links'] ) && is_array( $meta['media_links'] ) ) {
            $media_links = $meta['media_links'];
        }

        // Get bid status and created_at FIRST (Commit 2.3.5 - check if bid already submitted)
        // Commit 2.3.5.1 Addendum: Also get created_at to detect if dims/qty changed after bid submission
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $bid_media_links_table = $wpdb->prefix . 'n88_bid_media_links';
        $bid_media_files_table = $wpdb->prefix . 'n88_bid_media_files';
        $bid_status = null;
        $bid_created_at = null;
        $show_dims_qty_warning = false;
        $has_submitted_bid = false;
        $bid_data = null;
        $bid_status = null; // Initialize bid_status
        $bid_revision = null; // Initialize bid_revision to prevent undefined variable
        if ( ! $is_system_operator ) {
            // Check if meta_json column exists in bids table
            $bids_columns = $wpdb->get_col( "DESCRIBE {$item_bids_table}" );
            $has_bid_meta_json = in_array( 'meta_json', $bids_columns, true );
            
            // G) Check for revision column in bids table
            $has_revision_column = in_array( 'rfq_revision_at_submit', $bids_columns, true );
            
            $select_bid_fields = "bid_id, status, created_at, unit_price, production_lead_time_text, prototype_video_yes, prototype_timeline_option, prototype_cost";
            if ( $has_bid_meta_json ) {
                $select_bid_fields .= ", meta_json";
            }
            if ( $has_revision_column ) {
                $select_bid_fields .= ", rfq_revision_at_submit";
            }
            
            // Get item current revision to prioritize current revision bids
            $item_current_revision_for_bid = null;
            if ( $has_revision_column ) {
                $item_current_revision_for_bid = isset( $meta['rfq_revision_current'] ) ? intval( $meta['rfq_revision_current'] ) : null;
            }
            
            // CRITICAL: Always prioritize SUBMITTED bids over draft bids
            // First, try to get submitted bid (any revision)
            $submitted_bid = $wpdb->get_row( $wpdb->prepare(
                "SELECT {$select_bid_fields} FROM {$item_bids_table} 
                WHERE item_id = %d AND supplier_id = %d
                AND status = 'submitted'
                ORDER BY created_at DESC, bid_id DESC
                LIMIT 1",
                $item_id,
                $current_user->ID
            ), ARRAY_A );
            
            if ( $submitted_bid ) {
                // Submitted bid exists - use it (ignore drafts)
                $existing_bid = $submitted_bid;
            } else {
                // No submitted bid - check for current revision bid or draft
                if ( $has_revision_column && $item_current_revision_for_bid !== null ) {
                    // First try to get current revision bid (could be draft)
                    $current_revision_bid = $wpdb->get_row( $wpdb->prepare(
                        "SELECT {$select_bid_fields} FROM {$item_bids_table} 
                        WHERE item_id = %d AND supplier_id = %d
                        AND rfq_revision_at_submit = %d
                        AND status = 'draft'
                        ORDER BY created_at DESC, bid_id DESC
                        LIMIT 1",
                        $item_id,
                        $current_user->ID,
                        $item_current_revision_for_bid
                    ), ARRAY_A );
                    
                    if ( $current_revision_bid ) {
                        $existing_bid = $current_revision_bid;
                    } else {
                        // No current revision draft, check for any draft bid
                        $draft_bid = $wpdb->get_row( $wpdb->prepare(
                            "SELECT {$select_bid_fields} FROM {$item_bids_table} 
                            WHERE item_id = %d AND supplier_id = %d
                            AND status = 'draft'
                            ORDER BY created_at DESC, bid_id DESC
                            LIMIT 1",
                            $item_id,
                            $current_user->ID
                        ), ARRAY_A );
                        
                        if ( $draft_bid ) {
                            $existing_bid = $draft_bid;
                        } else {
                            // No draft, get latest bid (any status - could be withdrawn)
                            $existing_bid = $wpdb->get_row( $wpdb->prepare(
                                "SELECT {$select_bid_fields} FROM {$item_bids_table} 
                                WHERE item_id = %d AND supplier_id = %d
                                ORDER BY created_at DESC, bid_id DESC
                                LIMIT 1",
                                $item_id,
                                $current_user->ID
                            ), ARRAY_A );
                        }
                    }
                } else {
                    // No revision column, check for draft bid
                    $draft_bid = $wpdb->get_row( $wpdb->prepare(
                        "SELECT {$select_bid_fields} FROM {$item_bids_table} 
                        WHERE item_id = %d AND supplier_id = %d
                        AND status = 'draft'
                        ORDER BY created_at DESC, bid_id DESC
                        LIMIT 1",
                        $item_id,
                        $current_user->ID
                    ), ARRAY_A );
                    
                    if ( $draft_bid ) {
                        $existing_bid = $draft_bid;
                    } else {
                        // No draft, get latest bid (any status)
                        $existing_bid = $wpdb->get_row( $wpdb->prepare(
                            "SELECT {$select_bid_fields} FROM {$item_bids_table} 
                            WHERE item_id = %d AND supplier_id = %d
                            ORDER BY created_at DESC, bid_id DESC
                            LIMIT 1",
                            $item_id,
                            $current_user->ID
                        ), ARRAY_A );
                    }
                }
            }
            // IMPORTANT: Check for draft in user meta FIRST (drafts are saved to user meta, not database)
            // Only set bid_status to 'draft' if there's a valid, non-empty draft saved
            $draft_meta_key = 'n88_bid_draft_' . $item_id;
            $draft_json = get_user_meta( $current_user->ID, $draft_meta_key, true );
            $has_user_meta_draft = false;
            
            // Validate that draft JSON exists and contains actual data
            if ( ! empty( $draft_json ) ) {
                $draft_data = json_decode( $draft_json, true );
                // Only consider it a valid draft if JSON is valid and contains at least one field
                if ( is_array( $draft_data ) && ! empty( $draft_data ) ) {
                    // Check if draft has at least one meaningful field (not just empty values)
                    $has_meaningful_data = false;
                    $meaningful_fields = array( 'unit_price', 'production_lead_time_text', 'prototype_video_yes', 'prototype_timeline_option', 'prototype_cost', 'video_links', 'bid_photo_ids', 'smart_alternatives_suggestion' );
                    foreach ( $meaningful_fields as $field ) {
                        if ( isset( $draft_data[ $field ] ) && ! empty( $draft_data[ $field ] ) ) {
                            $has_meaningful_data = true;
                            break;
                        }
                    }
                    if ( $has_meaningful_data ) {
                        $has_user_meta_draft = true;
                    }
                }
            }
            
            if ( $existing_bid ) {
                // CRITICAL: Priority must be SUBMITTED bid > Draft (user meta or database)
                // If there's a submitted bid, always use that status (ignore drafts)
                if ( $existing_bid['status'] === 'submitted' ) {
                    // Submitted bid exists - use it (drafts are irrelevant when bid is submitted)
                    $bid_status = 'submitted';
                    $bid_created_at = $existing_bid['created_at'];
                    $has_submitted_bid = true;
                    
                    // Populate bid_data for submitted bid (needed for "Your Submitted Bid" box display)
                    $bid_id = intval( $existing_bid['bid_id'] );
                    
                    // Get video links for submitted bid
                    $video_links = $wpdb->get_results( $wpdb->prepare(
                        "SELECT url, provider FROM {$bid_media_links_table}
                        WHERE bid_id = %d
                        ORDER BY sort_order ASC, id ASC",
                        $bid_id
                    ), ARRAY_A );
                    
                    // Get bid photos for submitted bid
                    $bid_photos = $wpdb->get_results( $wpdb->prepare(
                        "SELECT file_url FROM {$bid_media_files_table}
                        WHERE bid_id = %d
                        ORDER BY sort_order ASC, id ASC",
                        $bid_id
                    ), ARRAY_A );
                    
                    $photo_urls = array();
                    foreach ( $bid_photos as $photo ) {
                        if ( isset( $photo['file_url'] ) && ! empty( $photo['file_url'] ) ) {
                            $photo_urls[] = esc_url_raw( $photo['file_url'] );
                        }
                    }
                    
                    // Get Smart Alternatives suggestion from meta_json for submitted bid
                    $smart_alternatives_suggestion = null;
                    if ( $has_bid_meta_json && ! empty( $existing_bid['meta_json'] ) ) {
                        $bid_meta = json_decode( $existing_bid['meta_json'], true );
                        if ( is_array( $bid_meta ) && isset( $bid_meta['smart_alternatives_suggestion'] ) ) {
                            $smart_alternatives_suggestion = $bid_meta['smart_alternatives_suggestion'];
                        }
                    }
                    
                    $bid_data = array(
                        'unit_price' => $existing_bid['unit_price'] ? floatval( $existing_bid['unit_price'] ) : null,
                        'production_lead_time' => $existing_bid['production_lead_time_text'] ? sanitize_text_field( $existing_bid['production_lead_time_text'] ) : null,
                        'prototype_video_yes' => intval( $existing_bid['prototype_video_yes'] ) === 1,
                        'prototype_timeline' => $existing_bid['prototype_timeline_option'] ? sanitize_text_field( $existing_bid['prototype_timeline_option'] ) : null,
                        'prototype_cost' => $existing_bid['prototype_cost'] ? floatval( $existing_bid['prototype_cost'] ) : null,
                        'video_links' => array_map( function( $link ) {
                            return esc_url_raw( $link['url'] );
                        }, $video_links ),
                        'bid_photos' => $photo_urls,
                        'created_at' => $bid_created_at,
                        'smart_alternatives_suggestion' => $smart_alternatives_suggestion,
                    );
                } else {
                    // No submitted bid - check for drafts
                    $draft_bid_check = $wpdb->get_row( $wpdb->prepare(
                        "SELECT {$select_bid_fields} FROM {$item_bids_table} 
                        WHERE item_id = %d AND supplier_id = %d
                        AND status = 'draft'
                        ORDER BY created_at DESC, bid_id DESC
                        LIMIT 1",
                        $item_id,
                        $current_user->ID
                    ), ARRAY_A );
                    
                    // Only set to 'draft' if there's actually a valid draft AND no submitted bid
                    if ( $has_user_meta_draft || $draft_bid_check ) {
                        // If user meta draft exists, use it for status (button will show "Continue Bid")
                        if ( $has_user_meta_draft ) {
                            $bid_status = 'draft';
                        } elseif ( $draft_bid_check ) {
                            // Use database draft bid for both status AND data
                            $existing_bid = $draft_bid_check;
                            $bid_status = 'draft';
                        }
                    } else {
                        // No valid draft - use existing bid status
                        $bid_status = $existing_bid['status'];
                    }
                    
                    $bid_created_at = $existing_bid['created_at'];
                    $has_submitted_bid = false;
                }
            } elseif ( $has_user_meta_draft ) {
                // No database bid but user has saved a valid draft in meta - set status to draft
                $bid_status = 'draft';
                $bid_created_at = null;
                $has_submitted_bid = false;
                
                // G) Get bid revision for "Specs Changed" check (no existing_bid, so revision is null)
                $bid_revision = null;
                
                // No existing_bid, so no bid_data to populate (draft is in user meta, will be loaded via ajax_get_bid_draft)
                $bid_data = null;
            } elseif ( $existing_bid && isset( $bid_status ) && $bid_status === 'draft' ) {
                    // G) For draft bids, get basic data for pre-filling
                    $bid_id = intval( $existing_bid['bid_id'] );
                    
                    // Get video links for draft
                    $video_links = $wpdb->get_results( $wpdb->prepare(
                        "SELECT url, provider FROM {$bid_media_links_table}
                        WHERE bid_id = %d
                        ORDER BY sort_order ASC, id ASC",
                        $bid_id
                    ), ARRAY_A );
                    
                    // Get bid photos for draft
                    $bid_photos = $wpdb->get_results( $wpdb->prepare(
                        "SELECT file_url FROM {$bid_media_files_table}
                        WHERE bid_id = %d
                        ORDER BY sort_order ASC, id ASC",
                        $bid_id
                    ), ARRAY_A );
                    
                    $photo_urls = array();
                    // H) Get photo IDs and URLs for restoration (draft)
                    $bid_photo_ids = array();
                    $bid_photo_urls_array = array();
                    foreach ( $bid_photos as $photo ) {
                        if ( isset( $photo['file_url'] ) && ! empty( $photo['file_url'] ) ) {
                            $photo_urls[] = esc_url_raw( $photo['file_url'] );
                            // Try to get attachment ID from URL
                            $attachment_id = attachment_url_to_postid( $photo['file_url'] );
                            if ( $attachment_id ) {
                                $bid_photo_ids[] = $attachment_id;
                                $bid_photo_urls_array[] = array(
                                    'id' => $attachment_id,
                                    'url' => esc_url_raw( $photo['file_url'] ),
                                );
                            } else {
                                // If no attachment ID, just store URL
                                $bid_photo_urls_array[] = array(
                                    'id' => null,
                                    'url' => esc_url_raw( $photo['file_url'] ),
                                );
                            }
                        }
                    }
                    
                    // Get Smart Alternatives suggestion from meta_json for draft
                    $smart_alternatives_suggestion = null;
                    if ( $has_bid_meta_json && ! empty( $existing_bid['meta_json'] ) ) {
                        $bid_meta = json_decode( $existing_bid['meta_json'], true );
                        if ( is_array( $bid_meta ) && isset( $bid_meta['smart_alternatives_suggestion'] ) ) {
                            $smart_alternatives_suggestion = $bid_meta['smart_alternatives_suggestion'];
                        }
                    }
                    
                    $bid_data = array(
                        'unit_price' => $existing_bid['unit_price'] ? floatval( $existing_bid['unit_price'] ) : null,
                        'production_lead_time' => $existing_bid['production_lead_time_text'] ? sanitize_text_field( $existing_bid['production_lead_time_text'] ) : null,
                        'prototype_video_yes' => intval( $existing_bid['prototype_video_yes'] ) === 1,
                        'prototype_timeline' => $existing_bid['prototype_timeline_option'] ? sanitize_text_field( $existing_bid['prototype_timeline_option'] ) : null,
                        'prototype_cost' => $existing_bid['prototype_cost'] ? floatval( $existing_bid['prototype_cost'] ) : null,
                        'video_links' => array_map( function( $link ) {
                            return esc_url_raw( $link['url'] );
                        }, $video_links ),
                        'bid_photos' => $photo_urls,
                        'bid_photo_ids' => $bid_photo_ids, // H) Photo IDs for restoration
                        'bid_photo_urls' => $bid_photo_urls_array, // H) Photo URLs with IDs for restoration
                        'created_at' => $bid_created_at,
                        'smart_alternatives_suggestion' => $smart_alternatives_suggestion,
                    );
                }
                
                // Check if dims/qty changed after bid submission
                // Show warning only if latest submitted bid has stale revision (or NULL revision)
                // AND there's no current revision bid (meaning supplier hasn't resubmitted yet)
                // IMPORTANT: Only check submitted bids, NOT withdrawn bids
                // Column existence: status, created_at, bid_id are base columns (always exist)
                // rfq_revision_at_submit is optional (checked via $has_revision_column)
                // NOTE: This will be overridden later to align with has_revision_mismatch
                $show_dims_qty_warning = false;
                // Ensure item current revision is available before using it in this early warning check
                if ( ! isset( $item_current_revision ) ) {
                    $item_current_revision = isset( $meta['rfq_revision_current'] ) ? intval( $meta['rfq_revision_current'] ) : null;
                }
                if ( $has_revision_column && $item_current_revision !== null ) {
                    // First, check if supplier has a current revision bid (already resubmitted)
                    // Uses: item_id, supplier_id, rfq_revision_at_submit (checked), status (base), bid_id (base)
                    $current_revision_bid_check = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$item_bids_table}
                        WHERE item_id = %d
                        AND supplier_id = %d
                        AND rfq_revision_at_submit = %d
                        AND status = 'submitted'
                        LIMIT 1",
                        $item_id,
                        $current_user->ID,
                        $item_current_revision
                    ) );
                    
                    // Only show warning if there's NO current revision bid
                    if ( $current_revision_bid_check == 0 ) {
                        // Get latest submitted bid for this supplier (ONLY submitted, NOT withdrawn)
                        // Uses: rfq_revision_at_submit (checked), created_at (base), item_id, supplier_id, status (base), bid_id (base)
                        $latest_submitted_bid = $wpdb->get_row( $wpdb->prepare(
                            "SELECT rfq_revision_at_submit, created_at FROM {$item_bids_table}
                            WHERE item_id = %d
                            AND supplier_id = %d
                            AND status = 'submitted'
                            ORDER BY bid_id DESC
                            LIMIT 1",
                            $item_id,
                            $current_user->ID
                        ) );
                        
                        // Only show warning if there's actually a submitted bid (not withdrawn)
                        if ( $latest_submitted_bid ) {
                            $bid_revision = isset( $latest_submitted_bid->rfq_revision_at_submit ) ? intval( $latest_submitted_bid->rfq_revision_at_submit ) : null;
                            
                            // Show warning if bid revision is NULL or less than current revision
                            if ( $bid_revision === null || $bid_revision < $item_current_revision ) {
                                $show_dims_qty_warning = true;
                            }
                        }
                        // If no submitted bid exists (all withdrawn), no warning should show
                    }
                    // If current_revision_bid_check > 0, supplier has already resubmitted, so no warning
                } else {
                    // Fallback: Use event-based check if revision column doesn't exist
                    // IMPORTANT: Only check submitted bids, NOT withdrawn bids
                    // Column existence: created_at, status are base columns (always exist)
                    // item_id, supplier_id, bid_id are base columns (always exist)
                    $events_table = $wpdb->prefix . 'n88_events';
                    $latest_update_event = $wpdb->get_row( $wpdb->prepare(
                        "SELECT created_at FROM {$events_table}
                        WHERE event_type = 'item_facts_updated_after_rfq'
                        AND item_id = %d
                        ORDER BY created_at DESC
                        LIMIT 1",
                        $item_id
                    ) );
                    
                    // Only show warning if there's a submitted bid (not withdrawn)
                    // Get the latest submitted bid's creation date for comparison
                    // Uses: created_at (base), item_id, supplier_id, status (base), bid_id (base)
                    $latest_submitted_bid_for_fallback = $wpdb->get_row( $wpdb->prepare(
                        "SELECT created_at FROM {$item_bids_table}
                        WHERE item_id = %d
                        AND supplier_id = %d
                        AND status = 'submitted'
                        ORDER BY bid_id DESC
                        LIMIT 1",
                        $item_id,
                        $current_user->ID
                    ) );
                    
                    // If event exists and is newer than submitted bid creation, show warning
                    if ( $latest_update_event && $latest_submitted_bid_for_fallback ) {
                        $event_timestamp = strtotime( $latest_update_event->created_at );
                        $bid_timestamp = strtotime( $latest_submitted_bid_for_fallback->created_at );
                        if ( $event_timestamp > $bid_timestamp ) {
                            $show_dims_qty_warning = true;
                        }
                    }
                    // If no submitted bid exists (all withdrawn), no warning should show
                }
            }

        // Commit 2.3.5.5: Always prioritize LATEST item meta values (from n88_items) over RFQ submission values
        // This ensures supplier always sees current dims/qty after designer edits
        $dims = null;
        // Always check item meta first (current item facts)
        if ( isset( $meta['dims'] ) && is_array( $meta['dims'] ) ) {
            $dims = $meta['dims'];
            error_log( 'Supplier Detail View - Using LATEST dimensions from item meta (dims) for item ' . $item_id );
        } elseif ( isset( $meta['dims_cm'] ) && is_array( $meta['dims_cm'] ) ) {
            $dims = $meta['dims_cm'];
            error_log( 'Supplier Detail View - Using LATEST dimensions from item meta (dims_cm) for item ' . $item_id );
        } elseif ( $delivery_context && $has_dimensions && ! empty( $delivery_context['dimensions_json'] ) ) {
            // Fallback to delivery context if item meta not available
            $decoded_dims = json_decode( $delivery_context['dimensions_json'], true );
            if ( is_array( $decoded_dims ) ) {
                $dims = array(
                    'w' => isset( $decoded_dims['width'] ) ? floatval( $decoded_dims['width'] ) : ( isset( $decoded_dims['w'] ) ? floatval( $decoded_dims['w'] ) : null ),
                    'd' => isset( $decoded_dims['depth'] ) ? floatval( $decoded_dims['depth'] ) : ( isset( $decoded_dims['d'] ) ? floatval( $decoded_dims['d'] ) : null ),
                    'h' => isset( $decoded_dims['height'] ) ? floatval( $decoded_dims['height'] ) : ( isset( $decoded_dims['h'] ) ? floatval( $decoded_dims['h'] ) : null ),
                    'unit' => isset( $decoded_dims['unit'] ) ? sanitize_text_field( $decoded_dims['unit'] ) : '',
                );
                error_log( 'Supplier Detail View - Using dimensions from delivery context (fallback) for item ' . $item_id );
            }
        } else {
            error_log( 'Supplier Detail View - No dimensions found for item ' . $item_id );
        }

        // Commit 2.3.5.5: Always prioritize LATEST item meta values (from n88_items) over RFQ submission values
        // This ensures supplier always sees current dims/qty after designer edits
        $quantity = null;
        // Always check item meta first (current item facts)
        if ( isset( $meta['quantity'] ) ) {
            $quantity = intval( $meta['quantity'] );
            error_log( 'Supplier Detail View - Using LATEST quantity from item meta for item ' . $item_id . ': ' . $quantity );
        } elseif ( $delivery_context && $has_quantity && ! empty( $delivery_context['quantity'] ) ) {
            // Fallback to delivery context if item meta not available
            $quantity = intval( $delivery_context['quantity'] );
            error_log( 'Supplier Detail View - Using quantity from delivery context (fallback) for item ' . $item_id );
        }

        // Get Smart Alternatives data from meta
        $smart_alternatives_enabled = isset( $meta['smart_alternatives'] ) && $meta['smart_alternatives'] === true;
        $smart_alternatives_note = isset( $meta['smart_alternatives_note'] ) ? sanitize_textarea_field( $meta['smart_alternatives_note'] ) : '';
        
        // Commit 2.3.5.4: Get keywords from meta (initialize as empty array if not present)
        $keywords = array();
        if ( isset( $meta['keywords'] ) && is_array( $meta['keywords'] ) ) {
            $keywords = $meta['keywords'];
        }
        
        // Commit 2.3.5.1 Addendum: Calculate Total CBM from current item facts
        $total_cbm = null;
        if ( $dims && isset( $dims['w'] ) && isset( $dims['d'] ) && isset( $dims['h'] ) && isset( $dims['unit'] ) && $quantity && $quantity > 0 ) {
            // Normalize dimensions to cm
            $w_cm = null;
            $d_cm = null;
            $h_cm = null;
            $unit = $dims['unit'];
            
            if ( $unit === 'mm' ) {
                $w_cm = floatval( $dims['w'] ) / 10;
                $d_cm = floatval( $dims['d'] ) / 10;
                $h_cm = floatval( $dims['h'] ) / 10;
            } elseif ( $unit === 'cm' ) {
                $w_cm = floatval( $dims['w'] );
                $d_cm = floatval( $dims['d'] );
                $h_cm = floatval( $dims['h'] );
            } elseif ( $unit === 'm' ) {
                $w_cm = floatval( $dims['w'] ) * 100;
                $d_cm = floatval( $dims['d'] ) * 100;
                $h_cm = floatval( $dims['h'] ) * 100;
            } elseif ( $unit === 'in' ) {
                $w_cm = floatval( $dims['w'] ) * 2.54;
                $d_cm = floatval( $dims['d'] ) * 2.54;
                $h_cm = floatval( $dims['h'] ) * 2.54;
            }
            
            if ( $w_cm && $d_cm && $h_cm ) {
                // Convert cm to meters, then calculate CBM per unit
                $w_m = $w_cm / 100;
                $d_m = $d_cm / 100;
                $h_m = $h_cm / 100;
                $item_cbm = $w_m * $d_m * $h_m;
                // Calculate Total CBM (item_cbm × quantity)
                $total_cbm = round( $item_cbm * $quantity, 3 );
            }
        }

        // G) Get item current revision for "Specs Changed" check
        // Initialize early to prevent undefined variable warnings
        if ( ! isset( $item_current_revision ) ) {
            $item_current_revision = isset( $meta['rfq_revision_current'] ) ? intval( $meta['rfq_revision_current'] ) : null;
        }
        
        // G) Check if supplier has revision mismatch (stale bid but no current revision bid)
        // IMPORTANT: Only show "Resubmit Bid" when specs changed (has_revision_mismatch = true)
        // Otherwise show "Bid Already Submitted" + "Withdraw Bid"
        $has_revision_mismatch = false;
        $latest_stale_bid_data = null;
        $is_resubmission = false; // Flag to track if this is a resubmission (has current revision bid AND had stale bid)
        
        // Re-check column existence right before using it (in case it was just created)
        if ( $item_current_revision !== null ) {
            $bids_columns_recheck = $wpdb->get_col( "DESCRIBE {$item_bids_table}" );
            $has_revision_column = in_array( 'rfq_revision_at_submit', $bids_columns_recheck, true );
        }
        
        if ( $item_current_revision !== null && $has_revision_column ) {
            // First, check if supplier has a current revision bid (already resubmitted or in progress)
            // Check for submitted bid first (takes priority)
            $current_revision_bid = $wpdb->get_row( $wpdb->prepare(
                "SELECT bid_id, status FROM {$item_bids_table} 
                WHERE item_id = %d 
                AND supplier_id = %d
                AND rfq_revision_at_submit = %d
                AND status = 'submitted'
                LIMIT 1",
                $item_id,
                $current_user->ID,
                $item_current_revision
            ), ARRAY_A );
            
            // If no submitted bid, check for draft bid
            if ( ! $current_revision_bid ) {
                $current_revision_bid = $wpdb->get_row( $wpdb->prepare(
                    "SELECT bid_id, status FROM {$item_bids_table} 
                    WHERE item_id = %d 
                    AND supplier_id = %d
                    AND rfq_revision_at_submit = %d
                    AND status = 'draft'
                    LIMIT 1",
                    $item_id,
                    $current_user->ID,
                    $item_current_revision
                ), ARRAY_A );
            }
            
            if ( $current_revision_bid ) {
                // Supplier has already resubmitted with current revision (or has draft) - NO resubmit button
                $has_revision_mismatch = false;
                
                // Check if there was a previous stale bid (to show "Bid Already Resubmitted" vs "Bid Already Submitted")
                $stale_bid_exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$item_bids_table} 
                    WHERE item_id = %d 
                    AND supplier_id = %d
                    AND (rfq_revision_at_submit IS NULL OR rfq_revision_at_submit < %d)
                    AND status IN ('submitted', 'draft', 'withdrawn')
                    LIMIT 1",
                    $item_id,
                    $current_user->ID,
                    $item_current_revision
                ) );
                if ( $stale_bid_exists > 0 ) {
                    $is_resubmission = true;
                }
            } else {
                // No current revision bid - check if there are any stale bids
                // This means specs changed and supplier hasn't resubmitted yet
                // IMPORTANT: Double-check that there's really no current revision bid
                // (in case of race condition or timing issue)
                $double_check_current = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$item_bids_table} 
                    WHERE item_id = %d 
                    AND supplier_id = %d
                    AND rfq_revision_at_submit = %d
                    AND status = 'submitted'",
                    $item_id,
                    $current_user->ID,
                    $item_current_revision
                ) );
                
                if ( $double_check_current > 0 ) {
                    // Actually has current revision bid - supplier has resubmitted
                    // FORCE has_revision_mismatch to false (supplier resubmitted, no mismatch)
                    $has_revision_mismatch = false;
                    $latest_stale_bid_data = null; // Clear stale bid data since supplier resubmitted
                    
                    // Check if there was a previous stale bid (to show "Bid Already Resubmitted")
                    $stale_bid_exists = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$item_bids_table} 
                        WHERE item_id = %d 
                        AND supplier_id = %d
                        AND (rfq_revision_at_submit IS NULL OR rfq_revision_at_submit < %d)
                        AND status IN ('submitted', 'draft', 'withdrawn')",
                        $item_id,
                        $current_user->ID,
                        $item_current_revision
                    ) );
                    if ( $stale_bid_exists > 0 ) {
                        $is_resubmission = true;
                    }
                } else {
                    // No current revision bid - check for stale bids
                    $stale_bid_check = $wpdb->get_row( $wpdb->prepare(
                        "SELECT rfq_revision_at_submit, bid_id, status FROM {$item_bids_table}
                        WHERE item_id = %d
                        AND supplier_id = %d
                        AND status = 'submitted'
                        AND (rfq_revision_at_submit IS NULL OR rfq_revision_at_submit < %d)
                        ORDER BY bid_id DESC
                        LIMIT 1",
                        $item_id,
                        $current_user->ID,
                        $item_current_revision
                    ), ARRAY_A );
                    
                    if ( $stale_bid_check ) {
                        // Stale bid exists and no current revision bid - show resubmit button
                        $has_revision_mismatch = true;
                        
                        // Get stale bid data for pre-filling (fetch fresh from database)
                        $stale_bid_id = intval( $stale_bid_check['bid_id'] );
                        $bids_columns = $wpdb->get_col( "DESCRIBE {$item_bids_table}" );
                        $has_bid_meta_json = in_array( 'meta_json', $bids_columns, true );
                        
                        $select_stale_fields = "bid_id, status, created_at, unit_price, production_lead_time_text, prototype_video_yes, prototype_timeline_option, prototype_cost";
                        if ( $has_bid_meta_json ) {
                            $select_stale_fields .= ", meta_json";
                        }
                        if ( $has_revision_column ) {
                            $select_stale_fields .= ", rfq_revision_at_submit";
                        }
                        
                        $stale_bid_full = $wpdb->get_row( $wpdb->prepare(
                            "SELECT {$select_stale_fields} FROM {$item_bids_table} WHERE bid_id = %d",
                            $stale_bid_id
                        ), ARRAY_A );
                        
                        if ( $stale_bid_full ) {
                            $latest_stale_bid_data = $stale_bid_full;
                        } elseif ( $bid_data ) {
                            // Fallback to bid_data if available
                            $latest_stale_bid_data = $bid_data;
                        }
                    }
                    // If no stale bids found, has_revision_mismatch stays false (no bids at all)
                }
            }
        } elseif ( ! $has_revision_column ) {
            // No revision column - fall back to event timestamps.
            // We treat "specs changed" as: latest item_facts_updated_after_rfq event is newer than the supplier's
            // latest submitted bid for this item. After the supplier submits again, their latest submitted bid will
            // be newer than the event, and the mismatch will clear automatically.
            $events_table = $wpdb->prefix . 'n88_events';
            $latest_update_event = $wpdb->get_row( $wpdb->prepare(
                "SELECT created_at FROM {$events_table}
                 WHERE event_type = 'item_facts_updated_after_rfq'
                 AND item_id = %d
                 ORDER BY created_at DESC
                 LIMIT 1",
                $item_id
            ) );

            // Latest submitted bid timestamp (ignore withdrawn/draft)
            // IMPORTANT: Order by created_at DESC to get the most recent bid (after resubmission)
            $latest_submitted_bid_for_mismatch = $wpdb->get_row( $wpdb->prepare(
                "SELECT bid_id, created_at FROM {$item_bids_table}
                 WHERE item_id = %d
                 AND supplier_id = %d
                 AND status = 'submitted'
                 ORDER BY created_at DESC, bid_id DESC
                 LIMIT 1",
                $item_id,
                $current_user->ID
            ) );

            if ( $latest_update_event && $latest_submitted_bid_for_mismatch ) {
                $event_ts = strtotime( $latest_update_event->created_at );
                $bid_ts = strtotime( $latest_submitted_bid_for_mismatch->created_at );
                // Only show mismatch if event is NEWER than bid (meaning specs changed AFTER bid was submitted)
                // If bid is NEWER than event (meaning supplier resubmitted AFTER specs changed), no mismatch
                // CRITICAL: Use >= comparison to handle edge case where timestamps are equal
                if ( $event_ts && $bid_ts && $event_ts > $bid_ts ) {
                    // Event is newer - specs changed after bid was submitted
                    $has_revision_mismatch = true;
                    
                    // Get stale bid data for pre-filling (fetch from the stale bid)
                    $stale_bid_id = intval( $latest_submitted_bid_for_mismatch->bid_id );
                    $bids_columns = $wpdb->get_col( "DESCRIBE {$item_bids_table}" );
                    $has_bid_meta_json = in_array( 'meta_json', $bids_columns, true );
                    
                    $select_stale_fields = "bid_id, status, created_at, unit_price, production_lead_time_text, prototype_video_yes, prototype_timeline_option, prototype_cost";
                    if ( $has_bid_meta_json ) {
                        $select_stale_fields .= ", meta_json";
                    }
                    
                    $stale_bid_full = $wpdb->get_row( $wpdb->prepare(
                        "SELECT {$select_stale_fields} FROM {$item_bids_table} WHERE bid_id = %d",
                        $stale_bid_id
                    ), ARRAY_A );
                    
                    if ( $stale_bid_full ) {
                        $latest_stale_bid_data = $stale_bid_full;
                    } elseif ( $bid_data ) {
                        // Fallback to bid_data if available
                        $latest_stale_bid_data = $bid_data;
                    }
                } else {
                    // Bid is newer than event OR timestamps are equal - supplier has already resubmitted
                    // FORCE has_revision_mismatch to false (supplier resubmitted, no mismatch)
                    $has_revision_mismatch = false;
                    $latest_stale_bid_data = null; // Clear stale bid data since supplier resubmitted
                    
                    // Check if there was a previous stale bid (to show "Bid Already Resubmitted")
                    $older_bid_check = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$item_bids_table}
                         WHERE item_id = %d
                         AND supplier_id = %d
                         AND status = 'submitted'
                         AND created_at < %s
                         LIMIT 1",
                        $item_id,
                        $current_user->ID,
                        $latest_submitted_bid_for_mismatch->created_at
                    ) );
                    if ( $older_bid_check > 0 ) {
                        $is_resubmission = true;
                    }
                }
            } else {
                // No event or no bid - no mismatch
                // FORCE has_revision_mismatch to false
                $has_revision_mismatch = false;
                $latest_stale_bid_data = null; // Clear stale bid data
            }
        }
        
        // IMPORTANT: Align show_dims_qty_warning with has_revision_mismatch
        // When specs change (has_revision_mismatch = true), show the warning AND resubmit button
        // When new bid is submitted (has_revision_mismatch = false), hide the warning
        // Only show warning if there's a submitted bid (not draft, not withdrawn)
        if ( $has_revision_mismatch && isset( $bid_status ) && $bid_status === 'submitted' ) {
            // Specs changed and supplier has a stale submitted bid - show warning
            $show_dims_qty_warning = true;
        } else {
            // No revision mismatch OR no submitted bid - hide warning
            $show_dims_qty_warning = false;
        }
        
        // Build response (read-only, no writes)
        // Commit 2.3.5.4: Remove total_cbm from supplier response (CBM should not be visible to suppliers)
        // Ensure bid_status is only 'draft' when there's actually a valid draft, otherwise null (shows "Start Bid")
        if ( ! isset( $bid_status ) || ( $bid_status !== 'draft' && $bid_status !== 'submitted' ) ) {
            $bid_status = null; // Default to null - will show "Start Bid" button
        }
        
        $response = array(
            'item_id' => intval( $item['id'] ),
            'title' => sanitize_text_field( $item['title'] ),
            'description' => sanitize_textarea_field( $item['description'] ),
            'category' => $category_name,
            'keywords' => array_map( 'sanitize_text_field', $keywords ), // Commit 2.3.5.4: Add keywords
            'image_url' => $image_url ? esc_url_raw( $image_url ) : '', // Keep for backward compatibility
            'primary_image_url' => $primary_image_url ? esc_url_raw( $primary_image_url ) : '', // Standardized key
            'quantity' => $quantity,
            'dimensions' => $dims,
            // Commit 2.3.5.4: total_cbm removed - CBM should not be visible to suppliers
            'sourcing_type' => isset( $meta['sourcing_type'] ) ? sanitize_text_field( $meta['sourcing_type'] ) : null,
            'timeline_type' => isset( $meta['timeline_type'] ) ? sanitize_text_field( $meta['timeline_type'] ) : null,
            'delivery_country' => $delivery_context ? sanitize_text_field( $delivery_context['delivery_country_code'] ) : null,
            'delivery_postal_code' => $delivery_context && ! empty( $delivery_context['delivery_postal_code'] ) ? sanitize_text_field( $delivery_context['delivery_postal_code'] ) : null,
            'shipping_mode_label' => $shipping_mode_label,
            'route_label' => $route_label,
            'reference_images' => $reference_images, // Keep for backward compatibility
            'inspiration_images' => $inspiration_images, // Standardized key
            'media_links' => $media_links,
            'smart_alternatives_enabled' => $smart_alternatives_enabled,
            'smart_alternatives_note' => $smart_alternatives_note,
            'bid_status' => $bid_status, // Commit 2.3.5 - bid status: 'draft' (Continue Bid), 'submitted' (Bid Submitted), or null (Start Bid)
            'show_dims_qty_warning' => $show_dims_qty_warning, // Commit 2.3.5.1 Addendum: Flag to show warning banner
            'bid_data' => $bid_data, // Bid details when bid is submitted
            'rfq_revision_current' => isset( $item_current_revision ) ? $item_current_revision : null, // G) Current item revision
            'bid_revision' => isset( $bid_revision ) ? $bid_revision : null, // G) Supplier's bid revision (ensure it's always set)
            'has_revision_mismatch' => $has_revision_mismatch, // G) Flag for "Specs Changed" banner
            'latest_stale_bid_data' => $latest_stale_bid_data, // G) Latest stale bid data for pre-filling
            'is_resubmission' => $is_resubmission, // G) Flag to show "Bid Already Resubmitted" instead of "Bid Already Submitted"
        );

        wp_send_json_success( $response );
    }

    /**
     * AJAX handler to get item RFQ and bid state for designer modal
     * Returns: has_rfq (boolean), has_bids (boolean), bids (array if has_bids)
     */
    public function ajax_get_item_rfq_state() {
        check_ajax_referer( 'n88_get_item_rfq_state', '_ajax_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        if ( ! $is_designer && ! $is_system_operator ) {
            wp_send_json_error( array( 'message' => 'Access denied. Designer account required.' ) );
        }

        $item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
        
        if ( ! $item_id ) {
            wp_send_json_error( array( 'message' => 'Invalid item ID.' ) );
        }

        global $wpdb;
        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $bid_media_links_table = $wpdb->prefix . 'n88_bid_media_links';
        $items_table = $wpdb->prefix . 'n88_items';

        // Check if item exists and user owns it (unless system operator)
        if ( ! $is_system_operator ) {
            $item_owner = $wpdb->get_var( $wpdb->prepare(
                "SELECT owner_user_id FROM {$items_table} WHERE id = %d",
                $item_id
            ) );
            
            if ( ! $item_owner || intval( $item_owner ) !== $current_user->ID ) {
                wp_send_json_error( array( 'message' => 'Access denied. You can only view your own items.' ), 403 );
            }
        }

        // Get item meta for Smart Alternatives (item-level setting, same for all bids)
        $item_meta = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_json FROM {$items_table} WHERE id = %d",
            $item_id
        ) );
        
        $smart_alternatives_enabled = false;
        $smart_alternatives_note = '';
        $rfq_revision_current = null;
        $revision_changed = false;
        if ( ! empty( $item_meta ) ) {
            $meta = json_decode( $item_meta, true );
            if ( is_array( $meta ) ) {
                $smart_alternatives_enabled = isset( $meta['smart_alternatives'] ) && $meta['smart_alternatives'] === true;
                $smart_alternatives_note = isset( $meta['smart_alternatives_note'] ) ? sanitize_textarea_field( $meta['smart_alternatives_note'] ) : '';
                // D5: Get revision info for Specs Updated panel
                if ( isset( $meta['rfq_revision_current'] ) ) {
                    $rfq_revision_current = intval( $meta['rfq_revision_current'] );
                }
                if ( isset( $meta['revision_changed'] ) ) {
                    $revision_changed = (bool) $meta['revision_changed'];
                }
            }
        }

        // Check if RFQ exists (has any routes for this item)
        $has_rfq = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$rfq_routes_table} 
            WHERE item_id = %d 
            AND status IN ('queued', 'sent', 'viewed', 'bid_submitted')",
            $item_id
        ) ) > 0;

        // Check if bids exist (submitted bids only)
        $has_bids = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$item_bids_table} 
            WHERE item_id = %d 
            AND status = 'submitted'",
            $item_id
        ) ) > 0;

        $bids = array();
        if ( $has_bids ) {
            // Commit 2.3.6: Get all submitted bids with CAD flag, prototype commitment, and photos
            // Check if meta_json and rfq_revision_at_submit columns exist
            $bids_columns = $wpdb->get_col( "DESCRIBE {$item_bids_table}" );
            $has_bid_meta_json = in_array( 'meta_json', $bids_columns, true );
            $has_revision_column = in_array( 'rfq_revision_at_submit', $bids_columns, true );
            
            $select_fields = "b.bid_id, b.unit_price, b.production_lead_time_text, b.prototype_timeline_option, b.prototype_cost, b.prototype_video_yes, b.cad_yes, b.created_at";
            if ( $has_bid_meta_json ) {
                $select_fields .= ", b.meta_json";
            }
            if ( $has_revision_column ) {
                $select_fields .= ", b.rfq_revision_at_submit";
            }
            
            $bids_data = $wpdb->get_results( $wpdb->prepare(
                "SELECT {$select_fields}
                FROM {$item_bids_table} b
                WHERE b.item_id = %d 
                AND b.status = 'submitted'
                ORDER BY b.created_at ASC, b.bid_id ASC",
                $item_id
            ), ARRAY_A );

            $bid_media_files_table = $wpdb->prefix . 'n88_bid_media_files';

            foreach ( $bids_data as $bid ) {
                // Get media links for this bid with provider information
                $media_links = $wpdb->get_results( $wpdb->prepare(
                    "SELECT url, provider 
                    FROM {$bid_media_links_table}
                    WHERE bid_id = %d
                    ORDER BY sort_order ASC, id ASC",
                    $bid['bid_id']
                ), ARRAY_A );

                // Commit 2.3.6: Get bid photos from n88_bid_media_files
                $bid_photos = $wpdb->get_results( $wpdb->prepare(
                    "SELECT file_url 
                    FROM {$bid_media_files_table}
                    WHERE bid_id = %d
                    ORDER BY sort_order ASC, id ASC",
                    $bid['bid_id']
                ), ARRAY_A );

                // Organize video links by provider
                $video_links_by_provider = array(
                    'youtube' => array(),
                    'vimeo' => array(),
                    'loom' => array(),
                );
                
                foreach ( $media_links as $link ) {
                    $provider = isset( $link['provider'] ) ? strtolower( $link['provider'] ) : 'youtube';
                    $url = esc_url_raw( $link['url'] );
                    
                    if ( in_array( $provider, array( 'youtube', 'vimeo', 'loom' ), true ) ) {
                        $video_links_by_provider[ $provider ][] = $url;
                    }
                }

                // Commit 2.3.6: Extract photo URLs (no metadata/filenames to prevent identity leakage)
                $photo_urls = array_map( function( $photo ) {
                    return esc_url_raw( $photo['file_url'] );
                }, $bid_photos );

                // Get Smart Alternatives suggestion and note from meta_json if available
                $smart_alternatives_suggestion = null;
                $bid_smart_alternatives_note = null;
                if ( $has_bid_meta_json && ! empty( $bid['meta_json'] ) ) {
                    $bid_meta = json_decode( $bid['meta_json'], true );
                    if ( is_array( $bid_meta ) ) {
                        if ( isset( $bid_meta['smart_alternatives_suggestion'] ) ) {
                            $smart_alternatives_suggestion = $bid_meta['smart_alternatives_suggestion'];
                        }
                        // Get supplier's note if stored in bid meta_json
                        if ( isset( $bid_meta['smart_alternatives_note'] ) ) {
                            $bid_smart_alternatives_note = sanitize_textarea_field( $bid_meta['smart_alternatives_note'] );
                        }
                    }
                }
                
                // Debug: Log if meta_json exists but smart_alternatives_suggestion is null
                if ( $has_bid_meta_json && ! empty( $bid['meta_json'] ) && $smart_alternatives_suggestion === null ) {
                    error_log( 'Bid ' . $bid['bid_id'] . ': meta_json exists but smart_alternatives_suggestion is null. meta_json: ' . substr( $bid['meta_json'], 0, 200 ) );
                }

                // D5: Get rfq_revision_at_submit for bid filtering
                $bid_revision = null;
                if ( $has_revision_column && isset( $bid['rfq_revision_at_submit'] ) && $bid['rfq_revision_at_submit'] !== null ) {
                    $bid_revision = intval( $bid['rfq_revision_at_submit'] );
                }
                
                // Commit 2.3.7.1: Apply 65% margin markup for designer display
                // Store raw prices for reference, but display marked-up prices to designers
                $unit_price_raw = $bid['unit_price'] ? floatval( $bid['unit_price'] ) : null;
                $prototype_cost_raw = $bid['prototype_cost'] ? floatval( $bid['prototype_cost'] ) : null;
                
                $bids[] = array(
                    'bid_id' => intval( $bid['bid_id'] ),
                    'unit_price' => N88_RFQ_Helpers::n88_price_display_from_raw( $unit_price_raw ), // Designer sees marked-up price
                    'unit_price_raw' => $unit_price_raw, // Keep raw price for reference
                    'production_lead_time' => $bid['production_lead_time_text'] ? sanitize_text_field( $bid['production_lead_time_text'] ) : null,
                    'prototype_timeline' => $bid['prototype_timeline_option'] ? sanitize_text_field( $bid['prototype_timeline_option'] ) : null,
                    'prototype_cost' => N88_RFQ_Helpers::n88_price_display_from_raw( $prototype_cost_raw ), // Designer sees marked-up price
                    'prototype_cost_raw' => $prototype_cost_raw, // Keep raw price for reference
                    'prototype_commitment' => isset( $bid['prototype_video_yes'] ) && intval( $bid['prototype_video_yes'] ) === 1 ? true : false,
                    'cad_yes' => isset( $bid['cad_yes'] ) && intval( $bid['cad_yes'] ) === 1 ? true : false,
                    'video_links' => array_map( function( $link ) {
                        return esc_url_raw( $link['url'] );
                    }, $media_links ),
                    'video_links_by_provider' => $video_links_by_provider,
                    'photo_urls' => $photo_urls,
                    'smart_alternatives_suggestion' => $smart_alternatives_suggestion,
                    'smart_alternatives_enabled' => $smart_alternatives_enabled,
                    'smart_alternatives_note' => $smart_alternatives_note, // Item-level note (designer's note)
                    'bid_smart_alternatives_note' => $bid_smart_alternatives_note, // Bid-level note (supplier's note)
                    'created_at' => $bid['created_at'],
                    'rfq_revision_at_submit' => $bid_revision, // D5: Revision tracking for bid filtering
                );
            }
        }

        wp_send_json_success( array(
            'has_rfq' => $has_rfq,
            'has_bids' => $has_bids,
            'bids' => $bids,
            'rfq_revision_current' => $rfq_revision_current, // D5: Current revision for Specs Updated panel
            'revision_changed' => $revision_changed, // D5: Flag indicating specs were updated after RFQ
        ) );
    }

    /**
     * AJAX handler to validate supplier bid (Commit 2.3.3)
     * Server-side validation only - NO database writes
     */
    public function ajax_validate_supplier_bid() {
        check_ajax_referer( 'n88_validate_supplier_bid', '_ajax_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        if ( ! $is_supplier && ! $is_system_operator ) {
            wp_send_json_error( array( 'message' => 'Access denied. Maker account required.' ) );
        }

        $item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
        
        if ( ! $item_id ) {
            wp_send_json_error( array( 'message' => 'Invalid item ID.' ) );
        }

        // DEMO ITEM FOR TESTING (Commit 2.3.3) - Allow demo item without route check
        $demo_item_id = 9999;
        if ( $item_id === $demo_item_id ) {
            // Skip route check for demo item - allow validation
        } else {
            // Verify supplier has route for this item (unless system operator)
            if ( ! $is_system_operator ) {
                global $wpdb;
                $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
                $route_exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$rfq_routes_table} 
                    WHERE item_id = %d 
                    AND supplier_id = %d 
                    AND status IN ('queued', 'sent', 'viewed', 'bid_submitted')",
                    $item_id,
                    $current_user->ID
                ) );

                if ( ! $route_exists || intval( $route_exists ) === 0 ) {
                    wp_send_json_error( array( 'message' => 'Access denied. You do not have permission to bid on this item.' ), 403 );
                }
            }
        }

        $errors = array();

        // 1. Video links validation (min 1, max 3, allowed providers only)
        $video_links_json = isset( $_POST['video_links'] ) ? wp_unslash( $_POST['video_links'] ) : '[]';
        $video_links = json_decode( $video_links_json, true );
        
        if ( ! is_array( $video_links ) ) {
            $video_links = array();
        }
        
        // Filter out empty links
        $video_links = array_filter( array_map( 'trim', $video_links ) );
        
        // Commit 2.3.5.1: Video links are optional now, but if provided, max 3
        if ( count( $video_links ) > 3 ) {
            $errors['video_links'] = 'Maximum 3 video links allowed.';
        } else {
            // Validate each link against allowlist (only if provided)
            if ( count( $video_links ) > 0 ) {
                $allowed_domains = array(
                    'youtube.com', 'www.youtube.com', 'youtu.be',
                    'vimeo.com', 'www.vimeo.com',
                    'loom.com', 'www.loom.com'
                );
                
                foreach ( $video_links as $link ) {
                    $parsed_url = wp_parse_url( $link );
                    if ( ! $parsed_url || ! isset( $parsed_url['host'] ) ) {
                        $errors['video_links'] = 'Invalid URL format: ' . esc_html( $link );
                        break;
                    }
                    
                    $hostname = strtolower( $parsed_url['host'] );
                    $hostname = preg_replace( '/^www\./', '', $hostname );
                    
                    $is_allowed = false;
                    foreach ( $allowed_domains as $domain ) {
                        $domain_clean = preg_replace( '/^www\./', '', $domain );
                        if ( $hostname === $domain_clean || strpos( $hostname, '.' . $domain_clean ) !== false ) {
                            $is_allowed = true;
                            break;
                        }
                    }
                    
                    if ( ! $is_allowed ) {
                        $errors['video_links'] = 'Only YouTube, Vimeo, or Loom links are allowed. Invalid: ' . esc_html( $link );
                        break;
                    }
                }
            }
        }
        
        // 1.5. Bid Photos validation (required, min 1, max 5) - Commit 2.3.5.1
        // Check for bid_photo_ids (already uploaded to WordPress media library)
        $bid_photo_ids_raw = isset( $_POST['bid_photo_ids'] ) ? $_POST['bid_photo_ids'] : null;
        
        // Handle both JSON string and array formats
        $bid_photo_ids = array();
        if ( $bid_photo_ids_raw !== null ) {
            if ( is_string( $bid_photo_ids_raw ) ) {
                $decoded = json_decode( wp_unslash( $bid_photo_ids_raw ), true );
                if ( is_array( $decoded ) ) {
                    $bid_photo_ids = $decoded;
                }
            } elseif ( is_array( $bid_photo_ids_raw ) ) {
                $bid_photo_ids = $bid_photo_ids_raw;
            }
        }
        
        // Filter out invalid IDs
        $bid_photo_ids = array_filter( array_map( 'intval', $bid_photo_ids ), function( $id ) {
            return $id > 0;
        } );
        
        $bid_photos_count = count( $bid_photo_ids );
        
        if ( $bid_photos_count < 1 ) {
            $errors['bid_photos'] = 'At least 1 photo is required.';
        } elseif ( $bid_photos_count > 5 ) {
            $errors['bid_photos'] = 'Maximum 5 photos allowed.';
        } else {
            // Validate that all photo IDs exist in WordPress media library
            foreach ( $bid_photo_ids as $photo_id ) {
                $attachment = get_post( $photo_id );
                if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
                    $errors['bid_photos'] = 'Invalid photo ID: ' . $photo_id;
                    break;
                }
                
                // Verify it's an image
                $mime_type = get_post_mime_type( $photo_id );
                if ( ! $mime_type || strpos( $mime_type, 'image/' ) !== 0 ) {
                    $errors['bid_photos'] = 'Photo ID ' . $photo_id . ' is not a valid image.';
                    break;
                }
            }
        }

        // 2. Prototype video commitment (optional - YES or NO)
        $prototype_video_yes = isset( $_POST['prototype_video_yes'] ) ? intval( $_POST['prototype_video_yes'] ) : 0;
        $is_prototype_yes = ( $prototype_video_yes === 1 );

        // 3. Prototype timeline (required ONLY if prototype is YES)
        $prototype_timeline_option = isset( $_POST['prototype_timeline_option'] ) ? sanitize_text_field( wp_unslash( $_POST['prototype_timeline_option'] ) ) : '';
        if ( $is_prototype_yes ) {
            $allowed_timelines = array( '1-2w', '2-4w', '4-6w', '6-8w', '8-10w' );
            if ( empty( $prototype_timeline_option ) || ! in_array( $prototype_timeline_option, $allowed_timelines, true ) ) {
                $errors['prototype_timeline_option'] = 'Please select a valid prototype timeline.';
            }
        }

        // 4. Prototype cost (required ONLY if prototype is YES)
        $prototype_cost = isset( $_POST['prototype_cost'] ) ? sanitize_text_field( wp_unslash( $_POST['prototype_cost'] ) ) : '';
        if ( $is_prototype_yes ) {
            if ( empty( $prototype_cost ) ) {
                $errors['prototype_cost'] = 'Prototype cost is required.';
            } else {
                $prototype_cost_float = floatval( $prototype_cost );
                if ( ! is_numeric( $prototype_cost ) || $prototype_cost_float < 0 ) {
                    $errors['prototype_cost'] = 'Prototype cost must be a number greater than or equal to 0.';
                }
            }
        }

        // 5. Production lead time (non-empty text)
        $production_lead_time_text = isset( $_POST['production_lead_time_text'] ) ? sanitize_text_field( wp_unslash( $_POST['production_lead_time_text'] ) ) : '';
        if ( empty( trim( $production_lead_time_text ) ) ) {
            $errors['production_lead_time_text'] = 'Production lead time is required.';
        }

        // 6. Unit price (numeric > 0)
        $unit_price = isset( $_POST['unit_price'] ) ? sanitize_text_field( wp_unslash( $_POST['unit_price'] ) ) : '';
        if ( empty( $unit_price ) ) {
            $errors['unit_price'] = 'Unit price is required.';
        } else {
            $unit_price_float = floatval( $unit_price );
            if ( ! is_numeric( $unit_price ) || $unit_price_float <= 0 ) {
                $errors['unit_price'] = 'Unit price must be a number greater than 0.';
            }
        }

        // If there are errors, return them
        if ( ! empty( $errors ) ) {
            wp_send_json_error( array(
                'message' => 'Validation failed. Please correct the errors below.',
                'errors' => $errors,
            ) );
        }

        // Validation passed (but NO database writes in 2.3.3)
        wp_send_json_success( array(
            'message' => 'Bid validation successful. (Note: Bid is not saved yet - this will be implemented in Commit 2.3.5)',
        ) );
    }

    /**
     * AJAX handler to submit supplier bid (Commit 2.3.5)
     * Persists validated bid to database and updates route status
     */
    public function ajax_submit_supplier_bid() {
        check_ajax_referer( 'n88_submit_supplier_bid', '_ajax_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        if ( ! $is_supplier && ! $is_system_operator ) {
            wp_send_json_error( array( 'message' => 'Access denied. Maker account required.' ) );
        }

        $item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
        
        if ( ! $item_id ) {
            wp_send_json_error( array( 'message' => 'Invalid item ID.' ) );
        }

        global $wpdb;
        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $bid_media_links_table = $wpdb->prefix . 'n88_bid_media_links';

        // Verify supplier has route for this item (unless system operator)
        if ( ! $is_system_operator ) {
            // Commit 2.3.7.1: Check and update expired routes (lazy evaluation)
            $this->check_and_update_expired_routes( $item_id, $current_user->ID );
            
            // Check if route is expired
            if ( $this->is_route_expired( $item_id, $current_user->ID ) ) {
                wp_send_json_error( array( 'message' => 'This RFQ is no longer accepting bids.' ), 403 );
            }
            
            $route = $wpdb->get_row( $wpdb->prepare(
                "SELECT route_id, status FROM {$rfq_routes_table} 
                WHERE item_id = %d 
                AND supplier_id = %d",
                $item_id,
                $current_user->ID
            ) );

            if ( ! $route ) {
                wp_send_json_error( array( 'message' => 'Access denied. You do not have permission to bid on this item.' ), 403 );
            }

            // Block if route is still queued
            if ( $route->status === 'queued' ) {
                wp_send_json_error( array( 'message' => 'This RFQ is not yet available for bidding.' ) );
            }
        }

        // Re-validate using exact rules from 2.3.3
        $errors = array();

        // 1. Video links validation (min 1, max 3, allowed providers only)
        $video_links_json = isset( $_POST['video_links'] ) ? wp_unslash( $_POST['video_links'] ) : '[]';
        $video_links = json_decode( $video_links_json, true );
        
        if ( ! is_array( $video_links ) ) {
            $video_links = array();
        }
        
        // Filter out empty links
        $video_links = array_filter( array_map( 'trim', $video_links ) );
        
        // Commit 2.3.5.1: Video links are optional now, but if provided, max 3
        if ( count( $video_links ) > 3 ) {
            $errors['video_links'] = 'Maximum 3 video links allowed.';
        } else {
            // Validate each link against allowlist (only if provided)
            if ( count( $video_links ) > 0 ) {
                $allowed_domains = array(
                    'youtube.com', 'www.youtube.com', 'youtu.be',
                    'vimeo.com', 'www.vimeo.com',
                    'loom.com', 'www.loom.com'
                );
                
                foreach ( $video_links as $link ) {
                    $parsed_url = wp_parse_url( $link );
                    if ( ! $parsed_url || ! isset( $parsed_url['host'] ) ) {
                        $errors['video_links'] = 'Invalid URL format: ' . esc_html( $link );
                        break;
                    }
                    
                    $hostname = strtolower( $parsed_url['host'] );
                    $hostname = preg_replace( '/^www\./', '', $hostname );
                    
                    $is_allowed = false;
                    foreach ( $allowed_domains as $domain ) {
                        $domain_clean = preg_replace( '/^www\./', '', $domain );
                        if ( $hostname === $domain_clean || strpos( $hostname, '.' . $domain_clean ) !== false ) {
                            $is_allowed = true;
                            break;
                        }
                    }
                    
                    if ( ! $is_allowed ) {
                        $errors['video_links'] = 'Only YouTube, Vimeo, or Loom links are allowed. Invalid: ' . esc_html( $link );
                        break;
                    }
                }
            }
        }
        
        // 1.5. Bid Photos validation (required, min 1, max 5) - Commit 2.3.5.1
        // Check for bid_photo_ids (already uploaded to WordPress media library)
        $bid_photo_ids_raw = isset( $_POST['bid_photo_ids'] ) ? $_POST['bid_photo_ids'] : null;
        
        // Handle both JSON string and array formats
        $bid_photo_ids = array();
        if ( $bid_photo_ids_raw !== null ) {
            if ( is_string( $bid_photo_ids_raw ) ) {
                $decoded = json_decode( wp_unslash( $bid_photo_ids_raw ), true );
                if ( is_array( $decoded ) ) {
                    $bid_photo_ids = $decoded;
                }
            } elseif ( is_array( $bid_photo_ids_raw ) ) {
                $bid_photo_ids = $bid_photo_ids_raw;
            }
        }
        
        // Filter out invalid IDs
        $bid_photo_ids = array_filter( array_map( 'intval', $bid_photo_ids ), function( $id ) {
            return $id > 0;
        } );
        
        $bid_photos_count = count( $bid_photo_ids );
        
        if ( $bid_photos_count < 1 ) {
            $errors['bid_photos'] = 'At least 1 photo is required.';
        } elseif ( $bid_photos_count > 5 ) {
            $errors['bid_photos'] = 'Maximum 5 photos allowed.';
        } else {
            // Validate that all photo IDs exist in WordPress media library
            foreach ( $bid_photo_ids as $photo_id ) {
                $attachment = get_post( $photo_id );
                if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
                    $errors['bid_photos'] = 'Invalid photo ID: ' . $photo_id;
                    break;
                }
                
                // Verify it's an image
                $mime_type = get_post_mime_type( $photo_id );
                if ( ! $mime_type || strpos( $mime_type, 'image/' ) !== 0 ) {
                    $errors['bid_photos'] = 'Photo ID ' . $photo_id . ' is not a valid image.';
                    break;
                }
            }
        }

        // 2. Prototype video commitment (optional - YES or NO)
        $prototype_video_yes = isset( $_POST['prototype_video_yes'] ) ? intval( $_POST['prototype_video_yes'] ) : 0;
        $is_prototype_yes = ( $prototype_video_yes === 1 );

        // 3. Prototype timeline (required ONLY if prototype is YES)
        $prototype_timeline_option = isset( $_POST['prototype_timeline_option'] ) ? sanitize_text_field( wp_unslash( $_POST['prototype_timeline_option'] ) ) : '';
        if ( $is_prototype_yes ) {
            $allowed_timelines = array( '1-2w', '2-4w', '4-6w', '6-8w', '8-10w' );
            if ( empty( $prototype_timeline_option ) || ! in_array( $prototype_timeline_option, $allowed_timelines, true ) ) {
                $errors['prototype_timeline_option'] = 'Please select a valid prototype timeline.';
            }
        }

        // 4. Prototype cost (required ONLY if prototype is YES)
        $prototype_cost = isset( $_POST['prototype_cost'] ) ? sanitize_text_field( wp_unslash( $_POST['prototype_cost'] ) ) : '';
        if ( $is_prototype_yes ) {
            if ( empty( $prototype_cost ) ) {
                $errors['prototype_cost'] = 'Prototype cost is required.';
            } else {
                $prototype_cost_float = floatval( $prototype_cost );
                if ( ! is_numeric( $prototype_cost ) || $prototype_cost_float < 0 ) {
                    $errors['prototype_cost'] = 'Prototype cost must be a number greater than or equal to 0.';
                }
            }
        }

        // 5. Production lead time (non-empty text)
        $production_lead_time_text = isset( $_POST['production_lead_time_text'] ) ? sanitize_text_field( wp_unslash( $_POST['production_lead_time_text'] ) ) : '';
        if ( empty( trim( $production_lead_time_text ) ) ) {
            $errors['production_lead_time_text'] = 'Production lead time is required.';
        }

        // 6. Unit price (numeric > 0)
        $unit_price = isset( $_POST['unit_price'] ) ? sanitize_text_field( wp_unslash( $_POST['unit_price'] ) ) : '';
        if ( empty( $unit_price ) ) {
            $errors['unit_price'] = 'Unit price is required.';
        } else {
            $unit_price_float = floatval( $unit_price );
            if ( ! is_numeric( $unit_price ) || $unit_price_float <= 0 ) {
                $errors['unit_price'] = 'Unit price must be a number greater than 0.';
            }
        }

        // 7. Smart Alternatives suggestion (optional, structured data)
        $smart_alternatives_suggestion = null;
        if ( isset( $_POST['smart_alternatives_suggestion'] ) && ! empty( $_POST['smart_alternatives_suggestion'] ) ) {
            $smart_alt_json = wp_unslash( $_POST['smart_alternatives_suggestion'] );
            error_log( 'Bid validation - Received smart_alternatives_suggestion: ' . $smart_alt_json );
            $smart_alt_data = json_decode( $smart_alt_json, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                error_log( 'Bid validation - JSON decode error: ' . json_last_error_msg() );
            } else {
                error_log( 'Bid validation - Decoded smart_alt_data: ' . print_r( $smart_alt_data, true ) );
                if ( is_array( $smart_alt_data ) ) {
                    // Check if ANY field has data (not just category)
                    $has_data = false;
                    if ( ! empty( $smart_alt_data['category'] ) ) $has_data = true;
                    if ( ! empty( $smart_alt_data['from'] ) ) $has_data = true;
                    if ( ! empty( $smart_alt_data['to'] ) ) $has_data = true;
                    if ( ! empty( $smart_alt_data['price_impact'] ) ) $has_data = true;
                    if ( ! empty( $smart_alt_data['lead_time_impact'] ) ) $has_data = true;
                    if ( ! empty( $smart_alt_data['comparison_points'] ) && is_array( $smart_alt_data['comparison_points'] ) && count( $smart_alt_data['comparison_points'] ) > 0 ) {
                        $has_data = true;
                        // Validate comparison points max 3
                        if ( count( $smart_alt_data['comparison_points'] ) > 3 ) {
                            $errors['smart_alternatives'] = 'Maximum 3 comparison points allowed.';
                        }
                    }
                    
                    error_log( 'Bid validation - has_data: ' . ( $has_data ? 'true' : 'false' ) . ', errors: ' . ( empty( $errors['smart_alternatives'] ) ? 'none' : $errors['smart_alternatives'] ) );
                    
                    if ( $has_data && empty( $errors['smart_alternatives'] ) ) {
                        $smart_alternatives_suggestion = $smart_alt_data;
                        error_log( 'Bid validation - Set smart_alternatives_suggestion: ' . print_r( $smart_alternatives_suggestion, true ) );
                    } else {
                        error_log( 'Bid validation - NOT setting smart_alternatives_suggestion (has_data: ' . ( $has_data ? 'true' : 'false' ) . ', has_errors: ' . ( empty( $errors['smart_alternatives'] ) ? 'false' : 'true' ) . ')' );
                    }
                } else {
                    error_log( 'Bid validation - smart_alt_data is not an array' );
                }
            }
        } else {
            error_log( 'Bid validation - smart_alternatives_suggestion not in POST or empty' );
        }

        // If validation errors, return them
        if ( ! empty( $errors ) ) {
            wp_send_json_error( array(
                'message' => 'Validation failed. Please correct the errors below.',
                'errors' => $errors,
            ) );
        }

        // D7: Revision guardrail - Check if item specs changed
        $items_table = $wpdb->prefix . 'n88_items';
        $item_meta_json = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_json FROM {$items_table} WHERE id = %d",
            $item_id
        ) );
        $item_meta = ! empty( $item_meta_json ) ? json_decode( $item_meta_json, true ) : array();
        if ( ! is_array( $item_meta ) ) {
            $item_meta = array();
        }
        
        // Get current item revision (default to 1 if not set)
        $item_current_revision = isset( $item_meta['rfq_revision_current'] ) ? intval( $item_meta['rfq_revision_current'] ) : 1;
        
        // Check if bids table has rfq_revision_at_submit column
        $bids_columns = $wpdb->get_col( "DESCRIBE {$item_bids_table}" );
        $has_revision_column = in_array( 'rfq_revision_at_submit', $bids_columns, true );
        
        // Start transaction
        $wpdb->query( 'START TRANSACTION' );

        try {
            // First, check if there's a bid for the current revision
            $current_revision_bid = null;
            if ( $has_revision_column ) {
                $current_revision_bid = $wpdb->get_row( $wpdb->prepare(
                    "SELECT bid_id, status FROM {$item_bids_table} 
                    WHERE item_id = %d 
                    AND supplier_id = %d
                    AND rfq_revision_at_submit = %d
                    AND status = 'submitted'
                    LIMIT 1",
                    $item_id,
                    $current_user->ID,
                    $item_current_revision
                ) );
            }
            
            // If there's already a submitted bid for current revision, block (no changes needed)
            if ( $current_revision_bid && $current_revision_bid->status === 'submitted' ) {
                $wpdb->query( 'ROLLBACK' );
                wp_send_json_error( array(
                    'message' => 'You\'ve already submitted a bid for this item.',
                ) );
            }
            
            // Check for existing bid (stale or draft) to update
            $existing_bid_query = "SELECT bid_id, status";
            if ( $has_revision_column ) {
                $existing_bid_query .= ", rfq_revision_at_submit";
            }
            $existing_bid_query .= " FROM {$item_bids_table} WHERE item_id = %d AND supplier_id = %d ORDER BY bid_id DESC LIMIT 1";
            
            $existing_bid = $wpdb->get_row( $wpdb->prepare(
                $existing_bid_query,
                $item_id,
                $current_user->ID
            ) );

            $is_stale_bid = false;
            $should_update_existing = false;
            
            // Check if existing bid is stale (revision mismatch or NULL revision) or is a draft
            if ( $existing_bid && $has_revision_column ) {
                $existing_bid_revision = isset( $existing_bid->rfq_revision_at_submit ) ? intval( $existing_bid->rfq_revision_at_submit ) : null;
                
                // Bid is stale if: revision is NULL or revision < current revision
                if ( $existing_bid_revision === null || ( $existing_bid_revision !== null && $existing_bid_revision < $item_current_revision ) ) {
                    $is_stale_bid = true;
                    
                    // Allow resubmission if bid is submitted and stale - UPDATE it
                    if ( $existing_bid->status === 'submitted' || $existing_bid->status === 'draft' ) {
                        $should_update_existing = true;
                    }
                } elseif ( $existing_bid_revision === $item_current_revision ) {
                    // Same revision - only allow if it's a draft
                    if ( $existing_bid->status === 'draft' ) {
                        // Allow updating draft to submitted
                        $should_update_existing = true;
                    }
                    // If submitted, we already blocked above
                }
            } elseif ( $existing_bid ) {
                // If no revision column, check status
                if ( $existing_bid->status === 'draft' ) {
                    // Allow updating draft to submitted
                    $should_update_existing = true;
                }
                // If submitted and no revision column, we can't check for stale bids, so block
                // (This is legacy behavior - should not happen if revision column exists)
            }

            // Prepare bid data
            $bid_data = array(
                'item_id' => $item_id,
                'supplier_id' => $current_user->ID,
                'is_anonymous' => 1, // Default
                'unit_price' => $unit_price_float,
                'production_lead_time_text' => $production_lead_time_text,
                'prototype_video_yes' => 1, // Must be 1
                'prototype_timeline_option' => $prototype_timeline_option,
                'prototype_cost' => $prototype_cost_float,
                'cad_yes' => null, // CAD removed from bid process - set to NULL
                'status' => 'submitted',
            );
            
            // Add Smart Alternatives suggestion to meta_json if provided
            $meta_json = null;
            if ( $smart_alternatives_suggestion !== null ) {
                $meta_json = wp_json_encode( array( 'smart_alternatives_suggestion' => $smart_alternatives_suggestion ) );
                error_log( 'Bid submission - Smart Alt data: ' . print_r( $smart_alternatives_suggestion, true ) );
                error_log( 'Bid submission - meta_json to save: ' . $meta_json );
            } else {
                error_log( 'Bid submission - smart_alternatives_suggestion is null, not saving' );
            }
            
            // Check if table has meta_json column, and try to add it if it doesn't
            $item_bids_table_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $item_bids_table );
            $table_columns = $wpdb->get_col( "DESCRIBE {$item_bids_table_safe}" );
            $has_bid_meta_json = in_array( 'meta_json', $table_columns, true );
            
            // If column doesn't exist, try to add it automatically
            if ( ! $has_bid_meta_json ) {
                $alter_result = $wpdb->query( "ALTER TABLE {$item_bids_table_safe} ADD COLUMN meta_json LONGTEXT NULL AFTER status" );
                if ( $alter_result !== false ) {
                    $has_bid_meta_json = true;
                    error_log( 'Bid submission - Successfully added meta_json column to bids table' );
                } else {
                    error_log( 'Bid submission - Failed to add meta_json column: ' . $wpdb->last_error );
                }
            }
            
            // Add meta_json to bid_data if column exists
            if ( $has_bid_meta_json ) {
                // Only add meta_json if it's not null (to avoid saving NULL)
                if ( $meta_json !== null ) {
                    $bid_data['meta_json'] = $meta_json;
                    error_log( 'Bid submission - meta_json column exists, adding to bid_data: ' . $meta_json );
                } else {
                    // Set to empty JSON object instead of NULL
                    $bid_data['meta_json'] = '{}';
                    error_log( 'Bid submission - meta_json column exists, but data is null, setting to empty JSON object' );
                }
            } else {
                error_log( 'Bid submission - meta_json column does NOT exist in table and could not be created' );
            }

            // D7: Revision guardrail - Final check before setting revision
            // Ensure bid.rfq_revision_at_submit will equal item.rfq_revision_current
            // (This check is already done above, but we verify again here before setting)
            
            // Check if rfq_revision_at_submit column exists, and try to add it if it doesn't
            $has_revision_column = in_array( 'rfq_revision_at_submit', $table_columns, true );
            
            // If column doesn't exist, try to add it automatically
            if ( ! $has_revision_column ) {
                // Determine position - add after meta_json if it exists, otherwise after status
                $after_column = $has_bid_meta_json ? 'meta_json' : 'status';
                $alter_result = $wpdb->query( "ALTER TABLE {$item_bids_table_safe} ADD COLUMN rfq_revision_at_submit INT UNSIGNED NULL AFTER {$after_column}" );
                if ( $alter_result !== false ) {
                    $has_revision_column = true;
                    $table_columns[] = 'rfq_revision_at_submit'; // Update local array
                    error_log( 'Bid submission - Successfully added rfq_revision_at_submit column to bids table' );
                } else {
                    error_log( 'Bid submission - Failed to add rfq_revision_at_submit column: ' . $wpdb->last_error );
                }
            }
            
            if ( $has_revision_column ) {
                // Set bid revision to current item revision (they should match at this point)
                $bid_data['rfq_revision_at_submit'] = $item_current_revision;
                error_log( 'Bid submission - Setting rfq_revision_at_submit to ' . $item_current_revision . ' for bid on item ' . $item_id );
            } else {
                error_log( 'Bid submission - rfq_revision_at_submit column does NOT exist in table and could not be created' );
            }

            // Prepare format array based on whether meta_json and rfq_revision_at_submit are included
            // Note: Use null in format array for NULL values (like cad_yes)
            $format_array = array( 
                '%d',  // item_id
                '%d',  // supplier_id
                '%d',  // is_anonymous
                '%f',  // unit_price
                '%s',  // production_lead_time_text
                '%d',  // prototype_video_yes
                '%s',  // prototype_timeline_option
                '%f',  // prototype_cost
                null,  // cad_yes (NULL)
                '%s',  // status
            );
            if ( isset( $bid_data['meta_json'] ) ) {
                $format_array[] = '%s';
            }
            if ( isset( $bid_data['rfq_revision_at_submit'] ) ) {
                $format_array[] = '%d';
            }
            
            // Validate that data and format arrays have matching counts
            $data_count = count( $bid_data );
            $format_count = count( $format_array );
            if ( $data_count !== $format_count ) {
                error_log( 'Bid submission - Data/Format mismatch! Data count: ' . $data_count . ', Format count: ' . $format_count );
                error_log( 'Bid submission - Data keys: ' . implode( ', ', array_keys( $bid_data ) ) );
                error_log( 'Bid submission - Format array: ' . print_r( $format_array, true ) );
                $wpdb->query( 'ROLLBACK' );
                throw new Exception( 'Internal error: Data and format arrays do not match. Please contact support.' );
            }

            if ( $should_update_existing && $existing_bid ) {
                // UPDATE existing stale submitted bid with new revision
                $update_result = $wpdb->update(
                    $item_bids_table,
                    $bid_data,
                    array( 'bid_id' => $existing_bid->bid_id ),
                    $format_array,
                    array( '%d' )
                );
                
                if ( $update_result === false ) {
                    error_log( 'Bid update failed - Database error: ' . $wpdb->last_error );
                    error_log( 'Bid update failed - Data: ' . print_r( $bid_data, true ) );
                    error_log( 'Bid update failed - Format: ' . print_r( $format_array, true ) );
                    $wpdb->query( 'ROLLBACK' );
                    throw new Exception( 'Failed to update bid: ' . $wpdb->last_error );
                }
                
                $bid_id = $existing_bid->bid_id;
                error_log( 'Bid resubmission - Updated existing stale bid (ID: ' . $bid_id . ') with new revision ' . $item_current_revision );
            } elseif ( $existing_bid && $existing_bid->status === 'withdrawn' ) {
                // UPDATE existing withdrawn bid
                $update_result = $wpdb->update(
                    $item_bids_table,
                    $bid_data,
                    array( 'bid_id' => $existing_bid->bid_id ),
                    $format_array,
                    array( '%d' )
                );
                
                if ( $update_result === false ) {
                    error_log( 'Bid update failed (withdrawn) - Database error: ' . $wpdb->last_error );
                    $wpdb->query( 'ROLLBACK' );
                    throw new Exception( 'Failed to update bid: ' . $wpdb->last_error );
                }
                
                $bid_id = $existing_bid->bid_id;
            } else {
                // INSERT new bid
                // Check one more time if a bid exists (in case it was created between our check and now)
                $final_check = $wpdb->get_row( $wpdb->prepare(
                    "SELECT bid_id, status FROM {$item_bids_table} WHERE item_id = %d AND supplier_id = %d LIMIT 1",
                    $item_id,
                    $current_user->ID
                ) );
                
                if ( $final_check ) {
                    // Bid exists - update it instead
                    $update_result = $wpdb->update(
                        $item_bids_table,
                        $bid_data,
                        array( 'bid_id' => $final_check->bid_id ),
                        $format_array,
                        array( '%d' )
                    );
                    
                    if ( $update_result === false ) {
                        error_log( 'Bid update failed (final check) - Database error: ' . $wpdb->last_error );
                        error_log( 'Bid update failed - Data: ' . print_r( $bid_data, true ) );
                        error_log( 'Bid update failed - Format: ' . print_r( $format_array, true ) );
                        $wpdb->query( 'ROLLBACK' );
                        throw new Exception( 'Failed to update bid: ' . ( $wpdb->last_error ? $wpdb->last_error : 'Unknown database error' ) );
                    }
                    
                    $bid_id = $final_check->bid_id;
                    error_log( 'Bid submission - Updated existing bid (ID: ' . $bid_id . ') found during final check' );
                } else {
                    // No bid exists - insert new one
                    $insert_result = $wpdb->insert(
                        $item_bids_table,
                        $bid_data,
                        $format_array
                    );
                    
                    if ( $insert_result === false ) {
                        $db_error = $wpdb->last_error ? $wpdb->last_error : 'Unknown database error';
                        error_log( 'Bid insert failed - Database error: ' . $db_error );
                        error_log( 'Bid insert failed - Last query: ' . $wpdb->last_query );
                        error_log( 'Bid insert failed - Data: ' . print_r( $bid_data, true ) );
                        error_log( 'Bid insert failed - Format: ' . print_r( $format_array, true ) );
                        error_log( 'Bid insert failed - Data count: ' . count( $bid_data ) . ', Format count: ' . count( $format_array ) );
                        $wpdb->query( 'ROLLBACK' );
                        throw new Exception( 'Failed to insert bid: ' . $db_error );
                    }
                    
                    $bid_id = $wpdb->insert_id;
                    
                    if ( ! $bid_id ) {
                        error_log( 'Bid insert succeeded but no insert_id returned' );
                        $wpdb->query( 'ROLLBACK' );
                        throw new Exception( 'Failed to insert bid: Insert succeeded but no bid ID returned.' );
                    }
                    
                    error_log( 'Bid submission - Inserted new bid (ID: ' . $bid_id . ')' );
                }
            }

            if ( ! $bid_id ) {
                error_log( 'Bid save failed - No bid_id returned. Last error: ' . $wpdb->last_error );
                $wpdb->query( 'ROLLBACK' );
                throw new Exception( 'Failed to save bid. No bid ID returned.' );
            }

            // Replace media links (DELETE old, INSERT new)
            $wpdb->delete(
                $bid_media_links_table,
                array( 'bid_id' => $bid_id ),
                array( '%d' )
            );

            // Insert new media links (optional, 0-3)
            $sort_order = 0;
            foreach ( $video_links as $link ) {
                // Determine provider from URL (matching validation logic)
                $parsed_url = wp_parse_url( $link );
                $hostname = strtolower( $parsed_url['host'] );
                $hostname = preg_replace( '/^www\./', '', $hostname );
                
                $provider = 'youtube'; // Default
                $hostname_clean = preg_replace( '/^www\./', '', $hostname );
                
                if ( $hostname_clean === 'vimeo.com' || strpos( $hostname_clean, '.vimeo.com' ) !== false ) {
                    $provider = 'vimeo';
                } elseif ( $hostname_clean === 'loom.com' || strpos( $hostname_clean, '.loom.com' ) !== false ) {
                    $provider = 'loom';
                } elseif ( $hostname_clean === 'youtube.com' || $hostname_clean === 'youtu.be' || strpos( $hostname_clean, '.youtube.com' ) !== false ) {
                    $provider = 'youtube';
                }

                $wpdb->insert(
                    $bid_media_links_table,
                    array(
                        'bid_id' => $bid_id,
                        'provider' => $provider,
                        'url' => $link,
                        'sort_order' => $sort_order,
                    ),
                    array( '%d', '%s', '%s', '%d' )
                );
                $sort_order++;
            }
            
            // Commit 2.3.5.1: Handle bid photos - support both file uploads and existing attachment IDs
            $bid_media_files_table = $wpdb->prefix . 'n88_bid_media_files';
            
            // Delete old bid photos
            $wpdb->delete(
                $bid_media_files_table,
                array( 'bid_id' => $bid_id ),
                array( '%d' )
            );
            
            $photo_sort_order = 0;
            
            // First, handle bid_photo_ids (already uploaded WordPress attachments)
            if ( ! empty( $bid_photo_ids ) && is_array( $bid_photo_ids ) ) {
                foreach ( $bid_photo_ids as $photo_id ) {
                    $photo_id = intval( $photo_id );
                    if ( $photo_id > 0 ) {
                        // Get attachment URL
                        $file_url = wp_get_attachment_url( $photo_id );
                        if ( $file_url ) {
                            // Save to n88_bid_media_files table
                            $wpdb->insert(
                                $bid_media_files_table,
                                array(
                                    'bid_id' => $bid_id,
                                    'file_url' => $file_url,
                                    'sort_order' => $photo_sort_order,
                                ),
                                array( '%d', '%s', '%d' )
                            );
                            
                            $photo_sort_order++;
                        }
                    }
                }
            }
            
            // Also handle file uploads if provided (fallback for direct file uploads)
            if ( ! empty( $_FILES['bid_photos'] ) ) {
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                
                $files_to_upload = array();
                if ( is_array( $_FILES['bid_photos']['name'] ) ) {
                    foreach ( $_FILES['bid_photos']['name'] as $index => $name ) {
                        if ( ! empty( $name ) ) {
                            $files_to_upload[] = array(
                                'name' => $name,
                                'type' => $_FILES['bid_photos']['type'][ $index ],
                                'tmp_name' => $_FILES['bid_photos']['tmp_name'][ $index ],
                                'error' => $_FILES['bid_photos']['error'][ $index ],
                                'size' => $_FILES['bid_photos']['size'][ $index ],
                            );
                        }
                    }
                } else {
                    if ( ! empty( $_FILES['bid_photos']['name'] ) ) {
                        $files_to_upload[] = $_FILES['bid_photos'];
                    }
                }
                
                foreach ( $files_to_upload as $file ) {
                    if ( $file['error'] === UPLOAD_ERR_OK ) {
                        $upload = wp_handle_upload( $file, array( 'test_form' => false ) );
                        if ( ! isset( $upload['error'] ) ) {
                            $attachment = array(
                                'post_mime_type' => $upload['type'],
                                'post_title'     => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ),
                                'post_content'   => '',
                                'post_status'    => 'inherit'
                            );
                            $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
                            $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
                            wp_update_attachment_metadata( $attach_id, $attach_data );
                            
                            $file_url = $upload['url'];
                            
                            // Save to n88_bid_media_files table
                            $wpdb->insert(
                                $bid_media_files_table,
                                array(
                                    'bid_id' => $bid_id,
                                    'file_url' => $file_url,
                                    'sort_order' => $photo_sort_order,
                                ),
                                array( '%d', '%s', '%d' )
                            );
                            
                            $photo_sort_order++;
                        }
                    }
                }
            }

            // Update route status to bid_submitted
            $wpdb->update(
                $rfq_routes_table,
                array( 'status' => 'bid_submitted' ),
                array(
                    'item_id' => $item_id,
                    'supplier_id' => $current_user->ID,
                ),
                array( '%s' ),
                array( '%d', '%d' )
            );

            $wpdb->query( 'COMMIT' );

            // Clear draft from user meta after successful submission
            $draft_meta_key = 'n88_bid_draft_' . $item_id;
            delete_user_meta( $current_user->ID, $draft_meta_key );
            
            // Also delete any database draft bids for this item (cleanup)
            $wpdb->delete(
                $item_bids_table,
                array(
                    'item_id' => $item_id,
                    'supplier_id' => $current_user->ID,
                    'status' => 'draft',
                ),
                array( '%d', '%d', '%s' )
            );

            wp_send_json_success( array(
                'message' => 'Bid submitted successfully!',
                'bid_id' => $bid_id,
            ) );

        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array(
                'message' => 'An error occurred while submitting the bid: ' . $e->getMessage(),
            ) );
        }
    }

    /**
     * AJAX handler to withdraw supplier bid (Commit 2.3.5 - Optional)
     */
    public function ajax_withdraw_supplier_bid() {
        check_ajax_referer( 'n88_withdraw_supplier_bid', '_ajax_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        if ( ! $is_supplier && ! $is_system_operator ) {
            wp_send_json_error( array( 'message' => 'Access denied. Maker account required.' ) );
        }

        $item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
        
        if ( ! $item_id ) {
            wp_send_json_error( array( 'message' => 'Invalid item ID.' ) );
        }

        global $wpdb;
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';

        // Find existing bid
        $existing_bid = $wpdb->get_row( $wpdb->prepare(
            "SELECT bid_id, status FROM {$item_bids_table} 
            WHERE item_id = %d AND supplier_id = %d",
            $item_id,
            $current_user->ID
        ) );

        if ( ! $existing_bid ) {
            wp_send_json_error( array( 'message' => 'No bid found to withdraw.' ) );
        }

        if ( $existing_bid->status === 'withdrawn' ) {
            wp_send_json_error( array( 'message' => 'This bid has already been withdrawn.' ) );
        }

        // Update bid status to withdrawn
        $updated = $wpdb->update(
            $item_bids_table,
            array( 'status' => 'withdrawn' ),
            array( 'bid_id' => $existing_bid->bid_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to withdraw bid. Please try again.' ) );
        }

        // Check if there are any other submitted bids for this item (from any supplier)
        // If no submitted bids remain, revert route status back to 'viewed' so designer board shows "Standby" instead of "Bids Received"
        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
        
        // Count remaining submitted bids for this item
        $remaining_submitted_bids = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$item_bids_table} 
            WHERE item_id = %d 
            AND status = 'submitted'",
            $item_id
        ) );
        
        // If no submitted bids remain, update route status back to 'viewed'
        if ( intval( $remaining_submitted_bids ) === 0 ) {
            // Update all routes for this item from 'bid_submitted' back to 'viewed'
            $wpdb->update(
                $rfq_routes_table,
                array( 'status' => 'viewed' ),
                array(
                    'item_id' => $item_id,
                    'status' => 'bid_submitted',
                ),
                array( '%s' ),
                array( '%d', '%s' )
            );
        } else {
            // There are still other submitted bids - only update this supplier's route
            // Check if this supplier's route is 'bid_submitted' and there are no other submitted bids from this supplier
            $supplier_submitted_bids = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$item_bids_table} 
                WHERE item_id = %d 
                AND supplier_id = %d 
                AND status = 'submitted'",
                $item_id,
                $current_user->ID
            ) );
            
            // If this supplier has no other submitted bids, update their route back to 'viewed'
            if ( intval( $supplier_submitted_bids ) === 0 ) {
                $wpdb->update(
                    $rfq_routes_table,
                    array( 'status' => 'viewed' ),
                    array(
                        'item_id' => $item_id,
                        'supplier_id' => $current_user->ID,
                        'status' => 'bid_submitted',
                    ),
                    array( '%s' ),
                    array( '%d', '%d', '%s' )
                );
            }
        }

        wp_send_json_success( array(
            'message' => 'Bid withdrawn successfully. You can resubmit a new bid.',
        ) );
    }

    /**
     * AJAX handler to save bid draft (Commit 2.3.6)
     * Stores draft bid data in user meta for later retrieval
     */
    public function ajax_save_bid_draft() {
        check_ajax_referer( 'n88_save_bid_draft', '_ajax_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        if ( ! $is_supplier && ! $is_system_operator ) {
            wp_send_json_error( array( 'message' => 'Access denied. Maker account required.' ) );
        }

        $item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
        
        if ( ! $item_id ) {
            wp_send_json_error( array( 'message' => 'Invalid item ID.' ) );
        }

        // Verify supplier has route for this item (unless system operator)
        if ( ! $is_system_operator ) {
            global $wpdb;
            $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
            
            // Commit 2.3.7.1: Check and update expired routes (lazy evaluation)
            $this->check_and_update_expired_routes( $item_id, $current_user->ID );
            
            // Check if route is expired
            if ( $this->is_route_expired( $item_id, $current_user->ID ) ) {
                wp_send_json_error( array( 'message' => 'This RFQ is no longer accepting bids.' ), 403 );
            }
            
            $route_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$rfq_routes_table} 
                WHERE item_id = %d 
                AND supplier_id = %d 
                AND status IN ('queued', 'sent', 'viewed', 'bid_submitted')",
                $item_id,
                $current_user->ID
            ) );

            if ( ! $route_exists || intval( $route_exists ) === 0 ) {
                wp_send_json_error( array( 'message' => 'Access denied. You do not have permission to bid on this item.' ), 403 );
            }
        }

        // Collect draft data
        $draft_data = array(
            'item_id' => $item_id,
            'saved_at' => current_time( 'mysql' ),
        );

        // Video links
        $video_links_json = isset( $_POST['video_links'] ) ? wp_unslash( $_POST['video_links'] ) : '[]';
        $video_links = json_decode( $video_links_json, true );
        if ( is_array( $video_links ) ) {
            $draft_data['video_links'] = array_map( 'esc_url_raw', $video_links );
        } else {
            $draft_data['video_links'] = array();
        }

        // Bid photos - store both IDs and URLs for restoration
        $bid_photo_ids_json = isset( $_POST['bid_photo_ids'] ) ? wp_unslash( $_POST['bid_photo_ids'] ) : '[]';
        $bid_photo_ids = json_decode( $bid_photo_ids_json, true );
        if ( is_array( $bid_photo_ids ) ) {
            $draft_data['bid_photo_ids'] = array_map( 'intval', $bid_photo_ids );
            // Also store URLs for easy restoration
            $draft_data['bid_photo_urls'] = array();
            foreach ( $bid_photo_ids as $photo_id ) {
                $photo_url = wp_get_attachment_image_url( $photo_id, 'medium' );
                if ( ! $photo_url ) {
                    $photo_url = wp_get_attachment_url( $photo_id );
                }
                if ( $photo_url ) {
                    $draft_data['bid_photo_urls'][] = array(
                        'id' => $photo_id,
                        'url' => esc_url_raw( $photo_url ),
                    );
                }
            }
        } else {
            $draft_data['bid_photo_ids'] = array();
            $draft_data['bid_photo_urls'] = array();
        }

        // Form fields
        $draft_data['prototype_video_yes'] = isset( $_POST['prototype_video_yes'] ) ? sanitize_text_field( $_POST['prototype_video_yes'] ) : '';
        $draft_data['prototype_timeline_option'] = isset( $_POST['prototype_timeline_option'] ) ? sanitize_text_field( $_POST['prototype_timeline_option'] ) : '';
        $draft_data['prototype_cost'] = isset( $_POST['prototype_cost'] ) ? sanitize_text_field( $_POST['prototype_cost'] ) : '';
        $draft_data['production_lead_time_text'] = isset( $_POST['production_lead_time_text'] ) ? sanitize_text_field( $_POST['production_lead_time_text'] ) : '';
        $draft_data['unit_price'] = isset( $_POST['unit_price'] ) ? sanitize_text_field( $_POST['unit_price'] ) : '';

        // Smart Alternatives suggestion
        if ( isset( $_POST['smart_alternatives_suggestion'] ) && ! empty( $_POST['smart_alternatives_suggestion'] ) ) {
            $smart_alt_json = wp_unslash( $_POST['smart_alternatives_suggestion'] );
            $smart_alt_data = json_decode( $smart_alt_json, true );
            if ( is_array( $smart_alt_data ) ) {
                $draft_data['smart_alternatives_suggestion'] = $smart_alt_data;
            }
        }

        // Store draft in user meta (key: n88_bid_draft_{item_id})
        $meta_key = 'n88_bid_draft_' . $item_id;
        $meta_value = wp_json_encode( $draft_data );
        
        $updated = update_user_meta( $current_user->ID, $meta_key, $meta_value );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to save draft. Please try again.' ) );
        }

        wp_send_json_success( array(
            'message' => 'Draft saved successfully.',
            'saved_at' => $draft_data['saved_at'],
        ) );
    }

    /**
     * AJAX handler to get bid draft (Commit 2.3.6)
     * Retrieves saved draft bid data from user meta OR database draft bid
     */
    public function ajax_get_bid_draft() {
        check_ajax_referer( 'n88_get_bid_draft', '_ajax_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        if ( ! $is_supplier && ! $is_system_operator ) {
            wp_send_json_error( array( 'message' => 'Access denied. Maker account required.' ) );
        }

        $item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
        
        if ( ! $item_id ) {
            wp_send_json_error( array( 'message' => 'Invalid item ID.' ) );
        }

        global $wpdb;
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $bid_media_links_table = $wpdb->prefix . 'n88_bid_media_links';
        $bid_media_files_table = $wpdb->prefix . 'n88_bid_media_files';
        
        // First, check for draft bid in database (takes priority)
        $draft_bid = $wpdb->get_row( $wpdb->prepare(
            "SELECT bid_id, unit_price, production_lead_time_text, prototype_video_yes, prototype_timeline_option, prototype_cost, meta_json
            FROM {$item_bids_table}
            WHERE item_id = %d
            AND supplier_id = %d
            AND status = 'draft'
            ORDER BY bid_id DESC
            LIMIT 1",
            $item_id,
            $current_user->ID
        ), ARRAY_A );
        
        if ( $draft_bid ) {
            // Draft bid exists in database - get full data including images
            $bid_id = intval( $draft_bid['bid_id'] );
            
            // Get video links
            $video_links = $wpdb->get_results( $wpdb->prepare(
                "SELECT url, provider FROM {$bid_media_links_table}
                WHERE bid_id = %d
                ORDER BY sort_order ASC, id ASC",
                $bid_id
            ), ARRAY_A );
            
            // Get bid photos
            $bid_photos = $wpdb->get_results( $wpdb->prepare(
                "SELECT file_url FROM {$bid_media_files_table}
                WHERE bid_id = %d
                ORDER BY sort_order ASC, id ASC",
                $bid_id
            ), ARRAY_A );
            
            $photo_urls = array();
            $bid_photo_ids = array();
            $bid_photo_urls_array = array();
            foreach ( $bid_photos as $photo ) {
                if ( isset( $photo['file_url'] ) && ! empty( $photo['file_url'] ) ) {
                    $photo_urls[] = esc_url_raw( $photo['file_url'] );
                    // Try to get attachment ID from URL
                    $attachment_id = attachment_url_to_postid( $photo['file_url'] );
                    if ( $attachment_id ) {
                        $bid_photo_ids[] = $attachment_id;
                        $bid_photo_urls_array[] = array(
                            'id' => $attachment_id,
                            'url' => esc_url_raw( $photo['file_url'] ),
                        );
                    } else {
                        // If no attachment ID, just store URL
                        $bid_photo_urls_array[] = array(
                            'id' => null,
                            'url' => esc_url_raw( $photo['file_url'] ),
                        );
                    }
                }
            }
            
            // Get Smart Alternatives from meta_json
            $smart_alternatives_suggestion = null;
            if ( ! empty( $draft_bid['meta_json'] ) ) {
                $bid_meta = json_decode( $draft_bid['meta_json'], true );
                if ( is_array( $bid_meta ) && isset( $bid_meta['smart_alternatives_suggestion'] ) ) {
                    $smart_alternatives_suggestion = $bid_meta['smart_alternatives_suggestion'];
                }
            }
            
            // Build draft data array
            $draft = array(
                'unit_price' => $draft_bid['unit_price'] ? floatval( $draft_bid['unit_price'] ) : null,
                'production_lead_time_text' => $draft_bid['production_lead_time_text'] ? sanitize_text_field( $draft_bid['production_lead_time_text'] ) : null,
                'prototype_video_yes' => intval( $draft_bid['prototype_video_yes'] ) === 1,
                'prototype_timeline_option' => $draft_bid['prototype_timeline_option'] ? sanitize_text_field( $draft_bid['prototype_timeline_option'] ) : null,
                'prototype_cost' => $draft_bid['prototype_cost'] ? floatval( $draft_bid['prototype_cost'] ) : null,
                'video_links' => array_map( function( $link ) {
                    return esc_url_raw( $link['url'] );
                }, $video_links ),
                'bid_photos' => $photo_urls,
                'bid_photo_ids' => $bid_photo_ids,
                'bid_photo_urls' => $bid_photo_urls_array,
                'smart_alternatives_suggestion' => $smart_alternatives_suggestion,
            );
            
            wp_send_json_success( array( 'draft' => $draft ) );
        }
        
        // Fallback: Get draft from user meta
        $meta_key = 'n88_bid_draft_' . $item_id;
        $draft_json = get_user_meta( $current_user->ID, $meta_key, true );

        if ( empty( $draft_json ) ) {
            wp_send_json_success( array( 'draft' => null ) );
        }

        $draft = json_decode( $draft_json, true );

        if ( ! is_array( $draft ) ) {
            wp_send_json_success( array( 'draft' => null ) );
        }

        wp_send_json_success( array( 'draft' => $draft ) );
    }

    /**
     * AJAX handler for RFQ submission routing (Commit 2.3.4)
     * Handles single-item and multi-item submissions
     * Creates routes and delivery context per item
     */
    public function ajax_submit_rfq() {
        check_ajax_referer( 'n88_submit_rfq', '_ajax_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }

        $current_user = wp_get_current_user();
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );

        if ( ! $is_designer && ! $is_system_operator ) {
            wp_send_json_error( array( 'message' => 'Access denied. Creator or System Operator account required.' ) );
        }

        global $wpdb;
        $items_table = $wpdb->prefix . 'n88_items';
        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
        $item_delivery_context_table = $wpdb->prefix . 'n88_item_delivery_context';
        $supplier_profiles_table = $wpdb->prefix . 'n88_supplier_profiles';
        $supplier_keyword_map_table = $wpdb->prefix . 'n88_supplier_keyword_map';
        $categories_table = $wpdb->prefix . 'n88_categories';
        $users_table = $wpdb->prefix . 'users';

        // Parse input: items array (each item has: item_id, quantity, dimensions, delivery)
        $items_json = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
        $items = json_decode( $items_json, true );

        if ( ! is_array( $items ) || empty( $items ) ) {
            wp_send_json_error( array( 'message' => 'At least one item is required.' ) );
        }

        // Routing options
        $invited_suppliers_raw = isset( $_POST['invited_suppliers'] ) ? wp_unslash( $_POST['invited_suppliers'] ) : '[]';
        $invited_suppliers = json_decode( $invited_suppliers_raw, true );
        if ( ! is_array( $invited_suppliers ) ) {
            $invited_suppliers = array();
        }
        // Sanitize each invited supplier
        $invited_suppliers = array_map( function( $supplier ) {
            return trim( sanitize_text_field( $supplier ) );
        }, $invited_suppliers );
        $invited_suppliers = array_filter( $invited_suppliers ); // Remove empty values
        
        // Validate max 5 invited suppliers
        if ( count( $invited_suppliers ) > 5 ) {
            wp_send_json_error( array(
                'message' => 'Maximum 5 invited makers allowed.',
                'errors' => array( 'invited_suppliers' => 'Maximum 5 invited makers allowed.' ),
            ) );
        }
        
        $allow_system_invites = isset( $_POST['allow_system_invites'] ) ? (bool) $_POST['allow_system_invites'] : false;

        // Validation: Case D - if no invite and toggle OFF, block
        if ( empty( $invited_suppliers ) && ! $allow_system_invites ) {
            wp_send_json_error( array(
                'message' => 'Invite at least one maker or allow the system to invite makers.',
                'errors' => array( 'routing' => 'At least one routing option must be selected.' ),
            ) );
        }

        $errors = array();
        $validated_items = array();

        // Validate each item
        foreach ( $items as $index => $item_data ) {
            $item_id = isset( $item_data['item_id'] ) ? intval( $item_data['item_id'] ) : 0;
            $quantity = isset( $item_data['quantity'] ) ? intval( $item_data['quantity'] ) : 0;
            $width = isset( $item_data['width'] ) ? floatval( $item_data['width'] ) : 0;
            $depth = isset( $item_data['depth'] ) ? floatval( $item_data['depth'] ) : 0;
            $height = isset( $item_data['height'] ) ? floatval( $item_data['height'] ) : 0;
            $dimension_unit = isset( $item_data['dimension_unit'] ) ? sanitize_text_field( $item_data['dimension_unit'] ) : 'in';
            $delivery_country = isset( $item_data['delivery_country'] ) ? strtoupper( trim( sanitize_text_field( $item_data['delivery_country'] ) ) ) : '';
            $delivery_postal = isset( $item_data['delivery_postal'] ) ? trim( sanitize_text_field( $item_data['delivery_postal'] ) ) : '';

            // Validate item exists and user owns it
            $item = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, owner_user_id, item_type, deleted_at FROM {$items_table} WHERE id = %d",
                $item_id
            ) );

            if ( ! $item || $item->deleted_at !== null ) {
                $errors[ "item_{$index}" ] = "Item #{$item_id} not found or deleted.";
                continue;
            }

            if ( (int) $item->owner_user_id !== $current_user->ID && ! $is_system_operator ) {
                $errors[ "item_{$index}" ] = "You do not have permission to submit RFQ for item #{$item_id}.";
                continue;
            }

            // Validate quantity
            if ( $quantity < 1 ) {
                $errors[ "item_{$index}_quantity" ] = 'Quantity must be at least 1.';
            }

            // Validate dimensions
            if ( $width <= 0 || $depth <= 0 || $height <= 0 ) {
                $errors[ "item_{$index}_dimensions" ] = 'All dimensions (width, depth, height) must be greater than 0.';
            }

            // Allow all dimension units (in, cm, mm, m) - validation removed as requested
            // Default to 'in' if empty or invalid
            if ( empty( $dimension_unit ) || ! in_array( $dimension_unit, array( 'in', 'cm', 'mm', 'm' ), true ) ) {
                $dimension_unit = 'in'; // Default fallback
            }

            // Validate delivery country
            if ( empty( $delivery_country ) || strlen( $delivery_country ) !== 2 ) {
                $errors[ "item_{$index}_delivery_country" ] = 'Valid delivery country code (2 letters) is required.';
            }

            // Validate ZIP for US/CA
            if ( in_array( $delivery_country, array( 'US', 'CA' ), true ) && empty( $delivery_postal ) ) {
                $errors[ "item_{$index}_delivery_postal" ] = 'ZIP/postal code is required for US and Canada.';
            }

            if ( empty( $errors ) ) {
                $validated_items[] = array(
                    'item_id' => $item_id,
                    'item' => $item,
                    'quantity' => $quantity,
                    'width' => $width,
                    'depth' => $depth,
                    'height' => $height,
                    'dimension_unit' => $dimension_unit,
                    'delivery_country' => $delivery_country,
                    'delivery_postal' => $delivery_postal,
                );
            }
        }

        // If validation errors, return them
        if ( ! empty( $errors ) ) {
            wp_send_json_error( array(
                'message' => 'Validation failed. Please correct the errors below.',
                'errors' => $errors,
            ) );
        }

        // Start transaction
        $wpdb->query( 'START TRANSACTION' );

        try {
            $invited_supplier_ids = array(); // Array of supplier IDs that exist
            $invite_emails_sent = array(); // Array of emails that were sent (non-existent suppliers)

            // Handle multiple supplier invites
            foreach ( $invited_suppliers as $invite_value ) {
                if ( empty( $invite_value ) ) {
                    continue;
                }
                
                // Check if it's an email or username
                if ( is_email( $invite_value ) ) {
                    // Email: check if user exists
                    $user = get_user_by( 'email', $invite_value );
                    if ( $user && in_array( 'n88_supplier_admin', $user->roles, true ) ) {
                        $invited_supplier_ids[] = $user->ID;
                    } else {
                        // Email doesn't exist or user is not a supplier - send invite email
                        $subject = "You've been invited to bid on an RFQ";
                        $message = "You've been invited to submit a bid. Create your maker account to view details.\n\n";
                        $message .= "Sign up here: " . home_url( '/supplier/onboarding' ) . "\n";
                        wp_mail( $invite_value, $subject, $message );
                        $invite_emails_sent[] = $invite_value;
                        // Do NOT create route yet - wait for supplier to sign up
                    }
                } else {
                    // Username: find user
                    $user = get_user_by( 'login', $invite_value );
                    if ( $user && in_array( 'n88_supplier_admin', $user->roles, true ) ) {
                        $invited_supplier_ids[] = $user->ID;
                    } else {
                        $errors['invited_suppliers'] = 'Maker "' . esc_html( $invite_value ) . '" not found. Please enter a valid maker username or email.';
                    }
                }
            }
            
            // Remove duplicates from invited supplier IDs
            $invited_supplier_ids = array_unique( $invited_supplier_ids );

            // Check if quantity and dimensions columns exist, add them if they don't (once per submission)
            $delivery_columns = $wpdb->get_col( "DESCRIBE {$item_delivery_context_table}" );
            $has_quantity = in_array( 'quantity', $delivery_columns, true );
            $has_dimensions = in_array( 'dimensions_json', $delivery_columns, true );
            
            // Add quantity column if it doesn't exist
            if ( ! $has_quantity ) {
                $table_safe = esc_sql( $item_delivery_context_table );
                $result = $wpdb->query( "ALTER TABLE {$table_safe} ADD COLUMN quantity INT UNSIGNED NULL AFTER shipping_estimate_mode" );
                if ( $result !== false ) {
                    $has_quantity = true;
                    error_log( 'RFQ Submission - Successfully added quantity column to delivery context table' );
                } else {
                    error_log( 'RFQ Submission - Failed to add quantity column to delivery context table: ' . $wpdb->last_error );
                }
            }
            
            // Add dimensions_json column if it doesn't exist
            if ( ! $has_dimensions ) {
                $table_safe = esc_sql( $item_delivery_context_table );
                $result = $wpdb->query( "ALTER TABLE {$table_safe} ADD COLUMN dimensions_json TEXT NULL AFTER quantity" );
                if ( $result !== false ) {
                    $has_dimensions = true;
                    error_log( 'RFQ Submission - Successfully added dimensions_json column to delivery context table' );
                } else {
                    error_log( 'RFQ Submission - Failed to add dimensions_json column to delivery context table: ' . $wpdb->last_error );
                }
            }

            // Process each item
            foreach ( $validated_items as $item_data ) {
                $item_id = $item_data['item_id'];
                $item = $item_data['item'];

                // 1. Write/update delivery context (including quantity and dimensions from RFQ submission)
                $shipping_mode = in_array( $item_data['delivery_country'], array( 'US', 'CA' ), true ) ? 'auto' : 'manual';

                $existing_delivery = $wpdb->get_var( $wpdb->prepare(
                    "SELECT item_id FROM {$item_delivery_context_table} WHERE item_id = %d",
                    $item_id
                ) );

                $delivery_data = array(
                    'item_id' => $item_id,
                    'delivery_country_code' => $item_data['delivery_country'],
                    'delivery_postal_code' => ! empty( $item_data['delivery_postal'] ) ? $item_data['delivery_postal'] : null,
                    'shipping_estimate_mode' => $shipping_mode,
                );
                
                // Add quantity and dimensions if columns exist
                if ( $has_quantity ) {
                    $delivery_data['quantity'] = $item_data['quantity'];
                }
                if ( $has_dimensions ) {
                    $dimensions_data = array(
                        'width' => floatval( $item_data['width'] ),
                        'depth' => floatval( $item_data['depth'] ),
                        'height' => floatval( $item_data['height'] ),
                        'unit' => sanitize_text_field( $item_data['dimension_unit'] ),
                    );
                    $delivery_data['dimensions_json'] = wp_json_encode( $dimensions_data );
                    
                    // Debug: Log dimensions being stored
                    error_log( 'RFQ Submission - Storing dimensions for item ' . $item_id . ': ' . wp_json_encode( $dimensions_data ) );
                } else {
                    error_log( 'RFQ Submission - WARNING: dimensions_json column does not exist for item ' . $item_id );
                }

                // Build format array dynamically based on columns
                $format_array = array( '%d', '%s', '%s', '%s' );
                if ( $has_quantity ) {
                    $format_array[] = '%d';
                }
                if ( $has_dimensions ) {
                    $format_array[] = '%s';
                }

                if ( $existing_delivery ) {
                    $update_result = $wpdb->update(
                        $item_delivery_context_table,
                        $delivery_data,
                        array( 'item_id' => $item_id ),
                        $format_array,
                        array( '%d' )
                    );
                    if ( $update_result === false ) {
                        error_log( 'RFQ Submission - Failed to update delivery context for item ' . $item_id . ': ' . $wpdb->last_error );
                    }
                } else {
                    $insert_result = $wpdb->insert(
                        $item_delivery_context_table,
                        $delivery_data,
                        $format_array
                    );
                    if ( $insert_result === false ) {
                        error_log( 'RFQ Submission - Failed to insert delivery context for item ' . $item_id . ': ' . $wpdb->last_error );
                    } else {
                        error_log( 'RFQ Submission - Successfully inserted delivery context for item ' . $item_id . ' with dimensions: ' . ( isset( $delivery_data['dimensions_json'] ) ? $delivery_data['dimensions_json'] : 'none' ) );
                    }
                }

                // 2. Create designer_invited routes (for each invited supplier that exists)
                foreach ( $invited_supplier_ids as $invited_supplier_id ) {
                    // Check if route already exists (idempotent)
                    $existing_route = $wpdb->get_var( $wpdb->prepare(
                        "SELECT route_id FROM {$rfq_routes_table} WHERE item_id = %d AND supplier_id = %d",
                        $item_id,
                        $invited_supplier_id
                    ) );

                    if ( ! $existing_route ) {
                        $wpdb->insert(
                            $rfq_routes_table,
                            array(
                                'item_id' => $item_id,
                                'supplier_id' => $invited_supplier_id,
                                'route_type' => 'designer_invited',
                                'eligible_after' => null,
                                'routed_at' => current_time( 'mysql' ),
                                'status' => 'sent',
                            ),
                            array( '%d', '%d', '%s', '%s', '%s', '%s' )
                        );
                    }
                }

                // 3. Create system_invited routes (if toggle is ON)
                if ( $allow_system_invites ) {
                    // Exclude already invited suppliers from system matching
                    $exclude_supplier_ids = $invited_supplier_ids;
                    $system_suppliers = $this->match_suppliers_for_item( $item, $exclude_supplier_ids, $wpdb );

                    // Check if any invites were provided (existing suppliers OR emails sent)
                    $has_any_invites_for_delay = ! empty( $invited_supplier_ids ) || ! empty( $invite_emails_sent );

                    foreach ( $system_suppliers as $supplier_id ) {
                        // Check if route already exists (idempotent)
                        $existing_route = $wpdb->get_var( $wpdb->prepare(
                            "SELECT route_id FROM {$rfq_routes_table} WHERE item_id = %d AND supplier_id = %d",
                            $item_id,
                            $supplier_id
                        ) );

                        if ( ! $existing_route ) {
                            // Case B: If 1+ invited suppliers OR emails sent, system routes are delayed 24h
                            // Case C: If no invited suppliers, system routes are immediate
                            if ( $has_any_invites_for_delay ) {
                                // Case B: Delayed system routes (24h delay)
                                $eligible_after = date( 'Y-m-d H:i:s', strtotime( '+24 hours' ) );
                                $wpdb->insert(
                                    $rfq_routes_table,
                                    array(
                                        'item_id' => $item_id,
                                        'supplier_id' => $supplier_id,
                                        'route_type' => 'system_invited',
                                        'eligible_after' => $eligible_after,
                                        'routed_at' => null,
                                        'status' => 'queued',
                                    ),
                                    array( '%d', '%d', '%s', '%s', '%s', '%s' )
                                );
                            } else {
                                // Case C: Immediate system routes
                                $wpdb->insert(
                                    $rfq_routes_table,
                                    array(
                                        'item_id' => $item_id,
                                        'supplier_id' => $supplier_id,
                                        'route_type' => 'system_invited',
                                        'eligible_after' => null,
                                        'routed_at' => current_time( 'mysql' ),
                                        'status' => 'sent',
                                    ),
                                    array( '%d', '%d', '%s', '%s', '%s', '%s' )
                                );
                            }
                        }
                    }
                }
                
                // Initialize rfq_revision_current to 1 if not already set (first RFQ submission)
                $item_meta_json = $wpdb->get_var( $wpdb->prepare(
                    "SELECT meta_json FROM {$items_table} WHERE id = %d",
                    $item_id
                ) );
                $item_meta = ! empty( $item_meta_json ) ? json_decode( $item_meta_json, true ) : array();
                if ( ! is_array( $item_meta ) ) {
                    $item_meta = array();
                }
                
                // Only set revision if not already set (first RFQ submission)
                if ( ! isset( $item_meta['rfq_revision_current'] ) || empty( $item_meta['rfq_revision_current'] ) ) {
                    $item_meta['rfq_revision_current'] = 1;
                    $item_meta['revision_changed'] = false; // Reset revision_changed flag on new RFQ
                    
                    $updated_meta_json = wp_json_encode( $item_meta );
                    $wpdb->update(
                        $items_table,
                        array( 'meta_json' => $updated_meta_json ),
                        array( 'id' => $item_id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    
                    error_log( 'RFQ Submission - Initialized rfq_revision_current to 1 for item ' . $item_id );
                }
            }

            $wpdb->query( 'COMMIT' );

            // Build success message based on cases
            $message = 'RFQ submitted successfully.';
            $invited_count = count( $invited_supplier_ids );
            $email_count = count( $invite_emails_sent );
            $has_any_invites = $invited_count > 0 || $email_count > 0;
            
            if ( $allow_system_invites ) {
                // Toggle ON
                if ( $has_any_invites ) {
                    // Toggle ON + email added
                    $message .= ' Your invited maker(s) will receive this request first. WireFrame (OS) will invite additional makers after 24 hours.';
                } else {
                    // Toggle ON + NO email entered
                    $message .= ' We sent your request to makers that match your category and keywords.';
                }
            } else {
                // Toggle OFF - only invited suppliers
                if ( $has_any_invites ) {
                    // Only email added (toggle OFF)
                    $message .= ' Your invited maker(s) will receive this request.';
                }
            }

            wp_send_json_success( array(
                'message' => $message,
                'items_processed' => count( $validated_items ),
                'state_updated' => array(
                    'has_rfq' => true,
                    'has_bids' => false,
                ),
            ) );

        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array(
                'message' => 'An error occurred while submitting the RFQ: ' . $e->getMessage(),
            ) );
        }
    }

    /**
     * Match suppliers for an item based on category, keywords, geography, and status (Commit 2.3.4)
     * Returns up to 2 supplier IDs
     * 
     * @param object $item Item row from database
     * @param int|array|null $exclude_supplier_ids Supplier ID(s) to exclude (already invited) - can be single ID or array
     * @param wpdb $wpdb Database instance
     * @return array Array of supplier IDs (max 2)
     */
    private function match_suppliers_for_item( $item, $exclude_supplier_ids = null, $wpdb ) {
        $supplier_profiles_table = $wpdb->prefix . 'n88_supplier_profiles';
        $categories_table = $wpdb->prefix . 'n88_categories';
        $supplier_keyword_map_table = $wpdb->prefix . 'n88_supplier_keyword_map';

        // Map item_type to category (simplified - you may need to adjust based on your category structure)
        // For now, we'll match by item_type directly or find category by name
        $item_type = $item->item_type;

        // Find category ID by name (item_type should match category name)
        $category_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT category_id FROM {$categories_table} WHERE name = %s AND is_active = 1 LIMIT 1",
            $item_type
        ) );

        // Build base query for supplier matching
        $where_conditions = array(
            "sp.is_active = 1",
            "sp.is_overloaded = 0",
            "sp.prototype_video_capable = 1",
            "sp.origin_region IN ('USA', 'ASIA', 'CANADA')",
        );

        $where_params = array();

        // Category match
        if ( $category_id ) {
            $where_conditions[] = "sp.primary_category_id = %d";
            $where_params[] = $category_id;
        }

        // Exclude already invited suppliers (can be single ID or array of IDs)
        if ( ! empty( $exclude_supplier_ids ) ) {
            if ( is_array( $exclude_supplier_ids ) ) {
                if ( count( $exclude_supplier_ids ) > 0 ) {
                    $placeholders = implode( ',', array_fill( 0, count( $exclude_supplier_ids ), '%d' ) );
                    $where_conditions[] = "sp.supplier_id NOT IN ({$placeholders})";
                    $where_params = array_merge( $where_params, $exclude_supplier_ids );
                }
            } else {
                $where_conditions[] = "sp.supplier_id != %d";
                $where_params[] = $exclude_supplier_ids;
            }
        }

        $where_clause = implode( ' AND ', $where_conditions );

        // Build query
        if ( ! empty( $where_params ) ) {
            $query = $wpdb->prepare(
                "SELECT sp.supplier_id FROM {$supplier_profiles_table} sp
                WHERE {$where_clause}
                LIMIT 2",
                $where_params
            );
        } else {
            $query = "SELECT sp.supplier_id FROM {$supplier_profiles_table} sp
                WHERE {$where_clause}
                LIMIT 2";
        }

        $supplier_ids = $wpdb->get_col( $query );

        return array_map( 'intval', $supplier_ids );
    }
    
    /**
     * G) AJAX handler to update bid to match new specs
     * Creates a new draft bid for current revision, pre-filled from latest stale bid
     */
    public function ajax_update_bid_to_match_new_specs() {
        check_ajax_referer( 'n88_update_bid_to_match_new_specs', '_ajax_nonce' );
        
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ) );
        }
        
        $current_user = wp_get_current_user();
        $is_supplier = in_array( 'n88_supplier_admin', $current_user->roles, true );
        $is_system_operator = in_array( 'n88_system_operator', $current_user->roles, true );
        
        if ( ! $is_supplier && ! $is_system_operator ) {
            wp_send_json_error( array( 'message' => 'Access denied. Maker account required.' ) );
        }
        
        $item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
        
        if ( ! $item_id ) {
            wp_send_json_error( array( 'message' => 'Invalid item ID.' ) );
        }
        
        global $wpdb;
        $items_table = $wpdb->prefix . 'n88_items';
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
        $bid_media_links_table = $wpdb->prefix . 'n88_bid_media_links';
        $bid_media_files_table = $wpdb->prefix . 'n88_bid_media_files';
        
        // Verify supplier has route
        if ( ! $is_system_operator ) {
            $route_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$rfq_routes_table} 
                WHERE item_id = %d 
                AND supplier_id = %d 
                AND status IN ('queued', 'sent', 'viewed', 'bid_submitted')",
                $item_id,
                $current_user->ID
            ) );
            
            if ( ! $route_exists || intval( $route_exists ) === 0 ) {
                wp_send_json_error( array( 'message' => 'Access denied. You do not have permission to bid on this item.' ), 403 );
            }
        }
        
        // Get item current revision
        $item_meta_json = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_json FROM {$items_table} WHERE id = %d",
            $item_id
        ) );
        $item_meta = ! empty( $item_meta_json ) ? json_decode( $item_meta_json, true ) : array();
        if ( ! is_array( $item_meta ) ) {
            $item_meta = array();
        }
        $item_current_revision = isset( $item_meta['rfq_revision_current'] ) ? intval( $item_meta['rfq_revision_current'] ) : 1;
        
        // Check if current revision draft already exists
        $bids_columns = $wpdb->get_col( "DESCRIBE {$item_bids_table}" );
        $has_revision_column = in_array( 'rfq_revision_at_submit', $bids_columns, true );
        
        $existing_current_draft = null;
        if ( $has_revision_column ) {
            $existing_current_draft = $wpdb->get_row( $wpdb->prepare(
                "SELECT bid_id FROM {$item_bids_table} 
                WHERE item_id = %d 
                AND supplier_id = %d
                AND rfq_revision_at_submit = %d
                AND status = 'draft'
                LIMIT 1",
                $item_id,
                $current_user->ID,
                $item_current_revision
            ) );
        }
        
        if ( $existing_current_draft ) {
            wp_send_json_success( array(
                'message' => 'Draft already exists for current revision.',
                'bid_id' => intval( $existing_current_draft->bid_id ),
            ) );
            return;
        }
        
        // Get latest stale bid (oldest revision, most recent created_at)
        $stale_bid_query = "SELECT bid_id, unit_price, production_lead_time_text, prototype_video_yes, prototype_timeline_option, prototype_cost";
        $bids_columns_check = $wpdb->get_col( "DESCRIBE {$item_bids_table}" );
        $has_bid_meta_json = in_array( 'meta_json', $bids_columns_check, true );
        if ( $has_bid_meta_json ) {
            $stale_bid_query .= ", meta_json";
        }
        if ( $has_revision_column ) {
            $stale_bid_query .= ", rfq_revision_at_submit";
        }
        $stale_bid_query .= " FROM {$item_bids_table} 
            WHERE item_id = %d 
            AND supplier_id = %d
            AND status IN ('submitted', 'draft')";
        
        if ( $has_revision_column ) {
            $stale_bid_query .= " AND (rfq_revision_at_submit IS NULL OR rfq_revision_at_submit < %d)";
            $stale_bid = $wpdb->get_row( $wpdb->prepare(
                $stale_bid_query . " ORDER BY created_at DESC, bid_id DESC LIMIT 1",
                $item_id,
                $current_user->ID,
                $item_current_revision
            ), ARRAY_A );
        } else {
            $stale_bid = $wpdb->get_row( $wpdb->prepare(
                $stale_bid_query . " ORDER BY created_at DESC, bid_id DESC LIMIT 1",
                $item_id,
                $current_user->ID
            ), ARRAY_A );
        }
        
        if ( ! $stale_bid ) {
            wp_send_json_error( array( 'message' => 'No stale bid found to copy from.' ) );
            return;
        }
        
        $stale_bid_id = intval( $stale_bid['bid_id'] );
        
        // Start transaction
        $wpdb->query( 'START TRANSACTION' );
        
        try {
            // Create new draft bid with current revision
            $new_draft_data = array(
                'item_id' => $item_id,
                'supplier_id' => $current_user->ID,
                'is_anonymous' => 1,
                'unit_price' => $stale_bid['unit_price'] ? floatval( $stale_bid['unit_price'] ) : null,
                'production_lead_time_text' => $stale_bid['production_lead_time_text'] ? sanitize_text_field( $stale_bid['production_lead_time_text'] ) : null,
                'prototype_video_yes' => intval( $stale_bid['prototype_video_yes'] ) === 1 ? 1 : 0,
                'prototype_timeline_option' => $stale_bid['prototype_timeline_option'] ? sanitize_text_field( $stale_bid['prototype_timeline_option'] ) : null,
                'prototype_cost' => $stale_bid['prototype_cost'] ? floatval( $stale_bid['prototype_cost'] ) : null,
                'cad_yes' => null,
                'status' => 'draft',
            );
            
            // Add meta_json if available
            if ( $has_bid_meta_json && ! empty( $stale_bid['meta_json'] ) ) {
                $new_draft_data['meta_json'] = $stale_bid['meta_json'];
            }
            
            // Add revision
            if ( $has_revision_column ) {
                $new_draft_data['rfq_revision_at_submit'] = $item_current_revision;
            }
            
            // Prepare format array
            $format_array = array( '%d', '%d', '%d', '%f', '%s', '%d', '%s', '%f', '%s', '%s' );
            if ( $has_bid_meta_json && isset( $new_draft_data['meta_json'] ) ) {
                $format_array[] = '%s';
            }
            if ( $has_revision_column ) {
                $format_array[] = '%d';
            }
            
            // Insert new draft
            $inserted = $wpdb->insert(
                $item_bids_table,
                $new_draft_data,
                $format_array
            );
            
            if ( ! $inserted ) {
                throw new Exception( 'Failed to create draft bid.' );
            }
            
            $new_draft_bid_id = $wpdb->insert_id;
            
            // Copy video links from stale bid
            $stale_video_links = $wpdb->get_results( $wpdb->prepare(
                "SELECT url, provider, sort_order FROM {$bid_media_links_table}
                WHERE bid_id = %d
                ORDER BY sort_order ASC, id ASC",
                $stale_bid_id
            ), ARRAY_A );
            
            foreach ( $stale_video_links as $link ) {
                $wpdb->insert(
                    $bid_media_links_table,
                    array(
                        'bid_id' => $new_draft_bid_id,
                        'url' => $link['url'],
                        'provider' => $link['provider'],
                        'sort_order' => $link['sort_order'],
                    ),
                    array( '%d', '%s', '%s', '%d' )
                );
            }
            
            // Copy bid photos from stale bid
            $stale_photos = $wpdb->get_results( $wpdb->prepare(
                "SELECT file_url, sort_order FROM {$bid_media_files_table}
                WHERE bid_id = %d
                ORDER BY sort_order ASC, id ASC",
                $stale_bid_id
            ), ARRAY_A );
            
            foreach ( $stale_photos as $photo ) {
                $wpdb->insert(
                    $bid_media_files_table,
                    array(
                        'bid_id' => $new_draft_bid_id,
                        'file_url' => $photo['file_url'],
                        'sort_order' => $photo['sort_order'],
                    ),
                    array( '%d', '%s', '%d' )
                );
            }
            
            $wpdb->query( 'COMMIT' );
            
            wp_send_json_success( array(
                'message' => 'New draft created successfully. Please update your bid to match the new specs.',
                'bid_id' => $new_draft_bid_id,
                'revision' => $item_current_revision,
            ) );
            
        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array(
                'message' => 'Failed to create draft: ' . $e->getMessage(),
            ) );
        }
    }
}

// .....