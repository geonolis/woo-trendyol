/**
 * WooCommerce Trendyol Integration — Brand Admin JS
 */

/* global wtBrand, jQuery */

( function ( $ ) {
    'use strict';

    // State for brand sync batch processing.
    var brandSync = {
        running:   false,
        paused:    false,
        chunks:    [],
        index:     0,
        total:     0,
        matched:   0,
        unmatched: 0
    };

    $( function () {
        if ( typeof wtBrand === 'undefined' ) {
            return;
        }

        initBrandSync();
        initBrandSearch();
    } );

    function escHtml( str ) {
        if ( ! str ) { return ''; }
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

    // =========================================================================
    // Brand sync (async, pauseable, with matched/unmatched totals)
    // =========================================================================

    function initBrandSync() {
        var $btn          = $( '#wt-brand-sync' );
        var $pauseBtn     = $( '#wt-brand-sync-pause' );
        var $progressWrap = $( '#wt-brand-progress-wrap' );
        var $progressFill = $( '#wt-brand-progress-fill' );
        var $progressText = $( '#wt-brand-progress-text' );
        var $results      = $( '#wt-brand-results' );
        var $resultsList  = $( '#wt-brand-results-list' );
        var $totals       = $( '#wt-brand-totals' );

        if ( ! $btn.length ) { return; }

        // ---- Start / Resume ----
        $btn.on( 'click', function () {
            if ( brandSync.running && ! brandSync.paused ) { return; }

            if ( brandSync.paused ) {
                brandSync.paused  = false;
                brandSync.running = true;
                $btn.prop( 'disabled', true ).text( wtBrand.syncingBrands || 'Syncing…' );
                $pauseBtn.prop( 'disabled', false ).text( 'Pause' );
                syncNextBrandChunk();
                return;
            }

            // Fresh start — fetch all brand term IDs first.
            brandSync.running   = true;
            brandSync.paused    = false;
            brandSync.chunks    = [];
            brandSync.index     = 0;
            brandSync.total     = 0;
            brandSync.matched   = 0;
            brandSync.unmatched = 0;

            $btn.prop( 'disabled', true ).text( wtBrand.syncingBrands || 'Syncing…' );
            $pauseBtn.prop( 'disabled', false ).show().text( 'Pause' );
            $progressWrap.show();
            $progressFill.css( 'width', '0%' );
            $progressText.text( '0 / ?' );
            $totals.hide().empty();
            $results.hide();
            $resultsList.empty();

            $.post( wtBrand.ajaxUrl, {
                action: 'trendyol_sync_brands',
                nonce:  wtBrand.nonce,
                step:   'get_brands'
            } )
            .done( function ( response ) {
                if ( ! response.success || ! response.data.term_ids ) {
                    finishBrandWithError( 'Could not retrieve brand list.' );
                    return;
                }

                var allIds = response.data.term_ids;
                brandSync.total = allIds.length;

                if ( brandSync.total === 0 ) {
                    finishBrandWithError( wtBrand.noResults || 'No brands found.' );
                    return;
                }

                $progressText.text( '0 / ' + brandSync.total );

                for ( var i = 0; i < allIds.length; i += 20 ) {
                    brandSync.chunks.push( allIds.slice( i, i + 20 ) );
                }

                syncNextBrandChunk();
            } )
            .fail( function () {
                finishBrandWithError( 'Failed to retrieve brand list.' );
            } );
        } );

        // ---- Pause ----
        $pauseBtn.on( 'click', function () {
            if ( ! brandSync.running || brandSync.paused ) { return; }
            brandSync.paused = true;
            $( this ).prop( 'disabled', true ).text( 'Paused' );
            $btn.prop( 'disabled', false ).text( 'Resume' );
        } );

        // ---- Sync next chunk ----
        function syncNextBrandChunk() {
            if ( brandSync.paused || ! brandSync.running ) { return; }

            if ( brandSync.index >= brandSync.chunks.length ) {
                finishBrandSync();
                return;
            }

            var chunk = brandSync.chunks[ brandSync.index++ ];

            $.post( wtBrand.ajaxUrl, {
                action:   'trendyol_sync_brands',
                nonce:    wtBrand.nonce,
                step:     'sync_batch',
                term_ids: JSON.stringify( chunk )
            } )
            .done( function ( res ) {
                if ( res.success && res.data.results ) {
                    $.each( res.data.results, function ( i, item ) {
                        if ( item.status === 'matched' ) {
                            brandSync.matched++;
                            appendBrandRow( item.name, 'success',
                                'Matched: <strong>' + escHtml( item.trendyol_name ) + '</strong> (ID: ' + escHtml( String( item.trendyol_id ) ) + ')' );
                        } else {
                            brandSync.unmatched++;
                            appendBrandRow( item.name, 'error', 'No match found' );
                        }
                    } );
                } else {
                    brandSync.unmatched += chunk.length;
                    appendBrandRow( 'Batch', 'error', ( res.data && res.data.message ) || 'Unknown error' );
                }

                var done = brandSync.matched + brandSync.unmatched;
                var pct  = brandSync.total > 0 ? Math.round( ( done / brandSync.total ) * 100 ) : 0;
                $progressFill.css( 'width', pct + '%' );
                $progressText.text( done + ' / ' + brandSync.total );

                syncNextBrandChunk();
            } )
            .fail( function () {
                brandSync.unmatched += chunk.length;
                appendBrandRow( 'Batch', 'error', 'HTTP request failed.' );
                syncNextBrandChunk();
            } );
        }

        function appendBrandRow( name, status, message ) {
            var cls = status === 'error' ? 'wt-result--error' : 'wt-result--success';
            $resultsList.append(
                '<div class="wt-result-row ' + cls + '">' +
                '<strong>' + escHtml( name ) + '</strong> — ' + message +
                '</div>'
            );
            $results.show();
        }

        function finishBrandSync() {
            brandSync.running = false;
            brandSync.paused  = false;

            $btn.prop( 'disabled', false ).text( 'Sync Brands' );
            $pauseBtn.prop( 'disabled', true ).hide();
            $progressFill.css( 'width', '100%' );
            $progressText.text( brandSync.total + ' / ' + brandSync.total );

            $totals.html(
                '<div class="wt-totals-row">' +
                '<span class="wt-total-item wt-total--approved">&#x2714; Matched: <strong>' + brandSync.matched + '</strong></span>' +
                '<span class="wt-total-item wt-total--skipped">&#x2716; Unmatched: <strong>' + brandSync.unmatched + '</strong></span>' +
                '</div>'
            ).show();

            $resultsList.prepend(
                '<div class="wt-result-row wt-result--success">' +
                '<strong>' + (wtBrand.syncComplete || 'Brand sync complete.') + '</strong> ' +
                brandSync.matched + ' matched, ' + brandSync.unmatched + ' unmatched.' +
                '</div>'
            );
            $results.show();
        }

        function finishBrandWithError( msg ) {
            brandSync.running = false;
            brandSync.paused  = false;
            $btn.prop( 'disabled', false ).text( 'Sync Brands' );
            $pauseBtn.prop( 'disabled', true ).hide();
            $progressWrap.hide();
            appendBrandRow( 'Error', 'error', msg );
            $results.show();
        }
    }

    // =========================================================================
    // Brand search & remap (edit-brand page)
    // =========================================================================

    function initBrandSearch() {
        // Search button
        $( document ).on( 'click', '.wt-btn-brand-search', function () {
            var termId = $(this).data('term-id');
            var query = $( '#wt-brand-search-input-' + termId ).val().trim();
            if ( ! query ) { return; }
            runBrandSearch( termId, query );
        } );

        // Enter key in input
        $( document ).on( 'keypress', '.wt-brand-search-input', function ( e ) {
            if ( 13 === e.which ) {
                e.preventDefault();
                var termId = $(this).data('term-id');
                var query = $( this ).val().trim();
                if ( query ) { runBrandSearch( termId, query ); }
            }
        } );

        // Select result
        $( document ).on( 'click', '.wt-brand-result-select', function ( e ) {
            e.preventDefault();
            var $row   = $( this ).closest( 'tr' );
            var tyId   = $row.data( 'ty-id' );
            var tyName = $row.data( 'ty-name' );
            var termId = $( this ).data( 'term-id' );

            saveBrandMapping( termId, tyId, tyName );
        } );

        // Clear mapping button
        $( document ).on( 'click', '.wt-btn-clear-brand', function ( e ) {
            e.preventDefault();
            var termId = $( this ).data( 'term-id' );
            if ( confirm( wtBrand.confirmClear || 'Clear mapping?' ) ) {
                saveBrandMapping( termId, '', '' );
            }
        } );
    }

    function runBrandSearch( termId, query ) {
        var $listBody = $( '#wt-brand-results-body-' + termId );
        var $listWrap = $( '#wt-brand-results-' + termId );
        var $spinner  = $( '#wt-brand-search-input-' + termId ).siblings( '.wt-brand-search-spinner' );

        $listBody.empty();
        $listWrap.hide();
        $spinner.addClass( 'is-active' );

        $.post( wtBrand.ajaxUrl, {
            action: 'trendyol_search_brand',
            nonce:  wtBrand.nonce,
            name:   query
        } )
        .done( function ( response ) {
            if ( response.success ) {
                if ( response.data.brands && response.data.brands.length ) {
                    var html = '';
                    $.each( response.data.brands, function ( i, brand ) {
                        html += '<tr data-ty-id="' + escHtml( String( brand.id ) ) + '" data-ty-name="' + escHtml( brand.name ) + '">' +
                                '<td>' + escHtml( brand.name ) + '</td>' +
                                '<td>#' + escHtml( String( brand.id ) ) + '</td>' +
                                '<td><a href="#" class="wt-brand-result-select button button-small" data-term-id="' + termId + '">Select</a></td>' +
                                '</tr>';
                    } );
                    $listBody.html( html );
                    $listWrap.show();
                } else {
                    $listBody.html( '<tr><td colspan="3">' + (wtBrand.noResults || 'No matching Trendyol brands found.') + '</td></tr>' );
                    $listWrap.show();
                }
            } else {
                var msg = ( response.data && response.data.message ) ? response.data.message : 'Search request failed.';
                $listBody.html( '<tr><td colspan="3" class="wt-error">' + escHtml( msg ) + '</td></tr>' );
                $listWrap.show();
            }
        } )
        .fail( function () {
            $listBody.html( '<tr><td colspan="3" class="wt-error">Search request failed.</td></tr>' );
            $listWrap.show();
        } )
        .always( function () {
            $spinner.removeClass( 'is-active' );
        } );
    }

    function saveBrandMapping( termId, tyId, tyName ) {
        var $notice = $( '#wt-brand-notice-' + termId );
        $notice.html( 'Saving…' ).show().removeClass('wt-error').addClass('wt-success');

        $.post( wtBrand.ajaxUrl, {
            action:    'trendyol_save_brand_mapping',
            nonce:     wtBrand.nonce,
            term_id:   termId,
            ty_id:     tyId,
            ty_name:   tyName
        } )
        .done( function ( response ) {
            if ( response.success ) {
                $notice.html( tyId ? 'Saved: ' + tyName + ' (#' + tyId + ')' : 'Mapping cleared.' );
                
                // Update the current mapping display
                var $current = $( '#wt-brand-current-' + termId );
                if ( tyId ) {
                    $current.html(
                        '<span class="wt-brand-dot wt-brand-dot--matched">&#9679;</span> ' +
                        '<strong>' + escHtml( tyName ) + '</strong> ' +
                        '<span class="wt-brand-id-badge">(ID: ' + escHtml( tyId ) + ')</span> ' +
                        '<button type="button" class="button button-small wt-btn-clear-brand" data-term-id="' + termId + '" style="margin-left:8px;">Clear mapping</button>'
                    );
                } else {
                    $current.html(
                        '<span class="wt-brand-dot wt-brand-dot--unmatched">&#9679;</span> ' +
                        '<em>No Trendyol brand mapped yet.</em>'
                    );
                }
                
                // Hide search results
                $( '#wt-brand-results-' + termId ).hide();
                $( '#wt-brand-search-input-' + termId ).val('');
            } else {
                $notice.html( 'Error: ' + ( response.data.message || 'Save failed.' ) ).removeClass('wt-success').addClass('wt-error');
            }
        } )
        .fail( function () {
            $notice.html( 'Request failed.' ).removeClass('wt-success').addClass('wt-error');
        } );
    }

} )( jQuery );
