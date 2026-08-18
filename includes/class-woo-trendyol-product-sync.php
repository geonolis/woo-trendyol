<?php
/**
 * Product Sync — pushes price, stock, and image changes to Trendyol.
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
 * Class Woo_Trendyol_Product_Sync
 *
 * Listens to WooCommerce product save and attachment update hooks and pushes
 * the relevant changes to Trendyol via the API client.
 *
 * Hook responsibilities:
 *  - on_product_saved()       — fires after any product save; syncs price + stock
 *  - on_post_meta_updated()   — fires when _price/_stock meta changes; triggers sync
 *  - on_attachment_updated()  — fires when a media attachment is updated; syncs images
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_Product_Sync {

    /**
     * The ID of this plugin.
     *
     * @since  1.0.0
     * @access private
     * @var    string $plugin_name
     */
    private string $plugin_name;

    /**
     * The version of this plugin.
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
     * Category helper — resolves Trendyol category IDs from product terms.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Category_Helper $category_helper
     */
    private Woo_Trendyol_Category_Helper $category_helper;

    /**
     * Post meta keys that trigger a price/stock sync when changed.
     *
     * @since  1.0.0
     * @access private
     * @var    string[] PRICE_STOCK_META_KEYS
     */
    private const PRICE_STOCK_META_KEYS = [
        '_price',
        '_regular_price',
        '_sale_price',
        '_stock',
        '_stock_quantity',
        '_stock_status',
    ];

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Initialise the class and set its properties.
     *
     * @since 1.0.0
     * @param string                         $plugin_name     The name of this plugin.
     * @param string                         $version         The version of this plugin.
     * @param Woo_Trendyol_API_Client        $api             Shared API client.
     * @param Woo_Trendyol_Logger            $logger          Shared logger.
     * @param Woo_Trendyol_Category_Helper   $category_helper Category resolution helper.
     */
    public function __construct(
        string $plugin_name,
        string $version,
        Woo_Trendyol_API_Client $api,
        Woo_Trendyol_Logger $logger,
        Woo_Trendyol_Category_Helper $category_helper
    ) {
        $this->plugin_name     = $plugin_name;
        $this->version         = $version;
        $this->api             = $api;
        $this->logger          = $logger;
        $this->category_helper = $category_helper;
    }

    // -----------------------------------------------------------------------
    // Hook callbacks (registered by Woo_Trendyol via the Loader)
    // -----------------------------------------------------------------------

    /**
     * Sync price and stock after a product is saved.
     *
     * Hooked to: woocommerce_after_product_object_save
     *
     * IMPORTANT — correct hook signature:
     *   do_action( 'woocommerce_after_product_object_save', WC_Product $product, WC_Data_Store $data_store )
     *
     * The second argument is the WC_Data_Store instance, NOT an array of changed
     * keys. Declaring it as `array $changed` causes a fatal TypeError because PHP
     * enforces strict type hints on hook callbacks since PHP 8.0.
     *
     * The changed-keys array is only available earlier in the save cycle via
     * woocommerce_before_product_object_save and is not passed to this hook.
     * We therefore sync unconditionally and rely on a short-lived transient to
     * debounce duplicate calls within the same request cycle.
     *
     * @since 1.0.0
     * @param WC_Product    $product    The saved product object.
     * @param WC_Data_Store $data_store The data store instance (required by hook, not used here).
     */
    public function on_product_saved( WC_Product $product, $data_store = null ): void {
        if ( ! $this->api->is_active() ) {
            return;
        }

        $product_id = $product->get_id();

        /*
         * Debounce: skip if we already triggered a sync for this product in the
         * current request cycle. WooCommerce sometimes saves a product object
         * more than once per request (e.g. during variation bulk edits or when
         * other plugins call $product->save()). The transient expires after
         * 5 seconds — long enough to cover within-request duplicates, short
         * enough not to suppress legitimate saves in subsequent requests.
         */
        $debounce_key = 'wt_sync_debounce_' . $product_id;
        if ( get_transient( $debounce_key ) ) {
            return;
        }
        set_transient( $debounce_key, 1, 5 );

        $this->sync_price_and_stock( $product );
    }

    /**
     * Sync price and stock when a price/stock post meta key is updated.
     *
     * Hooked to: updated_post_meta, added_post_meta
     *
     * @since 1.0.0
     * @param int    $meta_id    Meta ID.
     * @param int    $post_id    Post ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value New meta value.
     */
    public function on_post_meta_updated( int $meta_id, int $post_id, string $meta_key, mixed $meta_value ): void {
        if ( ! $this->api->is_active() ) {
            return;
        }

        // Only react to price/stock meta keys on product post types.
        if ( ! in_array( $meta_key, self::PRICE_STOCK_META_KEYS, true ) ) {
            return;
        }

        if ( 'product' !== get_post_type( $post_id ) && 'product_variation' !== get_post_type( $post_id ) ) {
            return;
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            return;
        }

        $this->sync_price_and_stock( $product );
    }

    /**
     * Sync price and stock when stock is set via woocommerce_product_set_stock or woocommerce_variation_set_stock.
     *
     * Hooked to: woocommerce_product_set_stock, woocommerce_variation_set_stock
     *
     * @since 1.0.0
     * @param WC_Product $product The product or variation.
     */
    public function on_product_stock_set( WC_Product $product ): void {
        if ( ! $this->api->is_active() ) {
            return;
        }

        $this->sync_price_and_stock( $product );
    }

    /**
     * Sync product images when a media attachment is updated.
     *
     * Hooked to: edit_attachment
     *
     * Finds all products that use this attachment as their featured image
     * or gallery image and pushes the updated image URLs to Trendyol.
     *
     * @since 1.0.0
     * @param int $attachment_id The attachment post ID.
     */
    public function on_attachment_updated( int $attachment_id ): void {
        if ( ! $this->api->is_active() ) {
            return;
        }

        // Find products that use this attachment as their featured image.
        $products_with_featured = $this->get_products_by_thumbnail( $attachment_id );

        // Find products that include this attachment in their gallery.
        $products_with_gallery = $this->get_products_by_gallery_image( $attachment_id );

        // Merge and deduplicate.
        $product_ids = array_unique( array_merge( $products_with_featured, $products_with_gallery ) );

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $this->sync_images( $product );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Sync methods
    // -----------------------------------------------------------------------

    /**
     * Build the price/stock payload and push it to Trendyol.
     *
     * Handles both simple products and variable products (syncs each variation).
     *
     * @since 1.0.0
     * @param WC_Product $product The product to sync.
     */
    public function sync_price_and_stock( WC_Product $product ): void {
        $items = [];

        if ( $product->is_type( 'variable' ) ) {
            // Sync all published variations.
            /** @var WC_Product_Variable $product */
            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( $variation && 'publish' === $variation->get_status() ) {
                    $item = $this->build_price_stock_item( $variation );
                    if ( $item ) {
                        $items[] = $item;
                    }
                }
            }
        } else {
            // Simple / external / grouped product.
            $item = $this->build_price_stock_item( $product );
            if ( $item ) {
                $items[] = $item;
            }
        }

        if ( empty( $items ) ) {
            $this->logger->warning(
                sprintf( 'Product ID %d has no syncable items (missing SKU?).', $product->get_id() )
            );
            return;
        }

        $response = $this->api->update_price_and_stock( $items );

        if ( is_wp_error( $response ) ) {
            $this->logger->error(
                sprintf( 'Price/stock sync failed for product %d: %s', $product->get_id(), $response->get_error_message() )
            );
            $this->write_sync_meta( $product->get_id(), 'error', $response->get_error_message() );
            return;
        }

        // Record successful sync metadata.
        $batch_id = $response['batchRequestId'] ?? '';
        $this->write_sync_meta( $product->get_id(), 'success', '', $batch_id );

        // Store the last synced values for display in the meta box.
        update_post_meta( $product->get_id(), '_trendyol_last_price', $product->get_price() );
        update_post_meta(
            $product->get_id(),
            '_trendyol_last_stock',
            $product->managing_stock() ? $product->get_stock_quantity() : 'N/A'
        );

        $this->logger->info(
            sprintf( 'Price/stock synced for product %d. Batch: %s', $product->get_id(), $batch_id )
        );
    }

    /**
     * Build the image payload and push it to Trendyol.
     *
     * @since 1.0.0
     * @param WC_Product $product The product to sync images for.
     */
    public function sync_images( WC_Product $product ): void {
        $sku = $product->get_sku();
        if ( empty( $sku ) ) {
            return;
        }

        $images = $this->collect_image_urls( $product );
        if ( empty( $images ) ) {
            return;
        }

        $response = $this->api->update_product_images( [
            [
                'barcode' => $sku,
                'images'  => $images,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            $this->logger->error(
                sprintf( 'Image sync failed for product %d: %s', $product->get_id(), $response->get_error_message() )
            );
            return;
        }

        $this->logger->info(
            sprintf( 'Images synced for product %d (%d images).', $product->get_id(), count( $images ) )
        );
    }

    // -----------------------------------------------------------------------
    // Private item builders
    // -----------------------------------------------------------------------

    /**
     * Build a single price/stock item array for the Trendyol API payload.
     *
     * Returns null when the product has no SKU (barcode), as Trendyol requires
     * a barcode to identify the product.
     *
     * For variations, the category lookup walks up to the parent product.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Product $product Product or variation.
     * @return array|null  Item array, or null if SKU is missing.
     */
    private function build_price_stock_item( WC_Product $product ): ?array {
        $sku = $product->get_sku();
        if ( empty( $sku ) ) {
            return null;
        }

        $prices     = $this->category_helper->get_final_trendyol_prices( $product );
        $list_price = $prices['listPrice'];
        $sale_price = $prices['salePrice'];

        // Determine stock quantity.
        $quantity = $product->managing_stock()
            ? max( 0, (int) $product->get_stock_quantity() )
            : ( $product->is_in_stock() ? 999 : 0 );

        // Resolve the Trendyol category ID.
        // For variations, walk up to the parent product for the category lookup.
        $lookup_id   = $product->is_type( 'variation' )
            ? $product->get_parent_id()
            : $product->get_id();
        $category_id = $this->category_helper->get_trendyol_category_id( $lookup_id );

        $item = [
            'barcode'   => $sku,
            'quantity'  => $quantity,
            'salePrice' => $sale_price,
            'listPrice' => $list_price,
        ];

        // Only include categoryId when a valid mapping exists.
        // Trendyol rejects price/inventory updates with an invalid or empty categoryId.
        if ( ! empty( $category_id ) ) {
            $item['categoryId'] = (int) $category_id;
        }

        return $item;
    }

    /**
     * Collect all image URLs for a product (featured image + gallery).
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Product $product The product.
     * @return array  Array of absolute image URL strings.
     */
    private function collect_image_urls( WC_Product $product ): array {
        $urls = [];

        // Featured image.
        $thumbnail_id = $product->get_image_id();
        if ( $thumbnail_id ) {
            $url = wp_get_attachment_url( $thumbnail_id );
            if ( $url ) {
                $urls[] = $url;
            }
        }

        // Gallery images.
        foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
            $url = wp_get_attachment_url( $gallery_id );
            if ( $url ) {
                $urls[] = $url;
            }
        }

        $unique_urls = array_values( array_unique( $urls ) );

        // Trendyol API allows a maximum of 8 images per product.
        if ( count( $unique_urls ) > 8 ) {
            $unique_urls = array_slice( $unique_urls, 0, 8 );
        }

        return $unique_urls;
    }

    // -----------------------------------------------------------------------
    // Private query helpers
    // -----------------------------------------------------------------------

    /**
     * Find product IDs that use a given attachment as their featured image.
     *
     * @since  1.0.0
     * @access private
     * @param  int $attachment_id The attachment post ID.
     * @return int[]  Array of product post IDs.
     */
    private function get_products_by_thumbnail( int $attachment_id ): array {
        $query = new WP_Query( [
            'post_type'      => [ 'product', 'product_variation' ],
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => '_thumbnail_id',
                    'value' => $attachment_id,
                ],
            ],
        ] );

        return $query->posts;
    }

    /**
     * Find product IDs that include a given attachment in their gallery.
     *
     * @since  1.0.0
     * @access private
     * @param  int $attachment_id The attachment post ID.
     * @return int[]  Array of product post IDs.
     */
    private function get_products_by_gallery_image( int $attachment_id ): array {
        $query = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => '_product_image_gallery',
                    'value'   => $attachment_id,
                    'compare' => 'LIKE',
                ],
            ],
        ] );

        return $query->posts;
    }

    // -----------------------------------------------------------------------
    // Private meta writers
    // -----------------------------------------------------------------------

    /**
     * Write sync result metadata to the product post.
     *
     * @since  1.0.0
     * @access private
     * @param  int    $post_id   Product post ID.
     * @param  string $status    'success' or 'error'.
     * @param  string $error_msg Error message (empty on success).
     * @param  string $batch_id  Trendyol batch request ID (empty on error).
     */
    private function write_sync_meta(
        int $post_id,
        string $status,
        string $error_msg = '',
        string $batch_id = ''
    ): void {
        update_post_meta( $post_id, '_trendyol_sync_status', $status );
        update_post_meta( $post_id, '_trendyol_last_sync',   current_time( 'timestamp' ) );
        update_post_meta( $post_id, '_trendyol_sync_error',  $error_msg );
        update_post_meta( $post_id, '_trendyol_batch_id',    $batch_id );
        update_post_meta( $post_id, '_trendyol_sent',        'yes' );
    }
}
