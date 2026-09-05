<?php
/**
 * DEV fixture product for bulk pricing Playwright acceptance.
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/bulk-pricing-fixtures.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Product_Simple' ) ) {
	WP_CLI::error( 'WooCommerce is required.' );
}

$slug = 'bulk-pricing-fixture';
$existing = get_page_by_path( $slug, OBJECT, 'product' );
$product  = $existing ? wc_get_product( $existing->ID ) : new WC_Product_Simple();

if ( ! $product ) {
	WP_CLI::error( 'Could not create fixture product.' );
}

$product->set_name( 'Bulk Pricing Fixture (DEV)' );
$product->set_slug( $slug );
$product->set_status( 'publish' );
$product->set_catalog_visibility( 'hidden' );
$product->set_regular_price( '100' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 999 );
$product->set_stock_status( 'instock' );

$id = $product->save();

update_post_meta( $id, '_mp_cp_bulk_pricing_enabled', 'yes' );
update_post_meta(
	$id,
	'_mp_cp_bulk_pricing_tiers',
	wp_json_encode(
		array(
			array(
				'min_quantity'        => 1,
				'discount_percentage' => 0,
				'anchor_quantity'     => 1,
				'badge'               => null,
				'sort_order'          => 1,
			),
			array(
				'min_quantity'        => 3,
				'discount_percentage' => 5,
				'anchor_quantity'     => 3,
				'badge'               => null,
				'sort_order'          => 2,
			),
			array(
				'min_quantity'        => 5,
				'discount_percentage' => 10,
				'anchor_quantity'     => 5,
				'badge'               => 'Best value',
				'sort_order'          => 3,
			),
			array(
				'min_quantity'        => 10,
				'discount_percentage' => 15,
				'anchor_quantity'     => 10,
				'badge'               => null,
				'sort_order'          => 4,
			),
		)
	)
);
update_post_meta( $id, '_mp_cp_bulk_pricing_schema_version', '1' );

update_option( 'mp_cp_bulk_pricing_enabled', 'yes' );

WP_CLI::success( 'Bulk pricing fixture product ID ' . $id . ' at /product/' . $slug . '/' );
