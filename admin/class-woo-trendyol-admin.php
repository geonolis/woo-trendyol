<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * Handles the settings page, product meta box, bulk push tool, and AJAX endpoints.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/admin
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

// Import the HPOS OrderUtil helper (available since WC 7.1).
// Used to detect whether HPOS tables are active and to resolve the correct
// order admin screen ID when registering meta boxes.
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Class Woo_Trendyol_Admin
 *
 * Responsibilities:
 *  - Enqueue admin CSS and JS assets.
 *  - Register the WooCommerce → Trendyol Sync settings page with multiple sections:
 *      1. API Credentials & Connection
 *      2. Product Defaults (cargo company, VAT rate, handling time)
 *      3. Global Attribute Mappings (gender, age group, brand, character)
 *      4. Bulk Push Tool
 *  - Register and render the product meta box (read-only status + category override).
 *  - Save the category override field on product save.
 *  - Handle AJAX requests: connection test, product status refresh, bulk push batch.
 *  - Add a "Settings" action link on the Plugins page.
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/admin
 */
class Woo_Trendyol_Admin {

    /**
     * The ID of this plugin.
     *
     * @since  1.0.0
     * @access private
     * @var    string $plugin_name
     */
    private string $plugin_name;

    /**
     * The current version of this plugin.
     *
     * @since  1.0.0
     * @access private
     * @var    string $version
     */
    private string $version;

    /**
     * Shared API client.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_API_Client $api
     */
    private Woo_Trendyol_API_Client $api;

    /**
     * Shared logger.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Logger $logger
     */
    private Woo_Trendyol_Logger $logger;

    /**
     * Category helper — resolves Trendyol category IDs for products.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Category_Helper $category_helper
     */
    private Woo_Trendyol_Category_Helper $category_helper;

    /**
     * Product creator — builds and submits product creation payloads.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Product_Creator $product_creator
     */
    private Woo_Trendyol_Product_Creator $product_creator;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Initialise the class and set its properties.
     *
     * @since 1.0.0
     * @param string                        $plugin_name     The name of this plugin.
     * @param string                        $version         The version of this plugin.
     * @param Woo_Trendyol_API_Client       $api             Shared API client.
     * @param Woo_Trendyol_Logger           $logger          Shared logger.
     * @param Woo_Trendyol_Category_Helper  $category_helper Category resolution helper.
     * @param Woo_Trendyol_Product_Creator  $product_creator Product creation handler.
     */
    public function __construct(
        string $plugin_name,
        string $version,
        Woo_Trendyol_API_Client $api,
        Woo_Trendyol_Logger $logger,
        Woo_Trendyol_Category_Helper $category_helper,
        Woo_Trendyol_Product_Creator $product_creator
    ) {
        $this->plugin_name     = $plugin_name;
        $this->version         = $version;
        $this->api             = $api;
        $this->logger          = $logger;
        $this->category_helper = $category_helper;
        $this->product_creator = $product_creator;
    }

    // -----------------------------------------------------------------------
    // Asset enqueueing
    // -----------------------------------------------------------------------

