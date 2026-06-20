<?php
/**
 * Trendyol brand mapping panel — rendered on the Edit Brand (product_brand) page.
 *
 * Variables available from Woo_Trendyol_Brand_Admin::render_brand_edit_fields():
 *  @var WP_Term $term       The term being edited.
 *  @var int     $brand_id   Currently mapped Trendyol brand ID (0 = none).
 *  @var string  $brand_name Currently mapped Trendyol brand name.
 *
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
?>
<tr class="form-field wt-brand-mapping-row">
    <th scope="row">
        <label><?php esc_html_e( 'Trendyol Brand Mapping', 'woo-trendyol' ); ?></label>
    </th>
    <td>
        <?php if ( $brand_id ) : ?>
        <div class="wt-brand-current-mapping" id="wt-brand-current-<?php echo esc_attr( $term->term_id ); ?>">
            <span class="wt-brand-dot wt-brand-dot--matched">&#9679;</span>
            <strong><?php echo esc_html( $brand_name ); ?></strong>
            <span class="wt-brand-id-badge">(ID: <?php echo esc_html( $brand_id ); ?>)</span>
            <button type="button"
                    class="button button-small wt-btn-clear-brand"
                    data-term-id="<?php echo esc_attr( $term->term_id ); ?>"
                    style="margin-left:8px;">
                <?php esc_html_e( 'Clear mapping', 'woo-trendyol' ); ?>
            </button>
        </div>
        <?php else : ?>
        <div class="wt-brand-current-mapping" id="wt-brand-current-<?php echo esc_attr( $term->term_id ); ?>">
            <span class="wt-brand-dot wt-brand-dot--unmatched">&#9679;</span>
            <em><?php esc_html_e( 'No Trendyol brand mapped yet.', 'woo-trendyol' ); ?></em>
        </div>
        <?php endif; ?>

        <div class="wt-brand-search-panel" style="margin-top:12px;">
            <label for="wt-brand-search-input-<?php echo esc_attr( $term->term_id ); ?>" class="screen-reader-text">
                <?php esc_html_e( 'Search Trendyol brands', 'woo-trendyol' ); ?>
            </label>
            <div class="wt-brand-search-row">
                <input type="text"
                       id="wt-brand-search-input-<?php echo esc_attr( $term->term_id ); ?>"
                       class="wt-brand-search-input regular-text"
                       data-term-id="<?php echo esc_attr( $term->term_id ); ?>"
                       value="<?php echo esc_attr( $term->name ); ?>"
                       placeholder="<?php esc_attr_e( 'Enter brand name to search…', 'woo-trendyol' ); ?>" />
                <button type="button"
                        class="button wt-btn-brand-search"
                        data-term-id="<?php echo esc_attr( $term->term_id ); ?>">
                    <?php esc_html_e( 'Search Trendyol', 'woo-trendyol' ); ?>
                </button>
                <span class="spinner wt-brand-search-spinner" style="float:none;"></span>
            </div>

            <div class="wt-brand-search-results"
                 id="wt-brand-results-<?php echo esc_attr( $term->term_id ); ?>"
                 style="display:none; margin-top:8px;">
                <table class="widefat striped wt-brand-results-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Trendyol Brand Name', 'woo-trendyol' ); ?></th>
                            <th><?php esc_html_e( 'ID', 'woo-trendyol' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'woo-trendyol' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wt-brand-results-body-<?php echo esc_attr( $term->term_id ); ?>">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>

            <div class="wt-brand-save-notice"
                 id="wt-brand-notice-<?php echo esc_attr( $term->term_id ); ?>"
                 style="display:none; margin-top:6px;">
            </div>
        </div>

        <p class="description" style="margin-top:8px;">
            <?php esc_html_e( 'Search for the matching Trendyol brand and click Select to save the mapping. The mapped brand ID will be sent to Trendyol when products in this brand are pushed.', 'woo-trendyol' ); ?>
        </p>
    </td>
</tr>
