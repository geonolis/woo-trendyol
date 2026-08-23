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

    var syncOp = {
        running: false,
        paused:  false,
        chunks:  [],
        index:   0,
        total:   0,
        synced:  0,
        skipped: 0,
        batches: []
    };

    var unapprovedOp = {
        running: false,
        paused: false,
        chunks: [],
        index: 0,
        total: 0,
        submitted: 0,
        skipped: 0,
        batches: []
    };

    // Warn user before closing or leaving tab while any sync process is active
    window.addEventListener( 'beforeunload', function ( e ) {
        if ( bulkPush.running || syncOp.running || unapprovedOp.running ) {
            e.preventDefault();
            e.returnValue = 'Sync in progress. Please keep this browser tab open until the process finishes.';
            return e.returnValue;
        }
    } );

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
        initPriceRulesToggle();
        initBulkPush();
        initSyncPriceStock();
        initUpdateUnapprovedProducts();
        initManualBatchPoll();
        initLogExport();

        initAttrMapping();
        initSendToTrendyol();
        initSyncTasks();
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
    // Price Rules visibility toggle
    // =========================================================================

    function initPriceRulesToggle() {
        var $fixedToggle = $( '#trendyol_price_rule_fixed_enabled' );
        var $pctToggle   = $( '#trendyol_price_rule_percentage_enabled' );
        var $vwToggle    = $( '#trendyol_price_rule_vw_enabled' );

        if ( ! $fixedToggle.length && ! $pctToggle.length && ! $vwToggle.length ) { return; }

        function toggleRows() {
            var isFixed = $fixedToggle.is( ':checked' );
            var isPct   = $pctToggle.is( ':checked' );
            var isVw    = $vwToggle.is( ':checked' );

            $( '#trendyol_price_rule_fixed_amount' ).closest( 'tr' ).toggle( isFixed );
            $( '#trendyol_price_rule_percentage' ).closest( 'tr' ).toggle( isPct );

            var $vwRows = $( '#trendyol_price_rule_vw_under_1, #trendyol_price_rule_vw_1_to_2, #trendyol_price_rule_vw_2_to_3, #trendyol_price_rule_vw_over_3_fixed, #trendyol_price_rule_vw_over_3_coef, #trendyol_price_rule_vw_zero_dimensions_amount' ).closest( 'tr' );
            $vwRows.toggle( isVw );
        }

        $( '#trendyol_price_rule_fixed_enabled, #trendyol_price_rule_percentage_enabled, #trendyol_price_rule_vw_enabled' ).on( 'change', toggleRows );
        toggleRows();
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
        var $cancelBtn    = $( '#wt-bulk-cancel' );
        var $progressWrap = $( '#wt-bulk-progress-wrap' );
        var $progressFill = $( '#wt-bulk-progress-fill' );
        var $progressText = $( '#wt-bulk-progress-text' );
        var $currentIds   = $( '#wt-bulk-current-ids' );
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
                $cancelBtn.show();
                pushNextChunk();
                return;
            }

            // Fresh start
            var onlyUnmapped = $( '#wt-bulk-only-unmapped' ).is( ':checked' );
            var includeOutOfStock = $( '#wt-bulk-include-out-of-stock' ).is( ':checked' );

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
            $cancelBtn.show();
            $progressWrap.show();
            $progressWrap.find( '.wt-keep-tab-open-notice' ).show();
            $progressFill.css( 'width', '0%' );
            $progressText.text( '0 / ?' );
            $currentIds.text( '' );
            $totals.hide().empty();
            $results.hide();
            $resultsList.empty();

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action:        'trendyol_get_pushable_products',
                nonce:         wooTrendyolAdmin.nonce,
                only_unmapped: onlyUnmapped ? 1 : 0,
                include_out_of_stock: includeOutOfStock ? 1 : 0,
                action_type:   'push'
            } )
            .done( function ( response ) {
                if ( ! response.success || ! response.data.product_ids ) {
                    finishBulkWithError( 'Could not retrieve product list.' );
                    return;
                }

                if ( response.data.message ) {
                    appendBulkRow( 'Info', 'info', response.data.message );
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

        // ---- Cancel button ----
        $cancelBtn.on( 'click', function () {
            if ( ! bulkPush.running ) { return; }
            bulkPush.running = false;
            bulkPush.paused = false;
            appendBulkRow( 'Cancelled', 'error', 'Operation cancelled by user.' );
            finishBulkPush();
        } );

        // ---- Push next chunk ----
        function pushNextChunk() {
            if ( bulkPush.paused || ! bulkPush.running ) { return; }

            if ( bulkPush.index >= bulkPush.chunks.length ) {
                finishBulkPush();
                return;
            }

            var chunk = bulkPush.chunks[ bulkPush.index++ ];
            $currentIds.text( 'Processing IDs: ' + chunk.join(', ') );

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
            if (status === 'info') {
                cls = 'wt-result--info';
            }
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
            $cancelBtn.hide();
            $progressWrap.find( '.wt-keep-tab-open-notice' ).hide();
            $progressFill.css( 'width', '100%' );
            $currentIds.text( 'Done.' );

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
            $cancelBtn.hide();
            $progressWrap.hide();
            appendBulkRow( 'Error', 'error', msg );
            $results.show();
        }
    }

    // =========================================================================
    // Sync Price & Stock (async, pauseable)
    // =========================================================================

    function initSyncPriceStock() {
        var $btn          = $( '#wt-sync-price-stock' );
        var $pauseBtn     = $( '#wt-sync-pause' );
        var $cancelBtn    = $( '#wt-sync-cancel' );
        var $progressWrap = $( '#wt-sync-progress-wrap' );
        var $progressFill = $( '#wt-sync-progress-fill' );
        var $progressText = $( '#wt-sync-progress-text' );
        var $currentIds   = $( '#wt-sync-current-ids' );
        var $results      = $( '#wt-sync-results' );
        var $resultsList  = $( '#wt-sync-results-list' );

        var syncOp = {
            running: false,
            paused: false,
            chunks: [],
            index: 0,
            total: 0,
            synced: 0,
            skipped: 0,
            batches: []
        };

        if ( ! $btn.length ) { return; }

        $btn.on( 'click', function () {
            if ( syncOp.running && ! syncOp.paused ) { return; }

            if ( syncOp.paused ) {
                syncOp.paused  = false;
                syncOp.running = true;
                $btn.prop( 'disabled', true ).text( 'Syncing...' );
                $pauseBtn.prop( 'disabled', false ).text( 'Pause' );
                $cancelBtn.show();
                pushNextChunk();
                return;
            }

            var includeOutOfStock = $( '#wt-sync-include-out-of-stock' ).is( ':checked' );

            syncOp.running  = true;
            syncOp.paused   = false;
            syncOp.chunks   = [];
            syncOp.index    = 0;
            syncOp.total    = 0;
            syncOp.synced   = 0;
            syncOp.skipped  = 0;
            syncOp.batches  = [];

            $btn.prop( 'disabled', true ).text( 'Syncing...' );
            $pauseBtn.prop( 'disabled', false ).show().text( 'Pause' );
            $cancelBtn.show();
            $progressWrap.show();
            $progressWrap.find( '.wt-keep-tab-open-notice' ).show();
            $progressFill.css( 'width', '0%' );
            $progressText.text( '0 / ?' );
            $currentIds.text( '' );
            $results.hide();
            $resultsList.empty();

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action:        'trendyol_get_pushable_products',
                nonce:         wooTrendyolAdmin.nonce,
                only_unmapped: 0,
                include_out_of_stock: includeOutOfStock ? 1 : 0,
                action_type:   'sync'
            } )
            .done( function ( response ) {
                if ( ! response.success || ! response.data.product_ids ) {
                    finishWithError( 'Could not retrieve product list.' );
                    return;
                }

                if ( response.data.message ) {
                    appendRow( 'Info', 'info', response.data.message );
                }

                var allIds = response.data.product_ids;
                syncOp.total = allIds.length;

                if ( syncOp.total === 0 ) {
                    finishWithError( 'No eligible products found.' );
                    return;
                }

                $progressText.text( '0 / ' + syncOp.total );

                for ( var i = 0; i < allIds.length; i += BATCH_SIZE ) {
                    syncOp.chunks.push( allIds.slice( i, i + BATCH_SIZE ) );
                }

                pushNextChunk();
            } )
            .fail( function () {
                finishWithError( 'Failed to retrieve product list.' );
            } );
        } );

        $pauseBtn.on( 'click', function () {
            if ( ! syncOp.running ) { return; }
            if ( syncOp.paused ) { return; }
            syncOp.paused = true;
            $( this ).prop( 'disabled', true ).text( 'Paused' );
            $btn.prop( 'disabled', false ).text( 'Resume' );
        } );

        $cancelBtn.on( 'click', function () {
            if ( ! syncOp.running ) { return; }
            syncOp.running = false;
            syncOp.paused = false;
            appendRow( 'Cancelled', 'error', 'Operation cancelled by user.' );
            finishOp();
        } );

        function pushNextChunk() {
            if ( syncOp.paused || ! syncOp.running ) { return; }

            if ( syncOp.index >= syncOp.chunks.length ) {
                finishOp();
                return;
            }

            var chunk = syncOp.chunks[ syncOp.index++ ];
            $currentIds.text( 'Processing IDs: ' + chunk.join(', ') );

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action:      'trendyol_bulk_sync_price_stock_batch',
                nonce:       wooTrendyolAdmin.nonce,
                product_ids: JSON.stringify( chunk )
            } )
            .done( function ( res ) {
                if ( res.success ) {
                    syncOp.synced  += res.data.submitted || 0;
                    syncOp.skipped += res.data.skipped   || 0;
                    if ( res.data.batches ) {
                        syncOp.batches = syncOp.batches.concat( res.data.batches );
                    }
                    if ( res.data.errors ) {
                        $.each( res.data.errors, function ( pid, msg ) {
                            appendRow( pid, 'error', msg );
                        } );
                    }
                } else {
                    syncOp.skipped += chunk.length;
                    appendRow( 'Batch', 'error', res.data.message || 'Unknown error' );
                }

                var done = syncOp.synced + syncOp.skipped;
                var pct  = syncOp.total > 0 ? Math.round( ( done / syncOp.total ) * 100 ) : 0;
                $progressFill.css( 'width', pct + '%' );
                $progressText.text( done + ' / ' + syncOp.total );

                pushNextChunk();
            } )
            .fail( function () {
                syncOp.skipped += chunk.length;
                appendRow( 'Batch', 'error', 'HTTP request failed.' );
                pushNextChunk();
            } );
        }

        function appendRow( id, status, message ) {
            var cls = status === 'error' ? 'wt-result--error' : 'wt-result--success';
            if (status === 'info') {
                cls = 'wt-result--info';
            }
            $resultsList.append(
                '<div class="wt-result-row ' + cls + '">' +
                '<strong>#' + escHtml( String( id ) ) + '</strong> — ' + escHtml( message ) +
                '</div>'
            );
            $results.show();
        }

        function finishOp() {
            syncOp.running = false;
            syncOp.paused  = false;

            $btn.prop( 'disabled', false ).text( 'Sync Price & Stock' );
            $pauseBtn.prop( 'disabled', true ).hide();
            $cancelBtn.hide();
            $progressWrap.find( '.wt-keep-tab-open-notice' ).hide();
            $progressFill.css( 'width', '100%' );
            $currentIds.text( 'Done.' );

            var summary = '<div class="wt-result-row wt-result--success">' +
                '<strong>Done.</strong> ' +
                syncOp.synced + ' submitted, ' + syncOp.skipped + ' skipped.' +
                '</div>';

            if ( syncOp.batches.length ) {
                summary += '<div class="wt-result-row wt-result--info">' +
                    'Batch IDs: ' + escHtml( syncOp.batches.join( ', ' ) ) +
                    ' &mdash; <a href="#" class="wt-poll-batches" data-batches="' +
                    escHtml( syncOp.batches.join( ',' ) ) + '">Poll Status</a>' +
                    '</div>';
            }

            $resultsList.prepend( summary );
            $results.show();

            if ( syncOp.batches.length ) {
                setTimeout( function () {
                    pollBatchStatuses( syncOp.batches, $resultsList, $results );
                }, 30000 );
            }
        }

        function finishWithError( msg ) {
            syncOp.running = false;
            syncOp.paused  = false;
            $btn.prop( 'disabled', false ).text( 'Sync Price & Stock' );
            $pauseBtn.prop( 'disabled', true ).hide();
            $cancelBtn.hide();
            $progressWrap.hide();
            appendRow( 'Error', 'error', msg );
            $results.show();
        }
    }

    // =========================================================================
    // Manual batch status poll
    // =========================================================================

    // =========================================================================
    // Update Unapproved Products (async, pauseable)
    // =========================================================================

    function initUpdateUnapprovedProducts() {
        var $btn          = $( '#wt-unapproved-push' );
        var $pauseBtn     = $( '#wt-unapproved-pause' );
        var $resumeBtn    = $( '#wt-unapproved-resume' );
        var $cancelBtn    = $( '#wt-unapproved-cancel' );
        var $spinner      = $( '#wt-unapproved-spinner' );
        var $progressWrap = $( '#wt-unapproved-progress-wrap' );
        var $progressFill = $( '#wt-unapproved-progress-fill' );
        var $progressText = $( '#wt-unapproved-progress-text' );
        var $currentIds   = $( '#wt-unapproved-current-ids' );
        var $totals       = $( '#wt-unapproved-totals' );
        var $results      = $( '#wt-unapproved-results' );
        var $resultsList  = $( '#wt-unapproved-results-list' );

        // uses shared unapprovedOp

        if ( ! $btn.length ) { return; }

        $btn.on( 'click', function () {
            if ( unapprovedOp.running && ! unapprovedOp.paused ) { return; }

            unapprovedOp.running   = true;
            unapprovedOp.paused    = false;
            unapprovedOp.chunks    = [];
            unapprovedOp.index     = 0;
            unapprovedOp.total     = 0;
            unapprovedOp.submitted = 0;
            unapprovedOp.skipped   = 0;
            unapprovedOp.batches   = [];

            $btn.prop( 'disabled', true );
            $pauseBtn.show().prop( 'disabled', false );
            $resumeBtn.hide();
            $cancelBtn.show();
            $spinner.addClass( 'is-active' );
            $progressWrap.show();
            $progressWrap.find( '.wt-keep-tab-open-notice' ).show();
            $progressFill.css( 'width', '0%' );
            $progressText.text( '0 / ?' );
            $currentIds.text( 'Fetching unapproved products list...' );
            $totals.hide().empty();
            $results.hide();
            $resultsList.empty();

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action: 'trendyol_get_unapproved_products_to_update',
                nonce:  wooTrendyolAdmin.nonce
            } )
            .done( function ( response ) {
                $spinner.removeClass( 'is-active' );

                if ( ! response.success || ! response.data.product_ids ) {
                    finishWithError( response.data && response.data.message ? response.data.message : 'Could not retrieve unapproved products list.' );
                    return;
                }

                var ids = response.data.product_ids;
                unapprovedOp.total = ids.length;

                if ( response.data.message ) {
                    appendRow( 'Info', 'info', response.data.message );
                }

                if ( unapprovedOp.total === 0 ) {
                    finishWithError( 'No unapproved products found to update.' );
                    return;
                }

                for ( var i = 0; i < ids.length; i += 20 ) {
                    unapprovedOp.chunks.push( ids.slice( i, i + 20 ) );
                }

                $progressText.text( '0 / ' + unapprovedOp.total );
                pushNextChunk();
            } )
            .fail( function () {
                $spinner.removeClass( 'is-active' );
                finishWithError( 'HTTP request failed while fetching unapproved products.' );
            } );
        } );

        $pauseBtn.on( 'click', function () {
            unapprovedOp.paused  = true;
            unapprovedOp.running = false;
            $pauseBtn.hide();
            $resumeBtn.show();
            $btn.prop( 'disabled', false );
            $currentIds.text( 'Paused.' );
        } );

        $resumeBtn.on( 'click', function () {
            unapprovedOp.paused  = false;
            unapprovedOp.running = true;
            $resumeBtn.hide();
            $pauseBtn.show();
            $btn.prop( 'disabled', true );
            pushNextChunk();
        } );

        $cancelBtn.on( 'click', function () {
            unapprovedOp.running = false;
            unapprovedOp.paused  = false;
            appendRow( 'Cancelled', 'error', 'Operation cancelled by user.' );
            finishOperation();
        } );

        function pushNextChunk() {
            if ( unapprovedOp.paused || ! unapprovedOp.running ) { return; }

            if ( unapprovedOp.index >= unapprovedOp.chunks.length ) {
                finishOperation();
                return;
            }

            var chunk = unapprovedOp.chunks[ unapprovedOp.index++ ];
            $currentIds.text( 'Submitting product IDs: ' + chunk.join( ', ' ) );

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action:      'trendyol_bulk_update_unapproved_batch',
                nonce:       wooTrendyolAdmin.nonce,
                product_ids: JSON.stringify( chunk )
            } )
            .done( function ( res ) {
                if ( res.success ) {
                    unapprovedOp.submitted += res.data.submitted || 0;
                    unapprovedOp.skipped   += res.data.skipped   || 0;
                    if ( res.data.batches ) {
                        unapprovedOp.batches = unapprovedOp.batches.concat( res.data.batches );
                    }
                    if ( res.data.errors ) {
                        $.each( res.data.errors, function ( pid, msg ) {
                            appendRow( pid, 'error', msg );
                        } );
                    }
                } else {
                    unapprovedOp.skipped += chunk.length;
                    appendRow( 'Batch', 'error', res.data.message || 'Unknown error' );
                }

                var done = unapprovedOp.submitted + unapprovedOp.skipped;
                var pct  = unapprovedOp.total > 0 ? Math.round( ( done / unapprovedOp.total ) * 100 ) : 0;
                $progressFill.css( 'width', pct + '%' );
                $progressText.text( done + ' / ' + unapprovedOp.total );

                pushNextChunk();
            } )
            .fail( function () {
                unapprovedOp.skipped += chunk.length;
                appendRow( 'Batch', 'error', 'HTTP request failed for batch.' );
                pushNextChunk();
            } );
        }

        function appendRow( id, status, message ) {
            var cls = status === 'error' ? 'wt-result--error' : ( status === 'info' ? 'wt-result--info' : 'wt-result--success' );
            var now = new Date().toLocaleTimeString();
            $resultsList.append(
                '<div class="wt-result-row ' + cls + '">' +
                '[' + now + '] <strong>#' + escHtml( String( id ) ) + '</strong> — ' + escHtml( message ) +
                '</div>'
            );
            $results.show();
        }

        function finishOperation() {
            unapprovedOp.running = false;
            unapprovedOp.paused  = false;

            $btn.prop( 'disabled', false );
            $pauseBtn.hide();
            $resumeBtn.hide();
            $cancelBtn.hide();
            $progressWrap.find( '.wt-keep-tab-open-notice' ).hide();
            $progressFill.css( 'width', '100%' );
            $currentIds.text( 'Done.' );

            $totals.html(
                '<div class="wt-totals-row">' +
                '<span class="wt-total-item wt-total--submitted">&#x2191; Submitted: <strong>' + unapprovedOp.submitted + '</strong></span>' +
                '<span class="wt-total-item wt-total--skipped">&#x26A0; Skipped: <strong>' + unapprovedOp.skipped + '</strong></span>' +
                '</div>'
            ).show();

            var summary = '<div class="wt-result-row wt-result--success">' +
                '<strong>Update Complete:</strong> ' +
                unapprovedOp.submitted + ' submitted, ' + unapprovedOp.skipped + ' skipped.' +
                '</div>';

            if ( unapprovedOp.batches.length ) {
                summary += '<div class="wt-result-row wt-result--info">' +
                    'Batch IDs: ' + escHtml( unapprovedOp.batches.join( ', ' ) ) +
                    ' &mdash; <a href="#" class="wt-poll-batches" data-batches="' +
                    escHtml( unapprovedOp.batches.join( ',' ) ) + '">Poll Status</a>' +
                    '</div>';
            }

            $resultsList.prepend( summary );
            $results.show();

            if ( unapprovedOp.batches.length ) {
                setTimeout( function () {
                    pollBatchStatuses( unapprovedOp.batches, $resultsList, $results );
                }, 10000 );
            }
        }

        function finishWithError( msg ) {
            unapprovedOp.running = false;
            unapprovedOp.paused  = false;
            $btn.prop( 'disabled', false );
            $pauseBtn.hide();
            $resumeBtn.hide();
            $cancelBtn.hide();
            $progressWrap.hide();
            appendRow( 'Error', 'error', msg );
            $results.show();
        }
    }

    // =========================================================================
    // Manual & Auto Batch Polling
    // =========================================================================

    function initManualBatchPoll() {
        $( document ).on( 'click', '.wt-poll-batches', function ( e ) {
            e.preventDefault();
            var $link        = $( this );
            var batches      = $link.data( 'batches' ).toString().split( ',' );
            var $results     = $link.closest( '.wt-bulk-results' );
            var $resultsList = $results.find( '.wt-log-box, div[id$="-results-list"]' );
            if ( ! $resultsList.length ) {
                $resultsList = $( '#wt-bulk-results-list' );
                $results     = $( '#wt-bulk-results' );
            }
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
                var now = new Date().toLocaleTimeString();

                if ( response.success ) {
                    var data   = response.data;
                    var status = data.status || 'UNKNOWN';
                    var items  = data.items  || [];
                    var failed = $.grep( items, function ( item ) {
                        return item.status === 'ERROR' || item.status === 'FAILED' || ( item.failureReasons && item.failureReasons.length > 0 );
                    } );

                    var statusCls = status === 'COMPLETED' ? ( failed.length ? 'color:#c92a2a;' : 'color:#1a7a2e;' ) : 'color:#d97706;';

                    msg = '[' + now + '] Batch <code>' + escHtml( batchId ) + '</code>: <strong style="' + statusCls + '">' + escHtml( status ) + '</strong>' +
                          ' (' + items.length + ' items, ' + failed.length + ' error(s))';

                    if ( failed.length ) {
                        msg += '<ul class="wt-log-item-failure">';
                        $.each( failed, function ( j, item ) {
                            var barcode = item.barcode || ( item.requestItem && item.requestItem.product && item.requestItem.product.barcode ) || 'Item';
                            var reasons = [];
                            if ( item.failureReasons && item.failureReasons.length ) {
                                $.each( item.failureReasons, function ( k, r ) {
                                    if ( typeof r === 'object' && r !== null ) {
                                        reasons.push( r.message || r.reason || JSON.stringify( r ) );
                                    } else {
                                        reasons.push( String( r ) );
                                    }
                                } );
                            } else if ( item.status ) {
                                reasons.push( 'Status: ' + item.status );
                            }
                            msg += '<li><strong>' + escHtml( barcode ) + '</strong>: ' + escHtml( reasons.join( '; ' ) ) + '</li>';
                        } );
                        msg += '</ul>';
                    }

                    if ( status === 'IN_PROGRESS' || status === 'PROCESSING' || status === 'WAITING' ) {
                        setTimeout( function () {
                            pollBatchStatuses( [ batchId ], $list, $wrap );
                        }, 10000 );
                    }
                } else {
                    msg = '[' + now + '] Batch <code>' + escHtml( batchId ) + '</code>: ' +
                          escHtml( ( response.data && response.data.message ) || 'Error polling batch.' );
                }

                $list.append( '<div class="wt-result-row wt-result--info">' + msg + '</div>' );
                $wrap.show();
            } );
        } );
    }

    // =========================================================================
    // Log Box Actions (Copy & Download TXT)
    // =========================================================================

    function initLogExport() {
        $( document ).on( 'click', '.wt-copy-log-btn', function ( e ) {
            e.preventDefault();
            var $btn = $( this );
            var targetSelector = $btn.data( 'target' );
            var $target = $( targetSelector );
            if ( ! $target.length ) { return; }

            var lines = [];
            $target.find( '.wt-result-row' ).each( function () {
                var text = $( this ).text().trim();
                if ( text ) {
                    lines.push( text );
                }
            } );

            var logText = lines.join( '\n' );
            if ( ! logText ) {
                logText = $target.text().trim();
            }

            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                navigator.clipboard.writeText( logText ).then( function () {
                    var originalHtml = $btn.html();
                    $btn.html( '<span class="dashicons dashicons-yes"></span> Copied!' );
                    setTimeout( function () { $btn.html( originalHtml ); }, 2000 );
                } );
            } else {
                var $temp = $( '<textarea>' );
                $( 'body' ).append( $temp );
                $temp.val( logText ).select();
                document.execCommand( 'copy' );
                $temp.remove();
                var orig = $btn.html();
                $btn.html( '<span class="dashicons dashicons-yes"></span> Copied!' );
                setTimeout( function () { $btn.html( orig ); }, 2000 );
            }
        } );

        $( document ).on( 'click', '.wt-download-log-btn', function ( e ) {
            e.preventDefault();
            var $btn = $( this );
            var targetSelector = $btn.data( 'target' );
            var filename = $btn.data( 'filename' ) || 'trendyol-sync-log.txt';
            var $target = $( targetSelector );
            if ( ! $target.length ) { return; }

            var lines = [];
            $target.find( '.wt-result-row' ).each( function () {
                var text = $( this ).text().trim();
                if ( text ) {
                    lines.push( text );
                }
            } );

            var logText = lines.join( '\r\n' );
            if ( ! logText ) {
                logText = $target.text().trim();
            }

            var blob = new Blob( [ logText ], { type: 'text/plain;charset=utf-8' } );
            var url  = URL.createObjectURL( blob );
            var a    = document.createElement( 'a' );
            a.href     = url;
            a.download = filename;
            document.body.appendChild( a );
            a.click();
            document.body.removeChild( a );
            URL.revokeObjectURL( url );
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
        gender:       { tyValues: [], wcTerms: [] },
        age:          { tyValues: [], wcTerms: [] },
        age_group:    { tyValues: [], wcTerms: [] },
        color:        { tyValues: [], wcTerms: [] },
        color_custom: { tyValues: [], wcTerms: [] }
    };

    function initAttrMapping() {
        if ( ! $( '#wt-load-all-mapped-attr-values' ).length && ! $( '.wt-attr-mapping-form-table' ).length ) { return; }

        initAttrAccordions();

        $( '#wt-load-all-mapped-attr-values' ).on( 'click', function () {
            loadAttrValues();
        } );

        $( document ).on( 'change', '.wt-wc-attr-selector, .wt-brand-source-select, select[name="trendyol_global_attr_character_wc"]', function () {
            var $select = $( this );
            var val     = $select.val();

            if ( val === 'custom_meta_prompt' ) {
                var metaKey = prompt( 'Enter product custom post meta key (e.g. _my_custom_meta_key):' );
                if ( metaKey ) {
                    metaKey = metaKey.trim();
                    if ( metaKey ) {
                        var fullVal = 'meta:' + metaKey;
                        if ( ! $select.find( 'option[value="' + fullVal + '"]' ).length ) {
                            var $optGroup = $select.find( 'optgroup[label*="Custom Meta"], optgroup:last' );
                            if ( $optGroup.length ) {
                                $optGroup.prepend( '<option value="' + escHtml( fullVal ) + '" selected="selected">' + escHtml( 'Custom Meta: ' + metaKey ) + '</option>' );
                            } else {
                                $select.append( '<option value="' + escHtml( fullVal ) + '" selected="selected">' + escHtml( 'Custom Meta: ' + metaKey ) + '</option>' );
                            }
                        }
                        $select.val( fullVal ).trigger( 'change' );
                        return;
                    }
                }
                $select.val( '' );
                return;
            }

            var slot   = $select.data( 'slot' );
            if ( slot ) {
                fetchWcTerms( slot, val );
            }
        } );

        $( 'form' ).on( 'submit', function () {
            $.each( attrState, function ( id, data ) {
                serializeMappingTable( id );
            } );
        } );
    }

    function initAttrAccordions() {
        var $table = $( '.wt-attr-mapping-form-table' );
        if ( ! $table.length ) return;

        $table.children( 'tbody' ).children( 'tr' ).each( function () {
            var $tr = $( this );
            var $th = $tr.children( 'th' );
            var $td = $tr.children( 'td' );

            if ( ! $th.length || $th.hasClass( 'wt-accordion-init' ) ) return;
            $th.addClass( 'wt-accordion-init' );

            // Extract label text cleanly
            var rawTitle = $th.find( 'label' ).text() || $th.text();
            var titleText = rawTitle.replace( /\s*—\s*WooCommerce Attribute/i, '' ).replace( /\s*—\s*WC Attribute/i, '' ).trim();

            // Find select dropdown in td
            var $select = $td.find( 'select' ).first();

            $th.html(
                '<div class="wt-accordion-header-left">' +
                    '<span class="dashicons dashicons-chevron-right wt-accordion-toggle-icon"></span>' +
                    '<strong class="wt-accordion-title">' + escHtml( titleText ) + '</strong>' +
                '</div>' +
                '<div class="wt-accordion-header-center"></div>' +
                '<div class="wt-accordion-header-right">' +
                    '<button type="button" class="button button-secondary button-small wt-accordion-toggle-btn">' +
                        '<span class="dashicons dashicons-arrow-down-alt2" style="vertical-align: middle; margin-right: 3px;"></span>Expand for details' +
                    '</button>' +
                '</div>'
            );

            // Move select element into visible header center column
            if ( $select.length ) {
                $th.find( '.wt-accordion-header-center' ).append( $select );
            }

            // Start closed by default
            $tr.addClass( 'wt-accordion-closed' );
            $td.hide();

            function updateToggleBtnState( $tr, isClosed ) {
                var $btn = $tr.find( '.wt-accordion-toggle-btn' );
                if ( isClosed ) {
                    $btn.html( '<span class="dashicons dashicons-arrow-down-alt2" style="vertical-align: middle; margin-right: 3px;"></span>Expand for details' );
                } else {
                    $btn.html( '<span class="dashicons dashicons-arrow-up-alt2" style="vertical-align: middle; margin-right: 3px;"></span>Collapse details' );
                }
            }

            // Toggle accordion when header left or button is clicked
            $th.find( '.wt-accordion-header-left, .wt-accordion-toggle-btn' ).on( 'click', function ( e ) {
                e.preventDefault();
                e.stopPropagation();

                var isClosed = $tr.hasClass( 'wt-accordion-closed' );
                if ( isClosed ) {
                    $tr.removeClass( 'wt-accordion-closed' );
                    $td.slideDown( 150 );
                    updateToggleBtnState( $tr, false );
                } else {
                    $td.slideUp( 150, function () {
                        $tr.addClass( 'wt-accordion-closed' );
                    } );
                    updateToggleBtnState( $tr, true );
                }
            } );

            // Prevent clicking directly on select from toggling accordion
            $th.find( 'select' ).on( 'click change', function ( e ) {
                e.stopPropagation();
            } );
        } );

        // Expand All control
        $( document ).off( 'click', '#wt-expand-all-attrs' ).on( 'click', '#wt-expand-all-attrs', function () {
            $( '.wt-attr-mapping-form-table > tbody > tr' ).each( function () {
                var $tr = $( this );
                $tr.removeClass( 'wt-accordion-closed' );
                $tr.children( 'td' ).slideDown( 150 );
                $tr.find( '.wt-accordion-toggle-btn' ).html( '<span class="dashicons dashicons-arrow-up-alt2" style="vertical-align: middle; margin-right: 3px;"></span>Collapse details' );
            } );
        } );

        // Collapse All control
        $( document ).off( 'click', '#wt-collapse-all-attrs' ).on( 'click', '#wt-collapse-all-attrs', function () {
            $( '.wt-attr-mapping-form-table > tbody > tr' ).each( function () {
                var $tr = $( this );
                $tr.children( 'td' ).slideUp( 150, function () {
                    $tr.addClass( 'wt-accordion-closed' );
                } );
                $tr.find( '.wt-accordion-toggle-btn' ).html( '<span class="dashicons dashicons-arrow-down-alt2" style="vertical-align: middle; margin-right: 3px;"></span>Expand for details' );
            } );
        } );
    }

    function loadAttrValues() {
        var $btn     = $( '#wt-load-all-mapped-attr-values' );
        var $spinner = $( '#wt-attr-load-spinner' );

        $btn.prop( 'disabled', true );
        $spinner.addClass( 'is-active' );

        $.post( wooTrendyolAdmin.ajaxUrl, {
            action:      'trendyol_load_all_mapped_attr_values',
            nonce:       wooTrendyolAdmin.nonce
        } )
        .done( function ( response ) {
            if ( ! response.success ) {
                alert( 'Error: ' + response.data.message );
                return;
            }
            var data = response.data || {};
            var savedMaps = data.saved_maps || {};

            // Dynamically register attributes in attrState
            if ( data.attributes ) {
                var needsRefresh = false;
                $.each( data.attributes, function ( id, attrData ) {
                    if ( ! $( '#wt-mapping-table-' + id ).length ) {
                        needsRefresh = true;
                    }
                } );

                if ( needsRefresh ) {
                    alert( 'Global attributes were updated! The page will now reload to display the new fields.' );
                    window.location.reload();
                    return;
                }

                $.each( data.attributes, function ( id, attrData ) {
                    var currentDomMap = getCurrentMap( id );
                    var dbMap = savedMaps[ id ] || {};
                    var mergedMap = $.extend( {}, dbMap, currentDomMap );

                    var $select = $( '#trendyol_global_attr_' + id + '_wc, select[data-slot="' + id + '"]' );
                    var currentDomWcAttr = $select.length ? $select.val() : '';

                    attrState[ id ] = attrState[ id ] || { wcTerms: [], tyValues: [] };
                    attrState[ id ].tyValues = attrData.values || [];
                    attrState[ id ].wcTerms  = attrData.wc_terms || [];
                    attrState[ id ].allowCustom = attrData.allowCustom || false;

                    if ( ( ! attrData.wc_terms || ! attrData.wc_terms.length ) && currentDomWcAttr ) {
                        fetchWcTerms( id, currentDomWcAttr );
                    } else {
                        renderMappingTable( id, mergedMap );
                    }
                } );
            }
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
            if ( attrState[ slot ] ) {
                attrState[ slot ].wcTerms = [];
            }
            $( '#wt-mapping-table-' + slot ).html(
                '<p class="wt-mapping-placeholder description">Select a WooCommerce attribute, then click "Load Trendyol Values" to build the mapping table.</p>'
            );
            return;
        }

        attrState[ slot ] = attrState[ slot ] || { wcTerms: [], tyValues: [] };

        $.post( wooTrendyolAdmin.ajaxUrl, {
            action:  'trendyol_get_wc_terms',
            nonce:   wooTrendyolAdmin.nonce,
            slot:    slot,
            wc_attr: wcAttr
        } )
        .done( function ( response ) {
            if ( ! response.success ) { return; }
            attrState[ slot ].wcTerms = response.data.wc_terms || [];
            renderMappingTable( slot, getCurrentMap( slot ) );
        } );
    }

    function getCurrentMap( slot ) {
        var map = {};
        var $colorRows = $( '#wt-mapping-table-' + slot + ' .wt-mapping-row-color' );
        if ( $colorRows.length ) {
            $colorRows.each( function () {
                var termSlug = $( this ).data( 'wc-term-slug' ).toString();
                var tyValId = $( this ).find( '.wt-color-dropdown-select' ).val();
                if ( tyValId ) {
                    map[ termSlug ] = tyValId;
                }
            } );
            return map;
        }
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

    function normalizeMapToTermKey( map ) {
        var result = {};
        if ( ! map || typeof map !== 'object' ) return result;
        $.each( map, function ( key, val ) {
            if ( Array.isArray( val ) ) {
                $.each( val, function ( i, termSlug ) {
                    if ( termSlug ) {
                        result[ termSlug.toString() ] = key.toString();
                    }
                } );
            } else if ( val !== null && val !== undefined && val !== '' ) {
                result[ key.toString() ] = val.toString();
            }
        } );
        return result;
    }

    function normalizeMapToTyKey( map ) {
        var result = {};
        if ( ! map || typeof map !== 'object' ) return result;
        $.each( map, function ( key, val ) {
            if ( Array.isArray( val ) ) {
                var tyId = key.toString();
                result[ tyId ] = result[ tyId ] || [];
                $.each( val, function ( i, termSlug ) {
                    if ( termSlug && result[ tyId ].indexOf( termSlug.toString() ) === -1 ) {
                        result[ tyId ].push( termSlug.toString() );
                    }
                } );
            } else if ( val !== null && val !== undefined && val !== '' ) {
                var tyId = val.toString();
                var termSlug = key.toString();
                result[ tyId ] = result[ tyId ] || [];
                if ( result[ tyId ].indexOf( termSlug ) === -1 ) {
                    result[ tyId ].push( termSlug );
                }
            }
        } );
        return result;
    }

    function renderMappingTable( slot, savedMap ) {
        var $wrap    = $( '#wt-mapping-table-' + slot );
        var state    = attrState[ slot ] || {};
        var tyValues = state.tyValues || [];
        var wcTerms  = state.wcTerms || [];

        var isCustom = state.allowCustom || slot === 'color_custom' || /\b(web|free|custom|serbest)\b/i.test( state.name || '' );
        if ( isCustom ) {
            $wrap.html( '<p class="description" style="color: #007cba; margin-top: 15px;">This attribute accepts free text. No value mapping is required; terms from your selected WooCommerce attribute will be sent exactly as they are.</p>' );
            return;
        }

        var selectedWcAttr = $( 'select[data-slot="' + slot + '"]' ).val() || $( 'select[name="' + slot + '"]' ).val() || '';
        var isDimensionOrMeta = selectedWcAttr && ( selectedWcAttr.indexOf( 'dim_' ) === 0 || selectedWcAttr.indexOf( 'meta:' ) === 0 );

        if ( isDimensionOrMeta ) {
            $wrap.html( '<p class="description" style="color: #007cba; margin-top: 15px;">Values for this attribute will be sent dynamically from your selected product property/meta field. No term mapping is required.</p>' );
            return;
        }

        if ( ! tyValues.length ) {
            $wrap.html( '<p class="wt-mapping-placeholder description">No Trendyol values found for this attribute in the selected category.</p>' );
            return;
        }
        if ( ! wcTerms.length ) {
            $wrap.html( '<p class="wt-mapping-placeholder description">Please select a WooCommerce attribute above to see its terms.</p>' );
            return;
        }

        var termKeyMap = normalizeMapToTermKey( savedMap );
        var tyKeyMap   = normalizeMapToTyKey( savedMap );

        var html = '<table class="wt-mapping-table widefat"><thead><tr>';
        html    += '<th style="width:35%">Trendyol Value</th>';
        html    += '<th>WooCommerce Terms <small>(check all that match)</small></th>';
        html    += '</tr></thead><tbody>';

        $.each( tyValues, function ( i, tyVal ) {
            if ( ! tyVal || ! tyVal.id ) { return; }
            var tyId = tyVal.id.toString();
            var mappedSlugs = tyKeyMap[ tyId ] ? tyKeyMap[ tyId ].slice() : [];

            // If no mapped slugs exist in saved map, try to auto-match by name/slug similarity
            if ( mappedSlugs.length === 0 ) {
                var tyNameLower = ( tyVal.name || '' ).toLowerCase().trim();
                if ( tyNameLower ) {
                    $.each( wcTerms, function ( j, term ) {
                        if ( ! term || ! term.slug ) { return; }
                        var termNameLower = ( term.name || '' ).toLowerCase().trim();
                        var termSlugLower = ( term.slug || '' ).toLowerCase().trim();
                        var isMatch = false;
                        
                        if ( slot == '346' ) { // 346 is Color in Trendyol
                            // Color / general: check exact match or if one is inside the other
                            if ( termNameLower === tyNameLower || termSlugLower === tyNameLower ||
                                 termNameLower.indexOf( tyNameLower ) !== -1 || tyNameLower.indexOf( termNameLower ) !== -1 ) {
                                isMatch = true;
                            }
                        } else {
                            // Try to match generally
                            var tyDigits = tyNameLower.replace(/\D/g, '');
                            var termDigits = termNameLower.replace(/\D/g, '');
                            if ( tyDigits && termDigits && tyDigits === termDigits ) {
                                isMatch = true;
                            } else if ( termNameLower === tyNameLower || termSlugLower === tyNameLower ||
                                 termNameLower.indexOf( tyNameLower ) !== -1 || tyNameLower.indexOf( termNameLower ) !== -1 ) {
                                isMatch = true;
                            }
                        }

                        if ( isMatch ) {
                            mappedSlugs.push( term.slug );
                        }
                    } );
                }
            }

            html += '<tr class="wt-mapping-row" data-ty-value-id="' + escHtml( tyId ) + '">';
            html += '<td class="wt-ty-value-label">';
            html += '<span class="wt-ty-id-badge">' + escHtml( tyVal.name || tyId ) + '</span>';
            html += '<small class="wt-ty-id-num"> #' + escHtml( tyId ) + '</small>';
            html += '</td><td class="wt-wc-terms-cell">';

            $.each( wcTerms, function ( j, term ) {
                if ( ! term || ! term.slug ) { return; }
                var checked = mappedSlugs.indexOf( term.slug ) !== -1 ? ' checked' : '';
                html += '<label class="wt-term-checkbox">';
                html += '<input type="checkbox" value="' + escHtml( term.slug ) + '"' + checked + ' /> ';
                html += escHtml( term.name || term.slug );
                html += '</label> ';
            } );

            html += '</td></tr>';
        } );

        html += '</tbody></table>';
        html += '<input type="hidden" id="wt-map-hidden-' + escHtml( slot ) + '" name="trendyol_global_attr_' + escHtml( slot ) + '_map" value="" />';

        $wrap.html( html );
        serializeMappingTable( slot );

        $wrap.off( 'change', 'input[type="checkbox"]' ).on( 'change', 'input[type="checkbox"]', function () {
            serializeMappingTable( slot );
        } );
    }

    function serializeMappingTable( slot ) {
        if ( ! attrState[ slot ].tyValues.length ) {
            return;
        }
        var map = getCurrentMap( slot );
        $( '#wt-map-hidden-' + slot ).val( JSON.stringify( map ) );
    }

    // =========================================================================
    // Sync Tasks
    // =========================================================================

    function initSyncTasks() {
        $( '.wt-run-sync-btn' ).on( 'click', function () {
            var $btn     = $( this );
            var action   = $btn.data( 'action' );
            var $spinner = $btn.siblings( '.spinner' );
            var $result  = $btn.siblings( '.wt-sync-result' );

            if ( 'trendyol_sync_brands' === action ) {
                var $sidebarBtn = $( '#wt-brand-sync' );
                if ( $sidebarBtn.length ) {
                    $sidebarBtn.trigger( 'click' );
                } else {
                    $result.addClass( 'wt-notice--error' ).html( 'Brand sync control is not available on this page.' ).show();
                }
                return;
            }

            $btn.prop( 'disabled', true );
            $spinner.addClass( 'is-active' );
            $result.hide().removeClass( 'wt-notice--success wt-notice--error' ).empty();

            $.post( wooTrendyolAdmin.ajaxUrl, {
                action: action,
                nonce:  wooTrendyolAdmin.nonce
            } )
            .done( function ( response ) {
                if ( response.success ) {
                    $result.addClass( 'wt-notice--success' ).html( response.data.message ).show();
                } else {
                    $result.addClass( 'wt-notice--error' ).html( response.data.message || 'An error occurred.' ).show();
                }
            } )
            .fail( function () {
                $result.addClass( 'wt-notice--error' ).html( 'Request failed. Check your API credentials and connection.' ).show();
            } )
            .always( function () {
                $btn.prop( 'disabled', false );
                $spinner.removeClass( 'is-active' );
            } );
        } );
    }

} )( jQuery );
