<?php
/**
 * WP-CLI smoke: gift card delivery security (no plain_code in order meta).
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-delivery-security-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\GiftCard\GiftCardDeliveryDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardOrderGenerator;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\Service\Settings;

$GLOBALS['gcds_smoke_failures'] = 0;

function gcds_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['gcds_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_create_order' ) ) {
	WP_CLI::error( 'WooCommerce product/order APIs unavailable' );
}

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable' );
}

$test_email = 'postmaster@biopentra.eu';

$repo      = new GiftCardRepository( $wpdb );
$tx        = new GiftCardTransactionRepository( $wpdb );
$ledger    = new GiftCardLedger( $repo, $tx );
$products  = new GiftCardProductService();
$settings  = new Settings();
$settings->set_gift_card_delivery_email_enabled( true );
$generator = new GiftCardOrderGenerator( $ledger, $products, $settings );

$product = new WC_Product_Simple();
$product->set_name( 'Delivery security smoke ' . wp_generate_password( 4, false ) );
$product->set_status( 'publish' );
$product->set_regular_price( '25' );
$product_id = (int) $product->save();
gcds_smoke_assert( $product_id > 0, 'create gift card product' );

GiftCardProductMeta::save(
	$product_id,
	array(
		'sells'        => GiftCardProductMeta::VALUE_YES,
		'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
		'fixed_amount' => '25',
		'expiry_days'  => '30',
	)
);

$order = wc_create_order();
$order->add_product( wc_get_product( $product_id ), 1 );
$order->set_billing_email( $test_email );
$order->set_currency( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR' );
$order->calculate_totals();
$order->set_status( 'processing', '', true );
$order->save();

$generator->generate_for_order( $order );
$order = wc_get_order( $order->get_id() );
if ( ! is_object( $order ) ) {
	WP_CLI::error( 'Could not reload order' );
}

$raw_meta = (string) $order->get_meta( GiftCardGeneratedOrderState::META_GENERATED, true );
gcds_smoke_assert( $raw_meta !== '', 'order meta has generated rows' );
gcds_smoke_assert( ! str_contains( $raw_meta, 'plain_code' ), 'full code not stored in order meta' );
gcds_smoke_assert( ! preg_match( '/[A-Z0-9]{8,}-[A-Z0-9]{4,}/', $raw_meta ), 'raw meta has no code-like strings' );

$rows = GiftCardGeneratedOrderState::get_generated( $order );
gcds_smoke_assert( count( $rows ) >= 1, 'at least one generated row' );
if ( $rows !== array() ) {
	$row = $rows[0];
	gcds_smoke_assert( isset( $row['masked_code'] ) && str_starts_with( (string) $row['masked_code'], '****' ), 'masked code stored' );
	gcds_smoke_assert( isset( $row['code_last4'] ), 'last4 stored' );
	$status = (string) ( $row['delivery_status'] ?? '' );
	gcds_smoke_assert(
		in_array( $status, array( GiftCardDeliveryStatus::SENT, GiftCardDeliveryStatus::FAILED, GiftCardDeliveryStatus::PENDING ), true ),
		'delivery status recorded: ' . $status
	);
	if ( $status === GiftCardDeliveryStatus::SENT ) {
		gcds_smoke_assert( (string) ( $row['delivered_to'] ?? '' ) === $test_email, 'delivered_to billing email' );
	}
}

$diag = new GiftCardDeliveryDiagnostics( $wpdb );
$repair_preview = $diag->repair( false );
gcds_smoke_assert( is_array( $repair_preview ), 'diagnostics repair preview returns array' );

$reports = new GiftCardReports( $wpdb );
$summary = $reports->summary();
foreach (
	array(
		'gift_cards_delivery_sent',
		'gift_cards_delivery_failed',
		'gift_cards_delivery_disabled',
		'gift_cards_delivery_unknown',
	) as $key
) {
	gcds_smoke_assert( array_key_exists( $key, $summary ), 'report key: ' . $key );
}

if ( $GLOBALS['gcds_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Gift card delivery security smoke finished with ' . (int) $GLOBALS['gcds_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Gift card delivery security smoke passed (email attempted to ' . $test_email . ').' );
