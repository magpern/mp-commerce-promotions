<?php
/**
 * WP-CLI E2E smoke: gift card product purchase, delivery, redemption, reversal.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-e2e-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardBalanceChecker;
use MP\CommercePromotions\GiftCard\GiftCardCustomerService;
use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardLineItemMeta;
use MP\CommercePromotions\GiftCard\GiftCardOrderGenerator;
use MP\CommercePromotions\GiftCard\GiftCardOrderReversal;
use MP\CommercePromotions\GiftCard\GiftCardPendingDeliveryState;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\GiftCard\GiftCardQaProductSetup;
use MP\CommercePromotions\GiftCard\GiftCardRedemptionService;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardScheduledDeliveryService;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\GiftCard\StoreCreditAccountService;
use MP\CommercePromotions\GiftCard\StoreCreditWallet;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\GiftCardOrderRecorder;
use MP\CommercePromotions\Woo\GiftCardOrderState;

$GLOBALS['gce2e_smoke_failures'] = 0;

function gce2e_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['gce2e_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

/**
 * @param array<string, mixed> $delivery
 */
function gce2e_apply_line_delivery( $item, array $delivery ): void {
	if ( ! is_object( $item ) || ! method_exists( $item, 'update_meta_data' ) ) {
		return;
	}
	GiftCardLineItemMeta::write_to_order_item( $item, $delivery );
	if ( method_exists( $item, 'save' ) ) {
		$item->save();
	}
}

/**
 * @return \WC_Order
 */
function gce2e_create_gift_card_order( int $product_id, string $billing_email, array $line_delivery ) {
	if ( ! function_exists( 'wc_create_order' ) ) {
		throw new RuntimeException( 'wc_create_order unavailable' );
	}

	$order = wc_create_order();
	$order->add_product( wc_get_product( $product_id ), 1 );
	$order->set_billing_email( $billing_email );
	$order->set_currency( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR' );
	$order->calculate_totals();
	$order->save();

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		gce2e_apply_line_delivery( $item, $line_delivery );
	}

	return $order;
}

function gce2e_ensure_test_user( string $email ): int {
	$user = get_user_by( 'email', $email );
	if ( $user instanceof WP_User ) {
		return (int) $user->ID;
	}

	$local = sanitize_user( 'mp_cp_gc_qa_' . substr( md5( $email ), 0, 8 ), true );
	if ( $local === '' ) {
		$local = 'mp_cp_gc_qa_user';
	}
	$base = $local;
	$n    = 0;
	while ( username_exists( $local ) ) {
		++$n;
		$local = $base . $n;
	}

	$user_id = wp_create_user( $local, wp_generate_password( 24, true, true ), $email );
	if ( is_wp_error( $user_id ) ) {
		return 0;
	}

	return (int) $user_id;
}

$test_email = 'postmaster@biopentra.eu';
$test_message = 'QA personal message from product e2e smoke.';

if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_create_order' ) ) {
	WP_CLI::error( 'WooCommerce product/order APIs unavailable' );
}

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable' );
}

$repo      = new GiftCardRepository( $wpdb );
$tx        = new GiftCardTransactionRepository( $wpdb );
$ledger    = new GiftCardLedger( $repo, $tx );
$products  = new GiftCardProductService();
$settings  = new Settings();
$settings->set_gift_card_delivery_email_enabled( true );
$generator = new GiftCardOrderGenerator( $ledger, $products, $settings );
$scheduler = new GiftCardScheduledDeliveryService( $ledger, $products, $settings );
$reversal_product = new GiftCardOrderReversal( $ledger, $repo );
$recorder  = new GiftCardOrderRecorder( $ledger );
$checker   = new GiftCardBalanceChecker( $ledger );
$customers = new GiftCardCustomerService( $repo, $wpdb );
$redemption = new GiftCardRedemptionService( $ledger );

$setup_result = ( new GiftCardQaProductSetup() )->ensure_demo_product();
$product_id   = (int) $setup_result['product_id'];
gce2e_assert( $product_id > 0, 'QA gift card product exists' );
gce2e_assert( $products->is_gift_card_product( $product_id ), 'QA product marked as gift card' );
gce2e_assert(
	( $setup_result['meta']['recipient_mode'] ?? '' ) === GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE,
	'QA product recipient mode is recipient_email_and_message'
);

