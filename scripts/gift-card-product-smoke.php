<?php
/**
 * WP-CLI smoke: gift cards sold via WooCommerce products.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardOrderGenerator;
use MP\CommercePromotions\GiftCard\GiftCardOrderReversal;
use MP\CommercePromotions\GiftCard\GiftCardProductDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\Infrastructure\Database\MigrationRunner;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Service\Settings;

$GLOBALS['gcp_smoke_failures'] = 0;

function gcp_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['gcp_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

gcp_smoke_assert( Schema::SCHEMA_VERSION === '1.19.0', 'schema version 1.19.0' );

if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_create_order' ) ) {
	WP_CLI::error( 'WooCommerce product/order APIs unavailable' );
}

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable' );
}

$migration = new MigrationRunner( $wpdb );
if ( $migration->needs_migration() ) {
	$migration->run();
}

$repo      = new GiftCardRepository( $wpdb );
$tx        = new GiftCardTransactionRepository( $wpdb );
$ledger    = new GiftCardLedger( $repo, $tx );
$products  = new GiftCardProductService();
$settings   = new Settings();
$qa_email   = 'postmaster@biopentra.eu';
$generator  = new GiftCardOrderGenerator( $ledger, $products, $settings );
$reversal   = new GiftCardOrderReversal( $ledger, $repo );

$product = new WC_Product_Simple();
$product->set_name( 'Smoke gift card product ' . wp_generate_password( 4, false ) );
$product->set_status( 'publish' );
$product->set_regular_price( '30' );
$product->set_manage_stock( false );
$product_id = (int) $product->save();
gcp_smoke_assert( $product_id > 0, 'create simple product' );

GiftCardProductMeta::save(
	$product_id,
	array(
		'sells'        => GiftCardProductMeta::VALUE_YES,
		'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE,
		'fixed_amount' => '',
		'expiry_days'  => '90',
	)
);
gcp_smoke_assert( $products->is_gift_card_product( $product_id ), 'product marked as gift card' );

$order = wc_create_order();
$order->add_product( wc_get_product( $product_id ), 2 );
$order->set_billing_email( $qa_email );
$order->set_customer_id( 0 );
$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
$order->set_currency( $currency );
$order->calculate_totals();
$order->set_status( 'processing', '', true );
$order->save();
$order_id = (int) $order->get_id();
gcp_smoke_assert( $order_id > 0, 'create paid processing order' );

$generated = $generator->generate_for_order( $order );
gcp_smoke_assert( count( $generated ) === 2, 'generator creates two gift cards for qty 2' );

$card_id = (int) ( $generated[0]['gift_card_id'] ?? 0 );
$card    = $repo->find( $card_id );
gcp_smoke_assert( $card !== null, 'generated card exists' );
if ( $card !== null ) {
	gcp_smoke_assert( $card->get_created_order_id() === $order_id, 'created_order_id stored' );
	gcp_smoke_assert( $card->get_currency() === $currency, 'currency stored' );
	gcp_smoke_assert( $card->get_initial_amount() === 30.0, 'amount from product price per unit' );
	gcp_smoke_assert( $card->get_recipient_email() === $qa_email, 'billing email as recipient' );
}

$again = $generator->generate_for_order( $order );
gcp_smoke_assert( count( $again ) === 2, 'second generator run returns same slots' );
gcp_smoke_assert(
	count( GiftCardGeneratedOrderState::get_generated( $order ) ) === 2,
	'order meta has two generated rows'
);

$order->set_status( 'cancelled', '', true );
$order->save();
$reversal->handle_order_reversal( $order );
$card_after = $repo->find( $card_id );
gcp_smoke_assert(
	$card_after !== null && $card_after->get_status() === GiftCard::STATUS_VOIDED,
	'cancellation voids unused card'
);

$reports = new GiftCardReports( $wpdb );
$summary = $reports->summary();
foreach (
	array(
		'gift_cards_sold_from_products',
		'product_generated_liability',
		'product_generated_issued_total',
		'manually_issued_total',
	) as $key
) {
	gcp_smoke_assert( array_key_exists( $key, $summary ), 'report key: ' . $key );
}

$diag   = new GiftCardProductDiagnostics( $wpdb, $repo, $ledger, $generator, $reversal );
$issues = $diag->analyze();
foreach (
	array(
		'paid_orders_missing_generation',
		'product_cards_missing_order_id',
		'cancelled_orders_active_unused_cards',
	) as $key
) {
	gcp_smoke_assert( array_key_exists( $key, $issues ), 'diagnostics key: ' . $key );
}

if ( $GLOBALS['gcp_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Gift card product smoke finished with ' . (int) $GLOBALS['gcp_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Gift card product smoke passed.' );
