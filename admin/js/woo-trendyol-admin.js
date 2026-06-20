/**
 * WooCommerce Trendyol Integration — Admin JavaScript
 *
 * Handles:
 *  - API connection test button
 *  - Password show/hide toggle
 *  - Handling time radio toggle
 *  - Barcode source radio toggle
 *  - Cargo company fetch button
 *  - Bulk push to Trendyol (async batches, pause/resume, progress bar, totals)
 *  - Brand sync (async batches, progress bar, results with matched/unmatched)
 *  - Brand search & remap on edit-brand page
 *  - Product status refresh button (product edit page)
 *  - Send single product to Trendyol button (product edit page)
 *  - Global attribute value mapping (gender / age)
 *
 * Depends on: jQuery, wooTrendyolAdmin (localised via wp_localize_script)
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 */

/* global wooTrendyolAdmin, jQuery */

( function ( $ ) {
    'use strict';

    // =========================================================================
    // Constants & shared state
    // =========================================================================

    var BATCH_SIZE = 50;

    var bulkPush = {
        running: false,
        paused:  false,
        chunks:  [],
        index:   0,
        total:   0,
        pushed:  0,
        skipped: 0,
        approved: 0,
        batches: []
    };

    var brandSync = {
        running: false,
        paused:  false,
        chunks:  [],
        index:   0,
        total:   0,
        matched: 0,
        unmatched: 0
    };

    // =========================================================================
    // DOM ready
    // =========================================================================

    $( function () {
        initConnectionTest();
        initStatusRefresh();
        initSecretReveal();
        initHandlingTimeToggle();
        initBarcodeSourceToggle();
        initCargoCompanyFetch();
        initBulkPush();
        initManualBatchPoll();
        initBrandSync();
        initBrandSearch();
        initAttrMapping();
        initSendToTrendyol();
    } );

    // =========================================================================
    // Utility helpers
    // =========================================================================

    function showResult( $container, message, type ) {
        var cls = 'wt-notice wt-notice--' + ( type || 'info' );
        $container.attr( 'class', cls ).html( message ).show();
    }

    function escHtml( str ) {
        return String( str )
            .replace( /&/g,  '&amp;' )
            .replace( /</g,  '&lt;' )
            .replace( />/g,  '&gt;' )
            .replace( /"/g,  '&quot;' )
            .replace( /'/g,  '&#039;' );
    }

    // =========================================================================
    // Password reveal toggle
    // =========================================================================

    function initSecretReveal() {
        $( document ).on( 'click', '.wt-reveal-secret', function () {
            var $btn   = $( this );
            var $input = $( '#' + $btn.data( 'target' ) );
            if ( ! $input.length ) { return; }
            if ( 'password' === $input.attr( 'type' ) ) {
                $input.attr( 'type', 'text' );
                $btn.text( 'Hide' );
            } else {
                $input.attr( 'type', 'password' );
                $btn.text( 'Show' );
            }
        } );
    }

    // =========================================================================
    // Handling time radio toggle
    // =========================================================================

    function initHandlingTimeToggle() {
        $( document ).on( 'change', '.wt-handling-type', function () {
            var val = $( this ).val();
            $( '#trendyol_handling_time_days' ).prop( 'disabled', val !== 'fixed' );
            $( '#trendyol_handling_time_wc_attr' ).prop( 'disabled', val !== 'attribute' );
        } );
    }

    // =========================================================================
    // Barcode source radio toggle
    // =========================================================================

    function initBarcodeSourceToggle() {
        var $radios = $( '.wt-barcode-radio' );
        if ( ! $radios.length ) { return; }

        function showSubField( val ) {
            $( '.wt-barcode-sub-field' ).hide();
            if ( val === 'meta' )      { $( '#wt-barcode-meta-row' ).show(); }
            if ( val === 'attribute' ) { $( '#wt-barcode-attr-row' ).show(); }
        }

        $radios.on( 'change', function () { showSubField( $( this ).val() ); } );
        showSubField( $radios.filter( ':checked' ).val() );
    }

    // =========================================================================
    // API connection test
    // =========================================================================

    function initConnectionTest() {
        var $btn    = $( '#wt-test-connection' );
        var $result = $( '#wt-test-result' );
        if ( ! $btn.length ) { return; }

        $btn.on( 'click', function () {
            var $self = $( this );
            $self.prop( 'disabled', true ).text( wooTrendyolAdmin.testingText );
            $result.hide();

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action: 'trendyol_test_connection',
                nonce:  wooTrendyolAdmin.nonce
            } )
            .done( function ( response ) {
                if ( response.success ) {
                    showResult( $result, response.data.message, 'success' );
                } else {
                    showResult( $result, wooTrendyolAdmin.connectionFail + response.data.message, 'error' );
                }
            } )
            .fail( function () {
                showResult( $result, wooTrendyolAdmin.connectionFail + 'Request failed.', 'error' );
            } )
            .always( function () {
                $self.prop( 'disabled', false ).text( wooTrendyolAdmin.testConnectionText );
            } );
        } );
    }

    // =========================================================================
    // Cargo company fetch
    // =========================================================================

    function initCargoCompanyFetch() {
        $( document ).on( 'click', '.wt-fetch-cargo-companies', function () {
            var $btn  = $( this );
            var $list = $( '#wt-cargo-list' );

            $btn.prop( 'disabled', true ).text( 'Fetching…' );
            $list.hide().empty();

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action: 'trendyol_fetch_cargo_companies',
                nonce:  wooTrendyolAdmin.nonce
            } )
            .done( function ( response ) {
                if ( response.success && response.data.companies ) {
                    var html = '<ul class="wt-cargo-list">';
                    $.each( response.data.companies, function ( i, c ) {
                        html += '<li><strong>' + c.id + '</strong> — ' + escHtml( c.name ) +
                                ' <a href="#" class="wt-select-cargo" data-id="' + c.id + '">[Use]</a></li>';
                    } );
                    html += '</ul>';
                    $list.html( html ).show();
                } else {
                    $list.html( '<span class="wt-error">Could not fetch companies.</span>' ).show();
                }
            } )
            .fail( function () {
                $list.html( '<span class="wt-error">Request failed.</span>' ).show();
            } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( 'Fetch Available Companies' );
            } );
        } );

        $( document ).on( 'click', '.wt-select-cargo', function ( e ) {
            e.preventDefault();
            $( '#trendyol_default_cargo_company_id' ).val( $( this ).data( 'id' ) );
        } );
    }

    // =========================================================================
    // Bulk push to Trendyol (async, pauseable, with totals)
    // =========================================================================

    function initBulkPush() {
        var $btn          = $( '#wt-bulk-push' );
        var $pauseBtn     = $( '#wt-bulk-pause' );
        var $progressWrap = $( '#wt-bulk-progress-wrap' );
        var $progressFill = $( '#wt-bulk-progress-fill' );
        var $progressText = $( '#wt-bulk-progress-text' );
        var $results      = $( '#wt-bulk-results' );
        var $resultsList  = $( '#wt-bulk-results-list' );
        var $totals       = $( '#wt-bulk-totals' );

        if ( ! $btn.length ) { return; }

        // ---- Start / Resume button ----
        $btn.on( 'click', function () {
            if ( bulkPush.running && ! bulkPush.paused ) { return; }

            if ( bulkPush.paused ) {
                // Resume
                bulkPush.paused  = false;
                bulkPush.running = true;
                $btn.prop( 'disabled', true ).text( wooTrendyolAdmin.bulkPushingText );
                $pauseBtn.prop( 'disabled', false ).text( 'Pause' );
                pushNextChunk();
                return;
            }

            // Fresh start
            var onlyUnmapped = $( '#wt-bulk-only-unmapped' ).is( ':checked' );

            bulkPush.running  = true;
            bulkPush.paused   = false;
            bulkPush.chunks   = [];
            bulkPush.index    = 0;
            bulkPush.total    = 0;
            bulkPush.pushed   = 0;
            bulkPush.skipped  = 0;
            bulkPush.approved = 0;
            bulkPush.batches  = [];

            $btn.prop( 'disabled', true ).text( wooTrendyolAdmin.bulkPushingText );
            $pauseBtn.prop( 'disabled', false ).show().text( 'Pause' );
            $progressWrap.show();
            $progressFill.css( 'width', '0%' );
            $progressText.text( '0 / ?' );
            $totals.hide().empty();
            $results.hide();
            $resultsList.empty();

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action:        'trendyol_get_pushable_products',
                nonce:         wooTrendyolAdmin.nonce,
                only_unmapped: onlyUnmapped ? 1 : 0
            } )
            .done( function ( response ) {
                if ( ! response.success || ! response.data.product_ids ) {
                    finishBulkWithError( 'Could not retrieve product list.' );
                    return;
                }

                var allIds = response.data.product_ids;
                bulkPush.total = allIds.length;

                if ( bulkPush.total === 0 ) {
                    finishBulkWithError( 'No eligible products found.' );
                    return;
                }

                $progressText.text( '0 / ' + bulkPush.total );

                for ( var i = 0; i < allIds.length; i += BATCH_SIZE ) {
                    bulkPush.chunks.push( allIds.slice( i, i + BATCH_SIZE ) );
                }

                pushNextChunk();
            } )
            .fail( function () {
                finishBulkWithError( 'Failed to retrieve product list.' );
            } );
        } );

        // ---- Pause button ----
        $pauseBtn.on( 'click', function () {
            if ( ! bulkPush.running ) { return; }

            if ( bulkPush.paused ) {
                // Resume via main button
                return;
            }

            bulkPush.paused = true;
            $( this ).prop( 'disabled', true ).text( 'Paused' );
            $btn.prop( 'disabled', false ).text( 'Resume' );
        } );

        // ---- Push next chunk ----
        function pushNextChunk() {
            if ( bulkPush.paused || ! bulkPush.running ) { return; }

            if ( bulkPush.index >= bulkPush.chunks.length ) {
                finishBulkPush();
                return;
            }

            var chunk = bulkPush.chunks[ bulkPush.index++ ];

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action:      'trendyol_bulk_push_batch',
                nonce:       wooTrendyolAdmin.nonce,
                product_ids: JSON.stringify( chunk )
            } )
            .done( function ( res ) {
                if ( res.success ) {
                    bulkPush.pushed   += res.data.submitted || 0;
                    bulkPush.skipped  += res.data.skipped   || 0;
                    bulkPush.approved += res.data.approved  || 0;
                    if ( res.data.batches ) {
                        bulkPush.batches = bulkPush.batches.concat( res.data.batches );
                    }
                    if ( res.data.errors ) {
                        $.each( res.data.errors, function ( pid, msg ) {
                            appendBulkRow( pid, 'error', msg );
                        } );
                    }
                } else {
                    bulkPush.skipped += chunk.length;
                    appendBulkRow( 'Batch', 'error', res.data.message || 'Unknown error' );
                }

                var done = bulkPush.pushed + bulkPush.skipped;
                var pct  = bulkPush.total > 0 ? Math.round( ( done / bulkPush.total ) * 100 ) : 0;
                $progressFill.css( 'width', pct + '%' );
                $progressText.text( done + ' / ' + bulkPush.total );

                pushNextChunk();
            } )
            .fail( function () {
                bulkPush.skipped += chunk.length;
                appendBulkRow( 'Batch', 'error', 'HTTP request failed.' );
                pushNextChunk();
            } );
        }

        function appendBulkRow( id, status, message ) {
            var cls = status === 'error' ? 'wt-result--error' : 'wt-result--success';
            $resultsList.append(
                '<div class="wt-result-row ' + cls + '">' +
                '<strong>#' + escHtml( String( id ) ) + '</strong> — ' + escHtml( message ) +
                '</div>'
            );
            $results.show();
        }

        function finishBulkPush() {
            bulkPush.running = false;
            bulkPush.paused  = false;

            $btn.prop( 'disabled', false ).text( wooTrendyolAdmin.bulkPushText );
            $pauseBtn.prop( 'disabled', true ).hide();
            $progressFill.css( 'width', '100%' );
            $progressText.text( bulkPush.total + ' / ' + bulkPush.total );

            // Totals summary.
            $totals.html(
                '<div class="wt-totals-row">' +
                '<span class="wt-total-item wt-total--submitted">&#x2191; Submitted: <strong>' + bulkPush.pushed + '</strong></span>' +
                '<span class="wt-total-item wt-total--skipped">&#x26A0; Skipped: <strong>' + bulkPush.skipped + '</strong></span>' +
                '<span class="wt-total-item wt-total--approved">&#x2714; Approved: <strong>' + bulkPush.approved + '</strong></span>' +
                '</div>'
            ).show();

            var summary = '<div class="wt-result-row wt-result--success">' +
                '<strong>' + wooTrendyolAdmin.bulkPushDone + '</strong> ' +
                bulkPush.pushed + ' submitted, ' + bulkPush.skipped + ' skipped, ' + bulkPush.approved + ' approved.' +
                '</div>';

            if ( bulkPush.batches.length ) {
                summary += '<div class="wt-result-row wt-result--info">' +
                    'Batch IDs: ' + escHtml( bulkPush.batches.join( ', ' ) ) +
                    ' &mdash; <a href="#" class="wt-poll-batches" data-batches="' +
                    escHtml( bulkPush.batches.join( ',' ) ) + '">Poll Status</a>' +
                    '</div>';
            }

            $resultsList.prepend( summary );
            $results.show();

            if ( bulkPush.batches.length ) {
                setTimeout( function () {
                    pollBatchStatuses( bulkPush.batches, $resultsList, $results );
                }, 30000 );
            }
        }

        function finishBulkWithError( msg ) {
            bulkPush.running = false;
            bulkPush.paused  = false;
            $btn.prop( 'disabled', false ).text( wooTrendyolAdmin.bulkPushText );
            $pauseBtn.prop( 'disabled', true ).hide();
            $progressWrap.hide();
            appendBulkRow( 'Error', 'error', msg );
            $results.show();
        }
    }

    // =========================================================================
    // Manual batch status poll
    // =========================================================================

    function initManualBatchPoll() {
        $( document ).on( 'click', '.wt-poll-batches', function ( e ) {
            e.preventDefault();
            var batches      = $( this ).data( 'batches' ).toString().split( ',' );
            var $resultsList = $( '#wt-bulk-results-list' );
            var $results     = $( '#wt-bulk-results' );
            pollBatchStatuses( batches, $resultsList, $results );
        } );
    }

    function pollBatchStatuses( batchIds, $list, $wrap ) {
        $.each( batchIds, function ( i, batchId ) {
            if ( ! batchId ) { return; }

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action:   'trendyol_poll_batch_status',
                nonce:    wooTrendyolAdmin.nonce,
                batch_id: batchId
            } )
            .done( function ( response ) {
                var msg;
                if ( response.success ) {
                    var data   = response.data;
                    var status = data.status || 'UNKNOWN';
                    var items  = data.items  || [];
                    var failed = $.grep( items, function ( item ) {
                        return item.status === 'ERROR';
                    } );

                    msg = 'Batch <strong>' + escHtml( batchId ) + '</strong>: ' + escHtml( status ) +
                          ' (' + items.length + ' items, ' + failed.length + ' errors)';

                    if ( failed.length ) {
                        msg += '<ul>';
                        $.each( failed, function ( j, item ) {
                            var reasons = ( item.failureReasons || [] ).join( ', ' );
                            msg += '<li>' + escHtml( item.barcode || '' ) + ': ' + escHtml( reasons ) + '</li>';
                        } );
                        msg += '</ul>';
                    }
                } else {
                    msg = 'Batch <strong>' + escHtml( batchId ) + '</strong>: ' +
                          escHtml( ( response.data && response.data.message ) || 'Error' );
                }

                $list.append( '<div class="wt-result-row wt-result--info">' + msg + '</div>' );
                $wrap.show();
            } );
        } );
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
                $btn.prop( 'disabled', true ).text( 'Syncing…' );
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

            $btn.prop( 'disabled', true ).text( 'Syncing…' );
            $pauseBtn.prop( 'disabled', false ).show().text( 'Pause' );
            $progressWrap.show();
            $progressFill.css( 'width', '0%' );
            $progressText.text( '0 / ?' );
            $totals.hide().empty();
            $results.hide();
            $resultsList.empty();

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action: 'trendyol_sync_brands',
                nonce:  wooTrendyolAdmin.nonce,
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
                    finishBrandWithError( 'No brands found. Make sure WooCommerce Brands is active.' );
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

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action:   'trendyol_sync_brands',
                nonce:    wooTrendyolAdmin.nonce,
                step:     'sync_batch',
                term_ids: JSON.stringify( chunk )
            } )
            .done( function ( res ) {
                if ( res.success && res.data.results ) {
                    $.each( res.data.results, function ( i, item ) {
                        if ( item.matched ) {
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
                '<strong>Brand sync complete.</strong> ' +
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
    // Brand search & remap (edit-brand page and brand list column)
    // =========================================================================

    function initBrandSearch() {
        // Search button on edit-brand page.
        $( document ).on( 'click', '#wt-brand-search-btn', function () {
            var query = $( '#wt-brand-search-input' ).val().trim();
            if ( ! query ) { return; }
            runBrandSearch( query );
        } );

        // Allow Enter key in the search input.
        $( document ).on( 'keypress', '#wt-brand-search-input', function ( e ) {
            if ( 13 === e.which ) {
                e.preventDefault();
                var query = $( this ).val().trim();
                if ( query ) { runBrandSearch( query ); }
            }
        } );

        // Select a result from the search list.
        $( document ).on( 'click', '.wt-brand-result-select', function ( e ) {
            e.preventDefault();
            var $row   = $( this ).closest( '.wt-brand-result-row' );
            var tyId   = $row.data( 'ty-id' );
            var tyName = $row.data( 'ty-name' );
            var termId = $( '#wt-brand-term-id' ).val();

            saveBrandMapping( termId, tyId, tyName );
        } );

        // Clear mapping button.
        $( document ).on( 'click', '#wt-brand-clear-mapping', function ( e ) {
            e.preventDefault();
            var termId = $( '#wt-brand-term-id' ).val();
            saveBrandMapping( termId, '', '' );
        } );
    }

    function runBrandSearch( query ) {
        var $list    = $( '#wt-brand-search-results' );
        var $spinner = $( '#wt-brand-search-spinner' );

        $list.empty().hide();
        $spinner.addClass( 'is-active' );

        $.post( wooTrendyolAdmin.ajaxUrl, {
            action: 'trendyol_search_brand',
            nonce:  wooTrendyolAdmin.nonce,
            query:  query
        } )
        .done( function ( response ) {
            if ( response.success && response.data.brands && response.data.brands.length ) {
                var html = '';
                $.each( response.data.brands, function ( i, brand ) {
                    html += '<div class="wt-brand-result-row" data-ty-id="' + escHtml( String( brand.id ) ) + '" data-ty-name="' + escHtml( brand.name ) + '">' +
                            '<span class="wt-brand-result-name">' + escHtml( brand.name ) + '</span>' +
                            ' <small class="wt-brand-result-id">#' + escHtml( String( brand.id ) ) + '</small>' +
                            ' <a href="#" class="wt-brand-result-select button button-small">Use</a>' +
                            '</div>';
                } );
                $list.html( html ).show();
            } else {
                $list.html( '<p class="description">No matching brands found.</p>' ).show();
            }
        } )
        .fail( function () {
            $list.html( '<p class="description wt-error">Search request failed.</p>' ).show();
        } )
        .always( function () {
            $spinner.removeClass( 'is-active' );
        } );
    }

    function saveBrandMapping( termId, tyId, tyName ) {
        var $status = $( '#wt-brand-mapping-status' );
        $status.text( 'Saving…' ).show();

        $.post( wooTrendyolAdmin.ajaxUrl, {
            action:    'trendyol_save_brand_mapping',
            nonce:     wooTrendyolAdmin.nonce,
            term_id:   termId,
            ty_id:     tyId,
            ty_name:   tyName
        } )
        .done( function ( response ) {
            if ( response.success ) {
                $status.text( tyId ? 'Saved: ' + tyName + ' (#' + tyId + ')' : 'Mapping cleared.' );
                // Update the current mapping display.
                if ( tyId ) {
                    $( '#wt-brand-current-id' ).text( tyId );
                    $( '#wt-brand-current-name' ).text( tyName );
                    $( '#wt-brand-status-badge' )
                        .removeClass( 'wt-badge--unmapped' )
                        .addClass( 'wt-badge--mapped' )
                        .text( 'Mapped' );
                } else {
                    $( '#wt-brand-current-id' ).text( '—' );
                    $( '#wt-brand-current-name' ).text( '—' );
                    $( '#wt-brand-status-badge' )
                        .removeClass( 'wt-badge--mapped' )
                        .addClass( 'wt-badge--unmapped' )
                        .text( 'Not mapped' );
                }
            } else {
                $status.text( 'Error: ' + ( response.data.message || 'Save failed.' ) );
            }
        } )
        .fail( function () {
            $status.text( 'Request failed.' );
        } );
    }

    // =========================================================================
    // Product status refresh (product edit page)
    // =========================================================================

    function initStatusRefresh() {
        var $btn    = $( '#wt-refresh-status' );
        var $result = $( '#wt-refresh-result' );
        if ( ! $btn.length ) { return; }

        $btn.on( 'click', function () {
            var $self  = $( this );
            var postId = $self.data( 'post-id' );

            $self.prop( 'disabled', true ).text( wooTrendyolAdmin.refreshingText );
            $result.hide();

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action:  'trendyol_refresh_status',
                nonce:   wooTrendyolAdmin.nonce,
                post_id: postId
            } )
            .done( function ( response ) {
                if ( response.success ) {
                    showResult( $result, response.data.message, 'success' );
                    setTimeout( function () { window.location.reload(); }, 1200 );
                } else {
                    showResult( $result, response.data.message, 'error' );
                    $self.prop( 'disabled', false ).text( wooTrendyolAdmin.refreshText );
                }
            } )
            .fail( function () {
                showResult( $result, 'Request failed.', 'error' );
                $self.prop( 'disabled', false ).text( wooTrendyolAdmin.refreshText );
            } );
        } );
    }

    // =========================================================================
    // Send single product to Trendyol (product edit page)
    // =========================================================================

    function initSendToTrendyol() {
        var $btn     = $( '#wt-send-to-trendyol' );
        var $spinner = $( '#wt-send-spinner' );
        var $result  = $( '#wt-send-result' );
        if ( ! $btn.length ) { return; }

        $btn.on( 'click', function () {
            var $self  = $( this );
            var postId = $self.data( 'post-id' );

            $self.prop( 'disabled', true ).text( wooTrendyolAdmin.sendingText );
            $spinner.addClass( 'is-active' );
            $result.hide().removeClass( 'wt-notice--success wt-notice--error wt-notice--pending' );

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action:  'trendyol_push_single_product',
                nonce:   wooTrendyolAdmin.nonce,
                post_id: postId
            } )
            .done( function ( response ) {
                $spinner.removeClass( 'is-active' );

                if ( response.success ) {
                    var type = response.data.type || 'success';
                    $result
                        .attr( 'class', 'wt-notice wt-notice--' + type )
                        .html( '<strong>' + escHtml( response.data.message ) + '</strong>' )
                        .show();

                    if ( response.data.reload ) {
                        setTimeout( function () { window.location.reload(); }, 2000 );
                    } else {
                        $self.prop( 'disabled', false ).text( wooTrendyolAdmin.sendText );
                    }
                } else {
                    $result
                        .attr( 'class', 'wt-notice wt-notice--error' )
                        .html( '<strong>' + escHtml( response.data.message ) + '</strong>' )
                        .show();
                    $self.prop( 'disabled', false ).text( wooTrendyolAdmin.sendText );
                }
            } )
            .fail( function () {
                $spinner.removeClass( 'is-active' );
                $result
                    .attr( 'class', 'wt-notice wt-notice--error' )
                    .html( '<strong>Request failed.</strong>' )
                    .show();
                $self.prop( 'disabled', false ).text( wooTrendyolAdmin.sendText );
            } );
        } );
    }

    // =========================================================================
    // Global attribute value mapping (gender / age)
    // =========================================================================

    var attrState = {
        gender: { tyValues: [], wcTerms: [] },
        age:    { tyValues: [], wcTerms: [] }
    };

    function initAttrMapping() {
        if ( ! $( '#wt-load-attr-values' ).length ) { return; }

        $( '#wt-load-attr-values' ).on( 'click', function () {
            var categoryId = $( '#wt-attr-sample-category' ).val();
            if ( ! categoryId ) {
                alert( 'Please enter a Trendyol category ID.' );
                return;
            }
            loadAttrValues( parseInt( categoryId, 10 ) );
        } );

        $( document ).on( 'change', '.wt-wc-attr-selector', function () {
            var slot   = $( this ).data( 'slot' );
            var wcAttr = $( this ).val();
            fetchWcTerms( slot, wcAttr );
        } );

        $( 'form' ).on( 'submit', function () {
            serializeMappingTable( 'gender' );
            serializeMappingTable( 'age' );
        } );
    }

    function loadAttrValues( categoryId ) {
        var $btn     = $( '#wt-load-attr-values' );
        var $spinner = $( '#wt-attr-load-spinner' );

        $btn.prop( 'disabled', true );
        $spinner.addClass( 'is-active' );

        $.post( wooTrendyolAdmin.ajaxUrl, {
            action:      'trendyol_load_attr_values',
            nonce:       wooTrendyolAdmin.nonce,
            category_id: categoryId
        } )
        .done( function ( response ) {
            if ( ! response.success ) {
                alert( 'Error: ' + response.data.message );
                return;
            }
            var data = response.data;
            attrState.gender.tyValues = data.gender.values   || [];
            attrState.gender.wcTerms  = data.gender.wc_terms || [];
            attrState.age.tyValues    = data.age.values      || [];
            attrState.age.wcTerms     = data.age.wc_terms    || [];
            renderMappingTable( 'gender', data.saved_maps.gender || {} );
            renderMappingTable( 'age',    data.saved_maps.age    || {} );
        } )
        .fail( function () {
            alert( 'Request failed. Check your API credentials and try again.' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false );
            $spinner.removeClass( 'is-active' );
        } );
    }

    function fetchWcTerms( slot, wcAttr ) {
        if ( ! wcAttr ) {
            attrState[ slot ].wcTerms = [];
            $( '#wt-mapping-table-' + slot ).html(
                '<p class="wt-mapping-placeholder description">Select a WooCommerce attribute, then click "Load Trendyol Values" to build the mapping table.</p>'
            );
            return;
        }

        $.post( wooTrendyolAdmin.ajaxUrl, {
            action:  'trendyol_get_wc_terms',
            nonce:   wooTrendyolAdmin.nonce,
            slot:    slot,
            wc_attr: wcAttr
        } )
        .done( function ( response ) {
            if ( ! response.success ) { return; }
            attrState[ slot ].wcTerms = response.data.wc_terms || [];
            if ( attrState[ slot ].tyValues.length ) {
                renderMappingTable( slot, getCurrentMap( slot ) );
            }
        } );
    }

    function getCurrentMap( slot ) {
        var map = {};
        $( '#wt-mapping-table-' + slot + ' .wt-mapping-row' ).each( function () {
            var tyId  = $( this ).data( 'ty-value-id' ).toString();
            var slugs = [];
            $( this ).find( 'input[type="checkbox"]:checked' ).each( function () {
                slugs.push( $( this ).val() );
            } );
            if ( slugs.length ) { map[ tyId ] = slugs; }
        } );
        return map;
    }

    function renderMappingTable( slot, savedMap ) {
        var $wrap    = $( '#wt-mapping-table-' + slot );
        var tyValues = attrState[ slot ].tyValues;
        var wcTerms  = attrState[ slot ].wcTerms;

        if ( ! tyValues.length ) {
            $wrap.html( '<p class="wt-mapping-placeholder description">No Trendyol values found for this attribute in the selected category.</p>' );
            return;
        }
        if ( ! wcTerms.length ) {
            $wrap.html( '<p class="wt-mapping-placeholder description">Please select a WooCommerce attribute above to see its terms.</p>' );
            return;
        }

        var html = '<table class="wt-mapping-table widefat"><thead><tr>';
        html    += '<th style="width:35%">Trendyol Value</th>';
        html    += '<th>WooCommerce Terms <small>(check all that match)</small></th>';
        html    += '</tr></thead><tbody>';

        $.each( tyValues, function ( i, tyVal ) {
            var tyId        = tyVal.id.toString();
            var mappedSlugs = savedMap[ tyId ] || [];

            html += '<tr class="wt-mapping-row" data-ty-value-id="' + escHtml( tyId ) + '">';
            html += '<td class="wt-ty-value-label">';
            html += '<span class="wt-ty-id-badge">' + escHtml( tyVal.name ) + '</span>';
            html += '<small class="wt-ty-id-num"> #' + escHtml( tyId ) + '</small>';
            html += '</td><td class="wt-wc-terms-cell">';

            $.each( wcTerms, function ( j, term ) {
                var checked = mappedSlugs.indexOf( term.slug ) !== -1 ? ' checked' : '';
                html += '<label class="wt-term-checkbox">';
                html += '<input type="checkbox" value="' + escHtml( term.slug ) + '"' + checked + ' /> ';
                html += escHtml( term.name );
                html += '</label> ';
            } );

            html += '</td></tr>';
        } );

        html += '</tbody></table>';
        html += '<input type="hidden" id="wt-map-hidden-' + escHtml( slot ) + '" name="trendyol_global_attr_' + escHtml( slot ) + '_map" value="" />';

        $wrap.html( html );
        serializeMappingTable( slot );

        $wrap.on( 'change', 'input[type="checkbox"]', function () {
            serializeMappingTable( slot );
        } );
    }

    function serializeMappingTable( slot ) {
        var map = getCurrentMap( slot );
        $( '#wt-map-hidden-' + slot ).val( JSON.stringify( map ) );
    }

} )( jQuery );