WP_CLI::log( 'Product URL: ' . (string) $setup_result['product_url'] );

// --- Send now ---
$order_now = gce2e_create_gift_card_order(
	$product_id,
	'buyer-send-now@example.com',
	array(
		'recipient_email'  => $test_email,
		'recipient_name'   => 'QA Recipient',
		'message'          => $test_message,
		'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_NOW,
		'scheduled_for'    => '',
	)
);
$order_now->set_status( 'processing', '', true );
$order_now->save();
$order_now_id = (int) $order_now->get_id();

$generator->generate_for_order( $order_now );
$order_now = wc_get_order( $order_now_id );
if ( ! is_object( $order_now ) ) {
	WP_CLI::error( 'Could not reload send_now order' );
}

$generated_now = GiftCardGeneratedOrderState::get_generated( $order_now );
gce2e_assert( count( $generated_now ) >= 1, 'send_now generates gift card' );

$raw_now = (string) $order_now->get_meta( GiftCardGeneratedOrderState::META_GENERATED, true );
gce2e_assert( ! str_contains( $raw_now, 'plain_code' ), 'send_now order meta has no plain_code' );

$send_now_card_id = (int) ( $generated_now[0]['gift_card_id'] ?? 0 );
$send_now_card    = $repo->find( $send_now_card_id );
gce2e_assert( $send_now_card !== null, 'send_now card in ledger' );
if ( $send_now_card !== null ) {
	gce2e_assert( $send_now_card->get_recipient_email() === $test_email, 'recipient email on card' );
	gce2e_assert( $send_now_card->get_created_order_id() === $order_now_id, 'created_order_id on send_now card' );
}

foreach ( $order_now->get_items( 'line_item' ) as $item ) {
	if ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
		gce2e_assert( (string) $item->get_meta( GiftCardLineItemMeta::KEY_MESSAGE, true ) === $test_message, 'line item message stored' );
		gce2e_assert( (string) $item->get_meta( GiftCardLineItemMeta::KEY_RECIPIENT_EMAIL, true ) === $test_email, 'line item recipient email stored' );
		break;
	}
}

$received = $customers->list_received( $test_email );
$found_received = false;
foreach ( $received as $row ) {
	if ( (int) ( $row['gift_card_id'] ?? 0 ) === $send_now_card_id ) {
		$found_received = true;
		break;
	}
}
gce2e_assert( $found_received, 'My Account received list includes send_now card' );

// Balance checker: separate issued card (product-generated codes are not persisted as plain).
$issued_for_lookup = $ledger->issue( 12.0, $order_now->get_currency(), null, $test_email );
$lookup_plain      = $issued_for_lookup->get_plain_code();
$lookup            = $checker->lookup( $lookup_plain ?? '' );
gce2e_assert( ! empty( $lookup['ok'] ), 'balance checker finds issued card' );
gce2e_assert(
	is_string( wp_json_encode( $lookup ) ) && ! str_contains( (string) wp_json_encode( $lookup ), (string) $lookup_plain ),
	'balance checker does not echo full code'
);

// Redemption on a dedicated card (full send_now card may be emailed-only without plain in CLI).
$redeem_issue = $ledger->issue( 20.0, $order_now->get_currency(), null, null );
$redeem_card  = $redeem_issue->get_card();
$redeem_id    = (int) $redeem_card->get_id();
$balance_before = GiftCard::money( $redeem_card->get_balance() );
$apply_amount   = $redemption->preview_apply_amount( $redeem_card, 7.5 );
gce2e_assert( $apply_amount > 0, 'checkout redeem preview amount > 0' );

$redeem_order = wc_create_order();
$redeem_order->set_currency( $order_now->get_currency() );
$redeem_order->calculate_totals();
$redeem_order->save();
$redeem_order_id = (int) $redeem_order->get_id();

