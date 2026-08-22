<?php
/**
 * The core plugin class — orchestrates all subsystems.
 *
 * Defines the plugin name, version, and loads all dependencies.
 * Instantiates the loader, registers all hooks via define_*_hooks() methods,
 * and fires run() to hand control to WordPress.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Woo_Trendyol
 *
 * The central orchestrator following the WPPB pattern:
 *  1. load_dependencies()      — require all class files
 *  2. set_locale()             — register i18n
 *  3. define_admin_hooks()     — register admin-area hooks
 *  4. define_public_hooks()    — register front-end hooks (stub)
 *  5. define_sync_hooks()      — register product + order sync hooks
 *  6. run()                    — fire the loader
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol {

    /**
     * The loader that's responsible for maintaining and registering all hooks.
     *
     * @since  1.0.0
     * @access protected
     * @var    Woo_Trendyol_Loader $loader
     */
    protected Woo_Trendyol_Loader $loader;

    /**
     * The unique identifier of this plugin.
     *
     * @since  1.0.0
     * @access protected
     * @var    string $plugin_name
     */
    protected string $plugin_name;

    /**
     * The current version of the plugin.
     *
     * @since  1.0.0
     * @access protected
     * @var    string $version
     */
    protected string $version;

    /**
     * Shared API client instance.
     *
     * @since  1.0.0
     * @access protected
     * @var    Woo_Trendyol_API_Client $api
     */
    protected Woo_Trendyol_API_Client $api;

    /**
     * Shared logger instance.
     *
     * @since  1.0.0
     * @access protected
     * @var    Woo_Trendyol_Logger $logger
     */
    protected Woo_Trendyol_Logger $logger;

    /**
     * Shared category helper instance.
     *
     * @since  1.0.0
     * @access protected
     * @var    Woo_Trendyol_Category_Helper $category_helper
     */
    protected Woo_Trendyol_Category_Helper $category_helper;

    /**
     * Shared attribute mapper instance.
     *
     * @since  1.0.0
     * @access protected
     * @var    Woo_Trendyol_Attribute_Mapper $attribute_mapper
     */
    protected Woo_Trendyol_Attribute_Mapper $attribute_mapper;

    /**
     * Shared product creator instance.
     *
     * @since  1.0.0
     * @access protected
     * @var    Woo_Trendyol_Product_Creator $product_creator
     */
    protected Woo_Trendyol_Product_Creator $product_creator;

    /**
     * Shared brand sync instance.
     *
     * @since  1.0.0
     * @access protected
     * @var    Woo_Trendyol_Brand_Sync $brand_sync
     */
    protected Woo_Trendyol_Brand_Sync $brand_sync;

    /**
     * Import Export instance.
     *
     * @since  1.0.0
     * @access protected
     * @var    Woo_Trendyol_Import_Export $import_export
     */
    protected Woo_Trendyol_Import_Export $import_export;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Define the core functionality of the plugin.
     *
     * Sets the plugin name and version, loads all dependencies, and
     * registers all hooks through the loader.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->plugin_name = 'woo-trendyol';
        $this->version     = WOO_TRENDYOL_VERSION;

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_sync_hooks();
    }

    // -----------------------------------------------------------------------
    // Dependency loading
    // -----------------------------------------------------------------------

    /**
     * Load all required dependency files and instantiate shared services.
     *
     * @since  1.0.0
     * @access private
     */
    private function load_dependencies(): void {
        $path = WOO_TRENDYOL_PATH;

        // WPPB core classes.
        require_once $path . 'includes/class-woo-trendyol-loader.php';
        require_once $path . 'includes/class-woo-trendyol-i18n.php';

        // Shared service classes.
        require_once $path . 'includes/class-woo-trendyol-logger.php';
        require_once $path . 'includes/class-woo-trendyol-api-client.php';
        require_once $path . 'includes/class-woo-trendyol-category-helper.php';
        require_once $path . 'includes/class-woo-trendyol-attribute-mapper.php';
        require_once $path . 'includes/class-woo-trendyol-product-creator.php';

        // Feature classes.
        require_once $path . 'includes/class-woo-trendyol-product-sync.php';
        require_once $path . 'includes/class-woo-trendyol-order-sync.php';
        require_once $path . 'includes/class-woo-trendyol-brand-sync.php';
        require_once $path . 'includes/class-woo-trendyol-import-export.php';

        // Admin classes.
        require_once $path . 'admin/class-woo-trendyol-admin.php';
        require_once $path . 'admin/class-woo-trendyol-taxonomy.php';
        require_once $path . 'admin/class-woo-trendyol-brand-admin.php';

        // Public class.
        require_once $path . 'public/class-woo-trendyol-public.php';

        // Instantiate the hook loader.
        $this->loader = new Woo_Trendyol_Loader();

        // Instantiate shared services (injected into all subsystems).
        $this->logger           = new Woo_Trendyol_Logger();
        $this->api              = new Woo_Trendyol_API_Client( $this->logger );
        $this->category_helper  = new Woo_Trendyol_Category_Helper();
        $this->attribute_mapper = new Woo_Trendyol_Attribute_Mapper( $this->api, $this->logger );
        $this->product_creator  = new Woo_Trendyol_Product_Creator(
            $this->api,
            $this->logger,
            $this->category_helper,
            $this->attribute_mapper
        );

        // Brand sync service.
        $this->brand_sync = new Woo_Trendyol_Brand_Sync(
            $this->plugin_name,
            $this->version,
            $this->api,
            $this->logger
        );

        // Import/Export service.
        $this->import_export = new Woo_Trendyol_Import_Export();
    }

    // -----------------------------------------------------------------------
    // Locale
    // -----------------------------------------------------------------------

    /**
     * Define the locale for this plugin for internationalisation.
     *
     * @since  1.0.0
     * @access private
     */
    private function set_locale(): void {
        $plugin_i18n = new Woo_Trendyol_i18n();
        $this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
    }

    // -----------------------------------------------------------------------
    // Admin hooks
    // -----------------------------------------------------------------------

    /**
     * Register all admin-area hooks.
     *
     * Covers:
     *  - Settings page (WooCommerce → Trendyol Sync menu)
     *    - Section 1: API credentials & connection toggle
     *    - Section 2: Product defaults (cargo, VAT, handling time)
     *    - Section 3: Global attribute mappings (gender, age, brand, character)
     *  - Bulk push tool (AJAX: get pushable products, push batch, poll batch)
     *  - Product meta box (read-only status + category override)
     *  - AJAX handlers (connection test, status refresh, cargo fetch)
     *  - Plugin action links
     *  - Taxonomy mapper (cascading dropdowns on product_cat)
     *
     * @since  1.0.0
     * @access private
     */
    private function define_admin_hooks(): void {
        $admin = new Woo_Trendyol_Admin(
            $this->plugin_name,
            $this->version,
            $this->api,
            $this->logger,
            $this->category_helper,
            $this->product_creator
        );

        // Enqueue admin assets.
        $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
        $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );

        // Settings page.
        $this->loader->add_action( 'admin_menu', $admin, 'add_settings_page' );
        $this->loader->add_action( 'admin_init',  $admin, 'register_settings' );
        $this->loader->add_filter(
            'plugin_action_links_' . WOO_TRENDYOL_BASENAME,
            $admin,
            'add_action_links'
        );

        // Product meta box.
        $this->loader->add_action( 'add_meta_boxes',    $admin, 'register_product_meta_box' );
        $this->loader->add_action( 'add_meta_boxes',    $admin, 'register_order_meta_box' );
        $this->loader->add_action( 'save_post_product', $admin, 'save_product_meta_box', 10, 1 );

        // AJAX handlers — settings page.
        $this->loader->add_action( 'wp_ajax_trendyol_test_connection',      $admin, 'ajax_test_connection' );
        $this->loader->add_action( 'wp_ajax_trendyol_fetch_cargo_companies', $admin, 'ajax_fetch_cargo_companies' );

        // AJAX handlers — bulk push.
        $this->loader->add_action( 'wp_ajax_trendyol_get_pushable_products', $admin, 'ajax_get_pushable_products' );
        $this->loader->add_action( 'wp_ajax_trendyol_bulk_push_batch',       $admin, 'ajax_bulk_push_batch' );
        $this->loader->add_action( 'wp_ajax_trendyol_bulk_sync_price_stock_batch', $admin, 'ajax_bulk_sync_price_stock_batch' );
        $this->loader->add_action( 'wp_ajax_trendyol_get_unapproved_products_to_update', $admin, 'ajax_get_unapproved_products_to_update' );
        $this->loader->add_action( 'wp_ajax_trendyol_bulk_update_unapproved_batch', $admin, 'ajax_bulk_update_unapproved_batch' );
        $this->loader->add_action( 'wp_ajax_trendyol_poll_batch_status',     $admin, 'ajax_poll_batch_status' );

        // AJAX handlers — product edit page.
        $this->loader->add_action( 'wp_ajax_trendyol_refresh_status',       $admin, 'ajax_refresh_product_status' );
        $this->loader->add_action( 'wp_ajax_trendyol_push_single_product',  $admin, 'ajax_push_single_product' );
        $this->loader->add_action( 'wp_ajax_trendyol_get_shipping_label',   $admin, 'ajax_get_shipping_label' );

        // AJAX handlers — global attribute mapping UI.
        $this->loader->add_action( 'wp_ajax_trendyol_load_attr_values', $admin, 'ajax_load_attr_values' );
        $this->loader->add_action( 'wp_ajax_trendyol_load_all_mapped_attr_values', $admin, 'ajax_load_all_mapped_attr_values' );
        $this->loader->add_action( 'wp_ajax_trendyol_get_wc_terms',     $admin, 'ajax_get_wc_terms' );

        // AJAX handlers — Sync actions (Brands are handled in brand admin).
        $this->loader->add_action( 'wp_ajax_trendyol_sync_categories',          $admin, 'ajax_sync_categories' );
        $this->loader->add_action( 'wp_ajax_trendyol_sync_category_attributes', $admin, 'ajax_sync_category_attributes' );
        $this->loader->add_action( 'wp_ajax_trendyol_sync_attribute_values',    $admin, 'ajax_sync_attribute_values' );
        $this->loader->add_action( 'wp_ajax_trendyol_sync_single_category_attributes', $admin, 'ajax_sync_single_category_attributes' );
        $this->loader->add_action( 'wp_ajax_trendyol_get_wc_attribute_terms', $admin, 'ajax_get_wc_attribute_terms' );

        // Taxonomy mapper (cascading dropdowns on product_cat).
        $taxonomy = new Woo_Trendyol_Taxonomy(
            $this->plugin_name,
            $this->version
        );

        $this->loader->add_action( 'product_cat_add_form_fields',  $taxonomy, 'add_category_fields',  10, 1 );
        $this->loader->add_action( 'product_cat_edit_form_fields', $taxonomy, 'edit_category_fields', 10, 2 );
        $this->loader->add_action( 'created_product_cat',          $taxonomy, 'save_category_fields', 10, 2 );
        $this->loader->add_action( 'edited_product_cat',           $taxonomy, 'save_category_fields', 10, 2 );
        $this->loader->add_action( 'admin_enqueue_scripts',        $taxonomy, 'enqueue_scripts' );
        $this->loader->add_filter( 'manage_edit-product_cat_columns', $taxonomy, 'add_category_columns' );
        $this->loader->add_filter( 'manage_product_cat_custom_column', $taxonomy, 'render_category_column_content', 10, 3 );
        $this->loader->add_filter( 'bulk_actions-edit-product_cat', $taxonomy, 'register_bulk_action' );
        $this->loader->add_action( 'wp_ajax_trendyol_bulk_map_categories', $taxonomy, 'ajax_bulk_map_categories' );
        $this->loader->add_action( 'admin_footer-edit-tags.php', $taxonomy, 'render_bulk_modal' );

        // Brand admin (taxonomy column, edit-brand UI, brand sync card).
        $brand_admin = new Woo_Trendyol_Brand_Admin(
            $this->plugin_name,
            $this->version,
            $this->brand_sync
        );

        // Only register brand taxonomy hooks when product_brand taxonomy is available.
        $this->loader->add_action( 'init', $brand_admin, 'maybe_register_brand_hooks' );

        // AJAX handlers for brand sync and brand search.
        $this->loader->add_action( 'wp_ajax_trendyol_sync_brands',       $brand_admin, 'ajax_sync_brands' );
        $this->loader->add_action( 'wp_ajax_trendyol_search_brand',      $brand_admin, 'ajax_search_brand' );
        $this->loader->add_action( 'wp_ajax_trendyol_save_brand_mapping', $brand_admin, 'ajax_save_brand_mapping' );

        // Brand sync card injected into settings sidebar.
        $this->loader->add_action( 'wt_settings_sidebar_cards', $brand_admin, 'render_brand_sync_card' );
    }

    // -----------------------------------------------------------------------
    // Public hooks
    // -----------------------------------------------------------------------

    /**
     * Register all front-end hooks (stub — no public output required).
     *
     * @since  1.0.0
     * @access private
     */
    private function define_public_hooks(): void {
        $plugin_public = new Woo_Trendyol_Public( $this->plugin_name, $this->version );

        $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
        $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
    }

    // -----------------------------------------------------------------------
    // Sync hooks
    // -----------------------------------------------------------------------

    /**
     * Register all product-sync and order-sync hooks.
     *
     * Covers:
     *  - Price/stock sync on product save and meta update
     *  - Image sync on attachment update
     *  - WP-Cron interval registration and scheduling
     *  - Order polling (cron)
     *  - Order status notifications (processing → picking, completed → invoiced)
     *
     * @since  1.0.0
     * @access private
     */
    private function define_sync_hooks(): void {
        // Product sync (price, stock, images).
        $product_sync = new Woo_Trendyol_Product_Sync(
            $this->plugin_name,
            $this->version,
            $this->api,
            $this->logger,
            $this->category_helper,
            $this->product_creator
        );

        $this->loader->add_action( 'woocommerce_after_product_object_save', $product_sync, 'on_product_saved',      10, 2 );
        $this->loader->add_action( 'updated_post_meta',                     $product_sync, 'on_post_meta_updated',  10, 4 );
        $this->loader->add_action( 'added_post_meta',                       $product_sync, 'on_post_meta_updated',  10, 4 );
        $this->loader->add_action( 'edit_attachment',                       $product_sync, 'on_attachment_updated', 10, 1 );
        $this->loader->add_action( 'woocommerce_product_set_stock',         $product_sync, 'on_product_stock_set',  10, 1 );
        $this->loader->add_action( 'woocommerce_variation_set_stock',       $product_sync, 'on_product_stock_set',  10, 1 );
        $this->loader->add_action( 'woocommerce_reduce_order_stock',         $product_sync, 'on_order_stock_reduced', 10, 1 );
        $this->loader->add_action( 'woocommerce_restore_order_stock',        $product_sync, 'on_order_stock_restored', 10, 1 );

        // Order sync (polling + status notifications).
        $order_sync = new Woo_Trendyol_Order_Sync(
            $this->plugin_name,
            $this->version,
            $this->api,
            $this->logger
        );

        $this->loader->add_filter( 'cron_schedules',                         $order_sync, 'register_cron_interval' );
        $this->loader->add_action( 'init',                                   $order_sync, 'maybe_schedule_cron' );
        $this->loader->add_action( Woo_Trendyol_Order_Sync::CRON_HOOK,      $order_sync, 'poll_orders' );
        $this->loader->add_action( 'woocommerce_order_status_processing',    $order_sync, 'on_order_processing', 10, 1 );
        $this->loader->add_action( 'woocommerce_order_status_completed',     $order_sync, 'on_order_completed',  10, 1 );
        $this->loader->add_action( 'woocommerce_order_status_cancelled',     $order_sync, 'on_order_cancelled',  10, 1 );
    }

    // -----------------------------------------------------------------------
    // Getters (WPPB pattern)
    // -----------------------------------------------------------------------

    /**
     * The name of the plugin used to uniquely identify it within WordPress.
     *
     * @since  1.0.0
     * @return string The name of the plugin.
     */
    public function get_plugin_name(): string {
        return $this->plugin_name;
    }

    /**
     * The reference to the class that orchestrates the hooks with the plugin.
     *
     * @since  1.0.0
     * @return Woo_Trendyol_Loader Orchestrates the hooks of the plugin.
     */
    public function get_loader(): Woo_Trendyol_Loader {
        return $this->loader;
    }

    /**
     * Retrieve the version number of the plugin.
     *
     * @since  1.0.0
     * @return string The version number of the plugin.
     */
    public function get_version(): string {
        return $this->version;
    }

    // -----------------------------------------------------------------------
    // Run
    // -----------------------------------------------------------------------

    /**
     * Run the loader to execute all registered hooks with WordPress.
     *
     * @since 1.0.0
     */
    public function run(): void {
        $this->loader->run();
    }
}
