<?php
/**
 * Admin settings page partial template — tabbed layout.
 *
 * Variables injected from Woo_Trendyol_Admin::render_settings_page():
 *  @var bool   $is_active       Whether the API connection is currently active.
 *  @var bool   $is_holiday_mode Whether holiday mode is currently active.
 *  @var string $active_tab      The currently active tab slug.
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
    'sync'        => __( 'Synchronization', 'woo-trendyol' ),
    'price_rules' => __( 'Price Rules', 'woo-trendyol' ),
    'tools'       => __( 'Tools', 'woo-trendyol' ),
];
?>
<div class="wrap woo-trendyol-settings-wrap">

    <h1 class="wt-page-title">
        <span class="dashicons dashicons-admin-links wt-page-icon"></span>
        <?php esc_html_e( 'Trendyol Integration', 'woo-trendyol' ); ?>
        <span class="wt-currency-badge">EUR &euro;</span>
    </h1>

    <?php
    $synced_notice = get_transient( 'trendyol_holiday_synced_notice' );
    if ( false !== $synced_notice ) :
        delete_transient( 'trendyol_holiday_synced_notice' );
    ?>
        <div class="notice notice-success is-dismissible" style="margin: 15px 0;">
            <p>
                <strong><?php esc_html_e( 'Holiday Mode Deactivated:', 'woo-trendyol' ); ?></strong>
                <?php
                printf(
                    /* translators: %d: number of synced products */
                    esc_html__( 'Smart Change-Tracking Queue successfully pushed %d queued product stock/price update(s) to Trendyol.', 'woo-trendyol' ),
                    (int) $synced_notice
                );
                ?>
            </p>
        </div>
    <?php
    endif;

    if ( 'credentials' === $active_tab ) {
        settings_errors( 'woo_trendyol_api_settings' );
    } elseif ( 'defaults' === $active_tab ) {
        settings_errors( 'woo_trendyol_defaults_settings' );
    } elseif ( 'attributes' === $active_tab ) {
        settings_errors( 'woo_trendyol_attrs_settings' );
    } elseif ( 'price_rules' === $active_tab ) {
        settings_errors( 'woo_trendyol_price_rules_settings' );
    }
    ?>

    <!-- Connection status banner -->
    <?php if ( $is_holiday_mode ) : ?>
        <div class="wt-connection-banner wt-connection-banner--holiday">
            <span class="wt-connection-dot"></span>
            <strong><?php esc_html_e( 'Holiday Mode Active', 'woo-trendyol' ); ?></strong>
            &mdash; <?php esc_html_e( 'Automatic product synchronization and order polling are currently paused.', 'woo-trendyol' ); ?>
        </div>
    <?php else : ?>
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
    <?php endif; ?>

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
                    case 'sync':
                        echo '<span class="dashicons dashicons-update"></span> ';
                        break;
                    case 'price_rules':
                        echo '<span class="dashicons dashicons-money-alt"></span> ';
                        break;
                    case 'tools':
                        echo '<span class="dashicons dashicons-admin-tools"></span> ';
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
                } elseif ( 'price_rules' === $active_tab ) {
                    settings_fields( 'woo_trendyol_price_rules_settings' );
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
                            <?php esc_html_e( 'Map WooCommerce attributes to their Trendyol equivalents. These global mappings are applied automatically across all categories. Dynamic attributes appearing as required in 2 or more mapped categories are listed below.', 'woo-trendyol' ); ?>
                        </p>
                        <p class="wt-tab-desc">
                            <?php echo wp_kses_post( __( 'First select the WooCommerce attribute, then map each Trendyol value to one or more of your WooCommerce terms.', 'woo-trendyol' ) ); ?>
                        </p>
                    </div>

                    <!-- Category loader for fetching Trendyol attribute values -->
                    <div class="wt-attr-category-loader wt-card wt-card--loader">
                        <h3><?php esc_html_e( 'Load Dynamic Global Attributes', 'woo-trendyol' ); ?></h3>
                        <p class="description">
                            <?php esc_html_e( 'Click to scan all mapped WooCommerce categories and dynamically discover required attributes. Then, load their values to map.', 'woo-trendyol' ); ?>
                        </p>
                        <div class="wt-loader-row">
                            <button type="button" class="button button-primary" id="wt-load-all-mapped-attr-values">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e( 'Load/Refresh Global Attributes', 'woo-trendyol' ); ?>
                            </button>
                            <span id="wt-attr-load-spinner" class="spinner" style="float:none;margin:0 4px;vertical-align:middle;"></span>
                        </div>
                        <div id="wt-attr-load-notice" style="display:none;"></div>
                    </div>

                    <!-- Attribute mapping accordion toolbar -->
                    <div class="wt-accordion-toolbar">
                        <button type="button" class="button button-secondary" id="wt-expand-all-attrs">
                            <span class="dashicons dashicons-editor-expand" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                            <?php esc_html_e( 'Expand All', 'woo-trendyol' ); ?>
                        </button>
                        <button type="button" class="button button-secondary" id="wt-collapse-all-attrs">
                            <span class="dashicons dashicons-editor-contract" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                            <?php esc_html_e( 'Collapse All', 'woo-trendyol' ); ?>
                        </button>
                    </div>

                    <!-- Attribute mapping fields -->
                    <table class="form-table wt-attr-mapping-form-table" role="presentation">
                        <?php do_settings_fields( 'woo-trendyol-settings', 'woo_trendyol_attrs_section' ); ?>
                    </table>

                    <?php submit_button( __( 'Save Attribute Mappings', 'woo-trendyol' ) ); ?>

                <?php elseif ( 'sync' === $active_tab ) : ?>
                    <!-- ================================================== -->
                    <!-- TAB 4: Synchronization                               -->
                    <!-- ================================================== -->
                    <div class="wt-tab-header">
                        <h2>
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e( 'Synchronization', 'woo-trendyol' ); ?>
                        </h2>
                        <p class="wt-tab-desc">
                            <?php esc_html_e( 'Run these synchronization tasks to keep your WooCommerce store aligned with Trendyol\'s catalogue. Following Trendyol V2 Best Practices, you should sync brands and categories before mapping attributes.', 'woo-trendyol' ); ?>
                        </p>
                    </div>

                    <div class="wt-sync-tasks">
                        <!-- Task 1: Sync Brands -->
                        <div class="wt-sync-task-card wt-card">
                            <h3>1. <?php esc_html_e( 'Sync Brands', 'woo-trendyol' ); ?></h3>
                            <p class="description"><?php esc_html_e( 'Fetches brands from Trendyol and attempts to automatically map them to your WooCommerce product brands.', 'woo-trendyol' ); ?></p>
                            <button type="button" class="button button-secondary wt-run-sync-btn" data-action="trendyol_sync_brands">
                                <span class="dashicons dashicons-update-alt"></span> <?php esc_html_e( 'Run Brand Sync', 'woo-trendyol' ); ?>
                            </button>
                            <span class="spinner" style="float:none;vertical-align:middle;margin-left:5px;"></span>
                            <div class="wt-sync-result"></div>
                        </div>

                        <!-- Task 2: Sync Categories -->
                        <div class="wt-sync-task-card wt-card">
                            <h3>2. <?php esc_html_e( 'Sync Categories', 'woo-trendyol' ); ?></h3>
                            <p class="description"><?php esc_html_e( 'Fuzzy matches your WooCommerce leaf-level categories to Trendyol categories. You can review matches on the WooCommerce category edit page.', 'woo-trendyol' ); ?></p>
                            <button type="button" class="button button-secondary wt-run-sync-btn" data-action="trendyol_sync_categories">
                                <span class="dashicons dashicons-update-alt"></span> <?php esc_html_e( 'Run Category Sync', 'woo-trendyol' ); ?>
                            </button>
                            <span class="spinner" style="float:none;vertical-align:middle;margin-left:5px;"></span>
                            <div class="wt-sync-result"></div>
                        </div>

                        <!-- Task 3: Sync Category Attributes -->
                        <div class="wt-sync-task-card wt-card">
                            <h3>3. <?php esc_html_e( 'Sync Category Attributes', 'woo-trendyol' ); ?></h3>
                            <p class="description"><?php esc_html_e( 'Fetches required attributes for all your mapped categories and stores them. Run this after mapping categories.', 'woo-trendyol' ); ?></p>
                            <button type="button" class="button button-secondary wt-run-sync-btn" data-action="trendyol_sync_category_attributes">
                                <span class="dashicons dashicons-update-alt"></span> <?php esc_html_e( 'Run Attribute Sync', 'woo-trendyol' ); ?>
                            </button>
                            <span class="spinner" style="float:none;vertical-align:middle;margin-left:5px;"></span>
                            <div class="wt-sync-result"></div>
                        </div>

                        <!-- Task 4: Sync Attribute Values (Fuzzy Match) -->
                        <div class="wt-sync-task-card wt-card">
                            <h3>4. <?php esc_html_e( 'Sync Attribute Values (Gender/Age)', 'woo-trendyol' ); ?></h3>
                            <p class="description"><?php esc_html_e( 'Automatically fuzzy matches Trendyol gender and age values to your global WooCommerce attributes. Other required attributes must be mapped manually on the category edit page.', 'woo-trendyol' ); ?></p>
                            <button type="button" class="button button-secondary wt-run-sync-btn" data-action="trendyol_sync_attribute_values">
                                <span class="dashicons dashicons-update-alt"></span> <?php esc_html_e( 'Run Value Sync', 'woo-trendyol' ); ?>
                            </button>
                            <span class="spinner" style="float:none;vertical-align:middle;margin-left:5px;"></span>
                            <div class="wt-sync-result"></div>
                        </div>
                    </div>

                <?php elseif ( 'price_rules' === $active_tab ) : ?>
                    <!-- ================================================== -->
                    <!-- TAB 5: Price Rules                                 -->
                    <!-- ================================================== -->
                    <div class="wt-tab-header">
                        <h2>
                            <span class="dashicons dashicons-money-alt"></span>
                            <?php esc_html_e( 'Price Rules', 'woo-trendyol' ); ?>
                        </h2>
                        <p class="wt-tab-desc">
                            <?php esc_html_e( 'Globally adjust product prices sent to Trendyol. Check the switches to activate and configure specific adjustments.', 'woo-trendyol' ); ?>
                        </p>
                    </div>

                    <table class="form-table" role="presentation">
                        <?php do_settings_fields( 'woo-trendyol-settings', 'woo_trendyol_price_rules_section' ); ?>
                    </table>

                    <?php submit_button( __( 'Save Price Rules', 'woo-trendyol' ) ); ?>
                <?php elseif ( 'tools' === $active_tab ) : ?>
                    <?php include WOO_TRENDYOL_PATH . 'admin/partials/woo-trendyol-admin-tools.php'; ?>
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
                    <?php if ( $is_holiday_mode ) : ?>
                        <span class="wt-status-badge wt-status-badge--warning" style="background:#fff8e5; color:#7a5c00; border:1px solid #f0b849;">
                            <span class="dashicons dashicons-palmtree"></span>
                            <?php esc_html_e( 'Holiday Mode Active', 'woo-trendyol' ); ?>
                        </span>
                    <?php elseif ( $is_active ) : ?>
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
                    <br>
                    <label style="margin-top:5px; display:inline-block;">
                        <input type="checkbox" id="wt-bulk-include-out-of-stock" />
                        <?php esc_html_e( 'Send also products out of stock', 'woo-trendyol' ); ?>
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
                    <button type="button" id="wt-bulk-cancel" class="button button-secondary" style="display:none;">
                        <span class="dashicons dashicons-no-alt"></span>
                        <?php esc_html_e( 'Cancel', 'woo-trendyol' ); ?>
                    </button>
                    <span class="spinner" id="wt-bulk-spinner" style="float:none;"></span>
                </div>

                <!-- Progress bar -->
                <div id="wt-bulk-progress-wrap" style="display:none; margin-top:10px;">
                    <div class="wt-progress-bar-track">
                        <div class="wt-progress-bar-fill" id="wt-bulk-progress-fill" style="width:0%"></div>
                    </div>
                    <p id="wt-bulk-progress-text" class="wt-progress-label">0 / 0</p>
                    <div id="wt-bulk-current-ids" style="font-size: 11px; color: #666; margin-top: 4px; text-align: center;"></div>
                    <p class="wt-keep-tab-open-notice" style="margin-top:6px; font-size:12px; color:#b32d2e; font-weight:500; text-align:center;">
                        <span class="dashicons dashicons-warning" style="font-size:14px; width:14px; height:14px; vertical-align:-2px;"></span>
                        <?php esc_html_e( 'Please keep this browser tab open until the process finishes.', 'woo-trendyol' ); ?>
                    </p>
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
                    <div class="wt-log-header">
                        <h4><?php esc_html_e( 'Push Log', 'woo-trendyol' ); ?></h4>
                        <div class="wt-log-actions">
                            <button type="button" class="button button-small wt-copy-log-btn" data-target="#wt-bulk-results-list">
                                <span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy Log', 'woo-trendyol' ); ?>
                            </button>
                            <button type="button" class="button button-small wt-download-log-btn" data-target="#wt-bulk-results-list" data-filename="trendyol-bulk-push-log.txt">
                                <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download (.txt)', 'woo-trendyol' ); ?>
                            </button>
                        </div>
                    </div>
                    <div id="wt-bulk-results-list" class="wt-log-box" style="max-height:220px; overflow-y:auto;"></div>
                </div>
            </div>

            <!-- Sync Price & Stock Tool -->
            <div class="wt-card wt-card--sync-price-stock">
                <h3><?php esc_html_e( 'Sync Price & Stock Only', 'woo-trendyol' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Quickly synchronize only price and inventory levels for your mapped products.', 'woo-trendyol' ); ?>
                </p>

                <div class="wt-bulk-filters">
                    <label>
                        <input type="checkbox" id="wt-sync-include-out-of-stock" />
                        <?php esc_html_e( 'Sync also products out of stock', 'woo-trendyol' ); ?>
                    </label>
                </div>

                <!-- Action buttons row -->
                <div class="wt-bulk-action-row">
                    <button type="button" id="wt-sync-price-stock" class="button button-primary">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Sync Price & Stock', 'woo-trendyol' ); ?>
                    </button>
                    <button type="button" id="wt-sync-pause" class="button" style="display:none;">
                        <span class="dashicons dashicons-controls-pause"></span>
                        <?php esc_html_e( 'Pause', 'woo-trendyol' ); ?>
                    </button>
                    <button type="button" id="wt-sync-resume" class="button button-primary" style="display:none;">
                        <span class="dashicons dashicons-controls-play"></span>
                        <?php esc_html_e( 'Resume', 'woo-trendyol' ); ?>
                    </button>
                    <button type="button" id="wt-sync-cancel" class="button button-secondary" style="display:none;">
                        <span class="dashicons dashicons-no-alt"></span>
                        <?php esc_html_e( 'Cancel', 'woo-trendyol' ); ?>
                    </button>
                    <span class="spinner" id="wt-sync-spinner" style="float:none;"></span>
                </div>

                <!-- Progress bar -->
                <div id="wt-sync-progress-wrap" style="display:none; margin-top:10px;">
                    <div class="wt-progress-bar-track">
                        <div class="wt-progress-bar-fill" id="wt-sync-progress-fill" style="width:0%"></div>
                    </div>
                    <p id="wt-sync-progress-text" class="wt-progress-label">0 / 0</p>
                    <div id="wt-sync-current-ids" style="font-size: 11px; color: #666; margin-top: 4px; text-align: center;"></div>
                    <p class="wt-keep-tab-open-notice" style="margin-top:6px; font-size:12px; color:#b32d2e; font-weight:500; text-align:center;">
                        <span class="dashicons dashicons-warning" style="font-size:14px; width:14px; height:14px; vertical-align:-2px;"></span>
                        <?php esc_html_e( 'Please keep this browser tab open until the process finishes.', 'woo-trendyol' ); ?>
                    </p>
                </div>

                <!-- Per-batch results log -->
                <div id="wt-sync-results" class="wt-bulk-results" style="display:none; margin-top:8px;">
                    <div class="wt-log-header">
                        <h4><?php esc_html_e( 'Sync Log', 'woo-trendyol' ); ?></h4>
                        <div class="wt-log-actions">
                            <button type="button" class="button button-small wt-copy-log-btn" data-target="#wt-sync-results-list">
                                <span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy Log', 'woo-trendyol' ); ?>
                            </button>
                            <button type="button" class="button button-small wt-download-log-btn" data-target="#wt-sync-results-list" data-filename="trendyol-price-stock-sync-log.txt">
                                <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download (.txt)', 'woo-trendyol' ); ?>
                            </button>
                        </div>
                    </div>
                    <div id="wt-sync-results-list" class="wt-log-box" style="max-height:220px; overflow-y:auto;"></div>
                </div>
            </div>

            <!-- Update Unapproved Products Tool -->
            <div class="wt-card wt-card--unapproved-push">
                <h3><?php esc_html_e( 'Update Unapproved Products', 'woo-trendyol' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Update content, images, and attributes for products currently on unapproved status in Trendyol using the unapproved-bulk-update API.', 'woo-trendyol' ); ?>
                </p>

                <!-- Action buttons row -->
                <div class="wt-bulk-action-row">
                    <button type="button" id="wt-unapproved-push" class="button button-primary">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Update Unapproved Products', 'woo-trendyol' ); ?>
                    </button>
                    <button type="button" id="wt-unapproved-pause" class="button" style="display:none;">
                        <span class="dashicons dashicons-controls-pause"></span>
                        <?php esc_html_e( 'Pause', 'woo-trendyol' ); ?>
                    </button>
                    <button type="button" id="wt-unapproved-resume" class="button button-primary" style="display:none;">
                        <span class="dashicons dashicons-controls-play"></span>
                        <?php esc_html_e( 'Resume', 'woo-trendyol' ); ?>
                    </button>
                    <button type="button" id="wt-unapproved-cancel" class="button button-secondary" style="display:none;">
                        <span class="dashicons dashicons-no-alt"></span>
                        <?php esc_html_e( 'Cancel', 'woo-trendyol' ); ?>
                    </button>
                    <span class="spinner" id="wt-unapproved-spinner" style="float:none;"></span>
                </div>

                <!-- Progress bar -->
                <div id="wt-unapproved-progress-wrap" style="display:none; margin-top:10px;">
                    <div class="wt-progress-bar-track">
                        <div class="wt-progress-bar-fill" id="wt-unapproved-progress-fill" style="width:0%"></div>
                    </div>
                    <p id="wt-unapproved-progress-text" class="wt-progress-label">0 / 0</p>
                    <div id="wt-unapproved-current-ids" style="font-size: 11px; color: #666; margin-top: 4px; text-align: center;"></div>
                    <p class="wt-keep-tab-open-notice" style="margin-top:6px; font-size:12px; color:#b32d2e; font-weight:500; text-align:center;">
                        <span class="dashicons dashicons-warning" style="font-size:14px; width:14px; height:14px; vertical-align:-2px;"></span>
                        <?php esc_html_e( 'Please keep this browser tab open until the process finishes.', 'woo-trendyol' ); ?>
                    </p>
                </div>

                <!-- Final totals -->
                <div id="wt-unapproved-totals" style="display:none; margin-top:8px;"></div>

                <!-- Per-batch results log -->
                <div id="wt-unapproved-results" class="wt-bulk-results" style="display:none; margin-top:8px;">
                    <div class="wt-log-header">
                        <h4><?php esc_html_e( 'Unapproved Update Log', 'woo-trendyol' ); ?></h4>
                        <div class="wt-log-actions">
                            <button type="button" class="button button-small wt-copy-log-btn" data-target="#wt-unapproved-results-list">
                                <span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy Log', 'woo-trendyol' ); ?>
                            </button>
                            <button type="button" class="button button-small wt-download-log-btn" data-target="#wt-unapproved-results-list" data-filename="trendyol-unapproved-update-log.txt">
                                <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download (.txt)', 'woo-trendyol' ); ?>
                            </button>
                        </div>
                    </div>
                    <div id="wt-unapproved-results-list" class="wt-log-box" style="max-height:220px; overflow-y:auto;"></div>
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