GiftCardOrderState::set_redemptions(
	$redeem_order,
	array(
		array(
			'gift_card_id' => $redeem_id,
			'amount'       => $apply_amount,
			'code_last4'   => $redeem_card->get_code_last4(),
		),
	)
);
$recorder->record_on_checkout_processed( $redeem_order );
$after_redeem = $repo->find( $redeem_id );
gce2e_assert(
	$after_redeem !== null && GiftCard::money( $after_redeem->get_balance() ) === GiftCard::money( $balance_before - $apply_amount ),
	'order redemption debits balance'
);

$recorder->reverse_on_order_status( $redeem_order );
$redeem_order->set_status( 'cancelled', '', true );
$redeem_order->save();
$after_reverse = $repo->find( $redeem_id );
gce2e_assert(
	$after_reverse !== null && GiftCard::money( $after_reverse->get_balance() ) === $balance_before,
	'reversal restores balance'
);

// --- Scheduled ---
$scheduled_date = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
$order_sched    = gce2e_create_gift_card_order(
	$product_id,
	'buyer-scheduled@example.com',
	array(
		'recipient_email' => $test_email,
		'recipient_name'  => 'Scheduled QA',
		'message'         => 'Scheduled QA message',
		'delivery_timing' => GiftCardLineItemMeta::TIMING_SEND_ON_DATE,
		'scheduled_for'   => $scheduled_date,
	)
);
$order_sched->set_status( 'processing', '', true );
$order_sched->save();
$order_sched_id = (int) $order_sched->get_id();

$generator->generate_for_order( $order_sched );
$order_sched = wc_get_order( $order_sched_id );
if ( ! is_object( $order_sched ) ) {
	WP_CLI::error( 'Could not reload scheduled order' );
}

gce2e_assert( GiftCardGeneratedOrderState::get_generated( $order_sched ) === array(), 'scheduled order does not generate immediately' );
$pending = GiftCardPendingDeliveryState::get_pending( $order_sched );
gce2e_assert( count( $pending ) >= 1, 'scheduled order stores pending row' );
$raw_pending = (string) $order_sched->get_meta( GiftCardPendingDeliveryState::META_PENDING, true );
gce2e_assert( ! str_contains( $raw_pending, 'plain_code' ), 'pending meta has no plain_code' );

$before_runner = count( GiftCardGeneratedOrderState::get_generated( $order_sched ) );
$result        = $scheduler->fulfill_order_pending( $order_sched, true );
$order_sched   = wc_get_order( $order_sched_id );
if ( ! is_object( $order_sched ) ) {
	WP_CLI::error( 'Could not reload order after scheduler' );
}
$after_runner = count( GiftCardGeneratedOrderState::get_generated( $order_sched ) );
gce2e_assert(
	(int) ( $result['fulfilled'] ?? 0 ) >= 1 || $after_runner > $before_runner,
	'scheduled runner fulfills due delivery once'
);

// --- Store credit test user ---
$customer_id = gce2e_ensure_test_user( $test_email );
gce2e_assert( $customer_id > 0, 'test recipient WP user exists' );

$wallet = new StoreCreditWallet( new StoreCreditAccountService( $repo ), $ledger );
$currency = $order_now->get_currency();
$wallet->grant_credit( $customer_id, 5.0, $currency, 'Product e2e QA grant' );
$sc_balance = $wallet->get_balance( $customer_id, $currency );
gce2e_assert( $sc_balance >= 5.0, 'store credit granted to test user' );

$summary = array(
	'product_id'        => $product_id,
	'product_url'       => $setup_result['product_url'],
	'test_recipient'    => $test_email,
	'customer_id'       => $customer_id,
	'send_now_order_id' => $order_now_id,
	'send_now_card_id'  => $send_now_card_id,
	'scheduled_order_id'=> $order_sched_id,
	'redeem_order_id'   => $redeem_order_id,
	'redeem_card_id'    => $redeem_id,
);

$encoded = wp_json_encode( $summary, JSON_PRETTY_PRINT );
WP_CLI::log( is_string( $encoded ) ? $encoded : '{}' );

if ( $GLOBALS['gce2e_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Gift card product e2e smoke finished with ' . (int) $GLOBALS['gce2e_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Gift card product e2e smoke passed.' );
