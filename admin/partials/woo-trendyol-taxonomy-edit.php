<?php
/**
 * Taxonomy partial — Edit Category screen.
 *
 * Renders the Trendyol category mapping section on the
 * product_cat "Edit Category" form.
 *
 * Variables available from Woo_Trendyol_Taxonomy::edit_category_fields():
 *  @var WP_Term $term           Current taxonomy term.
 *  @var string  $trendyol_id    Existing Trendyol category ID (may be empty).
 *  @var string  $trendyol_path  Existing Trendyol category path (may be empty).
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

// Build a human-readable display path from the stored '|||'-delimited path.
$display_path = ! empty( $trendyol_path )
    ? implode( ' > ', array_map( 'trim', explode( '|||', $trendyol_path ) ) )
    : '';
?>
<tr class="form-field term-trendyol-cat-wrap">
    <th scope="row">
        <label><?php esc_html_e( 'Trendyol Category Mapping', 'woo-trendyol' ); ?></label>
    </th>
    <td>

        <?php wp_nonce_field( 'woo_trendyol_taxonomy_save', 'woo_trendyol_taxonomy_nonce' ); ?>

        <div class="wt-taxonomy-mapping-box">
            <p class="description">
                <?php esc_html_e( 'Select the corresponding Trendyol category. Each level is loaded automatically based on your selection.', 'woo-trendyol' ); ?>
            </p>

            <?php if ( ! empty( $display_path ) ) : ?>
                <p class="wt-current-mapping">
                    <strong><?php esc_html_e( 'Current mapping:', 'woo-trendyol' ); ?></strong>
                    <span class="wt-selected-path"><?php echo esc_html( $display_path ); ?></span>
                    <?php if ( ! empty( $trendyol_id ) ) : ?>
                        <code class="wt-code">(ID: <?php echo esc_html( $trendyol_id ); ?>)</code>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <!-- Cascading dropdowns are injected here by woo-trendyol-taxonomy.js -->
            <div id="trendyol-dropdowns-container"></div>

            <!-- Hidden fields that store the final resolved values.
                 Pre-populated with existing values so JS can restore the selection. -->
            <input type="hidden"
                   name="trendyol_category_id"
                   id="trendyol_category_id"
                   value="<?php echo esc_attr( $trendyol_id ); ?>" />
            <input type="hidden"
                   name="trendyol_category_path"
                   id="trendyol_category_path"
                   value="<?php echo esc_attr( $trendyol_path ); ?>" />

            <!-- Live path display (updated by JS as user selects) -->
            <p class="wt-selected-path-wrap">
                <span id="trendyol-selected-path" class="wt-selected-path">
                    <?php echo esc_html( $display_path ); ?>
                </span>
            </p>
        </div>

        <?php if ( ! empty( $required_attributes ) ) : ?>
            <div class="wt-taxonomy-attributes-box" style="margin-top: 20px;">
                <hr>
                <h4><?php esc_html_e( 'Required Trendyol Attributes', 'woo-trendyol' ); ?></h4>
                <p class="description">
                    <?php esc_html_e( 'Map the required Trendyol attributes to your WooCommerce product attributes. Global mappings (like Gender and Age) are applied automatically if set up in the main settings.', 'woo-trendyol' ); ?>
                </p>
                <table class="form-table">
                    <?php foreach ( $required_attributes as $attr ) : 
                        $attr_id = $attr['id'];
                        $attr_name = $attr['name'];
                        $current_mapping = $attribute_mappings[ $attr_id ] ?? '';
                    ?>
                        <tr>
                            <th scope="row">
                                <label for="trendyol_attr_<?php echo esc_attr( $attr_id ); ?>">
                                    <?php echo esc_html( $attr_name ); ?>
                                </label>
                            </th>
                            <td>
                                <select name="trendyol_attribute_mappings[<?php echo esc_attr( $attr_id ); ?>]" id="trendyol_attr_<?php echo esc_attr( $attr_id ); ?>">
                                    <option value=""><?php esc_html_e( '-- Select WooCommerce Attribute --', 'woo-trendyol' ); ?></option>
                                    <?php foreach ( $woo_attributes as $woo_attr ) : ?>
                                        <option value="<?php echo esc_attr( 'pa_' . $woo_attr->attribute_name ); ?>" <?php selected( $current_mapping, 'pa_' . $woo_attr->attribute_name ); ?>>
                                            <?php echo esc_html( $woo_attr->attribute_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

    </td>
</tr>
