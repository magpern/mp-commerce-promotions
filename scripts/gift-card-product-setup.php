<?php
/**
 * WP-CLI: create or update the QA gift card demo product (idempotent).
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\GiftCard\GiftCardQaProductSetup;

if ( ! class_exists( 'WC_Product_Simple' ) ) {
	WP_CLI::error( 'WooCommerce is required.' );
}

$qa = mp_cp_qa_context();
if ( $qa !== null && $qa->is_dry_run() ) {
	WP_CLI::log(
		'DRY RUN: would create/update SKU ' . GiftCardQaProductSetup::PRODUCT_SKU
		. '. Set MP_CP_ALLOW_PERSISTENT_QA_SETUP=1 on production to persist.'
	);
	exit( 0 );
}

if ( $qa !== null ) {
	$qa->assert_may_write();
}

$setup  = new GiftCardQaProductSetup();
$result = $setup->ensure_demo_product();

if ( $qa !== null ) {
	$qa->register_product( (int) $result['product_id'] );
}

$products = new GiftCardProductService();
$ok       = $products->is_gift_card_product( $result['product_id'] );

$out = array(
	'product_id'  => $result['product_id'],
	'created'     => $result['created'],
	'product_url' => $result['product_url'],
	'is_gift_card_product' => $ok,
	'meta'        => array(
		GiftCardProductMeta::META_SELLS          => $result['meta']['sells'] ? GiftCardProductMeta::VALUE_YES : GiftCardProductMeta::VALUE_NO,
		GiftCardProductMeta::META_AMOUNT_MODE    => $result['meta']['amount_mode'],
		GiftCardProductMeta::META_EXPIRY_DAYS    => $result['meta']['expiry_days'],
		GiftCardProductMeta::META_RECIPIENT_MODE => $result['meta']['recipient_mode'],
	),
	'sku'         => GiftCardQaProductSetup::PRODUCT_SKU,
	'price'       => GiftCardQaProductSetup::DEFAULT_PRICE,
);

$encoded = wp_json_encode( $out, JSON_PRETTY_PRINT );
WP_CLI::log( is_string( $encoded ) ? $encoded : '{}' );

if ( ! $ok ) {
	WP_CLI::error( 'Product saved but gift card meta verification failed.' );
}

WP_CLI::success(
	sprintf(
		'Gift card QA product ready (ID %d, %s). Cleanup: tagged _mp_cp_qa_created for run %s.',
		$result['product_id'],
		$result['created'] ? 'created' : 'updated',
		$qa !== null ? $qa->cleanup->get_run_id() : 'n/a'
	)
);
