<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin options and scheduled cron events.
 * Term meta and post meta are intentionally preserved so that
 * category mappings and sync history are not lost on reinstall.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 */

// Guard: only run when WordPress itself triggers uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// Remove all plugin options from wp_options.
$options = [
    'trendyol_api_active',
    'trendyol_seller_id',
    'trendyol_api_key',
    'trendyol_api_secret',
    'trendyol_storefront_code',
    'trendyol_order_poll_interval',
    'trendyol_last_order_poll',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// Clear the scheduled cron event.
$timestamp = wp_next_scheduled( 'woo_trendyol_poll_orders' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'woo_trendyol_poll_orders' );
}
