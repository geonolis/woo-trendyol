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
        $container  = $( '#trendyol-dropdowns-container' );
        $idInput    = $( '#trendyol_category_id' );
        $pathInput  = $( '#trendyol_category_path' );
        $pathDisplay = $( '#trendyol-selected-path' );

        if ( ! $container.length ) {
            return;
        }

        init();
    } );

    // -----------------------------------------------------------------------
    // Initialisation
    // -----------------------------------------------------------------------

    /**
     * Initialise the dropdown cascade.
     *
     * Reads the existing category ID from the hidden input (set by PHP for
     * the Edit screen) and pre-selects all levels if a mapping exists.
     */
    function init() {
        $container.empty();

        var currentId       = $idInput.val();
        var preselectedPath = [];

        // If an existing mapping is stored, look up the full path array.
        if ( currentId && flatMap[ currentId ] ) {
            preselectedPath = flatMap[ currentId ];
        }

        // Build the root level.
        buildDropdown( 0, '__root__', preselectedPath );
    }

    // -----------------------------------------------------------------------
    // Dropdown builder
    // -----------------------------------------------------------------------

    /**
     * Build and append a <select> dropdown for a given cascade level.
     *
     * @param {number} levelIndex     Zero-based depth level.
     * @param {string} parentKey      Key into the cascade object for this level's children.
     * @param {Array}  preselectedPath Array of category names representing the pre-selected path.
     */
    function buildDropdown( levelIndex, parentKey, preselectedPath ) {
        // Stop if there are no children for this key (leaf node reached).
        if ( ! cascade[ parentKey ] || cascade[ parentKey ].length === 0 ) {
            return;
        }

        var children        = cascade[ parentKey ];
        var selectId        = 'trendyol-level-' + levelIndex;
        var preselectedValue = preselectedPath.length > levelIndex
            ? preselectedPath[ levelIndex ]
            : null;

        // Build the <select> element.
        var $select = $( '<select>', {
            id:               selectId,
            'class':          'trendyol-category-level',
            'data-level':     levelIndex,
            'data-parent-key': parentKey,
        } );

        // Default / placeholder option.
        $select.append( $( '<option>', {
            value: '',
            text:  sprintf( wooTrendyolTaxonomy.labels.selectLevel, levelIndex + 1 ),
        } ) );

        // Populate options from the cascade data.
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

        // Append to the container and bind the change handler.
        $container.append( $select );
        $select.on( 'change', handleDropdownChange );

        // If a value was pre-selected, recursively build the next level.
        if ( preselectedValue ) {
            var nextKey = buildNextKey( parentKey, preselectedValue );
            buildDropdown( levelIndex + 1, nextKey, preselectedPath );
        }
    }

    // -----------------------------------------------------------------------
    // Change handler
    // -----------------------------------------------------------------------

    /**
     * Handle a change event on any level dropdown.
     *
     * Removes all deeper dropdowns, then either builds the next level
     * (for non-leaf selections) or updates the hidden fields (for leaf selections).
     *
     * @param {Event} e The jQuery change event.
     */
    function handleDropdownChange( e ) {
        var $select       = $( e.target );
        var levelIndex    = parseInt( $select.data( 'level' ), 10 );
        var selectedValue = $select.val();
        var $selectedOpt  = $select.find( 'option:selected' );
        var leafId        = $selectedOpt.data( 'id' );

        // Remove all dropdowns deeper than the current level.
        $container.find( 'select' ).filter( function () {
            return parseInt( $( this ).data( 'level' ), 10 ) > levelIndex;
        } ).remove();

        // User cleared the selection.
        if ( ! selectedValue ) {
            updateHiddenFields( '', [] );
            return;
        }

        // Build the current path array from all visible dropdowns.
        var currentPath = collectCurrentPath();

        // Build the next cascade key.
        var nextKey = currentPath.join( SEP );

        if ( leafId ) {
            // Leaf node reached — update hidden fields with the resolved ID.
            updateHiddenFields( String( leafId ), currentPath );
        } else {
            // Intermediate node — update path display (incomplete) and build next level.
            updateHiddenFields( '', currentPath );
            buildDropdown( levelIndex + 1, nextKey, [] );
        }
    }

    // -----------------------------------------------------------------------
    // Hidden field updater
    // -----------------------------------------------------------------------

    /**
     * Update the hidden input fields and the visible path display.
     *
     * @param {string} id        Trendyol category ID (empty if not yet at a leaf).
     * @param {Array}  pathArray Array of selected category names.
     */
    function updateHiddenFields( id, pathArray ) {
        $idInput.val( id );
        $pathInput.val( pathArray.join( SEP ) );

        if ( pathArray.length > 0 ) {
            var displayStr = pathArray.join( ' > ' );

            if ( id ) {
                displayStr += ' (ID: ' + id + ')';
            } else {
                displayStr += ' (' + wooTrendyolTaxonomy.labels.incomplete + ')';
            }

            $pathDisplay.text( displayStr );
        } else {
            $pathDisplay.text( '' );
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Collect the currently selected values from all visible dropdowns.
     *
     * @returns {Array} Array of selected category name strings.
     */
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

    /**
     * Build the cascade lookup key for the next dropdown level.
     *
     * The root level uses the selected value directly; deeper levels
     * concatenate the parent key with the selected value using SEP.
     *
     * @param  {string} parentKey     The parent's cascade key.
     * @param  {string} selectedValue The value selected in the current dropdown.
     * @returns {string} The next cascade key.
     */
    function buildNextKey( parentKey, selectedValue ) {
        if ( '__root__' === parentKey ) {
            return selectedValue;
        }
        return parentKey + SEP + selectedValue;
    }

    /**
     * Minimal sprintf implementation for single %d substitution.
     *
     * Used to format the "Select Category Level N" placeholder text.
     *
     * @param  {string} template String containing a single %d placeholder.
     * @param  {number} value    The integer to substitute.
     * @returns {string} Formatted string.
     */
    function sprintf( template, value ) {
        return template.replace( '%d', value );
    }

} )( jQuery );
