<?php
/**
 * Define the internationalisation functionality.
 *
 * Loads and defines the internationalisation files for this plugin
 * so that it is ready for translation.
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
 * Class Woo_Trendyol_i18n
 *
 * Loads the plugin text domain so all strings are translatable.
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_i18n {

    /**
     * Load the plugin text domain for translation.
     *
     * Hooked to: plugins_loaded
     *
     * @since 1.0.0
     */
    public function load_plugin_textdomain(): void {
        load_plugin_textdomain(
            'woo-trendyol',
            false,
            dirname( WOO_TRENDYOL_BASENAME ) . '/languages/'
        );
    }
}
