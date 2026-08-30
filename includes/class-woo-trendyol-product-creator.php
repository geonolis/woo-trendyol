<?php
/**
 * Product Creator — assembles and submits product creation payloads to Trendyol.
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
 * Class Woo_Trendyol_Product_Creator
 *
 * Responsibilities:
 *  - Accept an array of WooCommerce product IDs.
 *  - Build a complete Trendyol product creation payload for each product.
 *  - Resolve: category ID, brand ID, required attributes, handling time, images.
 *  - Submit batches of up to 100 products per API call.
 *  - Store the returned batchRequestId on each product for status polling.
 *  - Return a structured result array for the admin bulk push UI.
 *
 * Payload fields assembled per product:
 *  - barcode         — WC product SKU
 *  - title           — WC product name (truncated to 100 chars)
 *  - productMainId   — WC product SKU (parent SKU for variations)
 *  - brandId         — resolved via brand search or global attribute mapping
 *  - categoryId      — resolved via Category_Helper (4-tier priority)
 *  - quantity        — WC stock quantity
 *  - stockCode       — WC product SKU
 *  - description     — WC short description or description (safe HTML, max 30,000 chars)
 *  - currencyType    — always 'EUR' (Woo_Trendyol_API_Client::CURRENCY)
 *  - listPrice       — WC regular price
 *  - salePrice       — WC sale price or regular price
 *  - vatRate         — from plugin settings (default 24)
 *  - cargoCompanyId  — from plugin settings
 *  - deliveryDuration— handling time in days (fixed or from WC attribute)
 *  - images          — array of { url } objects from WC product gallery
 *  - attributes      — required attributes only, built by Attribute_Mapper
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_Product_Creator {

    /**
     * Maximum number of products per API batch call.
     *
     * @since  1.0.0
     * @access private
     * @var    int BATCH_SIZE
     */
    private const BATCH_SIZE = 100;

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
     * Category helper — resolves Trendyol category IDs.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Category_Helper $category_helper
     */
    private Woo_Trendyol_Category_Helper $category_helper;

    /**
     * Attribute mapper — builds required attribute arrays.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Attribute_Mapper $attribute_mapper
     */
    private Woo_Trendyol_Attribute_Mapper $attribute_mapper;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Initialise the product creator.
     *
     * @since 1.0.0
     * @param Woo_Trendyol_API_Client      $api              Shared API client.
     * @param Woo_Trendyol_Logger          $logger           Shared logger.
     * @param Woo_Trendyol_Category_Helper $category_helper  Category resolution helper.
     * @param Woo_Trendyol_Attribute_Mapper $attribute_mapper Attribute resolution helper.
     */
    public function __construct(
        Woo_Trendyol_API_Client $api,
        Woo_Trendyol_Logger $logger,
        Woo_Trendyol_Category_Helper $category_helper,
        Woo_Trendyol_Attribute_Mapper $attribute_mapper
    ) {
        $this->api              = $api;
        $this->logger           = $logger;
        $this->category_helper  = $category_helper;
        $this->attribute_mapper = $attribute_mapper;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Push an array of WooCommerce product IDs to Trendyol.
     *
     * Processes products in batches of BATCH_SIZE. For each product:
     *  - Validates SKU, category mapping, and images.
     *  - Builds the full payload.
     *  - Submits the batch.
     *  - Stores the batchRequestId on each product.
     *
     * @since 1.0.0
     * @param int[] $product_ids Array of WooCommerce product post IDs.
     * @return array|WP_Error Result array with keys:
     *                        - submitted  (int)   — number of products submitted
     *                        - skipped    (int)   — number skipped (validation failures)
     *                        - batches    (array) — per-batch batchRequestId list
     *                        - errors     (array) — per-product error messages
     */
    /**
     * Push one or more products to Trendyol (routing to create or update as needed).
     *
     * @since 1.0.0
     * @param array $product_ids Array of WooCommerce product IDs to push.
     * @return array|WP_Error Combined push result details, or WP_Error.
     */
    public function push_products( array $product_ids, bool $is_bulk = false ): array|WP_Error {
        if ( ! $this->api->is_active() ) {
            return new WP_Error(
                'trendyol_inactive',
                __( 'Trendyol integration is not active. Please enable it in settings.', 'woo-trendyol' )
            );
        }

        $to_create = [];
        $to_update = [];
        $result = [
            'submitted' => 0,
            'skipped'   => 0,
            'batches'   => [],
            'errors'    => [],
        ];

        foreach ( $product_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) {
                $result['skipped']++;
                $result['errors'][ $pid ] = __( 'Product not found.', 'woo-trendyol' );
                continue;
            }

            if ( $product->is_type( 'variable' ) ) {
                $children = $product->get_children();
                if ( empty( $children ) ) {
                    $result['skipped']++;
                    $result['errors'][ $pid ] = __( 'Variable product has no variations.', 'woo-trendyol' );
                    continue;
                }
                foreach ( $children as $child_id ) {
                    $child = wc_get_product( $child_id );
                    if ( ! $child || ( 'publish' !== $child->get_status() && 'publish' !== get_post_status( $child_id ) ) ) {
                        continue;
                    }
                    if ( 'yes' === get_post_meta( $child_id, '_trendyol_sent', true ) || 'yes' === get_post_meta( $pid, '_trendyol_sent', true ) ) {
                        $to_update[] = $child_id;
                    } else {
                        $to_create[] = $child_id;
                    }
                }
            } else {
                if ( 'yes' === get_post_meta( $pid, '_trendyol_sent', true ) ) {
                    $to_update[] = $pid;
                } else {
                    $to_create[] = $pid;
                }
            }
        }

        if ( ! empty( $to_create ) ) {
            $create_res = $this->create_products_batch( $to_create, $is_bulk );
            if ( ! is_wp_error( $create_res ) ) {
                $result['submitted'] += $create_res['submitted'];
                $result['skipped']   += $create_res['skipped'];
                $result['batches']    = array_merge( $result['batches'], $create_res['batches'] );
                $result['errors']    = $result['errors'] + $create_res['errors'];
            } else {
                return $create_res;
            }
        }

        if ( ! empty( $to_update ) ) {
            $update_res = $this->update_products( $to_update, $is_bulk );
            if ( ! is_wp_error( $update_res ) ) {
                $result['submitted'] += $update_res['submitted'];
                $result['skipped']   += $update_res['skipped'];
                $result['batches']    = array_merge( $result['batches'], $update_res['batches'] );
                $result['errors']    = $result['errors'] + $update_res['errors'];
            } else {
                return $update_res;
            }
        }

        return $result;
    }

    /**
     * Check if a product has all required mappings (category, brand, attributes).
     *
     * @since 1.0.0
     * @param WC_Product $product The WooCommerce product.
     * @return bool True if mapped, false otherwise.
     */
    public function validate_mapping( WC_Product $product ): bool {
        if ( $product->is_type( 'variable' ) ) {
            $children = $product->get_children();
            if ( empty( $children ) ) {
                return false;
            }
            $category_id = (int) $this->category_helper->get_trendyol_category_id( $product->get_id() );
            if ( ! $category_id ) {
                return false;
            }
            $brand_id = $this->resolve_brand_id( $product );
            if ( ! $brand_id ) {
                return false;
            }
            $has_valid_child = false;
            foreach ( $children as $child_id ) {
                $child = wc_get_product( $child_id );
                if ( $child && ( 'publish' === $child->get_status() || 'publish' === get_post_status( $child_id ) ) ) {
                    if ( $this->validate_mapping( $child ) ) {
                        $has_valid_child = true;
                        break;
                    }
                }
            }
            return $has_valid_child;
        }

        $product_id = $product->get_id();
        $parent_id  = $product->get_parent_id();

        // Check category mapping
        $category_id = (int) $this->category_helper->get_trendyol_category_id( $product_id );
        if ( ! $category_id && $parent_id ) {
            $category_id = (int) $this->category_helper->get_trendyol_category_id( $parent_id );
        }
        if ( ! $category_id ) {
            return false;
        }

        // Check brand mapping
        $brand_id = $this->resolve_brand_id( $product );
        if ( ! $brand_id && $parent_id ) {
            $parent = wc_get_product( $parent_id );
            if ( $parent ) {
                $brand_id = $this->resolve_brand_id( $parent );
            }
        }
        if ( ! $brand_id ) {
            return false;
        }

        // Check required attributes
        $terms   = get_the_terms( $product_id, 'product_cat' );
        if ( ( empty( $terms ) || is_wp_error( $terms ) ) && $parent_id ) {
            $terms = get_the_terms( $parent_id, 'product_cat' );
        }
        $term_id = 0;
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                if ( get_term_meta( $term->term_id, 'trendyol_category_id', true ) ) {
                    $term_id = $term->term_id;
                    break;
                }
            }
            if ( ! $term_id ) {
                $term_id = $terms[0]->term_id;
            }
        }

        $attributes = $this->attribute_mapper->build_attributes( $product, $category_id, $term_id );
        if ( is_wp_error( $attributes ) ) {
            return false;
        }

        return true;
    }

    /**
     * Update unapproved products on Trendyol using unapproved-bulk-update.
     *
     * Endpoint: POST /product/sellers/{sellerId}/products/unapproved-bulk-update
     *
     * @since 1.0.0
     * @param array $product_ids Array of WooCommerce product IDs (simple, variable, or variation).
     * @param bool  $is_bulk     Whether this is part of a bulk operation.
     * @return array Result summary.
     */
    public function update_unapproved_products( array $product_ids, bool $is_bulk = false ): array {
        $result = [
            'submitted' => 0,
            'skipped'   => 0,
            'batches'   => [],
            'errors'    => [],
        ];

        if ( ! $this->api->is_active() ) {
            $result['errors'][0] = __( 'Trendyol integration is not active. Please enable it in settings.', 'woo-trendyol' );
            return $result;
        }

        $unapproved_items  = [];
        $barcode_map       = [];

        // Flatten variable products into variations
        $flat_ids = [];
        foreach ( $product_ids as $pid ) {
            $prod = wc_get_product( $pid );
            if ( ! $prod ) {
                $result['skipped']++;
                $result['errors'][ $pid ] = __( 'Product not found.', 'woo-trendyol' );
                continue;
            }
            if ( $prod->is_type( 'variable' ) ) {
                $children = $prod->get_children();
                if ( empty( $children ) ) {
                    $result['skipped']++;
                    $result['errors'][ $pid ] = __( 'Variable product has no variations.', 'woo-trendyol' );
                    continue;
                }
                foreach ( $children as $cid ) {
                    $child = wc_get_product( $cid );
                    if ( $child && 'trash' !== $child->get_status() ) {
                        $flat_ids[] = $cid;
                    }
                }
            } else {
                $flat_ids[] = $pid;
            }
        }

        foreach ( $flat_ids as $item_id ) {
            $product = wc_get_product( $item_id );
            if ( ! $product ) {
                $result['skipped']++;
                $result['errors'][ $item_id ] = __( 'Product not found.', 'woo-trendyol' );
                continue;
            }

            $barcode = $this->resolve_barcode( $product );
            if ( empty( $barcode ) ) {
                $result['skipped']++;
                $result['errors'][ $item_id ] = __( 'Product has no barcode.', 'woo-trendyol' );
                continue;
            }

            $payload = $this->build_payload( $product, $is_bulk );
            if ( is_wp_error( $payload ) ) {
                $result['skipped']++;
                $result['errors'][ $item_id ] = $payload->get_error_message();
                continue;
            }

            $brand_id = ! empty( $payload['brandId'] ) ? (int) $payload['brandId'] : 0;
            $cat_id   = ! empty( $payload['categoryId'] ) ? (int) $payload['categoryId'] : 0;
            $attrs    = ! empty( $payload['attributes'] ) ? $payload['attributes'] : [];
            $main_id  = $payload['productMainId'] ?? '';

            if ( ! $brand_id || empty( $attrs ) || ! $cat_id ) {
                $ty_prod = $this->api->get_product_base( $barcode );
                if ( ! is_wp_error( $ty_prod ) ) {
                    if ( ! $brand_id ) {
                        $brand_id = (int) ( $ty_prod['brand']['id'] ?? 0 );
                    }
                    if ( ! $cat_id ) {
                        $cat_id = (int) ( $ty_prod['category']['id'] ?? 0 );
                    }
                    if ( empty( $main_id ) ) {
                        $main_id = $ty_prod['productMainId'] ?? '';
                    }
                    if ( empty( $attrs ) && ! empty( $ty_prod['attributes'] ) ) {
                        foreach ( $ty_prod['attributes'] as $ty_attr ) {
                            if ( ! empty( $ty_attr['attributeValueId'] ) ) {
                                $attrs[] = [
                                    'attributeId'      => (int) $ty_attr['attributeId'],
                                    'attributeValueId' => (int) $ty_attr['attributeValueId'],
                                ];
                            } elseif ( ! empty( $ty_attr['attributeValue'] ) ) {
                                $attrs[] = [
                                    'attributeId'          => (int) $ty_attr['attributeId'],
                                    'customAttributeValue' => (string) $ty_attr['attributeValue'],
                                ];
                            }
                        }
                    }
                }
            }

            if ( ! $brand_id ) {
                $result['skipped']++;
                $result['errors'][ $item_id ] = __( 'Product has no brand mapped and no existing brand on Trendyol.', 'woo-trendyol' );
                continue;
            }

            $unapproved_items[] = [
                'barcode'           => $barcode,
                'title'             => $payload['title'],
                'description'       => $payload['description'],
                'productMainId'     => $main_id,
                'brandId'           => $brand_id,
                'categoryId'        => $cat_id,
                'stockCode'         => $payload['stockCode'] ?? $barcode,
                'vatRate'           => $payload['vatRate'] ?? 0,
                'dimensionalWeight' => $payload['dimensionalWeight'] ?? 1,
                'images'            => $payload['images'],
                'attributes'        => $attrs,
            ];
            $barcode_map[ $barcode ] = $item_id;
        }

        if ( empty( $unapproved_items ) ) {
            return $result;
        }

        // Chunk in batches of 100
        $chunks = array_chunk( $unapproved_items, 100 );

        foreach ( $chunks as $chunk ) {
            $response = $this->api->update_unapproved_product_content( $chunk );

            if ( is_wp_error( $response ) ) {
                $error_msg = $response->get_error_message();
                foreach ( $chunk as $item ) {
                    $pid = $barcode_map[ $item['barcode'] ] ?? null;
                    if ( $pid ) {
                        $result['errors'][ $pid ] = $error_msg;
                        $result['skipped']++;
                        update_post_meta( $pid, '_trendyol_sync_status', 'error' );
                        update_post_meta( $pid, '_trendyol_sync_error',  $error_msg );
                    }
                }
            } else {
                $batch_id = $response['batchRequestId'] ?? '';
                if ( ! empty( $batch_id ) ) {
                    $result['batches'][] = $batch_id;
                }
                foreach ( $chunk as $item ) {
                    $pid = $barcode_map[ $item['barcode'] ] ?? null;
                    if ( $pid ) {
                        update_post_meta( $pid, '_trendyol_sent',        'yes' );
                        update_post_meta( $pid, '_trendyol_batch_id',    $batch_id );
                        update_post_meta( $pid, '_trendyol_sync_status', 'pending' );
                        update_post_meta( $pid, '_trendyol_sync_error',  '' );
                        update_post_meta( $pid, '_trendyol_last_sync',   time() );
                        $parent_id = wp_get_post_parent_id( $pid );
                        if ( $parent_id && $parent_id !== $pid ) {
                            update_post_meta( $parent_id, '_trendyol_sent',        'yes' );
                            update_post_meta( $parent_id, '_trendyol_batch_id',    $batch_id );
                            update_post_meta( $parent_id, '_trendyol_sync_status', 'pending' );
                            update_post_meta( $parent_id, '_trendyol_sync_error',  '' );
                            update_post_meta( $parent_id, '_trendyol_last_sync',   time() );
                        }
                        $result['submitted']++;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Synchronize price and stock for a batch of products.
     *
     * @since 1.0.0
     * @param array $product_ids Array of WooCommerce product IDs.
     * @return array Result summary.
     */
    public function sync_price_and_stock( array $product_ids ): array {
        $result = [
            'submitted' => 0,
            'skipped'   => 0,
            'batches'   => [],
            'errors'    => [],
        ];

        if ( ! $this->api->is_active() ) {
            $result['errors'][0] = __( 'Trendyol integration is not active. Please enable it in settings.', 'woo-trendyol' );
            return $result;
        }

        $min_price_opt = get_option( 'trendyol_price_rule_min_bulk_push_price', '' );
        $min_price     = ( '' !== $min_price_opt && is_numeric( $min_price_opt ) ) ? (float) $min_price_opt : null;

        $price_stock_items = [];
        $item_map          = [];

        // Flatten variable products into variations
        $flat_products = [];
        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                $result['skipped']++;
                $result['errors'][ $product_id ] = __( 'Product not found.', 'woo-trendyol' );
                continue;
            }

            if ( $product->is_type( 'variable' ) ) {
                $children = $product->get_children();
                if ( empty( $children ) ) {
                    $result['skipped']++;
                    $result['errors'][ $product_id ] = __( 'Variable product has no variations.', 'woo-trendyol' );
                    continue;
                }
                foreach ( $children as $child_id ) {
                    $child = wc_get_product( $child_id );
                    if ( $child && ( 'publish' === $child->get_status() || 'publish' === get_post_status( $child_id ) ) ) {
                        $flat_products[] = $child;
                    }
                }
            } else {
                $flat_products[] = $product;
            }
        }

        foreach ( $flat_products as $product ) {
            // Guard: Never send price/stock for variable parent products
            if ( $product->is_type( 'variable' ) ) {
                continue;
            }

            $pid = $product->get_id();

            // Check if product was already sent to Trendyol
            $is_sent = 'yes' === get_post_meta( $pid, '_trendyol_sent', true );
            if ( ! $is_sent && $product->is_type( 'variation' ) ) {
                $is_sent = 'yes' === get_post_meta( $product->get_parent_id(), '_trendyol_sent', true );
            }

            // Exclude products if their category is excluded from Trendyol
            $lookup_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $pid;
            $terms     = get_the_terms( $lookup_id, 'product_cat' );
            if ( $terms && ! is_wp_error( $terms ) ) {
                $is_cat_excluded = false;
                foreach ( $terms as $term ) {
                    if ( 'yes' === $this->category_helper->get_inherited_term_meta( $term->term_id, 'trendyol_exclude_bulk_push' ) ) {
                        $is_cat_excluded = true;
                        break;
                    }
                }
                if ( $is_cat_excluded ) {
                    $result['skipped']++;
                    $result['errors'][ $pid ] = __( 'Category is excluded from Trendyol.', 'woo-trendyol' );
                    continue;
                }
            }

            // If not previously sent, product must be in stock to sync
            if ( ! $is_sent && ! $product->is_in_stock() ) {
                $result['skipped']++;
                $result['errors'][ $pid ] = __( 'Product is out of stock and not yet sent to Trendyol.', 'woo-trendyol' );
                continue;
            }

            $barcode = $this->resolve_barcode( $product );
            if ( empty( $barcode ) ) {
                $result['skipped']++;
                $result['errors'][ $pid ] = __( 'Product has no barcode.', 'woo-trendyol' );
                continue;
            }

            $prices     = $this->category_helper->get_final_trendyol_prices( $product );
            $sale_price = (float) $prices['salePrice'];
            $list_price = (float) $prices['listPrice'];

            if ( null !== $min_price && $sale_price < $min_price ) {
                $result['skipped']++;
                $result['errors'][ $pid ] = sprintf(
                    __( 'Price %1$s is below the minimum bulk push limit of %2$s.', 'woo-trendyol' ),
                    number_format( $sale_price, 2 ),
                    number_format( $min_price, 2 )
                );
                continue;
            }

            $parent   = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
            $quantity = $product->managing_stock()
                ? max( 0, (int) $product->get_stock_quantity() )
                : ( ( $parent && $parent->managing_stock() )
                    ? max( 0, (int) $parent->get_stock_quantity() )
                    : ( $product->is_in_stock() ? 100 : 0 ) );

            $item = [
                'barcode'   => $barcode,
                'quantity'  => $quantity,
                'salePrice' => $sale_price,
                'listPrice' => $list_price,
            ];

            $lookup_id   = $product->is_type( 'variation' ) ? $product->get_parent_id() : $pid;
            $category_id = $this->category_helper->get_trendyol_category_id( $lookup_id );
            if ( ! empty( $category_id ) ) {
                $item['categoryId'] = (int) $category_id;
            }

            $price_stock_items[] = $item;
            $item_map[]          = $pid;
        }

        if ( ! empty( $price_stock_items ) ) {
            $chunks     = array_chunk( $price_stock_items, 100 );
            $map_chunks = array_chunk( $item_map, 100 );

            foreach ( $chunks as $c_idx => $c_items ) {
                $c_map    = $map_chunks[ $c_idx ];
                $response = $this->api->update_price_and_stock( $c_items );

                if ( is_wp_error( $response ) ) {
                    $error_msg = $response->get_error_message();
                    foreach ( $c_map as $pid ) {
                        $result['errors'][ $pid ] = $error_msg;
                        $result['skipped']++;
                    }
                } else {
                    $batch_request_id = $response['batchRequestId'] ?? '';
                    if ( $batch_request_id ) {
                        $result['batches'][] = $batch_request_id;
                    }
                    foreach ( $c_map as $pid ) {
                        $result['submitted']++;
                        update_post_meta( $pid, '_trendyol_last_sync', time() );
                        if ( $batch_request_id ) {
                            update_post_meta( $pid, '_trendyol_batch_id', $batch_request_id );
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Create one or more new products on Trendyol (batch).
     *
     * @since 1.0.0
     * @param array $product_ids Array of WooCommerce product IDs.
     * @return array Result summary.
     */
    private function create_products_batch( array $product_ids, bool $is_bulk = false ): array {
        $result = [
            'submitted' => 0,
            'skipped'   => 0,
            'batches'   => [],
            'errors'    => [],
        ];

        // Build payload items, collecting skipped products separately.
        $items      = [];
        $item_map   = []; // index in $items => product_id

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );

            if ( ! $product ) {
                $result['skipped']++;
                $result['errors'][ $product_id ] = __( 'Product not found.', 'woo-trendyol' );
                continue;
            }

            $payload = $this->build_payload( $product, $is_bulk );

            if ( is_wp_error( $payload ) ) {
                $result['skipped']++;
                $result['errors'][ $product_id ] = $payload->get_error_message();
                $this->logger->warning(
                    sprintf( 'Skipped product #%d: %s', $product_id, $payload->get_error_message() )
                );
                continue;
            }

            $item_map[ count( $items ) ] = $product_id;
            $items[] = $payload;
        }

        if ( empty( $items ) ) {
            return $result;
        }

        // Submit in batches.
        $batches = array_chunk( $items, self::BATCH_SIZE, true );

        foreach ( $batches as $batch_index => $batch_items ) {
            $response = $this->api->create_products( array_values( $batch_items ) );

            if ( is_wp_error( $response ) ) {
                $error_msg = $response->get_error_message();
                $this->logger->error( sprintf( 'Batch %d failed: %s', $batch_index, $error_msg ) );

                // Mark all products in this batch as failed.
                foreach ( $batch_items as $idx => $item ) {
                    $pid = $item_map[ $idx ] ?? null;
                    if ( $pid ) {
                        $result['errors'][ $pid ] = $error_msg;
                        $result['skipped']++;
                        update_post_meta( $pid, '_trendyol_sync_status', 'error' );
                        update_post_meta( $pid, '_trendyol_sync_error',  $error_msg );
                        $parent_id = wp_get_post_parent_id( $pid );
                        if ( $parent_id && $parent_id !== $pid ) {
                            $result['errors'][ $parent_id ] = $error_msg;
                            update_post_meta( $parent_id, '_trendyol_sync_status', 'error' );
                            update_post_meta( $parent_id, '_trendyol_sync_error',  $error_msg );
                        }
                    }
                }
                continue;
            }

            $batch_request_id = $response['batchRequestId'] ?? '';

            $this->logger->info(
                sprintf( 'Batch %d submitted. batchRequestId: %s', $batch_index, $batch_request_id )
            );

            $result['batches'][] = $batch_request_id;

            // Store batchRequestId and mark products as submitted.
            foreach ( $batch_items as $idx => $item ) {
                $pid = $item_map[ $idx ] ?? null;
                if ( $pid ) {
                    update_post_meta( $pid, '_trendyol_sent',        'yes' );
                    update_post_meta( $pid, '_trendyol_batch_id',    $batch_request_id );
                    update_post_meta( $pid, '_trendyol_sync_status', 'pending' );
                    update_post_meta( $pid, '_trendyol_sync_error',  '' );
                    update_post_meta( $pid, '_trendyol_last_sync',   time() );
                    $parent_id = wp_get_post_parent_id( $pid );
                    if ( $parent_id && $parent_id !== $pid ) {
                        update_post_meta( $parent_id, '_trendyol_sent',        'yes' );
                        update_post_meta( $parent_id, '_trendyol_batch_id',    $batch_request_id );
                        update_post_meta( $parent_id, '_trendyol_sync_status', 'pending' );
                        update_post_meta( $parent_id, '_trendyol_sync_error',  '' );
                        update_post_meta( $parent_id, '_trendyol_last_sync',   time() );
                    }
                    $result['submitted']++;
                }
            }
        }

        return $result;
    }

    /**
     * Update one or more live products on Trendyol (content + inventory/price).
     *
     * @since 1.0.0
     * @param array $product_ids Array of WooCommerce product IDs.
     * @return array Result summary.
     */
    public function update_products( array $product_ids, bool $is_bulk = false ): array {
        $result = [
            'submitted' => 0,
            'skipped'   => 0,
            'batches'   => [],
            'errors'    => [],
        ];

        $approved_items      = [];
        $unapproved_items    = [];
        $price_stock_items   = [];
        $to_create_fallback  = [];
        
        $content_id_map      = [];
        $barcode_map         = [];

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                $result['skipped']++;
                $result['errors'][ $product_id ] = __( 'Product not found.', 'woo-trendyol' );
                continue;
            }

            $barcode = $this->resolve_barcode( $product );
            if ( empty( $barcode ) ) {
                $result['skipped']++;
                $result['errors'][ $product_id ] = __( 'Product has no barcode.', 'woo-trendyol' );
                continue;
            }

            $trendyol_product = $this->api->get_product_base( $barcode );
            if ( is_wp_error( $trendyol_product ) ) {
                if ( 'trendyol_not_found' === $trendyol_product->get_error_code() ) {
                    // Product is not on Trendyol yet (or was deleted/rejected) — fallback to sending as a new product.
                    $to_create_fallback[] = $product_id;
                    continue;
                }
                $result['skipped']++;
                $result['errors'][ $product_id ] = $trendyol_product->get_error_message();
                continue;
            }

            $payload = $this->build_payload( $product, $is_bulk );
            if ( is_wp_error( $payload ) ) {
                $result['skipped']++;
                $result['errors'][ $product_id ] = $payload->get_error_message();
                continue;
            }

            $price_stock_items[] = [
                'barcode'   => $barcode,
                'quantity'  => $payload['quantity'],
                'salePrice' => $payload['salePrice'],
                'listPrice' => $payload['listPrice'],
            ];

            $content_id = $trendyol_product['contentId'] ?? 0;

            if ( ! empty( $content_id ) ) {
                $approved_items[] = [
                    'contentId'   => $content_id,
                    'title'       => $payload['title'],
                    'description' => $payload['description'],
                    'images'      => $payload['images'],
                    'attributes'  => $payload['attributes'],
                ];
                $content_id_map[ $content_id ] = $product_id;
            } else {
                $brand_id = ! empty( $payload['brandId'] ) ? (int) $payload['brandId'] : (int) ( $trendyol_product['brand']['id'] ?? 0 );
                $cat_id   = ! empty( $payload['categoryId'] ) ? (int) $payload['categoryId'] : (int) ( $trendyol_product['category']['id'] ?? 0 );

                if ( ! $brand_id ) {
                    $result['skipped']++;
                    $result['errors'][ $product_id ] = __( 'Product has no brand mapped and no existing brand on Trendyol.', 'woo-trendyol' );
                    continue;
                }

                $attrs = ! empty( $payload['attributes'] ) ? $payload['attributes'] : [];
                if ( empty( $attrs ) && ! empty( $trendyol_product['attributes'] ) ) {
                    foreach ( $trendyol_product['attributes'] as $ty_attr ) {
                        if ( ! empty( $ty_attr['attributeValueId'] ) ) {
                            $attrs[] = [
                                'attributeId'      => (int) $ty_attr['attributeId'],
                                'attributeValueId' => (int) $ty_attr['attributeValueId'],
                            ];
                        } elseif ( ! empty( $ty_attr['attributeValue'] ) ) {
                            $attrs[] = [
                                'attributeId'          => (int) $ty_attr['attributeId'],
                                'customAttributeValue' => (string) $ty_attr['attributeValue'],
                            ];
                        }
                    }
                }

                $unapproved_items[] = [
                    'barcode'           => $barcode,
                    'title'             => $payload['title'],
                    'description'       => $payload['description'],
                    'productMainId'     => $payload['productMainId'] ?? ( $trendyol_product['productMainId'] ?? '' ),
                    'brandId'           => $brand_id,
                    'categoryId'        => $cat_id,
                    'stockCode'         => $payload['stockCode'] ?? $barcode,
                    'vatRate'           => $payload['vatRate'] ?? 0,
                    'dimensionalWeight' => $payload['dimensionalWeight'] ?? 1,
                    'images'            => $payload['images'],
                    'attributes'        => $attrs,
                ];
                $barcode_map[ $barcode ] = $product_id;
            }
        }

        // Fallback: If products were not found in Trendyol inventory, create and send them as new products.
        if ( ! empty( $to_create_fallback ) ) {
            $this->logger->info(
                sprintf(
                    '%d product(s) marked as sent locally but not found on Trendyol. Sending as new product(s) to Trendyol.',
                    count( $to_create_fallback )
                )
            );
            $create_res = $this->create_products_batch( $to_create_fallback, $is_bulk );
            $result['submitted'] += $create_res['submitted'];
            $result['skipped']   += $create_res['skipped'];
            $result['batches']    = array_merge( $result['batches'], $create_res['batches'] );
            $result['errors']     = $result['errors'] + $create_res['errors'];
        }

        if ( empty( $approved_items ) && empty( $unapproved_items ) ) {
            return $result;
        }

        // 1. Submit Content Updates for Approved Products
        if ( ! empty( $approved_items ) ) {
            $response = $this->api->update_product_content( $approved_items );
            if ( is_wp_error( $response ) ) {
                $error_msg = $response->get_error_message();
                foreach ( $approved_items as $item ) {
                    $pid = $content_id_map[ $item['contentId'] ] ?? null;
                    if ( $pid ) {
                        $result['errors'][ $pid ] = $error_msg;
                        $result['skipped']++;
                        update_post_meta( $pid, '_trendyol_sync_status', 'error' );
                        update_post_meta( $pid, '_trendyol_sync_error',  $error_msg );
                    }
                }
            } else {
                $batch_id = $response['batchRequestId'] ?? '';
                $result['batches'][] = $batch_id;
                foreach ( $approved_items as $item ) {
                    $pid = $content_id_map[ $item['contentId'] ] ?? null;
                    if ( $pid ) {
                        update_post_meta( $pid, '_trendyol_sent',        'yes' );
                        update_post_meta( $pid, '_trendyol_batch_id',    $batch_id );
                        update_post_meta( $pid, '_trendyol_sync_status', 'pending' );
                        update_post_meta( $pid, '_trendyol_sync_error',  '' );
                        update_post_meta( $pid, '_trendyol_last_sync',   time() );
                        $parent_id = wp_get_post_parent_id( $pid );
                        if ( $parent_id && $parent_id !== $pid ) {
                            update_post_meta( $parent_id, '_trendyol_sent',        'yes' );
                            update_post_meta( $parent_id, '_trendyol_batch_id',    $batch_id );
                            update_post_meta( $parent_id, '_trendyol_sync_status', 'pending' );
                            update_post_meta( $parent_id, '_trendyol_sync_error',  '' );
                            update_post_meta( $parent_id, '_trendyol_last_sync',   time() );
                        }
                        $result['submitted']++;
                    }
                }
            }
        }

        // 2. Submit Content Updates for Unapproved Products
        if ( ! empty( $unapproved_items ) ) {
            $response = $this->api->update_unapproved_product_content( $unapproved_items );
            if ( is_wp_error( $response ) ) {
                $error_msg = $response->get_error_message();
                foreach ( $unapproved_items as $item ) {
                    $pid = $barcode_map[ $item['barcode'] ] ?? null;
                    if ( $pid ) {
                        $result['errors'][ $pid ] = $error_msg;
                        $result['skipped']++;
                        update_post_meta( $pid, '_trendyol_sync_status', 'error' );
                        update_post_meta( $pid, '_trendyol_sync_error',  $error_msg );
                    }
                }
            } else {
                $batch_id = $response['batchRequestId'] ?? '';
                $result['batches'][] = $batch_id;
                foreach ( $unapproved_items as $item ) {
                    $pid = $barcode_map[ $item['barcode'] ] ?? null;
                    if ( $pid ) {
                        update_post_meta( $pid, '_trendyol_sent',        'yes' );
                        update_post_meta( $pid, '_trendyol_batch_id',    $batch_id );
                        update_post_meta( $pid, '_trendyol_sync_status', 'pending' );
                        update_post_meta( $pid, '_trendyol_sync_error',  '' );
                        update_post_meta( $pid, '_trendyol_last_sync',   time() );
                        $parent_id = wp_get_post_parent_id( $pid );
                        if ( $parent_id && $parent_id !== $pid ) {
                            update_post_meta( $parent_id, '_trendyol_sent',        'yes' );
                            update_post_meta( $parent_id, '_trendyol_batch_id',    $batch_id );
                            update_post_meta( $parent_id, '_trendyol_sync_status', 'pending' );
                            update_post_meta( $parent_id, '_trendyol_sync_error',  '' );
                            update_post_meta( $parent_id, '_trendyol_last_sync',   time() );
                        }
                        $result['submitted']++;
                    }
                }
            }
        }

        // 3. Submit Price & Stock Updates (if any updates were submitted successfully)
        if ( $result['submitted'] > 0 && ! empty( $price_stock_items ) ) {
            $this->api->update_price_and_stock( $price_stock_items );
        }

        return $result;
    }

    /**
     * Resolve the barcode value for a product according to the configured source.
     *
     * Priority chain:
     *  1. Configured source (global_unique_id / meta / attribute).
     *  2. Falls back to WooCommerce SKU if the primary source returns an empty string.
     *  3. Returns an empty string if SKU is also empty (caller must validate).
     *
     * Source modes:
     *  'sku'              — $product->get_sku() directly.
     *  'global_unique_id' — _global_unique_id post meta (WC GTIN/EAN/ISBN field, WC >= 9.2).
     *  'meta'             — custom post meta key stored in trendyol_barcode_meta_key option.
     *  'attribute'        — WC product attribute slu     * @since  1.0.0
     * @access public
     * @param  WC_Product $product The WooCommerce product.
     * @return string              The resolved barcode string, or empty string if not found.
     */
    public function resolve_barcode( WC_Product $product ): string {
        $source     = (string) get_option( 'trendyol_barcode_source', 'sku' );
        $product_id = $product->get_id();
        $sku        = (string) $product->get_sku(); // Always available as fallback.

        switch ( $source ) {

            /*
             * Mode: WooCommerce Global Unique ID
             * Reads the _global_unique_id post meta introduced in WooCommerce 9.2.
             * This is the official GTIN / EAN / ISBN / UPC field shown in the
             * product edit screen under "Product data → General → GTIN, UPC, EAN...".
             * Falls back to EAN attribute, and then SKU when the meta is empty.
             */
            case 'global_unique_id':
                $gtin = (string) get_post_meta( $product_id, '_global_unique_id', true );
                if ( ! empty( $gtin ) ) {
                    return $gtin;
                }

                // Fallback to EAN attribute (taxonomy first)
                if ( taxonomy_exists( 'pa_ean' ) ) {
                    $terms = wp_get_post_terms( $product_id, 'pa_ean', [ 'fields' => 'names' ] );
                    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                        return (string) $terms[0];
                    }
                } else {
                    // Custom non-taxonomy product attribute
                    $attributes = $product->get_attributes();
                    foreach ( [ 'ean', 'EAN' ] as $ean_key ) {
                        if ( isset( $attributes[ $ean_key ] ) ) {
                            $attr  = $attributes[ $ean_key ];
                            $value = is_object( $attr ) ? $attr->get_options() : (array) $attr;
                            if ( ! empty( $value ) ) {
                                return (string) $value[0];
                            }
                        }
                    }
                }

                return $sku;

            /*
             * Mode: Custom post meta key
             * Reads any post meta key the user specified in settings.
             * Falls back to SKU when the meta is empty or the key is not configured.
             */
            case 'meta':
                $meta_key = (string) get_option( 'trendyol_barcode_meta_key', '' );
                if ( empty( $meta_key ) ) {
                    // Key not configured — log a warning and fall back to SKU.
                    $this->logger->warning(
                        sprintf(
                            /* translators: %d: product ID */
                            __( 'Barcode source is "meta" but no meta key is configured. Falling back to SKU for product #%d.', 'woo-trendyol' ),
                            $product_id
                        )
                    );
                    return $sku;
                }
                $value = (string) get_post_meta( $product_id, $meta_key, true );
                return ! empty( $value ) ? $value : $sku;

            /*
             * Mode: WooCommerce product attribute
             * Reads the first term value of the specified attribute taxonomy (pa_*).
             * Also handles custom (non-taxonomy) attributes stored in product meta.
             * Falls back to SKU when the attribute is empty or not configured.
             */
            case 'attribute':
                $attr_slug = (string) get_option( 'trendyol_barcode_attr_slug', '' );
                if ( empty( $attr_slug ) ) {
                    $this->logger->warning(
                        sprintf(
                            /* translators: %d: product ID */
                            __( 'Barcode source is "attribute" but no attribute slug is configured. Falling back to SKU for product #%d.', 'woo-trendyol' ),
                            $product_id
                        )
                    );
                    return $sku;
                }
                // Try taxonomy attribute first (pa_* slugs registered as taxonomies).
                if ( taxonomy_exists( $attr_slug ) ) {
                    $terms = wp_get_post_terms( $product_id, $attr_slug, [ 'fields' => 'names' ] );
                    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                        return (string) $terms[0];
                    }
                } else {
                    // Custom (non-taxonomy) attribute stored in product meta.
                    $attributes = $product->get_attributes();
                    if ( isset( $attributes[ $attr_slug ] ) ) {
                        $attr  = $attributes[ $attr_slug ];
                        $value = is_object( $attr ) ? $attr->get_options() : (array) $attr;
                        if ( ! empty( $value ) ) {
                            return (string) $value[0];
                        }
                    }
                }
                return $sku; // Attribute empty — fall back to SKU.

            /*
             * Mode: WooCommerce SKU (default)
             * Uses the standard WooCommerce SKU field directly.
             */
            case 'sku':
            default:
                return $sku;
        }
    }

    /**
     * Build the Trendyol product creation payload for a single WooCommerce product.
     *
     * @since 1.0.0
     * @param WC_Product $product The WooCommerce product.
     * @return array|WP_Error Complete payload array or WP_Error if validation fails.
     */
    /**
     * Calculate dimensional weight (Desi / Volumetric weight) for a product.
     *
     * @since 1.0.0
     * @param WC_Product $product The product or variation.
     * @return float Calculated dimensional weight (min 1.0).
     */
    public function calculate_dimensional_weight( WC_Product $product ): float {
        $parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;

        $height = (float) $product->get_height();
        if ( ! $height && $parent ) { $height = (float) $parent->get_height(); }

        $width = (float) $product->get_width();
        if ( ! $width && $parent ) { $width = (float) $parent->get_width(); }

        $length = (float) $product->get_length();
        if ( ! $length && $parent ) { $length = (float) $parent->get_length(); }

        $weight = (float) $product->get_weight();
        if ( ! $weight && $parent ) { $weight = (float) $parent->get_weight(); }

        $vw_calc = ( $height * $width * $length ) / 5000;
        $val     = max( $vw_calc, $weight );

        return $val > 0 ? round( $val, 2 ) : 1.0;
    }

    public function build_payload( WC_Product $product, bool $is_bulk = false ): array|WP_Error {
        if ( $product->is_type( 'variable' ) ) {
            return new WP_Error(
                'variable_parent_not_supported',
                sprintf(
                    /* translators: %d: product ID */
                    __( 'Product #%d is a variable parent product and cannot be pushed directly. Its variations should be pushed.', 'woo-trendyol' ),
                    $product->get_id()
                )
            );
        }

        $product_id = $product->get_id();
        $parent_id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;
        $parent     = $parent_id ? wc_get_product( $parent_id ) : null;

        // ---- Resolve barcode using the configured source ----
        $barcode = $this->resolve_barcode( $product );
        $sku     = (string) $product->get_sku(); // Variation or simple product SKU.

        if ( empty( $barcode ) && ! empty( $sku ) ) {
            $barcode = $sku;
        }

        if ( empty( $barcode ) ) {
            return new WP_Error(
                'missing_barcode',
                sprintf(
                    /* translators: %d: product ID */
                    __( 'Product #%d has no barcode value. Please set a SKU or configure a barcode source in Trendyol Sync → Product Defaults.', 'woo-trendyol' ),
                    $product_id
                )
            );
        }

        // ---- Resolve Trendyol category ----
        $category_id = (int) $this->category_helper->get_trendyol_category_id( $product_id );
        if ( ! $category_id && $parent_id ) {
            $category_id = (int) $this->category_helper->get_trendyol_category_id( $parent_id );
        }

        if ( ! $category_id ) {
            return new WP_Error(
                'missing_category',
                sprintf(
                    /* translators: %d: product ID */
                    __( 'Product #%d has no Trendyol category mapping.', 'woo-trendyol' ),
                    $product_id
                )
            );
        }

        // ---- Resolve Trendyol productMainId and stockCode ----
        // Check if variation should be split into individual standalone products on Trendyol
        $is_split_variation = false;
        if ( $product->is_type( 'variation' ) && $parent ) {
            $is_split_variation = $this->category_helper->should_split_variations( $parent_id, $category_id );
        }

        if ( $product->is_type( 'variation' ) && $parent && ! $is_split_variation ) {
            $parent_sku      = (string) $parent->get_sku();
            $product_main_id = ! empty( $parent_sku ) ? $parent_sku : (string) $parent_id;
            $stock_code      = ! empty( $sku ) ? $sku : ( ! empty( $barcode ) ? $barcode : (string) $product_id );
        } else {
            $product_main_id = ! empty( $sku ) ? $sku : ( ! empty( $barcode ) ? $barcode : (string) $product_id );
            $stock_code      = ! empty( $sku ) ? $sku : ( ! empty( $barcode ) ? $barcode : (string) $product_id );
        }

        // ---- Resolve term ID for attribute mapping ----
        $terms   = get_the_terms( $product_id, 'product_cat' );
        if ( ( empty( $terms ) || is_wp_error( $terms ) ) && $parent_id ) {
            $terms = get_the_terms( $parent_id, 'product_cat' );
        }

        $term_id = 0;
        if ( $terms && ! is_wp_error( $terms ) ) {
            if ( $is_bulk ) {
                foreach ( $terms as $term ) {
                    if ( 'yes' === $this->category_helper->get_inherited_term_meta( $term->term_id, 'trendyol_exclude_bulk_push' ) ) {
                        return new WP_Error(
                            'excluded_category',
                            sprintf(
                                __( 'Product #%d is in category "%s" which is excluded from bulk push.', 'woo-trendyol' ),
                                $product_id,
                                $term->name
                            )
                        );
                    }
                }
            }

            // Use the deepest term that has a Trendyol mapping.
            foreach ( $terms as $term ) {
                if ( get_term_meta( $term->term_id, 'trendyol_category_id', true ) ) {
                    $term_id = $term->term_id;
                    break;
                }
            }
            if ( ! $term_id ) {
                $term_id = $terms[0]->term_id;
            }
        }

        // ---- Prices ----
        $regular_price = (float) $product->get_regular_price();

        if ( $regular_price <= 0 ) {
            return new WP_Error(
                'missing_price',
                sprintf(
                    /* translators: %d: product ID */
                    __( 'Product #%d has no regular price.', 'woo-trendyol' ),
                    $product_id
                )
            );
        }

        $prices     = $this->category_helper->get_final_trendyol_prices( $product );
        $list_price = $prices['listPrice'];
        $sale_price = $prices['salePrice'];

        if ( $is_bulk ) {
            $min_price_opt = get_option( 'trendyol_price_rule_min_bulk_push_price', '' );
            if ( '' !== $min_price_opt && is_numeric( $min_price_opt ) && $sale_price < (float) $min_price_opt ) {
                return new WP_Error(
                    'min_price_limit',
                    sprintf(
                        __( 'Price %1$s is below the minimum bulk push limit of %2$s.', 'woo-trendyol' ),
                        number_format( $sale_price, 2 ),
                        number_format( (float) $min_price_opt, 2 )
                    )
                );
            }
        }

        // ---- Stock ----
        $quantity = $product->get_manage_stock()
            ? max( 0, (int) $product->get_stock_quantity() )
            : ( ( $parent && $parent->get_manage_stock() )
                ? max( 0, (int) $parent->get_stock_quantity() )
                : ( $product->is_in_stock() ? 100 : 0 ) );

        // ---- Images ----
        $images = $this->build_image_array( $product, ! empty( $is_split_variation ) );
        if ( empty( $images ) ) {
            return new WP_Error(
                'missing_images',
                sprintf(
                    /* translators: %d: product ID */
                    __( 'Product #%d has no images.', 'woo-trendyol' ),
                    $product_id
                )
            );
        }

        // ---- Brand ID ----
        $brand_id = $this->resolve_brand_id( $product );
        if ( ! $brand_id && $parent ) {
            $brand_id = $this->resolve_brand_id( $parent );
        }

        // ---- Description ----
        $description = $this->build_description( $product );
        if ( empty( $description ) && $parent ) {
            $description = $this->build_description( $parent );
        }

        // ---- Handling time ----
        $handling_time = $this->resolve_handling_time( $product );

        // ---- Required attributes ----
        $attributes = $this->attribute_mapper->build_attributes( $product, $category_id, $term_id );

        if ( is_wp_error( $attributes ) ) {
            return $attributes;
        }

        // ---- Product title ----
        $title = $this->get_product_attribute_value( $product, 'skr-item' );
        if ( empty( $title ) && $parent ) {
            $title = $this->get_product_attribute_value( $parent, 'skr-item' );
        }
        if ( empty( $title ) ) {
            $title = $product->get_name();
        }

        // If variation is split into standalone product, ensure variation attribute value is in title
        if ( ! empty( $is_split_variation ) && $parent ) {
            $var_attrs = $product->get_attributes();
            $var_suffix = ! empty( $var_attrs ) ? implode( ' ', array_values( $var_attrs ) ) : '';
            if ( ! empty( $var_suffix ) && mb_stripos( $title, $var_suffix ) === false ) {
                $title = $title . ' - ' . $var_suffix;
            }
        }

        // ---- Assemble payload ----
        $payload = [
            'barcode'           => $barcode, // Resolved via configured barcode source.
            'title'             => mb_substr( $title, 0, 100 ),
            'productMainId'     => $product_main_id,
            'brandId'           => $brand_id,
            'categoryId'        => $category_id,
            'quantity'          => $quantity,
            'stockCode'         => $stock_code,
            'description'       => $description,
            'currencyType'      => Woo_Trendyol_API_Client::CURRENCY,
            'listPrice'         => round( $list_price, 2 ),
            'salePrice'         => round( $sale_price, 2 ),
            'vatRate'           => (int) get_option( 'trendyol_default_vat_rate', 24 ),
            'cargoCompanyId'    => (int) get_option( 'trendyol_default_cargo_company_id', 0 ),
            'deliveryDuration'  => $handling_time,
            'dimensionalWeight' => $this->calculate_dimensional_weight( $product ),
            'images'            => $images,
            'attributes'        => $attributes,
        ];

        // Remove cargoCompanyId if not set (Trendyol rejects 0).
        if ( empty( $payload['cargoCompanyId'] ) ) {
            unset( $payload['cargoCompanyId'] );
        }

        // Remove brandId if not resolved (Trendyol rejects 0).
        if ( empty( $payload['brandId'] ) ) {
            unset( $payload['brandId'] );
        }

        /**
         * Filter the Trendyol product creation payload before submission.
         *
         * @since 1.0.0
         * @param array      $payload    The assembled payload.
         * @param WC_Product $product    The WooCommerce product.
         * @param int        $product_id The product post ID.
         */
        return apply_filters( 'woo_trendyol_product_payload', $payload, $product, $product_id );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Build the images array for the Trendyol payload.
     *
     * Returns an array of [ 'url' => '...' ] objects.
     * The featured image is always first; gallery images follow.
     * Only publicly accessible HTTPS URLs are included.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Product $product The WooCommerce product.
     * @return array Array of image objects.
     */
    private function build_image_array( WC_Product $product, bool $is_split_variation = false ): array {
        $images     = [];
        $image_ids  = [];

        // Featured image first.
        $featured_id = $product->get_image_id();
        if ( $featured_id ) {
            $image_ids[] = $featured_id;
        }

        // Gallery images on the product itself.
        foreach ( $product->get_gallery_image_ids() as $id ) {
            if ( ! in_array( $id, $image_ids, true ) ) {
                $image_ids[] = $id;
            }
        }

        // If variation, handle parent product images:
        if ( $product->is_type( 'variation' ) || $product->get_parent_id() ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                if ( $is_split_variation ) {
                    // When splitting variations, only use parent image as fallback if variation has NO image at all.
                    if ( empty( $image_ids ) ) {
                        $parent_featured_id = $parent->get_image_id();
                        if ( $parent_featured_id ) {
                            $image_ids[] = $parent_featured_id;
                        }
                    }
                } else {
                    // When variations are grouped, include parent featured and gallery images.
                    $parent_featured_id = $parent->get_image_id();
                    if ( $parent_featured_id && ! in_array( $parent_featured_id, $image_ids, true ) ) {
                        $image_ids[] = $parent_featured_id;
                    }
                    foreach ( $parent->get_gallery_image_ids() as $id ) {
                        if ( ! in_array( $id, $image_ids, true ) ) {
                            $image_ids[] = $id;
                        }
                    }
                }
            }
        }

        foreach ( $image_ids as $id ) {
            $url = wp_get_attachment_url( $id );
            if ( $url && ( str_starts_with( $url, 'https://' ) || str_starts_with( $url, 'http://' ) ) ) {
                $parsed = parse_url( $url );
                if ( $parsed && ! empty( $parsed['host'] ) && ! empty( $parsed['path'] ) ) {
                    $path_parts    = explode( '/', $parsed['path'] );
                    $encoded_parts = array_map( function( $part ) {
                        return rawurlencode( rawurldecode( $part ) );
                    }, $path_parts );
                    $scheme        = $parsed['scheme'] ?? 'https';
                    $host          = $parsed['host'];
                    $port          = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
                    $query         = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
                    $url           = $scheme . '://' . $host . $port . implode( '/', $encoded_parts ) . $query;
                }
                $images[] = [ 'url' => $url ];
            }
        }

        // Trendyol API allows a maximum of 8 images per product.
        if ( count( $images ) > 8 ) {
            $images = array_slice( $images, 0, 8 );
        }

        return $images;
    }

    /**
     * Build a product description suitable for Trendyol.
     *
     * Uses main description first, falls back to short description.
     * Processes WPBakery / WordPress shortcodes if present, sanitizes
     * with wp_kses_post() to preserve safe HTML formatting, and enforces Trendyol's 30,000 char cap.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Product $product The WooCommerce product.
     * @return string Sanitized HTML description.
     */
    private function build_description( WC_Product $product ): string {
        $raw = $product->get_description();

        if ( empty( $raw ) && ( $product->is_type( 'variation' ) || $product->get_parent_id() ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $raw = $parent->get_description();
                if ( empty( $raw ) ) {
                    $raw = $parent->get_short_description();
                }
            }
        }

        if ( empty( $raw ) ) {
            $raw = $product->get_short_description();
        }

        if ( empty( $raw ) ) {
            return '';
        }

        // Initialize WPBakery shortcodes if WPBakery Page Builder is active.
        if ( class_exists( 'WPBMap' ) && method_exists( 'WPBMap', 'addAllMappedShortcodes' ) ) {
            \WPBMap::addAllMappedShortcodes();
        }

        // Process WPBakery and any standard WordPress shortcodes.
        $processed = do_shortcode( $raw );

        // Strip any remaining unhandled shortcodes to avoid leaking raw tags.
        $processed = strip_shortcodes( $processed );

        // Preserve safe HTML formatting (p, br, strong, ul, li, h1-h6, table, etc.) while stripping unsafe tags.
        $clean = wp_kses_post( $processed );
        $clean = trim( $clean );

        // Trendyol API allows descriptions up to 30,000 characters.
        $description = mb_substr( $clean, 0, 30000 );

        /**
         * Filter the product description sent to Trendyol.
         *
         * @since 1.0.0
         * @param string     $description The sanitized product description.
         * @param WC_Product $product     The WooCommerce product object.
         * @param string     $raw         The original raw description.
         */
        return apply_filters( 'woo_trendyol_product_description', $description, $product, $raw );
    }

    /**
     * Resolve the Trendyol brand ID for a product.
     *
     * Resolution order:
     *  1. Product meta `_trendyol_brand_id` (manually set override).
     *  2. WooCommerce Brands plugin taxonomy (product_brand).
     *  3. Global brand WC attribute configured in settings.
     *  4. WooCommerce product attribute named 'brand' or 'manufacturer'.
     *
     * For options 2–4, the brand name is looked up via the Trendyol brands API.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Product $product The WooCommerce product.
     * @return int Trendyol brand ID, or 0 if not found.
     */
    /**
     * Resolve the Trendyol brand ID for a product.
     *
     * Resolution chain (first non-empty value wins):
     *
     *  1. Product-level manual override stored in `_trendyol_brand_id` post meta.
     *     Allows per-product exceptions without touching global settings.
     *
     *  2. Configured brand source (settings option `trendyol_global_attr_brand_wc`):
     *
     *     a) '__wc_brands__' — read from the WooCommerce Brands taxonomy
     *        (product_brand). Available in WooCommerce >= 9.4 (with the feature
     *        flag enabled) or when the legacy WooCommerce Brands premium plugin
     *        is active. Both register the same 'product_brand' taxonomy slug.
     *        If the taxonomy is not registered at runtime, this source is
     *        silently skipped and the chain continues.
     *
     *     b) 'pa_*' slug — read from a WooCommerce product attribute taxonomy.
     *        The first term assigned to the product for that attribute is used.
     *
     *  3. Generic fallback — tries pa_brand, pa_manufacturer, brand, manufacturer
     *     in order. Useful for stores that have not yet configured a brand source.
     *
     * For sources 2 and 3 the resolved brand *name* is then looked up via the
     * Trendyol brands search API to obtain the numeric brand ID. Results are
     * cached in a transient for 12 hours to avoid repeated API calls.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Product $product The WooCommerce product.
     * @return int Trendyol brand ID, or 0 if not found.
     */
    private function resolve_brand_id( WC_Product $product ): int {
        $product_id = $product->get_id();
        $parent_id  = $product->get_parent_id();

        // ---- 1. Product-level manual override (post meta) ----
        $override = (int) get_post_meta( $product_id, '_trendyol_brand_id', true );
        if ( ! $override && $parent_id ) {
            $override = (int) get_post_meta( $parent_id, '_trendyol_brand_id', true );
        }
        if ( $override ) {
            return $override;
        }

        // ---- 1b. Term-level brand ID stored by Brand_Sync ----
        if ( taxonomy_exists( 'product_brand' ) ) {
            $brand_terms = get_the_terms( $product_id, 'product_brand' );
            if ( ( ! $brand_terms || is_wp_error( $brand_terms ) ) && $parent_id ) {
                $brand_terms = get_the_terms( $parent_id, 'product_brand' );
            }
            if ( $brand_terms && ! is_wp_error( $brand_terms ) ) {
                foreach ( $brand_terms as $brand_term ) {
                    $term_brand_id = (int) get_term_meta(
                        $brand_term->term_id,
                        'trendyol_brand_id',
                        true
                    );
                    if ( $term_brand_id ) {
                        return $term_brand_id;
                    }
                }
            }
        }

        $brand_name   = '';
        $brand_source = get_option( 'trendyol_global_attr_brand_wc', '' );

        // ---- 2a. WooCommerce Brands taxonomy (product_brand) ----
        if ( '__wc_brands__' === $brand_source ) {
            if ( taxonomy_exists( 'product_brand' ) ) {
                $brand_terms = get_the_terms( $product_id, 'product_brand' );
                if ( ( ! $brand_terms || is_wp_error( $brand_terms ) ) && $parent_id ) {
                    $brand_terms = get_the_terms( $parent_id, 'product_brand' );
                }
                if ( $brand_terms && ! is_wp_error( $brand_terms ) ) {
                    $brand_name = $brand_terms[0]->name;
                }
            } else {
                $this->logger->warning(
                    sprintf(
                        'Brand source is set to WC Brands (product_brand) but the taxonomy is not registered. Product ID %d will use the generic fallback.',
                        $product_id
                    )
                );
            }
        }

        // ---- 2b. WooCommerce product attribute (pa_*) ----
        if ( ! $brand_name && $brand_source && '__wc_brands__' !== $brand_source ) {
            $brand_name = $this->get_product_attribute_value( $product, $brand_source );
            if ( ! $brand_name && $parent_id ) {
                $parent = wc_get_product( $parent_id );
                if ( $parent ) {
                    $brand_name = $this->get_product_attribute_value( $parent, $brand_source );
                }
            }
        }

        // ---- 3. Generic fallback ----
        if ( ! $brand_name ) {
            foreach ( [ 'pa_brand', 'pa_manufacturer', 'brand', 'manufacturer' ] as $slug ) {
                $val = $this->get_product_attribute_value( $product, $slug );
                if ( ! $val && $parent_id ) {
                    $parent = wc_get_product( $parent_id );
                    if ( $parent ) {
                        $val = $this->get_product_attribute_value( $parent, $slug );
                    }
                }
                if ( $val ) {
                    $brand_name = $val;
                    break;
                }
            }
        }

        if ( ! $brand_name ) {
            return 0;
        }

        return $this->lookup_brand_id( $brand_name );
    }

    /**
     * Look up a Trendyol brand ID by name via the API.
     *
     * Caches results in a transient to avoid repeated API calls.
     *
     * @since  1.0.0
     * @access private
     * @param  string $brand_name The brand name to look up.
     * @return int Trendyol brand ID, or 0 if not found.
     */
    private function lookup_brand_id( string $brand_name ): int {
        $cache_key = 'wt_brand_id_' . md5( strtolower( $brand_name ) );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return (int) $cached;
        }

        $response = $this->api->search_brands( $brand_name );

        if ( is_wp_error( $response ) ) {
            return 0;
        }

        // The API returns an array of brand objects: [ { id, name }, … ]
        $brands = is_array( $response ) && isset( $response[0] ) ? $response : ( $response['brands'] ?? [] );

        $brand_lower = strtolower( trim( $brand_name ) );
        $brand_id    = 0;

        foreach ( $brands as $brand ) {
            if ( strtolower( trim( $brand['name'] ?? '' ) ) === $brand_lower ) {
                $brand_id = (int) $brand['id'];
                break;
            }
        }

        // Cache for 12 hours.
        set_transient( $cache_key, $brand_id, 12 * HOUR_IN_SECONDS );

        return $brand_id;
    }

    /**
     * Resolve the handling time (deliveryDuration) in business days.
     *
     * Uses the configured mode from plugin settings:
     *  - 'fixed'     — returns the configured fixed number of days.
     *  - 'attribute' — reads the value from the configured WC attribute slug.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Product $product The WooCommerce product.
     * @return int Number of handling days (minimum 1).
     */
    private function resolve_handling_time( WC_Product $product ): int {
        $type = get_option( 'trendyol_handling_time_type', 'fixed' );

        if ( 'attribute' === $type ) {
            $attr_slug = get_option( 'trendyol_handling_time_wc_attr', '' );
            if ( $attr_slug ) {
                $value = $this->get_product_attribute_value( $product, $attr_slug );
                $days  = (int) $value;
                if ( $days > 0 ) {
                    return $days;
                }
            }
        }

        // Fixed mode or attribute fallback.
        return max( 1, (int) get_option( 'trendyol_handling_time_days', 3 ) );
    }

    /**
     * Get the first value of a WooCommerce product attribute by slug.
     *
     * Handles both taxonomy-based (pa_*) and custom text attributes.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Product $product   The WooCommerce product.
     * @param  string     $attr_slug The attribute slug (with or without pa_ prefix).
     * @return string First attribute value, or empty string if not found.
     */
    private function get_product_attribute_value( WC_Product $product, string $attr_slug ): string {
        $val = $product->get_attribute( $attr_slug );
        if ( ! empty( $val ) ) {
            return $val;
        }

        $attributes = $product->get_attributes();

        // Try exact slug first.
        if ( isset( $attributes[ $attr_slug ] ) ) {
            $attr = $attributes[ $attr_slug ];
            if ( is_object( $attr ) && method_exists( $attr, 'is_taxonomy' ) ) {
                if ( $attr->is_taxonomy() ) {
                    $terms = wc_get_product_terms( $product->get_id(), $attr_slug, [ 'fields' => 'names' ] );
                    return ! empty( $terms ) ? $terms[0] : '';
                }
                $options = $attr->get_options();
                return ! empty( $options ) ? $options[0] : '';
            } elseif ( is_string( $attr ) ) {
                return $attr;
            }
        }

        // Try without pa_ prefix.
        $without_prefix = preg_replace( '/^pa_/', '', $attr_slug );
        foreach ( $attributes as $slug => $attr ) {
            $normalised = preg_replace( '/^pa_/', '', $slug );
            if ( strtolower( $normalised ) === strtolower( $without_prefix ) ) {
                if ( is_object( $attr ) && method_exists( $attr, 'is_taxonomy' ) ) {
                    if ( $attr->is_taxonomy() ) {
                        $terms = wc_get_product_terms( $product->get_id(), $slug, [ 'fields' => 'names' ] );
                        return ! empty( $terms ) ? $terms[0] : '';
                    }
                    $options = $attr->get_options();
                    return ! empty( $options ) ? $options[0] : '';
                } elseif ( is_string( $attr ) ) {
                    return $attr;
                }
            }
        }

        if ( $product->get_parent_id() ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                return $this->get_product_attribute_value( $parent, $attr_slug );
            }
        }

        return '';
    }
}
