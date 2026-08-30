<?php
/**
 * Category Helper — resolves the Trendyol category ID for a WooCommerce product.
 *
 * Reads the term meta keys stored by the taxonomy mapper (trendyol_category_id
 * and trendyol_category_path) and applies a four-tier priority resolution to
 * determine the single Trendyol leaf-level category ID for any product.
 *
 * Resolution priority (highest → lowest):
 *  1. Product-level override  (_trendyol_category_id_override post meta)
 *  2. Yoast primary category  (_yoast_wpseo_primary_product_cat post meta)
 *  3. Deepest mapped category (most specific term that carries a Trendyol ID)
 *  4. First mapped category   (first term in the product's category list)
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
 * Class Woo_Trendyol_Category_Helper
 *
 * All methods are public so they can be called from the product sync class,
 * the admin class, and external code (e.g. WP All Export snippets).
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_Category_Helper {

    /**
     * Post meta key for the product-level Trendyol category override.
     *
     * @since  1.0.0
     * @var    string META_OVERRIDE
     */
    public const META_OVERRIDE = '_trendyol_category_id_override';

    /**
     * Term meta key used to store the Trendyol leaf-level category ID on a product_cat term.
     *
     * @since  1.0.0
     * @var    string TERM_META_ID
     */
    public const TERM_META_ID = 'trendyol_category_id';

    /**
     * Term meta key used to store the full category path string on a product_cat term.
     * Levels are separated by the '|||' delimiter.
     *
     * @since  1.0.0
     * @var    string TERM_META_PATH
     */
    public const TERM_META_PATH = 'trendyol_category_path';

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Resolve the Trendyol category ID for a given WooCommerce product.
     *
     * Applies the four-tier priority resolution described in the class docblock.
     * Returns an empty string when no mapping can be found.
     *
     * This method is intentionally named to match the WP All Export snippet
     * documented in the plugin guide:  [get_trendyol_category_id({ID})]
     *
     * @since  1.0.0
     * @param  int $post_id WooCommerce product post ID.
     * @return string  Trendyol category ID (numeric string) or empty string.
     */
    public function get_trendyol_category_id( int $post_id ): string {
        // ---- Priority 1: product-level override ----
        $override = get_post_meta( $post_id, self::META_OVERRIDE, true );
        if ( ! empty( $override ) ) {
            return (string) $override;
        }

        // ---- Priority 2: Yoast SEO primary category ----
        $primary_id = $this->get_yoast_primary_category_id( $post_id );
        if ( $primary_id ) {
            $id = $this->get_inherited_term_meta( $primary_id, self::TERM_META_ID );
            if ( ! empty( $id ) ) {
                return (string) $id;
            }
        }

        // ---- Priority 3 & 4: scan all assigned categories ----
        $category_id = $this->resolve_from_product_categories( $post_id );
        if ( ! empty( $category_id ) ) {
            return $category_id;
        }

        // ---- Variation fallback: resolve from parent product ----
        $parent_id = wp_get_post_parent_id( $post_id );
        if ( $parent_id && $parent_id !== $post_id ) {
            return $this->get_trendyol_category_id( $parent_id );
        }

        return '';
    }

    /**
     * Resolve the Trendyol category path string for a given WooCommerce product.
     *
     * Uses the same priority resolution as get_trendyol_category_id() but
     * returns the human-readable path (e.g. "Clothing > Women > Dresses").
     *
     * @since  1.0.0
     * @param  int $post_id WooCommerce product post ID.
     * @return string  Category path using " > " as separator, or empty string.
     */
    public function get_trendyol_category_path( int $post_id ): string {
        // ---- Priority 1: product-level override — look up path from terms ----
        $override_id = get_post_meta( $post_id, self::META_OVERRIDE, true );
        if ( ! empty( $override_id ) ) {
            $path = $this->get_path_by_category_id( (string) $override_id, $post_id );
            if ( ! empty( $path ) ) {
                return $path;
            }
        }

        // ---- Priority 2: Yoast SEO primary category ----
        $primary_id = $this->get_yoast_primary_category_id( $post_id );
        if ( $primary_id ) {
            $path = $this->get_inherited_term_meta( $primary_id, self::TERM_META_PATH );
            if ( ! empty( $path ) ) {
                return $this->normalise_path( (string) $path );
            }
        }

        // ---- Priority 3 & 4: scan all assigned categories ----
        $path = $this->resolve_path_from_product_categories( $post_id );
        if ( ! empty( $path ) ) {
            return $path;
        }

        // ---- Variation fallback: resolve from parent product ----
        $parent_id = wp_get_post_parent_id( $post_id );
        if ( $parent_id && $parent_id !== $post_id ) {
            return $this->get_trendyol_category_path( $parent_id );
        }

        return '';
    }

    /**
     * Return the product-level category override value (raw post meta).
     *
     * @since  1.0.0
     * @param  int $post_id WooCommerce product post ID.
     * @return string  Override category ID or empty string.
     */
    public function get_override( int $post_id ): string {
        return (string) get_post_meta( $post_id, self::META_OVERRIDE, true );
    }

    /**
     * Save the product-level category override.
     *
     * Sanitises and stores the value. Passing an empty string removes the
     * override so the category-level mapping takes effect again.
     *
     * @since  1.0.0
     * @param  int    $post_id     WooCommerce product post ID.
     * @param  string $category_id Trendyol category ID to store (or '' to clear).
     */
    public function save_override( int $post_id, string $category_id ): void {
        $category_id = sanitize_text_field( $category_id );

        if ( '' === $category_id ) {
            delete_post_meta( $post_id, self::META_OVERRIDE );
        } else {
            update_post_meta( $post_id, self::META_OVERRIDE, $category_id );
        }
    }

    // -----------------------------------------------------------------------
    // Inheritance Helpers
    // -----------------------------------------------------------------------

    /**
     * Recursively find the closest mapped term in a WooCommerce category's ancestry.
     * A term is considered "mapped" if it has a `trendyol_category_id` term meta.
     *
     * @since  1.0.0
     * @param  int $term_id The starting WooCommerce product_cat term ID.
     * @return int The ID of the mapped term, or 0 if none is found.
     */
    public function get_mapped_term_id( int $term_id ): int {
        while ( $term_id ) {
            $trendyol_id = get_term_meta( $term_id, self::TERM_META_ID, true );
            if ( ! empty( $trendyol_id ) ) {
                return $term_id;
            }

            $term = get_term( $term_id, 'product_cat' );
            if ( ! $term || is_wp_error( $term ) || empty( $term->parent ) ) {
                break;
            }

            $term_id = (int) $term->parent;
        }

        return 0;
    }

    /**
     * Retrieve term meta by climbing the category tree until a mapped category is found.
     *
     * @since  1.0.0
     * @param  int    $term_id  The starting WooCommerce product_cat term ID.
     * @param  string $meta_key The term meta key to retrieve.
     * @return mixed  The term meta value from the closest mapped parent, or false/empty if none.
     */
    public function get_inherited_term_meta( int $term_id, string $meta_key ) {
        $mapped_id = $this->get_mapped_term_id( $term_id );
        if ( $mapped_id ) {
            return get_term_meta( $mapped_id, $meta_key, true );
        }
        return get_term_meta( $term_id, $meta_key, true ); // Fallback to raw if unmapped
    }

    /**
     * Check if a Trendyol category supports variation slicers (free text / custom / predefined slicer).
     *
     * @since 1.1.0
     * @param int $category_id Trendyol category ID.
     * @return bool True if the category schema provides at least one slicer or custom variation attribute.
     */
    public function category_supports_slicers( int $category_id ): bool {
        if ( ! $category_id ) {
            return false;
        }

        $cache_key = "wt_cat_supports_slicers_" . $category_id;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return (bool) $cached;
        }

        $api = new Woo_Trendyol_API_Client( new Woo_Trendyol_Logger() );
        $schema = $api->get_category_attributes( $category_id );

        if ( is_wp_error( $schema ) || empty( $schema["categoryAttributes"] ) ) {
            set_transient( $cache_key, 0, DAY_IN_SECONDS );
            return false;
        }

        $variant_keywords = [
            "χρωμα", "χρώμα", "renk", "color",
            "μεγεθος", "μέγεθος", "beden", "size", "ebat", "numara",
            "σχεδιο", "σχέδιο", "desen", "pattern", "model",
        ];

        $supports = false;
        foreach ( $schema["categoryAttributes"] as $attr ) {
            if ( ! empty( $attr["slicer"] ) ) {
                $supports = true;
                break;
            }

            $name_lower = mb_strtolower( trim( $attr["attribute"]["name"] ?? "" ) );
            foreach ( $variant_keywords as $kw ) {
                if ( mb_stripos( $name_lower, $kw ) !== false ) {
                    $supports = true;
                    break 2;
                }
            }
        }

        set_transient( $cache_key, $supports ? 1 : 0, DAY_IN_SECONDS );
        return $supports;
    }

    /**
     * Determine whether variations for a given product should be split into individual standalone products.
     *
     * Priority:
     * 1. Product-level override (_trendyol_force_split_variations: "yes" -> split, "no" -> do not split)
     * 2. If category lacks slicer support AND global setting "trendyol_split_variations_without_slicers" !== "no" -> split.
     *
     * @since 1.1.0
     * @param int $product_id Post ID of parent or variation product.
     * @param int $category_id Resolved Trendyol category ID.
     * @return bool True if variations should be split.
     */
    public function should_split_variations( int $product_id, int $category_id ): bool {
        $parent_id   = wp_get_post_parent_id( $product_id ) ?: $product_id;
        $force_split = get_post_meta( $parent_id, "_trendyol_force_split_variations", true );

        if ( "yes" === $force_split ) {
            return true;
        }
        if ( "no" === $force_split ) {
            return false;
        }

        // Check if category lacks slicers
        $supports_slicers = $this->category_supports_slicers( $category_id );
        if ( ! $supports_slicers ) {
            $global_split = get_option( "trendyol_split_variations_without_slicers", "yes" );
            return "yes" === $global_split;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Private resolution helpers
    // -----------------------------------------------------------------------

    /**
     * Scan the product's assigned categories and return the best Trendyol ID.
     *
     * "Best" is the deepest (most specific) category that has a Trendyol mapping.
     * Depth is measured by the number of '|||' separators in the stored path.
     *
     * @since  1.0.0
     * @access private
     * @param  int $post_id WooCommerce product post ID.
     * @return string  Trendyol category ID or empty string.
     */
    private function resolve_from_product_categories( int $post_id ): string {
        $terms = get_the_terms( $post_id, 'product_cat' );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return '';
        }

        $best_id    = '';
        $best_depth = -1;

        foreach ( $terms as $term ) {
            $trendyol_id   = $this->get_inherited_term_meta( $term->term_id, self::TERM_META_ID );
            $trendyol_path = $this->get_inherited_term_meta( $term->term_id, self::TERM_META_PATH );

            if ( empty( $trendyol_id ) ) {
                continue;
            }

            $depth = empty( $trendyol_path )
                ? 0
                : substr_count( (string) $trendyol_path, '|||' );

            if ( $depth > $best_depth ) {
                $best_depth = $depth;
                $best_id    = (string) $trendyol_id;
            }
        }

        return $best_id;
    }

    /**
     * Scan the product's assigned categories and return the best path string.
     *
     * @since  1.0.0
     * @access private
     * @param  int $post_id WooCommerce product post ID.
     * @return string  Human-readable path or empty string.
     */
    private function resolve_path_from_product_categories( int $post_id ): string {
        $terms = get_the_terms( $post_id, 'product_cat' );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return '';
        }

        $best_path  = '';
        $best_depth = -1;

        foreach ( $terms as $term ) {
            $trendyol_id   = $this->get_inherited_term_meta( $term->term_id, self::TERM_META_ID );
            $trendyol_path = $this->get_inherited_term_meta( $term->term_id, self::TERM_META_PATH );

            if ( empty( $trendyol_id ) || empty( $trendyol_path ) ) {
                continue;
            }

            $depth = substr_count( (string) $trendyol_path, '|||' );

            if ( $depth > $best_depth ) {
                $best_depth = $depth;
                $best_path  = (string) $trendyol_path;
            }
        }

        return $this->normalise_path( $best_path );
    }

    /**
     * Look up the Trendyol category path by category ID from the product's assigned terms.
     *
     * Used when an override ID is set but no path is stored at the product level.
     *
     * @since  1.0.0
     * @access private
     * @param  string $category_id Trendyol category ID to look up.
     * @param  int    $post_id     WooCommerce product post ID.
     * @return string  Path string or empty string.
     */
    private function get_path_by_category_id( string $category_id, int $post_id ): string {
        $terms = get_the_terms( $post_id, 'product_cat' );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return '';
        }

        foreach ( $terms as $term ) {
            $id = $this->get_inherited_term_meta( $term->term_id, self::TERM_META_ID );
            if ( (string) $id === $category_id ) {
                $path = $this->get_inherited_term_meta( $term->term_id, self::TERM_META_PATH );
                return $this->normalise_path( (string) $path );
            }
        }

        return '';
    }

    /**
     * Retrieve the Yoast SEO primary category term ID for a product, if set.
     *
     * Returns 0 when Yoast is not active or no primary category is set.
     *
     * @since  1.0.0
     * @access private
     * @param  int $post_id WooCommerce product post ID.
     * @return int  Term ID or 0.
     */
    private function get_yoast_primary_category_id( int $post_id ): int {
        $primary = get_post_meta( $post_id, '_yoast_wpseo_primary_product_cat', true );
        return $primary ? (int) $primary : 0;
    }

    /**
     * Convert the '|||' separator used by the taxonomy mapper into ' > '.
     *
     * @since  1.0.0
     * @access private
     * @param  string $path Raw path string from term meta.
     * @return string  Human-readable path or empty string.
     */
    /**
     * Convert the '|||' separator used by the taxonomy mapper into ' > '.
     *
     * @since  1.0.0
     * @access private
     * @param  string $path Raw path string from term meta.
     * @return string  Human-readable path or empty string.
     */
    private function normalise_path( string $path ): string {
        if ( empty( $path ) ) {
            return '';
        }
        return implode( ' > ', array_map( 'trim', explode( '|||', $path ) ) );
    }

    /**
     * Resolve the WooCommerce category term representing the mapped category.
     *
     * @since  1.0.0
     * @param  int $post_id WooCommerce product post ID.
     * @return WP_Term|null Mapped category term or null.
     */
    public function get_resolved_category_term( int $post_id ): ?WP_Term {
        // Yoast SEO primary category
        $primary_id = $this->get_yoast_primary_category_id( $post_id );
        if ( $primary_id ) {
            $id = $this->get_inherited_term_meta( $primary_id, self::TERM_META_ID );
            if ( ! empty( $id ) ) {
                $term = get_term( $primary_id, 'product_cat' );
                if ( $term instanceof WP_Term ) {
                    return $term;
                }
            }
        }

        // Deepest mapped category
        $terms = get_the_terms( $post_id, 'product_cat' );
        if ( ( empty( $terms ) || is_wp_error( $terms ) ) && ( $parent_id = wp_get_post_parent_id( $post_id ) ) && $parent_id !== $post_id ) {
            return $this->get_resolved_category_term( $parent_id );
        }
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return null;
        }

        $best_term  = null;
        $best_depth = -1;

        foreach ( $terms as $term ) {
            $trendyol_id   = $this->get_inherited_term_meta( $term->term_id, self::TERM_META_ID );
            $trendyol_path = $this->get_inherited_term_meta( $term->term_id, self::TERM_META_PATH );

            if ( empty( $trendyol_id ) ) {
                continue;
            }

            $depth = empty( $trendyol_path )
                ? 0
                : substr_count( (string) $trendyol_path, '|||' );

            if ( $depth > $best_depth ) {
                $best_depth = $depth;
                $best_term  = $term;
            }
        }

        return $best_term;
    }

    /**
     * Calculate adjusted price based on global rules and category extra percentage.
     *
     * @since  1.0.0
     * @param  WC_Product $product    The product.
     * @param  float      $base_price Price to adjust.
     * @return float Adjusted price.
     */
    public function get_adjusted_price( WC_Product $product, float $base_price ): float {
        $adjusted_price = $base_price;

        // 1. Global Fixed Amount Adjustment
        $fixed_enabled = get_option( 'trendyol_price_rule_fixed_enabled', 'no' );
        if ( 'yes' === $fixed_enabled ) {
            $fixed_amount = (float) get_option( 'trendyol_price_rule_fixed_amount', 0 );
            $adjusted_price += $fixed_amount;
        }

        // 2. Global Volumetric Weight Adjustment
        $vw_enabled = get_option( 'trendyol_price_rule_vw_enabled', 'no' );
        if ( 'yes' === $vw_enabled ) {
            $height = (float) $product->get_height();
            $width  = (float) $product->get_width();
            $length = (float) $product->get_length();
            $weight = (float) $product->get_weight();

            $vw_calc = ( $height * $width * $length ) / 5000;
            $volumetric_weight = max( $vw_calc, $weight );

            if ( $volumetric_weight <= 0 ) {
                $zero_dim_amount = (float) get_option( 'trendyol_price_rule_vw_zero_dimensions_amount', 0 );
                $adjusted_price += $zero_dim_amount;
            } else {
                $amount_to_add = 0.0;
                if ( $volumetric_weight < 1 ) {
                    $amount_to_add = (float) get_option( 'trendyol_price_rule_vw_under_1', 0 );
                } elseif ( $volumetric_weight >= 1 && $volumetric_weight <= 2 ) {
                    $amount_to_add = (float) get_option( 'trendyol_price_rule_vw_1_to_2', 0 );
                } elseif ( $volumetric_weight > 2 && $volumetric_weight <= 3 ) {
                    $amount_to_add = (float) get_option( 'trendyol_price_rule_vw_2_to_3', 0 );
                } elseif ( $volumetric_weight > 3 ) {
                    $fixed = (float) get_option( 'trendyol_price_rule_vw_over_3_fixed', 0 );
                    $coef  = (float) get_option( 'trendyol_price_rule_vw_over_3_coef', 0 );
                    $amount_to_add = $fixed + ( $coef * ( $volumetric_weight - 3 ) );
                }
                $adjusted_price += $amount_to_add;
            }
        }

        // 3. Global Percentage Adjustment
        $pct_enabled = get_option( 'trendyol_price_rule_percentage_enabled', 'no' );
        if ( 'yes' === $pct_enabled ) {
            $percentage = (float) get_option( 'trendyol_price_rule_percentage', 0 );
            $adjusted_price += $adjusted_price * ( $percentage / 100 );
        }

        // 4. Category-level Extra Percentage
        $resolved_term = $this->get_resolved_category_term( $product->get_id() );
        if ( ! $resolved_term && $product->get_parent_id() ) {
            $resolved_term = $this->get_resolved_category_term( $product->get_parent_id() );
        }

        if ( $resolved_term ) {
            $cat_extra_pct = (float) get_term_meta( $resolved_term->term_id, 'trendyol_category_extra_percentage', true );
            if ( $cat_extra_pct > 0 ) {
                $adjusted_price += $adjusted_price * ( $cat_extra_pct / 100 );
            }
        }

        return $adjusted_price;
    }

    /**
     * Calculate the final listPrice and salePrice to be sent to Trendyol
     * based on WooCommerce prices, price rules, and override rules.
     *
     * @since  1.0.0
     * @param  WC_Product $product The product or variation.
     * @return array Array with keys 'listPrice' and 'salePrice'.
     */
    public function get_final_trendyol_prices( WC_Product $product ): array {
        $regular_price = (float) $product->get_regular_price();
        $sale_price    = (float) $product->get_sale_price();

        // Check if there is an explicit product-level price override
        $post_id = $product->get_id();
        $price_override = get_post_meta( $post_id, '_trendyol_price_override', true );
        if ( empty( $price_override ) && $product->get_parent_id() ) {
            $price_override = get_post_meta( $product->get_parent_id(), '_trendyol_price_override', true );
        }

        if ( ! empty( $price_override ) && is_numeric( $price_override ) ) {
            $override_val = round( (float) $price_override, 2 );
            return [
                'listPrice' => $override_val,
                'salePrice' => $override_val,
            ];
        }

        // Standard logic
        if ( $sale_price > 0 && $sale_price < $regular_price ) {
            // Product has a valid sale price
            // Leave normal price as is, adjust sale price
            $adj_sale_price = $this->get_adjusted_price( $product, $sale_price );
            
            if ( $adj_sale_price > $regular_price ) {
                // If adjusted sale price > normal price, replace normal price (list price) with adjusted sale price
                $final_list_price = $adj_sale_price;
                $final_sale_price = $adj_sale_price;
            } else {
                // If adjusted sale price < normal price, send normal price as is and adjusted sale price
                $final_list_price = $regular_price;
                $final_sale_price = $adj_sale_price;
            }
        } else {
            // Product has no sale price (or sale price is not active/valid)
            // Adjust the regular price
            $adj_regular_price = $this->get_adjusted_price( $product, $regular_price );
            $final_list_price  = $adj_regular_price;
            $final_sale_price  = $adj_regular_price;
        }

        // Just in case of any edge cases, ensure listPrice >= salePrice
        if ( $final_list_price < $final_sale_price ) {
            $final_list_price = $final_sale_price;
        }

        return [
            'listPrice' => round( $final_list_price, 2 ),
            'salePrice' => round( $final_sale_price, 2 ),
        ];
    }
}

