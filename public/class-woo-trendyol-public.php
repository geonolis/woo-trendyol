<?php
/**
 * The public-facing functionality of the plugin.
 *
 * This plugin has no public-facing output. This class exists as a WPPB
 * structural requirement and may be extended in future versions.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/public
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Woo_Trendyol_Public
 *
 * Stub class for public-facing hooks. No front-end output is produced
 * by this plugin; all functionality is admin-only.
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/public
 */
class Woo_Trendyol_Public {

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

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * Hooked to: wp_enqueue_scripts
     * Currently a no-op; no public CSS is required.
     *
     * @since 1.0.0
     */
    public function enqueue_styles(): void {
        // No public styles required.
    }

    /**
     * Register the JavaScript for the public-facing side of the site.
     *
     * Hooked to: wp_enqueue_scripts
     * Currently a no-op; no public JS is required.
     *
     * @since 1.0.0
     */
    public function enqueue_scripts(): void {
        // No public scripts required.
    }
}
