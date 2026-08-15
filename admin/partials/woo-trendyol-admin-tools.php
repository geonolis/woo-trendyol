<?php
/**
 * Tools tab partial for Trendyol Settings
 *
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
?>

<div class="wt-tab-header">
    <h2>
        <span class="dashicons dashicons-admin-tools"></span>
        <?php esc_html_e( 'Tools: Export / Import Mappings', 'woo-trendyol' ); ?>
    </h2>
    <p class="wt-tab-desc">
        <?php esc_html_e( 'Export your category, attribute, and brand mappings to a JSON file. You can import this file into another WooCommerce installation to quickly set up Trendyol mappings. Mappings are matched by taxonomy term slug or name to remain portable across different sites.', 'woo-trendyol' ); ?>
    </p>
</div>

<!-- Export Card -->
<div class="wt-card">
    <h3><?php esc_html_e( 'Export Mappings', 'woo-trendyol' ); ?></h3>
    <p class="description">
        <?php esc_html_e( 'Download a single JSON file containing all your Trendyol mappings (Brands, Categories, and Attributes).', 'woo-trendyol' ); ?>
    </p>
    
    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=woo_trendyol_export_mappings' ), 'woo_trendyol_export' ) ); ?>" class="button button-primary">
        <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export to JSON', 'woo-trendyol' ); ?>
    </a>
</div>

<!-- Import Card -->
<div class="wt-card" style="margin-top: 20px;">
    <h3><?php esc_html_e( 'Import Mappings', 'woo-trendyol' ); ?></h3>
    <p class="description">
        <?php esc_html_e( 'Upload a previously exported JSON file to apply Trendyol mappings to this site. Existing WooCommerce categories and brands will be matched by slug (or name) and updated with the corresponding Trendyol IDs and attribute mappings.', 'woo-trendyol' ); ?>
    </p>
    
    <div style="margin-top:15px;">
        <input type="file" id="wt-import-file" accept=".json" />
        <button type="button" id="wt-import-btn" class="button button-secondary" style="margin-left:10px;">
            <span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Import Mappings', 'woo-trendyol' ); ?>
        </button>
        <span class="spinner" id="wt-import-spinner" style="float:none;vertical-align:middle;margin-left:5px;"></span>
    </div>
    
    <div id="wt-import-result" style="margin-top: 15px; display:none;"></div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#wt-import-btn').on('click', function() {
        var fileInput = $('#wt-import-file')[0];
        var file = fileInput.files[0];
        var resultDiv = $('#wt-import-result');
        var spinner = $('#wt-import-spinner');
        
        if (!file) {
            alert('<?php esc_html_e( 'Please select a JSON file to import.', 'woo-trendyol' ); ?>');
            return;
        }
        
        spinner.addClass('is-active');
        resultDiv.hide().removeClass('wt-notice-success wt-notice-error').empty();
        
        var formData = new FormData();
        formData.append('action', 'woo_trendyol_import_mappings');
        formData.append('nonce', wooTrendyolAdmin.nonce);
        formData.append('import_file', file);
        
        $.ajax({
            url: wooTrendyolAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                spinner.removeClass('is-active');
                if (response.success) {
                    resultDiv.addClass('wt-notice-success').html('<p>' + response.data.message + '</p>').show();
                    fileInput.value = ''; // clear
                } else {
                    resultDiv.addClass('wt-notice-error').html('<p><strong>Error:</strong> ' + response.data.message + '</p>').show();
                }
            },
            error: function() {
                spinner.removeClass('is-active');
                resultDiv.addClass('wt-notice-error').html('<p><strong><?php esc_html_e( 'Server Error', 'woo-trendyol' ); ?></strong></p>').show();
            }
        });
    });
});
</script>
<style>
.wt-notice-success { border-left: 4px solid #00a32a; background: #fff; padding: 1px 12px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
.wt-notice-error { border-left: 4px solid #d63638; background: #fff; padding: 1px 12px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
</style>
