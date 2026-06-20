<?php
/**
 * Admin settings page partial template — tabbed layout.
 *
 * Variables injected from Woo_Trendyol_Admin::render_settings_page():
 *  @var bool   $is_active   Whether the API connection is currently active.
 *  @var string $active_tab  The currently active tab slug.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

$tabs = [
    'credentials' => __( 'API Credentials &amp; Connection', 'woo-trendyol' ),
    'defaults'    => __( 'Product Defaults', 'woo-trendyol' ),
    'attributes'  => __( 'Global Attribute Mappings', 'woo-trendyol' ),
];
?>
<div class="wrap woo-trendyol-settings-wrap">

    <h1 class="wt-page-title">
        <span class="dashicons dashicons-admin-links wt-page-icon"></span>
        <?php esc_html_e( 'Trendyol Integration', 'woo-trendyol' ); ?>
        <span class="wt-currency-badge">EUR &euro;</span>
    </h1>

    <?php
    if ( 'credentials' === $active_tab ) {
        settings_errors( 'woo_trendyol_api_settings' );
    } elseif ( 'defaults' === $active_tab ) {
        settings_errors( 'woo_trendyol_defaults_settings' );
    } elseif ( 'attributes' === $active_tab ) {
        settings_errors( 'woo_trendyol_attrs_settings' );
    }
    ?>

    <!-- Connection status banner -->
    <div class="wt-connection-banner <?php echo $is_active ? 'wt-connection-banner--active' : 'wt-connection-banner--inactive'; ?>">
        <span class="wt-connection-dot"></span>
        <?php if ( $is_active ) : ?>
            <strong><?php esc_html_e( 'Integration Active', 'woo-trendyol' ); ?></strong>
            &mdash; <?php esc_html_e( 'Product sync and order polling are running. Currency: EUR (International/Greek marketplace).', 'woo-trendyol' ); ?>
        <?php else : ?>
            <strong><?php esc_html_e( 'Integration Inactive', 'woo-trendyol' ); ?></strong>
            &mdash; <?php esc_html_e( 'Enter your credentials and enable the toggle to activate.', 'woo-trendyol' ); ?>
        <?php endif; ?>
    </div>

    <!-- Tab navigation (native WP nav-tab style) -->
    <nav class="nav-tab-wrapper wt-nav-tab-wrapper">
        <?php foreach ( $tabs as $slug => $label ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-trendyol-settings&tab=' . $slug ) ); ?>"
               class="nav-tab<?php echo $active_tab === $slug ? ' nav-tab-active' : ''; ?>">
                <?php
                switch ( $slug ) {
                    case 'credentials':
                        echo '<span class="dashicons dashicons-lock"></span> ';
                        break;
                    case 'defaults':
                        echo '<span class="dashicons dashicons-admin-settings"></span> ';
                        break;
                    case 'attributes':
                        echo '<span class="dashicons dashicons-tag"></span> ';
                        break;
                }
                echo wp_kses_post( $label );
                ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="wt-settings-grid">

        <!-- Main settings form -->
        <div class="wt-card wt-card--settings">
            <form method="post" action="options.php">
                <?php
                if ( 'credentials' === $active_tab ) {
                    settings_fields( 'woo_trendyol_api_settings' );
                } elseif ( 'defaults' === $active_tab ) {
                    settings_fields( 'woo_trendyol_defaults_settings' );
                } elseif ( 'attributes' === $active_tab ) {
                    settings_fields( 'woo_trendyol_attrs_settings' );
                }
                ?>
                <!-- Preserve active tab through form submission -->
                <input type="hidden" name="_wt_active_tab" value="<?php echo esc_attr( $active_tab ); ?>" />

                <?php if ( 'credentials' === $active_tab ) : ?>
                    <!-- ================================================== -->
                    <!-- TAB 1: API Credentials & Connection                  -->
                    <!-- ================================================== -->
                    <div class="wt-tab-header">
                        <h2>
                            <span class="dashicons dashicons-lock"></span>
                            <?php esc_html_e( 'API Credentials &amp; Connection', 'woo-trendyol' ); ?>
                        </h2>
                        <p class="wt-tab-desc">
                            <?php echo wp_kses_post(
                                sprintf(
                                    /* translators: %s: link to Trendyol seller panel */
                                    __( 'Enter your Trendyol Seller API credentials. Find these in the <a href="%s" target="_blank" rel="noopener">Trendyol Seller Panel &rarr; Integration Settings</a>. All prices are submitted in <strong>EUR</strong> (International / Greek marketplace).', 'woo-trendyol' ),
                                    'https://partner.trendyol.com'
                                )
                            ); ?>
                        </p>
                    </div>

                    <table class="form-table" role="presentation">
                        <?php do_settings_fields( 'woo-trendyol-settings', 'woo_trendyol_api_section' ); ?>
                    </table>

                    <div class="wt-credentials-actions">
                        <?php submit_button( __( 'Save Credentials', 'woo-trendyol' ), 'primary', 'submit', false ); ?>
                        <button type="button" id="wt-test-connection" class="button button-secondary">
                            <span class="dashicons dashicons-update-alt"></span>
                            <?php esc_html_e( 'Test API Connection', 'woo-trendyol' ); ?>
                        </button>
                        <span id="wt-test-spinner" class="spinner" style="float:none;vertical-align:middle;"></span>
                    </div>
                    <div id="wt-test-result" class="wt-test-result" style="display:none;"></div>

                <?php elseif ( 'defaults' === $active_tab ) : ?>
                    <!-- ================================================== -->
                    <!-- TAB 2: Product Defaults                              -->
                    <!-- ================================================== -->
                    <div class="wt-tab-header">
                        <h2>
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php esc_html_e( 'Product Defaults', 'woo-trendyol' ); ?>
                        </h2>
                        <p class="wt-tab-desc">
                            <?php esc_html_e( 'These values are applied to all products pushed to Trendyol unless overridden at the product level.', 'woo-trendyol' ); ?>
                        </p>
                    </div>

                    <table class="form-table" role="presentation">
                        <?php do_settings_fields( 'woo-trendyol-settings', 'woo_trendyol_defaults_section' ); ?>
                    </table>

                    <?php submit_button( __( 'Save Defaults', 'woo-trendyol' ) ); ?>

                <?php elseif ( 'attributes' === $active_tab ) : ?>
                    <!-- ================================================== -->
                    <!-- TAB 3: Global Attribute Mappings                     -->
                    <!-- ================================================== -->
                    <div class="wt-tab-header">
                        <h2>
                            <span class="dashicons dashicons-tag"></span>
                            <?php esc_html_e( 'Global Attribute Mappings', 'woo-trendyol' ); ?>
                        </h2>
                        <p class="wt-tab-desc">
                            <?php esc_html_e( 'Map WooCommerce attributes to their Trendyol equivalents. These global mappings are applied first, before per-category or per-product attribute mappings. Optional attributes are always omitted.', 'woo-trendyol' ); ?>
                        </p>
                        <p class="wt-tab-desc">
                            <?php echo wp_kses_post( __( 'For <strong>Gender</strong> and <strong>Age Group</strong>: first select the WooCommerce attribute that holds those values, then map each Trendyol value to one or more of your WooCommerce terms. Age supports many-to-one mapping (e.g. "από 3 ετών" and "από 4 ετών" can both map to the Trendyol "3-4 Yaş" value).', 'woo-trendyol' ) ); ?>
                        </p>
                    </div>

                    <!-- Category loader for fetching Trendyol attribute values -->
                    <div class="wt-attr-category-loader wt-card wt-card--loader">
                        <h3><?php esc_html_e( 'Load Trendyol Attribute Values', 'woo-trendyol' ); ?></h3>
                        <p class="description">
                            <?php esc_html_e( 'Trendyol gender and age values are category-specific. Enter any leaf-level category ID from your catalogue to fetch the available values for mapping.', 'woo-trendyol' ); ?>
                        </p>
                        <div class="wt-loader-row">
                            <label for="wt-attr-sample-category">
                                <strong><?php esc_html_e( 'Trendyol Category ID:', 'woo-trendyol' ); ?></strong>
                            </label>
                            <input type="number"
                                   id="wt-attr-sample-category"
                                   class="small-text"
                                   placeholder="<?php esc_attr_e( 'e.g. 1082', 'woo-trendyol' ); ?>"
                                   min="1" />
                            <button type="button" class="button button-secondary" id="wt-load-attr-values">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e( 'Load Trendyol Values', 'woo-trendyol' ); ?>
                            </button>
                            <span id="wt-attr-load-spinner" class="spinner" style="float:none;margin:0 4px;vertical-align:middle;"></span>
                        </div>
                        <div id="wt-attr-load-notice" style="display:none;"></div>
                    </div>

                    <!-- Attribute mapping fields -->
                    <table class="form-table wt-attr-mapping-form-table" role="presentation">
                        <?php do_settings_fields( 'woo-trendyol-settings', 'woo_trendyol_attrs_section' ); ?>
                    </table>

                    <?php submit_button( __( 'Save Attribute Mappings', 'woo-trendyol' ) ); ?>

                <?php endif; ?>

            </form>
        </div><!-- /.wt-card--settings -->

        <!-- Sidebar -->
        <div class="wt-sidebar">

            <?php if ( 'credentials' === $active_tab ) : ?>
            <!-- Connection test card (credentials tab only) -->
            <div class="wt-card wt-card--test">
                <h3><?php esc_html_e( 'Connection Status', 'woo-trendyol' ); ?></h3>
                <p>
                    <?php if ( $is_active ) : ?>
                        <span class="wt-status-badge wt-status-badge--success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e( 'Connected', 'woo-trendyol' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="wt-status-badge wt-status-badge--error">
                            <span class="dashicons dashicons-dismiss"></span>
                            <?php esc_html_e( 'Not Connected', 'woo-trendyol' ); ?>
                        </span>
                    <?php endif; ?>
                </p>
                <p class="description">
                    <?php esc_html_e( 'After saving credentials, use the "Test API Connection" button in the form to verify.', 'woo-trendyol' ); ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Bulk Push Tool (all tabs) -->
            <div class="wt-card wt-card--bulk-push">
                <h3><?php esc_html_e( 'Bulk Push to Trendyol', 'woo-trendyol' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Push all WooCommerce products to Trendyol. Only products with a valid Trendyol category mapping and SKU will be submitted.', 'woo-trendyol' ); ?>
                </p>

                <div class="wt-bulk-filters">
                    <label>
                        <input type="checkbox" id="wt-bulk-only-unmapped" />
                        <?php esc_html_e( 'Only products not yet sent to Trendyol', 'woo-trendyol' ); ?>
                    </label>
                </div>

                <!-- Action buttons row -->
                <div class="wt-bulk-action-row">
                    <button type="button" id="wt-bulk-push" class="button button-primary">
                        <span class="dashicons dashicons-upload"></span>
                        <?php esc_html_e( 'Push Products', 'woo-trendyol' ); ?>
                    </button>
                    <button type="button" id="wt-bulk-pause" class="button" style="display:none;">
                        <span class="dashicons dashicons-controls-pause"></span>
                        <?php esc_html_e( 'Pause', 'woo-trendyol' ); ?>
                    </button>
                    <button type="button" id="wt-bulk-resume" class="button button-primary" style="display:none;">
                        <span class="dashicons dashicons-controls-play"></span>
                        <?php esc_html_e( 'Resume', 'woo-trendyol' ); ?>
                    </button>
                    <span class="spinner" id="wt-bulk-spinner" style="float:none;"></span>
                </div>

                <!-- Progress bar -->
                <div id="wt-bulk-progress-wrap" style="display:none; margin-top:10px;">
                    <div class="wt-progress-bar-track">
                        <div class="wt-progress-bar-fill" id="wt-bulk-progress-fill" style="width:0%"></div>
                    </div>
                    <p id="wt-bulk-progress-text" class="wt-progress-label">0 / 0</p>
                </div>

                <!-- Final totals (shown when complete) -->
                <div id="wt-bulk-totals" style="display:none; margin-top:8px;">
                    <table class="wt-totals-table">
                        <tr>
                            <td><?php esc_html_e( 'Total pushed:', 'woo-trendyol' ); ?></td>
                            <td><strong id="wt-total-pushed">0</strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Approved:', 'woo-trendyol' ); ?></td>
                            <td><strong id="wt-total-approved" class="wt-color-success">0</strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Pending:', 'woo-trendyol' ); ?></td>
                            <td><strong id="wt-total-pending" class="wt-color-warning">0</strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Failed:', 'woo-trendyol' ); ?></td>
                            <td><strong id="wt-total-failed" class="wt-color-error">0</strong></td>
                        </tr>
                    </table>
                </div>

                <!-- Per-batch results log -->
                <div id="wt-bulk-results" class="wt-bulk-results" style="display:none; margin-top:8px;">
                    <h4><?php esc_html_e( 'Push Log', 'woo-trendyol' ); ?></h4>
                    <div id="wt-bulk-results-list" style="max-height:180px; overflow-y:auto;"></div>
                </div>
            </div>

            <!-- Brand Sync Card — injected via action hook -->
            <?php do_action( 'wt_settings_sidebar_cards' ); ?>

            <!-- Logs -->
            <div class="wt-card wt-card--logs">
                <h3><?php esc_html_e( 'Sync Logs', 'woo-trendyol' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'All sync and order events are logged in WooCommerce Status Logs.', 'woo-trendyol' ); ?>
                </p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=woo-trendyol' ) ); ?>"
                   class="button button-secondary">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php esc_html_e( 'View Logs', 'woo-trendyol' ); ?>
                </a>
            </div>

            <!-- Quick Reference -->
            <div class="wt-card wt-card--info">
                <h3><?php esc_html_e( 'Quick Reference', 'woo-trendyol' ); ?></h3>
                <dl class="wt-quick-ref">
                    <dt><?php esc_html_e( 'Currency', 'woo-trendyol' ); ?></dt>
                    <dd><?php esc_html_e( 'All prices submitted in EUR (International/Greek marketplace).', 'woo-trendyol' ); ?></dd>

                    <dt><?php esc_html_e( 'Seller ID', 'woo-trendyol' ); ?></dt>
                    <dd><?php esc_html_e( 'Your numeric Trendyol supplier ID.', 'woo-trendyol' ); ?></dd>

                    <dt><?php esc_html_e( 'API Key / Secret', 'woo-trendyol' ); ?></dt>
                    <dd><?php esc_html_e( 'Found in Trendyol Seller Panel → Integration Settings.', 'woo-trendyol' ); ?></dd>

                    <dt><?php esc_html_e( 'Optional Attributes', 'woo-trendyol' ); ?></dt>
                    <dd><?php esc_html_e( 'Automatically omitted — only required attributes are submitted.', 'woo-trendyol' ); ?></dd>
                </dl>
                <p>
                    <a href="https://developers.trendyol.com" target="_blank" rel="noopener">
                        <?php esc_html_e( 'Trendyol API Documentation →', 'woo-trendyol' ); ?>
                    </a>
                </p>
            </div>

        </div><!-- /.wt-sidebar -->

    </div><!-- /.wt-settings-grid -->

</div><!-- /.wrap -->
