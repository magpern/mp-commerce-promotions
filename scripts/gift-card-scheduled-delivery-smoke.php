<?php
/**
 * WP-CLI smoke: gift card recipient fields and scheduled delivery.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-scheduled-delivery-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardLineItemMeta;
use MP\CommercePromotions\GiftCard\GiftCardOrderGenerator;
use MP\CommercePromotions\GiftCard\GiftCardPendingDeliveryState;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\GiftCard\GiftCardRecipientValidator;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardScheduledDeliveryService;
use MP\CommercePromotions\GiftCard\GiftCardScheduledDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\Service\Settings;

$GLOBALS['gcsd_smoke_failures'] = 0;

function gcsd_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['gcsd_smoke_failures'];
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
$scheduler = new GiftCardScheduledDeliveryService( $ledger, $products, $settings );

gcsd_smoke_assert(
	class_exists( GiftCardScheduledDiagnostics::class ),
	'scheduled diagnostics class exists'
);

try {
	GiftCardRecipientValidator::validate_for_product(
		array(
			'sells'          => true,
			'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
			'fixed_amount'   => 10.0,
			'expiry_days'    => null,
			'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL,
		),
		array(
			'recipient_email' => 'not-an-email',
			'recipient_name'  => '',
			'message'         => '',
			'delivery_timing' => GiftCardLineItemMeta::TIMING_SEND_NOW,
			'scheduled_for'   => '',
		)
	);
	gcsd_smoke_assert( false, 'invalid recipient email rejected' );
} catch ( InvalidArgumentException $e ) {
	gcsd_smoke_assert( true, 'invalid recipient email rejected' );
}

$product = new WC_Product_Simple();
$product->set_name( 'Scheduled delivery smoke ' . wp_generate_password( 4, false ) );
$product->set_status( 'publish' );
$product->set_regular_price( '35' );
$product_id = (int) $product->save();
gcsd_smoke_assert( $product_id > 0, 'create gift card product' );

GiftCardProductMeta::save(
	$product_id,
	array(
		'sells'          => GiftCardProductMeta::VALUE_YES,
		'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
		'fixed_amount'   => '35',
		'expiry_days'    => '30',
		'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE,
	)
);

$config = GiftCardProductMeta::read( $product_id );
gcsd_smoke_assert(
	( $config['recipient_mode'] ?? '' ) === GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE,
	'product recipient mode saved'
);

$order_now = wc_create_order();
$order_now->add_product( wc_get_product( $product_id ), 1 );
$order_now->set_billing_email( 'buyer@example.com' );
$order_now->set_currency( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR' );
$order_now->calculate_totals();
$order_now->save();

foreach ( $order_now->get_items( 'line_item' ) as $item ) {
	if ( is_object( $item ) && method_exists( $item, 'update_meta_data' ) ) {
		$item->update_meta_data( GiftCardLineItemMeta::KEY_RECIPIENT_EMAIL, $test_email );
		$item->update_meta_data( GiftCardLineItemMeta::KEY_MESSAGE, 'Smoke test message' );
		$item->update_meta_data( GiftCardLineItemMeta::KEY_DELIVERY_TIMING, GiftCardLineItemMeta::TIMING_SEND_NOW );
		$item->save();
	}
}

$order_now->set_status( 'processing', '', true );
$order_now->save();
$generator->generate_for_order( $order_now );
$order_now = wc_get_order( $order_now->get_id() );
if ( ! is_object( $order_now ) ) {
	WP_CLI::error( 'Could not reload send_now order' );
}

$generated_now = GiftCardGeneratedOrderState::get_generated( $order_now );
gcsd_smoke_assert( count( $generated_now ) >= 1, 'send_now generates card after payment' );
$raw_now = (string) $order_now->get_meta( GiftCardGeneratedOrderState::META_GENERATED, true );
gcsd_smoke_assert( ! str_contains( $raw_now, 'plain_code' ), 'send_now order meta has no plain_code' );

$order_sched = wc_create_order();
$order_sched->add_product( wc_get_product( $product_id ), 1 );
$order_sched->set_billing_email( 'buyer@example.com' );
$order_sched->set_currency( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR' );
$order_sched->calculate_totals();
$order_sched->save();

$scheduled_date = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
foreach ( $order_sched->get_items( 'line_item' ) as $item ) {
	if ( is_object( $item ) && method_exists( $item, 'update_meta_data' ) ) {
		$item->update_meta_data( GiftCardLineItemMeta::KEY_RECIPIENT_EMAIL, $test_email );
		$item->update_meta_data( GiftCardLineItemMeta::KEY_DELIVERY_TIMING, GiftCardLineItemMeta::TIMING_SEND_ON_DATE );
		$item->update_meta_data( GiftCardLineItemMeta::KEY_SCHEDULED_FOR, $scheduled_date );
		$item->save();
	}
}

$order_sched->set_status( 'processing', '', true );
$order_sched->save();
$generator->generate_for_order( $order_sched );
$order_sched = wc_get_order( $order_sched->get_id() );
if ( ! is_object( $order_sched ) ) {
	WP_CLI::error( 'Could not reload scheduled order' );
}

gcsd_smoke_assert(
	GiftCardGeneratedOrderState::get_generated( $order_sched ) === array(),
	'scheduled order does not generate card immediately'
);
$pending = GiftCardPendingDeliveryState::get_pending( $order_sched );
gcsd_smoke_assert( count( $pending ) >= 1, 'scheduled order stores pending delivery row' );
if ( $pending !== array() ) {
	gcsd_smoke_assert( (string) ( $pending[0]['recipient_email'] ?? '' ) === $test_email, 'pending row has recipient email' );
}

$raw_pending = (string) $order_sched->get_meta( GiftCardPendingDeliveryState::META_PENDING, true );
gcsd_smoke_assert( ! str_contains( $raw_pending, 'plain_code' ), 'pending meta has no plain_code' );

$generated_before_runner = is_object( $order_sched )
	? count( GiftCardGeneratedOrderState::get_generated( $order_sched ) )
	: 0;
$result = $scheduler->fulfill_order_pending( $order_sched, true );
$order_sched = wc_get_order( $order_sched->get_id() );
if ( ! is_object( $order_sched ) ) {
	WP_CLI::error( 'Could not reload order after scheduler' );
}
$generated_after = count( GiftCardGeneratedOrderState::get_generated( $order_sched ) );
gcsd_smoke_assert(
	(int) ( $result['fulfilled'] ?? 0 ) >= 1 || $generated_after > $generated_before_runner,
	'runner fulfills due scheduled card'
);
gcsd_smoke_assert( $generated_after >= 1, 'scheduled runner created generated row' );

$diag = new GiftCardScheduledDiagnostics( $wpdb, $scheduler );
$issues = $diag->analyze();
foreach ( array( 'overdue', 'unpaid_pending', 'invalid_recipient', 'failed_scheduled' ) as $key ) {
	gcsd_smoke_assert( array_key_exists( $key, $issues ), 'diagnostics key: ' . $key );
}

$reports = new GiftCardReports( $wpdb );
$summary = $reports->summary();
foreach (
	array(
		'scheduled_pending',
		'scheduled_sent',
		'scheduled_failed',
		'scheduled_cancelled',
	) as $key
) {
	gcsd_smoke_assert( array_key_exists( $key, $summary ), 'report key: ' . $key );
}

if ( $GLOBALS['gcsd_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Gift card scheduled delivery smoke finished with ' . (int) $GLOBALS['gcsd_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Gift card scheduled delivery smoke passed (recipient ' . $test_email . ').' );
