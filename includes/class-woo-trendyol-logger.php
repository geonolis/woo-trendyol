<?php
/**
 * Logger — wraps WooCommerce's WC_Logger for structured plugin logging.
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
 * Class Woo_Trendyol_Logger
 *
 * Provides info(), warning(), and error() convenience methods that write
 * to the WooCommerce log system under the 'woo-trendyol' source.
 * All log entries are visible in WooCommerce → Status → Logs.
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_Logger {

    /**
     * WooCommerce logger instance.
     *
     * @since  1.0.0
     * @access private
     * @var    WC_Logger $wc_logger
     */
    private WC_Logger $wc_logger;

    /**
     * Log source identifier shown in WooCommerce logs.
     *
     * @since  1.0.0
     * @access private
     * @var    string $source
     */
    private string $source;

    /**
     * Log context array passed to every WC_Logger call.
     *
     * @since  1.0.0
     * @access private
     * @var    array $context
     */
    private array $context;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Initialise the logger.
     *
     * @since 1.0.0
     * @param string $source Log source identifier. Defaults to 'woo-trendyol'.
     */
    public function __construct( string $source = 'woo-trendyol' ) {
        $this->source  = $source;
        $this->context = [ 'source' => $source ];

        // wc_get_logger() is available after WooCommerce is loaded.
        $this->wc_logger = wc_get_logger();
    }

    // -----------------------------------------------------------------------
    // Public logging methods
    // -----------------------------------------------------------------------

    /**
     * Log an informational message.
     *
     * @since 1.0.0
     * @param string $message The message to log.
     */
    public function info( string $message ): void {
        $this->wc_logger->info( $message, $this->context );
    }

    /**
     * Log a warning message.
     *
     * @since 1.0.0
     * @param string $message The message to log.
     */
    public function warning( string $message ): void {
        $this->wc_logger->warning( $message, $this->context );
    }

    /**
     * Log an error message.
     *
     * @since 1.0.0
     * @param string $message The message to log.
     */
    public function error( string $message ): void {
        $this->wc_logger->error( $message, $this->context );
    }

    /**
     * Log a debug message (only visible when WP_DEBUG_LOG is enabled).
     *
     * @since 1.0.0
     * @param string $message The message to log.
     */
    public function debug( string $message ): void {
        $this->wc_logger->debug( $message, $this->context );
    }
}
