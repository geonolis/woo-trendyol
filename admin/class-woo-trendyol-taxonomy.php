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

        wp_enqueue_script(
            $this->plugin_name . '-taxonomy-bulk',
            WOO_TRENDYOL_URL . 'admin/js/woo-trendyol-taxonomy-bulk.js',
            [ 'jquery', $this->plugin_name . '-taxonomy' ],
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
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'woo_trendyol_taxonomy_save' ),
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
        $trendyol_category_extra_percentage = (string) get_term_meta( $term->term_id, 'trendyol_category_extra_percentage', true );
        $exclude_bulk_push = (string) get_term_meta( $term->term_id, 'trendyol_exclude_bulk_push', true );

        $required_attributes = get_term_meta( $term->term_id, '_trendyol_required_attributes', true );
        $required_attributes = is_array( $required_attributes ) ? $required_attributes : [];

        $attribute_mappings = get_term_meta( $term->term_id, '_trendyol_attribute_mappings', true );
        $attribute_mappings = is_array( $attribute_mappings ) ? $attribute_mappings : [];

        $attribute_value_mappings = get_term_meta( $term->term_id, '_trendyol_attribute_value_mappings', true );
        $attribute_value_mappings = is_array( $attribute_value_mappings ) ? $attribute_value_mappings : [];

        // We also need all WooCommerce attributes and custom attributes to populate dropdowns
        $woo_attributes    = wc_get_attribute_taxonomies();
        $custom_attributes = Woo_Trendyol_Attribute_Mapper::get_custom_product_attributes();

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

        // Save Trendyol category extra percentage.
        if ( isset( $_POST['trendyol_category_extra_percentage'] ) ) {
            $extra_pct = sanitize_text_field( wp_unslash( $_POST['trendyol_category_extra_percentage'] ) );
            if ( '' !== $extra_pct ) {
                update_term_meta( $term_id, 'trendyol_category_extra_percentage', $extra_pct );
            } else {
                delete_term_meta( $term_id, 'trendyol_category_extra_percentage' );
            }
        }

        // Save exclude from bulk push option.
        if ( isset( $_POST['trendyol_exclude_bulk_push'] ) && 'yes' === $_POST['trendyol_exclude_bulk_push'] ) {
            update_term_meta( $term_id, 'trendyol_exclude_bulk_push', 'yes' );
        } else {
            update_term_meta( $term_id, 'trendyol_exclude_bulk_push', 'no' );
        }

        // Save attribute mappings.
        if ( isset( $_POST['trendyol_attribute_mappings'] ) && is_array( $_POST['trendyol_attribute_mappings'] ) ) {
            $mappings = [];
            foreach ( $_POST['trendyol_attribute_mappings'] as $attr_id => $woo_attr ) {
                $attr_id = sanitize_text_field( wp_unslash( $attr_id ) );
                $woo_attr = sanitize_text_field( wp_unslash( $woo_attr ) );
                if ( ! empty( $woo_attr ) ) {
                    $mappings[ $attr_id ] = $woo_attr;
                }
            }
            update_term_meta( $term_id, '_trendyol_attribute_mappings', $mappings );
        } else {
            delete_term_meta( $term_id, '_trendyol_attribute_mappings' );
        }

        // Save attribute value mappings.
        if ( isset( $_POST['trendyol_attribute_value_mappings'] ) && is_array( $_POST['trendyol_attribute_value_mappings'] ) ) {
            $value_mappings = [];
            foreach ( $_POST['trendyol_attribute_value_mappings'] as $attr_id => $terms_map ) {
                $attr_id = sanitize_text_field( wp_unslash( $attr_id ) );
                if ( is_array( $terms_map ) ) {
                    foreach ( $terms_map as $woo_term_slug => $ty_val_id ) {
                        $woo_term_slug = sanitize_title( $woo_term_slug );
                        $ty_val_id     = sanitize_text_field( wp_unslash( $ty_val_id ) );
                        if ( '' !== $ty_val_id ) {
                            $value_mappings[ $attr_id ][ $woo_term_slug ] = $ty_val_id;
                        }
                    }
                }
            }
            update_term_meta( $term_id, '_trendyol_attribute_value_mappings', $value_mappings );
        } else {
            delete_term_meta( $term_id, '_trendyol_attribute_value_mappings' );
        }
    }

    /**
     * Add Trendyol Column to Product Category list table.
     *
     * @since 1.0.0
     * @param array $columns Current columns.
     * @return array Modified columns.
     */
    public function add_category_columns( array $columns ): array {
        $columns['trendyol_mapped_cat'] = __( 'Trendyol Category', 'woo-trendyol' );
        return $columns;
    }

    /**
     * Render the Trendyol column content in the Product Category list table.
     *
     * @since 1.0.0
     * @param string $content     Column content.
     * @param string $column_name Column name.
     * @param int    $term_id     Term ID.
     * @return string Column content.
     */
    public function render_category_column_content( string $content, string $column_name, int $term_id ): string {
        if ( 'trendyol_mapped_cat' === $column_name ) {
            $path = get_term_meta( $term_id, 'trendyol_category_path', true );
            $id   = get_term_meta( $term_id, 'trendyol_category_id', true );
            if ( ! empty( $path ) ) {
                // Format path cleanly (e.g. replace '|||' with ' > ')
                $parts = array_map( 'trim', explode( '|||', $path ) );
                $clean_path = implode( ' &gt; ', array_map( 'esc_html', $parts ) );
                $content = sprintf(
                    '<span class="trendyol-mapped-path">%s</span> <code class="trendyol-mapped-id">(ID: %s)</code>',
                    $clean_path,
                    esc_html( $id )
                );
            } else {
                $cat_helper = new Woo_Trendyol_Category_Helper();
                $mapped_parent_id = $cat_helper->get_mapped_term_id( $term_id );
                if ( $mapped_parent_id ) {
                    $parent_path = get_term_meta( $mapped_parent_id, 'trendyol_category_path', true );
                    $parent_trendyol_id = get_term_meta( $mapped_parent_id, 'trendyol_category_id', true );
                    $parts = array_map( 'trim', explode( '|||', $parent_path ) );
                    $clean_path = implode( ' &gt; ', array_map( 'esc_html', $parts ) );
                    $content = sprintf(
                        '<span class="trendyol-mapped-path" style="color: #666;"><span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; background: #e0e0e0; padding: 2px 4px; border-radius: 3px; margin-right: 5px;">Inheriting from Parent</span> %s</span> <code class="trendyol-mapped-id">(ID: %s)</code>',
                        $clean_path,
                        esc_html( $parent_trendyol_id )
                    );
                } else {
                    $content = '<span class="trendyol-not-mapped" style="color: #999; font-style: italic;">' . esc_html__( 'Not mapped', 'woo-trendyol' ) . '</span>';
                }
            }
        }
        return $content;
    }

    /**
     * Register bulk action for mapping Trendyol categories.
     *
     * @param array $bulk_actions
     * @return array
     */
    public function register_bulk_action( array $bulk_actions ): array {
        $bulk_actions['trendyol_map_category'] = __( 'Map Trendyol Category', 'woo-trendyol' );
        return $bulk_actions;
    }

    /**
     * Render the modal HTML in the footer of edit-tags.php.
     */
    public function render_bulk_modal(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'product_cat' !== $screen->taxonomy ) {
            return;
        }
        include WOO_TRENDYOL_PATH . 'admin/partials/woo-trendyol-taxonomy-bulk-modal.php';
    }

    /**
     * AJAX handler to save the bulk mapped categories.
     */
    public function ajax_bulk_map_categories(): void {
        check_ajax_referer( 'woo_trendyol_taxonomy_save', 'nonce' );

        if ( ! current_user_can( 'manage_categories' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $term_ids = isset( $_POST['term_ids'] ) ? array_map( 'absint', (array) $_POST['term_ids'] ) : [];
        $category_id = isset( $_POST['trendyol_category_id'] ) ? sanitize_text_field( wp_unslash( $_POST['trendyol_category_id'] ) ) : '';
        $category_path = isset( $_POST['trendyol_category_path'] ) ? sanitize_text_field( wp_unslash( $_POST['trendyol_category_path'] ) ) : '';

        if ( empty( $term_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'No categories selected.', 'woo-trendyol' ) ] );
        }

        foreach ( $term_ids as $term_id ) {
            if ( '' !== $category_id ) {
                update_term_meta( $term_id, 'trendyol_category_id', $category_id );
                update_term_meta( $term_id, 'trendyol_category_path', $category_path );
            } else {
                delete_term_meta( $term_id, 'trendyol_category_id' );
                delete_term_meta( $term_id, 'trendyol_category_path' );
            }
        }

        wp_send_json_success( [ 'message' => __( 'Categories successfully mapped.', 'woo-trendyol' ) ] );
    }
}
