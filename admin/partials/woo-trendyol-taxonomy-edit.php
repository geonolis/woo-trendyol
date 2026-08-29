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

            <!-- Direct ID entry -->
            <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                <label for="wt-direct-category-id" style="font-weight: 600;"><?php esc_html_e( 'Or enter Category ID directly:', 'woo-trendyol' ); ?></label>
                <input type="text" id="wt-direct-category-id" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 1234', 'woo-trendyol' ); ?>" style="width: 150px;">
                <button type="button" id="wt-direct-category-btn" class="button button-secondary">
                    <?php esc_html_e( 'Load ID', 'woo-trendyol' ); ?>
                </button>
            </div>

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
            <p class="wt-selected-path-wrap" style="margin-top: 10px; display: flex; align-items: center; gap: 10px;">
                <span id="trendyol-selected-path" class="wt-selected-path">
                    <?php echo esc_html( $display_path ); ?>
                </span>
                <button type="button" id="wt-resync-attrs-btn" class="button button-secondary" style="<?php echo empty( $trendyol_id ) ? 'display: none;' : ''; ?>">
                    <?php esc_html_e( 'Resync Attributes', 'woo-trendyol' ); ?>
                </button>
                <span id="wt-resync-attrs-spinner" class="spinner" style="float: none; margin: 0;"></span>
            </p>
        </div>

        <div id="wt-attributes-wrapper">
        <?php if ( ! empty( $required_attributes ) ) : ?>
            <div class="wt-taxonomy-attributes-box" style="margin-top: 20px;" data-required-attributes="<?php echo esc_attr( wp_json_encode( $required_attributes ) ); ?>" data-value-mappings="<?php echo esc_attr( wp_json_encode( $attribute_value_mappings ) ); ?>">
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

                        $slot = null;
                        $attr_name_lower = mb_strtolower( trim( $attr_name ) );
                        foreach ( Woo_Trendyol_Attribute_Mapper::GLOBAL_ATTR_KEYWORDS as $s => $keywords ) {
                            foreach ( $keywords as $keyword ) {
                                if ( mb_stripos( $attr_name_lower, $keyword ) !== false ) {
                                    $slot = $s;
                                    break 2;
                                }
                            }
                        }

                        $is_globally_mapped = false;
                        $global_wc_attr = '';
                        if ( $slot && in_array( $slot, [ 'gender', 'age', 'age_group', 'color' ], true ) ) {
                            $global_wc_attr = get_option( 'trendyol_global_attr_' . $slot . '_wc', '' );
                            if ( ! empty( $global_wc_attr ) ) {
                                $is_globally_mapped = true;
                            }
                        }
                                            $is_custom    = ! empty( $attr['allowCustom'] );
                        $values_count = count( $attr['values'] ?? [] );
                    ?>
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <th scope="row" style="vertical-align: top; padding: 15px 10px 15px 0; width: 230px;">
                                <label for="trendyol_attr_<?php echo esc_attr( $attr_id ); ?>" style="font-weight: 600; font-size: 13px; display: block;">
                                    <?php echo esc_html( $attr_name ); ?>
                                    <span style="font-size: 11px; font-weight: normal; color: #777; display: block; margin-top: 2px;">
                                        (Trendyol ID: <strong><?php echo esc_html( $attr_id ); ?></strong>)
                                    </span>
                                </label>

                                <?php if ( $is_custom ) : ?>
                                    <span class="wt-badge wt-badge-custom" style="display: inline-block; margin-top: 6px; padding: 3px 8px; font-size: 11px; line-height: 1.3; background: #e7f5ea; color: #1e7e34; border: 1px solid #c3e6cb; border-radius: 3px; font-weight: 600;">
                                        &#x2714; <?php esc_html_e( 'Free Text / Slicer', 'woo-trendyol' ); ?>
                                    </span>
                                    <span style="display: block; font-size: 11px; color: #666; font-weight: normal; margin-top: 3px; line-height: 1.3;">
                                        <?php esc_html_e( 'Accepts custom variation values (e.g. Σχέδιο)', 'woo-trendyol' ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="wt-badge wt-badge-predefined" style="display: inline-block; margin-top: 6px; padding: 3px 8px; font-size: 11px; line-height: 1.3; background: #f0f0f1; color: #3c434a; border: 1px solid #d6d8db; border-radius: 3px; font-weight: 600;">
                                        &#x25C6; <?php esc_html_e( 'Predefined List', 'woo-trendyol' ); ?>
                                    </span>
                                    <span style="display: block; font-size: 11px; color: #666; font-weight: normal; margin-top: 3px; line-height: 1.3;">
                                        <?php printf( esc_html__( 'Fixed Trendyol list (%d options)', 'woo-trendyol' ), $values_count ); ?>
                                    </span>
                                <?php endif; ?>
                            </th>
                            <td>
                                <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 8px;">
                                    <select name="trendyol_attribute_mappings[<?php echo esc_attr( $attr_id ); ?>]" id="trendyol_attr_<?php echo esc_attr( $attr_id ); ?>" style="width: 100%; max-width: 400px; min-width: 250px;">
                                        <option value=""><?php esc_html_e( '-- Select WooCommerce Attribute --', 'woo-trendyol' ); ?></option>
                                        
                                        <?php if ( ! empty( $woo_attributes ) ) : ?>
                                            <optgroup label="<?php esc_attr_e( 'Global Product Attributes (pa_*)', 'woo-trendyol' ); ?>">
                                                <?php foreach ( $woo_attributes as $woo_attr ) : ?>
                                                    <option value="<?php echo esc_attr( 'pa_' . $woo_attr->attribute_name ); ?>" <?php selected( $current_mapping, 'pa_' . $woo_attr->attribute_name ); ?>>
                                                        <?php echo esc_html( $woo_attr->attribute_label ); ?> (pa_<?php echo esc_attr( $woo_attr->attribute_name ); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>

                                        <?php if ( ! empty( $custom_attributes ) ) : ?>
                                            <optgroup label="<?php esc_attr_e( 'Custom Product Attributes', 'woo-trendyol' ); ?>">
                                                <?php foreach ( $custom_attributes as $c_slug => $c_label ) : ?>
                                                    <option value="<?php echo esc_attr( $c_slug ); ?>" <?php selected( $current_mapping, $c_slug ); ?>>
                                                        <?php echo esc_html( $c_label ); ?> (<?php echo esc_html( $c_slug ); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>

                                        <?php 
                                        $known_keys = array_merge(
                                            array_map( static fn( $a ) => 'pa_' . $a->attribute_name, $woo_attributes ?? [] ),
                                            array_keys( $custom_attributes ?? [] )
                                        );
                                        if ( ! empty( $current_mapping ) && ! in_array( $current_mapping, $known_keys, true ) ) : 
                                        ?>
                                            <optgroup label="<?php esc_attr_e( 'Custom Source', 'woo-trendyol' ); ?>">
                                                <option value="<?php echo esc_attr( $current_mapping ); ?>" selected="selected">
                                                    <?php echo esc_html( $current_mapping ); ?>
                                                </option>
                                            </optgroup>
                                        <?php endif; ?>
                                    </select>

                                    <?php if ( $is_globally_mapped ) : ?>
                                        <div class="wt-global-mapping-notice" style="font-size: 11px; color: #46b450; padding: 4px 8px; background: #ecf7ed; border-left: 4px solid #46b450; display: block; width: 100%; max-width: 400px; box-sizing: border-box;">
                                            <?php printf( 
                                                esc_html__( 'Mapped globally to "%s". Select an attribute here only to override global mapping.', 'woo-trendyol' ),
                                                esc_html( $global_wc_attr )
                                            ); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php 
                                if ( ! empty( $current_mapping ) && ! empty( $attr['allowCustom'] ) ) :
                                ?>
                                    <div class="wt-custom-values-notice" style="margin-top: 10px; padding: 8px 12px; background: #f0f6fb; border-left: 4px solid #11a0d2; font-size: 11px; color: #50575e;">
                                        <?php esc_html_e( 'This attribute allows custom values. Individual value mapping is not required; values from your selected WooCommerce attribute will be sent directly to Trendyol.', 'woo-trendyol' ); ?>
                                    </div>
                                <?php 
                                elseif ( ! empty( $current_mapping ) && taxonomy_exists( $current_mapping ) && ! empty( $attr['values'] ) ) :
                                    $woo_terms = get_terms( [ 'taxonomy' => $current_mapping, 'hide_empty' => false ] );
                                    if ( ! is_wp_error( $woo_terms ) && ! empty( $woo_terms ) ) :
                                ?>
                                    <div class="wt-value-mappings" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; max-height: 250px; overflow-y: auto;">
                                        <strong><?php esc_html_e( 'Map Values:', 'woo-trendyol' ); ?></strong>
                                        <table style="width: 100%; border-collapse: collapse; margin-top: 5px;">
                                            <?php foreach ( $woo_terms as $woo_term ) : 
                                                $saved_ty_val = $attribute_value_mappings[ $attr_id ][ $woo_term->slug ] ?? '';
                                                
                                                // Automap by exact/case-insensitive name matching if no saved mapping exists
                                                if ( empty( $saved_ty_val ) ) {
                                                    $term_name_lower = mb_strtolower( trim( $woo_term->name ) );
                                                    foreach ( $attr['values'] as $ty_val ) {
                                                        if ( mb_strtolower( trim( $ty_val['name'] ) ) === $term_name_lower ) {
                                                            $saved_ty_val = $ty_val['id'];
                                                            break;
                                                        }
                                                    }
                                                }
                                            ?>
                                                <tr style="border-bottom: 1px solid #eee;">
                                                    <td style="padding: 5px 0; font-size: 12px;"><?php echo esc_html( $woo_term->name ); ?></td>
                                                    <td style="padding: 5px 0; text-align: right;">
                                                        <select name="trendyol_attribute_value_mappings[<?php echo esc_attr( $attr_id ); ?>][<?php echo esc_attr( $woo_term->slug ); ?>]" style="font-size: 12px; min-width: 250px; max-width: 100%;">
                                                            <option value=""><?php esc_html_e( '-- Select Trendyol Value --', 'woo-trendyol' ); ?></option>
                                                            <?php foreach ( $attr['values'] as $ty_val ) : ?>
                                                                <option value="<?php echo esc_attr( $ty_val['id'] ); ?>" <?php selected( $saved_ty_val, $ty_val['id'] ); ?>>
                                                                    <?php echo esc_html( $ty_val['name'] ); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </div>
                                <?php 
                                    endif;
                                endif; 
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php else : ?>
            <div class="wt-taxonomy-attributes-box" style="margin-top: 20px;">
                <hr>
                <h4><?php esc_html_e( 'Required Trendyol Attributes', 'woo-trendyol' ); ?></h4>
                <p class="description" style="color: #666; font-style: italic;">
                    <?php esc_html_e( 'No required attributes are defined by Trendyol for this category.', 'woo-trendyol' ); ?>
                </p>
            </div>
        <?php endif; ?>
        </div>

    </td>
</tr>
<tr class="form-field term-trendyol-extra-percentage-wrap">
    <th scope="row">
        <label for="trendyol_category_extra_percentage"><?php esc_html_e( 'Trendyol Extra Percentage', 'woo-trendyol' ); ?></label>
    </th>
    <td>
        <input type="number"
               step="0.01"
               name="trendyol_category_extra_percentage"
               id="trendyol_category_extra_percentage"
               value="<?php echo esc_attr( $trendyol_category_extra_percentage ); ?>"
               style="width:100px;" /> %
        <p class="description">
            <?php esc_html_e( 'Specify an extra percentage to be added to the price of products that belong to this category when sending to Trendyol.', 'woo-trendyol' ); ?>
        </p>
    </td>
</tr>
<tr class="form-field term-trendyol-exclude-bulk-push-wrap">
    <th scope="row">
        <label for="trendyol_exclude_bulk_push"><?php esc_html_e( 'Exclude from Bulk Push', 'woo-trendyol' ); ?></label>
    </th>
    <td>
        <input type="checkbox"
               name="trendyol_exclude_bulk_push"
               id="trendyol_exclude_bulk_push"
               value="yes"
               <?php checked( $exclude_bulk_push, 'yes' ); ?> />
        <span class="description"><?php esc_html_e( 'Do not include products in this category when using the Bulk Push to Trendyol action. Price and stock sync will still work if the product was manually pushed.', 'woo-trendyol' ); ?></span>
    </td>
</tr>