    /**
     * Register the stylesheets for the admin area.
     *
     * Hooked to: admin_enqueue_scripts
     *
     * @since 1.0.0
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_styles( string $hook_suffix ): void {
        if ( ! $this->is_plugin_admin_page( $hook_suffix ) && ! $this->is_product_edit_page( $hook_suffix ) ) {
            return;
        }

        wp_enqueue_style(
            $this->plugin_name . '-admin',
            WOO_TRENDYOL_URL . 'admin/css/woo-trendyol-admin.css',
            [],
            $this->version,
            'all'
        );
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * Hooked to: admin_enqueue_scripts
     *
     * @since 1.0.0
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_scripts( string $hook_suffix ): void {
        if ( ! $this->is_plugin_admin_page( $hook_suffix ) && ! $this->is_product_edit_page( $hook_suffix ) ) {
            return;
        }

        wp_enqueue_script(
            $this->plugin_name . '-admin',
            WOO_TRENDYOL_URL . 'admin/js/woo-trendyol-admin.js',
            [ 'jquery' ],
            $this->version . '.' . time(),
            true
        );

        wp_localize_script(
            $this->plugin_name . '-admin',
            'wooTrendyolAdmin',
            [
                'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
                'nonce'              => wp_create_nonce( 'woo_trendyol_admin' ),
                'testConnectionText' => __( 'Test API Connection', 'woo-trendyol' ),
                'testingText'        => __( 'Testing…', 'woo-trendyol' ),
                'refreshText'        => __( 'Refresh Status', 'woo-trendyol' ),
                'refreshingText'     => __( 'Refreshing…', 'woo-trendyol' ),
                'connectionSuccess'  => __( 'Connection successful!', 'woo-trendyol' ),
                'connectionFail'     => __( 'Connection failed: ', 'woo-trendyol' ),
                'bulkPushText'       => __( 'Push Products to Trendyol', 'woo-trendyol' ),
                'bulkPushingText'    => __( 'Pushing…', 'woo-trendyol' ),
                'bulkPushDone'       => __( 'Push complete.', 'woo-trendyol' ),
                'bulkPushError'      => __( 'Push error: ', 'woo-trendyol' ),
                'currency'           => Woo_Trendyol_API_Client::CURRENCY,
                'sendText'           => __( 'Send to Trendyol', 'woo-trendyol' ),
                'sendingText'        => __( 'Sending…', 'woo-trendyol' ),
                'sendSuccess'        => __( 'Sent successfully.', 'woo-trendyol' ),
                'sendError'          => __( 'Send failed: ', 'woo-trendyol' ),
                'sendPending'        => __( 'Submitted — status pending.', 'woo-trendyol' ),
            ]
        );
    }

    // -----------------------------------------------------------------------
    // Settings page — menu and registration
    // -----------------------------------------------------------------------

    /**
     * Add the Trendyol Sync settings page under WooCommerce.
     *
     * Hooked to: admin_menu
     *
     * @since 1.0.0
     */
    public function add_settings_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Trendyol Sync', 'woo-trendyol' ),
            __( 'Trendyol Sync', 'woo-trendyol' ),
            'manage_woocommerce',
            'woo-trendyol-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Register all plugin settings with the WordPress Settings API.
     *
     * Sections:
     *  1. woo_trendyol_api_section       — API credentials & connection toggle
     *  2. woo_trendyol_defaults_section  — Product defaults (cargo, VAT, handling time)
     *  3. woo_trendyol_attrs_section     — Global attribute mappings
     *
     * Hooked to: admin_init
     *
     * @since 1.0.0
     */
    public function register_settings(): void {

        // ---- Section 1: API Credentials ----
        $api_options = [
            'trendyol_api_active',
            'trendyol_seller_id',
            'trendyol_api_key',
            'trendyol_api_secret',
            'trendyol_storefront_code',
            'trendyol_integration_reference_code',
            'trendyol_order_poll_interval',
        ];
        foreach ( $api_options as $opt ) {
            register_setting( 'woo_trendyol_api_settings', $opt, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        }
        register_setting( 'woo_trendyol_api_settings', 'trendyol_order_poll_interval', [ 'sanitize_callback' => 'absint' ] );

        add_settings_section(
            'woo_trendyol_api_section',
            __( 'API Credentials & Connection', 'woo-trendyol' ),
            [ $this, 'render_api_section_description' ],
            'woo-trendyol-settings'
        );

        $api_fields = [
            [ 'trendyol_api_active',                __( 'Enable Integration',        'woo-trendyol' ), 'render_field_toggle'   ],
            [ 'trendyol_seller_id',                 __( 'Seller ID',                 'woo-trendyol' ), 'render_field_text'     ],
            [ 'trendyol_api_key',                   __( 'API Key',                   'woo-trendyol' ), 'render_field_text'     ],
            [ 'trendyol_api_secret',                __( 'API Secret',                'woo-trendyol' ), 'render_field_password' ],
            [ 'trendyol_storefront_code',           __( 'Storefront Code',           'woo-trendyol' ), 'render_field_text'     ],
            [ 'trendyol_integration_reference_code', __( 'Integration Reference Code', 'woo-trendyol' ), 'render_field_text'     ],
            [ 'trendyol_order_poll_interval',       __( 'Order Poll Interval (min)', 'woo-trendyol' ), 'render_field_number'   ],
        ];

        foreach ( $api_fields as [ $id, $label, $callback ] ) {
            add_settings_field( $id, $label, [ $this, $callback ], 'woo-trendyol-settings', 'woo_trendyol_api_section', [ 'label_for' => $id ] );
        }

        // ---- Section 2: Product Defaults ----
        $default_options = [
            'trendyol_default_cargo_company_id',
            'trendyol_default_vat_rate',
            'trendyol_handling_time_type',
            'trendyol_handling_time_days',
            'trendyol_handling_time_wc_attr',
        ];
        foreach ( $default_options as $opt ) {
            register_setting( 'woo_trendyol_defaults_settings', $opt, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        }
        register_setting( 'woo_trendyol_defaults_settings', 'trendyol_default_vat_rate',  [ 'sanitize_callback' => 'absint' ] );
                register_setting( 'woo_trendyol_defaults_settings', 'trendyol_handling_time_days', [ 'sanitize_callback' => 'absint' ] );

        /*
         * Barcode source — three modes:
         *  'sku'            : WooCommerce product SKU (get_sku()) — the legacy default.
         *  'global_unique_id': The official WooCommerce _global_unique_id post meta
         *                     (GTIN/EAN/ISBN field, introduced in WC 9.2).
         *  'meta'           : A custom post meta key entered by the user.
         *  'attribute'      : A WooCommerce product attribute slug (pa_* or custom).
         *
         * trendyol_barcode_source   — one of the four mode strings above.
         * trendyol_barcode_meta_key — the custom meta key (used when source = 'meta').
         * trendyol_barcode_attr_slug— the attribute slug (used when source = 'attribute').
         */
        register_setting(
            'woo_trendyol_defaults_settings',
            'trendyol_barcode_source',
            [ 'sanitize_callback' => 'sanitize_key', 'default' => 'sku' ]
        );
        register_setting(
            'woo_trendyol_defaults_settings',
            'trendyol_barcode_meta_key',
            [ 'sanitize_callback' => 'sanitize_text_field' ]
        );
        register_setting(
            'woo_trendyol_defaults_settings',
            'trendyol_barcode_attr_slug',
            [ 'sanitize_callback' => 'sanitize_text_field' ]
        );

        add_settings_section(
            'woo_trendyol_defaults_section',
            __( 'Product Defaults', 'woo-trendyol' ),
            [ $this, 'render_defaults_section_description' ],
            'woo-trendyol-settings'
        );

        $default_fields = [
            [ 'trendyol_barcode_source',           __( 'Barcode Source',      'woo-trendyol' ), 'render_field_barcode_source'  ],
            [ 'trendyol_default_cargo_company_id', __( 'Cargo Company ID',    'woo-trendyol' ), 'render_field_cargo_company'   ],
            [ 'trendyol_default_vat_rate',         __( 'VAT Rate (%)',        'woo-trendyol' ), 'render_field_vat_rate'        ],
            [ 'trendyol_handling_time_type',       __( 'Handling Time',       'woo-trendyol' ), 'render_field_handling_time'   ],
        ];

        foreach ( $default_fields as [ $id, $label, $callback ] ) {
            add_settings_field( $id, $label, [ $this, $callback ], 'woo-trendyol-settings', 'woo_trendyol_defaults_section', [ 'label_for' => $id ] );
        }

        // ---- Section 3: Global Attribute Mappings ----
        $attr_options = [
            'trendyol_global_attr_brand_wc',
            'trendyol_global_attr_character_wc',
        ];

        // Register fixed options
        foreach ( $attr_options as $opt ) {
            register_setting(
                'woo_trendyol_attrs_settings',
                $opt,
                [ 'sanitize_callback' => [ $this, 'sanitize_attr_option' ] ]
            );
        }

        add_settings_section(
            'woo_trendyol_attrs_section',
            __( 'Global Attribute Mappings', 'woo-trendyol' ),
            [ $this, 'render_attrs_section_description' ],
            'woo-trendyol-settings'
        );

        $attr_fields = [
            [ 'trendyol_global_attr_brand_wc',        __( 'Brand — WooCommerce Attribute',              'woo-trendyol' ), 'render_field_global_brand'        ],
            [ 'trendyol_global_attr_character_wc',    __( 'Character / Hero — WC Attribute',            'woo-trendyol' ), 'render_field_global_character'    ],
        ];

        foreach ( $attr_fields as [ $id, $label, $callback ] ) {
            add_settings_field( $id, $label, [ $this, $callback ], 'woo-trendyol-settings', 'woo_trendyol_attrs_section', [ 'label_for' => $id ] );
        }

        // Register dynamic discovered attributes
        $discovered = get_option( 'trendyol_discovered_global_attrs', [] );
        if ( is_array( $discovered ) ) {
            foreach ( $discovered as $g_attr ) {
                $attr_id = (string) ( $g_attr['id'] ?? '' );
                if ( empty( $attr_id ) ) {
                    continue;
                }
                
                $wc_opt  = 'trendyol_global_attr_' . $attr_id . '_wc';
                $map_opt = 'trendyol_global_attr_' . $attr_id . '_map';

                register_setting( 'woo_trendyol_attrs_settings', $wc_opt, [ 'sanitize_callback' => [ $this, 'sanitize_attr_option' ] ] );
                register_setting( 'woo_trendyol_attrs_settings', $map_opt, [ 'sanitize_callback' => [ $this, 'sanitize_attr_option' ] ] );

                $clean_name = preg_replace( '/\s*\(\s*Free Text\s*\)/i', '', (string) ( $g_attr['name'] ?? '' ) );
                add_settings_field(
                    $wc_opt,
                    sprintf( __( '%s — WooCommerce Attribute', 'woo-trendyol' ), esc_html( $clean_name ) ),
                    function( $args ) use ( $attr_id, $g_attr, $clean_name, $wc_opt, $map_opt ) {
                        $desc = sprintf( __( 'Map WooCommerce terms to Trendyol values for %s.', 'woo-trendyol' ), esc_html( $clean_name ) );
                        if ( ! empty( $g_attr['categories'] ) ) {
                            $cat_names = array_unique( $g_attr['categories'] );
                            $desc .= '<br><span style="font-size: 11px; color: #666;">' . sprintf( __( 'Mandatory in categories: %s', 'woo-trendyol' ), esc_html( implode( ', ', $cat_names ) ) ) . '</span>';
                        }
                        
                        $is_free_text = ! empty( $g_attr['allowCustom'] ) || (bool) preg_match( '/\b(web|free|custom|serbest)\b/i', (string) ( $g_attr['name'] ?? '' ) );

                        $this->render_attr_mapping_field(
                            $attr_id,
                            $wc_opt,
                            $map_opt,
                            $desc,
                            $is_free_text,
                            $is_free_text
                        );
                    },
                    'woo-trendyol-settings',
                    'woo_trendyol_attrs_section',
                    [ 'label_for' => $wc_opt ]
                );
            }
        }

        // ---- Section 4: Price Rules ----
        $price_options = [
            'trendyol_price_rule_fixed_enabled',
            'trendyol_price_rule_fixed_amount',
            'trendyol_price_rule_percentage_enabled',
            'trendyol_price_rule_percentage',
            'trendyol_price_rule_vw_enabled',
            'trendyol_price_rule_vw_under_1',
            'trendyol_price_rule_vw_1_to_2',
            'trendyol_price_rule_vw_2_to_3',
            'trendyol_price_rule_vw_over_3_fixed',
            'trendyol_price_rule_vw_over_3_coef',
            'trendyol_price_rule_vw_zero_dimensions_amount',
            'trendyol_price_rule_min_bulk_push_price',
        ];
        foreach ( $price_options as $opt ) {
            register_setting( 'woo_trendyol_price_rules_settings', $opt, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        }

        add_settings_section(
            'woo_trendyol_price_rules_section',
            __( 'Price Rules', 'woo-trendyol' ),
            [ $this, 'render_price_rules_section_description' ],
            'woo-trendyol-settings'
        );

        $price_fields = [
            [ 'trendyol_price_rule_fixed_enabled',    __( 'Enable Fixed Amount Adjustment',              'woo-trendyol' ), 'render_field_price_fixed_toggle' ],
            [ 'trendyol_price_rule_fixed_amount',     __( 'Fixed Amount to Add',                         'woo-trendyol' ), 'render_field_price_fixed_amount' ],
            [ 'trendyol_price_rule_percentage_enabled', __( 'Enable Percentage Adjustment',              'woo-trendyol' ), 'render_field_price_pct_toggle'   ],
            [ 'trendyol_price_rule_percentage',       __( 'Percentage to Add (%)',                       'woo-trendyol' ), 'render_field_price_pct_amount'   ],
            [ 'trendyol_price_rule_vw_enabled',       __( 'Enable Volumetric Weight Adjustment',         'woo-trendyol' ), 'render_field_price_vw_toggle'    ],
            [ 'trendyol_price_rule_vw_under_1',       __( 'Volumetric Weight < 1: Add Amount',           'woo-trendyol' ), 'render_field_price_vw_under_1'   ],
            [ 'trendyol_price_rule_vw_1_to_2',       __( 'Volumetric Weight 1 to 2: Add Amount',         'woo-trendyol' ), 'render_field_price_vw_1_to_2'   ],
            [ 'trendyol_price_rule_vw_2_to_3',       __( 'Volumetric Weight 2 to 3: Add Amount',         'woo-trendyol' ), 'render_field_price_vw_2_to_3'   ],
            [ 'trendyol_price_rule_vw_over_3_fixed',  __( 'Volumetric Weight > 3: Base Fixed Amount',     'woo-trendyol' ), 'render_field_price_vw_over_3_fixed' ],
            [ 'trendyol_price_rule_vw_over_3_coef',   __( 'Volumetric Weight > 3: Coefficient (per kg)',   'woo-trendyol' ), 'render_field_price_vw_over_3_coef'  ],
            [ 'trendyol_price_rule_vw_zero_dimensions_amount', __( 'Zero Dimensions Fixed Amount',     'woo-trendyol' ), 'render_field_price_vw_zero_dimensions' ],
            [ 'trendyol_price_rule_min_bulk_push_price', __( 'Minimum Price for Bulk Push',                 'woo-trendyol' ), 'render_field_price_min_bulk_push' ],
        ];

        foreach ( $price_fields as [ $id, $label, $callback ] ) {
            add_settings_field( $id, $label, [ $this, $callback ], 'woo-trendyol-settings', 'woo_trendyol_price_rules_section', [ 'label_for' => $id ] );
        }
    }

    /**
     * Sanitize a global attribute option value.
     *
     * Accepts three value types:
     *  1. The sentinel string '__wc_brands__' — indicates the WooCommerce Brands
     *     taxonomy (product_brand) should be used as the brand source.
     *  2. A pa_* attribute slug (e.g. 'pa_brand') — a plain string.
     *  3. A JSON-encoded value map (e.g. for gender/age maps) — decoded and
     *     re-encoded to ensure structural validity.
     *
     * @since  1.0.0
     * @param  mixed $value Raw option value.
     * @return string Sanitized value.
     */
    public function sanitize_attr_option( $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $trimmed = trim( wp_unslash( $value ) );

        // Allow the WC Brands sentinel value through without modification.
        if ( '__wc_brands__' === $trimmed ) {
            return '__wc_brands__';
        }

        // If it looks like JSON, decode → re-encode to ensure it is valid.
        if ( str_starts_with( $trimmed, '{' ) || str_starts_with( $trimmed, '[' ) ) {
            $decoded = json_decode( $trimmed, true );
            if ( is_array( $decoded ) ) {
                return wp_json_encode( $decoded );
            }
            return '';
        }

        // Plain string (pa_* slug or empty).
        return sanitize_text_field( $trimmed );
    }

    /**
     * Render the settings page by including its tabbed partial template.
     *
     * Reads the 'tab' query parameter from the URL to determine which tab is
     * active. Falls back to 'credentials' if the parameter is absent or invalid.
     * The $active_tab variable is injected into the partial so it can render
     * the correct tab content and highlight the correct nav-tab link.
     *
     * @since 1.0.0
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'woo-trendyol' ) );
        }

        // Determine the active tab. Whitelist the allowed slugs.
        $allowed_tabs = [ 'credentials', 'defaults', 'attributes', 'sync', 'price_rules', 'tools' ];
        $active_tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'credentials'; // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
            $active_tab = 'credentials';
        }

        $is_active = $this->api->is_active();

        include WOO_TRENDYOL_PATH . 'admin/partials/woo-trendyol-admin-settings.php';
    }

    // -----------------------------------------------------------------------
    // Section description renderers
    // -----------------------------------------------------------------------

    /**
     * Render the API section description.
     *
     * @since 1.0.0
     */
    public function render_api_section_description(): void {
        echo '<p>' . wp_kses_post(
            sprintf(
                /* translators: %s: link to Trendyol developer docs */
                __( 'Enter your Trendyol Seller API credentials. Find these in the <a href="%s" target="_blank" rel="noopener">Trendyol Seller Panel → Integration Settings</a>. All prices are submitted in <strong>EUR</strong> (International/Greek marketplace).', 'woo-trendyol' ),
                'https://partner.trendyol.com'
            )
        ) . '</p>';
    }

    /**
     * Render the Product Defaults section description.
     *
     * @since 1.0.0
     */
    public function render_defaults_section_description(): void {
        echo '<p>' . esc_html__( 'Default values applied to all products pushed to Trendyol. Individual products can override these via product meta.', 'woo-trendyol' ) . '</p>';
    }

    /**
     * Render the Global Attributes section description.
     *
     * @since 1.0.0
     */
    public function render_attrs_section_description(): void {
        echo '<p>' . esc_html__( 'Map WooCommerce attributes to their Trendyol equivalents. These global mappings are applied automatically across all categories. Dynamic attributes appearing as required in 2 or more categories are listed below.', 'woo-trendyol' ) . '</p>';
        echo '<p>' . wp_kses_post( __( 'First select the WooCommerce attribute, then map each Trendyol value to one or more of your WooCommerce terms.', 'woo-trendyol' ) ) . '</p>';
        echo '<div class="wt-attr-category-loader" style="margin-bottom: 20px;">';
        echo '<button type="button" class="button button-primary" id="wt-load-all-mapped-attr-values">' . esc_html__( 'Load/Refresh Global Attributes', 'woo-trendyol' ) . '</button>';
        echo '<span id="wt-attr-load-spinner" class="spinner" style="float:none;margin-top:0;"></span>';
        echo '<p class="description">' . esc_html__( 'Click to scan all mapped WooCommerce categories and dynamically discover required attributes. Then, load their values to map.', 'woo-trendyol' ) . '</p>';
        echo '</div>';
    }

    // -----------------------------------------------------------------------
    // Settings field renderers — API section
    // -----------------------------------------------------------------------

    /**
     * Render the Enable Integration toggle field.
     *
     * @since 1.0.0
     */
    public function render_field_toggle(): void {
        $value = get_option( 'trendyol_api_active', 'no' );
        ?>
        <label class="wt-toggle-switch">
            <input type="checkbox"
                   id="trendyol_api_active"
                   name="trendyol_api_active"
                   value="yes"
                   <?php checked( $value, 'yes' ); ?> />
            <span class="wt-toggle-slider"></span>
        </label>
        <span class="wt-toggle-label">
            <?php echo 'yes' === $value
                ? '<span class="wt-badge wt-badge--success">' . esc_html__( 'Active', 'woo-trendyol' ) . '</span>'
                : '<span class="wt-badge wt-badge--inactive">' . esc_html__( 'Inactive', 'woo-trendyol' ) . '</span>'; ?>
        </span>
        <?php
    }

    /**
     * Render Price Rules section description.
     */
    public function render_price_rules_section_description(): void {
        echo '<p class="description">' . esc_html__( 'Configure global pricing adjustment rules for products sent to Trendyol. Check the switches to activate and configure specific adjustments. These rules will be applied globally, but can be overridden at the product or category level.', 'woo-trendyol' ) . '</p>';
    }

    public function render_field_price_fixed_toggle(): void {
        $value = get_option( 'trendyol_price_rule_fixed_enabled', 'no' );
        ?>
        <label class="wt-toggle-switch">
            <input type="checkbox"
                   id="trendyol_price_rule_fixed_enabled"
                   name="trendyol_price_rule_fixed_enabled"
                   value="yes"
                   <?php checked( $value, 'yes' ); ?> />
            <span class="wt-toggle-slider"></span>
        </label>
        <?php
    }

    public function render_field_price_fixed_amount(): void {
        $value = get_option( 'trendyol_price_rule_fixed_amount', '0' );
        echo '<input type="number" step="0.01" id="trendyol_price_rule_fixed_amount" name="trendyol_price_rule_fixed_amount" value="' . esc_attr( $value ) . '" class="small-text" /> EUR';
    }

    public function render_field_price_pct_toggle(): void {
        $value = get_option( 'trendyol_price_rule_percentage_enabled', 'no' );
        ?>
        <label class="wt-toggle-switch">
            <input type="checkbox"
                   id="trendyol_price_rule_percentage_enabled"
                   name="trendyol_price_rule_percentage_enabled"
                   value="yes"
                   <?php checked( $value, 'yes' ); ?> />
            <span class="wt-toggle-slider"></span>
        </label>
        <?php
    }

    public function render_field_price_pct_amount(): void {
        $value = get_option( 'trendyol_price_rule_percentage', '0' );
        echo '<input type="number" step="0.01" id="trendyol_price_rule_percentage" name="trendyol_price_rule_percentage" value="' . esc_attr( $value ) . '" class="small-text" /> %';
    }

    public function render_field_price_vw_toggle(): void {
        $value = get_option( 'trendyol_price_rule_vw_enabled', 'no' );
        ?>
        <label class="wt-toggle-switch">
            <input type="checkbox"
                   id="trendyol_price_rule_vw_enabled"
                   name="trendyol_price_rule_vw_enabled"
                   value="yes"
                   <?php checked( $value, 'yes' ); ?> />
            <span class="wt-toggle-slider"></span>
        </label>
        <?php
    }

    public function render_field_price_vw_under_1(): void {
        $value = get_option( 'trendyol_price_rule_vw_under_1', '0' );
        echo '<input type="number" step="0.01" id="trendyol_price_rule_vw_under_1" name="trendyol_price_rule_vw_under_1" value="' . esc_attr( $value ) . '" class="small-text" /> EUR';
    }

    public function render_field_price_vw_1_to_2(): void {
        $value = get_option( 'trendyol_price_rule_vw_1_to_2', '0' );
        echo '<input type="number" step="0.01" id="trendyol_price_rule_vw_1_to_2" name="trendyol_price_rule_vw_1_to_2" value="' . esc_attr( $value ) . '" class="small-text" /> EUR';
    }

    public function render_field_price_vw_2_to_3(): void {
        $value = get_option( 'trendyol_price_rule_vw_2_to_3', '0' );
        echo '<input type="number" step="0.01" id="trendyol_price_rule_vw_2_to_3" name="trendyol_price_rule_vw_2_to_3" value="' . esc_attr( $value ) . '" class="small-text" /> EUR';
    }

    public function render_field_price_vw_over_3_fixed(): void {
        $value = get_option( 'trendyol_price_rule_vw_over_3_fixed', '0' );
        echo '<input type="number" step="0.01" id="trendyol_price_rule_vw_over_3_fixed" name="trendyol_price_rule_vw_over_3_fixed" value="' . esc_attr( $value ) . '" class="small-text" /> EUR';
    }

    public function render_field_price_vw_over_3_coef(): void {
        $value = get_option( 'trendyol_price_rule_vw_over_3_coef', '0' );
        echo '<input type="number" step="0.01" id="trendyol_price_rule_vw_over_3_coef" name="trendyol_price_rule_vw_over_3_coef" value="' . esc_attr( $value ) . '" class="small-text" /> EUR/kg';
    }

    public function render_field_price_vw_zero_dimensions(): void {
        $value = get_option( 'trendyol_price_rule_vw_zero_dimensions_amount', '0' );
        echo '<input type="number" step="0.01" id="trendyol_price_rule_vw_zero_dimensions_amount" name="trendyol_price_rule_vw_zero_dimensions_amount" value="' . esc_attr( $value ) . '" class="small-text" /> EUR';
    }

    public function render_field_price_min_bulk_push(): void {
        $value = get_option( 'trendyol_price_rule_min_bulk_push_price', '' );
        echo '<input type="number" step="0.01" id="trendyol_price_rule_min_bulk_push_price" name="trendyol_price_rule_min_bulk_push_price" value="' . esc_attr( $value ) . '" class="small-text" /> EUR';
        echo '<p class="description">' . esc_html__( 'If set, products with a calculated Trendyol price less than this amount will be skipped during Bulk Push. They can still be pushed individually from their product edit page.', 'woo-trendyol' ) . '</p>';
    }

    /**
     * Render a standard text input field.
     *
     * @since 1.0.0
     * @param array $args Field arguments (label_for = option name).
     */
    public function render_field_text( array $args ): void {
        $option = $args['label_for'];
        $value  = get_option( $option, '' );
        printf(
            '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
            esc_attr( $option ),
            esc_attr( $value )
        );
    }

    /**
     * Render a password input field with a show/hide toggle.
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_password( array $args ): void {
        $option = $args['label_for'];
        $value  = get_option( $option, '' );
        printf(
            '<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text wt-secret-field" autocomplete="new-password" />
             <button type="button" class="button wt-reveal-secret" data-target="%1$s">%3$s</button>',
            esc_attr( $option ),
            esc_attr( $value ),
            esc_html__( 'Show', 'woo-trendyol' )
        );
    }

    /**
     * Render a number input field.
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_number( array $args ): void {
        $option = $args['label_for'];
        $value  = (int) get_option( $option, 15 );
        printf(
            '<input type="number" id="%1$s" name="%1$s" value="%2$d" min="5" max="1440" class="small-text" /> %3$s',
            esc_attr( $option ),
            $value,
            esc_html__( 'minutes (minimum 5)', 'woo-trendyol' )
        );
    }

    // -----------------------------------------------------------------------
    // Settings field renderers — Product Defaults section
    // -----------------------------------------------------------------------

    /**
     * Render the Barcode Source field.
     *
     * Allows the user to choose which WooCommerce data is used as the Trendyol
     * barcode (EAN/GTIN) for each product. Four modes are available:
     *
     *  1. WooCommerce SKU (default / legacy) — uses $product->get_sku().
     *     Simple and works for stores that already use SKU as barcode.
     *
     *  2. WooCommerce Global Unique ID (_global_unique_id) — the official GTIN
     *     / EAN / ISBN field introduced in WooCommerce 9.2. Displayed in the
     *     product edit screen under "Product data → General → GTIN, UPC, EAN..."
     *     Falls back to SKU if the meta is empty for a given product.
     *
     *  3. Custom post meta key — any post meta key the user specifies. Useful
     *     for stores that store barcodes in a custom field added by another
     *     plugin (e.g. ACF, Pods, or a custom import script).
     *     Falls back to SKU if the meta is empty for a given product.
     *
     *  4. WooCommerce product attribute — reads the first term value of a
     *     specified pa_* attribute taxonomy or a custom (non-taxonomy)
     *     attribute. Useful when barcodes are stored as a product attribute
     *     rather than post meta.
     *     Falls back to SKU if the attribute is empty for a given product.
     *
     * The conditional sub-fields (meta key input, attribute slug input) are
     * shown/hidden via JavaScript based on the selected radio option.
     *
     * @since  1.0.0
     * @param  array $args Field arguments passed by add_settings_field().
     */
    public function render_field_barcode_source( array $args ): void {
        $source   = get_option( 'trendyol_barcode_source', 'sku' );
        $meta_key = get_option( 'trendyol_barcode_meta_key', '' );
        $attr_slug = get_option( 'trendyol_barcode_attr_slug', '' );

        /*
         * Build the list of all WooCommerce product attribute taxonomies so the
         * user can pick one from a dropdown instead of typing a slug manually.
         */
        $wc_attributes = wc_get_attribute_taxonomies(); // Returns WC_Product_Attribute objects.

        // Detect whether the _global_unique_id field is available (WC >= 9.2).
        $gtin_available = function_exists( 'wc_get_product_id_by_global_unique_id' )
            || ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.2', '>=' ) );
        ?>
        <fieldset class="wt-barcode-source-fieldset">

            <!-- Option 1: WooCommerce SKU -->
            <label class="wt-radio-label">
                <input type="radio"
                       name="trendyol_barcode_source"
                       value="sku"
                       class="wt-barcode-radio"
                       <?php checked( $source, 'sku' ); ?> />
                <strong><?php esc_html_e( 'WooCommerce SKU', 'woo-trendyol' ); ?></strong>
                <span class="description">
                    &mdash; <?php esc_html_e( 'Uses the product SKU field (get_sku()). Default and recommended for most stores.', 'woo-trendyol' ); ?>
                </span>
            </label>

            <!-- Option 2: Global Unique ID (GTIN/EAN) -->
            <label class="wt-radio-label">
                <input type="radio"
                       name="trendyol_barcode_source"
                       value="global_unique_id"
                       class="wt-barcode-radio"
                       <?php checked( $source, 'global_unique_id' ); ?>
                       <?php disabled( ! $gtin_available ); ?> />
                <strong><?php esc_html_e( 'WooCommerce Global Unique ID (GTIN / EAN / ISBN)', 'woo-trendyol' ); ?></strong>
                <?php if ( $gtin_available ) : ?>
                    <span class="wt-badge wt-badge--success"><?php esc_html_e( 'Available', 'woo-trendyol' ); ?></span>
                    <span class="description">
                        &mdash; <?php esc_html_e( 'Uses the _global_unique_id meta field (WooCommerce ≥ 9.2). Falls back to SKU if empty.', 'woo-trendyol' ); ?>
                    </span>
                <?php else : ?>
                    <span class="wt-badge wt-badge--warning"><?php esc_html_e( 'Requires WooCommerce ≥ 9.2', 'woo-trendyol' ); ?></span>
                <?php endif; ?>
            </label>

            <!-- Option 3: Custom post meta key -->
            <label class="wt-radio-label">
                <input type="radio"
                       name="trendyol_barcode_source"
                       value="meta"
                       class="wt-barcode-radio"
                       <?php checked( $source, 'meta' ); ?> />
                <strong><?php esc_html_e( 'Custom Post Meta Key', 'woo-trendyol' ); ?></strong>
                <span class="description">
                    &mdash; <?php esc_html_e( 'Reads barcode from any post meta key. Falls back to SKU if empty.', 'woo-trendyol' ); ?>
                </span>
            </label>
            <div class="wt-barcode-sub-field" id="wt-barcode-meta-row"
                 style="<?php echo 'meta' === $source ? '' : 'display:none;'; ?>">
                <label for="trendyol_barcode_meta_key" class="wt-sub-label">
                    <?php esc_html_e( 'Meta Key:', 'woo-trendyol' ); ?>
                </label>
                <input type="text"
                       id="trendyol_barcode_meta_key"
                       name="trendyol_barcode_meta_key"
                       value="<?php echo esc_attr( $meta_key ); ?>"
                       class="regular-text"
                       placeholder="<?php esc_attr_e( 'e.g. _barcode or my_ean_field', 'woo-trendyol' ); ?>" />
                <p class="description">
                    <?php esc_html_e( 'Enter the exact post meta key where the barcode is stored. Include the leading underscore if present.', 'woo-trendyol' ); ?>
                </p>
            </div>

            <!-- Option 4: WooCommerce product attribute -->
            <label class="wt-radio-label">
                <input type="radio"
                       name="trendyol_barcode_source"
                       value="attribute"
                       class="wt-barcode-radio"
                       <?php checked( $source, 'attribute' ); ?> />
                <strong><?php esc_html_e( 'WooCommerce Product Attribute', 'woo-trendyol' ); ?></strong>
                <span class="description">
                    &mdash; <?php esc_html_e( 'Reads barcode from a product attribute. Falls back to SKU if empty.', 'woo-trendyol' ); ?>
                </span>
            </label>
            <div class="wt-barcode-sub-field" id="wt-barcode-attr-row"
                 style="<?php echo 'attribute' === $source ? '' : 'display:none;'; ?>">
                <label for="trendyol_barcode_attr_slug" class="wt-sub-label">
                    <?php esc_html_e( 'Attribute:', 'woo-trendyol' ); ?>
                </label>
                <?php if ( ! empty( $wc_attributes ) ) : ?>
                    <select id="trendyol_barcode_attr_slug" name="trendyol_barcode_attr_slug">
                        <option value=""><?php esc_html_e( '&mdash; Select attribute &mdash;', 'woo-trendyol' ); ?></option>
                        <?php foreach ( $wc_attributes as $attr ) : ?>
                            <option value="<?php echo esc_attr( wc_attribute_taxonomy_name( $attr->attribute_name ) ); ?>"
                                    <?php selected( $attr_slug, wc_attribute_taxonomy_name( $attr->attribute_name ) ); ?>>
                                <?php echo esc_html( $attr->attribute_label ); ?>
                                (<?php echo esc_html( wc_attribute_taxonomy_name( $attr->attribute_name ) ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input type="text"
                           id="trendyol_barcode_attr_slug"
                           name="trendyol_barcode_attr_slug"
                           value="<?php echo esc_attr( $attr_slug ); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'e.g. pa_barcode', 'woo-trendyol' ); ?>" />
                    <p class="description">
                        <?php esc_html_e( 'No product attributes found. Enter the attribute slug manually (e.g. pa_barcode).', 'woo-trendyol' ); ?>
                    </p>
                <?php endif; ?>
            </div>

        </fieldset>
        <?php
    }

    /**
     * Render the Cargo Company ID field with a live-fetch button.
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_cargo_company( array $args ): void {
        $value = get_option( 'trendyol_default_cargo_company_id', '' );
        printf(
            '<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text" min="1" />
             <button type="button" class="button wt-fetch-cargo-companies">%3$s</button>
             <div id="wt-cargo-list" class="wt-inline-list" style="display:none;"></div>
             <p class="description">%4$s</p>',
            esc_attr( $args['label_for'] ),
            esc_attr( $value ),
            esc_html__( 'Fetch Available Companies', 'woo-trendyol' ),
            esc_html__( 'Numeric Trendyol cargo company ID. Click "Fetch" to see available options.', 'woo-trendyol' )
        );
    }

    /**
     * Render the VAT Rate select field.
     *
     * Trendyol accepts: 0, 1, 8, 18.
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_vat_rate( array $args ): void {
        $current = (int) get_option( 'trendyol_default_vat_rate', 24 );
        $rates   = [ 0, 1, 8, 18, 24 ];
        echo '<select id="' . esc_attr( $args['label_for'] ) . '" name="trendyol_default_vat_rate">';
        foreach ( $rates as $rate ) {
            printf(
                '<option value="%1$d" %2$s>%1$d%%</option>',
                $rate,
                selected( $current, $rate, false )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Default VAT rate applied to all products. Greece standard rate is 24%.', 'woo-trendyol' ) . '</p>';
    }

    /**
     * Render the Handling Time field.
     *
     * Offers two modes:
     *  - Fixed: a number of days entered directly.
     *  - WC Attribute: reads the value from a WooCommerce product attribute slug.
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_handling_time( array $args ): void {
        $type    = get_option( 'trendyol_handling_time_type',    'fixed' );
        $days    = (int) get_option( 'trendyol_handling_time_days',    3 );
        $wc_attr = get_option( 'trendyol_handling_time_wc_attr', '' );

        // Build WC attribute list for the dropdown.
        $wc_attributes = wc_get_attribute_taxonomies();
        ?>
        <fieldset>
            <label>
                <input type="radio" name="trendyol_handling_time_type" value="fixed"
                    <?php checked( $type, 'fixed' ); ?> class="wt-handling-type" />
                <?php esc_html_e( 'Fixed number of days:', 'woo-trendyol' ); ?>
                <input type="number" id="trendyol_handling_time_days"
                       name="trendyol_handling_time_days"
                       value="<?php echo esc_attr( $days ); ?>"
                       min="1" max="30" class="small-text"
                       <?php echo 'fixed' !== $type ? 'disabled' : ''; ?> />
            </label>
            <br /><br />
            <label>
                <input type="radio" name="trendyol_handling_time_type" value="attribute"
                    <?php checked( $type, 'attribute' ); ?> class="wt-handling-type" />
                <?php esc_html_e( 'Read from WooCommerce attribute:', 'woo-trendyol' ); ?>
                <select id="trendyol_handling_time_wc_attr"
                        name="trendyol_handling_time_wc_attr"
                        <?php echo 'attribute' !== $type ? 'disabled' : ''; ?>>
                    <option value=""><?php esc_html_e( '— Select attribute —', 'woo-trendyol' ); ?></option>
                    <?php foreach ( $wc_attributes as $attr ) : ?>
                        <option value="pa_<?php echo esc_attr( $attr->attribute_name ); ?>"
                            <?php selected( $wc_attr, 'pa_' . $attr->attribute_name ); ?>>
                            <?php echo esc_html( $attr->attribute_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </fieldset>
        <p class="description">
            <?php esc_html_e( 'Handling time is the number of business days before the order is shipped. When using a WC attribute, the attribute value must be a plain integer.', 'woo-trendyol' ); ?>
        </p>
        <?php
    }

    // -----------------------------------------------------------------------
    // Settings field renderers — Global Attribute Mappings section
    // -----------------------------------------------------------------------

    /**
     * Build a sorted list of all WooCommerce product attribute taxonomies.
     *
     * @since  1.0.0
     * @access private
     * @return array[] Array of objects with attribute_name and attribute_label.
     */
    private function get_wc_attribute_list(): array {
        $attrs = wc_get_attribute_taxonomies();
        usort( $attrs, static fn( $a, $b ) => strcmp( $a->attribute_label, $b->attribute_label ) );
        return $attrs;
    }

    /**
     * Render a WC attribute selector + value mapping table for a global slot.
     *
     * The selector lets the user choose which WooCommerce attribute holds the values.
     * Once a Trendyol category is loaded via the "Load Trendyol Values" button, a
     * mapping table is rendered by JS: each Trendyol value row shows checkboxes for
     * every WC term in the selected attribute.
     *
     * @since  1.0.0
     * @access private
     * @param  string $slot        'gender' or 'age'.
     * @param  string $wc_opt      Option key for the WC attribute slug.
     * @param  string $map_opt     Option key for the JSON value map.
     * @param  string $description Human-readable description for the field.
     */
    private function render_attr_mapping_field(
        string $slot,
        string $wc_opt,
        string $map_opt,
        string $description,
        bool $allow_custom = false,
        bool $no_predefined_values = false
    ): void {
        $current_wc  = get_option( $wc_opt,  '' );
        $current_map = get_option( $map_opt, '' );
        $wc_attrs    = $this->get_wc_attribute_list();

        // Decode the saved map so JS can pre-populate checkboxes.
        $map_decoded = [];
        if ( ! empty( $current_map ) ) {
            $decoded = json_decode( $current_map, true );
            if ( is_array( $decoded ) ) {
                $map_decoded = $decoded;
            }
        }

        // Fetch names cache to display names instead of just IDs.
        $names_cache = get_option( 'trendyol_global_attr_names_cache', [] );
        if ( ! is_array( $names_cache ) ) {
            $names_cache = [];
        }

        // Fetch WC terms for the currently selected attribute (for pre-rendering).
        $wc_terms = [];
        if ( $current_wc ) {
            $terms = get_terms( [ 'taxonomy' => $current_wc, 'hide_empty' => false ] );
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $wc_terms[] = [ 'slug' => $term->slug, 'name' => $term->name ];
                }
            }
        }
        ?>
        <div class="wt-attr-mapping-field" data-slot="<?php echo esc_attr( $slot ); ?>">

            <!-- Step 1: WC attribute selector -->
            <div class="wt-attr-mapping-step">
                <label class="wt-attr-mapping-label">
                    <strong><?php esc_html_e( 'WooCommerce attribute:', 'woo-trendyol' ); ?></strong>
                </label>
                <select id="<?php echo esc_attr( $wc_opt ); ?>"
                        name="<?php echo esc_attr( $wc_opt ); ?>"
                        class="wt-wc-attr-selector"
                        data-slot="<?php echo esc_attr( $slot ); ?>">
                    <option value=""><?php esc_html_e( '— Select Attribute Source —', 'woo-trendyol' ); ?></option>

                    <?php if ( ! empty( $wc_attrs ) ) : ?>
                        <optgroup label="<?php esc_attr_e( 'WooCommerce Product Attributes (pa_*)', 'woo-trendyol' ); ?>">
                            <?php foreach ( $wc_attrs as $attr ) : ?>
                                <option value="pa_<?php echo esc_attr( $attr->attribute_name ); ?>"
                                    <?php selected( $current_wc, 'pa_' . $attr->attribute_name ); ?>>
                                    <?php echo esc_html( $attr->attribute_label ); ?>
                                    (pa_<?php echo esc_attr( $attr->attribute_name ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>

                    <optgroup label="<?php esc_attr_e( 'Product Dimensions & Physical Attributes', 'woo-trendyol' ); ?>">
                        <option value="dim_length" <?php selected( $current_wc, 'dim_length' ); ?>><?php esc_html_e( 'Product Length (cm)', 'woo-trendyol' ); ?></option>
                        <option value="dim_width" <?php selected( $current_wc, 'dim_width' ); ?>><?php esc_html_e( 'Product Width (cm)', 'woo-trendyol' ); ?></option>
                        <option value="dim_height" <?php selected( $current_wc, 'dim_height' ); ?>><?php esc_html_e( 'Product Height (cm)', 'woo-trendyol' ); ?></option>
                        <option value="dim_weight" <?php selected( $current_wc, 'dim_weight' ); ?>><?php esc_html_e( 'Product Weight (kg)', 'woo-trendyol' ); ?></option>
                    </optgroup>

                    <optgroup label="<?php esc_attr_e( 'Product Custom Meta / Post Meta', 'woo-trendyol' ); ?>">
                        <?php if ( $current_wc && 0 === strpos( $current_wc, 'meta:' ) ) : ?>
                            <option value="<?php echo esc_attr( $current_wc ); ?>" selected="selected">
                                <?php echo esc_html( sprintf( __( 'Custom Meta: %s', 'woo-trendyol' ), substr( $current_wc, 5 ) ) ); ?>
                            </option>
                        <?php endif; ?>
                        <option value="custom_meta_prompt"><?php esc_html_e( '+ Enter Custom Meta Key...', 'woo-trendyol' ); ?></option>
                    </optgroup>
                </select>
                <p class="description"><?php echo wp_kses_post( $description ); ?></p>
            </div>

            <!-- Step 2: Value mapping table (rendered/updated by JS) -->
            <div class="wt-attr-mapping-table-wrap" id="wt-mapping-table-<?php echo esc_attr( $slot ); ?>">
                <?php if ( $no_predefined_values ) : ?>
                    <p class="description" style="color: #007cba; margin-top: 15px;">
                        <?php esc_html_e( 'This attribute accepts free text. No value mapping is required; terms from your selected WooCommerce attribute will be sent exactly as they are.', 'woo-trendyol' ); ?>
                    </p>
                    <input type="hidden"
                           name="<?php echo esc_attr( $map_opt ); ?>"
                           id="wt-map-hidden-<?php echo esc_attr( $slot ); ?>"
                           value="<?php echo esc_attr( $current_map ); ?>" />
                <?php elseif ( ! empty( $wc_terms ) && ! empty( $map_decoded ) && is_array( $map_decoded ) ) : 
                    $normalized_map = [];
                    $first_val = reset( $map_decoded );
                    if ( ! is_array( $first_val ) ) {
                        // Old Format: { term_slug: ty_val_id }
                        foreach ( $map_decoded as $term_slug => $ty_val_id ) {
                            if ( empty( $ty_val_id ) ) { continue; }
                            $ty_id_str = (string) $ty_val_id;
                            if ( ! isset( $normalized_map[ $ty_id_str ] ) ) {
                                $normalized_map[ $ty_id_str ] = [];
                            }
                            if ( ! in_array( (string) $term_slug, $normalized_map[ $ty_id_str ], true ) ) {
                                $normalized_map[ $ty_id_str ][] = (string) $term_slug;
                            }
                        }
                    } else {
                        // Format: { ty_val_id: [ term_slugs ] }
                        foreach ( $map_decoded as $ty_val_id => $slugs ) {
                            $ty_id_str = (string) $ty_val_id;
                            $normalized_map[ $ty_id_str ] = array_map( 'strval', (array) $slugs );
                        }
                    }
                ?>
                    <table class="wt-mapping-table widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Trendyol Value', 'woo-trendyol' ); ?></th>
                                <th><?php esc_html_e( 'WooCommerce Terms', 'woo-trendyol' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $normalized_map as $ty_value_id => $mapped_slugs ) : ?>
                                <?php
                                $ty_name = $names_cache[ $ty_value_id ] ?? $ty_value_id;
                                ?>
                                <tr class="wt-mapping-row" data-ty-value-id="<?php echo esc_attr( $ty_value_id ); ?>">
                                    <td class="wt-ty-value-label">
                                        <span class="wt-ty-id-badge"><?php echo esc_html( $ty_name ); ?></span>
                                        <small class="wt-ty-id-num"> #<?php echo esc_html( $ty_value_id ); ?></small>
                                    </td>
                                    <td>
                                        <?php foreach ( $wc_terms as $term ) : ?>
                                            <label class="wt-term-checkbox">
                                                <input type="checkbox"
                                                    value="<?php echo esc_attr( $term['slug'] ); ?>"
                                                    <?php checked( in_array( $term['slug'], (array) $mapped_slugs, true ) ); ?> />
                                                <?php echo esc_html( $term['name'] ); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <!-- Hidden field carries the serialised map for saving -->
                    <input type="hidden"
                           name="<?php echo esc_attr( $map_opt ); ?>"
                           id="wt-map-hidden-<?php echo esc_attr( $slot ); ?>"
                           value="<?php echo esc_attr( $current_map ); ?>" />
                <?php else : ?>
                    <p class="wt-mapping-placeholder description">
                        <?php esc_html_e( 'Enter a Trendyol category ID above and click "Load Trendyol Values" to build the mapping table.', 'woo-trendyol' ); ?>
                    </p>
                    <input type="hidden"
                           name="<?php echo esc_attr( $map_opt ); ?>"
                           id="wt-map-hidden-<?php echo esc_attr( $slot ); ?>"
                           value="<?php echo esc_attr( $current_map ); ?>" />
                <?php endif; ?>
            </div>

        </div>
        <?php
    }

    /**
     * Render the Gender WC attribute selector and value mapping table.
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_global_gender( array $args ): void {
        $this->render_attr_mapping_field(
            'gender',
            'trendyol_global_attr_gender_wc',
            'trendyol_global_attr_gender_map',
            __( 'Select the WooCommerce attribute that stores gender (e.g. pa_gender). Each Trendyol gender value will then be mapped to one or more of its terms.', 'woo-trendyol' )
        );
    }

    /**
     * Render the Age Group WC attribute selector and value mapping table.
     *
     * Supports many-to-one mapping: multiple WC age terms (e.g. "από 3 ετών",
     * "από 4 ετών") can be checked for a single Trendyol age value (e.g. "3-4 Yaş").
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_global_age( array $args ): void {
        $this->render_attr_mapping_field(
            'age',
            'trendyol_global_attr_age_wc',
            'trendyol_global_attr_age_map',
            __( 'Select the WooCommerce attribute that stores Age (e.g. pa_age). Multiple WC terms can map to a single Trendyol age value.', 'woo-trendyol' )
        );
    }

    /**
     * Render the Age Group WC attribute selector and value mapping table.
     *
     * Supports many-to-one mapping.
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_global_age_group( array $args ): void {
        $this->render_attr_mapping_field(
            'age_group',
            'trendyol_global_attr_age_group_wc',
            'trendyol_global_attr_age_group_map',
            __( 'Select the WooCommerce attribute that stores Age Group (e.g. pa_age_group). Multiple WC terms can map to a single Trendyol age value — useful for granular age ranges like "από 3 ετών" and "από 4 ετών" mapping to one Trendyol bracket.', 'woo-trendyol' )
        );
    }

    /**
     * Render the Color WC attribute selector and value mapping table.
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_global_color( array $args ): void {
        $this->render_attr_mapping_field(
            'color',
            'trendyol_global_attr_color_wc',
            'trendyol_global_attr_color_map',
            __( 'Select the WooCommerce attribute that stores color (e.g. pa_color). Each Trendyol color value will then be mapped to one or more of its terms.', 'woo-trendyol' )
        );
    }

    /**
     * Render the Custom Color WC attribute selector.
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_global_color_custom( array $args ): void {
        $this->render_attr_mapping_field(
            'color_custom',
            'trendyol_global_attr_color_custom_wc',
            'trendyol_global_attr_color_custom_map',
            __( 'Select the WooCommerce attribute that stores custom colors (e.g. pa_color). Since custom values are allowed, you do not need to map values; the WooCommerce term name will be sent directly.', 'woo-trendyol' ),
            true,
            true
        );
    }

    /**
     * Render the Brand source selector for the global attribute mapping.
     *
     * The selector offers three tiers of brand sources:
     *
     *  1. WooCommerce Brands taxonomy (product_brand) — shown only when the
     *     taxonomy is registered, i.e. WooCommerce ≥ 9.4 with Brands enabled
     *     or the legacy WooCommerce Brands premium plugin is active.
     *     The special value "__wc_brands__" is stored in the option to
     *     distinguish this source from a pa_* attribute slug.
     *
     *  2. WooCommerce product attributes (pa_*) — the full list of taxonomies
     *     registered via Products → Attributes.
     *
     *  3. Empty (no mapping) — Trendyol brand will be resolved from the
     *     product-level override or the generic fallback chain.
     *
     * @since 1.0.0
     * @param array $args Field arguments passed by add_settings_field().
     */
    public function render_field_global_brand( array $args ): void {
        $current       = get_option( 'trendyol_global_attr_brand_wc', '' );
        $wc_attributes = wc_get_attribute_taxonomies();

        /*
         * Detect whether the WooCommerce Brands taxonomy is available.
         * taxonomy_exists() returns true when:
         *  - WooCommerce ≥ 9.4 with the Brands feature flag enabled, OR
         *  - The legacy WooCommerce Brands premium plugin is active.
         * Both register the same 'product_brand' taxonomy slug.
         */
        $wc_brands_available = taxonomy_exists( 'product_brand' );

        echo '<select id="' . esc_attr( $args['label_for'] ) . '" name="trendyol_global_attr_brand_wc" class="wt-brand-source-select">';
        echo '<option value="">' . esc_html__( '— No brand mapping —', 'woo-trendyol' ) . '</option>';

        // ---- Option group 1: WooCommerce Brands taxonomy ----
        if ( $wc_brands_available ) {
            /*
             * WC Brands is active. Offer it as the first and recommended option.
             * We store the sentinel value '__wc_brands__' so the resolver can
             * distinguish this from a pa_* attribute slug.
             */
            echo '<optgroup label="' . esc_attr__( 'WooCommerce Brands (Recommended)', 'woo-trendyol' ) . '">';
            printf(
                '<option value="__wc_brands__" %s>%s</option>',
                selected( $current, '__wc_brands__', false ),
                esc_html__( 'WooCommerce Brands taxonomy (product_brand)', 'woo-trendyol' )
            );
            echo '</optgroup>';
        }

        // ---- Option group 2: WooCommerce product attributes (pa_*) ----
        if ( ! empty( $wc_attributes ) ) {
            echo '<optgroup label="' . esc_attr__( 'WooCommerce Product Attributes', 'woo-trendyol' ) . '">';
            foreach ( $wc_attributes as $attr ) {
                printf(
                    '<option value="pa_%1$s" %2$s>%3$s (pa_%1$s)</option>',
                    esc_attr( $attr->attribute_name ),
                    selected( $current, 'pa_' . $attr->attribute_name, false ),
                    esc_html( $attr->attribute_label )
                );
            }
            echo '</optgroup>';
        }

        echo '</select>';

        // ---- Status notice ----
        if ( $wc_brands_available ) {
            echo '<p class="description wt-notice wt-notice--info">';
            echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> ';
            echo esc_html__(
                'WooCommerce Brands taxonomy (product_brand) is active on this site. Select it above to use brand names from Products → Brands.',
                'woo-trendyol'
            );
            echo '</p>';
        } else {
            echo '<p class="description wt-notice wt-notice--warning">';
            echo '<span class="dashicons dashicons-info" style="color:#dba617;"></span> ';
            echo esc_html__(
                'WooCommerce Brands taxonomy is not active. Enable it in WooCommerce ≥ 9.4 or install the WooCommerce Brands plugin, then select it here. Alternatively, choose a product attribute (pa_*) that holds brand names.',
                'woo-trendyol'
            );
            echo '</p>';
        }

        // ---- Fallback chain explanation ----
        echo '<p class="description">';
        echo esc_html__(
            'Brand resolution order: (1) product-level override (_trendyol_brand_id), (2) source selected above, (3) generic pa_brand / pa_manufacturer attribute fallback.',
            'woo-trendyol'
        );
        echo '</p>';
    }

    /**
     * Render the Character / Hero WooCommerce attribute selector.
     *
     * @since 1.0.0
     * @param array $args Field arguments.
     */
    public function render_field_global_character( array $args ): void {
        $current       = get_option( 'trendyol_global_attr_character_wc', '' );
        $wc_attributes = wc_get_attribute_taxonomies();
        echo '<select id="' . esc_attr( $args['label_for'] ) . '" name="trendyol_global_attr_character_wc">';
        echo '<option value="">' . esc_html__( '— Select WC attribute —', 'woo-trendyol' ) . '</option>';
        foreach ( $wc_attributes as $attr ) {
            printf(
                '<option value="pa_%1$s" %2$s>%3$s (pa_%1$s)</option>',
                esc_attr( $attr->attribute_name ),
                selected( $current, 'pa_' . $attr->attribute_name, false ),
                esc_html( $attr->attribute_label )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'The WooCommerce attribute whose value will be sent as the Trendyol "Character/Hero/License" attribute (e.g. Barbie, Batman, Hello Kitty).', 'woo-trendyol' ) . '</p>';
    }

    // -----------------------------------------------------------------------
    // Product meta box
    // -----------------------------------------------------------------------

    /**
     * Register the Trendyol Status meta box on the product edit screen.
     *
     * Hooked to: add_meta_boxes
     *
     * HPOS note: WooCommerce products are always stored as CPT (post_type=product)
     * regardless of whether HPOS is enabled. HPOS only changes the storage backend
     * for *orders*, not products. Therefore 'product' is always the correct screen
     * ID here and no OrderUtil lookup is required.
     *
     * @since 1.0.0
     */
    public function register_product_meta_box(): void {
        add_meta_box(
            'woo-trendyol-product-status',
            __( 'Trendyol Integration', 'woo-trendyol' ),
            [ $this, 'render_product_meta_box' ],
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render the product meta box by gathering data and including the partial.
     *
     * HPOS note: The callback parameter is widened to accept any object type.
     * Per the HPOS recipe book, the passed argument should not be relied upon
     * directly; instead the order/post ID is extracted and the object is
     * re-fetched via the appropriate WC CRUD method. For the product screen
     * WordPress always passes a WP_Post, but the guard below prevents a
     * fatal error if the hook ever fires with a different argument type.
     *
     * @since 1.0.0
     * @param WP_Post|object $post_or_object The current post or order object.
     */
    public function render_product_meta_box( $post_or_object ): void {
        // Normalise to a post ID regardless of the argument type.
        if ( is_numeric( $post_or_object ) ) {
            $post_id = (int) $post_or_object;
        } elseif ( $post_or_object instanceof WP_Post ) {
            $post_id = $post_or_object->ID;
        } elseif ( is_object( $post_or_object ) && method_exists( $post_or_object, 'get_id' ) ) {
            $post_id = (int) $post_or_object->get_id();
        } else {
            return;
        }

        // Re-fetch as WP_Post to keep downstream code consistent.
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        wp_nonce_field( 'woo_trendyol_product_meta_box', 'woo_trendyol_product_nonce' );

        // ---- Sync status meta ----
        $sent        = get_post_meta( $post_id, '_trendyol_sent',        true );
        $approved    = get_post_meta( $post_id, '_trendyol_approved',    true );
        $on_sale     = get_post_meta( $post_id, '_trendyol_on_sale',     true );
        $archived    = get_post_meta( $post_id, '_trendyol_archived',    true );
        $blacklisted = get_post_meta( $post_id, '_trendyol_blacklisted', true );
        $last_sync   = get_post_meta( $post_id, '_trendyol_last_sync',   true );
        $last_price  = get_post_meta( $post_id, '_trendyol_last_price',  true );
        $last_stock  = get_post_meta( $post_id, '_trendyol_last_stock',  true );
        $sync_status = get_post_meta( $post_id, '_trendyol_sync_status', true );
        $sync_error  = get_post_meta( $post_id, '_trendyol_sync_error',  true );
        $batch_id    = get_post_meta( $post_id, '_trendyol_batch_id',    true );

        // ---- Category mapping ----
        $category_id     = $this->category_helper->get_trendyol_category_id( $post_id );
        $category_path   = $this->category_helper->get_trendyol_category_path( $post_id );
        $override        = $this->category_helper->get_override( $post_id );
        $override_active = ! empty( $override );
        $category_source = $override_active
            ? __( 'Product override', 'woo-trendyol' )
            : __( 'Category mapping', 'woo-trendyol' );

        // ---- Price override ----
        $price_override  = get_post_meta( $post_id, '_trendyol_price_override', true );

        // ---- Calculated price display & Variable product breakdown ----
        $calculated_price_display = '';
        $variations_data          = [];
        $product_obj              = wc_get_product( $post_id );

        if ( $product_obj ) {
            if ( $product_obj->is_type( 'variable' ) ) {
                $prices               = [];
                $children             = $product_obj->get_children();
                $any_var_sent         = false;
                $all_var_approved     = true;
                $any_var_approved     = false;
                $any_var_on_sale      = false;
                $any_var_archived     = false;
                $any_var_blacklisted  = false;
                $latest_var_sync      = 0;

                $has_var_approval_val = false;
                $has_var_onsale_val   = false;
                $has_var_archived_val = false;
                $all_var_archived     = true;

                foreach ( $children as $child_id ) {
                    $variation = wc_get_product( $child_id );
                    if ( ! $variation || 'trash' === $variation->get_status() ) {
                        continue;
                    }

                    $v_prices = $this->category_helper->get_final_trendyol_prices( $variation );
                    $prices[] = $v_prices['listPrice'];
                    $prices[] = $v_prices['salePrice'];

                    $var_barcode     = $this->product_creator->resolve_barcode( $variation );
                    $var_sent        = get_post_meta( $child_id, '_trendyol_sent', true );
                    $var_approved    = get_post_meta( $child_id, '_trendyol_approved', true );
                    $var_on_sale     = get_post_meta( $child_id, '_trendyol_on_sale', true );
                    $var_archived    = get_post_meta( $child_id, '_trendyol_archived', true );
                    $var_blacklisted = get_post_meta( $child_id, '_trendyol_blacklisted', true );
                    $var_last_sync   = (int) get_post_meta( $child_id, '_trendyol_last_sync', true );
                    $var_sync_status = get_post_meta( $child_id, '_trendyol_sync_status', true );
                    $var_sync_error  = get_post_meta( $child_id, '_trendyol_sync_error', true );
                    $var_batch_id    = get_post_meta( $child_id, '_trendyol_batch_id', true );

                    $var_stock = $variation->managing_stock()
                        ? $variation->get_stock_quantity()
                        : ( $product_obj->managing_stock() ? $product_obj->get_stock_quantity() : ( $variation->is_in_stock() ? __( 'In stock', 'woo-trendyol' ) : __( 'Out of stock', 'woo-trendyol' ) ) );

                    if ( 'yes' === $var_sent ) { $any_var_sent = true; }
                    if ( '' !== $var_approved ) {
                        $has_var_approval_val = true;
                        if ( 'yes' === $var_approved ) {
                            $any_var_approved = true;
                        } else {
                            $all_var_approved = false;
                        }
                    } else {
                        $all_var_approved = false;
                    }
                    if ( '' !== $var_on_sale ) {
                        $has_var_onsale_val = true;
                        if ( 'yes' === $var_on_sale ) {
                            $any_var_on_sale = true;
                        }
                    }
                    if ( '' !== $var_archived ) {
                        $has_var_archived_val = true;
                        if ( 'yes' === $var_archived ) {
                            $any_var_archived = true;
                        } else {
                            $all_var_archived = false;
                        }
                    } else {
                        $all_var_archived = false;
                    }
                    if ( 'yes' === $var_blacklisted ) { $any_var_blacklisted = true; }
                    if ( $var_last_sync > $latest_var_sync ) { $latest_var_sync = $var_last_sync; }

                    $variations_data[] = [
                        'id'          => $child_id,
                        'name'        => wc_get_formatted_variation( $variation, true, false, false ),
                        'sku'         => $variation->get_sku(),
                        'barcode'     => $var_barcode,
                        'sent'        => $var_sent,
                        'approved'    => $var_approved,
                        'on_sale'     => $var_on_sale,
                        'archived'    => $var_archived,
                        'blacklisted' => $var_blacklisted,
                        'last_sync'   => $var_last_sync,
                        'stock'       => $var_stock,
                        'list_price'  => $v_prices['listPrice'],
                        'sale_price'  => $v_prices['salePrice'],
                        'sync_status' => $var_sync_status,
                        'sync_error'  => $var_sync_error,
                        'batch_id'    => $var_batch_id,
                    ];
                }

                // For variable products, aggregate status dynamically from child variations
                if ( ! empty( $variations_data ) ) {
                    if ( $any_var_sent ) {
                        $sent = 'yes';
                    }
                    if ( $has_var_approval_val ) {
                        $approved = ( $all_var_approved && $any_var_approved ) ? 'yes' : ( $any_var_approved ? 'partial' : 'no' );
                    }
                    if ( $has_var_onsale_val ) {
                        $on_sale = $any_var_on_sale ? 'yes' : 'no';
                    }
                    if ( $has_var_archived_val ) {
                        $archived = $all_var_archived ? 'yes' : ( $any_var_archived ? 'partial' : 'no' );
                    }
                    $blacklisted = $any_var_blacklisted ? 'yes' : 'no';
                    if ( $latest_var_sync > 0 ) {
                        $last_sync = $latest_var_sync;
                    }
                }

                if ( ! empty( $prices ) ) {
                    $min_price = min( $prices );
                    $max_price = max( $prices );
                    if ( $min_price === $max_price ) {
                        $calculated_price_display = number_format( $min_price, 2 ) . ' &euro;';
                    } else {
                        $calculated_price_display = number_format( $min_price, 2 ) . ' &euro; - ' . number_format( $max_price, 2 ) . ' &euro;';
                    }
                } else {
                    $calculated_price_display = __( 'N/A', 'woo-trendyol' );
                }
            } else {
                $calculated_prices = $this->category_helper->get_final_trendyol_prices( $product_obj );
                if ( $calculated_prices['salePrice'] < $calculated_prices['listPrice'] ) {
                    $calculated_price_display = sprintf(
                        __( '%1$s &euro; (List) / %2$s &euro; (Sale)', 'woo-trendyol' ),
                        number_format( $calculated_prices['listPrice'], 2 ),
                        number_format( $calculated_prices['salePrice'], 2 )
                    );
                } else {
                    $calculated_price_display = number_format( $calculated_prices['listPrice'], 2 ) . ' &euro;';
                }
            }
        }

        // ---- Last sync human diff ----
        $last_sync_human = $last_sync
            ? sprintf(
                /* translators: %s: human-readable time diff */
                __( '%s ago', 'woo-trendyol' ),
                human_time_diff( (int) $last_sync, time() )
            )
            : __( 'Never', 'woo-trendyol' );

        include WOO_TRENDYOL_PATH . 'admin/partials/woo-trendyol-admin-product-meta.php';
    }

    /**
     * Save the category override field when the product is saved.
     *
     * Hooked to: save_post_product
     *
     * @since 1.0.0
     * @param int $post_id The product post ID.
     */
    public function save_product_meta_box( int $post_id ): void {
        if ( ! isset( $_POST['woo_trendyol_product_nonce'] )
            || ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['woo_trendyol_product_nonce'] ) ),
                'woo_trendyol_product_meta_box'
            )
        ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $override = isset( $_POST['_trendyol_category_id_override'] )
            ? sanitize_text_field( wp_unslash( $_POST['_trendyol_category_id_override'] ) )
            : '';

        $this->category_helper->save_override( $post_id, $override );

        $price_override = isset( $_POST['_trendyol_price_override'] )
            ? sanitize_text_field( wp_unslash( $_POST['_trendyol_price_override'] ) )
            : '';

        if ( '' !== $price_override ) {
            update_post_meta( $post_id, '_trendyol_price_override', $price_override );
        } else {
            delete_post_meta( $post_id, '_trendyol_price_override' );
        }
    }

    // -----------------------------------------------------------------------
    // AJAX handlers
    // -----------------------------------------------------------------------

    /**
     * Handle AJAX request to test the Trendyol API connection.
     *
     * Action: wp_ajax_trendyol_test_connection
     *
     * @since 1.0.0
     */
    public function ajax_test_connection(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $response = $this->api->test_connection();

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        wp_send_json_success( [
            'message' => __( 'Connection successful! Credentials are valid.', 'woo-trendyol' ),
        ] );
    }

    /**
     * Handle AJAX request to fetch available cargo companies.
     *
     * Action: wp_ajax_trendyol_fetch_cargo_companies
     *
     * @since 1.0.0
     */
    public function ajax_fetch_cargo_companies(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $response = $this->api->get_cargo_companies();

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        wp_send_json_success( [ 'companies' => $response ] );
    }

    /**
     * Handle AJAX request to refresh Trendyol product status.
     *
     * Action: wp_ajax_trendyol_refresh_status
     *
     * @since 1.0.0
     */
    public function ajax_refresh_product_status(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'woo-trendyol' ) ] );
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            wp_send_json_error( [ 'message' => __( 'Product not found.', 'woo-trendyol' ) ] );
        }

        if ( $product->is_type( 'variable' ) ) {
            $children = $product->get_children();
            if ( empty( $children ) ) {
                wp_send_json_error( [ 'message' => __( 'Variable product has no variations.', 'woo-trendyol' ) ] );
            }

            $all_approved    = true;
            $any_approved    = false;
            $any_on_sale     = false;
            $any_archived    = false;
            $any_blacklisted = false;
            $checked_count   = 0;
            $errors          = [];

            foreach ( $children as $child_id ) {
                $variation = wc_get_product( $child_id );
                if ( ! $variation || 'trash' === $variation->get_status() ) {
                    continue;
                }

                $barcode = $this->product_creator->resolve_barcode( $variation );
                if ( empty( $barcode ) ) {
                    $errors[] = sprintf( __( 'Variation #%d has no barcode.', 'woo-trendyol' ), $child_id );
                    continue;
                }

                $trendyol_product = $this->api->get_product_base( $barcode );
                if ( is_wp_error( $trendyol_product ) ) {
                    $errors[] = sprintf( __( 'Variation #%d (%s): %s', 'woo-trendyol' ), $child_id, $barcode, $trendyol_product->get_error_message() );
                    update_post_meta( $child_id, '_trendyol_sync_status', 'error' );
                    update_post_meta( $child_id, '_trendyol_sync_error',  $trendyol_product->get_error_message() );
                    continue;
                }

                $checked_count++;
                $approved    = $trendyol_product['approved']    ?? null;
                $on_sale     = $trendyol_product['onSale']      ?? null;
                $archived    = $trendyol_product['archived']    ?? null;
                $blacklisted = $trendyol_product['blacklisted'] ?? null;

                if ( null !== $approved ) {
                    update_post_meta( $child_id, '_trendyol_approved', $approved ? 'yes' : 'no' );
                    if ( $approved ) { $any_approved = true; } else { $all_approved = false; }
                }
                if ( null !== $on_sale ) {
                    update_post_meta( $child_id, '_trendyol_on_sale', $on_sale ? 'yes' : 'no' );
                    if ( $on_sale ) { $any_on_sale = true; }
                }
                if ( null !== $archived ) {
                    update_post_meta( $child_id, '_trendyol_archived', $archived ? 'yes' : 'no' );
                    if ( $archived ) { $any_archived = true; }
                }
                if ( null !== $blacklisted ) {
                    update_post_meta( $child_id, '_trendyol_blacklisted', $blacklisted ? 'yes' : 'no' );
                    if ( $blacklisted ) { $any_blacklisted = true; }
                }

                update_post_meta( $child_id, '_trendyol_sent', 'yes' );
                update_post_meta( $child_id, '_trendyol_last_sync', time() );
                update_post_meta( $child_id, '_trendyol_sync_status', 'success' );
                update_post_meta( $child_id, '_trendyol_sync_error', '' );
            }

            if ( $checked_count > 0 ) {
                update_post_meta( $post_id, '_trendyol_sent', 'yes' );
                update_post_meta( $post_id, '_trendyol_approved', ( $all_approved && $any_approved ) ? 'yes' : ( $any_approved ? 'partial' : 'no' ) );
                update_post_meta( $post_id, '_trendyol_on_sale', $any_on_sale ? 'yes' : 'no' );
                update_post_meta( $post_id, '_trendyol_archived', $any_archived ? 'yes' : 'no' );
                update_post_meta( $post_id, '_trendyol_blacklisted', $any_blacklisted ? 'yes' : 'no' );
                update_post_meta( $post_id, '_trendyol_last_sync', time() );
                update_post_meta( $post_id, '_trendyol_sync_status', empty( $errors ) ? 'success' : 'error' );
                if ( ! empty( $errors ) ) {
                    update_post_meta( $post_id, '_trendyol_sync_error', implode( '; ', $errors ) );
                } else {
                    update_post_meta( $post_id, '_trendyol_sync_error', '' );
                }

                wp_send_json_success( [
                    'message'     => sprintf( __( 'Status refreshed for %d variations.', 'woo-trendyol' ), $checked_count ),
                    'approved'    => ( $all_approved && $any_approved ) ? 'yes' : ( $any_approved ? 'partial' : 'no' ),
                    'on_sale'     => $any_on_sale ? 'yes' : 'no',
                    'archived'    => $any_archived ? 'yes' : 'no',
                    'blacklisted' => $any_blacklisted ? 'yes' : 'no',
                ] );
            } else {
                wp_send_json_error( [ 'message' => ! empty( $errors ) ? implode( '<br>', $errors ) : __( 'No valid variations found to refresh.', 'woo-trendyol' ) ] );
            }
        } else {
            $barcode = $this->product_creator->resolve_barcode( $product );
            if ( empty( $barcode ) ) {
                wp_send_json_error( [ 'message' => __( 'Product has no barcode. Cannot fetch Trendyol status.', 'woo-trendyol' ) ] );
            }

            $trendyol_product = $this->api->get_product_base( $barcode );

            if ( is_wp_error( $trendyol_product ) ) {
                wp_send_json_error( [ 'message' => $trendyol_product->get_error_message() ] );
            }

            $approved    = $trendyol_product['approved']    ?? null;
            $on_sale     = $trendyol_product['onSale']      ?? null;
            $archived    = $trendyol_product['archived']    ?? null;
            $blacklisted = $trendyol_product['blacklisted'] ?? null;

            if ( null !== $approved )    update_post_meta( $post_id, '_trendyol_approved',    $approved    ? 'yes' : 'no' );
            if ( null !== $on_sale )     update_post_meta( $post_id, '_trendyol_on_sale',     $on_sale     ? 'yes' : 'no' );
            if ( null !== $archived )    update_post_meta( $post_id, '_trendyol_archived',    $archived    ? 'yes' : 'no' );
            if ( null !== $blacklisted ) update_post_meta( $post_id, '_trendyol_blacklisted', $blacklisted ? 'yes' : 'no' );

            wp_send_json_success( [
                'message'     => __( 'Status refreshed successfully.', 'woo-trendyol' ),
                'approved'    => $approved,
                'on_sale'     => $on_sale,
                'archived'    => $archived,
                'blacklisted' => $blacklisted,
            ] );
        }
    }

    /**
     * Handle AJAX request to retrieve all product IDs eligible for a Trendyol push.
     *
     * Accepts optional filter: only_unmapped (1/0) — skip products already sent.
     *
     * Action: wp_ajax_trendyol_get_pushable_products
     *
     * @since 1.0.0
     */
    public function ajax_get_pushable_products(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $only_unmapped        = isset( $_POST['only_unmapped'] ) && '1' === $_POST['only_unmapped'];
        $include_out_of_stock = isset( $_POST['include_out_of_stock'] ) && '1' === $_POST['include_out_of_stock'];
        $action_type          = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : 'push';

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [],
        ];

        if ( $only_unmapped ) {
            $args['meta_query'][] = [
                'relation' => 'OR',
                [
                    'key'     => '_trendyol_sent',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_trendyol_sent',
                    'value'   => 'yes',
                    'compare' => '!=',
                ],
            ];
        }

        if ( ! $include_out_of_stock ) {
            $args['meta_query'][] = [
                'key'     => '_stock_status',
                'value'   => 'outofstock',
                'compare' => '!=',
            ];
        }

        // For bulk push, exclude categories marked as excluded from bulk push
        if ( 'push' === $action_type ) {
            $excluded_terms = get_terms( [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'fields'     => 'ids',
                'meta_query' => [
                    [
                        'key'   => 'trendyol_exclude_bulk_push',
                        'value' => 'yes',
                    ],
                ],
            ] );

            if ( ! is_wp_error( $excluded_terms ) && ! empty( $excluded_terms ) ) {
                $args['tax_query'] = [
                    [
                        'taxonomy'         => 'product_cat',
                        'field'            => 'term_id',
                        'terms'            => $excluded_terms,
                        'operator'         => 'NOT IN',
                        'include_children' => true,
                    ],
                ];
            }
        }

        $product_ids = get_posts( $args );

        wp_send_json_success( [
            'product_ids'   => array_map( 'intval', $product_ids ),
            'omitted_count' => 0,
            'message'       => sprintf( __( 'Found %d eligible products to process.', 'woo-trendyol' ), count( $product_ids ) ),
        ] );
    }

    /**
     * Handle AJAX request to push a batch of products to Trendyol.
     *
     * Accepts a JSON-encoded array of product IDs in $_POST['product_ids'].
     * Returns per-product results and the batchRequestId for polling.
     *
     * Action: wp_ajax_trendyol_bulk_push_batch
     *
     * @since 1.0.0
     */
    public function ajax_bulk_push_batch(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $raw_ids = isset( $_POST['product_ids'] )
            ? json_decode( sanitize_text_field( wp_unslash( $_POST['product_ids'] ) ), true )
            : [];

        if ( empty( $raw_ids ) || ! is_array( $raw_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'No product IDs provided.', 'woo-trendyol' ) ] );
        }

