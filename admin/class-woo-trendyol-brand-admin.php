<?php
/**
 * Brand Admin — taxonomy column, per-brand remap UI, and brand sync sidebar card.
 *
 * Hooks into the product_brand taxonomy admin screens to:
 *  - Add a "Trendyol Brand" column to the edit-tags table with a green/red dot.
 *  - Render a brand search + remap panel on the Edit Brand page.
 *  - Render the Brand Sync card in the plugin settings sidebar.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/admin
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Woo_Trendyol_Brand_Admin
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/admin
 */
class Woo_Trendyol_Brand_Admin {

    /**
     * The ID of this plugin.
     *
     * @since  1.0.0
     * @access private
     * @var    string
     */
    private string $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since  1.0.0
     * @access private
     * @var    string
     */
    private string $version;

    /**
     * Brand sync service.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Brand_Sync
     */
    private Woo_Trendyol_Brand_Sync $brand_sync;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Initialise the class and set its properties.
     *
     * @since 1.0.0
     * @param string                   $plugin_name The name of this plugin.
     * @param string                   $version     The version of this plugin.
     * @param Woo_Trendyol_Brand_Sync  $brand_sync  Brand sync service.
     */
    public function __construct(
        string $plugin_name,
        string $version,
        Woo_Trendyol_Brand_Sync $brand_sync
    ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
        $this->brand_sync  = $brand_sync;
    }

    // -----------------------------------------------------------------------
    // Scripts & styles
    // -----------------------------------------------------------------------

    /**
     * Enqueue scripts and styles on the product_brand taxonomy screens.
     *
     * @since 1.0.0
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_scripts( string $hook_suffix ): void {
        $is_brand_screen = (
            in_array( $hook_suffix, [ 'edit-tags.php', 'term.php' ], true ) &&
            isset( $_GET['taxonomy'] ) &&
            'product_brand' === sanitize_key( $_GET['taxonomy'] )
        );

        $is_settings_screen = (
            'woocommerce_page_woo-trendyol' === $hook_suffix
        );

        if ( ! $is_brand_screen && ! $is_settings_screen ) {
            return;
        }

        wp_enqueue_script(
            $this->plugin_name . '-brand-admin',
            plugin_dir_url( dirname( __FILE__ ) ) . 'admin/js/woo-trendyol-brand-admin.js',
            [ 'jquery' ],
            $this->version,
            true
        );

        wp_localize_script(
            $this->plugin_name . '-brand-admin',
            'wtBrand',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'woo_trendyol_admin' ),
                'i18n'    => [
                    'searching'       => __( 'Searching…', 'woo-trendyol' ),
                    'noResults'       => __( 'No matching Trendyol brands found.', 'woo-trendyol' ),
                    'saved'           => __( 'Mapping saved.', 'woo-trendyol' ),
                    'cleared'         => __( 'Mapping cleared.', 'woo-trendyol' ),
                    'error'           => __( 'An error occurred. Please try again.', 'woo-trendyol' ),
                    'select'          => __( 'Select', 'woo-trendyol' ),
                    'syncingBrands'   => __( 'Syncing brands…', 'woo-trendyol' ),
                    'syncComplete'    => __( 'Brand sync complete.', 'woo-trendyol' ),
                    'matched'         => __( 'Matched', 'woo-trendyol' ),
                    'notFound'        => __( 'Not found', 'woo-trendyol' ),
                    'syncError'       => __( 'Error', 'woo-trendyol' ),
                    'paused'          => __( 'Paused', 'woo-trendyol' ),
                    'resuming'        => __( 'Resuming…', 'woo-trendyol' ),
                    'confirmClear'    => __( 'Clear the Trendyol brand mapping for this brand?', 'woo-trendyol' ),
                ],
            ]
        );
    }

    // -----------------------------------------------------------------------
    // Taxonomy column: edit-tags.php
    // -----------------------------------------------------------------------

    /**
     * Register the Trendyol Brand column in the product_brand term table.
     *
     * Hooked to: manage_product_brand_columns
     *
     * @since 1.0.0
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function add_brand_column( array $columns ): array {
        $columns['trendyol_brand'] = __( 'Trendyol', 'woo-trendyol' );
        return $columns;
    }

    /**
     * Render the Trendyol Brand column cell for each term row.
     *
     * Hooked to: manage_product_brand_custom_column
     *
     * @since 1.0.0
     * @param string $content     Existing cell content.
     * @param string $column_name The column slug.
     * @param int    $term_id     The term ID.
     * @return string HTML for the cell.
     */
    public function render_brand_column( string $content, string $column_name, int $term_id ): string {
        if ( 'trendyol_brand' !== $column_name ) {
            return $content;
        }

        $brand_id   = $this->brand_sync->get_brand_id_for_term( $term_id );
        $brand_name = (string) get_term_meta( $term_id, Woo_Trendyol_Brand_Sync::META_NAME_KEY, true );

        if ( $brand_id ) {
            return sprintf(
                '<span class="wt-brand-dot wt-brand-dot--matched" title="%s">&#9679;</span> <small>%s</small>',
                esc_attr( sprintf( __( 'Mapped to Trendyol brand: %s (ID %d)', 'woo-trendyol' ), $brand_name, $brand_id ) ),
                esc_html( $brand_id )
            );
        }

        return sprintf(
            '<span class="wt-brand-dot wt-brand-dot--unmatched" title="%s">&#9679;</span>',
            esc_attr( __( 'No Trendyol brand mapped', 'woo-trendyol' ) )
        );
    }

