/**
 * WooCommerce Trendyol Integration — Taxonomy Cascading Dropdowns
 *
 * Builds and manages the multi-level cascading category selection on the
 * WooCommerce product_cat taxonomy screens (Add New / Edit Category).
 *
 * Data is provided by wooTrendyolTaxonomy (localised via wp_localize_script):
 *  - cascade  {Object}  Cascade lookup: parentKey → [ { name, id? }, … ]
 *  - flatMap  {Object}  Flat map: categoryId → [ level0, level1, … ]
 *  - sep      {string}  Path separator ('|||')
 *  - labels   {Object}  Translatable UI strings
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 */

/* global wooTrendyolTaxonomy, jQuery */

( function ( $ ) {
    'use strict';

    // -----------------------------------------------------------------------
    // Module-level references
    // -----------------------------------------------------------------------

    /** @type {Object} Cascade lookup table: parentKey → children array */
    var cascade;

    /** @type {Object} Flat map: categoryId → path array */
    var flatMap;

    /** @type {string} Path separator used in stored meta values */
    var SEP;

    /** @type {jQuery} The container that holds all dropdown <select> elements */
    var $container;

    /** @type {jQuery} Hidden input storing the final resolved Trendyol category ID */
    var $idInput;

    /** @type {jQuery} Hidden input storing the full path string */
    var $pathInput;

    /** @type {jQuery} Visible span that shows the human-readable path */
    var $pathDisplay;

    // -----------------------------------------------------------------------
    // Initialise on DOM ready
    // -----------------------------------------------------------------------

        $( function () {
        // Bail if the localised data is not present (not on a taxonomy page).
        if ( typeof wooTrendyolTaxonomy === 'undefined' ) {
            return;
        }

        cascade     = wooTrendyolTaxonomy.cascade  || {};
        flatMap     = wooTrendyolTaxonomy.flatMap   || {};
        SEP         = wooTrendyolTaxonomy.sep       || '|||';

        $( '.wt-taxonomy-mapping-box' ).each( function () {
            var $wrapper = $( this );

            var $container   = $wrapper.find( '[id="trendyol-dropdowns-container"]' );
            var $idInput     = $wrapper.find( '[id="trendyol_category_id"]' );
            var $pathInput   = $wrapper.find( '[id="trendyol_category_path"]' );
            var $pathDisplay = $wrapper.find( '[id="trendyol-selected-path"]' );
            var $directIdBtn = $wrapper.find( '[id="wt-direct-category-btn"]' );
            var $directIdInput = $wrapper.find( '[id="wt-direct-category-id"]' );

            if ( ! $container.length ) {
                return;
            }

            init();

            $directIdBtn.on( 'click', function ( e ) {
                e.preventDefault();
                var inputId = $directIdInput.val().trim();
                if ( ! inputId ) {
                    return;
                }

                if ( flatMap && flatMap[ inputId ] ) {
                    var pathArray = flatMap[ inputId ];
                    updateHiddenFields( inputId, pathArray );
                    init(); // Rebuild the dropdowns based on the new ID
                    
                    // Automatically resync attributes when leaf category is selected.
                    $( '#wt-resync-attrs-btn' ).trigger( 'click' );
                } else {
                    alert( wooTrendyolTaxonomy.labels && wooTrendyolTaxonomy.labels.idNotFound ? wooTrendyolTaxonomy.labels.idNotFound : 'Category ID not found in the Trendyol mapping.' );
                }
            } );

            // Attributes sync is only for the single-edit screen, 
            // but we bind it on the global element '#wt-resync-attrs-btn' once outside the loop?
            // Actually, the button is global so binding it multiple times would be bad.
            // But wait, the button is only on the single-edit screen.
            // Let's keep the internal functions here.

            function init() {
                $container.empty();
                var currentId       = $idInput.val();
                var preselectedPath = [];
                if ( currentId && flatMap[ currentId ] ) {
                    preselectedPath = flatMap[ currentId ];
                }
                buildDropdown( 0, '__root__', preselectedPath );
            }

            function buildDropdown( levelIndex, parentKey, preselectedPath ) {
                if ( ! cascade[ parentKey ] || cascade[ parentKey ].length === 0 ) {
                    return;
                }
                var children        = cascade[ parentKey ];
                var selectId        = 'trendyol-level-' + levelIndex + '-' + Math.floor(Math.random() * 1000);
                var preselectedValue = preselectedPath.length > levelIndex ? preselectedPath[ levelIndex ] : null;
                var $select = $( '<select>', {
                    id:               selectId,
                    'class':          'trendyol-category-level',
                    'data-level':     levelIndex,
                    'data-parent-key': parentKey,
                } );
                $select.append( $( '<option>', {
                    value: '',
                    text:  sprintf( wooTrendyolTaxonomy.labels.selectLevel, levelIndex + 1 ),
                } ) );
                $.each( children, function ( index, item ) {
                    var $option = $( '<option>', {
                        value:      item.name,
                        text:       item.name,
                        'data-id':  item.id || '',
                    } );
                    if ( item.name === preselectedValue ) {
                        $option.prop( 'selected', true );
                    }
                    $select.append( $option );
                } );
                $container.append( $select );
                $select.on( 'change', handleDropdownChange );
                if ( preselectedValue ) {
                    var nextKey = buildNextKey( parentKey, preselectedValue );
                    buildDropdown( levelIndex + 1, nextKey, preselectedPath );
                }
            }

            function handleDropdownChange( e ) {
                var $select       = $( e.target );
                var levelIndex    = parseInt( $select.data( 'level' ), 10 );
                var selectedValue = $select.val();
                var $selectedOpt  = $select.find( 'option:selected' );
                var leafId        = $selectedOpt.data( 'id' );

                $container.find( 'select' ).filter( function () {
                    return parseInt( $( this ).data( 'level' ), 10 ) > levelIndex;
                } ).remove();

                if ( ! selectedValue ) {
                    updateHiddenFields( '', [] );
                    return;
                }

                var currentPath = collectCurrentPath();
                var nextKey = currentPath.join( SEP );

                if ( leafId ) {
                    updateHiddenFields( String( leafId ), currentPath );
                    $( '#wt-resync-attrs-btn' ).trigger( 'click' );
                } else {
                    updateHiddenFields( '', currentPath );
                    buildDropdown( levelIndex + 1, nextKey, [] );
                }
            }

            function updateHiddenFields( id, pathArray ) {
                $idInput.val( id );
                $pathInput.val( pathArray.join( SEP ) );
                var $btn = $( '#wt-resync-attrs-btn' );
                if ( pathArray.length > 0 ) {
                    var displayStr = pathArray.join( ' > ' );
                    if ( id ) {
                        displayStr += ' (ID: ' + id + ')';
                        $btn.show();
                    } else {
                        displayStr += ' (' + wooTrendyolTaxonomy.labels.incomplete + ')';
                        $btn.hide();
                    }
                    $pathDisplay.text( displayStr );
                } else {
                    $pathDisplay.text( '' );
                    $btn.hide();
                }
            }

            function collectCurrentPath() {
                var path = [];
                $container.find( 'select' ).each( function () {
                    var val = $( this ).val();
                    if ( val ) {
                        path.push( val );
                    }
                } );
                return path;
            }

            function buildNextKey( parentKey, selectedValue ) {
                if ( '__root__' === parentKey ) {
                    return selectedValue;
                }
                return parentKey + SEP + selectedValue;
            }
        } );

        // Global attributes binding (should only be done once per page)
        $( '#wt-resync-attrs-btn' ).on( 'click', function ( e ) {
            e.preventDefault();
            // In the taxonomy edit screen, the main idInput is the only one we care about for attributes
            var $mainIdInput = $( '.wt-taxonomy-mapping-box' ).not('#wt-bulk-modal .wt-taxonomy-mapping-box').find( '[id="trendyol_category_id"]' );
            if ( ! $mainIdInput.length ) $mainIdInput = $( '#trendyol_category_id' );
            
            var categoryId = $mainIdInput.val();
            if ( ! categoryId ) {
                return;
            }

            var $btn = $( this );
            var $spinner = $( '#wt-resync-attrs-spinner' );

            $btn.prop( 'disabled', true );
            $spinner.addClass( 'is-active' );

            var termId = 0;
            var urlParams = new URLSearchParams( window.location.search );
            if ( urlParams.has( 'tag_ID' ) ) {
                termId = parseInt( urlParams.get( 'tag_ID' ), 10 );
            }

            $.ajax( {
                url:      wooTrendyolTaxonomy.ajaxUrl,
                type:     'POST',
                dataType: 'json',
                data: {
                    action:      'trendyol_sync_single_category_attributes',
                    category_id: categoryId,
                    term_id:     termId,
                    nonce:       wooTrendyolTaxonomy.nonce,
                },
                success: function ( response ) {
                    $btn.prop( 'disabled', false );
                    $spinner.removeClass( 'is-active' );

                    if ( response.success ) {
                        $( '#wt-attributes-wrapper' ).html( response.data.html );
                    } else {
                        alert( response.data.message || 'Error occurred.' );
                    }
                },
                error: function () {
                    $btn.prop( 'disabled', false );
                    $spinner.removeClass( 'is-active' );
                    alert( 'An error occurred while syncing attributes.' );
                }
            } );
        } );

        $( '#wt-attributes-wrapper' ).on( 'change', 'select[id^="trendyol_attr_"]', function ( e ) {
            var $select = $( this );
            var attrId = $select.attr( 'id' ).replace( 'trendyol_attr_', '' );
            var wcAttr = $select.val();

            var $td = $select.parent();
            $td.find( '.wt-value-mappings' ).remove();

            if ( ! wcAttr ) {
                return;
            }

            var $box = $( '#wt-attributes-wrapper' ).find( '.wt-taxonomy-attributes-box' );
            var reqAttrsData = $box.data( 'required-attributes' ) || [];
            var valueMappings = $box.data( 'value-mappings' ) || {};

            var attrData = null;
            $.each( reqAttrsData, function( i, item ) {
                if ( String( item.id ) === String( attrId ) ) {
                    attrData = item;
                    return false;
                }
            } );

            if ( ! attrData || ! attrData.values || attrData.values.length === 0 ) {
                return;
            }

            $.ajax( {
                url:      wooTrendyolTaxonomy.ajaxUrl,
                type:     'POST',
                dataType: 'json',
                data: {
                    action:  'trendyol_get_wc_attribute_terms',
                    wc_attr: wcAttr,
                    nonce:   wooTrendyolTaxonomy.nonce,
                },
                success: function ( response ) {
                    if ( response.success && response.data.terms && response.data.terms.length > 0 ) {
                        var terms = response.data.terms;
                        var savedMap = valueMappings[ attrId ] || {};

                        $td.find( '.wt-value-mappings, .wt-custom-values-notice' ).remove();

                        if ( attrData.values && attrData.values.length > 0 ) {
                            var html = '<div class="wt-value-mappings" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; max-height: 250px; overflow-y: auto;">';
                            html += '<strong>Map Values:</strong>';
                            html += '<table style="width: 100%; border-collapse: collapse; margin-top: 5px;">';

                            $.each( terms, function( j, term ) {
                                var savedVal = savedMap[ term.slug ] || '';

                                if ( ! savedVal ) {
                                    var termNameLower = term.name.toLowerCase().trim();
                                    $.each( attrData.values, function( k, tyVal ) {
                                        var tyValNameLower = tyVal.name.toLowerCase().trim();
                                        if ( termNameLower === tyValNameLower ) {
                                            savedVal = tyVal.id;
                                            return false;
                                        }
                                    } );
                                }

                                html += '<tr style="border-bottom: 1px solid #eee;">';
                                html += '<td style="padding: 5px 0; font-size: 12px;">' + term.name + '</td>';
                                html += '<td style="padding: 5px 0; text-align: right;">';
                                html += '<select name="trendyol_attribute_value_mappings[' + attrId + '][' + term.slug + ']" style="font-size: 12px; min-width: 250px; max-width: 100%;">';
                                html += '<option value="">-- Select Trendyol Value --</option>';

                                $.each( attrData.values, function( k, tyVal ) {
                                    var selectedAttr = String( tyVal.id ) === String( savedVal ) ? ' selected="selected"' : '';
                                    html += '<option value="' + tyVal.id + '"' + selectedAttr + '>' + tyVal.name + '</option>';
                                } );

                                html += '</select>';
                                html += '</td>';
                                html += '</tr>';
                            } );

                            html += '</table>';
                            html += '</div>';

                            $td.append( html );
                        } else if ( attrData.allowCustom ) {
                            var html = '<div class="wt-custom-values-notice" style="margin-top: 10px; padding: 8px 12px; background: #f0f6fb; border-left: 4px solid #11a0d2; font-size: 11px; color: #50575e;">';
                            html += 'This attribute allows custom values. Individual value mapping is not required; your WooCommerce term names will be sent directly.';
                            html += '</div>';
                            $td.append( html );
                        }
                    }
                }
            } );
        } );

        function sprintf( template, value ) {
            return template.replace( '%d', value );
        }

    } );

} )( jQuery );
