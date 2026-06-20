<?php
/**
 * Taxonomy — adds cascading Trendyol category mapping to the product_cat taxonomy.
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
 * Class Woo_Trendyol_Taxonomy
 *
 * Adds a cascading multi-level dropdown to the WooCommerce product category
 * (product_cat) taxonomy screens (Add New and Edit).
 *
 * The dropdowns are driven by the trendyol_categories.json cascade lookup
 * table generated from the official Trendyol category tree.
 *
 * Meta keys stored on each product_cat term:
 *  - trendyol_category_id   — numeric leaf-level Trendyol category ID
 *  - trendyol_category_path — full path string using '|||' as separator
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/admin
 */
class Woo_Trendyol_Taxonomy {

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

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Initialise the class and set its properties.
     *
     * @since 1.0.0
     * @param string $plugin_name The name of this plugin.
     * @param string $version     The version of this plugin.
     */
    public function __construct( string $plugin_name, string $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    // -----------------------------------------------------------------------
    // Asset enqueueing
    // -----------------------------------------------------------------------

    /**
     * Enqueue the taxonomy-specific JS and pass category data to it.
     *
     * Hooked to: admin_enqueue_scripts
     *
     * Only loads on the product_cat taxonomy screens (edit-tags.php / term.php).
     *
     * @since 1.0.0
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_scripts( string $hook_suffix ): void {
        // Only load on taxonomy pages.
        if ( 'edit-tags.php' !== $hook_suffix && 'term.php' !== $hook_suffix ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'product_cat' !== $screen->taxonomy ) {
            return;
        }

        // Enqueue the taxonomy-specific JS.
        wp_enqueue_script(
            $this->plugin_name . '-taxonomy',
            WOO_TRENDYOL_URL . 'admin/js/woo-trendyol-taxonomy.js',
            [ 'jquery' ],
            $this->version,
            true
        );

        // Read the cascade lookup table and flat map from assets/data/.
        $cascade_file  = WOO_TRENDYOL_PATH . 'assets/data/trendyol_categories.json';
        $flat_map_file = WOO_TRENDYOL_PATH . 'assets/data/trendyol_flat_map.json';

        $cascade  = file_exists( $cascade_file )  ? json_decode( file_get_contents( $cascade_file ),  true ) : [];
        $flat_map = file_exists( $flat_map_file ) ? json_decode( file_get_contents( $flat_map_file ), true ) : [];

        // Pass data to JavaScript.
        wp_localize_script(
            $this->plugin_name . '-taxonomy',
            'wooTrendyolTaxonomy',
            [
                'cascade' => $cascade,
                'flatMap' => $flat_map,
                'sep'     => '|||',
                'labels'  => [
                    'selectLevel'      => __( '-- Select Category Level %d --', 'woo-trendyol' ),
                    'incomplete'       => __( 'Incomplete selection', 'woo-trendyol' ),
                    'idLabel'          => __( 'ID: ', 'woo-trendyol' ),
                ],
            ]
        );
    }

    // -----------------------------------------------------------------------
    // Form field renderers
    // -----------------------------------------------------------------------

    /**
     * Add Trendyol category mapping fields to the Add New Category screen.
     *
     * Hooked to: product_cat_add_form_fields
     *
     * @since 1.0.0
     * @param string $taxonomy The taxonomy slug.
     */
    public function add_category_fields( string $taxonomy ): void {
        include WOO_TRENDYOL_PATH . 'admin/partials/woo-trendyol-taxonomy-add.php';
    }

    /**
     * Add Trendyol category mapping fields to the Edit Category screen.
     *
     * Hooked to: product_cat_edit_form_fields
     *
     * @since 1.0.0
     * @param WP_Term $term     Current taxonomy term object.
     * @param string  $taxonomy Current taxonomy slug.
     */
    public function edit_category_fields( WP_Term $term, string $taxonomy ): void {
        // Retrieve existing mapping for pre-population.
        $trendyol_id   = (string) get_term_meta( $term->term_id, 'trendyol_category_id',   true );
        $trendyol_path = (string) get_term_meta( $term->term_id, 'trendyol_category_path', true );

        include WOO_TRENDYOL_PATH . 'admin/partials/woo-trendyol-taxonomy-edit.php';
    }

    // -----------------------------------------------------------------------
    // Save handler
    // -----------------------------------------------------------------------

    /**
     * Save the Trendyol category mapping fields when a category is created or updated.
     *
     * Hooked to: created_product_cat, edited_product_cat
     *
     * @since 1.0.0
     * @param int $term_id Term ID.
     * @param int $tt_id   Term taxonomy ID.
     */
    public function save_category_fields( int $term_id, int $tt_id ): void {
        // Verify nonce.
        if ( ! isset( $_POST['woo_trendyol_taxonomy_nonce'] )
            || ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['woo_trendyol_taxonomy_nonce'] ) ),
                'woo_trendyol_taxonomy_save'
            )
        ) {
            return;
        }

        // Save the Trendyol category ID.
        if ( isset( $_POST['trendyol_category_id'] ) ) {
            $category_id = sanitize_text_field( wp_unslash( $_POST['trendyol_category_id'] ) );
            update_term_meta( $term_id, 'trendyol_category_id', $category_id );
        }

        // Save the Trendyol category path.
        if ( isset( $_POST['trendyol_category_path'] ) ) {
            $category_path = sanitize_text_field( wp_unslash( $_POST['trendyol_category_path'] ) );
            update_term_meta( $term_id, 'trendyol_category_path', $category_path );
        }
    }
}
