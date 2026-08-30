<?php
/**
 * Product meta box partial template.
 *
 * Variables available from Woo_Trendyol_Admin::render_product_meta_box():
 *  @var int    $post_id          Product post ID.
 *  @var string $sent             '_trendyol_sent' meta value ('yes'|'').
 *  @var string $approved         '_trendyol_approved' meta value ('yes'|'no'|'').
 *  @var string $on_sale          '_trendyol_on_sale' meta value ('yes'|'no'|'').
 *  @var string $archived         '_trendyol_archived' meta value ('yes'|'no'|'').
 *  @var string $blacklisted      '_trendyol_blacklisted' meta value ('yes'|'no'|'').
 *  @var string $last_sync_human  Human-readable time since last sync.
 *  @var int    $last_sync        Unix timestamp of last sync.
 *  @var string $last_price       Last synced price.
 *  @var string $last_stock       Last synced stock quantity.
 *  @var string $sync_status      'success'|'error'|'pending'|''.
 *  @var string $sync_error       Error message (empty on success).
 *  @var string $batch_id         Trendyol batch request ID.
 *  @var string $category_id      Resolved Trendyol category ID (or '').
 *  @var string $category_path    Resolved Trendyol category path (or '').
 *  @var string $override         Raw product-level override value.
 *  @var bool   $override_active  Whether an override is set.
 *  @var string $category_source  Human-readable source label.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

// Helper: render a status badge.
$badge = static function ( string $value, string $yes_label = '', string $no_label = '' ): string {
    if ( '' === $value ) {
        return '<span class="wt-badge wt-badge--unknown">' . esc_html__( 'Unknown', 'woo-trendyol' ) . '</span>';
    }
    if ( 'yes' === $value ) {
        $label = $yes_label ?: esc_html__( 'Yes', 'woo-trendyol' );
        return '<span class="wt-badge wt-badge--success">' . esc_html( $label ) . '</span>';
    }
    if ( 'partial' === $value ) {
        return '<span class="wt-badge wt-badge--warning">' . esc_html__( 'Partial', 'woo-trendyol' ) . '</span>';
    }
    $label = $no_label ?: esc_html__( 'No', 'woo-trendyol' );
    return '<span class="wt-badge wt-badge--error">' . esc_html( $label ) . '</span>';
};

// Sync status badge.
$sync_badge = '';
if ( 'success' === $sync_status ) {
    $sync_badge = '<span class="wt-badge wt-badge--success">' . esc_html__( 'Success', 'woo-trendyol' ) . '</span>';
} elseif ( 'error' === $sync_status ) {
    $sync_badge = '<span class="wt-badge wt-badge--error">' . esc_html__( 'Error', 'woo-trendyol' ) . '</span>';
} elseif ( 'pending' === $sync_status ) {
    $sync_badge = '<span class="wt-badge wt-badge--pending">' . esc_html__( 'Pending', 'woo-trendyol' ) . '</span>';
} else {
    $sync_badge = '<span class="wt-badge wt-badge--unknown">' . esc_html__( 'Not synced', 'woo-trendyol' ) . '</span>';
}
?>

<div class="wt-product-meta-box">

    <!-- ================================================================
         SECTION 1: Category Mapping
         ================================================================ -->
    <div class="wt-meta-section">
        <h4 class="wt-meta-section-title"><?php esc_html_e( 'Trendyol Category', 'woo-trendyol' ); ?></h4>

        <table class="wt-meta-table">
            <tr>
                <th><?php esc_html_e( 'Category ID', 'woo-trendyol' ); ?></th>
                <td>
                    <?php if ( ! empty( $category_id ) ) : ?>
                        <code class="wt-code"><?php echo esc_html( $category_id ); ?></code>
                        <span class="wt-source-label">(<?php echo esc_html( $category_source ); ?>)</span>
                        <?php if ( ! empty( $category_supports_slicers ) ) : ?>
                            <span class="wt-badge wt-badge--success" style="margin-left: 6px; font-size: 11px;"><?php esc_html_e( 'Supports Variations / Slicers', 'woo-trendyol' ); ?></span>
                        <?php else : ?>
                            <span class="wt-badge wt-badge--warning" style="margin-left: 6px; font-size: 11px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba;"><?php esc_html_e( 'No Variation / Slicer Support', 'woo-trendyol' ); ?></span>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="wt-badge wt-badge--warning"><?php esc_html_e( 'Not mapped', 'woo-trendyol' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ( ! empty( $category_path ) ) : ?>
            <tr>
                <th><?php esc_html_e( 'Category Path', 'woo-trendyol' ); ?></th>
                <td><span class="wt-category-path"><?php echo esc_html( $category_path ); ?></span></td>
            </tr>
            <?php endif; ?>
        </table>

        <?php if ( ! empty( $is_variable ) && ! empty( $category_id ) && empty( $category_supports_slicers ) ) : ?>
            <div class="wt-slicer-warning-box" style="margin: 12px 0; padding: 12px 14px; background: #fff8e5; border-left: 4px solid #ffb900; border-radius: 2px;">
                <div style="font-weight: 600; color: #855a00; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                    <span class="dashicons dashicons-warning" style="color: #dba617; font-size: 18px; line-height: 1;"></span>
                    <?php esc_html_e( 'Category Does Not Support Variations / Slicers on Trendyol', 'woo-trendyol' ); ?>
                </div>
                <p style="margin: 0 0 6px; font-size: 12px; line-height: 1.4; color: #555;">
                    <?php printf(
                        esc_html__( 'Trendyol Category %1$s has no variation slicers (Color/Size/Pattern/Σχέδιο). On Trendyol, items in this category cannot be displayed under one product card with variation dropdown buttons.', 'woo-trendyol' ),
                        '<strong>#' . esc_html( $category_id ) . '</strong>'
                    ); ?>
                </p>
                <div style="font-size: 12px; color: #333; line-height: 1.4;">
                    <strong><?php esc_html_e( 'Current behavior:', 'woo-trendyol' ); ?></strong>
                    <?php
                    $is_active_split = ( 'yes' === $force_split ) || ( '' === $force_split && 'no' !== $global_split_setting );
                    if ( $is_active_split ) {
                        echo '<span style="color: #1b6d2e; font-weight: 600;"> ' . esc_html__( 'Each variation will be created as a separate standalone product on Trendyol with its full variation name.', 'woo-trendyol' ) . '</span>';
                    } else {
                        echo '<span style="color: #a00; font-weight: 600;"> ' . esc_html__( 'Variations will be sent grouped together under the parent SKU (Trendyol will reject duplicate variations).', 'woo-trendyol' ) . '</span>';
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $is_variable ) ) : ?>
            <!-- Variation Splitting Option -->
            <div class="wt-override-field" style="margin-top: 15px;">
                <label for="_trendyol_force_split_variations">
                    <strong><?php esc_html_e( 'Variation Splitting Behavior', 'woo-trendyol' ); ?></strong>
                </label>
                <select id="_trendyol_force_split_variations" name="_trendyol_force_split_variations" style="width: 100%; max-width: 400px;">
                    <option value="" <?php selected( $force_split, '' ); ?>>
                        <?php printf( esc_html__( 'Follow Global Default (%s)', 'woo-trendyol' ), ( 'no' === $global_split_setting ? esc_html__( 'Do Not Split', 'woo-trendyol' ) : esc_html__( 'Split if Category Lacks Slicers', 'woo-trendyol' ) ) ); ?>
                    </option>
                    <option value="yes" <?php selected( $force_split, 'yes' ); ?>>
                        <?php esc_html_e( 'Force Split into Individual Standalone Products', 'woo-trendyol' ); ?>
                    </option>
                    <option value="no" <?php selected( $force_split, 'no' ); ?>>
                        <?php esc_html_e( 'Do Not Split (Keep Grouped Under Parent SKU)', 'woo-trendyol' ); ?>
                    </option>
                </select>
                <p class="description">
                    <?php esc_html_e( 'Choose whether to push child variations as individual standalone listings on Trendyol or keep them grouped under the parent product.', 'woo-trendyol' ); ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Category override field (editable) -->
        <div class="wt-override-field">
            <label for="_trendyol_category_id_override">
                <strong><?php esc_html_e( 'Category Override', 'woo-trendyol' ); ?></strong>
            </label>
            <input type="text"
                   id="_trendyol_category_id_override"
                   name="_trendyol_category_id_override"
                   value="<?php echo esc_attr( $override ); ?>"
                   class="regular-text"
                   placeholder="<?php esc_attr_e( 'Enter Trendyol category ID to override', 'woo-trendyol' ); ?>" />
            <p class="description">
                <?php esc_html_e( 'Leave blank to use the category-level mapping. Enter a numeric Trendyol leaf category ID to override for this product only.', 'woo-trendyol' ); ?>
            </p>
            <?php if ( $override_active ) : ?>
                <p class="wt-override-active-notice">
                    <?php esc_html_e( 'Override is active. Category-level mapping is ignored for this product.', 'woo-trendyol' ); ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Price Settings & Override -->
        <div class="wt-override-field" style="margin-top: 15px;">
            <label for="_trendyol_price_override">
                <strong><?php esc_html_e( 'Price Override', 'woo-trendyol' ); ?></strong>
            </label>
            <input type="number"
                   step="0.01"
                   id="_trendyol_price_override"
                   name="_trendyol_price_override"
                   value="<?php echo esc_attr( $price_override ); ?>"
                   class="short-text"
                   placeholder="<?php esc_attr_e( 'e.g. 29.99', 'woo-trendyol' ); ?>" /> &euro;
            <p class="description">
                <?php esc_html_e( 'Enter a specific price for Trendyol. If set, this price will override the global price rules and category-level rules.', 'woo-trendyol' ); ?>
            </p>
        </div>

        <div class="wt-calculated-price-display" style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-left: 4px solid #11a0d2;">
            <strong><?php esc_html_e( 'Calculated Trendyol Price:', 'woo-trendyol' ); ?></strong>
            <span style="font-size: 14px; font-weight: bold; margin-left: 5px; color: #11a0d2;">
                <?php echo wp_kses_post( $calculated_price_display ); ?>
            </span>
            <p class="description" style="margin-top: 3px;">
                <?php esc_html_e( 'The actual price that will be submitted to Trendyol, based on active global rules, category extra percentage, and overrides.', 'woo-trendyol' ); ?>
            </p>
        </div>
    </div>

    <hr class="wt-divider" />

    <!-- ================================================================
         SECTION 2: Send to Trendyol
         ================================================================ -->
    <div class="wt-meta-section wt-send-section">
        <h4 class="wt-meta-section-title"><?php esc_html_e( 'Push to Trendyol', 'woo-trendyol' ); ?></h4>

        <?php if ( 'yes' === $sent ) : ?>
            <!-- Product already sent — show re-send option with a note -->
            <p class="description wt-send-note">
                <?php esc_html_e( 'This product has already been sent to Trendyol. You can re-send it to update the listing.', 'woo-trendyol' ); ?>
            </p>
        <?php else : ?>
            <p class="description wt-send-note">
                <?php esc_html_e( 'Send this product to Trendyol for the first time. Make sure a category is mapped and the product has a SKU.', 'woo-trendyol' ); ?>
            </p>
        <?php endif; ?>

        <!-- Prerequisites checklist -->
        <ul class="wt-prereq-list">
            <li class="wt-prereq-item <?php echo ! empty( $category_id ) ? 'wt-prereq--ok' : 'wt-prereq--fail'; ?>">
                <span class="wt-prereq-icon"><?php echo ! empty( $category_id ) ? '&#10003;' : '&#10007;'; ?></span>
                <?php if ( ! empty( $category_id ) ) : ?>
                    <?php esc_html_e( 'Trendyol category mapped', 'woo-trendyol' ); ?>
                    <code class="wt-code wt-code--small"><?php echo esc_html( $category_id ); ?></code>
                <?php else : ?>
                    <?php esc_html_e( 'No Trendyol category mapped — assign one above', 'woo-trendyol' ); ?>
                <?php endif; ?>
            </li>
            <?php
            $product_obj = wc_get_product( $post_id );
            $has_sku     = false;
            $sku_label   = '';
            if ( $product_obj ) {
                if ( $product_obj->is_type( 'variable' ) ) {
                    if ( ! empty( $product_obj->get_sku() ) ) {
                        $has_sku   = true;
                        $sku_label = $product_obj->get_sku();
                    } else {
                        $children = $product_obj->get_children();
                        if ( ! empty( $children ) ) {
                            $has_sku   = true;
                            $sku_label = sprintf( _n( '%d variation', '%d variations', count( $children ), 'woo-trendyol' ), count( $children ) );
                        }
                    }
                } else {
                    $has_sku   = ! empty( $product_obj->get_sku() );
                    $sku_label = $product_obj->get_sku();
                }
            }
            ?>
            <li class="wt-prereq-item <?php echo $has_sku ? 'wt-prereq--ok' : 'wt-prereq--fail'; ?>">
                <span class="wt-prereq-icon"><?php echo $has_sku ? '&#10003;' : '&#10007;'; ?></span>
                <?php if ( $has_sku ) : ?>
                    <?php esc_html_e( 'SKU set', 'woo-trendyol' ); ?>
                    <code class="wt-code wt-code--small"><?php echo esc_html( $sku_label ); ?></code>
                <?php else : ?>
                    <?php esc_html_e( 'No SKU — add a SKU to this product', 'woo-trendyol' ); ?>
                <?php endif; ?>
            </li>
        </ul>

        <!-- Send button row -->
        <div class="wt-send-row">
            <button type="button"
                    id="wt-send-to-trendyol"
                    class="button <?php echo 'yes' === $sent ? 'button-secondary' : 'button-primary'; ?> wt-send-btn"
                    data-post-id="<?php echo esc_attr( $post_id ); ?>"
                    <?php echo ( empty( $category_id ) || ! $has_sku ) ? 'disabled' : ''; ?>>
                <?php echo 'yes' === $sent
                    ? esc_html__( 'Re-send to Trendyol', 'woo-trendyol' )
                    : esc_html__( 'Send to Trendyol', 'woo-trendyol' );
                ?>
            </button>
            <span class="spinner wt-send-spinner" id="wt-send-spinner"></span>
        </div>

        <!-- Result notice area -->
        <div id="wt-send-result" class="wt-send-result" style="display:none;"></div>
    </div>

    <hr class="wt-divider" />

    <!-- ================================================================
         SECTION 3: Sync Status (read-only)
         ================================================================ -->
    <div class="wt-meta-section">
        <h4 class="wt-meta-section-title"><?php esc_html_e( 'Sync Status', 'woo-trendyol' ); ?></h4>

        <table class="wt-meta-table">
            <tr>
                <th><?php esc_html_e( 'Sent to Trendyol', 'woo-trendyol' ); ?></th>
                <td><?php echo $badge( $sent ?: 'no', __( 'Yes', 'woo-trendyol' ), __( 'No', 'woo-trendyol' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Approval Status', 'woo-trendyol' ); ?></th>
                <td><?php echo $badge( $approved, __( 'Approved', 'woo-trendyol' ), __( 'Not Approved', 'woo-trendyol' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'On Sale', 'woo-trendyol' ); ?></th>
                <td><?php echo $badge( $on_sale, __( 'Yes', 'woo-trendyol' ), __( 'No', 'woo-trendyol' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Archived', 'woo-trendyol' ); ?></th>
                <td><?php echo $badge( $archived, __( 'Yes', 'woo-trendyol' ), __( 'No', 'woo-trendyol' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Blacklisted', 'woo-trendyol' ); ?></th>
                <td><?php echo $badge( $blacklisted, __( 'Yes', 'woo-trendyol' ), __( 'No', 'woo-trendyol' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Last Sync', 'woo-trendyol' ); ?></th>
                <td>
                    <?php echo esc_html( $last_sync_human ); ?>
                    <?php if ( $last_sync ) : ?>
                        <br><small><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $last_sync ) ); ?></small>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ( ! empty( $last_price ) ) : ?>
            <tr>
                <th><?php esc_html_e( 'Last Price', 'woo-trendyol' ); ?></th>
                <td><?php echo esc_html( $last_price ); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ( ! empty( $last_stock ) ) : ?>
            <tr>
                <th><?php esc_html_e( 'Last Stock', 'woo-trendyol' ); ?></th>
                <td><?php echo esc_html( $last_stock ); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th><?php esc_html_e( 'Sync Result', 'woo-trendyol' ); ?></th>
                <td><?php echo $sync_badge; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            </tr>
            <?php if ( ! empty( $sync_error ) ) : ?>
            <tr>
                <th><?php esc_html_e( 'Error', 'woo-trendyol' ); ?></th>
                <td><span class="wt-error-message"><?php echo esc_html( $sync_error ); ?></span></td>
            </tr>
            <?php endif; ?>
            <?php if ( ! empty( $batch_id ) ) : ?>
            <tr>
                <th><?php esc_html_e( 'Batch ID', 'woo-trendyol' ); ?></th>
                <td><code class="wt-code wt-code--small"><?php echo esc_html( $batch_id ); ?></code></td>
            </tr>
            <?php endif; ?>
        </table>

        <?php if ( ! empty( $variations_data ) ) : ?>
            <h5 style="margin: 18px 0 8px; font-size: 13px; font-weight: 600; color: #1d2327;">
                <?php esc_html_e( 'Variations Sync Details', 'woo-trendyol' ); ?>
            </h5>
            <div class="wt-variations-table-wrapper" style="overflow-x: auto; margin-bottom: 12px;">
                <table class="widefat striped" style="font-size: 12px; border: 1px solid #c3c4c7;">
                    <thead>
                        <tr>
                            <th style="padding: 6px 8px;"><?php esc_html_e( 'Variation', 'woo-trendyol' ); ?></th>
                            <th style="padding: 6px 8px;"><?php esc_html_e( 'Barcode', 'woo-trendyol' ); ?></th>
                            <th style="padding: 6px 8px;"><?php esc_html_e( 'Approved', 'woo-trendyol' ); ?></th>
                            <th style="padding: 6px 8px;"><?php esc_html_e( 'On Sale', 'woo-trendyol' ); ?></th>
                            <th style="padding: 6px 8px;"><?php esc_html_e( 'Stock', 'woo-trendyol' ); ?></th>
                            <th style="padding: 6px 8px;"><?php esc_html_e( 'Price', 'woo-trendyol' ); ?></th>
                            <th style="padding: 6px 8px;"><?php esc_html_e( 'Status', 'woo-trendyol' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $variations_data as $v ) : ?>
                            <tr>
                                <td style="padding: 6px 8px;">
                                    <strong>#<?php echo esc_html( $v['id'] ); ?></strong>
                                    <?php if ( ! empty( $v['name'] ) ) : ?>
                                        <br><small style="color: #646970;"><?php echo esc_html( $v['name'] ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 6px 8px;">
                                    <?php if ( ! empty( $v['barcode'] ) ) : ?>
                                        <code><?php echo esc_html( $v['barcode'] ); ?></code>
                                    <?php else : ?>
                                        <span class="wt-badge wt-badge--error"><?php esc_html_e( 'Missing', 'woo-trendyol' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 6px 8px;"><?php echo $badge( $v['approved'], __( 'Yes', 'woo-trendyol' ), __( 'No', 'woo-trendyol' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td style="padding: 6px 8px;"><?php echo $badge( $v['on_sale'], __( 'Yes', 'woo-trendyol' ), __( 'No', 'woo-trendyol' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td style="padding: 6px 8px;"><?php echo esc_html( (string) $v['stock'] ); ?></td>
                                <td style="padding: 6px 8px;">
                                    <?php if ( $v['sale_price'] < $v['list_price'] ) : ?>
                                        <del style="color: #8c8f94; font-size: 11px;"><?php echo esc_html( number_format( $v['list_price'], 2 ) ); ?></del><br>
                                        <strong><?php echo esc_html( number_format( $v['sale_price'], 2 ) ); ?> &euro;</strong>
                                    <?php else : ?>
                                        <?php echo esc_html( number_format( $v['sale_price'], 2 ) ); ?> &euro;
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 6px 8px;">
                                    <?php if ( 'success' === $v['sync_status'] ) : ?>
                                        <span class="wt-badge wt-badge--success"><?php esc_html_e( 'Synced', 'woo-trendyol' ); ?></span>
                                    <?php elseif ( 'error' === $v['sync_status'] ) : ?>
                                        <span class="wt-badge wt-badge--error" title="<?php echo esc_attr( $v['sync_error'] ); ?>"><?php esc_html_e( 'Error', 'woo-trendyol' ); ?></span>
                                    <?php elseif ( 'pending' === $v['sync_status'] ) : ?>
                                        <span class="wt-badge wt-badge--pending"><?php esc_html_e( 'Pending', 'woo-trendyol' ); ?></span>
                                    <?php else : ?>
                                        <span class="wt-badge wt-badge--unknown"><?php esc_html_e( 'Not synced', 'woo-trendyol' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Refresh status button -->
        <div class="wt-refresh-row">
            <button type="button"
                    id="wt-refresh-status"
                    class="button button-secondary"
                    data-post-id="<?php echo esc_attr( $post_id ); ?>">
                <?php esc_html_e( 'Refresh Status', 'woo-trendyol' ); ?>
            </button>
            <span id="wt-refresh-result" class="wt-refresh-result" style="display:none;"></span>
        </div>
    </div>

</div><!-- /.wt-product-meta-box -->
