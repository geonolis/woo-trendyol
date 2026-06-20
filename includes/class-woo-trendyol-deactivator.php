<?php
/**
 * Fired during plugin deactivation.
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
 * Class Woo_Trendyol_Deactivator
 *
 * Clears the scheduled cron event when the plugin is deactivated.
 * Options and meta data are preserved so they survive reactivation.
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_Deactivator {

    /**
     * Run deactivation tasks.
     *
     * Unschedules the order-poll cron event.
     *
     * @since 1.0.0
     */
    public static function deactivate(): void {
        // Remove the scheduled cron event.
        $timestamp = wp_next_scheduled( 'woo_trendyol_poll_orders' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'woo_trendyol_poll_orders' );
        }

        // Flush rewrite rules.
        flush_rewrite_rules();
    }
}
