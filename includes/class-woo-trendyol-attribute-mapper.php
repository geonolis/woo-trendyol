<?php
/**
 * Attribute Mapper — resolves Trendyol required attributes for a product.
 *
 * Fetches the category attribute schema, filters to required-only attributes,
 * applies global mappings (gender, age group, brand, character) from plugin
 * settings, and maps remaining attributes from WooCommerce product attributes.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Woo_Trendyol_Attribute_Mapper
 *
 * Responsibilities:
 *  - Fetch and cache the Trendyol attribute schema for a given category.
 *  - Filter to required attributes only (optional attributes are omitted).
 *  - Resolve globally-mapped attributes (gender, age group, brand, character)
 *    from plugin settings before falling back to product-level attributes.
 *  - Map WooCommerce product attributes to Trendyol attributeId/attributeValueId pairs.
 *  - Return a ready-to-use attributes array for the product creation payload.
 *
 * Global attribute option keys (stored in WordPress options):
 *  - trendyol_global_attr_gender      — Trendyol attribute ID for "Gender"
 *  - trendyol_global_attr_gender_val  — Trendyol attribute value ID for the global gender
 *  - trendyol_global_attr_age         — Trendyol attribute ID for "Age Group"
 *  - trendyol_global_attr_age_val     — Trendyol attribute value ID for the global age group
 *  - trendyol_global_attr_brand       — Trendyol attribute ID for "Brand/Manufacturer"
 *  - trendyol_global_attr_brand_wc    — WooCommerce attribute slug to use as brand source
 *  - trendyol_global_attr_character   — Trendyol attribute ID for "Character/Hero"
 *  - trendyol_global_attr_character_wc— WooCommerce attribute slug to use as character source
 *
 * Per-category attribute mappings are stored as term meta on product_cat terms:
 *  - trendyol_attr_map — JSON: { "trendyol_attr_id": "wc_attribute_slug", … }
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_Attribute_Mapper {

    /**
     * Trendyol attribute IDs that are handled globally (not per-category).
     * These are the canonical Trendyol attribute names used for matching.
     *
     * @since  1.0.0
     * @access public
     * @var    array GLOBAL_ATTR_KEYWORDS
     */
    public const GLOBAL_ATTR_KEYWORDS = [
        'gender'    => [ 'cinsiyet', 'gender', 'φύλο' ],
        'age_group' => [ 'yaş grubu', 'age group', 'ηλικιακή ομάδα', 'ηλικιακη ομαδα' ],
        'age'       => [ 'yaş', 'age', 'ηλικία' ],
        'color'     => [ 'renk', 'color', 'χρώμα' ],
        'brand'     => [ 'marka', 'brand', 'μάρκα', 'manufacturer', 'κατασκευαστ' ],
        'character' => [ 'karakter', 'character', 'χαρακτήρας', 'hero', 'license' ],
    ];

    public const GLOBAL_ATTR_NAMES = [
        'gender'    => [ 'cinsiyet', 'gender', 'φύλο' ],
        'age_group' => [ 'yaş grubu', 'age group', 'ηλικιακή ομάδα', 'ηλικιακη ομαδα' ],
        'age'       => [ 'yaş', 'age', 'ηλικία' ],
        'color'     => [ 'renk', 'color', 'χρώμα' ],
    ];

    /**
     * Shared API client instance.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_API_Client $api
     */
    private Woo_Trendyol_API_Client $api;

    /**
     * Shared logger instance.
     *
     * @since  1.0.0
     * @access private
     * @var    Woo_Trendyol_Logger $logger
     */
    private Woo_Trendyol_Logger $logger;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Initialise the attribute mapper.
     *
     * @since 1.0.0
     * @param Woo_Trendyol_API_Client $api    Shared API client.
     * @param Woo_Trendyol_Logger     $logger Shared logger.
     */
    public function __construct( Woo_Trendyol_API_Client $api, Woo_Trendyol_Logger $logger ) {
        $this->api    = $api;
        $this->logger = $logger;
    }

    // -----------------------------------------------------------------------
    // Main resolution method
    // -----------------------------------------------------------------------

    /**
     * Build the attributes array for a product creation payload.
     *
     * Resolution order for each required attribute:
     *  1. Global setting (gender, age, brand, character) — if the attribute
     *     name matches a global slot and a global value is configured.
     *  2. Per-category attribute mapping (term meta on the product_cat term).
     *  3. WooCommerce product attribute with the same slug as the Trendyol
     *     attribute name (case-insensitive, normalised).
     *
     * Optional attributes are always skipped.
     *
     * @since 1.0.0
     * @param WC_Product $product     The WooCommerce product.
     * @param int        $category_id Trendyol leaf-level category ID.
     * @param int        $term_id     WooCommerce product_cat term ID (for per-category mapping).
     * @return array|WP_Error Array of attribute objects ready for the Trendyol payload, or WP_Error if a required attribute is missing.
     *               Each object: [ 'attributeId' => int, 'attributeValueId' => int ]
     *               or           [ 'attributeId' => int, 'customAttributeValue' => string ]
     */
    public function build_attributes( WC_Product $product, int $category_id, int $term_id ): array|WP_Error {
        // Fetch the category attribute schema.
        $schema = $this->api->get_category_attributes( $category_id );

        if ( is_wp_error( $schema ) ) {
            $this->logger->warning(
                sprintf(
                    'Could not fetch attributes for category %d: %s',
                    $category_id,
                    $schema->get_error_message()
                )
            );
            return [];
        }

        $category_attributes = $schema['categoryAttributes'] ?? [];

        if ( empty( $category_attributes ) ) {
            return [];
        }

        // Load global settings.
        $global_settings = $this->load_global_settings();

        // Load per-category attribute mapping from term meta.
        $category_map = $this->load_category_attribute_map( $term_id );

        // Load per-category attribute value mapping from term meta.
        $category_value_map = $this->load_category_attribute_value_map( $term_id );

        // Load WooCommerce product attributes (keyed by normalised slug).
        $wc_attributes = $this->load_wc_attributes( $product );

        $result = [];

        foreach ( $category_attributes as $cat_attr ) {
            $attr_id   = (int) ( $cat_attr['attribute']['id']   ?? 0 );
            $attr_name = (string) ( $cat_attr['attribute']['name'] ?? '' );
            
            $is_required            = ! empty( $cat_attr['required'] );
            $is_category_mapped     = ! empty( $category_map[ (string) $attr_id ] );
            $is_dyn_globally_mapped = ! empty( get_option( 'trendyol_global_attr_' . $attr_id . '_wc', '' ) );
            $is_slot_mapped         = ( null !== $this->get_global_slot( $attr_name ) );

            // Include all required attributes, plus any optional attributes that are mapped globally or per-category.
            if ( ! $is_required && ! $is_category_mapped && ! $is_dyn_globally_mapped && ! $is_slot_mapped ) {
                continue;
            }

            if ( ! $attr_id ) {
                continue;
            }

            $allow_custom = ! empty( $cat_attr['allowCustom'] );
            $attr_values  = $cat_attr['attributeValues'] ?? [];

            // Attempt to resolve the attribute value.
            $resolved = $this->resolve_attribute(
                $attr_id,
                $attr_name,
                $attr_values,
                $allow_custom,
                $global_settings,
                $category_map,
                $category_value_map,
                $wc_attributes,
                $product
            );

            // If color resolution failed, fall back to "Πολύχρωμο" (Multi-color)
            if ( null === $resolved ) {
                $is_color_attr = false;
                $attr_name_lower = mb_strtolower( trim( $attr_name ) );
                foreach ( self::GLOBAL_ATTR_NAMES['color'] as $keyword ) {
                    if ( mb_stripos( $attr_name_lower, $keyword ) !== false ) {
                        $is_color_attr = true;
                        break;
                    }
                }

                if ( $is_color_attr ) {
                    if ( $allow_custom ) {
                        $resolved = [ 'attributeId' => $attr_id, 'customAttributeValue' => 'Πολύχρωμο' ];
                    } else {
                        // Ensure we have values
                        if ( empty( $attr_values ) ) {
                            $values_res = $this->api->get_attribute_values( $category_id, $attr_id );
                            if ( ! is_wp_error( $values_res ) && ! empty( $values_res['content'] ) ) {
                                foreach ( $values_res['content'] as $v ) {
                                    $attr_values[] = [
                                        'id'   => (int) $v['attributeValueId'],
                                        'name' => (string) $v['attributeValue'],
                                    ];
                                }
                            }
                        }

                        // Search for match by name or ID
                        foreach ( $attr_values as $av ) {
                            $av_name_lower = mb_strtolower( trim( $av['name'] ) );
                            if ( $av_name_lower === 'πολύχρωμο' || $av_name_lower === 'çok renkli' || $av['id'] == 686230 ) {
                                $resolved = [ 'attributeId' => $attr_id, 'attributeValueId' => (int) $av['id'] ];
                                break;
                            }
                        }
                    }
                }
            }

            if ( null !== $resolved ) {
                $result[] = $resolved;
            } else {
                if ( ! $is_required ) {
                    continue;
                }

                $error_message = sprintf(
                    __( 'Missing required attribute: "%s" (ID %d). Please map this attribute in the category settings or globally.', 'woo-trendyol' ),
                    $attr_name,
                    $attr_id
                );
                
                $this->logger->warning(
                    sprintf(
                        'Could not resolve required attribute "%s" (ID %d) for product #%d.',
                        $attr_name,
                        $attr_id,
                        $product->get_id()
                    )
                );
                
                return new WP_Error( 'missing_required_attribute', $error_message );
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Attribute schema helpers (used by the admin UI)
    // -----------------------------------------------------------------------

    /**
     * Fetch only the required attributes for a category (for admin display).
     *
     * @since 1.0.0
     * @param int $category_id Trendyol leaf-level category ID.
     * @return array Array of required attribute objects, or empty array on failure.
     */
    public function get_required_attributes( int $category_id ): array {
        $schema = $this->api->get_category_attributes( $category_id );

        if ( is_wp_error( $schema ) ) {
            return [];
        }

        return array_values(
            array_filter(
                $schema['categoryAttributes'] ?? [],
                static fn( $a ) => ! empty( $a['required'] )
            )
        );
    }

    /**
     * Check whether a given attribute name fuzzy-matches a global attribute slot.
     *
     * @since 1.0.0
     * @param string $attr_name Trendyol attribute name.
     * @return string|null Global slot key ('gender', 'age', 'brand', 'character') or null.
     */
    public function get_global_slot( string $attr_name ): ?string {
        $normalised = mb_strtolower( trim( $attr_name ) );

        foreach ( self::GLOBAL_ATTR_KEYWORDS as $slot => $keywords ) {
            foreach ( $keywords as $keyword ) {
                if ( mb_stripos( $normalised, mb_strtolower( $keyword ) ) !== false ) {
                    return $slot;
                }
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Private resolution helpers
    // -----------------------------------------------------------------------

    /**
     * Attempt to resolve a single attribute value using the priority chain.
     *
     * @since  1.0.0
     * @access private
     * @param  int    $attr_id         Trendyol attribute ID.
     * @param  string $attr_name       Trendyol attribute name.
     * @param  array  $attr_values     Available predefined attribute values.
     * @param  bool   $allow_custom    Whether a custom string value is accepted.
     * @param  array  $global_settings Loaded global attribute settings.
     * @param  array  $category_map    Per-category WC attribute slug → Trendyol attr ID map.
     * @param  array  $wc_attributes   WC product attributes keyed by normalised slug.
     * @param  WC_Product $product     The product being processed.
     * @return array|null Resolved attribute object or null if unresolvable.
     */
    private function resolve_attribute(
        int $attr_id,
        string $attr_name,
        array $attr_values,
        bool $allow_custom,
        array $global_settings,
        array $category_map,
        array $category_value_map,
        array $wc_attributes,
        WC_Product $product
    ): ?array {
        // --- Priority 1: Per-category mapping ---
        // The category map stores: trendyol_attr_id => wc_attribute_slug
        $mapped_wc_slug = $category_map[ (string) $attr_id ] ?? null;

        if ( $mapped_wc_slug ) {
            // First check if there is a category-level value mapping defined for this attribute
            // and the product's value for the WooCommerce attribute.
            $product_slugs = $this->get_product_term_slugs( $product, $mapped_wc_slug );
            $value_map = $category_value_map[ $attr_id ] ?? [];

            if ( ! empty( $value_map ) && ! empty( $product_slugs ) ) {
                foreach ( $product_slugs as $slug ) {
                    if ( isset( $value_map[ $slug ] ) ) {
                        return [ 'attributeId' => $attr_id, 'attributeValueId' => (int) $value_map[ $slug ] ];
                    }
                }
            }

            // Fall back to exact name matching if no custom value mapping was found
            $normalised_slug = $this->normalise_slug( $mapped_wc_slug );
            $wc_value        = $wc_attributes[ $normalised_slug ] ?? null;

            if ( empty( $wc_value ) ) {
                $direct_val = $product->get_attribute( $mapped_wc_slug );
                if ( ! empty( $direct_val ) ) {
                    $wc_value = $direct_val;
                }
            }

            if ( $wc_value ) {
                return $this->match_value( $attr_id, $wc_value, $attr_values, $allow_custom );
            }
        }

        // --- Priority 2: Dynamic Global settings ---
        $dyn_wc_slug = get_option( 'trendyol_global_attr_' . $attr_id . '_wc', '' );
        if ( ! empty( $dyn_wc_slug ) ) {
            $dyn_product_slugs = $this->get_product_term_slugs( $product, $dyn_wc_slug );
            if ( ! empty( $dyn_product_slugs ) ) {
                $raw_map = get_option( 'trendyol_global_attr_' . $attr_id . '_map', '' );
                $dyn_value_map = [];
                if ( $raw_map ) {
                    $decoded = json_decode( $raw_map, true );
                    $dyn_value_map = is_array( $decoded ) ? $decoded : [];
                }

                $is_term_key_map = false;
                if ( ! empty( $dyn_value_map ) ) {
                    $first_val = reset( $dyn_value_map );
                    if ( ! is_array( $first_val ) ) {
                        $is_term_key_map = true; // Format: { "term_slug": "ty_id" }
                    }
                }

                if ( $is_term_key_map ) {
                    foreach ( $dyn_product_slugs as $slug ) {
                        if ( isset( $dyn_value_map[ $slug ] ) && ! empty( $dyn_value_map[ $slug ] ) ) {
                            return [ 'attributeId' => $attr_id, 'attributeValueId' => (int) $dyn_value_map[ $slug ] ];
                        }
                    }
                } else {
                    // Format: { "ty_id": ["slug1", "slug2"] }
                    foreach ( $dyn_product_slugs as $slug ) {
                        foreach ( $dyn_value_map as $ty_val_id => $mapped_slugs ) {
                            if ( in_array( $slug, (array) $mapped_slugs, true ) ) {
                                return [ 'attributeId' => $attr_id, 'attributeValueId' => (int) $ty_val_id ];
                            }
                        }
                    }
                }

                if ( $allow_custom ) {
                    if ( 'dim_length' === $dyn_wc_slug ) {
                        $val = (string) $product->get_length();
                        if ( '' !== $val ) { return [ 'attributeId' => $attr_id, 'customAttributeValue' => $val ]; }
                    } elseif ( 'dim_width' === $dyn_wc_slug ) {
                        $val = (string) $product->get_width();
                        if ( '' !== $val ) { return [ 'attributeId' => $attr_id, 'customAttributeValue' => $val ]; }
                    } elseif ( 'dim_height' === $dyn_wc_slug ) {
                        $val = (string) $product->get_height();
                        if ( '' !== $val ) { return [ 'attributeId' => $attr_id, 'customAttributeValue' => $val ]; }
                    } elseif ( 'dim_weight' === $dyn_wc_slug ) {
                        $val = (string) $product->get_weight();
                        if ( '' !== $val ) { return [ 'attributeId' => $attr_id, 'customAttributeValue' => $val ]; }
                    } elseif ( 0 === strpos( $dyn_wc_slug, 'meta:' ) ) {
                        $meta_key = substr( $dyn_wc_slug, 5 );
                        $val = (string) get_post_meta( $product->get_id(), $meta_key, true );
                        if ( '' !== $val ) { return [ 'attributeId' => $attr_id, 'customAttributeValue' => $val ]; }
                    } else {
                        $term_names = wp_get_post_terms( $product->get_id(), $dyn_wc_slug, [ 'fields' => 'names' ] );
                        if ( ! is_wp_error( $term_names ) && ! empty( $term_names ) ) {
                            return [ 'attributeId' => $attr_id, 'customAttributeValue' => $term_names[0] ];
                        }
                        $attr_val = $product->get_attribute( $dyn_wc_slug );
                        if ( ! empty( $attr_val ) ) {
                            return [ 'attributeId' => $attr_id, 'customAttributeValue' => $attr_val ];
                        }
                    }
                }
            }
        }

        // --- Priority 2.5: Legacy Global settings (Brand/Character) ---
        $global_slot = $this->get_global_slot( $attr_name );
        if ( 'color' === $global_slot && $allow_custom ) {
            $global_slot = 'color_custom';
        }

        if ( $global_slot ) {
            $resolved = $this->resolve_from_global(
                $global_slot,
                $attr_id,
                $attr_values,
                $allow_custom,
                $global_settings,
                $wc_attributes,
                $product
            );

            if ( null !== $resolved ) {
                return $resolved;
            }
        }

        // --- Priority 3: WC attribute with matching name ---
        $normalised_attr = $this->normalise_slug( $attr_name );
        $wc_value        = $wc_attributes[ $normalised_attr ] ?? null;

        if ( $wc_value ) {
            return $this->match_value( $attr_id, $wc_value, $attr_values, $allow_custom );
        }

        return null;
    }

    /**
     * Resolve an attribute from the global settings slot.
     *
     * For 'gender' and 'age':
     *   Reads the product's term slug from the configured WC attribute, then
     *   looks it up in the saved value map (trendyol_value_id → [wc_term_slugs]).
     *   Returns the Trendyol attributeValueId whose mapped slugs include the
     *   product's term. Age supports many-to-one: multiple WC slugs can map to
     *   the same Trendyol value.
     *
     * For 'brand' and 'character':
     *   Reads the value from the configured WC attribute slug and matches it
     *   against the Trendyol attribute values list.
     *
     * @since  1.0.0
     * @access private
     */
    private function resolve_from_global(
        string $slot,
        int $attr_id,
        array $attr_values,
        bool $allow_custom,
        array $global_settings,
        array $wc_attributes,
        WC_Product $product
    ): ?array {
        switch ( $slot ) {
            case 'color_custom':
                $wc_slug = $global_settings['color_custom_wc'] ?? '';
                if ( empty( $wc_slug ) ) {
                    break;
                }
                $attr_val = $product->get_attribute( $wc_slug );
                if ( ! empty( $attr_val ) ) {
                    return [ 'attributeId' => $attr_id, 'customAttributeValue' => $attr_val ];
                }
                $term_names = wp_get_post_terms( $product->get_id(), $wc_slug, [ 'fields' => 'names' ] );
                if ( ( is_wp_error( $term_names ) || empty( $term_names ) ) && $product->get_parent_id() ) {
                    $term_names = wp_get_post_terms( $product->get_parent_id(), $wc_slug, [ 'fields' => 'names' ] );
                }
                if ( ! is_wp_error( $term_names ) && ! empty( $term_names ) ) {
                    return [ 'attributeId' => $attr_id, 'customAttributeValue' => $term_names[0] ];
                }
                break;

            case 'color':
                $wc_slug  = $global_settings[ $slot . '_wc' ]  ?? '';
                $value_map = $global_settings[ $slot . '_map' ] ?? [];

                if ( empty( $wc_slug ) || empty( $value_map ) ) {
                    break;
                }

                $product_slugs = $this->get_product_term_slugs( $product, $wc_slug );

                if ( empty( $product_slugs ) ) {
                    break;
                }

                foreach ( $product_slugs as $slug ) {
                    if ( isset( $value_map[ $slug ] ) && ! empty( $value_map[ $slug ] ) ) {
                        return [ 'attributeId' => $attr_id, 'attributeValueId' => (int) $value_map[ $slug ] ];
                    }
                }
                break;

            case 'gender':
            case 'age':
            case 'age_group':
                $wc_slug  = $global_settings[ $slot . '_wc' ]  ?? '';
                $value_map = $global_settings[ $slot . '_map' ] ?? [];

                if ( empty( $wc_slug ) || empty( $value_map ) ) {
                    break;
                }

                // Get the product's term slug(s) for the configured WC attribute.
                $product_slugs = $this->get_product_term_slugs( $product, $wc_slug );

                if ( empty( $product_slugs ) ) {
                    break;
                }

                // Walk the value map: { "trendyol_value_id": ["wc_slug", …], … }
                // Return the first Trendyol value whose mapped slugs intersect
                // with the product's term slugs.
                foreach ( $value_map as $ty_value_id => $mapped_slugs ) {
                    if ( ! is_array( $mapped_slugs ) ) {
                        $mapped_slugs = [ $mapped_slugs ];
                    }
                    $intersection = array_intersect( $product_slugs, $mapped_slugs );
                    if ( ! empty( $intersection ) ) {
                        return [ 'attributeId' => $attr_id, 'attributeValueId' => (int) $ty_value_id ];
                    }
                }
                break;

            case 'brand':
            case 'character':
                // Read value from the configured WC attribute slug.
                $wc_slug = $global_settings[ $slot . '_wc' ] ?? '';
                if ( $wc_slug ) {
                    $normalised = $this->normalise_slug( $wc_slug );
                    $wc_value   = $wc_attributes[ $normalised ] ?? null;

                    if ( $wc_value ) {
                        return $this->match_value( $attr_id, $wc_value, $attr_values, $allow_custom );
                    }

                    // Fallback: try the product's brand meta (WooCommerce Brands plugin).
                    if ( 'brand' === $slot ) {
                        $brand_terms = get_the_terms( $product->get_id(), 'product_brand' );
                        if ( ( ! $brand_terms || is_wp_error( $brand_terms ) ) && $product->get_parent_id() ) {
                            $brand_terms = get_the_terms( $product->get_parent_id(), 'product_brand' );
                        }
                        if ( $brand_terms && ! is_wp_error( $brand_terms ) ) {
                            $brand_name = $brand_terms[0]->name;
                            return $this->match_value( $attr_id, $brand_name, $attr_values, $allow_custom );
                        }
                    }
                }
                break;
        }

        return null;
    }

    /**
     * Get all term slugs for a given WooCommerce attribute taxonomy on a product.
     *
     * Handles both taxonomy-based (pa_*) and custom text attributes.
     * For text attributes the raw option string is returned as a single-element array.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Product $product  The WooCommerce product.
     * @param  string     $wc_slug  Taxonomy slug, e.g. 'pa_age'.
     * @return string[]  Array of term slugs (may be empty).
     */
    private function get_product_term_slugs( WC_Product $product, string $wc_slug ): array {
        if ( 'dim_length' === $wc_slug ) {
            $val = (string) $product->get_length();
            if ( '' === $val && $product->get_parent_id() ) {
                $parent = wc_get_product( $product->get_parent_id() );
                $val = $parent ? (string) $parent->get_length() : '';
            }
            return '' !== $val ? [ $val, sanitize_title( $val ) ] : [];
        }
        if ( 'dim_width' === $wc_slug ) {
            $val = (string) $product->get_width();
            if ( '' === $val && $product->get_parent_id() ) {
                $parent = wc_get_product( $product->get_parent_id() );
                $val = $parent ? (string) $parent->get_width() : '';
            }
            return '' !== $val ? [ $val, sanitize_title( $val ) ] : [];
        }
        if ( 'dim_height' === $wc_slug ) {
            $val = (string) $product->get_height();
            if ( '' === $val && $product->get_parent_id() ) {
                $parent = wc_get_product( $product->get_parent_id() );
                $val = $parent ? (string) $parent->get_height() : '';
            }
            return '' !== $val ? [ $val, sanitize_title( $val ) ] : [];
        }
        if ( 'dim_weight' === $wc_slug ) {
            $val = (string) $product->get_weight();
            if ( '' === $val && $product->get_parent_id() ) {
                $parent = wc_get_product( $product->get_parent_id() );
                $val = $parent ? (string) $parent->get_weight() : '';
            }
            return '' !== $val ? [ $val, sanitize_title( $val ) ] : [];
        }
        if ( 0 === strpos( $wc_slug, 'meta:' ) ) {
            $meta_key = substr( $wc_slug, 5 );
            $val = (string) get_post_meta( $product->get_id(), $meta_key, true );
            if ( '' === $val && $product->get_parent_id() ) {
                $val = (string) get_post_meta( $product->get_parent_id(), $meta_key, true );
            }
            return '' !== $val ? [ $val, sanitize_title( $val ) ] : [];
        }

        $attribute = $product->get_attribute( $wc_slug );

        // get_attribute() returns a comma-separated string for text attrs or single value for variation.
        if ( ! empty( $attribute ) && ! taxonomy_exists( $wc_slug ) ) {
            return array_map( 'sanitize_title', explode( ',', $attribute ) );
        }

        // Taxonomy-based attribute: fetch term slugs.
        $terms = wc_get_product_terms( $product->get_id(), $wc_slug, [ 'fields' => 'slugs' ] );
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            return $terms;
        }

        // If variation, check variation attribute directly (which might be term slug).
        if ( ! empty( $attribute ) ) {
            return array_map( 'sanitize_title', explode( ',', $attribute ) );
        }

        // Fall back to parent product terms if available.
        if ( $product->get_parent_id() ) {
            $parent_terms = wc_get_product_terms( $product->get_parent_id(), $wc_slug, [ 'fields' => 'slugs' ] );
            if ( ! empty( $parent_terms ) && ! is_wp_error( $parent_terms ) ) {
                return $parent_terms;
            }
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $parent_attr = $parent->get_attribute( $wc_slug );
                if ( ! empty( $parent_attr ) ) {
                    return array_map( 'sanitize_title', explode( ',', $parent_attr ) );
                }
            }
        }

        return is_array( $terms ) ? $terms : [];
    }

    /**
     * Match a string value against the predefined Trendyol attribute values list.
     *
     * Tries exact match first, then case-insensitive match.
     * If allowCustom is true and no match is found, returns a customAttributeValue entry.
     *
     * @since  1.0.0
     * @access private
     * @param  int    $attr_id     Trendyol attribute ID.
     * @param  string $value       The value string to match.
     * @param  array  $attr_values Predefined attribute values [ { id, name }, … ].
     * @param  bool   $allow_custom Whether custom string values are accepted.
     * @return array|null Resolved attribute object or null.
     */
    private function match_value( int $attr_id, string $value, array $attr_values, bool $allow_custom ): ?array {
        $value_lower = strtolower( trim( $value ) );

        // Exact match.
        foreach ( $attr_values as $av ) {
            if ( strtolower( trim( $av['name'] ) ) === $value_lower ) {
                return [ 'attributeId' => $attr_id, 'attributeValueId' => (int) $av['id'] ];
            }
        }

        // Custom value fallback.
        if ( $allow_custom && ! empty( $value ) ) {
            return [ 'attributeId' => $attr_id, 'customAttributeValue' => $value ];
        }

        return null;
    }

    /**
     * Load global attribute settings from WordPress options.
     *
     * @since  1.0.0
     * @access private
     * @return array Associative array of global setting values.
     */
    private function load_global_settings(): array {
        // Decode the JSON value maps stored as option strings.
        $decode_map = static function ( string $opt_key ): array {
            $raw = get_option( $opt_key, '' );
            if ( empty( $raw ) ) {
                return [];
            }
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : [];
        };

        return [
            // Gender: which WC attribute holds gender, and the value map.
            'gender_wc'      => get_option( 'trendyol_global_attr_gender_wc',  '' ),
            'gender_map'     => $decode_map( 'trendyol_global_attr_gender_map' ),
            // Age: which WC attribute holds age, and the value map.
            'age_wc'         => get_option( 'trendyol_global_attr_age_wc',     '' ),
            'age_map'        => $decode_map( 'trendyol_global_attr_age_map' ),
            // Age group: which WC attribute holds age group, and the value map.
            'age_group_wc'   => get_option( 'trendyol_global_attr_age_group_wc',     '' ),
            'age_group_map'  => $decode_map( 'trendyol_global_attr_age_group_map' ),
            // Color (Predefined): which WC attribute holds color, and the value map.
            'color_wc'           => get_option( 'trendyol_global_attr_color_wc',   '' ),
            'color_map'          => $decode_map( 'trendyol_global_attr_color_map' ),
            // Color (Custom): which WC attribute holds custom color, and the value map.
            'color_custom_wc'    => get_option( 'trendyol_global_attr_color_custom_wc',   '' ),
            'color_custom_map'   => $decode_map( 'trendyol_global_attr_color_custom_map' ),
            // Brand and character: WC attribute slugs (unchanged).
            'brand_wc'           => get_option( 'trendyol_global_attr_brand_wc',       '' ),
            'character_wc'       => get_option( 'trendyol_global_attr_character_wc',   '' ),
        ];
    }

    /**
     * Load the per-category attribute mapping from term meta.
     *
     * The map is stored as an array: [ trendyol_attr_id => wc_attribute_slug, … ]
     *
     * @since  1.0.0
     * @access private
     * @param  int $term_id WooCommerce product_cat term ID.
     * @return array Map array, or empty array if not set.
     */
    private function load_category_attribute_map( int $term_id ): array {
        if ( ! $term_id ) {
            return [];
        }

        $helper   = new Woo_Trendyol_Category_Helper();
        $mappings = $helper->get_inherited_term_meta( $term_id, '_trendyol_attribute_mappings' );

        return is_array( $mappings ) ? $mappings : [];
    }

    /**
     * Load the per-category attribute value mapping from term meta.
     *
     * @since  1.0.0
     * @access private
     * @param  int $term_id WooCommerce product_cat term ID.
     * @return array Map array, or empty array if not set.
     */
    private function load_category_attribute_value_map( int $term_id ): array {
        if ( ! $term_id ) {
            return [];
        }

        $helper   = new Woo_Trendyol_Category_Helper();
        $mappings = $helper->get_inherited_term_meta( $term_id, '_trendyol_attribute_value_mappings' );

        return is_array( $mappings ) ? $mappings : [];
    }

    /**
     * Load all WooCommerce product attributes as a normalised slug → value map.
     *
     * Handles both taxonomy-based attributes (pa_*) and custom text attributes.
     *
     * @since  1.0.0
     * @access private
     * @param  WC_Product $product The WooCommerce product.
     * @return array Normalised slug → first value string map.
     */
    private function load_wc_attributes( WC_Product $product ): array {
        $result = [];

        // For variations, return parent product attributes as baseline.
        if ( $product->is_type( 'variation' ) || ( $product->get_parent_id() ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $result = $this->load_wc_attributes( $parent );
            }
        }

        foreach ( $product->get_attributes() as $slug => $attribute ) {
            $normalised = $this->normalise_slug( $slug );
            $value      = '';

            if ( is_object( $attribute ) && method_exists( $attribute, 'is_taxonomy' ) ) {
                if ( $attribute->is_taxonomy() ) {
                    $terms = wc_get_product_terms( $product->get_id(), $slug, [ 'fields' => 'names' ] );
                    $value = ! empty( $terms ) ? $terms[0] : '';
                } else {
                    $options = $attribute->get_options();
                    $value   = ! empty( $options ) ? $options[0] : '';
                }
            } elseif ( is_string( $attribute ) && '' !== $attribute ) {
                $value = $attribute;
                $tax_name = taxonomy_exists( $slug ) ? $slug : ( taxonomy_exists( 'pa_' . $slug ) ? 'pa_' . $slug : '' );
                if ( $tax_name ) {
                    $term = get_term_by( 'slug', $attribute, $tax_name );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $value = $term->name;
                    }
                }
            }

            if ( ! empty( $value ) ) {
                $result[ $normalised ] = $value;

                // Also index by the human-readable attribute label.
                $label = wc_attribute_label( $slug );
                if ( $label ) {
                    $result[ $this->normalise_slug( $label ) ] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Normalise a string for use as a lookup key.
     *
     * Strips the 'pa_' taxonomy prefix, lowercases, replaces hyphens/underscores
     * with spaces, and trims whitespace.
     *
     * @since  1.0.0
     * @access private
     * @param  string $slug Input string.
     * @return string Normalised string.
     */
    private function normalise_slug( string $slug ): string {
        $slug = preg_replace( '/^pa_/', '', $slug );
        $slug = strtolower( $slug );
        $slug = str_replace( [ '-', '_' ], ' ', $slug );
        return trim( $slug );
    }

    /**
     * Retrieve all custom (non-taxonomy) product attributes across WooCommerce products.
     *
     * @since 1.0.0
     * @return array Associative array of [ slug => label ].
     */
    public static function get_custom_product_attributes(): array {
        $cached = get_transient( 'trendyol_custom_product_attributes' );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
                '_product_attributes',
                '%is_taxonomy%0%'
            )
        );

        $custom_attrs = [];
        if ( ! empty( $results ) ) {
            foreach ( $results as $meta_value ) {
                $attrs = maybe_unserialize( $meta_value );
                if ( is_array( $attrs ) ) {
                    foreach ( $attrs as $key => $attr_data ) {
                        if ( empty( $attr_data['is_taxonomy'] ) ) {
                            $slug = sanitize_title( $key );
                            $name = ! empty( $attr_data['name'] ) ? $attr_data['name'] : $key;
                            $custom_attrs[ $slug ] = $name;
                        }
                    }
                }
            }
        }

        if ( empty( $custom_attrs ) ) {
            $results_all = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 200",
                    '_product_attributes'
                )
            );
            if ( ! empty( $results_all ) ) {
                foreach ( $results_all as $meta_value ) {
                    $attrs = maybe_unserialize( $meta_value );
                    if ( is_array( $attrs ) ) {
                        foreach ( $attrs as $key => $attr_data ) {
                            if ( empty( $attr_data['is_taxonomy'] ) ) {
                                $slug = sanitize_title( $key );
                                $name = ! empty( $attr_data['name'] ) ? $attr_data['name'] : $key;
                                $custom_attrs[ $slug ] = $name;
                            }
                        }
                    }
                }
            }
        }

        set_transient( 'trendyol_custom_product_attributes', $custom_attrs, 12 * HOUR_IN_SECONDS );
        return $custom_attrs;
    }
}
