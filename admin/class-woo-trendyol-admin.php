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
            $this->version,
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
            [ 'trendyol_api_active',          __( 'Enable Integration',        'woo-trendyol' ), 'render_field_toggle'   ],
            [ 'trendyol_seller_id',           __( 'Seller ID',                 'woo-trendyol' ), 'render_field_text'     ],
            [ 'trendyol_api_key',             __( 'API Key',                   'woo-trendyol' ), 'render_field_text'     ],
            [ 'trendyol_api_secret',          __( 'API Secret',                'woo-trendyol' ), 'render_field_password' ],
            [ 'trendyol_storefront_code',     __( 'Storefront Code',           'woo-trendyol' ), 'render_field_text'     ],
            [ 'trendyol_order_poll_interval', __( 'Order Poll Interval (min)', 'woo-trendyol' ), 'render_field_number'   ],
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
        // New option model:
        //  trendyol_global_attr_gender_wc       — pa_* slug of the WC attribute that holds gender
        //  trendyol_global_attr_gender_map      — JSON: { "trendyol_value_id": ["wc_term_slug", …], … }
        //  trendyol_global_attr_age_wc          — pa_* slug of the WC attribute that holds age group
        //  trendyol_global_attr_age_map         — JSON: { "trendyol_value_id": ["wc_term_slug", …], … }
        //  trendyol_global_attr_brand_wc        — pa_* slug for brand (unchanged)
        //  trendyol_global_attr_character_wc    — pa_* slug for character (unchanged)
        $attr_options = [
            'trendyol_global_attr_gender_wc',
            'trendyol_global_attr_gender_map',
            'trendyol_global_attr_age_wc',
            'trendyol_global_attr_age_map',
            'trendyol_global_attr_brand_wc',
            'trendyol_global_attr_character_wc',
        ];
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
            [ 'trendyol_global_attr_gender_wc',   __( 'Gender — WooCommerce Attribute',   'woo-trendyol' ), 'render_field_global_gender'    ],
            [ 'trendyol_global_attr_age_wc',      __( 'Age Group — WooCommerce Attribute', 'woo-trendyol' ), 'render_field_global_age'       ],
            [ 'trendyol_global_attr_brand_wc',    __( 'Brand — WooCommerce Attribute',     'woo-trendyol' ), 'render_field_global_brand'     ],
            [ 'trendyol_global_attr_character_wc',__( 'Character / Hero — WC Attribute',   'woo-trendyol' ), 'render_field_global_character' ],
        ];

        foreach ( $attr_fields as [ $id, $label, $callback ] ) {
            add_settings_field( $id, $label, [ $this, $callback ], 'woo-trendyol-settings', 'woo_trendyol_attrs_section', [ 'label_for' => $id ] );
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

        $trimmed = trim( $value );

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
        $allowed_tabs = [ 'credentials', 'defaults', 'attributes' ];
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
        echo '<p>' . esc_html__( 'Map WooCommerce attributes to their Trendyol equivalents. These global mappings are applied first before per-category or per-product attribute mappings. Optional attributes are always omitted.', 'woo-trendyol' ) . '</p>';
        echo '<p>' . wp_kses_post( __( 'For <strong>Gender</strong> and <strong>Age Group</strong>: first select the WooCommerce attribute that holds those values, then map each Trendyol value to one or more of your WooCommerce terms. Age supports many-to-one mapping (e.g. "από 3 ετών" and "από 4 ετών" can both map to the Trendyol "3-4 Yaş" value).', 'woo-trendyol' ) ) . '</p>';
        echo '<p class="description">' . esc_html__( 'Trendyol gender and age values are category-specific. Enter a sample Trendyol category ID below to load the available values for mapping.', 'woo-trendyol' ) . '</p>';
        echo '<div class="wt-attr-category-loader">';
        echo '<label for="wt-attr-sample-category"><strong>' . esc_html__( 'Sample Trendyol Category ID:', 'woo-trendyol' ) . '</strong></label> ';
        echo '<input type="number" id="wt-attr-sample-category" class="small-text" placeholder="e.g. 1082" min="1" /> ';
        echo '<button type="button" class="button" id="wt-load-attr-values">' . esc_html__( 'Load Trendyol Values', 'woo-trendyol' ) . '</button>';
        echo '<span id="wt-attr-load-spinner" class="spinner" style="float:none;margin-top:0;"></span>';
        echo '<p class="description">' . esc_html__( 'Trendyol gender and age attribute values differ per category. Enter any leaf-level category ID from your catalogue to fetch the available values.', 'woo-trendyol' ) . '</p>';
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
        string $description
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
                    <option value=""><?php esc_html_e( '— Select WC attribute —', 'woo-trendyol' ); ?></option>
                    <?php foreach ( $wc_attrs as $attr ) : ?>
                        <option value="pa_<?php echo esc_attr( $attr->attribute_name ); ?>"
                            <?php selected( $current_wc, 'pa_' . $attr->attribute_name ); ?>>
                            <?php echo esc_html( $attr->attribute_label ); ?>
                            (pa_<?php echo esc_attr( $attr->attribute_name ); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php echo esc_html( $description ); ?></p>
            </div>

            <!-- Step 2: Value mapping table (rendered/updated by JS) -->
            <div class="wt-attr-mapping-table-wrap" id="wt-mapping-table-<?php echo esc_attr( $slot ); ?>">
                <?php if ( ! empty( $wc_terms ) && ! empty( $map_decoded ) ) : ?>
                    <table class="wt-mapping-table widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Trendyol Value', 'woo-trendyol' ); ?></th>
                                <th><?php esc_html_e( 'WooCommerce Terms', 'woo-trendyol' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $map_decoded as $ty_value_id => $mapped_slugs ) : ?>
                                <?php
                                // We only have the ID at this point; the label is fetched by JS.
                                // On initial render we show the ID as a placeholder.
                                ?>
                                <tr data-ty-value-id="<?php echo esc_attr( $ty_value_id ); ?>">
                                    <td class="wt-ty-value-label">
                                        <span class="wt-ty-id-badge"><?php echo esc_html( $ty_value_id ); ?></span>
                                    </td>
                                    <td>
                                        <?php foreach ( $wc_terms as $term ) : ?>
                                            <label class="wt-term-checkbox">
                                                <input type="checkbox"
                                                    name="<?php echo esc_attr( $map_opt ); ?>[<?php echo esc_attr( $ty_value_id ); ?>][]"
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
            __( 'Select the WooCommerce attribute that stores age group (e.g. pa_age). Multiple WC terms can map to a single Trendyol age value — useful for granular age ranges like "από 3 ετών" and "από 4 ετών" mapping to one Trendyol bracket.', 'woo-trendyol' )
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
        $post_id = ( $post_or_object instanceof WP_Post )
            ? $post_or_object->ID
            : (int) $post_or_object->get_id();

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

        $sku = $product->get_sku();
        if ( empty( $sku ) ) {
            wp_send_json_error( [ 'message' => __( 'Product has no SKU. Cannot fetch Trendyol status.', 'woo-trendyol' ) ] );
        }

        $trendyol_product = $this->api->get_product_base( $sku );

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

        $only_unmapped = isset( $_POST['only_unmapped'] ) && '1' === $_POST['only_unmapped'];

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        if ( $only_unmapped ) {
            $args['meta_query'] = [
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

        $product_ids = get_posts( $args );

        wp_send_json_success( [ 'product_ids' => array_map( 'intval', $product_ids ) ] );
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
        $result      = $this->product_creator->push_products( $product_ids );

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
        $gender_names    = Woo_Trendyol_Attribute_Mapper::GLOBAL_ATTR_NAMES['gender'];
        $age_names       = Woo_Trendyol_Attribute_Mapper::GLOBAL_ATTR_NAMES['age'];

        $gender_data = [ 'attr_id' => 0, 'values' => [] ];
        $age_data    = [ 'attr_id' => 0, 'values' => [] ];

        foreach ( $category_attributes as $cat_attr ) {
            $attr_name = (string) ( $cat_attr['attribute']['name'] ?? '' );
            $attr_id   = (int)    ( $cat_attr['attribute']['id']   ?? 0 );
            $raw_vals  = $cat_attr['attributeValues'] ?? [];

            $values = array_map(
                static fn( $v ) => [ 'id' => (int) $v['id'], 'name' => (string) $v['name'] ],
                $raw_vals
            );

            if ( in_array( $attr_name, $gender_names, true ) ) {
                $gender_data = [ 'attr_id' => $attr_id, 'values' => $values ];
            } elseif ( in_array( $attr_name, $age_names, true ) ) {
                $age_data = [ 'attr_id' => $attr_id, 'values' => $values ];
            }
        }

        // Load WC terms for the currently selected WC attributes.
        $gender_data['wc_terms'] = $this->get_wc_terms_for_attr(
            get_option( 'trendyol_global_attr_gender_wc', '' )
        );
        $age_data['wc_terms'] = $this->get_wc_terms_for_attr(
            get_option( 'trendyol_global_attr_age_wc', '' )
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

        wp_send_json_success( [
            'gender'     => $gender_data,
            'age'        => $age_data,
            'saved_maps' => [
                'gender' => $saved_gender_map,
                'age'    => $saved_age_map,
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

        if ( ! in_array( $slot, [ 'gender', 'age' ], true ) || empty( $wc_attr ) ) {
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
        if ( empty( $product->get_sku() ) ) {
            wp_send_json_error( [
                'message' => __( 'Product has no SKU. Please add a SKU before sending to Trendyol.', 'woo-trendyol' ),
            ] );
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
                    foreach ( $items as $item ) {
                        $item_status = $item['status'] ?? 'UNKNOWN';
                        if ( ! empty( $item['failureReasons'] ) ) {
                            $reasons     = array_column( $item['failureReasons'], 'message' );
                            $fail_reason = implode( '; ', $reasons );
                        }
                        break; // Only one product in this batch.
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

        // --- Fetch live Trendyol record to update approval flags ---
        $sku              = $product->get_sku();
        $trendyol_product = $this->api->get_product_base( $sku );

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
}