    /**
     * Make the Trendyol Brand column sortable.
     *
     * Hooked to: manage_edit-product_brand_sortable_columns
     *
     * @since 1.0.0
     * @param array $sortable Existing sortable columns.
     * @return array Modified sortable columns.
     */
    public function sortable_brand_column( array $sortable ): array {
        $sortable['trendyol_brand'] = 'trendyol_brand';
        return $sortable;
    }

    // -----------------------------------------------------------------------
    // Per-brand remap UI: term.php (Edit Brand page)
    // -----------------------------------------------------------------------

    /**
     * Render the Trendyol brand mapping panel on the Edit Brand page.
     *
     * Hooked to: product_brand_edit_form_fields
     *
     * @since 1.0.0
     * @param WP_Term $term     The term being edited.
     * @param string  $taxonomy The taxonomy slug.
     */
    public function render_brand_edit_fields( WP_Term $term, string $taxonomy ): void {
        if ( 'product_brand' !== $taxonomy ) {
            return;
        }

        $brand_id   = $this->brand_sync->get_brand_id_for_term( $term->term_id );
        $brand_name = (string) get_term_meta( $term->term_id, Woo_Trendyol_Brand_Sync::META_NAME_KEY, true );

        include plugin_dir_path( __FILE__ ) . 'partials/woo-trendyol-brand-edit-fields.php';
    }

    // -----------------------------------------------------------------------
    // Brand sync sidebar card (rendered from settings page partial)
    // -----------------------------------------------------------------------

