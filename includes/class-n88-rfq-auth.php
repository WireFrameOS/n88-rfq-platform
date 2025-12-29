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

        // AJAX handlers
        add_action( 'wp_ajax_n88_register_user', array( $this, 'ajax_register_user' ) );
        add_action( 'wp_ajax_nopriv_n88_register_user', array( $this, 'ajax_register_user' ) );
        add_action( 'wp_ajax_n88_login_user', array( $this, 'ajax_login_user' ) );
        add_action( 'wp_ajax_nopriv_n88_login_user', array( $this, 'ajax_login_user' ) );

        // Create designer role on activation
        add_action( 'init', array( $this, 'create_designer_role' ) );

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
    }

    /**
     * Create designer role with appropriate capabilities
     */
    public function create_designer_role() {
        // Check if role already exists
        if ( get_role( 'designer' ) ) {
            return;
        }

        // Create designer role with capabilities similar to subscriber but with access to our plugin
        add_role(
            'designer',
            __( 'Designer', 'n88-rfq' ),
            array(
                'read' => true,
                'upload_files' => true,
                // Add custom capabilities for plugin access
                'n88_access_boards' => true,
                'n88_access_items' => true,
                'n88_access_projects' => true,
            )
        );
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
            if ( in_array( 'designer', $current_user->roles ) ) {
                return '<p>You are already logged in. <a href="' . esc_url( home_url( '/designer-dashboard/' ) ) . '">Go to Dashboard</a></p>';
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
                <h2 class="n88-auth-title">Create Designer Account</h2>
                
                <?php if ( $message ) : ?>
                    <div class="n88-auth-message n88-auth-message-<?php echo esc_attr( $message_type ); ?>">
                        <?php echo esc_html( $message ); ?>
                    </div>
                <?php endif; ?>

                <form id="n88-signup-form" class="n88-auth-form" method="post">
                    <?php wp_nonce_field( 'n88_register_user', 'n88_signup_nonce' ); ?>
                    
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
        // If user is already logged in, redirect
        if ( is_user_logged_in() ) {
            $current_user = wp_get_current_user();
            if ( in_array( 'designer', $current_user->roles ) ) {
                wp_redirect( home_url( '/designer-dashboard/' ) );
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
                        window.location.href = data.data.redirect_url || '<?php echo esc_url( home_url( '/designer-dashboard/' ) ); ?>';
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
        // Verify nonce (use check_ajax_referer for better compatibility with public endpoints)
        if ( ! check_ajax_referer( 'n88_register_user', 'n88_signup_nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed. Please refresh and try again.' ) );
        }

        // Get and sanitize form data
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $password = isset( $_POST['password'] ) ? $_POST['password'] : '';
        $company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
        $country = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';

        // Validate required fields
        if ( empty( $name ) || empty( $username ) || empty( $email ) || empty( $password ) || empty( $company_name ) || empty( $country ) ) {
            wp_send_json_error( array( 'message' => 'All fields are required.' ) );
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

        // Assign designer role
        $user = new WP_User( $user_id );
        $user->set_role( 'designer' );

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
        // Verify nonce (use check_ajax_referer for better compatibility with public endpoints)
        if ( ! check_ajax_referer( 'n88_login_user', 'n88_login_nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed. Please refresh and try again.' ) );
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

        // Check if user has designer role
        if ( ! in_array( 'designer', $user->roles ) ) {
            wp_send_json_error( array( 'message' => 'Access denied. Designer account required.' ) );
        }

        // Log the user in
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, $remember );

        // Determine redirect URL
        $redirect_url = home_url( '/designer-dashboard/' );

        wp_send_json_success( array(
            'message' => 'Login successful!',
            'redirect_url' => $redirect_url,
        ) );
    }

    /**
     * Redirect designer after login
     */
    public function redirect_designer_after_login( $redirect_to, $requested_redirect_to, $user ) {
        // Check if user has designer role
        if ( isset( $user->roles ) && in_array( 'designer', $user->roles ) ) {
            return home_url( '/designer-dashboard/' );
        }
        return $redirect_to;
    }

    /**
     * Handle designer login
     */
    public function handle_designer_login( $user_login, $user ) {
        if ( in_array( 'designer', $user->roles ) ) {
            // Additional logic on designer login if needed
        }
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
     * Hide WordPress admin menus for designers
     */
    public function hide_wp_menus_for_designer() {
        $current_user = wp_get_current_user();
        if ( ! $current_user || ! in_array( 'designer', $current_user->roles, true ) ) {
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
     * Remove WordPress admin bar items for designers
     */
    public function remove_wp_admin_bar_items( $wp_admin_bar ) {
        $current_user = wp_get_current_user();
        if ( ! $current_user || ! in_array( 'designer', $current_user->roles, true ) ) {
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
     * Redirect designers away from WordPress admin pages
     */
    public function redirect_designer_from_wp_admin() {
        $current_user = wp_get_current_user();
        if ( ! $current_user || ! in_array( 'designer', $current_user->roles, true ) ) {
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
     * Render designer dashboard
     */
    public function render_designer_dashboard( $atts = array() ) {
        // Check if user is logged in and is a designer
        if ( ! is_user_logged_in() ) {
            return '<p>Please <a href="' . esc_url( home_url( '/login/' ) ) . '">log in</a> to access the dashboard.</p>';
        }

        $current_user = wp_get_current_user();
        if ( ! in_array( 'designer', $current_user->roles ) ) {
            return '<p>Access denied. Designer account required.</p>';
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
}

