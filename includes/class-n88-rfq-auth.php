<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Authentication and User Management
 * Handles signup, login, and designer role management
 */
class N88_RFQ_Auth {

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
     * Render supplier queue page (Commit 2.2.3)
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

        // Read filter values from URL query parameters
        // These values are restored on page refresh, ensuring filter state persists across page reloads
        $status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'all';
        $category_id = isset( $_GET['category_id'] ) ? sanitize_text_field( wp_unslash( $_GET['category_id'] ) ) : 'all';
        $search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

        ob_start();
        ?>
        <div class="n88-supplier-queue" style="max-width: 1400px; margin: 0 auto; padding: 40px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;">
            <!-- Header -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0;">
                <h1 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;">Maker Queue</h1>
                <div style="display: flex; align-items: center; gap: 16px; font-size: 14px; color: #666;">
                    <span>Logged in as: <strong><?php echo esc_html( $current_user->display_name ); ?></strong></span>
                    <a href="<?php echo esc_url( wp_logout_url( home_url( '/login/' ) ) ); ?>" style="padding: 6px 12px; background-color: #dc3545; color: #fff; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: 500; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#c82333';" onmouseout="this.style.backgroundColor='#dc3545';">Logout</a>
                </div>
            </div>
            
            <!-- Filters Section -->
            <div style="margin-bottom: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 4px;">
                <div style="font-size: 14px; font-weight: 600; margin-bottom: 15px; color: #333;">Filters:</div>
                <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size: 14px; color: #666;">Status</label>
                        <select id="n88-supplier-status" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff; cursor: pointer; min-width: 120px;">
                            <option value="all" <?php selected( $status, 'all' ); ?>>All</option>
                            <option value="pending" <?php selected( $status, 'pending' ); ?>>Pending</option>
                            <option value="awaiting_bid" <?php selected( $status, 'awaiting_bid' ); ?>>Awaiting Bid</option>
                            <option value="bid_submitted" <?php selected( $status, 'bid_submitted' ); ?>>Bid Submitted</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size: 14px; color: #666;">Category</label>
                        <select id="n88-supplier-category" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff; cursor: pointer; min-width: 120px;">
                            <option value="all" <?php selected( $category_id, 'all' ); ?>>All</option>
                            <option value="upholstery" <?php selected( $category_id, 'upholstery' ); ?>>Upholstery</option>
                            <option value="casegoods" <?php selected( $category_id, 'casegoods' ); ?>>Casegoods</option>
                            <option value="lighting" <?php selected( $category_id, 'lighting' ); ?>>Lighting</option>
                            <option value="accessories" <?php selected( $category_id, 'accessories' ); ?>>Accessories</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; flex: 1; min-width: 200px;">
                        <label style="font-size: 14px; color: #666;">Search</label>
                        <input type="text" id="n88-supplier-search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search items..." style="flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                    </div>
                </div>
            </div>
            
            <!-- Routed Items Section -->
            <div style="margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;">Routed Items (Anonymous RFQs)</h2>
            </div>
            
            <!-- Items Table -->
            <div style="background-color: #fff; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #f5f5f5; border-bottom: 2px solid #e0e0e0;">
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Item</th>
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Item Title</th>
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Category</th>
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Request Type</th>
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // DEMO ITEM FOR TESTING (Commit 2.3.2/2.3.3) - Remove when routing is implemented
                        $demo_item_id = 9999;
                        $demo_item = array(
                            'id' => $demo_item_id,
                            'title' => 'Demo Curved Sofa',
                            'category' => 'Upholstery',
                            'request_type' => 'Pricing Req',
                            'route_type' => 'designer_invited'
                        );
                        ?>
                        <tr style="border-bottom: 1px solid #e0e0e0; background-color: #fff9e6;">
                            <td style="padding: 15px; font-size: 14px; color: #ff6600; font-weight: 500;">#<?php echo esc_html( $demo_item_id ); ?> <span style="font-size: 11px; color: #999;">(Demo)</span></td>
                            <td style="padding: 15px; font-size: 14px; color: #333;"><?php echo esc_html( $demo_item['title'] ); ?></td>
                            <td style="padding: 15px; font-size: 14px; color: #666;"><?php echo esc_html( $demo_item['category'] ); ?></td>
                            <td style="padding: 15px; font-size: 14px; color: #666;"><?php echo esc_html( $demo_item['request_type'] ); ?></td>
                            <td style="padding: 15px;">
                                <button class="n88-open-bid-modal" data-item-id="<?php echo esc_attr( $demo_item_id ); ?>" data-item-title="<?php echo esc_attr( $demo_item['title'] ); ?>" data-category="<?php echo esc_attr( $demo_item['category'] ); ?>" data-request-type="<?php echo esc_attr( $demo_item['request_type'] ); ?>" style="padding: 6px 12px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                                    Open ►
                                </button>
                            </td>
                        </tr>
                        <?php
                        // Fetch real routed items from database (Commit 2.3.2)
                        global $wpdb;
                        $rfq_routes_table = $wpdb->prefix . 'n88_rfq_routes';
                        $items_table = $wpdb->prefix . 'n88_items';
                        $categories_table = $wpdb->prefix . 'n88_categories';
                        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
                        
                        // Get items routed to this supplier
                        $routed_items = $wpdb->get_results( $wpdb->prepare(
                            "SELECT DISTINCT
                                r.item_id,
                                r.status as route_status,
                                r.route_type,
                                i.id,
                                i.title,
                                i.item_type,
                                i.status as item_status,
                                c.name as category_name,
                                b.status as bid_status
                            FROM {$rfq_routes_table} r
                            INNER JOIN {$items_table} i ON r.item_id = i.id
                            LEFT JOIN {$categories_table} c ON i.item_type = c.category_id OR i.item_type = c.name
                            LEFT JOIN {$item_bids_table} b ON r.item_id = b.item_id AND r.supplier_id = b.supplier_id
                            WHERE r.supplier_id = %d
                            AND r.status IN ('queued', 'sent', 'viewed', 'bid_submitted')
                            AND i.deleted_at IS NULL
                            ORDER BY r.route_id DESC",
                            $current_user->ID
                        ), ARRAY_A );
                        
