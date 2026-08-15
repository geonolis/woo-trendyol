/**
 * WooCommerce Trendyol Integration — Bulk Taxonomy Javascript
 *
 * Intercepts the "Map Trendyol Category" bulk action on the product_cat list table.
 *
 * @since   1.0.0
 * @package Woo_Trendyol
 */

( function ( $ ) {
    'use strict';

    $( function () {
        var $doActionBtn = $( '#doaction, #doaction2' );
        var $modalOverlay = $( '#wt-bulk-modal-overlay' );
        var $modal = $( '#wt-bulk-modal' );
        var $cancelBtn = $( '#wt-bulk-modal-cancel' );
        var $applyBtn = $( '#wt-bulk-modal-apply' );
        var $spinner = $( '#wt-bulk-spinner' );
        
        var selectedTermIds = [];

        // Intercept WP bulk action apply button
        $doActionBtn.on( 'click', function ( e ) {
            var isTop = $( this ).attr( 'id' ) === 'doaction';
            var action = isTop ? $( '#bulk-action-selector-top' ).val() : $( '#bulk-action-selector-bottom' ).val();

            if ( action === 'trendyol_map_category' ) {
                e.preventDefault();

                // Collect checked categories
                selectedTermIds = [];
                $( 'tbody .check-column input[type="checkbox"]:checked' ).each( function () {
                    selectedTermIds.push( $( this ).val() );
                } );

                if ( selectedTermIds.length === 0 ) {
                    alert( 'Please select at least one category.' );
                    return;
                }

                // Show modal
                $modalOverlay.show();
                $modal.show();
            }
        } );

        // Cancel modal
        $cancelBtn.on( 'click', function () {
            $modalOverlay.hide();
            $modal.hide();
        } );

        // Apply Mapping via AJAX
        $applyBtn.on( 'click', function () {
            var catId = $( '#trendyol_category_id' ).val();
            var catPath = $( '#trendyol_category_path' ).val();

            $applyBtn.prop( 'disabled', true );
            $cancelBtn.prop( 'disabled', true );
            $spinner.addClass( 'is-active' );

            $.post( wooTrendyolTaxonomy.ajaxUrl, {
                action: 'trendyol_bulk_map_categories',
                nonce: wooTrendyolTaxonomy.nonce,
                term_ids: selectedTermIds,
                trendyol_category_id: catId,
                trendyol_category_path: catPath
            } )
            .done( function ( response ) {
                if ( response.success ) {
                    // Reload the page to show the new mappings in the column
                    window.location.reload();
                } else {
                    alert( response.data && response.data.message ? response.data.message : 'An error occurred.' );
                    resetButtons();
                }
            } )
            .fail( function () {
                alert( 'Network request failed.' );
                resetButtons();
            } );
        } );

        function resetButtons() {
            $applyBtn.prop( 'disabled', false );
            $cancelBtn.prop( 'disabled', false );
            $spinner.removeClass( 'is-active' );
        }
    } );

} )( jQuery );
