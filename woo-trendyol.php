<?php
/**
 * Plugin Name:       WooCommerce Trendyol Integration
 * Plugin URI:        https://developers.trendyol.com
 * Description:       Complete Trendyol integration for WooCommerce: cascading category mapping on product taxonomies, automatic price/stock/image sync to Trendyol, and bidirectional order management.
 * Version:           1.0.0
 * Author:            Manus AI
 * Author URI:        https://manus.im
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       woo-trendyol
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 */

// Prevent direct file access.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// ---------------------------------------------------------------------------
// Global constants
// ---------------------------------------------------------------------------

/**
 * Plugin version.
 *
 * @since 1.0.0
 * @var   string WOO_TRENDYOL_VERSION
 */
define( 'WOO_TRENDYOL_VERSION', '1.0.0' );

/**
 * Absolute path to the plugin root directory (with trailing slash).
 *
 * @since 1.0.0
 * @var   string WOO_TRENDYOL_PATH
 */
define( 'WOO_TRENDYOL_PATH', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin root directory (with trailing slash).
 *
 * @since 1.0.0
 * @var   string WOO_TRENDYOL_URL
 */
define( 'WOO_TRENDYOL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename (e.g. woo-trendyol/woo-trendyol.php).
 *
 * @since 1.0.0
 * @var   string WOO_TRENDYOL_BASENAME
 */
define( 'WOO_TRENDYOL_BASENAME', plugin_basename( __FILE__ ) );

// ---------------------------------------------------------------------------
// WooCommerce feature compatibility declarations
// ---------------------------------------------------------------------------

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 *
 * HPOS (introduced in WooCommerce 7.1, default since 8.2) stores orders in
 * dedicated custom tables instead of wp_posts / wp_postmeta. This plugin uses
 * the WooCommerce CRUD API exclusively for order data (wc_get_order(),
 * $order->get_meta(), $order->update_meta_data(), $order->save(), wc_get_orders())
 * so it is fully compatible with both HPOS and the legacy CPT-based storage.
 *
 * The declaration is wrapped in a class_exists guard so the plugin remains
 * loadable on WooCommerce versions that pre-date FeaturesUtil (< 7.1).
 *
 * @see https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/
 * @since 1.0.0
 */
add_action( 'before_woocommerce_init', static function (): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        // Declare HPOS (custom order tables) compatibility.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );

        // Declare Cart & Checkout Blocks compatibility (optional but good practice).
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
} );

// ---------------------------------------------------------------------------
// Activation / deactivation hooks
// ---------------------------------------------------------------------------

/**
 * Code that runs during plugin activation.
 *
 * Requires the Activator class and calls its static activate() method.
 *
 * @since 1.0.0
 */
function activate_woo_trendyol(): void {
    require_once WOO_TRENDYOL_PATH . 'includes/class-woo-trendyol-activator.php';
    Woo_Trendyol_Activator::activate();
}

/**
 * Code that runs during plugin deactivation.
 *
 * Requires the Deactivator class and calls its static deactivate() method.
 *
 * @since 1.0.0
 */
function deactivate_woo_trendyol(): void {
    require_once WOO_TRENDYOL_PATH . 'includes/class-woo-trendyol-deactivator.php';
    Woo_Trendyol_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_woo_trendyol' );
register_deactivation_hook( __FILE__, 'deactivate_woo_trendyol' );

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

/**
 * Begin execution of the plugin.
 *
 * Loads the core class and fires the run() method which registers all hooks.
 * Wrapped in a WooCommerce availability check to avoid fatal errors when
 * WooCommerce is deactivated.
 *
 * @since 1.0.0
 */
function run_woo_trendyol(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'WooCommerce Trendyol Integration requires WooCommerce to be installed and active.', 'woo-trendyol' )
                . '</p></div>';
        } );
        return;
    }

    require_once WOO_TRENDYOL_PATH . 'includes/class-woo-trendyol.php';
    $plugin = new Woo_Trendyol();
    $plugin->run();
}

add_action( 'plugins_loaded', 'run_woo_trendyol' );
