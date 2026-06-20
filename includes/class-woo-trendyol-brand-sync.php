<?php
/**
 * Brand Sync — matches WooCommerce Brands terms to Trendyol brand IDs.
 *
 * Iterates over all product_brand taxonomy terms, calls the Trendyol
 * brands-by-name endpoint for each, selects the first result, and stores
 * the matched Trendyol brand ID as term meta.
 *
 * Meta key: trendyol_brand_id  (stored on product_brand terms)
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
 * Class Woo_Trendyol_Brand_Sync
 *
 * Provides:
 *  - ajax_sync_brands()          — batch-processes all product_brand terms
 *  - ajax_search_brand()         — live search for a single brand name
 *  - ajax_save_brand_mapping()   — save a manually chosen Trendyol brand ID
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_Brand_Sync {

    /**
     * Term meta key used to store the matched Trendyol brand ID.
     *
     * @since 1.0.0
     * @var   string
     */
    const META_KEY = 'trendyol_brand_id';

    /**
     * Term meta key used to store the matched Trendyol brand name (for display).
     *
     * @since 1.0.0
     * @var   string
     */
    const META_NAME_KEY = 'trendyol_brand_name';

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
     * Shared API client.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_API_Client
     */
    private Woo_Trendyol_API_Client $api;

    /**
     * Shared logger.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Logger
     */
    private Woo_Trendyol_Logger $logger;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Initialise the class and set its properties.
     *
     * @since 1.0.0
     * @param string                  $plugin_name The name of this plugin.
     * @param string                  $version     The version of this plugin.
     * @param Woo_Trendyol_API_Client $api         Shared API client.
     * @param Woo_Trendyol_Logger     $logger      Shared logger.
     */
    public function __construct(
        string $plugin_name,
        string $version,
        Woo_Trendyol_API_Client $api,
        Woo_Trendyol_Logger $logger
    ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
        $this->api         = $api;
        $this->logger      = $logger;
    }

    // -----------------------------------------------------------------------
    // AJAX: batch brand sync
    // -----------------------------------------------------------------------

    /**
     * Return the list of all product_brand term IDs to sync.
     *
     * Action: wp_ajax_trendyol_get_brands_to_sync
     *
     * Response: { term_ids: int[] }
     *
     * @since 1.0.0
     */
    public function ajax_get_brands_to_sync(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        if ( ! taxonomy_exists( 'product_brand' ) ) {
            wp_send_json_error( [ 'message' => __( 'WooCommerce Brands taxonomy is not active.', 'woo-trendyol' ) ] );
        }

        $terms = get_terms( [
            'taxonomy'   => 'product_brand',
            'hide_empty' => false,
            'fields'     => 'ids',
        ] );

        if ( is_wp_error( $terms ) ) {
            wp_send_json_error( [ 'message' => $terms->get_error_message() ] );
        }

        wp_send_json_success( [ 'term_ids' => array_map( 'intval', $terms ) ] );
    }

    /**
     * Sync a single batch of brand term IDs.
     *
     * Accepts: $_POST['term_ids'] — JSON array of term IDs (up to 20 per call).
     *
     * Returns per-term results:
     * [
     *   { term_id, name, status: 'matched'|'not_found'|'error', trendyol_id, trendyol_name },
     *   …
     * ]
     *
     * Action: wp_ajax_trendyol_sync_brands_batch
     *
     * @since 1.0.0
     */
    public function ajax_sync_brands_batch(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $raw_ids = isset( $_POST['term_ids'] )
            ? json_decode( sanitize_text_field( wp_unslash( $_POST['term_ids'] ) ), true )
            : [];

        if ( empty( $raw_ids ) || ! is_array( $raw_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'No term IDs provided.', 'woo-trendyol' ) ] );
        }

        $results = [];

        foreach ( array_map( 'absint', $raw_ids ) as $term_id ) {
            $term = get_term( $term_id, 'product_brand' );
            if ( ! $term || is_wp_error( $term ) ) {
                $results[] = [
                    'term_id'       => $term_id,
                    'name'          => '',
                    'status'        => 'error',
                    'trendyol_id'   => 0,
                    'trendyol_name' => '',
                    'message'       => __( 'Term not found.', 'woo-trendyol' ),
                ];
                continue;
            }

            $result = $this->match_brand_term( $term );
            $results[] = $result;
        }

        wp_send_json_success( [ 'results' => $results ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: live brand search (for per-term remap UI)
    // -----------------------------------------------------------------------

    /**
     * Search Trendyol for brands matching a given name string.
     *
     * Accepts: $_POST['name'] — search string.
     *
     * Returns: { brands: [ { id, name }, … ] }
     *
     * Action: wp_ajax_trendyol_search_brand
     *
     * @since 1.0.0
     */
    public function ajax_search_brand(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $name = isset( $_POST['name'] )
            ? sanitize_text_field( wp_unslash( $_POST['name'] ) )
            : '';

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'No search name provided.', 'woo-trendyol' ) ] );
        }

        $response = $this->api->search_brands( $name );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        // Normalise the response — Trendyol may return a flat array or wrap in 'brands'.
        $brands = $this->normalise_brand_list( $response );

        wp_send_json_success( [ 'brands' => $brands ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: save a manually chosen brand mapping
    // -----------------------------------------------------------------------

    /**
     * Save a manually chosen Trendyol brand ID to a product_brand term.
     *
     * Accepts:
     *  $_POST['term_id']       — WC product_brand term ID.
     *  $_POST['trendyol_id']   — Trendyol brand ID to save.
     *  $_POST['trendyol_name'] — Trendyol brand name (for display).
     *
     * Action: wp_ajax_trendyol_save_brand_mapping
     *
     * @since 1.0.0
     */
    public function ajax_save_brand_mapping(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        $term_id       = isset( $_POST['term_id'] )       ? absint( $_POST['term_id'] )                                          : 0;
        $trendyol_id   = isset( $_POST['trendyol_id'] )   ? absint( $_POST['trendyol_id'] )                                      : 0;
        $trendyol_name = isset( $_POST['trendyol_name'] ) ? sanitize_text_field( wp_unslash( $_POST['trendyol_name'] ) )          : '';

        if ( ! $term_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid term ID.', 'woo-trendyol' ) ] );
        }

        if ( $trendyol_id ) {
            update_term_meta( $term_id, self::META_KEY,      $trendyol_id );
            update_term_meta( $term_id, self::META_NAME_KEY, $trendyol_name );
            $this->logger->info(
                sprintf( 'Brand mapping saved: term %d → Trendyol brand %d (%s)', $term_id, $trendyol_id, $trendyol_name )
            );
            wp_send_json_success( [
                'message'       => __( 'Brand mapping saved.', 'woo-trendyol' ),
                'trendyol_id'   => $trendyol_id,
                'trendyol_name' => $trendyol_name,
            ] );
        } else {
            // Clearing the mapping.
            delete_term_meta( $term_id, self::META_KEY );
            delete_term_meta( $term_id, self::META_NAME_KEY );
            $this->logger->info( sprintf( 'Brand mapping cleared for term %d.', $term_id ) );
            wp_send_json_success( [ 'message' => __( 'Brand mapping cleared.', 'woo-trendyol' ) ] );
        }
    }

    // -----------------------------------------------------------------------
    // Public helpers
    // -----------------------------------------------------------------------

    /**
     * Get the stored Trendyol brand ID for a product_brand term.
     *
     * @since 1.0.0
     * @param int $term_id The product_brand term ID.
     * @return int Trendyol brand ID, or 0 if not mapped.
     */
    public function get_brand_id_for_term( int $term_id ): int {
        return (int) get_term_meta( $term_id, self::META_KEY, true );
    }

    /**
     * Resolve the Trendyol brand ID for a WooCommerce product.
     *
     * Reads the product's product_brand terms and returns the stored
     * trendyol_brand_id from the first matched term.
     *
     * Falls back to 0 if no mapping exists.
     *
     * @since 1.0.0
     * @param WC_Product $product The WooCommerce product.
     * @return int Trendyol brand ID, or 0 if not found.
     */
    public function get_brand_id_for_product( WC_Product $product ): int {
        if ( ! taxonomy_exists( 'product_brand' ) ) {
            return 0;
        }

        $terms = get_the_terms( $product->get_id(), 'product_brand' );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return 0;
        }

        foreach ( $terms as $term ) {
            $brand_id = $this->get_brand_id_for_term( $term->term_id );
            if ( $brand_id ) {
                return $brand_id;
            }
        }

        return 0;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Match a single product_brand term to a Trendyol brand.
     *
     * Calls the Trendyol brands-by-name endpoint, selects the first result,
     * and stores the matched brand ID and name as term meta.
     *
     * @since  1.0.0
     * @access private
     * @param  WP_Term $term The product_brand term.
     * @return array   Result array with keys: term_id, name, status, trendyol_id, trendyol_name.
     */
    private function match_brand_term( WP_Term $term ): array {
        $base = [
            'term_id'       => $term->term_id,
            'name'          => $term->name,
            'trendyol_id'   => 0,
            'trendyol_name' => '',
        ];

        $response = $this->api->search_brands( $term->name );

        if ( is_wp_error( $response ) ) {
            $this->logger->error(
                sprintf( 'Brand sync error for "%s": %s', $term->name, $response->get_error_message() )
            );
            return array_merge( $base, [
                'status'  => 'error',
                'message' => $response->get_error_message(),
            ] );
        }

        $brands = $this->normalise_brand_list( $response );

        if ( empty( $brands ) ) {
            // No match — clear any stale meta.
            delete_term_meta( $term->term_id, self::META_KEY );
            delete_term_meta( $term->term_id, self::META_NAME_KEY );
            $this->logger->info( sprintf( 'Brand sync: no match found for "%s".', $term->name ) );
            return array_merge( $base, [ 'status' => 'not_found' ] );
        }

        // Always select the first result (Trendyol returns best-match first).
        $matched      = $brands[0];
        $trendyol_id  = (int)    ( $matched['id']   ?? 0 );
        $trendyol_name = (string) ( $matched['name'] ?? '' );

        update_term_meta( $term->term_id, self::META_KEY,      $trendyol_id );
        update_term_meta( $term->term_id, self::META_NAME_KEY, $trendyol_name );

        $this->logger->info(
            sprintf( 'Brand sync: "%s" → Trendyol brand %d (%s).', $term->name, $trendyol_id, $trendyol_name )
        );

        return array_merge( $base, [
            'status'        => 'matched',
            'trendyol_id'   => $trendyol_id,
            'trendyol_name' => $trendyol_name,
        ] );
    }

    /**
     * Normalise the Trendyol brand search response into a flat array.
     *
     * The API may return:
     *  - A flat array:        [ { id, name }, … ]
     *  - A wrapped object:    { brands: [ { id, name }, … ] }
     *
     * @since  1.0.0
     * @access private
     * @param  mixed $response Raw API response.
     * @return array  Flat array of { id, name } objects.
     */
    private function normalise_brand_list( mixed $response ): array {
        if ( ! is_array( $response ) ) {
            return [];
        }

        // Wrapped: { brands: [ … ] }
        if ( isset( $response['brands'] ) && is_array( $response['brands'] ) ) {
            return $response['brands'];
        }

        // Flat array of brand objects.
        if ( isset( $response[0] ) && is_array( $response[0] ) ) {
            return $response;
        }

        return [];
    }
}
