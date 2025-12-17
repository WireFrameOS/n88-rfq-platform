<?php
/**
 * Plugin Name: NorthEightyEight RFQ Platform
 * Description: Custom RFQ system with projects, metadata, repeaters, and dashboards.
 * Version: 1.2.1
 * Author: NorthEightyEight
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'N88_RFQ_VERSION', '0.1.0' );

// Determine plugin directory using realpath to resolve symlinks and get actual file location
// This avoids WordPress path resolution issues that can cause directory name mismatches
$real_file = realpath( __FILE__ );
if ( $real_file === false ) {
    $real_file = __FILE__;
}
$plugin_dir = rtrim( dirname( $real_file ), '/\\' ) . '/';

// Verify the directory is correct by checking if required file exists
$helpers_file_name = 'includes/class-n88-rfq-helpers.php';
$test_file = $plugin_dir . $helpers_file_name;

// If file doesn't exist, try alternative: use __FILE__ directly (in case realpath fails)
if ( ! file_exists( $test_file ) ) {
    $alt_dir = rtrim( dirname( __FILE__ ), '/\\' ) . '/';
    $alt_test_file = $alt_dir . $helpers_file_name;
    if ( file_exists( $alt_test_file ) ) {
        $plugin_dir = $alt_dir;
        $test_file = $alt_test_file;
    }
}

define( 'N88_RFQ_PLUGIN_DIR', $plugin_dir );
define( 'N88_RFQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Status constants
define( 'N88_RFQ_STATUS_DRAFT', 0 );
define( 'N88_RFQ_STATUS_SUBMITTED', 1 );

// Autoload includes with file existence checks
$helpers_file = N88_RFQ_PLUGIN_DIR . $helpers_file_name;
if ( ! file_exists( $helpers_file ) ) {
    $debug_info = '<br><strong>Debug Information:</strong><br>';
    $debug_info .= 'Resolved plugin directory: ' . esc_html( N88_RFQ_PLUGIN_DIR ) . '<br>';
    $debug_info .= '__FILE__: ' . esc_html( __FILE__ ) . '<br>';
    $debug_info .= 'realpath(__FILE__): ' . esc_html( realpath( __FILE__ ) ) . '<br>';
    $debug_info .= 'dirname(realpath(__FILE__)): ' . esc_html( dirname( realpath( __FILE__ ) ) ) . '<br>';
    $debug_info .= 'Expected file: ' . esc_html( $helpers_file ) . '<br>';
    $debug_info .= 'File exists: ' . ( file_exists( $helpers_file ) ? 'YES' : 'NO' ) . '<br>';
    wp_die( 'N88 RFQ Plugin Error: Required file not found: ' . esc_html( $helpers_file ) . $debug_info );
}
require_once $helpers_file;
$includes = array(
    'includes/class-n88-rfq-installer.php',
    'includes/class-n88-rfq-projects.php',
    'includes/class-n88-rfq-admin.php',
    'includes/class-n88-rfq-frontend.php',
    'includes/class-n88-rfq-comments.php',
    'includes/class-n88-rfq-quotes.php',
    'includes/class-n88-rfq-notifications.php',
    'includes/class-n88-rfq-audit.php',
    'includes/class-n88-rfq-pdf-extractor.php',
    'includes/class-n88-rfq-item-flags.php',
    'includes/class-n88-rfq-pricing.php',
    'includes/class-n88-rfq-timeline.php',
    'includes/class-n88-rfq-timeline-events.php',
    'includes/class-n88-events.php',
    'includes/class-n88-authorization.php',
    'includes/class-n88-intelligence.php',
    'includes/class-n88-items.php',
    'includes/class-n88-boards.php',
    'includes/class-n88-board-layout.php',
    'includes/lib/fpdf.php',
);

foreach ( $includes as $include_file ) {
    $file_path = N88_RFQ_PLUGIN_DIR . $include_file;
    if ( file_exists( $file_path ) ) {
        require_once $file_path;
    } else {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'N88 RFQ Plugin: File not found: ' . $file_path );
        }
    }
}

// On activation create custom tables
register_activation_hook( __FILE__, array( 'N88_RFQ_Installer', 'activate' ) );
add_action( 'plugins_loaded', array( 'N88_RFQ_Installer', 'maybe_upgrade' ), 1 );

// Bootstrap core classes
if ( ! function_exists( 'n88_rfq_bootstrap' ) ) {
function n88_rfq_bootstrap() {
    new N88_RFQ_Projects();
    new N88_RFQ_Admin();
    new N88_RFQ_Frontend();
    // Milestone 1.1: Items, Boards, and Layout endpoints
    new N88_Items();
    new N88_Boards();
    new N88_Board_Layout();
}
    add_action( 'plugins_loaded', 'n88_rfq_bootstrap', 5 );
}