                        // Display real routed items
                        if ( ! empty( $routed_items ) ) {
                            foreach ( $routed_items as $item ) {
                                $item_id = intval( $item['id'] );
                                // Skip demo item if it exists in real data
                                if ( $item_id === $demo_item_id ) {
                                    continue;
                                }
                                $item_title = esc_html( $item['title'] ?: 'Untitled Item' );
                                $category = esc_html( $item['category_name'] ?: $item['item_type'] ?: 'Uncategorized' );
                                
                                // Determine request type based on bid status
                                $request_type = 'Awaiting Bid';
                                if ( ! empty( $item['bid_status'] ) ) {
                                    if ( $item['bid_status'] === 'submitted' ) {
                                        $request_type = 'Bid Submitted';
                                    } elseif ( $item['bid_status'] === 'awarded' ) {
                                        $request_type = 'Awarded';
                                    } elseif ( $item['bid_status'] === 'declined' ) {
                                        $request_type = 'Declined';
                                    }
                                } elseif ( $item['route_status'] === 'queued' ) {
                                    $request_type = 'Queued';
                                } elseif ( $item['route_status'] === 'sent' ) {
                                    $request_type = 'Pricing Req';
                                }
                                
                                $button_text = ( ! empty( $item['bid_status'] ) && $item['bid_status'] === 'submitted' ) ? 'View ►' : 'Open ►';
                                ?>
                                <tr style="border-bottom: 1px solid #e0e0e0;">
                                    <td style="padding: 15px; font-size: 14px; color: #ff6600; font-weight: 500;">#<?php echo esc_html( $item_id ); ?></td>
                                    <td style="padding: 15px; font-size: 14px; color: #333;"><?php echo $item_title; ?></td>
                                    <td style="padding: 15px; font-size: 14px; color: #666;"><?php echo $category; ?></td>
                                    <td style="padding: 15px; font-size: 14px; color: #666;"><?php echo esc_html( $request_type ); ?></td>
                                    <td style="padding: 15px;">
                                        <button class="n88-open-bid-modal" data-item-id="<?php echo esc_attr( $item_id ); ?>" data-item-title="<?php echo esc_attr( $item_title ); ?>" data-category="<?php echo esc_attr( $category ); ?>" data-request-type="<?php echo esc_attr( $request_type ); ?>" style="padding: 6px 12px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                                            <?php echo esc_html( $button_text ); ?>
                                        </button>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Rules Note -->
            <div style="margin-top: 30px; padding: 15px; background-color: #f0f0f0; border-left: 4px solid #0073aa; border-radius: 4px;">
                <p style="margin: 0; font-size: 13px; color: #666; font-style: italic;">
                    <strong>Rules:</strong> Maker sees ONLY items routed to them. No creator identity shown.
                </p>
            </div>
        </div>
        
        <!-- Supplier RFQ Detail Modal (Commit 2.3.2) -->
        <div id="n88-supplier-bid-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 10000; overflow: hidden;">
            <div id="n88-supplier-bid-modal-content" style="position: fixed; top: 0; right: 0; width: 480px; max-width: 90vw; height: 100vh; background-color: #fff; box-shadow: -2px 0 10px rgba(0,0,0,0.2); z-index: 10001; display: flex; flex-direction: column; overflow: hidden;">
                <!-- Modal content will be populated by JavaScript -->
            </div>
        </div>
        
        <!-- Supplier Bid Form Modal (Commit 2.3.3) -->
        <div id="n88-supplier-bid-form-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 10002; overflow: hidden;">
            <div id="n88-supplier-bid-form-modal-content" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 600px; max-width: 90vw; max-height: 90vh; background-color: #fff; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 10003; display: flex; flex-direction: column; overflow: hidden; border-radius: 8px;">
                <!-- Modal content will be populated by JavaScript -->
            </div>
        </div>
        
        <script>
        (function() {
            // Filter persistence via URL query parameters
            // When filters change, update the URL. On page refresh, PHP reads these params and restores filter values
            var searchTimeout;
            function updateSupplierQueueURL() {
                var status = document.getElementById('n88-supplier-status')?.value || 'all';
                var category = document.getElementById('n88-supplier-category')?.value || 'all';
                var search = document.getElementById('n88-supplier-search')?.value || '';
                
                var params = new URLSearchParams(window.location.search);
                
                if (status && status !== 'all') {
                    params.set('status', status);
                } else {
                    params.delete('status');
                }
                
                if (category && category !== 'all') {
                    params.set('category_id', category);
                } else {
                    params.delete('category_id');
                }
                
                if (search && search.trim()) {
                    params.set('search', search.trim());
                } else {
                    params.delete('search');
                }
                
                var newURL = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                window.history.replaceState({}, '', newURL);
            }
            
            // Attach event listeners to filter elements
            document.addEventListener('DOMContentLoaded', function() {
                var statusSelect = document.getElementById('n88-supplier-status');
                var categorySelect = document.getElementById('n88-supplier-category');
                var searchInput = document.getElementById('n88-supplier-search');
                
                if (statusSelect) {
                    statusSelect.addEventListener('change', updateSupplierQueueURL);
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
                    
                    // Format dimensions (support both w/d/h and width/depth/height formats)
                    var dimsText = '—';
                    if (item.dimensions) {
                        // Support both formats: {w, d, h, unit} and {width, depth, height, unit}
                        var w = item.dimensions.width || item.dimensions.w || '—';
                        var d = item.dimensions.depth || item.dimensions.d || '—';
                        var h = item.dimensions.height || item.dimensions.h || '—';
                        var unit = item.dimensions.unit || '';
                        if (w !== '—' && d !== '—' && h !== '—') {
                            dimsText = 'W: ' + w + ' ' + unit + ' × D: ' + d + ' ' + unit + ' × H: ' + h + ' ' + unit;
                        }
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
                                refImagesHTML += '<div style="position: relative;">' +
                                    '<img id="' + imgId + '" ' +
                                    'src="' + imgUrl.replace(/"/g, '&quot;') + '" ' +
                                    'data-full-url="' + (fullUrl || imgUrl).replace(/"/g, '&quot;') + '" ' +
                                    'onerror="this.onerror=null; this.src=\'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\'%3E%3Crect fill=\'%23f0f0f0\' width=\'100\' height=\'100\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\' font-size=\'12\'%3EImage%3C/text%3E%3C/svg%3E\';" ' +
                                    'style="width: 100px; height: 100px; object-fit: cover; border-radius: 4px; border: 2px solid #e0e0e0; cursor: pointer; transition: all 0.2s; background-color: #f0f0f0;" ' +
                                    'onmouseover="this.style.borderColor=\'#0073aa\'; this.style.transform=\'scale(1.05)\';" ' +
                                    'onmouseout="this.style.borderColor=\'#e0e0e0\'; this.style.transform=\'scale(1)\';" ' +
                                    'onclick="(function(e){var url=e.getAttribute(\'data-full-url\');if(url&&url.trim()){window.open(url,\'_blank\',\'noopener,noreferrer\');}})(this);" ' +
                                    'title="Click to view full size" ' +
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
                    
                    // Build modal HTML - Read-only Supplier RFQ Detail View (Commit 2.3.2)
                    var modalHTML = '<div style="padding: 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; background-color: #fff;">' +
                        '<h2 style="margin: 0; font-size: 20px; font-weight: 600; color: #333;">Item #' + item.item_id + ' <span style="color: #666; font-weight: 400;">(Maker View)</span></h2>' +
                        '<button onclick="closeBidModal()" style="background: none; border: none; font-size: 28px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; color: #666; line-height: 1;">×</button>' +
                        '</div>' +
                        '<div style="flex: 1; overflow-y: auto; padding: 0; background-color: #fff;">' +
                        '<div style="padding: 20px;">' +
                        
                        // Item Image - reduced size
                        (item.image_url || item.primary_image_url ? '<div style="margin-bottom: 16px; text-align: center;">' +
                            '<img src="' + (item.primary_image_url || item.image_url) + '" style="max-width: 100%; max-height: 180px; width: auto; height: auto; border-radius: 4px; border: 1px solid #e0e0e0; object-fit: contain;" />' +
                            '</div>' : '') +
                        
                        // Item Title
                        '<div style="margin-bottom: 16px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Item Title:</label>' +
                        '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + (item.title || '—') + '</div>' +
                        '</div>' +
                        
                        // Category
                        '<div style="margin-bottom: 16px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Category:</label>' +
                        '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + (item.category || '—') + '</div>' +
                        '</div>' +
                        
                        // Routing Context (if available)
                        (item.route_label ? '<div style="margin-bottom: 16px;">' +
                            '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Routing:</label>' +
                            '<div style="padding: 10px 12px; background-color: #e3f2fd; border-radius: 4px; font-size: 14px; border: 1px solid #90caf9; color: #1976d2;">' + item.route_label + '</div>' +
                            '<div style="margin-top: 6px; font-size: 12px; color: #666; font-style: italic;">Creator identity remains hidden until award.</div>' +
                            '</div>' : '') +
                        
                        // Delivery Context
                        '<div style="margin-bottom: 16px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Delivery:</label>' +
                        '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' +
                            (item.delivery_country ? 'Country: ' + item.delivery_country + '<br>' : '') +
                            (item.delivery_postal_code ? 'Postal Code: ' + item.delivery_postal_code + '<br>' : '') +
                            '<strong>Shipping:</strong> ' + item.shipping_mode_label +
                        '</div>' +
                        '</div>' +
                        
                        // Specs
                        '<div style="margin-bottom: 24px;">' +
                        '<h3 style="font-size: 14px; font-weight: 600; margin-bottom: 16px; color: #333; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px;">Specifications</h3>' +
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Quantity:</label>' +
                        '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + (item.quantity || '—') + '</div>' +
                        '</div>' +
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Dimensions:</label>' +
                        '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + dimsText + '</div>' +
                        '</div>' +
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Sourcing Type:</label>' +
                        '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + sourcingTypeText + '</div>' +
                        '</div>' +
                        '<div style="margin-bottom: 12px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Timeline Type:</label>' +
                        '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + timelineTypeText + '</div>' +
                        '</div>' +
                        '</div>' +
                        
                        // Notes/Description
                        '<div style="margin-bottom: 24px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Notes/Description:</label>' +
                        '<div style="padding: 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; min-height: 100px; color: #333; white-space: pre-wrap;">' + (item.description || '—') + '</div>' +
                        '</div>' +
                        
                        // Smart Alternatives (Optional)
                        '<div style="margin-bottom: 24px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Smart Alternatives (Optional):</label>' +
                        '<div style="padding: 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' +
                            '<div style="margin-bottom: 8px;">' +
                                '<strong>Status:</strong> ' + (item.smart_alternatives_enabled ? '<span style="color: #2e7d32;">Enabled</span>' : '<span style="color: #666;">Disabled</span>') +
                            '</div>' +
                            (item.smart_alternatives_note && item.smart_alternatives_note.trim() ? 
                                '<div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #e0e0e0;">' +
                                    '<strong>Note:</strong> ' +
                                    '<div style="margin-top: 4px; color: #555; white-space: pre-wrap;">' + (item.smart_alternatives_note || '—') + '</div>' +
                                '</div>' : 
                                '<div style="margin-top: 8px; color: #999; font-style: italic;">No note provided</div>'
                            ) +
                        '</div>' +
                        '</div>' +
                        
                        // Reference Images
                        '<div style="margin-bottom: 24px;">' +
                        '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Reference Images:</label>' +
                        refImagesHTML +
                        '</div>' +
                        
                        // // Media Links
                        // '<div style="margin-bottom: 24px;">' +
                        // '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Media Links:</label>' +
                        // mediaLinksHTML +
                        // '</div>' +
                        
                        '</div>' +
                        // Footer - Start Bid / Withdraw Bid button (Commit 2.3.3/2.3.5)
                        '<div style="padding: 20px; border-top: 1px solid #e0e0e0; background-color: #fff; display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">' +
                        (item.bid_status === 'submitted' ? 
                            '<div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">' +
                            '<div style="padding: 12px 24px; background-color: #e8f5e9; color: #2e7d32; border: 1px solid #4caf50; border-radius: 4px; font-size: 14px; font-weight: 600;">✓ Bid Already Submitted</div>' +
                            '<button onclick="withdrawBid(' + item.item_id + ')" style="padding: 12px 24px; background-color: #dc3545; color: #fff; border: none; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background-color 0.2s;">Withdraw Bid</button>' +
                            '</div>' :
                            '<button onclick="openBidFormModal(' + item.item_id + ')" style="padding: 12px 24px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background-color 0.2s;">Start Bid</button>'
                        ) +
                        '</div>';
                    
                    modalContent.innerHTML = modalHTML;
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
                    if (data.success && data.data.bid_status === 'submitted') {
                        alert('You\'ve already submitted a bid for this item.');
                        return;
                    }
                    // Continue with opening modal
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
                                    'style="width: 100px; height: 100px; object-fit: cover; border-radius: 4px; border: 2px solid #ddd; cursor: pointer; transition: all 0.2s; background-color: #000;" ' +
                                    'onmouseover="this.style.borderColor=\'#0073aa\'; this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 2px 8px rgba(0,115,170,0.3)\';" ' +
                                    'onmouseout="this.style.borderColor=\'#ddd\'; this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\';" ' +
                                    'onclick="(function(elem){var url=elem.getAttribute(\'data-full-url\');if(url&&url.trim()){try{window.open(url,\'_blank\',\'noopener,noreferrer\');}catch(err){console.error(\'Error opening image:\',err);}}else{console.error(\'No URL found for image\');}})(this);" ' +
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
                                'style="max-width: 100%; max-height: 350px; width: auto; height: auto; border-radius: 4px; border: 1px solid #e0e0e0; object-fit: contain; box-shadow: 0 2px 8px rgba(0,0,0,0.1);" ' +
                                'alt="Item main image" />';
                        } else {
                            centerColumnHTML += '<div style="width: 100%; height: 300px; background-color: #f0f0f0; border-radius: 4px; border: 1px solid #e0e0e0; display: flex; align-items: center; justify-content: center; color: #999;">No main image available</div>';
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
                                    'style="width: 100px; height: 100px; object-fit: cover; border-radius: 4px; border: 2px solid #ddd; cursor: pointer; transition: all 0.2s; background-color: #000;" ' +
                                    'onmouseover="this.style.borderColor=\'#0073aa\'; this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 2px 8px rgba(0,115,170,0.3)\';" ' +
                                    'onmouseout="this.style.borderColor=\'#ddd\'; this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\';" ' +
                                    'onclick="(function(elem){var url=elem.getAttribute(\'data-full-url\');if(url&&url.trim()){try{window.open(url,\'_blank\',\'noopener,noreferrer\');}catch(err){console.error(\'Error opening image:\',err);}}else{console.error(\'No URL found for image\');}})(this);" ' +
                                    'title="Click to view full size" ' +
                                    'alt="Reference photo" />' +
                                    '</div>';
                            });
                        } else {
                            // Placeholder if no right images
                            // rightColumnHTML += '<div style="width: 100px; height: 100px; background-color: #000; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; text-align: center; padding: 4px;">reference photo</div>';
                        }
                        rightColumnHTML += '</div>';
                        
                        // Combine into gallery layout
                        imageGalleryHTML = '<div style="margin-bottom: 24px; display: flex; gap: 16px; align-items: flex-start; justify-content: center; padding: 16px; background-color: #fafafa; border-radius: 4px; border: 1px solid #e0e0e0;">' +
                            leftColumnHTML +
                            centerColumnHTML +
                            rightColumnHTML +
                            '</div>';
                    }
                    
                    // Build bid form modal HTML
                    var modalHTML = '<div style="padding: 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; background-color: #fff;">' +
                        '<h2 style="margin: 0; font-size: 20px; font-weight: 600; color: #333;">Submit Bid - Item #' + itemId + '</h2>' +
                        '<button onclick="closeBidFormModal()" style="background: none; border: none; font-size: 28px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; color: #666; line-height: 1;">×</button>' +
                        '</div>' +
                        '<div style="flex: 1; overflow-y: auto; padding: 0; background-color: #fff;">' +
                        '<form id="n88-bid-form" style="padding: 20px;" onsubmit="return validateAndSubmitBid(event);">' +
                        
                        // Image gallery: left reference images, center main image, right reference images
                        imageGalleryHTML +
                        
                        // Smart Alternatives (Optional) - Read-only display
                        '<div style="margin-bottom: 24px; padding: 16px; background-color: #f9f9f9; border-radius: 4px; border: 1px solid #e0e0e0;">' +
                        '<label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #333;">Smart Alternatives (Optional)</label>' +
                        '<div style="font-size: 12px; color: #666; margin-bottom: 8px;">The creator\'s preference for alternative materials:</div>' +
                        '<div style="padding: 12px; background-color: #fff; border-radius: 4px; border: 1px solid #e0e0e0;">' +
                            '<div style="margin-bottom: 8px;">' +
                                '<strong>Status:</strong> ' + (item.smart_alternatives_enabled ? '<span style="color: #2e7d32;">Enabled</span> - Creator is open to alternative materials' : '<span style="color: #666;">Disabled</span> - Creator wants the specified material only') +
                            '</div>' +
                            (item.smart_alternatives_note && item.smart_alternatives_note.trim() ? 
                                '<div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #e0e0e0;">' +
                                    '<strong>Creator\'s Note:</strong> ' +
                                    '<div style="margin-top: 4px; color: #555; white-space: pre-wrap; font-size: 13px;">' + (item.smart_alternatives_note || '—') + '</div>' +
                                '</div>' : 
                                '<div style="margin-top: 8px; color: #999; font-style: italic; font-size: 12px;">No additional note provided</div>'
                            ) +
                        '</div>' +
                        '</div>' +
                        
                    
                    // 1. Video links (min 1, max 3)
                    '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #333;">Video Links <span style="color: #d32f2f;">*</span></label>' +
                    '<div style="font-size: 11px; color: #888; margin-bottom: 4px; font-style: italic;">Add video of similar item so creator can see your capability</div>' +
                    '<div style="font-size: 12px; color: #666; margin-bottom: 8px;">Paste up to 3 links (YouTube, Vimeo, or Loom). At least 1 is required.</div>' +
                    '<div id="n88-video-links-container">' +
                    '<div style="margin-bottom: 8px; display: flex; gap: 8px;">' +
                    '<input type="url" name="video_links[]" class="n88-video-link-input" placeholder="https://youtube.com/watch?v=..." style="flex: 1; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" onblur="validateVideoLink(this);" oninput="validateBidForm();" />' +
                    '<button type="button" onclick="removeVideoLink(this)" style="padding: 10px 16px; background-color: #dc3545; color: #fff; border: none; border-radius: 4px; cursor: pointer; display: none;">Remove</button>' +
                    '</div>' +
                    '</div>' +
                    '<button type="button" onclick="addVideoLink()" id="n88-add-video-link-btn" style="margin-top: 8px; padding: 8px 16px; background-color: #f0f0f0; color: #333; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-size: 13px;">+ Add Another Link</button>' +
                    '<div id="n88-video-links-error" style="margin-top: 6px; font-size: 12px; color: #d32f2f; display: none;"></div>' +
                    '</div>' +
                    
                    // 2. Prototype video commitment (must be YES)
                    '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #333;">Will you prepare and video a prototype? <span style="color: #d32f2f;">*</span></label>' +
                    '<div style="font-size: 12px; color: #856404; margin-bottom: 8px; padding: 8px; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">Prototype video is required to submit a bid.</div>' +
                    '<div style="display: flex; gap: 16px;">' +
                    '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">' +
                    '<input type="radio" name="prototype_video_yes" value="1" required style="width: 18px; height: 18px; cursor: pointer;" onchange="validateBidForm();" />' +
                    '<span style="font-size: 14px;">Yes</span>' +
                    '</label>' +
                    '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">' +
                    '<input type="radio" name="prototype_video_yes" value="0" style="width: 18px; height: 18px; cursor: pointer;" onchange="validateBidForm();" />' +
                    '<span style="font-size: 14px;">No</span>' +
                    '</label>' +
                    '</div>' +
                    '<div id="n88-prototype-video-error" style="margin-top: 6px; font-size: 12px; color: #d32f2f; display: none;"></div>' +
                    '</div>' +
                    
                    // 3. Prototype timeline
                    '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #333;">Prototype Timeline <span style="color: #d32f2f;">*</span></label>' +
                    '<select name="prototype_timeline_option" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff; cursor: pointer;" onchange="validateBidForm();">' +
                    '<option value="">Select timeline...</option>' +
                    '<option value="1-2w">1–2w</option>' +
                    '<option value="2-4w">2–4w</option>' +
                    '<option value="4-6w">4–6w</option>' +
                    '<option value="6-8w">6–8w</option>' +
                    '<option value="8-10w">8–10w</option>' +
                    '</select>' +
                    '<div id="n88-prototype-timeline-error" style="margin-top: 6px; font-size: 12px; color: #d32f2f; display: none;"></div>' +
                    '</div>' +
                    
                    // 4. Prototype cost
                    '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #333;">Prototype Cost ($) <span style="color: #d32f2f;">*</span></label>' +
                    '<input type="number" name="prototype_cost" step="0.01" min="0" required placeholder="0.00" style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" oninput="validateBidForm();" />' +
                    '<div id="n88-prototype-cost-error" style="margin-top: 6px; font-size: 12px; color: #d32f2f; display: none;"></div>' +
                    '</div>' +
                    
                    // 5. Production lead time
                    '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #333;">Production Lead Time <span style="color: #d32f2f;">*</span></label>' +
                    '<select name="production_lead_time_text" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff;" onchange="validateBidForm();">' +
                    '<option value="">Select lead time</option>' +
                    '<option value="2-4 weeks">2-4 weeks</option>' +
                    '<option value="4-6 weeks">4-6 weeks</option>' +
                    '<option value="6-8 weeks">6-8 weeks</option>' +
                    '<option value="8-12 weeks">8-12 weeks</option>' +
                    '<option value="12-16 weeks">12-16 weeks</option>' +
                    '</select>' +
                    '<div id="n88-lead-time-error" style="margin-top: 6px; font-size: 12px; color: #d32f2f; display: none;"></div>' +
                    '</div>' +
                    
                    // 6. Unit price
                    '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #333;">Unit Price ($) <span style="color: #d32f2f;">*</span></label>' +
                    '<input type="number" name="unit_price" step="0.01" min="0.01" required placeholder="0.00" style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" oninput="validateBidForm();" />' +
                    '<div id="n88-unit-price-error" style="margin-top: 6px; font-size: 12px; color: #d32f2f; display: none;"></div>' +
                    '</div>' +
                    
                    '</form>' +
                    '</div>' +
                    // Footer with submit button
                    '<div style="padding: 20px; border-top: 1px solid #e0e0e0; background-color: #fff; display: flex; justify-content: flex-end; gap: 12px;">' +
                    '<button type="button" onclick="closeBidFormModal()" style="padding: 10px 20px; background-color: #f0f0f0; color: #333; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; cursor: pointer;">Cancel</button>' +
                    '<button type="button" id="n88-validate-bid-btn" onclick="validateAndSubmitBid(event)" disabled style="padding: 10px 20px; background-color: #ccc; color: #666; border: none; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: not-allowed;">Validate Bid</button>' +
                    '<button type="button" id="n88-submit-bid-btn" onclick="submitBid(event)" disabled style="display: none; padding: 10px 20px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer;">Submit Bid</button>' +
                    '</div>';
                    
                    modalContent.innerHTML = modalHTML;
                    
                    // Initial validation - use setTimeout to ensure DOM is ready
                    setTimeout(function() {
                        validateBidForm();
                    }, 100);
                    
                    // Reset button states (Commit 2.3.5)
                    setTimeout(function() {
                        var validateBtn = document.getElementById('n88-validate-bid-btn');
                        var submitBtn = document.getElementById('n88-submit-bid-btn');
                        if (validateBtn) {
                            validateBtn.style.display = 'inline-block';
                        }
                        if (submitBtn) {
                            submitBtn.style.display = 'none';
                            submitBtn.disabled = true;
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
            
            // Validate entire bid form (client-side)
            function validateBidForm() {
                var form = document.getElementById('n88-bid-form');
                if (!form) return false;
                
                var isValid = true;
                var submitBtn = document.getElementById('n88-validate-bid-btn');
                
                // 1. Video links: min 1, max 3, all valid
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
                
                if (validVideoLinks < 1) {
                    isValid = false;
                }
                
                // 2. Prototype video must be YES
                var prototypeYes = form.querySelector('input[name="prototype_video_yes"][value="1"]');
                if (!prototypeYes || !prototypeYes.checked) {
                    isValid = false;
                }
                
                // 3. Prototype timeline required
                var timeline = form.querySelector('select[name="prototype_timeline_option"]');
                if (!timeline || !timeline.value) {
                    isValid = false;
                }
                
                // 4. Prototype cost: numeric >= 0
                var prototypeCost = form.querySelector('input[name="prototype_cost"]');
                if (prototypeCost && prototypeCost.value) {
                    var costValue = parseFloat(prototypeCost.value);
                    if (isNaN(costValue) || costValue < 0) {
                        isValid = false;
                    }
                } else {
                    isValid = false;
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
                
                // Enable/disable submit button
                if (submitBtn) {
                    if (isValid) {
                        submitBtn.disabled = false;
                        submitBtn.style.backgroundColor = '#0073aa';
                        submitBtn.style.color = '#fff';
                        submitBtn.style.cursor = 'pointer';
                    } else {
                        submitBtn.disabled = true;
                        submitBtn.style.backgroundColor = '#ccc';
                        submitBtn.style.color = '#666';
                        submitBtn.style.cursor = 'not-allowed';
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
                
                // Other fields
                formData.append('prototype_video_yes', form.querySelector('input[name="prototype_video_yes"]:checked') ? form.querySelector('input[name="prototype_video_yes"]:checked').value : '');
                formData.append('prototype_timeline_option', form.querySelector('select[name="prototype_timeline_option"]').value);
                formData.append('prototype_cost', form.querySelector('input[name="prototype_cost"]').value);
                formData.append('production_lead_time_text', form.querySelector('select[name="production_lead_time_text"]').value);
                formData.append('unit_price', form.querySelector('input[name="unit_price"]').value);
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
                
                // Other fields
                formData.append('prototype_video_yes', form.querySelector('input[name="prototype_video_yes"]:checked') ? form.querySelector('input[name="prototype_video_yes"]:checked').value : '');
                formData.append('prototype_timeline_option', form.querySelector('select[name="prototype_timeline_option"]').value);
                formData.append('prototype_cost', form.querySelector('input[name="prototype_cost"]').value);
                formData.append('production_lead_time_text', form.querySelector('select[name="production_lead_time_text"]').value);
                formData.append('unit_price', form.querySelector('input[name="unit_price"]').value);
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
            
            // Make functions globally accessible
            window.validateAndSubmitBid = validateAndSubmitBid;
            window.submitBid = submitBid;
            
            // Attach event listeners
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.n88-open-bid-modal').forEach(function(button) {
                    button.addEventListener('click', function() {
                        var itemId = this.getAttribute('data-item-id');
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
                        // Refresh the item detail modal to show "Start Bid" button
                        openBidModal(itemId);
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

        // Extract dimensions - prioritize RFQ submission dimensions over item meta
        $dims = null;
        if ( $delivery_context && $has_dimensions && ! empty( $delivery_context['dimensions_json'] ) ) {
            // Use dimensions from RFQ submission (stored in delivery context)
            $decoded_dims = json_decode( $delivery_context['dimensions_json'], true );
            if ( is_array( $decoded_dims ) ) {
                // Normalize to w/d/h format for frontend compatibility (frontend expects w, d, h, unit)
                $dims = array(
                    'w' => isset( $decoded_dims['width'] ) ? floatval( $decoded_dims['width'] ) : ( isset( $decoded_dims['w'] ) ? floatval( $decoded_dims['w'] ) : null ),
                    'd' => isset( $decoded_dims['depth'] ) ? floatval( $decoded_dims['depth'] ) : ( isset( $decoded_dims['d'] ) ? floatval( $decoded_dims['d'] ) : null ),
                    'h' => isset( $decoded_dims['height'] ) ? floatval( $decoded_dims['height'] ) : ( isset( $decoded_dims['h'] ) ? floatval( $decoded_dims['h'] ) : null ),
                    'unit' => isset( $decoded_dims['unit'] ) ? sanitize_text_field( $decoded_dims['unit'] ) : '',
                );
                error_log( 'Supplier Detail View - Using dimensions from delivery context for item ' . $item_id . ': ' . wp_json_encode( $dims ) );
            } else {
                error_log( 'Supplier Detail View - Failed to decode dimensions_json for item ' . $item_id . ': ' . $delivery_context['dimensions_json'] );
            }
        } elseif ( isset( $meta['dims'] ) && is_array( $meta['dims'] ) ) {
            // Fallback to item meta
            $dims = $meta['dims'];
            error_log( 'Supplier Detail View - Using dimensions from item meta (dims) for item ' . $item_id );
        } elseif ( isset( $meta['dims_cm'] ) && is_array( $meta['dims_cm'] ) ) {
            // Fallback to item meta (cm)
            $dims = $meta['dims_cm'];
            error_log( 'Supplier Detail View - Using dimensions from item meta (dims_cm) for item ' . $item_id );
        } else {
            error_log( 'Supplier Detail View - No dimensions found for item ' . $item_id . ' (has_dimensions: ' . ( $has_dimensions ? 'true' : 'false' ) . ', delivery_context exists: ' . ( $delivery_context ? 'true' : 'false' ) . ')' );
        }

        // Get quantity - prioritize RFQ submission quantity over item meta
        $quantity = null;
        if ( $delivery_context && $has_quantity && ! empty( $delivery_context['quantity'] ) ) {
            // Use quantity from RFQ submission (stored in delivery context)
            $quantity = intval( $delivery_context['quantity'] );
        } elseif ( isset( $meta['quantity'] ) ) {
            // Fallback to item meta
            $quantity = intval( $meta['quantity'] );
        }

        // Get bid status (Commit 2.3.5 - check if bid already submitted)
        $item_bids_table = $wpdb->prefix . 'n88_item_bids';
        $bid_status = null;
        if ( ! $is_system_operator ) {
            $existing_bid = $wpdb->get_row( $wpdb->prepare(
                "SELECT status FROM {$item_bids_table} 
                WHERE item_id = %d AND supplier_id = %d",
                $item_id,
                $current_user->ID
            ) );
            if ( $existing_bid ) {
                $bid_status = $existing_bid->status;
            }
        }

        // Get Smart Alternatives data from meta
        $smart_alternatives_enabled = isset( $meta['smart_alternatives'] ) && $meta['smart_alternatives'] === true;
        $smart_alternatives_note = isset( $meta['smart_alternatives_note'] ) ? sanitize_textarea_field( $meta['smart_alternatives_note'] ) : '';

        // Build response (read-only, no writes)
        $response = array(
            'item_id' => intval( $item['id'] ),
            'title' => sanitize_text_field( $item['title'] ),
            'description' => sanitize_textarea_field( $item['description'] ),
            'category' => $category_name,
            'image_url' => $image_url ? esc_url_raw( $image_url ) : '', // Keep for backward compatibility
            'primary_image_url' => $primary_image_url ? esc_url_raw( $primary_image_url ) : '', // Standardized key
            'quantity' => $quantity,
            'dimensions' => $dims,
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
            'bid_status' => $bid_status, // Commit 2.3.5 - bid status to prevent duplicate submissions
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
        if ( ! empty( $item_meta ) ) {
            $meta = json_decode( $item_meta, true );
            if ( is_array( $meta ) ) {
                $smart_alternatives_enabled = isset( $meta['smart_alternatives'] ) && $meta['smart_alternatives'] === true;
                $smart_alternatives_note = isset( $meta['smart_alternatives_note'] ) ? sanitize_textarea_field( $meta['smart_alternatives_note'] ) : '';
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
            // Get all submitted bids with media links
            $bids_data = $wpdb->get_results( $wpdb->prepare(
                "SELECT 
                    b.bid_id,
                    b.unit_price,
                    b.production_lead_time_text,
                    b.prototype_timeline_option,
                    b.prototype_cost,
                    b.created_at
                FROM {$item_bids_table} b
                WHERE b.item_id = %d 
                AND b.status = 'submitted'
                ORDER BY b.created_at ASC",
                $item_id
            ), ARRAY_A );

            foreach ( $bids_data as $bid ) {
                // Get media links for this bid with provider information
                $media_links = $wpdb->get_results( $wpdb->prepare(
                    "SELECT url, provider 
                    FROM {$bid_media_links_table}
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

                $bids[] = array(
                    'bid_id' => intval( $bid['bid_id'] ),
                    'unit_price' => $bid['unit_price'] ? floatval( $bid['unit_price'] ) : null,
                    'production_lead_time' => $bid['production_lead_time_text'] ? sanitize_text_field( $bid['production_lead_time_text'] ) : null,
                    'prototype_timeline' => $bid['prototype_timeline_option'] ? sanitize_text_field( $bid['prototype_timeline_option'] ) : null,
                    'prototype_cost' => $bid['prototype_cost'] ? floatval( $bid['prototype_cost'] ) : null,
                    'video_links' => array_map( function( $link ) {
                        return esc_url_raw( $link['url'] );
                    }, $media_links ),
                    'video_links_by_provider' => $video_links_by_provider,
                    'smart_alternatives_enabled' => $smart_alternatives_enabled,
                    'smart_alternatives_note' => $smart_alternatives_note,
                    'created_at' => $bid['created_at'],
                );
            }
        }

        wp_send_json_success( array(
            'has_rfq' => $has_rfq,
            'has_bids' => $has_bids,
            'bids' => $bids,
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
        
        if ( count( $video_links ) < 1 ) {
            $errors['video_links'] = 'At least 1 video link is required.';
        } elseif ( count( $video_links ) > 3 ) {
            $errors['video_links'] = 'Maximum 3 video links allowed.';
        } else {
            // Validate each link against allowlist
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

        // 2. Prototype video commitment (must be YES)
        $prototype_video_yes = isset( $_POST['prototype_video_yes'] ) ? intval( $_POST['prototype_video_yes'] ) : 0;
        if ( $prototype_video_yes !== 1 ) {
            $errors['prototype_video_yes'] = 'Prototype video is required to submit a bid.';
        }

        // 3. Prototype timeline (required)
        $prototype_timeline_option = isset( $_POST['prototype_timeline_option'] ) ? sanitize_text_field( wp_unslash( $_POST['prototype_timeline_option'] ) ) : '';
        $allowed_timelines = array( '1-2w', '2-4w', '4-6w', '6-8w', '8-10w' );
        if ( empty( $prototype_timeline_option ) || ! in_array( $prototype_timeline_option, $allowed_timelines, true ) ) {
            $errors['prototype_timeline_option'] = 'Please select a valid prototype timeline.';
        }

        // 4. Prototype cost (numeric >= 0)
        $prototype_cost = isset( $_POST['prototype_cost'] ) ? sanitize_text_field( wp_unslash( $_POST['prototype_cost'] ) ) : '';
        if ( empty( $prototype_cost ) ) {
            $errors['prototype_cost'] = 'Prototype cost is required.';
        } else {
            $prototype_cost_float = floatval( $prototype_cost );
            if ( ! is_numeric( $prototype_cost ) || $prototype_cost_float < 0 ) {
                $errors['prototype_cost'] = 'Prototype cost must be a number greater than or equal to 0.';
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
        
        if ( count( $video_links ) < 1 ) {
            $errors['video_links'] = 'At least 1 video link is required.';
        } elseif ( count( $video_links ) > 3 ) {
            $errors['video_links'] = 'Maximum 3 video links allowed.';
        } else {
            // Validate each link against allowlist
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

        // 2. Prototype video commitment (must be YES)
        $prototype_video_yes = isset( $_POST['prototype_video_yes'] ) ? intval( $_POST['prototype_video_yes'] ) : 0;
        if ( $prototype_video_yes !== 1 ) {
            $errors['prototype_video_yes'] = 'Prototype video is required to submit a bid.';
        }

        // 3. Prototype timeline (required)
        $prototype_timeline_option = isset( $_POST['prototype_timeline_option'] ) ? sanitize_text_field( wp_unslash( $_POST['prototype_timeline_option'] ) ) : '';
        $allowed_timelines = array( '1-2w', '2-4w', '4-6w', '6-8w', '8-10w' );
        if ( empty( $prototype_timeline_option ) || ! in_array( $prototype_timeline_option, $allowed_timelines, true ) ) {
            $errors['prototype_timeline_option'] = 'Please select a valid prototype timeline.';
        }

        // 4. Prototype cost (numeric >= 0)
        $prototype_cost = isset( $_POST['prototype_cost'] ) ? sanitize_text_field( wp_unslash( $_POST['prototype_cost'] ) ) : '';
        if ( empty( $prototype_cost ) ) {
            $errors['prototype_cost'] = 'Prototype cost is required.';
        } else {
            $prototype_cost_float = floatval( $prototype_cost );
            if ( ! is_numeric( $prototype_cost ) || $prototype_cost_float < 0 ) {
                $errors['prototype_cost'] = 'Prototype cost must be a number greater than or equal to 0.';
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
            // Check for existing bid
            $existing_bid = $wpdb->get_row( $wpdb->prepare(
                "SELECT bid_id, status FROM {$item_bids_table} 
                WHERE item_id = %d AND supplier_id = %d",
                $item_id,
                $current_user->ID
            ) );

            // Block if bid already submitted
            if ( $existing_bid && $existing_bid->status === 'submitted' ) {
                $wpdb->query( 'ROLLBACK' );
                wp_send_json_error( array(
                    'message' => 'You\'ve already submitted a bid for this item.',
                ) );
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

            if ( $existing_bid && $existing_bid->status === 'withdrawn' ) {
                // UPDATE existing withdrawn bid
                $wpdb->update(
                    $item_bids_table,
                    $bid_data,
                    array( 'bid_id' => $existing_bid->bid_id ),
                    array( '%d', '%d', '%d', '%f', '%s', '%d', '%s', '%f', '%s', '%s' ),
                    array( '%d' )
                );
                $bid_id = $existing_bid->bid_id;
            } else {
                // INSERT new bid
                $wpdb->insert(
                    $item_bids_table,
                    $bid_data,
                    array( '%d', '%d', '%d', '%f', '%s', '%d', '%s', '%f', '%s', '%s' )
                );
                $bid_id = $wpdb->insert_id;
            }

            if ( ! $bid_id ) {
                throw new Exception( 'Failed to save bid.' );
            }

            // Replace media links (DELETE old, INSERT new)
            $wpdb->delete(
                $bid_media_links_table,
                array( 'bid_id' => $bid_id ),
                array( '%d' )
            );

            // Insert new media links
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

        wp_send_json_success( array(
            'message' => 'Bid withdrawn successfully. You can resubmit a new bid.',
        ) );
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
}

// .....