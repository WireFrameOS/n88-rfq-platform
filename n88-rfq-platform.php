<?php
/**
 * Plugin Name: NorthEightyEight RFQ Platform
 * Description: Custom RFQ system with projects, metadata, repeaters, and dashboards.
 * Version: 1.2.0
 * Author: NorthEightyEight
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'N88_RFQ_VERSION', '0.1.0' );
define( 'N88_RFQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'N88_RFQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Status constants
define( 'N88_RFQ_STATUS_DRAFT', 0 );
define( 'N88_RFQ_STATUS_SUBMITTED', 1 );

// Autoload includes
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-helpers.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-installer.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-projects.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-admin.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-frontend.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-comments.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-quotes.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-notifications.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-audit.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-pdf-extractor.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-item-flags.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/class-n88-rfq-pricing.php';
require_once N88_RFQ_PLUGIN_DIR . 'includes/lib/fpdf.php';

// On activation create custom tables
register_activation_hook( __FILE__, array( 'N88_RFQ_Installer', 'activate' ) );
add_action( 'plugins_loaded', array( 'N88_RFQ_Installer', 'maybe_upgrade' ), 1 );

// Bootstrap core classes
if ( ! function_exists( 'n88_rfq_bootstrap' ) ) {
    function n88_rfq_bootstrap() {
        new N88_RFQ_Projects();
        new N88_RFQ_Admin();
        new N88_RFQ_Frontend();
    }
    add_action( 'plugins_loaded', 'n88_rfq_bootstrap', 5 );
}