        $product_ids = array_map( 'absint', $raw_ids );
        $result      = $this->product_creator->push_products( $product_ids, true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Handle AJAX request to sync price and stock for a batch of products to Trendyol.
     *
     * Accepts a JSON-encoded array of product IDs in $_POST['product_ids'].
     * Returns per-product results and the batchRequestId for polling.
     *
     * Action: wp_ajax_trendyol_bulk_sync_price_stock_batch
     *
     * @since 1.0.0
     */
    public function ajax_bulk_sync_price_stock_batch(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $raw_ids = isset( $_POST['product_ids'] )
            ? json_decode( sanitize_text_field( wp_unslash( $_POST['product_ids'] ) ), true )
            : [];

        if ( empty( $raw_ids ) || ! is_array( $raw_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'No product IDs provided.', 'woo-trendyol' ) ] );
        }

        $product_ids = array_map( 'absint', $raw_ids );
        $result      = $this->product_creator->sync_price_and_stock( $product_ids );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Handle AJAX request to retrieve all product IDs eligible for Unapproved Products Update.
     *
     * Queries Trendyol API for unapproved products and checks local WooCommerce unapproved products.
     *
     * Action: wp_ajax_trendyol_get_unapproved_products_to_update
     *
     * @since 1.0.0
     */
    public function ajax_get_unapproved_products_to_update(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        global $wpdb;
        $matched_product_ids = [];

        // 1. Fetch unapproved products from Trendyol API (pages 0-9, up to 1000 items)
        for ( $page = 0; $page < 10; $page++ ) {
            $ty_res = $this->api->get_unapproved_products( [ 'page' => $page, 'size' => 100 ] );
            if ( is_wp_error( $ty_res ) || empty( $ty_res['content'] ) ) {
                break;
            }

            foreach ( $ty_res['content'] as $item ) {
                $barcode         = (string) ( $item['barcode'] ?? '' );
                $stock_code      = (string) ( $item['stockCode'] ?? '' );
                $product_main_id = (string) ( $item['productMainId'] ?? '' );

                $pid = 0;
                if ( ! empty( $barcode ) ) {
                    $pid = wc_get_product_id_by_sku( $barcode );
                }
                if ( ! $pid && ! empty( $stock_code ) ) {
                    $pid = wc_get_product_id_by_sku( $stock_code );
                }
                if ( ! $pid && ! empty( $product_main_id ) ) {
                    $pid = wc_get_product_id_by_sku( $product_main_id );
                }
                if ( ! $pid && ! empty( $barcode ) ) {
                    $pid = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_global_unique_id', '_variation_ean', '_barcode', '_sku', 'barcode', 'ean') AND meta_value = %s LIMIT 1",
                            $barcode
                        )
                    );
                }
                if ( ! $pid && ! empty( $barcode ) ) {
                    $pid = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_product_attributes' AND meta_value LIKE %s LIMIT 1",
                            '%' . $wpdb->esc_like( $barcode ) . '%'
                        )
                    );
                }

                if ( $pid ) {
                    $p = wc_get_product( $pid );
                    if ( $p ) {
                        $parent_id = $p->get_parent_id();
                        $target_id = $parent_id ? $parent_id : $pid;
                        $matched_product_ids[ $target_id ] = true;
                    }
                }
            }

            $total_pages = (int) ( $ty_res['totalPages'] ?? 0 );
            if ( $page + 1 >= $total_pages ) {
                break;
            }
        }

        // 2. Also find WooCommerce products marked as not approved
        $local_unapproved = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => '_trendyol_sent',
                    'value'   => 'yes',
                    'compare' => '=',
                ],
                [
                    'key'     => '_trendyol_approved',
                    'value'   => 'yes',
                    'compare' => '!=',
                ],
            ],
        ] );

        foreach ( $local_unapproved as $l_id ) {
            $matched_product_ids[ $l_id ] = true;
        }

        // Filter valid mappings
        $final_ids     = [];
        $omitted_count = 0;

        foreach ( array_keys( $matched_product_ids ) as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product || ! $this->product_creator->validate_mapping( $product ) ) {
                $omitted_count++;
                continue;
            }
            $final_ids[] = (int) $pid;
        }

        wp_send_json_success( [
            'product_ids'   => $final_ids,
            'omitted_count' => $omitted_count,
            'message'       => sprintf(
                __( 'Found %1$d unapproved products to update (%2$d omitted due to invalid mapping).', 'woo-trendyol' ),
                count( $final_ids ),
                $omitted_count
            ),
        ] );
    }

    /**
     * Handle AJAX request to push an unapproved update batch to Trendyol.
     *
     * Action: wp_ajax_trendyol_bulk_update_unapproved_batch
     *
     * @since 1.0.0
     */
    public function ajax_bulk_update_unapproved_batch(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $raw_ids = isset( $_POST['product_ids'] )
            ? json_decode( sanitize_text_field( wp_unslash( $_POST['product_ids'] ) ), true )
            : [];

        if ( empty( $raw_ids ) || ! is_array( $raw_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'No product IDs provided.', 'woo-trendyol' ) ] );
        }

        $product_ids = array_map( 'absint', $raw_ids );
        $result      = $this->product_creator->update_unapproved_products( $product_ids, true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Handle AJAX request to poll the status of a batch push request.
     *
     * Action: wp_ajax_trendyol_poll_batch_status
     *
     * @since 1.0.0
     */
    public function ajax_poll_batch_status(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $batch_id = isset( $_POST['batch_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) )
            : '';

        if ( empty( $batch_id ) ) {
            wp_send_json_error( [ 'message' => __( 'No batch ID provided.', 'woo-trendyol' ) ] );
        }

        $response = $this->api->get_batch_request_result( $batch_id );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        wp_send_json_success( $response );
    }

    /**
     * Handle AJAX request to load Trendyol gender and age attribute values
     * for a given category, plus the WC terms for the currently selected
     * WC attribute for each slot.
     *
     * Returns:
     *  {
     *    gender: { attr_id: int, values: [ { id, name }, … ], wc_terms: [ { slug, name }, … ] },
     *    age:    { attr_id: int, values: [ { id, name }, … ], wc_terms: [ { slug, name }, … ] },
     *    saved_maps: { gender: { ty_id: [slug,…], … }, age: { ty_id: [slug,…], … } }
     *  }
     *
     * Action: wp_ajax_trendyol_load_attr_values
     *
     * @since 1.0.0
     */
    public function ajax_load_all_mapped_attr_values(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'     => 'trendyol_category_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ];
        $terms = get_terms( $args );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            wp_send_json_error( [ 'message' => __( 'No categories are mapped to Trendyol yet. Please map categories first.', 'woo-trendyol' ) ] );
        }

        $attr_freq = []; 
        $attr_data = []; 

        foreach ( $terms as $term ) {
            $required_attrs = get_term_meta( $term->term_id, '_trendyol_required_attributes', true );
            if ( ! is_array( $required_attrs ) || empty( $required_attrs ) ) {
                continue;
            }

            foreach ( $required_attrs as $attr ) {
                $attr_id = (int) ( $attr['id'] ?? 0 );
                if ( ! $attr_id ) {
                    continue;
                }

                if ( ! isset( $attr_freq[ $attr_id ] ) ) {
                    $attr_freq[ $attr_id ] = 0;
                    $raw_name  = (string) ( $attr['name'] ?? '' );
                    $is_custom = ! empty( $attr['allowCustom'] ) || (bool) preg_match( '/\b(web|free|custom|serbest)\b/i', $raw_name );

                    $attr_data[ $attr_id ] = [
                        'id'          => $attr_id,
                        'name'        => $raw_name,
                        'values'      => [],
                        'allowCustom' => $is_custom,
                        'categories'  => [],
                        'cat_ids'     => [],
                    ];
                } else {
                    if ( ! empty( $attr['allowCustom'] ) ) {
                        $attr_data[ $attr_id ]['allowCustom'] = true;
                    }
                }

                $attr_freq[ $attr_id ]++;
                $attr_data[ $attr_id ]['categories'][] = $term->name;
                $trendyol_cat_id = get_term_meta( $term->term_id, 'trendyol_category_id', true );
                if ( $trendyol_cat_id ) {
                    $attr_data[ $attr_id ]['cat_ids'][] = $trendyol_cat_id;
                }

                if ( ! empty( $attr['values'] ) ) {
                    foreach ( $attr['values'] as $v ) {
                        $v_id = (int) $v['id'];
                        if ( ! isset( $attr_data[ $attr_id ]['values'][ $v_id ] ) ) {
                            $attr_data[ $attr_id ]['values'][ $v_id ] = $v;
                        }
                    }
                }
            }
        }

        if ( empty( $attr_freq ) ) {
            wp_send_json_error( [ 'message' => __( 'No cached category attributes found. Please run "Sync Category Attributes" from the Synchronization tab first.', 'woo-trendyol' ) ] );
        }

        $discovered_global_attrs = [];
        $names_cache = get_option( 'trendyol_global_attr_names_cache', [] );
        if ( ! is_array( $names_cache ) ) {
            $names_cache = [];
        }

        $response_data = [ 'attributes' => [], 'saved_maps' => [] ];

        foreach ( $attr_freq as $attr_id => $count ) {
            if ( $count >= 2 ) {
                $is_custom = ! empty( $attr_data[ $attr_id ]['allowCustom'] ) || (bool) preg_match( '/\b(web|free|custom|serbest)\b/i', (string) ( $attr_data[ $attr_id ]['name'] ?? '' ) );

                // If not custom and values are empty, fetch from API
                if ( ! $is_custom && empty( $attr_data[ $attr_id ]['values'] ) && ! empty( $attr_data[ $attr_id ]['cat_ids'] ) ) {
                    $cat_id = reset( $attr_data[ $attr_id ]['cat_ids'] );
                    $values_res = $this->api->get_attribute_values( (int) $cat_id, $attr_id );
                    if ( ! is_wp_error( $values_res ) && ! empty( $values_res['content'] ) ) {
                        foreach ( $values_res['content'] as $v ) {
                            $v_id = (int) $v['attributeValueId'];
                            $attr_data[ $attr_id ]['values'][ $v_id ] = [
                                'id'   => $v_id,
                                'name' => (string) $v['attributeValue'],
                            ];
                        }
                    }
                }

                $values_list = array_values( $attr_data[ $attr_id ]['values'] );

                // Sort by name
                usort( $values_list, static fn( $a, $b ) => strcmp( $a['name'] ?? '', $b['name'] ?? '' ) );

                $attr_data[ $attr_id ]['values'] = $values_list;
                $attr_data[ $attr_id ]['categories'] = array_values( array_unique( $attr_data[ $attr_id ]['categories'] ) );
                $discovered_global_attrs[] = $attr_data[ $attr_id ];

                foreach ( $values_list as $v ) {
                    $names_cache[ $v['id'] ] = $v['name'];
                }

                $wc_terms = $this->get_wc_terms_for_attr( get_option( 'trendyol_global_attr_' . $attr_id . '_wc', '' ) );

                $saved_map = [];
                $raw_map = get_option( 'trendyol_global_attr_' . $attr_id . '_map', '' );
                if ( $raw_map ) {
                    $decoded = json_decode( $raw_map, true );
                    if ( is_array( $decoded ) ) {
                        $saved_map = $decoded;
                    }
                }

                $response_data['attributes'][ $attr_id ] = [
                    'attr_id'     => $attr_id,
                    'name'        => $attr_data[ $attr_id ]['name'],
                    'values'      => $values_list,
                    'wc_terms'    => $wc_terms,
                    'allowCustom' => $attr_data[ $attr_id ]['allowCustom'],
                ];
                $response_data['saved_maps'][ $attr_id ] = $saved_map;
            }
        }

        if ( empty( $discovered_global_attrs ) ) {
            wp_send_json_error( [ 'message' => __( 'No global attributes found (i.e. no required attribute appears in 2 or more categories).', 'woo-trendyol' ) ] );
        }

        update_option( 'trendyol_discovered_global_attrs', $discovered_global_attrs, false );
        update_option( 'trendyol_global_attr_names_cache', $names_cache, false );

        wp_send_json_success( $response_data );
    }

    public function ajax_load_attr_values(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
        if ( ! $category_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid category ID.', 'woo-trendyol' ) ] );
        }

        // Fetch category attribute schema from Trendyol.
        $schema = $this->api->get_category_attributes( $category_id );
        if ( is_wp_error( $schema ) ) {
            wp_send_json_error( [ 'message' => $schema->get_error_message() ] );
        }

        $category_attributes = $schema['categoryAttributes'] ?? [];

        // Canonical names we look for (multi-language).
        $gender_names        = array_map( 'mb_strtolower', Woo_Trendyol_Attribute_Mapper::GLOBAL_ATTR_NAMES['gender'] );
        $age_names           = array_map( 'mb_strtolower', Woo_Trendyol_Attribute_Mapper::GLOBAL_ATTR_NAMES['age'] );
        $age_group_names     = array_map( 'mb_strtolower', Woo_Trendyol_Attribute_Mapper::GLOBAL_ATTR_NAMES['age_group'] );
        $color_names         = array_map( 'mb_strtolower', Woo_Trendyol_Attribute_Mapper::GLOBAL_ATTR_NAMES['color'] );

        $gender_data    = [ 'attr_id' => 0, 'values' => [] ];
        $age_data       = [ 'attr_id' => 0, 'values' => [] ];
        $age_group_data = [ 'attr_id' => 0, 'values' => [] ];
        $color_data     = [ 'attr_id' => 0, 'values' => [], 'allowCustom' => false ];
        $color_custom_data = [ 'attr_id' => 0, 'values' => [], 'allowCustom' => true ];

        foreach ( $category_attributes as $cat_attr ) {
            $attr_name    = (string) ( $cat_attr['attribute']['name'] ?? '' );
            $attr_id      = (int)    ( $cat_attr['attribute']['id']   ?? 0 );
            $raw_vals     = $cat_attr['attributeValues'] ?? [];
            $allow_custom = ! empty( $cat_attr['allowCustom'] );

            $values = array_map(
                static fn( $v ) => [ 'id' => (int) $v['id'], 'name' => (string) $v['name'] ],
                $raw_vals
            );

            // If the attribute has no predefined values returned inline, try fetching them from the sub-endpoint
            if ( empty( $values ) ) {
                $values_res = $this->api->get_attribute_values( $category_id, $attr_id );
                if ( ! is_wp_error( $values_res ) && ! empty( $values_res['content'] ) ) {
                    foreach ( $values_res['content'] as $v ) {
                        $values[] = [
                            'id'   => (int) $v['attributeValueId'],
                            'name' => (string) $v['attributeValue'],
                        ];
                    }
                }
            }

            $attr_name_lower = mb_strtolower( trim( $attr_name ) );

            $matched_slot = null;
            foreach ( $gender_names as $k ) {
                if ( false !== mb_strpos( $attr_name_lower, $k ) ) {
                    $matched_slot = 'gender';
                    break;
                }
            }
            if ( ! $matched_slot ) {
                foreach ( $age_group_names as $k ) {
                    if ( false !== mb_strpos( $attr_name_lower, $k ) ) {
                        $matched_slot = 'age_group';
                        break;
                    }
                }
            }
            if ( ! $matched_slot ) {
                foreach ( $age_names as $k ) {
                    if ( false !== mb_strpos( $attr_name_lower, $k ) ) {
                        $matched_slot = 'age';
                        break;
                    }
                }
            }
            if ( ! $matched_slot ) {
                foreach ( $color_names as $k ) {
                    if ( false !== mb_strpos( $attr_name_lower, $k ) ) {
                        $matched_slot = 'color';
                        break;
                    }
                }
            }

            if ( 'gender' === $matched_slot ) {
                if ( empty( $gender_data['values'] ) || ! empty( $values ) ) {
                    $gender_data = [ 'attr_id' => $attr_id, 'values' => $values ];
                }
            } elseif ( 'age' === $matched_slot ) {
                if ( empty( $age_data['values'] ) || ! empty( $values ) ) {
                    $age_data = [ 'attr_id' => $attr_id, 'values' => $values ];
                }
            } elseif ( 'age_group' === $matched_slot ) {
                if ( empty( $age_group_data['values'] ) || ! empty( $values ) ) {
                    $age_group_data = [ 'attr_id' => $attr_id, 'values' => $values ];
                }
            } elseif ( 'color' === $matched_slot ) {
                if ( $allow_custom ) {
                    $color_custom_data = [ 'attr_id' => $attr_id, 'values' => $values, 'allowCustom' => true ];
                } else {
                    $color_data = [ 'attr_id' => $attr_id, 'values' => $values, 'allowCustom' => false ];
                }
            }
        }

        // Cache the loaded attribute value names so they can be shown on page reload.
        $names_cache = get_option( 'trendyol_global_attr_names_cache', [] );
        if ( ! is_array( $names_cache ) ) {
            $names_cache = [];
        }
        foreach ( [ $gender_data, $age_data, $age_group_data, $color_data, $color_custom_data ] as $slot_data ) {
            if ( ! empty( $slot_data['values'] ) ) {
                foreach ( $slot_data['values'] as $v ) {
                    $names_cache[ $v['id'] ] = $v['name'];
                }
            }
        }
        update_option( 'trendyol_global_attr_names_cache', $names_cache, false );

        // Load WC terms for the currently selected WC attributes.
        $gender_data['wc_terms'] = $this->get_wc_terms_for_attr(
            get_option( 'trendyol_global_attr_gender_wc', '' )
        );
        $age_data['wc_terms'] = $this->get_wc_terms_for_attr(
            get_option( 'trendyol_global_attr_age_wc', '' )
        );
        $age_group_data['wc_terms'] = $this->get_wc_terms_for_attr(
            get_option( 'trendyol_global_attr_age_group_wc', '' )
        );
        $color_data['wc_terms'] = $this->get_wc_terms_for_attr(
            get_option( 'trendyol_global_attr_color_wc', '' )
        );

        // Load saved maps.
        $saved_gender_map = [];
        $raw_g = get_option( 'trendyol_global_attr_gender_map', '' );
        if ( $raw_g ) {
            $decoded = json_decode( $raw_g, true );
            if ( is_array( $decoded ) ) {
                $saved_gender_map = $decoded;
            }
        }

        $saved_age_map = [];
        $raw_a = get_option( 'trendyol_global_attr_age_map', '' );
        if ( $raw_a ) {
            $decoded = json_decode( $raw_a, true );
            if ( is_array( $decoded ) ) {
                $saved_age_map = $decoded;
            }
        }

        $saved_age_group_map = [];
        $raw_ag = get_option( 'trendyol_global_attr_age_group_map', '' );
        if ( $raw_ag ) {
            $decoded = json_decode( $raw_ag, true );
            if ( is_array( $decoded ) ) {
                $saved_age_group_map = $decoded;
            }
        }

        $saved_color_map = [];
        $raw_c = get_option( 'trendyol_global_attr_color_map', '' );
        if ( $raw_c ) {
            $decoded = json_decode( $raw_c, true );
            if ( is_array( $decoded ) ) {
                $saved_color_map = $decoded;
            }
        }

        wp_send_json_success( [
            'gender'     => $gender_data,
            'age'        => $age_data,
            'age_group'  => $age_group_data,
            'color'      => $color_data,
            'saved_maps' => [
                'gender'    => $saved_gender_map,
                'age'       => $saved_age_map,
                'age_group' => $saved_age_group_map,
                'color'     => $saved_color_map,
            ],
        ] );
    }

    /**
     * Handle AJAX request to fetch WC attribute terms when the user changes
     * the WC attribute selector for gender or age.
     *
     * POST params: slot (gender|age), wc_attr (pa_* slug)
     * Returns: { slot, wc_terms: [ { slug, name }, … ] }
     *
     * Action: wp_ajax_trendyol_get_wc_terms
     *
     * @since 1.0.0
     */
    public function ajax_get_wc_terms(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $slot    = isset( $_POST['slot'] )    ? sanitize_key( $_POST['slot'] )                             : '';
        $wc_attr = isset( $_POST['wc_attr'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_attr'] ) )    : '';

        if ( ! in_array( $slot, [ 'gender', 'age', 'age_group', 'color', 'color_custom' ], true ) || empty( $wc_attr ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'woo-trendyol' ) ] );
        }

        $wc_terms = $this->get_wc_terms_for_attr( $wc_attr );

        wp_send_json_success( [ 'slot' => $slot, 'wc_terms' => $wc_terms ] );
    }

    /**
     * Fetch all terms for a given WooCommerce attribute taxonomy.
     *
     * @since  1.0.0
     * @access private
     * @param  string $wc_attr Taxonomy slug (e.g. 'pa_age').
     * @return array[] Array of [ 'slug' => string, 'name' => string ].
     */
    private function get_wc_terms_for_attr( string $wc_attr ): array {
        if ( empty( $wc_attr ) ) {
            return [];
        }

        $terms = get_terms( [
            'taxonomy'   => $wc_attr,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        return array_map(
            static fn( $t ) => [ 'slug' => $t->slug, 'name' => $t->name ],
            $terms
        );
    }

    // -----------------------------------------------------------------------
    // Plugin action links
    // -----------------------------------------------------------------------

    /**
     * Add a "Settings" link to the plugin entry on the Plugins page.
     *
     * Hooked to: plugin_action_links_{plugin_basename}
     *
     * @since 1.0.0
     * @param array $links Existing action links.
     * @return array Modified action links.
     */
    public function add_action_links( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=woo-trendyol-settings' ) ),
            esc_html__( 'Settings', 'woo-trendyol' )
        );

        array_unshift( $links, $settings_link );

        return $links;
    }

    // -----------------------------------------------------------------------
    // Single-product push
    // -----------------------------------------------------------------------

    /**
     * Handle AJAX request to push a single product to Trendyol.
     *
     * Validates prerequisites (SKU, category mapping), submits the product via
     * Product_Creator::push_products(), then polls the batch result up to 5 times
     * (2 s apart) to resolve a definitive accepted/rejected status in one round-trip.
     * After polling, fetches the live Trendyol product record to update approval flags.
     *
     * Action: wp_ajax_trendyol_push_single_product
     *
     * @since 1.0.0
     */
    public function ajax_push_single_product(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'woo-trendyol' ) ] );
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            wp_send_json_error( [ 'message' => __( 'Product not found.', 'woo-trendyol' ) ] );
        }

        // --- Validate prerequisites ---
        if ( $product->is_type( 'variable' ) ) {
            $children = $product->get_children();
            if ( empty( $children ) ) {
                wp_send_json_error( [
                    'message' => __( 'Variable product has no variations. Please add variations before sending to Trendyol.', 'woo-trendyol' ),
                ] );
            }
        } else {
            $barcode = $this->product_creator->resolve_barcode( $product );
            if ( empty( $barcode ) ) {
                wp_send_json_error( [
                    'message' => __( 'Product has no barcode. Please add a barcode before sending to Trendyol.', 'woo-trendyol' ),
                ] );
            }
        }

        $category_id = $this->category_helper->get_trendyol_category_id( $post_id );
        if ( empty( $category_id ) ) {
            wp_send_json_error( [
                'message' => __( 'No Trendyol category mapped. Please map a category before sending.', 'woo-trendyol' ),
            ] );
        }

        // --- Submit to Trendyol ---
        $push_result = $this->product_creator->push_products( [ $post_id ] );

        if ( is_wp_error( $push_result ) ) {
            wp_send_json_error( [ 'message' => $push_result->get_error_message() ] );
        }

        if ( ! empty( $push_result['errors'][ $post_id ] ) ) {
            $error_msg = $push_result['errors'][ $post_id ];
            update_post_meta( $post_id, '_trendyol_sync_status', 'error' );
            update_post_meta( $post_id, '_trendyol_sync_error',  $error_msg );
            update_post_meta( $post_id, '_trendyol_batch_id',    '' );
            update_post_meta( $post_id, '_trendyol_last_sync',   time() );
            wp_send_json_error( [ 'message' => $error_msg ] );
        }

        // Retrieve the batch ID stored by push_products().
        $batch_id = get_post_meta( $post_id, '_trendyol_batch_id', true );

        // --- Poll batch result (up to 5 attempts, 2 s apart) ---
        $batch_status = null;
        $item_status  = 'pending';
        $fail_reason  = '';

        if ( ! empty( $batch_id ) ) {
            for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
                sleep( 2 );

                $batch_response = $this->api->get_batch_request_result( $batch_id );

                if ( is_wp_error( $batch_response ) ) {
                    break;
                }

                $batch_status = $batch_response['status'] ?? '';

                if ( in_array( $batch_status, [ 'COMPLETED', 'FAILED' ], true ) ) {
                    $items = $batch_response['items'] ?? [];
                    $has_failure = false;
                    $reasons = [];
                    foreach ( $items as $item ) {
                        $st = $item['status'] ?? 'UNKNOWN';
                        if ( in_array( $st, [ 'ERROR', 'FAILED' ], true ) || ! empty( $item['failureReasons'] ) ) {
                            $has_failure = true;
                            if ( ! empty( $item['failureReasons'] ) ) {
                                $r = array_column( $item['failureReasons'], 'message' );
                                $reasons = array_merge( $reasons, $r );
                            }
                        }
                    }
                    if ( $has_failure ) {
                        $item_status = 'FAILED';
                        $fail_reason = implode( '; ', array_unique( $reasons ) );
                    } else {
                        $item_status = 'SUCCESS';
                    }
                    break;
                }
            }
        }

        // --- Persist resolved status ---
        if ( 'SUCCESS' === $item_status ) {
            update_post_meta( $post_id, '_trendyol_sent',        'yes' );
            update_post_meta( $post_id, '_trendyol_sync_status', 'success' );
            update_post_meta( $post_id, '_trendyol_sync_error',  '' );
        } elseif ( in_array( $item_status, [ 'ERROR', 'FAILED' ], true ) ) {
            update_post_meta( $post_id, '_trendyol_sync_status', 'error' );
            update_post_meta( $post_id, '_trendyol_sync_error',  $fail_reason );
        } else {
            update_post_meta( $post_id, '_trendyol_sent',        'yes' );
            update_post_meta( $post_id, '_trendyol_sync_status', 'pending' );
        }

        update_post_meta( $post_id, '_trendyol_last_sync', time() );

        // --- Fetch live Trendyol records to update approval flags ---
        if ( $product->is_type( 'variable' ) ) {
            $all_approved    = true;
            $any_approved    = false;
            $any_on_sale     = false;
            $any_archived    = false;
            $all_archived    = true;
            $any_blacklisted = false;
            $has_val_count   = 0;

            foreach ( $product->get_children() as $cid ) {
                $child = wc_get_product( $cid );
                if ( ! $child || 'trash' === $child->get_status() ) {
                    continue;
                }

                if ( 'SUCCESS' === $item_status ) {
                    update_post_meta( $cid, '_trendyol_sent',        'yes' );
                    update_post_meta( $cid, '_trendyol_sync_status', 'success' );
                    update_post_meta( $cid, '_trendyol_sync_error',  '' );
                    update_post_meta( $cid, '_trendyol_last_sync',   time() );
                }

                $child_barcode = $this->product_creator->resolve_barcode( $child );
                if ( empty( $child_barcode ) ) {
                    continue;
                }

                $var_trendyol = $this->api->get_product_base( $child_barcode );
                if ( ! is_wp_error( $var_trendyol ) ) {
                    $v_approved    = $var_trendyol['approved']    ?? null;
                    $v_on_sale     = $var_trendyol['onSale']      ?? null;
                    $v_archived    = $var_trendyol['archived']    ?? null;
                    $v_blacklisted = $var_trendyol['blacklisted'] ?? null;

                    if ( null !== $v_approved ) {
                        update_post_meta( $cid, '_trendyol_approved', $v_approved ? 'yes' : 'no' );
                        $has_val_count++;
                        if ( $v_approved ) { $any_approved = true; } else { $all_approved = false; }
                    } else {
                        $all_approved = false;
                    }
                    if ( null !== $v_on_sale ) {
                        update_post_meta( $cid, '_trendyol_on_sale', $v_on_sale ? 'yes' : 'no' );
                        if ( $v_on_sale ) { $any_on_sale = true; }
                    }
                    if ( null !== $v_archived ) {
                        update_post_meta( $cid, '_trendyol_archived', $v_archived ? 'yes' : 'no' );
                        if ( $v_archived ) { $any_archived = true; } else { $all_archived = false; }
                    }
                    if ( null !== $v_blacklisted ) {
                        update_post_meta( $cid, '_trendyol_blacklisted', $v_blacklisted ? 'yes' : 'no' );
                        if ( $v_blacklisted ) { $any_blacklisted = true; }
                    }
                }
            }

            if ( $has_val_count > 0 ) {
                update_post_meta( $post_id, '_trendyol_approved',    ( $all_approved && $any_approved ) ? 'yes' : ( $any_approved ? 'partial' : 'no' ) );
                update_post_meta( $post_id, '_trendyol_on_sale',     $any_on_sale ? 'yes' : 'no' );
                update_post_meta( $post_id, '_trendyol_archived',    ( $all_archived && $has_val_count > 0 ) ? 'yes' : ( $any_archived ? 'partial' : 'no' ) );
                update_post_meta( $post_id, '_trendyol_blacklisted', $any_blacklisted ? 'yes' : 'no' );
            }
        } else {
            $lookup_barcode = $this->product_creator->resolve_barcode( $product );
            $trendyol_product = ! empty( $lookup_barcode ) ? $this->api->get_product_base( $lookup_barcode ) : new WP_Error( 'no_barcode', 'No barcode' );

            if ( ! is_wp_error( $trendyol_product ) ) {
                $approved    = $trendyol_product['approved']    ?? null;
                $on_sale     = $trendyol_product['onSale']      ?? null;
                $archived    = $trendyol_product['archived']    ?? null;
                $blacklisted = $trendyol_product['blacklisted'] ?? null;

                if ( null !== $approved )    update_post_meta( $post_id, '_trendyol_approved',    $approved    ? 'yes' : 'no' );
                if ( null !== $on_sale )     update_post_meta( $post_id, '_trendyol_on_sale',     $on_sale     ? 'yes' : 'no' );
                if ( null !== $archived )    update_post_meta( $post_id, '_trendyol_archived',    $archived    ? 'yes' : 'no' );
                if ( null !== $blacklisted ) update_post_meta( $post_id, '_trendyol_blacklisted', $blacklisted ? 'yes' : 'no' );
            }
        }

        // --- Build response ---
        if ( 'SUCCESS' === $item_status ) {
            $message = __( 'Product sent and accepted by Trendyol.', 'woo-trendyol' );
            $type    = 'success';
        } elseif ( in_array( $item_status, [ 'ERROR', 'FAILED' ], true ) ) {
            $message = sprintf(
                /* translators: %s: failure reason */
                __( 'Trendyol rejected the product: %s', 'woo-trendyol' ),
                $fail_reason ?: __( 'Unknown reason.', 'woo-trendyol' )
            );
            $type = 'error';
        } else {
            $message = __( 'Product submitted. Status is pending — click Refresh Status to check later.', 'woo-trendyol' );
            $type    = 'pending';
        }

        wp_send_json_success( [
            'message'      => $message,
            'type'         => $type,
            'item_status'  => $item_status,
            'batch_status' => $batch_status,
            'batch_id'     => $batch_id,
            'reload'       => ( 'pending' !== $type ),
        ] );
    }

    // -----------------------------------------------------------------------
    // Sync Tasks
    // -----------------------------------------------------------------------

    /**
     * AJAX handler to sync categories.
     * Fetches Trendyol categories and fuzzy matches them to WooCommerce leaf categories.
     *
     * @since 1.0.0
     */
    public function ajax_sync_categories(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        delete_transient( 'wt_category_tree' );
        $response = $this->api->get_categories();

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 
                'message' => sprintf( 
                    __( 'Failed to fetch Trendyol categories: %s', 'woo-trendyol' ), 
                    $response->get_error_message() 
                ) 
            ] );
        }

        $trendyol_categories = $response['categories'] ?? [];
        $trendyol_leaf_cats  = [];

        // Recursive function to extract leaf categories
        $extract_leaf_categories = function ( $categories, $path = '' ) use ( &$extract_leaf_categories, &$trendyol_leaf_cats ) {
            foreach ( $categories as $category ) {
                $current_path = $path ? $path . ' ||| ' . $category['name'] : $category['name'];
                if ( empty( $category['subCategories'] ) ) {
                    $trendyol_leaf_cats[ $category['id'] ] = [
                        'id'   => $category['id'],
                        'name' => $category['name'],
                        'path' => $current_path,
                    ];
                } else {
                    $extract_leaf_categories( $category['subCategories'], $current_path );
                }
            }
        };

        $extract_leaf_categories( $trendyol_categories );

        if ( empty( $trendyol_leaf_cats ) ) {
             wp_send_json_error( [ 'message' => __( 'No Trendyol categories found.', 'woo-trendyol' ) ] );
        }

        // Dynamically build and save the cascade and flat map files to keep the admin dropdowns
        // in sync with the fetched category language/tree.
        $cascade = [];
        $flat_map = [];

        $build_cascade_and_flat_map = function ( $categories, $parent_key = '__root__', $path_array = [] ) use ( &$build_cascade_and_flat_map, &$cascade, &$flat_map ) {
            foreach ( $categories as $cat ) {
                $current_path_array = array_merge( $path_array, [ $cat['name'] ] );
                $has_sub = ! empty( $cat['subCategories'] );
                
                $cascade[ $parent_key ][] = [
                    'id'   => $has_sub ? null : (string) $cat['id'],
                    'name' => $cat['name'],
                ];

                if ( ! $has_sub ) {
                    $flat_map[ (string) $cat['id'] ] = $current_path_array;
                } else {
                    $next_key = implode( '|||', $current_path_array );
                    $build_cascade_and_flat_map( $cat['subCategories'], $next_key, $current_path_array );
                }
            }
        };

        $build_cascade_and_flat_map( $trendyol_categories );

        $cascade_file  = WOO_TRENDYOL_PATH . 'assets/data/trendyol_categories.json';
        $flat_map_file = WOO_TRENDYOL_PATH . 'assets/data/trendyol_flat_map.json';

        if ( ! empty( $cascade ) && ! empty( $flat_map ) ) {
            @file_put_contents( $cascade_file, wp_json_encode( $cascade, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
            @file_put_contents( $flat_map_file, wp_json_encode( $flat_map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
        }

        $woo_categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $woo_categories ) || empty( $woo_categories ) ) {
            wp_send_json_error( [ 'message' => __( 'No WooCommerce categories found.', 'woo-trendyol' ) ] );
        }

        $woo_leaf_cats = [];
        foreach ( $woo_categories as $cat ) {
            $children = get_term_children( $cat->term_id, 'product_cat' );
            if ( empty( $children ) ) {
                $woo_leaf_cats[] = $cat;
            }
        }

        $matched_count = 0;

        foreach ( $woo_leaf_cats as $woo_cat ) {
            $mapped_id = get_term_meta( $woo_cat->term_id, Woo_Trendyol_Category_Helper::TERM_META_ID, true );

            // If already mapped, update path to Greek and skip matching
            if ( ! empty( $mapped_id ) ) {
                if ( isset( $trendyol_leaf_cats[ $mapped_id ] ) ) {
                    update_term_meta( $woo_cat->term_id, Woo_Trendyol_Category_Helper::TERM_META_PATH, $trendyol_leaf_cats[ $mapped_id ]['path'] );
                }
                continue;
            }

            $woo_name = mb_strtolower( $woo_cat->name );

            $best_match_id   = 0;
            $best_match_path = '';
            $best_score      = 0;

            foreach ( $trendyol_leaf_cats as $t_cat ) {
                $t_name = mb_strtolower( $t_cat['name'] );

                if ( $woo_name === $t_name ) {
                    $best_match_id   = $t_cat['id'];
                    $best_match_path = $t_cat['path'];
                    break;
                }

                // Try fuzzy match
                similar_text( $woo_name, $t_name, $percent );
                if ( $percent > 75 && $percent > $best_score ) {
                    $best_score      = $percent;
                    $best_match_id   = $t_cat['id'];
                    $best_match_path = $t_cat['path'];
                }
            }

            if ( $best_match_id ) {
                update_term_meta( $woo_cat->term_id, Woo_Trendyol_Category_Helper::TERM_META_ID, $best_match_id );
                update_term_meta( $woo_cat->term_id, Woo_Trendyol_Category_Helper::TERM_META_PATH, $best_match_path );
                $matched_count++;
            }
        }

        wp_send_json_success( [
            'message' => sprintf(
                __( 'Category sync complete. %1$d WooCommerce categories mapped out of %2$d.', 'woo-trendyol' ),
                $matched_count,
                count( $woo_leaf_cats )
            )
        ] );
    }

    /**
     * AJAX handler to sync category attributes.
     * Fetches required attributes for mapped WooCommerce categories and saves them.
     *
     * @since 1.0.0
     */
    public function ajax_sync_category_attributes(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $woo_categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $woo_categories ) || empty( $woo_categories ) ) {
            wp_send_json_error( [ 'message' => __( 'No WooCommerce categories found.', 'woo-trendyol' ) ] );
        }

        $synced_count = 0;

        foreach ( $woo_categories as $cat ) {
            $trendyol_id = get_term_meta( $cat->term_id, Woo_Trendyol_Category_Helper::TERM_META_ID, true );

            if ( empty( $trendyol_id ) ) {
                continue;
            }

            delete_transient( 'wt_cat_attrs_' . $trendyol_id );
            $response = $this->api->get_category_attributes( (int) $trendyol_id );

            if ( ! is_wp_error( $response ) && ! empty( $response['categoryAttributes'] ) ) {
                 $required_attrs = [];
                 foreach ( $response['categoryAttributes'] as $attr ) {
                     if ( ! empty( $attr['required'] ) ) {
                         $attr_id = $attr['attribute']['id'] ?? 0;
                         
                         delete_transient( 'wt_attr_values_' . $trendyol_id . '_' . $attr_id );
                         $values_res = $this->api->get_attribute_values( (int) $trendyol_id, $attr_id );
                         $values = [];
                         if ( ! is_wp_error( $values_res ) && ! empty( $values_res['content'] ) ) {
                             foreach ( $values_res['content'] as $v ) {
                                 $values[] = [
                                     'id'   => (int) $v['attributeValueId'],
                                     'name' => (string) $v['attributeValue'],
                                 ];
                             }
                         }

                         $required_attrs[] = [
                             'id'          => $attr_id,
                             'name'        => $attr['attribute']['name'] ?? '',
                             'values'      => $values,
                             'allowCustom' => ! empty( $attr['allowCustom'] ),
                         ];
                     }
                 }

                update_term_meta( $cat->term_id, '_trendyol_required_attributes', $required_attrs );
                $synced_count++;
            }
        }

        wp_send_json_success( [
            'message' => sprintf(
                __( 'Category attributes sync complete. Attributes updated for %d categories.', 'woo-trendyol' ),
                $synced_count
            )
        ] );
    }

    /**
     * AJAX handler to sync attribute values (Fuzzy match gender/age).
     *
     * @since 1.0.0
     */
    public function ajax_sync_attribute_values(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        // Just fetching values and showing a message. The actual mapping is 
        // manual or via the mapper's auto-resolution for gender/age keywords.
        // We will just fetch attribute values for mapped categories and save them for dropdowns.

        $woo_categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ] );

        $synced_count = 0;
        foreach ( $woo_categories as $cat ) {
             $trendyol_id = get_term_meta( $cat->term_id, Woo_Trendyol_Category_Helper::TERM_META_ID, true );
             if ( $trendyol_id ) {
                 // Trigger caching via API client
                 delete_transient( 'wt_cat_attrs_' . $trendyol_id );
                 $this->api->get_category_attributes( (int) $trendyol_id );
                 $synced_count++;
             }
        }

        wp_send_json_success( [
            'message' => sprintf(
                __( 'Attribute values synced and cached for %d mapped categories. You can now map values in category edit pages.', 'woo-trendyol' ),
                $synced_count
            )
        ] );
    }

    /**
     * AJAX handler to sync attributes for a single category when modified or requested on category edit screen.
     *
     * @since 1.0.0
     */
    public function ajax_sync_single_category_attributes(): void {
        check_ajax_referer( 'woo_trendyol_taxonomy_save', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
        $term_id     = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;

        if ( ! $category_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid Trendyol category ID.', 'woo-trendyol' ) ] );
        }

        // Delete the transient to force fetching fresh Greek translation from API
        delete_transient( 'wt_cat_attrs_' . $category_id );

        $response = $this->api->get_category_attributes( $category_id );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $required_attrs = [];
        if ( ! empty( $response['categoryAttributes'] ) ) {
            foreach ( $response['categoryAttributes'] as $attr ) {
                if ( ! empty( $attr['required'] ) ) {
                    $attr_id = $attr['attribute']['id'] ?? 0;
                    
                    delete_transient( 'wt_attr_values_' . $category_id . '_' . $attr_id );
                    $values_res = $this->api->get_attribute_values( (int) $category_id, $attr_id );
                    $values = [];
                    if ( ! is_wp_error( $values_res ) && ! empty( $values_res['content'] ) ) {
                        foreach ( $values_res['content'] as $v ) {
                            $values[] = [
                                'id'   => (int) $v['attributeValueId'],
                                'name' => (string) $v['attributeValue'],
                            ];
                        }
                    }

                    $required_attrs[] = [
                        'id'          => $attr_id,
                        'name'        => $attr['attribute']['name'] ?? '',
                        'values'      => $values,
                        'allowCustom' => ! empty( $attr['allowCustom'] ),
                    ];
                }
            }
        }

        // Save to term meta if term_id is valid (e.g. they are on edit page of an existing term)
        if ( $term_id ) {
            update_term_meta( $term_id, '_trendyol_required_attributes', $required_attrs );
        }

        // Prepare variables for rendering the partial block
        $required_attributes      = $required_attrs;
        $attribute_mappings       = $term_id ? get_term_meta( $term_id, '_trendyol_attribute_mappings', true ) : [];
        $attribute_mappings       = is_array( $attribute_mappings ) ? $attribute_mappings : [];
        $attribute_value_mappings = $term_id ? get_term_meta( $term_id, '_trendyol_attribute_value_mappings', true ) : [];
        $attribute_value_mappings = is_array( $attribute_value_mappings ) ? $attribute_value_mappings : [];
        $woo_attributes           = wc_get_attribute_taxonomies();

        // Capture HTML representation of required attributes mapping block
        ob_start();
        if ( ! empty( $required_attributes ) ) {
            ?>
            <div class="wt-taxonomy-attributes-box" style="margin-top: 20px;" data-required-attributes="<?php echo esc_attr( wp_json_encode( $required_attributes ) ); ?>" data-value-mappings="<?php echo esc_attr( wp_json_encode( $attribute_value_mappings ) ); ?>">
                <hr>
                <h4><?php esc_html_e( 'Required Trendyol Attributes', 'woo-trendyol' ); ?></h4>
                <p class="description">
                    <?php esc_html_e( 'Map the required Trendyol attributes to your WooCommerce product attributes. Global mappings (like Gender and Age) are applied automatically if set up in the main settings.', 'woo-trendyol' ); ?>
                </p>
                <table class="form-table">
                     <?php foreach ( $required_attributes as $attr ) : 
                        $attr_id = $attr['id'];
                        $attr_name = $attr['name'];
                        $current_mapping = $attribute_mappings[ $attr_id ] ?? '';

                        $slot = null;
                        $attr_name_lower = mb_strtolower( trim( $attr_name ) );
                        foreach ( Woo_Trendyol_Attribute_Mapper::GLOBAL_ATTR_KEYWORDS as $s => $keywords ) {
                            foreach ( $keywords as $keyword ) {
                                if ( mb_stripos( $attr_name_lower, $keyword ) !== false ) {
                                    $slot = $s;
                                    break 2;
                                }
                            }
                        }

                        $is_globally_mapped = false;
                        $global_wc_attr = '';
                        if ( $slot && in_array( $slot, [ 'gender', 'age', 'age_group', 'color' ], true ) ) {
                            $global_wc_attr = get_option( 'trendyol_global_attr_' . $slot . '_wc', '' );
                            if ( ! empty( $global_wc_attr ) ) {
                                $is_globally_mapped = true;
                            }
                        }
                    ?>
                        <tr>
                            <th scope="row">
                                <label for="trendyol_attr_<?php echo esc_attr( $attr_id ); ?>">
                                    <?php echo esc_html( $attr_name ); ?>
                                </label>
                            </th>
                            <td>
                                <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 8px;">
                                    <select name="trendyol_attribute_mappings[<?php echo esc_attr( $attr_id ); ?>]" id="trendyol_attr_<?php echo esc_attr( $attr_id ); ?>" style="width: 100%; max-width: 400px; min-width: 250px;">
                                        <option value=""><?php esc_html_e( '-- Select WooCommerce Attribute --', 'woo-trendyol' ); ?></option>
                                        <?php foreach ( $woo_attributes as $woo_attr ) : ?>
                                            <option value="<?php echo esc_attr( 'pa_' . $woo_attr->attribute_name ); ?>" <?php selected( $current_mapping, 'pa_' . $woo_attr->attribute_name ); ?>>
                                                <?php echo esc_html( $woo_attr->attribute_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <?php if ( $is_globally_mapped ) : ?>
                                        <div class="wt-global-mapping-notice" style="font-size: 11px; color: #46b450; padding: 4px 8px; background: #ecf7ed; border-left: 4px solid #46b450; display: block; width: 100%; max-width: 400px; box-sizing: border-box;">
                                            <?php printf( 
                                                esc_html__( 'Mapped globally to "%s". Select an attribute here only to override global mapping.', 'woo-trendyol' ),
                                                esc_html( $global_wc_attr )
                                            ); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php 
                                if ( ! empty( $current_mapping ) && taxonomy_exists( $current_mapping ) ) :
                                    $woo_terms = get_terms( [ 'taxonomy' => $current_mapping, 'hide_empty' => false ] );
                                    if ( ! is_wp_error( $woo_terms ) && ! empty( $woo_terms ) ) :
                                        if ( ! empty( $attr['values'] ) ) :
                                ?>
                                    <div class="wt-value-mappings" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; max-height: 250px; overflow-y: auto;">
                                        <strong><?php esc_html_e( 'Map Values:', 'woo-trendyol' ); ?></strong>
                                        <table style="width: 100%; border-collapse: collapse; margin-top: 5px;">
                                            <?php foreach ( $woo_terms as $woo_term ) : 
                                                $saved_ty_val = $attribute_value_mappings[ $attr_id ][ $woo_term->slug ] ?? '';
                                                
                                                // Automap by exact/case-insensitive name matching if no saved mapping exists
                                                if ( empty( $saved_ty_val ) ) {
                                                    $term_name_lower = mb_strtolower( trim( $woo_term->name ) );
                                                    foreach ( $attr['values'] as $ty_val ) {
                                                        if ( mb_strtolower( trim( $ty_val['name'] ) ) === $term_name_lower ) {
                                                            $saved_ty_val = $ty_val['id'];
                                                            break;
                                                        }
                                                    }
                                                }
                                            ?>
                                                <tr style="border-bottom: 1px solid #eee;">
                                                    <td style="padding: 5px 0; font-size: 12px;"><?php echo esc_html( $woo_term->name ); ?></td>
                                                    <td style="padding: 5px 0; text-align: right;">
                                                        <select name="trendyol_attribute_value_mappings[<?php echo esc_attr( $attr_id ); ?>][<?php echo esc_attr( $woo_term->slug ); ?>]" style="font-size: 12px; min-width: 250px; max-width: 100%;">
                                                            <option value=""><?php esc_html_e( '-- Select Trendyol Value --', 'woo-trendyol' ); ?></option>
                                                            <?php foreach ( $attr['values'] as $ty_val ) : ?>
                                                                <option value="<?php echo esc_attr( $ty_val['id'] ); ?>" <?php selected( $saved_ty_val, $ty_val['id'] ); ?>>
                                                                    <?php echo esc_html( $ty_val['name'] ); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </div>
                                <?php 
                                        elseif ( ! empty( $attr['allowCustom'] ) ) :
                                ?>
                                    <div class="wt-custom-values-notice" style="margin-top: 10px; padding: 8px 12px; background: #f0f6fb; border-left: 4px solid #11a0d2; font-size: 11px; color: #50575e;">
                                        <?php esc_html_e( 'This attribute allows custom values. Individual value mapping is not required; your WooCommerce term names will be sent directly.', 'woo-trendyol' ); ?>
                                    </div>
                                <?php
                                        endif;
                                    endif;
                                endif; 
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php
        } else {
            ?>
            <div class="wt-taxonomy-attributes-box" style="margin-top: 20px;">
                <hr>
                <h4><?php esc_html_e( 'Required Trendyol Attributes', 'woo-trendyol' ); ?></h4>
                <p class="description" style="color: #666; font-style: italic;">
                    <?php esc_html_e( 'No required attributes are defined by Trendyol for this category.', 'woo-trendyol' ); ?>
                </p>
            </div>
            <?php
        }
        $html = ob_get_clean();

        wp_send_json_success( [
            'html'    => $html,
            'message' => __( 'Attributes synchronized successfully for this category.', 'woo-trendyol' ),
        ] );
    }

    /**
     * AJAX handler to fetch WooCommerce terms for an attribute.
     *
     * @since 1.0.0
     */
    public function ajax_get_wc_attribute_terms(): void {
        check_ajax_referer( 'woo_trendyol_taxonomy_save', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $wc_attr = isset( $_POST['wc_attr'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_attr'] ) ) : '';

        if ( empty( $wc_attr ) || ! taxonomy_exists( $wc_attr ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid attribute.', 'woo-trendyol' ) ] );
        }

        $terms = get_terms( [
            'taxonomy'   => $wc_attr,
            'hide_empty' => false,
        ] );

        $result = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $result[] = [
                    'slug' => $term->slug,
                    'name' => $term->name,
                ];
            }
        }

        wp_send_json_success( [ 'terms' => $result ] );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Check whether the current admin page is the plugin's settings page.
     *
     * @since  1.0.0
     * @access private
     * @param  string $hook_suffix Current admin page hook suffix.
     * @return bool
     */
    private function is_plugin_admin_page( string $hook_suffix ): bool {
        return str_contains( $hook_suffix, 'woo-trendyol-settings' );
    }

    /**
     * Check whether the current admin page is a product edit screen.
     *
     * @since  1.0.0
     * @access private
     * @param  string $hook_suffix Current admin page hook suffix.
     * @return bool
     */
    private function is_product_edit_page( string $hook_suffix ): bool {
        return in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true )
            && isset( $_GET['post'] )
            && 'product' === get_post_type( absint( $_GET['post'] ) );
    }

    // -----------------------------------------------------------------------
    // HPOS helpers
    // -----------------------------------------------------------------------

    /**
     * Detect whether WooCommerce HPOS (custom order tables) is currently active.
     *
     * Wraps OrderUtil::custom_orders_table_usage_is_enabled() with a class_exists
     * guard so the plugin remains loadable on WooCommerce < 7.1 where OrderUtil
     * does not exist.
     *
     * Use this method when you need to conditionally branch on HPOS status, e.g.:
     *
     *   if ( Woo_Trendyol_Admin::is_hpos_enabled() ) {
     *       // HPOS path: use wc_get_orders(), $order->get_meta(), etc.
     *   } else {
     *       // Legacy path: WP_Query with post_type=shop_order.
     *   }
     *
     * Note: For order data access in this plugin, all code already uses the
     * WC CRUD API (wc_get_order, $order->get_meta, $order->update_meta_data),
     * which is transparently compatible with both storage backends. This helper
     * is provided for any future code that requires explicit branching.
     *
     * @since  1.0.0
     * @return bool True when HPOS tables are the authoritative order store.
     */
    public static function is_hpos_enabled(): bool {
        if ( class_exists( OrderUtil::class ) ) {
            return OrderUtil::custom_orders_table_usage_is_enabled();
        }
        // WC < 7.1: HPOS did not exist, so legacy CPT storage is always in use.
        return false;
    }

    /**
     * Register the Trendyol Order Details metabox on WooCommerce Order edit page.
     *
     * @since 1.0.0
     */
    public function register_order_meta_box(): void {
        $screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'woo-trendyol-order-details',
                __( 'Trendyol Order Details', 'woo-trendyol' ),
                [ $this, 'render_order_meta_box' ],
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the Trendyol Order Details metabox.
     *
     * @since 1.0.0
     * @param WP_Post|object $post_or_object The post or order object.
     */
    public function render_order_meta_box( $post_or_object ): void {
        $order_id = 0;
        if ( is_numeric( $post_or_object ) ) {
            $order_id = (int) $post_or_object;
        } elseif ( $post_or_object instanceof WP_Post ) {
            $order_id = $post_or_object->ID;
        } elseif ( is_a( $post_or_object, 'WP_Screen' ) ) {
            return;
        } elseif ( method_exists( $post_or_object, 'get_id' ) ) {
            $order_id = $post_or_object->get_id();
        }

        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $package_id = $order->get_meta( '_trendyol_package_id', true );
        if ( empty( $package_id ) ) {
            echo '<p>' . esc_html__( 'Not a Trendyol order.', 'woo-trendyol' ) . '</p>';
            return;
        }

        $order_number   = $order->get_meta( '_trendyol_order_number', true );
        $tracking_number= $order->get_meta( '_trendyol_cargo_tracking_number', true );
        $cargo_provider = $order->get_meta( '_trendyol_cargo_provider', true );

        // Query the live package status from Trendyol
        $live_status = __( 'Unknown', 'woo-trendyol' );
        $pkg_res     = $this->api->get_shipment_package( (string) $package_id );
        if ( ! is_wp_error( $pkg_res ) && ! empty( $pkg_res['content'] ) ) {
            $live_status = (string) ( $pkg_res['content'][0]['status'] ?? 'Unknown' );
        }

        // Output UI HTML
        ?>
        <div class="wt-order-metabox-wrapper" style="padding: 5px 0;">
            <p style="margin: 6px 0;">
                <strong><?php esc_html_e( 'Order Number:', 'woo-trendyol' ); ?></strong>
                <code style="float: right;"><?php echo esc_html( $order_number ); ?></code>
            </p>
            <p style="margin: 6px 0;">
                <strong><?php esc_html_e( 'Package ID:', 'woo-trendyol' ); ?></strong>
                <code style="float: right;"><?php echo esc_html( $package_id ); ?></code>
            </p>
            <p style="margin: 6px 0;">
                <strong><?php esc_html_e( 'Cargo Carrier:', 'woo-trendyol' ); ?></strong>
                <span style="float: right;"><?php echo esc_html( $cargo_provider ?: 'ACS' ); ?></span>
            </p>
            <p style="margin: 6px 0;">
                <strong><?php esc_html_e( 'Tracking Number:', 'woo-trendyol' ); ?></strong>
                <code style="float: right;"><?php echo esc_html( $tracking_number ); ?></code>
            </p>
            <p style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px; margin-bottom: 6px;">
                <strong><?php esc_html_e( 'Live Status:', 'woo-trendyol' ); ?></strong>
                <span style="float: right; font-weight: bold; color: #11a0d2;"><?php echo esc_html( $live_status ); ?></span>
            </p>

            <?php if ( ! empty( $tracking_number ) ) : ?>
                <div style="margin-top: 15px; text-align: center;">
                    <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=trendyol_get_shipping_label&order_id=' . $order_id . '&nonce=' . wp_create_nonce( 'trendyol_shipping_label_' . $order_id ) ) ); ?>" 
                       target="_blank" 
                       class="button button-primary" 
                       style="width: 100%; display: block; text-align: center;">
                        <?php esc_html_e( 'Download Shipping Label', 'woo-trendyol' ); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle AJAX request to download a shipping label from Trendyol.
     *
     * @since 1.0.0
     */
    public function ajax_get_shipping_label(): void {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        if ( ! $order_id ) {
            wp_die( esc_html__( 'Invalid order ID.', 'woo-trendyol' ) );
        }

        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'trendyol_shipping_label_' . $order_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'woo-trendyol' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'woo-trendyol' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            wp_die( esc_html__( 'Order not found.', 'woo-trendyol' ) );
        }

        $tracking_number = $order->get_meta( '_trendyol_cargo_tracking_number', true );
        if ( empty( $tracking_number ) ) {
            wp_die( esc_html__( 'Tracking number not found for this order.', 'woo-trendyol' ) );
        }

        $response = $this->api->get_common_label( $tracking_number );
        if ( is_wp_error( $response ) ) {
            wp_die( sprintf( esc_html__( 'Failed to fetch label: %s', 'woo-trendyol' ), $response->get_error_message() ) );
        }

        $label_data = null;
        if ( isset( $response['data'] ) && is_array( $response['data'] ) && ! empty( $response['data'] ) ) {
            $label_data = $response['data'][0];
        } elseif ( is_array( $response ) && ! empty( $response ) ) {
            $label_data = $response[0];
        }

        if ( ! $label_data ) {
            wp_die( esc_html__( 'Label data not found in response.', 'woo-trendyol' ) );
        }

        $format = strtoupper( (string) ( $label_data['format'] ?? 'PDF' ) );
        $label  = (string) ( $label_data['label'] ?? '' );

        if ( empty( $label ) ) {
            wp_die( esc_html__( 'Label content is empty.', 'woo-trendyol' ) );
        }

        if ( 'ZPL' === $format ) {
            header( 'Content-Type: text/plain' );
            header( 'Content-Disposition: attachment; filename="shipping-label-' . $tracking_number . '.zpl"' );
            echo $label;
        } else {
            header( 'Content-Type: application/pdf' );
            header( 'Content-Disposition: inline; filename="shipping-label-' . $tracking_number . '.pdf"' );
            echo base64_decode( $label );
        }
        exit;
    }

}
