<?php
/**
 * Fired during plugin activation.
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
 * Class Woo_Trendyol_Activator
 *
 * Sets default option values and schedules the order-polling cron event
 * when the plugin is first activated.
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_Activator {

    /**
     * Run activation tasks.
     *
     * Sets default plugin options if they have not been set before,
     * and schedules the recurring order-poll cron event.
     *
     * @since 1.0.0
     */
    public static function activate(): void {
        // Set default options only if they do not already exist.
        $defaults = [
            'trendyol_api_active'          => 'no',
            'trendyol_holiday_mode'        => 'no',
            'trendyol_seller_id'           => '',
            'trendyol_api_key'             => '',
            'trendyol_api_secret'          => '',
            'trendyol_storefront_code'            => 'GR',
            'trendyol_integration_reference_code' => '',
            'trendyol_order_poll_interval'        => 15,
        ];

        foreach ( $defaults as $option => $value ) {
            if ( false === get_option( $option ) ) {
                update_option( $option, $value );
            }
        }

        // Schedule the order-poll cron if not already scheduled.
        if ( ! wp_next_scheduled( 'woo_trendyol_poll_orders' ) ) {
            wp_schedule_event( time(), 'woo_trendyol_15min', 'woo_trendyol_poll_orders' );
        }

        // Flush rewrite rules (in case any custom post types/taxonomies are registered).
        flush_rewrite_rules();
    }
}
