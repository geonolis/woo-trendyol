<?php
/**
 * Order Sync — polls Trendyol for new orders and manages order status notifications.
 *
 * HPOS Compatibility
 * ------------------
 * This class is fully compatible with WooCommerce High-Performance Order Storage
 * (HPOS, introduced in WC 7.1, default since WC 8.2).
 *
 * Rules applied (per https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/):
 *
 *  1. Order meta is read/written exclusively via WC_Order CRUD methods:
 *       $order->get_meta()         instead of get_post_meta()
 *       $order->update_meta_data() instead of update_post_meta()
 *       $order->delete_meta_data() instead of delete_post_meta()
 *       followed by $order->save() to persist.
 *
 *  2. Order status hooks (woocommerce_order_status_processing / _completed)
 *     receive the order ID as the first argument in both legacy and HPOS modes.
 *     The order object is always obtained via wc_get_order( $order_id ).
 *
 *  3. Order existence checks use wc_get_orders() — the HPOS-safe query API —
 *     instead of WP_Query or get_posts() with post_type=shop_order.
 *
 *  4. Order type checks use OrderUtil::is_order() / OrderUtil::get_order_type()
 *     instead of get_post_type() comparisons.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

// Import the HPOS OrderUtil helper (available since WC 7.1).
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Class Woo_Trendyol_Order_Sync
 *
 * Responsibilities:
 *  - Register a custom WP-Cron interval and schedule the polling event.
 *  - poll_orders()          — fetch new Trendyol packages and create WC orders.
 *  - on_order_processing()  — notify Trendyol that picking has started.
 *  - on_order_completed()   — notify Trendyol that the order has been invoiced/shipped.
 *
 * All order data access is performed through the WooCommerce CRUD API so the
 * class works identically regardless of whether HPOS or legacy CPT storage is
 * active on the merchant's site.
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_Order_Sync {

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    /**
     * WP-Cron hook name for the order-polling event.
     *
     * @since  1.0.0
     * @var    string CRON_HOOK
     */
    public const CRON_HOOK = 'woo_trendyol_poll_orders';

    /**
     * Order meta key: Trendyol shipment package ID.
     *
     * Stored via $order->update_meta_data() — works with both HPOS and legacy.
     *
     * @since  1.0.0
     * @var    string META_PACKAGE_ID
     */
    public const META_PACKAGE_ID = '_trendyol_package_id';

    /**
     * Order meta key: Trendyol order number.
     *
     * @since  1.0.0
     * @var    string META_ORDER_NUMBER
     */
    public const META_ORDER_NUMBER = '_trendyol_order_number';

    // -----------------------------------------------------------------------
    // Properties
    // -----------------------------------------------------------------------

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
     * Shared API client instance.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_API_Client $api
     */
    private Woo_Trendyol_API_Client $api;

    /**
     * Shared logger instance.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Logger $logger
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
    // Cron management
    // -----------------------------------------------------------------------

    /**
     * Register a custom WP-Cron interval matching the configured poll interval.
     *
     * Hooked to: cron_schedules
     *
     * @since 1.0.0
     * @param array $schedules Existing cron schedules.
     * @return array Modified schedules array.
     */
    public function register_cron_interval( array $schedules ): array {
        $interval_minutes = (int) get_option( 'trendyol_order_poll_interval', 15 );

        // Enforce a minimum of 5 minutes to avoid hammering the Trendyol API.
        $interval_seconds = max( 5, $interval_minutes ) * 60;

        $schedules['woo_trendyol_poll_interval'] = [
            'interval' => $interval_seconds,
            'display'  => sprintf(
                /* translators: %d: number of minutes */
                __( 'Every %d minutes (Trendyol order poll)', 'woo-trendyol' ),
                $interval_minutes
            ),
        ];

        return $schedules;
    }

    /**
     * Schedule the order-polling cron event if not already scheduled.
     *
     * Hooked to: init
     *
     * @since 1.0.0
     */
    public function maybe_schedule_cron(): void {
        if ( ! $this->api->is_active() ) {
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'woo_trendyol_poll_interval', self::CRON_HOOK );
        }
    }

    // -----------------------------------------------------------------------
    // Order polling
    // -----------------------------------------------------------------------

    /**
     * Poll Trendyol for new shipment packages and create WooCommerce orders.
     *
     * Hooked to: woo_trendyol_poll_orders (WP-Cron)
     *
     * Fetches packages with status 'Created' since the last successful poll,
     * creates a WooCommerce order for each new package, and updates the
     * last-poll timestamp on success.
     *
     * @since 1.0.0
     */
    public function poll_orders(): void {
        if ( ! $this->api->is_active() ) {
            return;
        }

        // Determine the time window: last poll → now.
        $last_poll  = (int) get_option( 'trendyol_last_order_poll', 0 );
        $now        = time();

        // Default to last 7 days on first run (with 1 hour overlap on subsequent runs to prevent race conditions).
        $start_date = $last_poll > 0
            ? ( ( $last_poll - HOUR_IN_SECONDS ) * 1000 )
            : ( ( $now - ( 7 * DAY_IN_SECONDS ) ) * 1000 );
        $end_date   = $now * 1000;

        $this->logger->info(
            sprintf(
                'Polling Trendyol orders from %s to %s.',
                gmdate( 'Y-m-d H:i:s', (int) ( $start_date / 1000 ) ),
                gmdate( 'Y-m-d H:i:s', (int) ( $end_date   / 1000 ) )
            )
        );

        $response = $this->api->get_shipment_packages( [
            'startDate'          => $start_date,
            'endDate'            => $end_date,
            'orderByField'       => 'PackageLastModifiedDate',
            'orderByDirection'   => 'DESC',
            'page'               => 0,
            'size'               => 200,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->logger->error( 'Order poll failed: ' . $response->get_error_message() );
            return;
        }

        $packages = $response['content'] ?? [];
        $count    = 0;

        foreach ( $packages as $package ) {
            if ( $this->create_wc_order_from_package( $package ) ) {
                $count++;
            }
        }

        // Poll status updates for active orders to follow carrier status directly
        $this->update_active_orders_status();

        // Persist the poll timestamp so the next run starts from here.
        update_option( 'trendyol_last_order_poll', $now );

        $this->logger->info(
            sprintf( 'Order poll complete. %d new order(s) created.', $count )
        );
    }

    // -----------------------------------------------------------------------
    // Order status notifications
    // -----------------------------------------------------------------------

    /**
     * Notify Trendyol that picking has started when a WC order moves to Processing.
     *
     * Hooked to: woocommerce_order_status_processing
     *
     * HPOS note: This hook fires with the same signature in both HPOS and legacy
     * modes. The order object is always fetched via wc_get_order() to ensure the
     * correct data store is used.
     *
     * @since 1.0.0
     * @param int $order_id WooCommerce order ID.
     */
    public function on_order_processing( int $order_id ): void {
        if ( ! $this->api->is_active() ) {
            return;
        }

        // Use wc_get_order() — HPOS-safe, works with both storage backends.
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Read meta via the WC_Order CRUD method — not get_post_meta().
        $package_id = $order->get_meta( self::META_PACKAGE_ID, true );
        if ( empty( $package_id ) ) {
            return; // Not a Trendyol order — nothing to notify.
        }

        $response = $this->api->mark_package_picking( (string) $package_id );

        if ( is_wp_error( $response ) ) {
            $this->logger->error(
                sprintf(
                    'Failed to notify Trendyol picking for order %d (package %s): %s',
                    $order_id,
                    $package_id,
                    $response->get_error_message()
                )
            );
            return;
        }

        $this->logger->info(
            sprintf(
                'Trendyol notified: picking started for order %d (package %s).',
                $order_id,
                $package_id
            )
        );

        $order->add_order_note( __( 'Trendyol status updated to Picking. The shipping voucher is now being prepared by Trendyol.', 'woo-trendyol' ) );
        $order->save();
    }

    /**
     * Notify Trendyol that an order has been invoiced when a WC order is Completed.
     *
     * Hooked to: woocommerce_order_status_completed
     *
     * HPOS note: Same as on_order_processing() — hook signature is identical in
     * both storage modes. Always use wc_get_order() and $order->get_meta().
     *
     * @since 1.0.0
     * @param int $order_id WooCommerce order ID.
     */
    public function on_order_completed( int $order_id ): void {
        if ( ! $this->api->is_active() ) {
            return;
        }

        // Fetch order via WC CRUD — HPOS-safe.
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Read Trendyol package ID via WC_Order::get_meta() — not get_post_meta().
        $package_id = $order->get_meta( self::META_PACKAGE_ID, true );
        if ( empty( $package_id ) ) {
            return; // Not a Trendyol order.
        }

        // Use the WC order number as the invoice number.
        $invoice_number = (string) $order->get_order_number();

        $response = $this->api->mark_package_invoiced(
            (string) $package_id,
            $invoice_number
        );

        if ( is_wp_error( $response ) ) {
            $this->logger->error(
                sprintf(
                    'Failed to notify Trendyol invoiced for order %d (package %s): %s',
                    $order_id,
                    $package_id,
                    $response->get_error_message()
                )
            );
            return;
        }

        $this->logger->info(
            sprintf(
                'Trendyol notified: order %d invoiced (package %s, invoice %s).',
                $order_id,
                $package_id,
                $invoice_number
            )
        );
    }

    /**
     * Notify Trendyol when a WC order is Cancelled.
     *
     * Hooked to: woocommerce_order_status_cancelled
     *
     * @since 1.0.0
     * @param int $order_id WooCommerce order ID.
     */
    public function on_order_cancelled( int $order_id ): void {
        if ( ! $this->api->is_active() ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $package_id = $order->get_meta( self::META_PACKAGE_ID, true );
        if ( empty( $package_id ) ) {
            return; // Not a Trendyol order.
        }

        // Avoid duplicate notification if status was updated by polling Trendyol
        $already_cancelled = $order->get_meta( '_trendyol_notified_cancelled', true );
        if ( 'yes' === $already_cancelled ) {
            return;
        }

        // Collect line IDs from order items if available
        $lines = [];
        foreach ( $order->get_items() as $item ) {
            $ty_line_id = $item->get_meta( '_trendyol_line_id', true );
            if ( ! empty( $ty_line_id ) ) {
                $lines[] = [
                    'lineId'   => (int) $ty_line_id,
                    'quantity' => (int) $item->get_quantity(),
                ];
            }
        }

        $response = $this->api->mark_package_unsupplied( (string) $package_id, $lines );

        if ( is_wp_error( $response ) ) {
            $this->logger->error(
                sprintf(
                    'Failed to notify Trendyol cancellation for order %d (package %s): %s',
                    $order_id,
                    $package_id,
                    $response->get_error_message()
                )
            );
            $order->add_order_note( sprintf( __( 'Could not notify Trendyol of cancellation: %s', 'woo-trendyol' ), $response->get_error_message() ) );
            $order->save();
            return;
        }

        $order->update_meta_data( '_trendyol_notified_cancelled', 'yes' );
        $order->add_order_note( __( 'Trendyol notified: order cancelled / unsupplied (out of stock).', 'woo-trendyol' ) );
        $order->save();

        $this->logger->info(
            sprintf(
                'Trendyol notified: order %d (package %s) cancelled / unsupplied.',
                $order_id,
                $package_id
            )
        );
    }

    // -----------------------------------------------------------------------
    // Private — order creation
    // -----------------------------------------------------------------------

    /**
     * Create a WooCommerce order from a Trendyol shipment package.
     *
     * Skips creation if an order with the same Trendyol package ID already exists.
     * Returns true on successful creation, false on skip or failure.
     *
     * HPOS compliance:
     *  - wc_create_order() creates the order in the active data store (HPOS or CPT).
     *  - All meta is written via $order->update_meta_data() followed by $order->save().
     *  - No direct wp_insert_post() or add_post_meta() calls are made.
     *
     * @since  1.0.0
     * @access private
     * @param  array $package Trendyol shipment package data array.
     * @return bool  True if a new WC order was created.
     */
    private function create_wc_order_from_package( array $package ): bool {
        $package_id   = (string) ( $package['id']          ?? '' );
        $order_number = (string) ( $package['orderNumber']  ?? '' );

        if ( empty( $package_id ) ) {
            return false;
        }

        // Duplicate check using wc_get_orders() — HPOS-safe, no WP_Query.
        if ( $this->wc_order_exists_for_package( $package_id ) ) {
            $this->logger->debug(
                sprintf( 'Package %s already imported, skipping.', $package_id )
            );
            return false;
        }

        try {
            // Instantiate order without initial status to avoid premature empty emails & stock checks.
            $order = wc_create_order( [
                'customer_id' => 0, // Guest order.
            ] );

            if ( is_wp_error( $order ) ) {
                $this->logger->error(
                    sprintf(
                        'Failed to create WC order for package %s: %s',
                        $package_id,
                        $order->get_error_message()
                    )
                );
                return false;
            }

            // Populate billing / shipping address (including postcode).
            $this->set_order_address( $order, $package );

            // Add product line items.
            $this->add_order_lines( $order, $package );

            // Add shipping line item.
            $this->add_shipping( $order, $package );

            // ---------------------------------------------------------------
            // Store Trendyol metadata using WC_Order CRUD — HPOS-safe.
            // Never use update_post_meta() for order data.
            // ---------------------------------------------------------------
            $order->update_meta_data( self::META_PACKAGE_ID,              $package_id );
            $order->update_meta_data( self::META_ORDER_NUMBER,             $order_number );
            $order->update_meta_data( '_trendyol_cargo_tracking_number',   $package['cargoTrackingNumber'] ?? '' );
            $order->update_meta_data( '_trendyol_cargo_provider',          $package['cargoProviderName']   ?? '' );
            $order->update_meta_data( '_trendyol_gross_amount',            $package['grossAmount']         ?? '' );
            $order->update_meta_data( '_trendyol_total_discount',          $package['totalDiscount']       ?? '' );
            $order->update_meta_data( '_trendyol_delivery_type',           $package['deliveryType']        ?? '' );

            // Origin / created via
            $order->set_created_via( 'trendyol' );

            // Set payment method origin
            $order->set_payment_method( 'trendyol' );
            $order->set_payment_method_title( 'Trendyol' );

            // Order Attribution metadata (WooCommerce 8.5+ & HPOS compatible)
            $order->update_meta_data( '_wc_order_attribution_source_type',   'typein' );
            $order->update_meta_data( '_wc_order_attribution_origin',        'Trendyol' );
            $order->update_meta_data( '_wc_order_attribution_utm_source',    'Trendyol' );
            $order->update_meta_data( '_wc_order_attribution_utm_medium',    'marketplace' );
            $order->update_meta_data( '_wc_order_attribution_utm_campaign',  'trendyol_sync' );

            // Add a human-readable order note.
            $order->add_order_note(
                sprintf(
                    /* translators: 1: Trendyol order number, 2: package ID */
                    __( 'Order imported from Trendyol. Order #%1$s, Package ID: %2$s', 'woo-trendyol' ),
                    $order_number,
                    $package_id
                )
            );

            // Calculate taxes first, then totals — avoids duplicate tax addition.
            if ( wc_tax_enabled() ) {
                $order->calculate_taxes();
            }
            $order->calculate_totals( false );

            // Transition status to target status (default: processing).
            $target_status = (string) get_option( 'trendyol_default_order_status', 'processing' );
            $order->set_status( $target_status, __( 'Order imported from Trendyol.', 'woo-trendyol' ) );
            $order->save();

            // Ensure stock levels are reduced for all products in WooCommerce.
            wc_reduce_stock_levels( $order->get_id() );

            // Dispatch admin new order email now that line items and totals exist.
            $mailer = WC()->mailer();
            $emails = $mailer ? $mailer->get_emails() : [];
            if ( ! empty( $emails['WC_Email_New_Order'] ) ) {
                $emails['WC_Email_New_Order']->trigger( $order->get_id(), $order );
            }

            $this->logger->info(
                sprintf(
                    'WC order %d created for Trendyol package %s.',
                    $order->get_id(),
                    $package_id
                )
            );

            /**
             * Fires after a WooCommerce order has been created from a Trendyol package.
             *
             * @since 1.0.0
             * @param int   $order_id The WooCommerce order ID.
             * @param array $package  The raw Trendyol package data array.
             */
            do_action( 'woo_trendyol_order_created', $order->get_id(), $package );

            return true;

        } catch ( Exception $e ) {
            $this->logger->error(
                sprintf(
                    'Exception creating WC order for package %s: %s',
                    $package_id,
                    $e->getMessage()
                )
            );
            return false;
        }
    }

    /**
     * Set the billing and shipping address on a WC order from Trendyol package data.
     *
     * Uses WC_Order::set_address() — the HPOS-safe address setter.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Order $order   The WooCommerce order object.
     * @param  array    $package Trendyol package data.
     */
    private function set_order_address( WC_Order $order, array $package ): void {
        $shipment_addr = $package['shipmentAddress'] ?? [];
        $invoice_addr  = $package['invoiceAddress']  ?? $shipment_addr;

        $shipping = [
            'first_name' => sanitize_text_field( $shipment_addr['firstName'] ?? $package['customerFirstName'] ?? '' ),
            'last_name'  => sanitize_text_field( $shipment_addr['lastName']  ?? $package['customerLastName']  ?? '' ),
            'company'    => sanitize_text_field( $shipment_addr['company']   ?? '' ),
            'address_1'  => sanitize_text_field( $shipment_addr['address1']  ?? '' ),
            'address_2'  => sanitize_text_field( $shipment_addr['district']  ?? '' ),
            'city'       => sanitize_text_field( $shipment_addr['city']      ?? '' ),
            'postcode'   => sanitize_text_field( $shipment_addr['postalCode'] ?? '' ),
            'country'    => sanitize_text_field( $shipment_addr['countryCode'] ?? 'GR' ),
            'phone'      => sanitize_text_field( $shipment_addr['phone']     ?? $package['customerPhone'] ?? '' ),
            'email'      => sanitize_email( $package['customerEmail'] ?? '' ),
        ];

        $billing = [
            'first_name' => sanitize_text_field( $invoice_addr['firstName'] ?? $package['customerFirstName'] ?? '' ),
            'last_name'  => sanitize_text_field( $invoice_addr['lastName']  ?? $package['customerLastName']  ?? '' ),
            'company'    => sanitize_text_field( $invoice_addr['company']   ?? '' ),
            'address_1'  => sanitize_text_field( $invoice_addr['address1']  ?? '' ),
            'address_2'  => sanitize_text_field( $invoice_addr['district']  ?? '' ),
            'city'       => sanitize_text_field( $invoice_addr['city']      ?? '' ),
            'postcode'   => sanitize_text_field( $invoice_addr['postalCode'] ?? $shipment_addr['postalCode'] ?? '' ),
            'country'    => sanitize_text_field( $invoice_addr['countryCode'] ?? $shipment_addr['countryCode'] ?? 'GR' ),
            'phone'      => sanitize_text_field( $invoice_addr['phone']     ?? $package['customerPhone'] ?? '' ),
            'email'      => sanitize_email( $package['customerEmail'] ?? '' ),
        ];

        // set_address() is the WC CRUD method — works with HPOS and legacy.
        $order->set_address( $billing, 'billing' );
        $order->set_address( $shipping, 'shipping' );
    }

    /**
     * Add product line items to a WC order from Trendyol package lines.
     *
     * Attempts to match each Trendyol line item to a WooCommerce product by
     * merchantSku (WooCommerce SKU). Adds a fee line item if no matching product is found.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Order $order   The WooCommerce order object.
     * @param  array    $package Trendyol package data.
     */
    private function add_order_lines( WC_Order $order, array $package ): void {
        $lines = $package['lines'] ?? [];

        foreach ( $lines as $line ) {
            $merchant_sku = (string) ( $line['merchantSku'] ?? '' );
            $barcode      = (string) ( $line['barcode']     ?? '' );
            $quantity     = (int)    ( $line['quantity']    ?? 1  );
            $price        = (float)  ( $line['amount']      ?? 0  );
            $name         = (string) ( $line['productName'] ?? ( $merchant_sku ?: $barcode ) );

            // Attempt to match by SKU (merchantSku), then by barcode / GTIN.
            $product_id = 0;
            if ( ! empty( $merchant_sku ) ) {
                $product_id = wc_get_product_id_by_sku( $merchant_sku );
            }

            if ( ! $product_id && ! empty( $barcode ) ) {
                $product_id = wc_get_product_id_by_sku( $barcode );
            }

            if ( ! $product_id && ! empty( $barcode ) ) {
                // Check _global_unique_id (WooCommerce 9.2+ GTIN/EAN)
                $found = wc_get_products( [
                    'meta_key'   => '_global_unique_id',
                    'meta_value' => $barcode,
                    'limit'      => 1,
                    'return'     => 'ids',
                ] );
                if ( ! empty( $found ) ) {
                    $product_id = $found[0];
                }
            }

            if ( ! $product_id && ! empty( $barcode ) ) {
                // Check custom barcode meta if configured
                $meta_key = (string) get_option( 'trendyol_barcode_meta_key', '' );
                if ( ! empty( $meta_key ) ) {
                    $found = wc_get_products( [
                        'meta_key'   => $meta_key,
                        'meta_value' => $barcode,
                        'limit'      => 1,
                        'return'     => 'ids',
                    ] );
                    if ( ! empty( $found ) ) {
                        $product_id = $found[0];
                    }
                }
            }

            $gross_line_total = $price * $quantity;

            if ( $product_id ) {
                $product = wc_get_product( $product_id );

                if ( $product ) {
                    // Extract net amount from Trendyol gross amount (which includes VAT)
                    $tax_rates = WC_Tax::get_rates( $product->get_tax_class() );
                    if ( wc_tax_enabled() && ! empty( $tax_rates ) ) {
                        $taxes      = WC_Tax::calc_inclusive_tax( $gross_line_total, $tax_rates );
                        $tax_amount = array_sum( $taxes );
                        $net_total  = $gross_line_total - $tax_amount;
                    } else {
                        $net_total = $gross_line_total;
                    }

                    // Add a proper product line item.
                    $item = new WC_Order_Item_Product();
                    $item->set_product( $product );
                    $item->set_quantity( $quantity );
                    $item->set_subtotal( $net_total );
                    $item->set_total( $net_total );

                    // Store the Trendyol reference details on the line item for reference.
                    $line_id = (string) ( $line['lineId'] ?? $line['id'] ?? '' );
                    if ( ! empty( $line_id ) ) {
                        $item->add_meta_data( '_trendyol_line_id', $line_id );
                    }
                    $item->add_meta_data( '_trendyol_merchant_sku', $merchant_sku );
                    if ( ! empty( $barcode ) ) {
                        $item->add_meta_data( '_trendyol_barcode', $barcode );
                    }

                    $order->add_item( $item );
                    continue;
                }
            }

            // No matching WC product — add as a fee line so the order total is correct.
            $tax_rates = WC_Tax::get_rates();
            if ( wc_tax_enabled() && ! empty( $tax_rates ) ) {
                $taxes      = WC_Tax::calc_inclusive_tax( $gross_line_total, $tax_rates );
                $tax_amount = array_sum( $taxes );
                $net_fee    = $gross_line_total - $tax_amount;
            } else {
                $net_fee = $gross_line_total;
            }

            $line_id = (string) ( $line['lineId'] ?? $line['id'] ?? '' );
            $fee = new WC_Order_Item_Fee();
            if ( ! empty( $line_id ) ) {
                $fee->add_meta_data( '_trendyol_line_id', $line_id );
            }
            $fee_name = sanitize_text_field( $name );
            if ( ! empty( $merchant_sku ) ) {
                $fee_name .= ' (' . esc_html( $merchant_sku ) . ')';
            } elseif ( ! empty( $barcode ) ) {
                $fee_name .= ' (' . esc_html( $barcode ) . ')';
            }
            $fee->set_name( $fee_name );
            $fee->set_amount( $net_fee );
            $fee->set_total( $net_fee );
            if ( wc_tax_enabled() && ! empty( $tax_rates ) ) {
                $fee->set_tax_status( 'taxable' );
            }

            $order->add_item( $fee );
        }
    }

    /**
     * Add a shipping line item to a WC order from Trendyol package data.
     *
     * Trendyol does not always expose a discrete shipping cost; this method
     * silently skips if the cost is zero or unavailable.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Order $order   The WooCommerce order object.
     * @param  array    $package Trendyol package data.
     */
    private function add_shipping( WC_Order $order, array $package ): void {
        // Trendyol stores the shipping cost in 'cargoAmount' (not agreedDeliveryDate).
        $shipping_gross = (float) ( $package['cargoAmount'] ?? 0 );

        if ( $shipping_gross <= 0 ) {
            return;
        }

        $tax_rates = WC_Tax::get_shipping_tax_rates();
        if ( wc_tax_enabled() && ! empty( $tax_rates ) ) {
            $taxes        = WC_Tax::calc_inclusive_tax( $shipping_gross, $tax_rates );
            $tax_amount   = array_sum( $taxes );
            $net_shipping = $shipping_gross - $tax_amount;
        } else {
            $net_shipping = $shipping_gross;
        }

        $shipping = new WC_Order_Item_Shipping();
        $shipping->set_method_title(
            sanitize_text_field(
                $package['cargoProviderName'] ?? __( 'Trendyol Shipping', 'woo-trendyol' )
            )
        );
        $shipping->set_total( $net_shipping );

        $order->add_item( $shipping );
    }

    // -----------------------------------------------------------------------
    // Private — HPOS-safe order queries
    // -----------------------------------------------------------------------

    /**
     * Check whether a WooCommerce order already exists for a given Trendyol package ID.
     *
     * Uses wc_get_orders() — the HPOS-safe order query API — instead of WP_Query
     * or get_posts() with post_type=shop_order, which would bypass HPOS tables.
     *
     * @since  1.0.0
     * @access private
     * @param  string $package_id Trendyol package ID to search for.
     * @return bool   True if a matching WC order already exists.
     */
    private function wc_order_exists_for_package( string $package_id ): bool {
        /*
         * wc_get_orders() routes the query through the active data store:
         *   - HPOS enabled  → queries wc_orders / wc_orders_meta tables.
         *   - HPOS disabled → queries wp_posts / wp_postmeta tables.
         * Both paths are handled transparently by WooCommerce.
         */
        $orders = wc_get_orders( [
            'meta_key'   => self::META_PACKAGE_ID,
            'meta_value' => $package_id,
            'limit'      => 1,
            'return'     => 'ids',
        ] );

        return ! empty( $orders );
    }

    /**
     * Poll Trendyol for status changes on active orders and update WooCommerce status.
     *
     * Queries WooCommerce orders in 'on-hold' or 'processing' status that have a Trendyol package ID,
     * checks their current status on Trendyol, and updates WooCommerce if they are Shipped, Delivered, or Cancelled.
     *
     * @since 1.0.0
     */
    public function update_active_orders_status(): void {
        if ( ! $this->api->is_active() ) {
            return;
        }

        // Query active orders (on-hold or processing) with a Trendyol package ID.
        $orders = wc_get_orders( [
            'status'     => [ 'on-hold', 'processing' ],
            'meta_key'   => self::META_PACKAGE_ID,
            'meta_compare'=> 'EXISTS',
            'limit'      => 50, // Process in batches of 50 to avoid timeouts.
        ] );

        if ( empty( $orders ) ) {
            return;
        }

        foreach ( $orders as $order ) {
            $package_id = $order->get_meta( self::META_PACKAGE_ID, true );
            if ( empty( $package_id ) ) {
                continue;
            }

            $response = $this->api->get_shipment_package( (string) $package_id );
            if ( is_wp_error( $response ) ) {
                $this->logger->error( sprintf( 'Failed to fetch status for package %s: %s', $package_id, $response->get_error_message() ) );
                continue;
            }

            $packages = $response['content'] ?? [];
            if ( empty( $packages ) ) {
                continue;
            }

            $package = $packages[0];
            $ty_status = (string) ( $package['status'] ?? '' );

            if ( empty( $ty_status ) ) {
                continue;
            }

            $current_wc_status = $order->get_status();

            // Map Trendyol statuses to WooCommerce order statuses / notes
            if ( in_array( $ty_status, [ 'Shipped', 'Delivered' ], true ) ) {
                $notified_status = $order->get_meta( '_trendyol_carrier_notified_status', true );
                if ( $notified_status !== $ty_status ) {
                    $order->add_order_note( sprintf( __( 'Trendyol carrier status updated to %s.', 'woo-trendyol' ), $ty_status ) );
                    $order->update_meta_data( '_trendyol_carrier_notified_status', $ty_status );
                    $order->save();
                    $this->logger->info( sprintf( 'Order %d (package %s) carrier status updated: %s', $order->get_id(), $package_id, $ty_status ) );
                }
            } elseif ( in_array( $ty_status, [ 'Cancelled', 'UnSupplied' ], true ) && 'cancelled' !== $current_wc_status ) {
                $order->update_meta_data( '_trendyol_notified_cancelled', 'yes' );
                $order->update_status( 'cancelled', sprintf( __( 'Trendyol package status updated to %s.', 'woo-trendyol' ), $ty_status ) );
                $this->logger->info( sprintf( 'Order %d (package %s) transitioned to cancelled based on Trendyol status: %s', $order->get_id(), $package_id, $ty_status ) );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Static helper — HPOS detection
    // -----------------------------------------------------------------------

    /**
     * Detect whether HPOS (custom order tables) is currently active.
     *
     * Wraps OrderUtil::custom_orders_table_usage_is_enabled() with a class_exists
     * guard so the plugin remains loadable on WooCommerce < 7.1.
     *
     * Usage example (for raw SQL or admin screen IDs):
     *
     *   if ( Woo_Trendyol_Order_Sync::is_hpos_enabled() ) {
     *       $screen = wc_get_page_screen_id( 'shop-order' );
     *   } else {
     *       $screen = 'shop_order';
     *   }
     *
     * @since  1.0.0
     * @return bool True when HPOS tables are the authoritative order store.
     */
    public static function is_hpos_enabled(): bool {
        if ( class_exists( OrderUtil::class ) ) {
            return OrderUtil::custom_orders_table_usage_is_enabled();
        }

        // Fallback for WC < 7.1 — HPOS did not exist, so legacy CPT is in use.
        return false;
    }
}
