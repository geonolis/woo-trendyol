<?php
/**
 * Taxonomy partial — Bulk Edit Modal.
 *
 * Renders a modal for selecting a Trendyol category during a bulk action.
 *
 * @since   1.0.0
 * @package Woo_Trendyol
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
?>

<div id="wt-bulk-modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 99999;"></div>

<div id="wt-bulk-modal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 600px; max-width: 90%; background: #fff; padding: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); z-index: 100000; border-radius: 4px;">
    
    <h2 style="margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
        <?php esc_html_e( 'Bulk Map Trendyol Category', 'woo-trendyol' ); ?>
    </h2>
    
    <p><?php esc_html_e( 'Select the Trendyol category you want to apply to all selected WooCommerce categories.', 'woo-trendyol' ); ?></p>
    
    <div class="wt-taxonomy-mapping-box" style="margin: 20px 0;">
        <!-- Direct ID entry -->
        <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
            <label for="wt-direct-category-id" style="font-weight: 600;"><?php esc_html_e( 'Enter Category ID directly:', 'woo-trendyol' ); ?></label>
            <input type="text" id="wt-direct-category-id" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 1234', 'woo-trendyol' ); ?>" style="width: 150px;">
            <button type="button" id="wt-direct-category-btn" class="button button-secondary">
                <?php esc_html_e( 'Load ID', 'woo-trendyol' ); ?>
            </button>
        </div>

        <!-- Cascading dropdowns are injected here by woo-trendyol-taxonomy.js -->
        <div id="trendyol-dropdowns-container"></div>

        <!-- Hidden fields that store the final resolved values -->
        <input type="hidden" name="trendyol_category_id" id="trendyol_category_id" value="" />
        <input type="hidden" name="trendyol_category_path" id="trendyol_category_path" value="" />

        <!-- Live path display -->
        <p class="wt-selected-path-wrap" style="margin-top: 10px;">
            <span id="trendyol-selected-path" class="wt-selected-path" style="font-weight: bold; color: #0071a1;"></span>
        </p>

        <p class="description" style="color: #d63638; font-style: italic; margin-top: 15px;">
            <?php esc_html_e( 'Note: Category attributes will NOT be automatically mapped. You must edit each category individually to map its required attributes if needed.', 'woo-trendyol' ); ?>
        </p>
    </div>

    <div style="text-align: right; border-top: 1px solid #ddd; padding-top: 15px;">
        <span id="wt-bulk-spinner" class="spinner" style="float: none; margin: 0 10px 0 0;"></span>
        <button type="button" id="wt-bulk-modal-cancel" class="button button-secondary" style="margin-right: 10px;">
            <?php esc_html_e( 'Cancel', 'woo-trendyol' ); ?>
        </button>
        <button type="button" id="wt-bulk-modal-apply" class="button button-primary">
            <?php esc_html_e( 'Apply Mapping', 'woo-trendyol' ); ?>
        </button>
    </div>
</div>
