<?php
/**
 * Import/Export handler for Trendyol mappings.
 *
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Woo_Trendyol_Import_Export {

    public function __construct() {
        add_action( 'admin_post_woo_trendyol_export_mappings', [ $this, 'export_mappings' ] );
        add_action( 'wp_ajax_woo_trendyol_import_mappings', [ $this, 'import_mappings' ] );
    }

    /**
     * Export all mappings as a JSON file.
     */
    public function export_mappings(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'woo-trendyol' ) );
        }

        check_admin_referer( 'woo_trendyol_export' );

        $data = [
            'brands'     => [],
            'categories' => [],
            'options'    => []
        ];

        // Export Global Attributes Mappings
        $global_options = [
            'trendyol_global_attr_gender_wc',
            'trendyol_global_attr_gender_map',
            'trendyol_global_attr_age_wc',
            'trendyol_global_attr_age_map',
            'trendyol_global_attr_age_group_wc',
            'trendyol_global_attr_age_group_map',
            'trendyol_global_attr_color_wc',
            'trendyol_global_attr_color_map',
            'trendyol_global_attr_color_custom_wc',
            'trendyol_global_attr_color_custom_map',
            'trendyol_global_attr_brand_wc',
            'trendyol_global_attr_character_wc',
        ];

        foreach ( $global_options as $opt_name ) {
            $val = get_option( $opt_name, '' );
            if ( strpos( $opt_name, '_map' ) !== false && ! empty( $val ) ) {
                $decoded = json_decode( $val, true );
                if ( is_array( $decoded ) ) {
                    $val = $decoded;
                }
            }
            $data['options'][$opt_name] = $val;
        }

        // Export Brands
        if ( taxonomy_exists( 'product_brand' ) ) {
            $brands = get_terms( [
                'taxonomy'   => 'product_brand',
                'hide_empty' => false,
                'meta_query' => [
                    [
                        'key'     => 'trendyol_brand_id',
                        'compare' => 'EXISTS'
                    ]
                ]
            ] );

            if ( ! is_wp_error( $brands ) && ! empty( $brands ) ) {
                foreach ( $brands as $brand ) {
                    $brand_id   = get_term_meta( $brand->term_id, 'trendyol_brand_id', true );
                    $brand_name = get_term_meta( $brand->term_id, 'trendyol_brand_name', true );
                    
                    if ( $brand_id ) {
                        $data['brands'][] = [
                            'woo_slug'            => $brand->slug,
                            'woo_name'            => $brand->name,
                            'trendyol_brand_id'   => $brand_id,
                            'trendyol_brand_name' => $brand_name,
                        ];
                    }
                }
            }
        }

        // Export Categories
        $categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'     => 'trendyol_category_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ] );

        if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
            foreach ( $categories as $cat ) {
                $cat_id = get_term_meta( $cat->term_id, 'trendyol_category_id', true );
                if ( $cat_id ) {
                    $cat_data = [
                        'woo_slug'                       => $cat->slug,
                        'woo_name'                       => $cat->name,
                        'trendyol_category_id'           => $cat_id,
                        'trendyol_category_path'         => get_term_meta( $cat->term_id, 'trendyol_category_path', true ),
                        '_trendyol_attribute_mappings'   => maybe_unserialize( get_term_meta( $cat->term_id, '_trendyol_attribute_mappings', true ) ),
                        '_trendyol_attribute_value_mappings' => maybe_unserialize( get_term_meta( $cat->term_id, '_trendyol_attribute_value_mappings', true ) ),
                        '_trendyol_required_attributes'  => maybe_unserialize( get_term_meta( $cat->term_id, '_trendyol_required_attributes', true ) ),
                        'trendyol_category_extra_percentage' => get_term_meta( $cat->term_id, 'trendyol_category_extra_percentage', true ),
                    ];
                    $data['categories'][] = $cat_data;
                }
            }
        }

        $json_data = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="woo-trendyol-mappings-export.json"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        
        echo $json_data;
        exit;
    }

    /**
     * Import mappings from an uploaded JSON file.
     */
    public function import_mappings(): void {
        check_ajax_referer( 'woo_trendyol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-trendyol' ) ] );
        }

        if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'woo-trendyol' ) ] );
        }

        $file_content = file_get_contents( $_FILES['import_file']['tmp_name'] );
        $data = json_decode( $file_content, true );

        if ( ! is_array( $data ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid JSON file.', 'woo-trendyol' ) ] );
        }

        $brands_imported = 0;
        $categories_imported = 0;

        // Import Brands
        if ( ! empty( $data['brands'] ) && is_array( $data['brands'] ) && taxonomy_exists( 'product_brand' ) ) {
            foreach ( $data['brands'] as $brand_data ) {
                if ( empty( $brand_data['trendyol_brand_id'] ) ) continue;

                $term = get_term_by( 'slug', $brand_data['woo_slug'], 'product_brand' );
                if ( ! $term && ! empty( $brand_data['woo_name'] ) ) {
                    $term = get_term_by( 'name', $brand_data['woo_name'], 'product_brand' );
                }

                if ( $term && ! is_wp_error( $term ) ) {
                    update_term_meta( $term->term_id, 'trendyol_brand_id', $brand_data['trendyol_brand_id'] );
                    if ( ! empty( $brand_data['trendyol_brand_name'] ) ) {
                        update_term_meta( $term->term_id, 'trendyol_brand_name', $brand_data['trendyol_brand_name'] );
                    }
                    $brands_imported++;
                }
            }
        }

        // Import Categories
        if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
            foreach ( $data['categories'] as $cat_data ) {
                if ( empty( $cat_data['trendyol_category_id'] ) ) continue;

                $term = get_term_by( 'slug', $cat_data['woo_slug'], 'product_cat' );
                if ( ! $term && ! empty( $cat_data['woo_name'] ) ) {
                    $term = get_term_by( 'name', $cat_data['woo_name'], 'product_cat' );
                }

                if ( $term && ! is_wp_error( $term ) ) {
                    update_term_meta( $term->term_id, 'trendyol_category_id', $cat_data['trendyol_category_id'] );
                    
                    if ( isset( $cat_data['trendyol_category_path'] ) ) {
                        update_term_meta( $term->term_id, 'trendyol_category_path', $cat_data['trendyol_category_path'] );
                    }
                    if ( isset( $cat_data['_trendyol_attribute_mappings'] ) ) {
                        update_term_meta( $term->term_id, '_trendyol_attribute_mappings', $cat_data['_trendyol_attribute_mappings'] );
                    }
                    if ( isset( $cat_data['_trendyol_attribute_value_mappings'] ) ) {
                        update_term_meta( $term->term_id, '_trendyol_attribute_value_mappings', $cat_data['_trendyol_attribute_value_mappings'] );
                    }
                    if ( isset( $cat_data['_trendyol_required_attributes'] ) ) {
                        update_term_meta( $term->term_id, '_trendyol_required_attributes', $cat_data['_trendyol_required_attributes'] );
                    }
                    if ( isset( $cat_data['trendyol_category_extra_percentage'] ) ) {
                        update_term_meta( $term->term_id, 'trendyol_category_extra_percentage', $cat_data['trendyol_category_extra_percentage'] );
                    }
                    $categories_imported++;
                }
            }
        }

        // Import Options
        if ( ! empty( $data['options'] ) && is_array( $data['options'] ) ) {
            foreach ( $data['options'] as $opt_name => $opt_val ) {
                if ( is_array( $opt_val ) && strpos( $opt_name, '_map' ) !== false ) {
                    $opt_val = wp_json_encode( $opt_val, JSON_UNESCAPED_UNICODE );
                }
                update_option( $opt_name, $opt_val );
            }
        }

        wp_send_json_success( [
            /* translators: 1: number of categories, 2: number of brands */
            'message' => sprintf( __( 'Import complete! Imported %1$d categories, %2$d brands, and global settings.', 'woo-trendyol' ), $categories_imported, $brands_imported )
        ] );
    }
}
