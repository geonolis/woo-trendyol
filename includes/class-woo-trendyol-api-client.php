<?php
/**
 * Trendyol API Client — handles all HTTP communication with the Trendyol Seller API.
 *
 * International marketplace base URL:
 *   https://apigw.trendyol.com/integration
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
 * Class Woo_Trendyol_API_Client
 *
 * Encapsulates every Trendyol API endpoint used by the plugin.
 * All public methods return either a decoded array on success or a
 * WP_Error object on failure, making error handling uniform across callers.
 *
 * Endpoint groups:
 *  Products    — create_products(), update_price_and_stock(),
 *                update_product_images(), get_product_base(),
 *                get_batch_request_result()
 *  Catalogue   — get_category_attributes(), get_brands(), search_brands()
 *  Orders      — get_shipment_packages(), update_package_status(),
 *                mark_package_picking(), mark_package_invoiced()
 *  Utility     — test_connection(), is_active()
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_API_Client {

    /**
     * Trendyol International Marketplace API base URL.
     *
     * The international (non-TR) gateway is used for Greek/EU sellers.
     *
     * @since  1.0.0
     * @access private
     * @var    string BASE_URL
     */
    private const BASE_URL = 'https://apigw.trendyol.com/integration';

    /**
     * HTTP request timeout in seconds.
     *
     * @since  1.0.0
     * @access private
     * @var    int TIMEOUT
     */
    private const TIMEOUT = 30;

    /**
     * Currency type for all product payloads (EUR for international/Greek sellers).
     *
     * @since  1.0.0
     * @access public
     * @var    string CURRENCY
     */
    public const CURRENCY = 'EUR';

    /**
     * Shared logger instance.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Logger $logger
     */
    private Woo_Trendyol_Logger $logger;

    /**
     * Trendyol Seller ID (numeric string).
     *
     * @since  1.0.0
     * @access private
     * @var    string $seller_id
     */
    private string $seller_id;

    /**
     * Trendyol API Key.
     *
     * @since  1.0.0
     * @access private
     * @var    string $api_key
     */
    private string $api_key;

    /**
     * Trendyol API Secret.
     *
     * @since  1.0.0
     * @access private
     * @var    string $api_secret
     */
    private string $api_secret;

    /**
     * Trendyol Storefront Code (used in order-related request headers).
     *
     * @since  1.0.0
     * @access private
     * @var    string $storefront_code
     */
    private string $storefront_code;

    /**
     * Whether the API connection is enabled in plugin settings.
     *
     * @since  1.0.0
     * @access private
     * @var    bool $active
     */
    private bool $active;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Initialise the API client by reading credentials from WordPress options.
     *
     * @since 1.0.0
     * @param Woo_Trendyol_Logger $logger Shared logger instance.
     */
    public function __construct( Woo_Trendyol_Logger $logger ) {
        $this->logger          = $logger;
        $this->seller_id       = (string) get_option( 'trendyol_seller_id',       '' );
        $this->api_key         = (string) get_option( 'trendyol_api_key',         '' );
        $this->api_secret      = (string) get_option( 'trendyol_api_secret',      '' );
        $this->storefront_code = (string) get_option( 'trendyol_storefront_code', '' );
        $this->active          = 'yes' === get_option( 'trendyol_api_active', 'no' );
    }

    // -----------------------------------------------------------------------
    // Utility
    // -----------------------------------------------------------------------

    /**
     * Check whether the API connection is active and credentials are set.
     *
     * @since 1.0.0
     * @return bool True when the integration is enabled and credentials are present.
     */
    public function is_active(): bool {
        return $this->active
            && ! empty( $this->seller_id )
            && ! empty( $this->api_key )
            && ! empty( $this->api_secret );
    }

    /**
     * Return the configured seller ID.
     *
     * @since 1.0.0
     * @return string
     */
    public function get_seller_id(): string {
        return $this->seller_id;
    }

    /**
     * Test the API connection by fetching the seller's product list (page 0, size 1).
     *
     * @since 1.0.0
     * @return array|WP_Error Decoded response array on success, WP_Error on failure.
     */
    public function test_connection(): array|WP_Error {
        return $this->get( "/product/sellers/{$this->seller_id}/products", [ 'page' => 0, 'size' => 1 ] );
    }

    // -----------------------------------------------------------------------
    // Product — creation
    // -----------------------------------------------------------------------

    /**
     * Create one or more products on Trendyol (batch, max 1,000 per request).
     *
     * Endpoint: POST /product/sellers/{sellerId}/v2/products
     *
     * The operation is asynchronous. Trendyol returns a batchRequestId which
     * must be polled via get_batch_request_result() to check acceptance status.
     *
     * Each item in $items must contain at minimum:
     *  - barcode        (string)  Product SKU / barcode
     *  - title          (string)  Product title (max 100 chars)
     *  - productMainId  (string)  Groups variants (usually same as barcode for simple products)
     *  - brandId        (int)     Trendyol brand ID
     *  - categoryId     (int)     Trendyol leaf-level category ID
     *  - quantity       (int)     Stock quantity
     *  - stockCode      (string)  Warehouse stock code
     *  - description    (string)  Plain-text product description
     *  - currencyType   (string)  Always 'EUR' for international sellers
     *  - listPrice      (float)   List price (must be >= salePrice)
     *  - salePrice      (float)   Sale price
     *  - vatRate        (int)     VAT rate: 0, 1, 8, or 18
     *  - cargoCompanyId (int)     Trendyol cargo company ID
     *  - images         (array)   Array of [ 'url' => '...' ] objects
     *  - attributes     (array)   Array of attribute objects (required attributes only)
     *
     * @since 1.0.0
     * @param array $items Array of product payload objects (max 1,000).
     * @return array|WP_Error Response containing batchRequestId, or WP_Error.
     */
    public function create_products( array $items ): array|WP_Error {
        return $this->post(
            "/product/sellers/{$this->seller_id}/v2/products",
            [ 'items' => $items ]
        );
    }

    /**
     * Update price and inventory for one or more products.
     *
     * Endpoint: POST /product/sellers/{sellerId}/products/price-and-inventory
     *
     * @since 1.0.0
     * @param array $items Array of item objects, each containing:
     *                     - barcode    (string) — product barcode / SKU
     *                     - quantity   (int)    — stock quantity
     *                     - salePrice  (float)  — selling price
     *                     - listPrice  (float)  — list price (must be >= salePrice)
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function update_price_and_stock( array $items ): array|WP_Error {
        return $this->post(
            "/product/sellers/{$this->seller_id}/products/price-and-inventory",
            [ 'items' => $items ]
        );
    }

    /**
     * Update product images.
     *
     * Endpoint: PUT /product/sellers/{sellerId}/products
     *
     * @since 1.0.0
     * @param array $items Array of item objects, each containing:
     *                     - barcode (string) — product barcode / SKU
     *                     - images  (array)  — array of [ 'url' => '...' ] objects
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function update_product_images( array $items ): array|WP_Error {
        return $this->put(
            "/product/sellers/{$this->seller_id}/products",
            [ 'items' => $items ]
        );
    }

    /**
     * Fetch a single product from Trendyol by barcode (SKU).
     *
     * Endpoint: GET /product/sellers/{sellerId}/products?barcode={barcode}
     *
     * @since 1.0.0
     * @param string $barcode The product barcode / SKU.
     * @return array|WP_Error First matching product data array, or WP_Error.
     */
    public function get_product_base( string $barcode ): array|WP_Error {
        $response = $this->get(
            "/product/sellers/{$this->seller_id}/products",
            [ 'barcode' => $barcode, 'size' => 1 ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $items = $response['content'] ?? [];
        if ( empty( $items ) ) {
            return new WP_Error(
                'trendyol_not_found',
                sprintf(
                    /* translators: %s: product barcode */
                    __( 'Product with barcode "%s" not found on Trendyol.', 'woo-trendyol' ),
                    $barcode
                )
            );
        }

        return $items[0];
    }

    /**
     * Check the result of an asynchronous batch request.
     *
     * Endpoint: GET /product/sellers/{sellerId}/products/batch-requests/{batchRequestId}
     *
     * Returns an array with:
     *  - batchRequestId (string)
     *  - status         (string) — 'IN_PROGRESS', 'COMPLETED', 'FAILED'
     *  - items          (array)  — per-item status with failureReasons
     *
     * @since 1.0.0
     * @param string $batch_request_id The batch request ID returned by create_products().
     * @return array|WP_Error Decoded batch status or WP_Error.
     */
    public function get_batch_request_result( string $batch_request_id ): array|WP_Error {
        return $this->get(
            "/product/sellers/{$this->seller_id}/products/batch-requests/{$batch_request_id}"
        );
    }

    // -----------------------------------------------------------------------
    // Catalogue endpoints
    // -----------------------------------------------------------------------

    /**
     * Fetch the required and optional attribute schema for a Trendyol category.
     *
     * Endpoint: GET /product/categories/{categoryId}/attributes
     *
     * Returns an array with:
     *  - categoryAttributes (array) — list of attribute objects, each with:
     *      - attribute.id         (int)
     *      - attribute.name       (string)
     *      - required             (bool)
     *      - allowCustom          (bool)
     *      - allowMultipleValues  (bool)
     *      - attributeValues      (array) — [ { id, name }, … ]
     *
     * @since 1.0.0
     * @param int $category_id Trendyol leaf-level category ID.
     * @return array|WP_Error Decoded attribute schema or WP_Error.
     */
    public function get_category_attributes( int $category_id ): array|WP_Error {
        // Cache in a transient to avoid repeated API calls.
        $cache_key = 'wt_cat_attrs_' . $category_id;
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $response = $this->get( "/product/categories/{$category_id}/attributes", [], [ 'Accept-Language' => 'el' ] );

        if ( ! is_wp_error( $response ) ) {
            // Cache for 24 hours — category schemas rarely change.
            set_transient( $cache_key, $response, DAY_IN_SECONDS );
        }

        return $response;
    }

    /**
     * Fetch the category tree from Trendyol.
     *
     * Endpoint: GET /product/category-tree
     *
     * @since 1.0.0
     * @return array|WP_Error Decoded category tree or WP_Error.
     */
    public function get_categories(): array|WP_Error {
        $cache_key = 'wt_category_tree';
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $response = $this->get( '/product/category-tree', [], [ 'Accept-Language' => 'el' ] );

        if ( ! is_wp_error( $response ) ) {
            set_transient( $cache_key, $response, DAY_IN_SECONDS );
        }

        return $response;
    }

    /**
     * Fetch a paginated list of Trendyol brands.
     *
     * Endpoint: GET /product/brands
     *
     * @since 1.0.0
     * @param int $page Page number (0-based).
     * @param int $size Number of brands per page (min 1000 recommended).
     * @return array|WP_Error Decoded brand list or WP_Error.
     */
    public function get_brands( int $page = 0, int $size = 1000 ): array|WP_Error {
        return $this->get( '/product/brands', [ 'page' => $page, 'size' => $size ], [ 'Accept-Language' => 'el' ] );
    }

    /**
     * Search for brands by name.
     *
     * Endpoint: GET /product/brands/by-name?name={name}
     *
     * @since 1.0.0
     * @param string $name Brand name to search for.
     * @return array|WP_Error Decoded brand list or WP_Error.
     */
    public function search_brands( string $name ): array|WP_Error {
        $cache_key = 'wt_brand_search_' . md5( strtolower( $name ) );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $response = $this->get( '/product/brands/by-name', [ 'name' => $name ], [ 'Accept-Language' => 'el' ] );

        if ( ! is_wp_error( $response ) ) {
            set_transient( $cache_key, $response, 12 * HOUR_IN_SECONDS );
        }

        return $response;
    }

    /**
     * Fetch the list of Trendyol cargo companies.
     *
     * Endpoint: GET /product/cargo-companies
     *
     * @since 1.0.0
     * @return array|WP_Error Decoded cargo company list or WP_Error.
     */
    public function get_cargo_companies(): array|WP_Error {
        $cache_key = 'wt_cargo_companies';
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $response = $this->get( '/product/cargo-companies' );

        if ( ! is_wp_error( $response ) ) {
            set_transient( $cache_key, $response, DAY_IN_SECONDS );
        }

        return $response;
    }

    // -----------------------------------------------------------------------
    // Order / shipment endpoints
    // -----------------------------------------------------------------------

    /**
     * Fetch shipment packages from Trendyol.
     *
     * Endpoint: GET /order/sellers/{sellerId}/orders/shipment-packages
     *
     * @since 1.0.0
     * @param array $params Optional query parameters:
     *                      - startDate   (int)    — Unix timestamp ms
     *                      - endDate     (int)    — Unix timestamp ms
     *                      - status      (string) — package status filter
     *                      - page        (int)    — page number (0-based)
     *                      - size        (int)    — page size
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function get_shipment_packages( array $params = [] ): array|WP_Error {
        return $this->get( "/order/sellers/{$this->seller_id}/orders/shipment-packages", $params );
    }

    /**
     * Update the status of a shipment package.
     *
     * Endpoint: PUT /order/sellers/{sellerId}/shipment-packages/{packageId}
     *
     * @since 1.0.0
     * @param string $package_id The Trendyol shipment package ID.
     * @param string $status     The new status string (e.g. 'Picking', 'Invoiced').
     * @param array  $extra      Optional extra payload fields (e.g. invoiceNumber).
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function update_package_status( string $package_id, string $status, array $extra = [] ): array|WP_Error {
        $payload = array_merge( [ 'status' => $status ], $extra );
        return $this->put( "/order/sellers/{$this->seller_id}/shipment-packages/{$package_id}", $payload );
    }

    /**
     * Notify Trendyol that picking has started for a package.
     *
     * Convenience wrapper around update_package_status().
     *
     * @since 1.0.0
     * @param string $package_id The Trendyol shipment package ID.
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function mark_package_picking( string $package_id ): array|WP_Error {
        return $this->update_package_status( $package_id, 'Picking' );
    }

    /**
     * Notify Trendyol that an order has been invoiced (completed).
     *
     * Convenience wrapper around update_package_status().
     *
     * @since 1.0.0
     * @param string $package_id    The Trendyol shipment package ID.
     * @param string $invoice_number The invoice number to send to Trendyol.
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function mark_package_invoiced( string $package_id, string $invoice_number ): array|WP_Error {
        return $this->update_package_status(
            $package_id,
            'Invoiced',
            [ 'invoiceNumber' => $invoice_number ]
        );
    }

    // -----------------------------------------------------------------------
    // Private HTTP helpers
    // -----------------------------------------------------------------------

    /**
     * Perform a GET request to the Trendyol API.
     *
     * @since  1.0.0
     * @access private
     * @param  string $endpoint Relative endpoint path.
     * @param  array  $params   Optional query string parameters.
     * @param  array  $headers  Optional additional headers.
     * @return array|WP_Error   Decoded JSON response or WP_Error.
     */
    private function get( string $endpoint, array $params = [], array $headers = [] ): array|WP_Error {
        $url = self::BASE_URL . $endpoint;

        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }

        $response = wp_remote_get( $url, $this->build_request_args( 'GET', [], $headers ) );

        return $this->parse_response( $response, 'GET', $url );
    }

    /**
     * Perform a PUT request to the Trendyol API.
     *
     * @since  1.0.0
     * @access private
     * @param  string $endpoint Relative endpoint path.
     * @param  array  $body     Request body to JSON-encode.
     * @param  array  $headers  Optional additional headers.
     * @return array|WP_Error   Decoded JSON response or WP_Error.
     */
    private function put( string $endpoint, array $body, array $headers = [] ): array|WP_Error {
        $url  = self::BASE_URL . $endpoint;
        $args = $this->build_request_args( 'PUT', $body, $headers );

        $response = wp_remote_request( $url, $args );

        return $this->parse_response( $response, 'PUT', $url );
    }

    /**
     * Perform a POST request to the Trendyol API.
     *
     * @since  1.0.0
     * @access private
     * @param  string $endpoint Relative endpoint path.
     * @param  array  $body     Request body to JSON-encode.
     * @param  array  $headers  Optional additional headers.
     * @return array|WP_Error   Decoded JSON response or WP_Error.
     */
    private function post( string $endpoint, array $body, array $headers = [] ): array|WP_Error {
        $url  = self::BASE_URL . $endpoint;
        $args = $this->build_request_args( 'POST', $body, $headers );

        $response = wp_remote_post( $url, $args );

        return $this->parse_response( $response, 'POST', $url );
    }

    /**
     * Build the wp_remote_* args array with auth headers and optional body.
     *
     * @since  1.0.0
     * @access private
     * @param  string $method HTTP method (GET, PUT, POST). Default 'GET'.
     * @param  array  $body   Optional request body for PUT/POST.
     * @param  array  $extra_headers Optional extra headers.
     * @return array  Args array for wp_remote_*.
     */
    private function build_request_args( string $method = 'GET', array $body = [], array $extra_headers = [] ): array {
        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => array_merge( [
                'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' . $this->api_secret ),
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'WooCommerce-Trendyol/' . WOO_TRENDYOL_VERSION,
                'Accept'        => 'application/json',
            ], $extra_headers ),
        ];

        // Attach storefront code header when set (required for order endpoints and international marketplace).
        if ( ! empty( $this->storefront_code ) ) {
            $args['headers']['storefront-code'] = $this->storefront_code;
        }

        // Encode body for PUT/POST requests.
        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        return $args;
    }

    /**
     * Parse a wp_remote_* response into a decoded array or WP_Error.
     *
     * @since  1.0.0
     * @access private
     * @param  array|WP_Error $response The raw wp_remote_* response.
     * @param  string         $method   HTTP method (for logging).
     * @param  string         $url      Request URL (for logging).
     * @return array|WP_Error           Decoded response body or WP_Error.
     */
    private function parse_response( array|WP_Error $response, string $method, string $url ): array|WP_Error {
        // Handle transport-level errors (e.g. DNS failure, timeout).
        if ( is_wp_error( $response ) ) {
            $this->logger->error(
                sprintf( '[%s %s] Transport error: %s', $method, $url, $response->get_error_message() )
            );
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body, true );

        // Log non-2xx responses as errors.
        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_message = $decoded['message'] ?? $decoded['errors'][0]['message'] ?? $body;

            $this->logger->error(
                sprintf( '[%s %s] HTTP %d: %s', $method, $url, $status_code, $error_message )
            );

            return new WP_Error(
                'trendyol_api_error',
                sprintf(
                    /* translators: 1: HTTP status code, 2: error message */
                    __( 'Trendyol API error (HTTP %1$d): %2$s', 'woo-trendyol' ),
                    $status_code,
                    $error_message
                ),
                [ 'status' => $status_code, 'body' => $decoded ]
            );
        }

        // Return decoded body, or an empty array for 204 No Content responses.
        return is_array( $decoded ) ? $decoded : [];
    }
}
