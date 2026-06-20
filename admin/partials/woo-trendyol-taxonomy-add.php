<?php
/**
 * Taxonomy partial — Add New Category screen.
 *
 * Renders the Trendyol category mapping section on the
 * product_cat "Add New Category" form.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
?>
<div class="form-field term-trendyol-cat-wrap">

    <label><?php esc_html_e( 'Trendyol Category Mapping', 'woo-trendyol' ); ?></label>

    <?php wp_nonce_field( 'woo_trendyol_taxonomy_save', 'woo_trendyol_taxonomy_nonce' ); ?>

    <div class="wt-taxonomy-mapping-box">
        <p class="description">
            <?php esc_html_e( 'Select the corresponding Trendyol category. Each level is loaded automatically based on your selection.', 'woo-trendyol' ); ?>
        </p>

        <!-- Cascading dropdowns are injected here by woo-trendyol-taxonomy.js -->
        <div id="trendyol-dropdowns-container"></div>

        <!-- Hidden fields that store the final resolved values -->
        <input type="hidden" name="trendyol_category_id"   id="trendyol_category_id"   value="" />
        <input type="hidden" name="trendyol_category_path" id="trendyol_category_path" value="" />

        <!-- Live path display -->
        <p class="wt-selected-path-wrap">
            <span id="trendyol-selected-path" class="wt-selected-path"></span>
        </p>
    </div>

</div>