    /**
     * Return the HTML for the brand sync sidebar card.
     *
     * Called directly from the settings page partial.
     *
     * @since 1.0.0
     * @return string HTML string.
     */
    public function render_brand_sync_card(): string {
        if ( ! taxonomy_exists( 'product_brand' ) ) {
            return sprintf(
                '<div class="wt-card wt-card--notice"><p>%s</p></div>',
                esc_html__( 'WooCommerce Brands is not active. Enable it to use brand sync.', 'woo-trendyol' )
            );
        }

        $total_brands = wp_count_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false ] );
        if ( is_wp_error( $total_brands ) ) {
            $total_brands = 0;
        }

        ob_start();
        ?>
        <div class="wt-card" id="wt-brand-sync-card">
            <h3><?php esc_html_e( 'Sync Brands to Trendyol', 'woo-trendyol' ); ?></h3>
            <p class="wt-card-desc">
                <?php
                printf(
                    /* translators: %d: number of brands */
                    esc_html__( 'Match all %d WooCommerce brands to their Trendyol equivalents.', 'woo-trendyol' ),
                    (int) $total_brands
                );
                ?>
            </p>

            <div class="wt-brand-sync-controls">
                <button type="button" id="wt-btn-sync-brands" class="button button-secondary">
                    <?php esc_html_e( 'Sync Brands', 'woo-trendyol' ); ?>
                </button>
                <button type="button" id="wt-btn-pause-brands" class="button" style="display:none;">
                    <?php esc_html_e( 'Pause', 'woo-trendyol' ); ?>
                </button>
                <span class="spinner" id="wt-brand-spinner" style="float:none;"></span>
            </div>

            <div id="wt-brand-progress-wrap" style="display:none; margin-top:10px;">
                <div class="wt-progress-bar-track">
                    <div class="wt-progress-bar-fill" id="wt-brand-progress-fill" style="width:0%"></div>
                </div>
                <p class="wt-progress-label" id="wt-brand-progress-label">0 / 0</p>
            </div>

            <div id="wt-brand-sync-results" style="display:none; margin-top:10px; max-height:220px; overflow-y:auto;">
                <table class="wt-brand-results-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Brand', 'woo-trendyol' ); ?></th>
                            <th><?php esc_html_e( 'Trendyol Match', 'woo-trendyol' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'woo-trendyol' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wt-brand-results-body"></tbody>
                </table>
            </div>

            <div id="wt-brand-sync-totals" style="display:none; margin-top:8px;">
                <strong><?php esc_html_e( 'Results:', 'woo-trendyol' ); ?></strong>
                <span id="wt-brand-total-matched">0</span> <?php esc_html_e( 'matched', 'woo-trendyol' ); ?>,
                <span id="wt-brand-total-not-found">0</span> <?php esc_html_e( 'not found', 'woo-trendyol' ); ?>,
                <span id="wt-brand-total-errors">0</span> <?php esc_html_e( 'errors', 'woo-trendyol' ); ?>
            </div>

            <?php if ( $total_brands > 0 ) : ?>
            <p style="margin-top:8px;">
                <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=product_brand&post_type=product' ) ); ?>">
                    <?php esc_html_e( 'View all brands →', 'woo-trendyol' ); ?>
                </a>
            </p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Register brand taxonomy admin hooks if the product_brand taxonomy exists.
     *
     * Hooked to: init
     *
     * @since 1.0.0
     */
    public function maybe_register_brand_hooks(): void {
        if ( ! taxonomy_exists( 'product_brand' ) ) {
            return;
        }

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_filter( 'manage_edit-product_brand_columns', [ $this, 'add_brand_column' ] );
        add_filter( 'manage_product_brand_custom_column', [ $this, 'render_brand_column' ], 10, 3 );
        add_filter( 'manage_edit-product_brand_sortable_columns', [ $this, 'sortable_brand_column' ] );
        add_action( 'product_brand_edit_form_fields', [ $this, 'render_brand_edit_fields' ], 10, 2 );
    }

    /**
     * AJAX handler for syncing WooCommerce brands to Trendyol.
     *
     * Delegates to the brand sync service depending on the 'step' parameter.
     *
     * @since 1.0.0
     */
    public function ajax_sync_brands(): void {
        $step = isset( $_POST['step'] ) ? sanitize_key( $_POST['step'] ) : '';

        if ( 'get_brands' === $step ) {
            $this->brand_sync->ajax_get_brands_to_sync();
        } elseif ( 'sync_batch' === $step ) {
            $this->brand_sync->ajax_sync_brands_batch();
        } else {
            wp_send_json_error( [ 'message' => __( 'Invalid step.', 'woo-trendyol' ) ] );
        }
    }

    /**
     * AJAX handler for searching Trendyol brands.
     *
     * Delegates to the brand sync service.
     *
     * @since 1.0.0
     */
    public function ajax_search_brand(): void {
        $this->brand_sync->ajax_search_brand();
    }

    /**
     * AJAX handler for saving manually selected brand mapping.
     *
     * Delegates to the brand sync service.
     *
     * @since 1.0.0
     */
    public function ajax_save_brand_mapping(): void {
        $this->brand_sync->ajax_save_brand_mapping();
    }
}
