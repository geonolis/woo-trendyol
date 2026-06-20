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
            $id = get_term_meta( $primary_id, self::TERM_META_ID, true );
            if ( ! empty( $id ) ) {
                return (string) $id;
            }
        }

        // ---- Priority 3 & 4: scan all assigned categories ----
        return $this->resolve_from_product_categories( $post_id );
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
            $path = get_term_meta( $primary_id, self::TERM_META_PATH, true );
            if ( ! empty( $path ) ) {
                return $this->normalise_path( (string) $path );
            }
        }

        // ---- Priority 3 & 4: scan all assigned categories ----
        return $this->resolve_path_from_product_categories( $post_id );
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
            $trendyol_id   = get_term_meta( $term->term_id, self::TERM_META_ID,   true );
            $trendyol_path = get_term_meta( $term->term_id, self::TERM_META_PATH, true );

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
            $trendyol_id   = get_term_meta( $term->term_id, self::TERM_META_ID,   true );
            $trendyol_path = get_term_meta( $term->term_id, self::TERM_META_PATH, true );

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
            $id = get_term_meta( $term->term_id, self::TERM_META_ID, true );
            if ( (string) $id === $category_id ) {
                $path = get_term_meta( $term->term_id, self::TERM_META_PATH, true );
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
    private function normalise_path( string $path ): string {
        if ( empty( $path ) ) {
            return '';
        }
        return implode( ' > ', array_map( 'trim', explode( '|||', $path ) ) );
    }
}
