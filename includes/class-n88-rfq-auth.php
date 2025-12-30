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
            __( 'Designer', 'n88-rfq' ),
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
                                <span>Designer</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
                                <input type="radio" name="user_role" value="n88_supplier_admin" required style="margin: 0;">
                                <span>Supplier</span>
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
                <h2 class="n88-auth-title">Designer Login</h2>
                
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
                self::render_403_error( 'Access Denied', 'You do not have permission to access this page. Designers are restricted from accessing admin and supplier areas.' );
                exit;
            }
        }

        // Suppliers blocked from global queue scope on frontend
        if ( $is_supplier && ! $is_system_operator ) {
            if ( strpos( $path, '/admin/queue' ) !== false && strpos( $path, '/wp-admin' ) === false ) {
                $query_params = isset( $parsed_url['query'] ) ? $parsed_url['query'] : '';
                parse_str( $query_params, $query_vars );
                if ( isset( $query_vars['scope'] ) && $query_vars['scope'] === 'global' ) {
                    self::render_403_error( 'Access Denied', 'You do not have permission to access the global queue. Suppliers can only access the supplier queue.' );
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
                    self::render_403_error( 'Access Denied', 'You do not have permission to access this page. Designers are restricted from accessing admin and supplier areas.' );
                    exit;
                }
            }
        }

        // Suppliers blocked from global queue scope in our plugin admin
        if ( $is_supplier && ! $is_system_operator ) {
            if ( strpos( $query_vars['page'], 'admin-queue' ) !== false || strpos( $query_vars['page'], 'n88-rfq-role-management' ) !== false ) {
                if ( isset( $query_vars['scope'] ) && $query_vars['scope'] === 'global' ) {
                    self::render_403_error( 'Access Denied', 'You do not have permission to access the global queue. Suppliers can only access the supplier queue.' );
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
            return '<p>Access denied. Designer or System Operator account required.</p>';
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
            wp_die( 'Access denied. Designer or System Operator account required.', 'Access Denied', array( 'response' => 403 ) );
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
            return '<p><em>Supplier Queue page - This shortcode will display the supplier queue for authorized users.</em></p>';
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
            wp_die( 'Access denied. Supplier or System Operator account required.', 'Access Denied', array( 'response' => 403 ) );
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
                <h1 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;">NorthEightyEight — Supplier Queue</h1>
                <div style="font-size: 14px; color: #666;">
                    Logged in: <?php echo esc_html( $current_user->display_name ); ?>
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
                        <!-- Demo data from wireframe -->
                        <tr style="border-bottom: 1px solid #e0e0e0;">
                            <td style="padding: 15px; font-size: 14px; color: #ff6600; font-weight: 500;">#1023</td>
                            <td style="padding: 15px; font-size: 14px; color: #333;">Curved Sofa</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Upholstery</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Pricing Req</td>
                            <td style="padding: 15px;">
                                <button class="n88-open-bid-modal" data-item-id="1023" data-item-title="Curved Sofa" data-category="Upholstery" data-request-type="Pricing Req" style="padding: 6px 12px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                                    Open ►
                                </button>
                            </td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e0e0e0;">
                            <td style="padding: 15px; font-size: 14px; color: #ff6600; font-weight: 500;">#1027</td>
                            <td style="padding: 15px; font-size: 14px; color: #333;">Dining Chair</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Casegoods</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Awaiting Bid</td>
                            <td style="padding: 15px;">
                                <button class="n88-open-bid-modal" data-item-id="1027" data-item-title="Dining Chair" data-category="Casegoods" data-request-type="Awaiting Bid" style="padding: 6px 12px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                                    Open ►
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 15px; font-size: 14px; color: #ff6600; font-weight: 500;">#1031</td>
                            <td style="padding: 15px; font-size: 14px; color: #333;">Banquette</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Upholstery</td>
                            <td style="padding: 15px; font-size: 14px; color: #666;">Bid Submitted</td>
                            <td style="padding: 15px;">
                                <button class="n88-open-bid-modal" data-item-id="1031" data-item-title="Banquette" data-category="Upholstery" data-request-type="Bid Submitted" style="padding: 6px 12px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                                    View ►
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Rules Note -->
            <div style="margin-top: 30px; padding: 15px; background-color: #f0f0f0; border-left: 4px solid #0073aa; border-radius: 4px;">
                <p style="margin: 0; font-size: 13px; color: #666; font-style: italic;">
                    <strong>Rules:</strong> Supplier sees ONLY items routed to them. No designer identity shown.
                </p>
            </div>
        </div>
        
        <!-- Supplier Bid Modal -->
        <div id="n88-supplier-bid-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 10000; overflow: hidden;">
            <div id="n88-supplier-bid-modal-content" style="position: fixed; top: 0; right: 0; width: 480px; max-width: 90vw; height: 100vh; background-color: #fff; box-shadow: -2px 0 10px rgba(0,0,0,0.2); z-index: 10001; display: flex; flex-direction: column; overflow: hidden;">
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
            
            // Demo item data
            var demoItems = {
                '1023': {
                    id: '1023',
                    title: 'Curved Sofa',
                    category: 'Upholstery',
                    requestType: 'Pricing Req',
                    description: 'Modern curved sofa for reception area',
                    quantity: 2,
                    dimensions: { w: 240, d: 100, h: 85, unit: 'cm' },
                    status: 'Pricing Req'
                },
                '1027': {
                    id: '1027',
                    title: 'Dining Chair',
                    category: 'Casegoods',
                    requestType: 'Awaiting Bid',
                    description: 'Contemporary dining chair with upholstered seat',
                    quantity: 8,
                    dimensions: { w: 50, d: 55, h: 95, unit: 'cm' },
                    status: 'Awaiting Bid'
                },
                '1031': {
                    id: '1031',
                    title: 'Banquette',
                    category: 'Upholstery',
                    requestType: 'Bid Submitted',
                    description: 'Custom banquette seating for dining area',
                    quantity: 1,
                    dimensions: { w: 300, d: 60, h: 90, unit: 'cm' },
                    status: 'Bid Submitted'
                }
            };
            
            function openBidModal(itemId) {
                var item = demoItems[itemId];
                if (!item) return;
                
                var modal = document.getElementById('n88-supplier-bid-modal');
                var modalContent = document.getElementById('n88-supplier-bid-modal-content');
                
                if (!modal || !modalContent) return;
                
                // Build modal HTML - Supplier Bid Modal (matching wireframe exactly)
                var modalHTML = '<div style="padding: 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; background-color: #fff;">' +
                    '<h2 style="margin: 0; font-size: 20px; font-weight: 600; color: #333;">Item #' + item.id + ' <span style="color: #666; font-weight: 400;">(Supplier View)</span></h2>' +
                    '<button onclick="closeBidModal()" style="background: none; border: none; font-size: 28px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; color: #666; line-height: 1;">×</button>' +
                    '</div>' +
                    '<div style="flex: 1; overflow-y: auto; padding: 0; background-color: #fff;">' +
                    '<div style="padding: 20px;">' +
                    // READ-ONLY PLACEHOLDER text
                    '<div style="margin-bottom: 24px; padding: 12px; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">' +
                    '<div style="font-size: 13px; color: #856404; font-weight: 500;">READ-ONLY PLACEHOLDER <span style="color: #ff6600;">(Phase 2.2.x)</span></div>' +
                    '</div>' +
                    // Item Title
                    '<div style="margin-bottom: 16px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Item Title:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + item.title + '</div>' +
                    '</div>' +
                    // Category
                    '<div style="margin-bottom: 16px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Category:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + item.category + '</div>' +
                    '</div>' +
                    // Status/Queue
                    '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Status/Queue:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #333;">' + item.requestType + '</div>' +
                    '</div>' +
                    // Specs (if available)
                    '<div style="margin-bottom: 24px;">' +
                    '<h3 style="font-size: 14px; font-weight: 600; margin-bottom: 16px; color: #333; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px;">Specs (if available)</h3>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Qty:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">—</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">Dims:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">W— D— H— Unit: —</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">CBM:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">—</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">sourcing_type:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">—</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 12px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666;">timeline_type:</label>' +
                    '<div style="padding: 10px 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; color: #999;">—</div>' +
                    '</div>' +
                    '</div>' +
                    // Notes/Description
                    '<div style="margin-bottom: 24px;">' +
                    '<label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #666;">Notes/Description:</label>' +
                    '<div style="padding: 12px; background-color: #f5f5f5; border-radius: 4px; font-size: 14px; border: 1px solid #e0e0e0; min-height: 100px; color: #999; display: flex; align-items: center; justify-content: center;">[ ]</div>' +
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
                    '<div style="font-size: 12px; color: #999; font-style: italic; text-align: center;">(No bid UI. No prototype UI. No uploads.)</div>' +
                    '</div>';
                
                modalContent.innerHTML = modalHTML;
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
            
            function closeBidModal() {
                var modal = document.getElementById('n88-supplier-bid-modal');
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }
            
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
            });
            
            // Expose to global scope
            window.openBidModal = openBidModal;
            window.closeBidModal = closeBidModal;
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
                <h1 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;">NorthEightyEight — Admin Assembly Line</h1>
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
                        <label style="font-size: 14px; color: #666;">Supplier</label>
                        <select id="n88-admin-supplier" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background-color: #fff; cursor: pointer; min-width: 120px;">
                            <option value="all" <?php selected( $supplier_id, 'all' ); ?>>All</option>
                            <option value="supplier_x" <?php selected( $supplier_id, 'supplier_x' ); ?>>Supplier X</option>
                            <option value="supplier_y" <?php selected( $supplier_id, 'supplier_y' ); ?>>Supplier Y</option>
                            <option value="unassigned" <?php selected( $supplier_id, 'unassigned' ); ?>>Unassigned</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size: 14px; color: #666;">Designer</label>
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
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Designer</th>
                            <th style="padding: 15px; text-align: left; font-size: 14px; font-weight: 600; color: #333; border-right: 1px solid #e0e0e0;">Supplier</th>
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
            wp_die( 'Access denied. Supplier Admin account required.', 'Access Denied', array( 'response' => 403 ) );
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
            return '<p><em>Designer Onboarding page - This shortcode will display the designer onboarding form.</em></p>';
        }

        // Check if user is logged in and is a designer
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url( '/login/' ) );
            exit;
        }

        $current_user = wp_get_current_user();
        $is_designer = in_array( 'n88_designer', $current_user->roles, true ) || in_array( 'designer', $current_user->roles, true );
        
        if ( ! $is_designer ) {
            wp_die( 'Access denied. Designer account required.', 'Access Denied', array( 'response' => 403 ) );
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
                            <label style="display: block; margin-bottom: 6px; font-size: 13px; color: #666;">Zip Code</label>
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
            wp_send_json_error( array( 'message' => 'Access denied. Designer account required.' ) );
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
}

// .....