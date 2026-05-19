<?php
/**
 * WP-CLI: collect gift card storefront QA evidence (IDs + pass/fail hints).
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-storefront-qa-evidence.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardBalanceChecker;
use MP\CommercePromotions\GiftCard\GiftCardQaProductSetup;
use MP\CommercePromotions\GiftCard\GiftCardCustomerService;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardMailDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardRedemptionService;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\GiftCard\StoreCreditAccountService;
use MP\CommercePromotions\GiftCard\StoreCreditWallet;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\GiftCardMyAccount;

$recipient = 'postmaster@biopentra.eu';
$evidence  = array(
	'generated_at' => gmdate( 'c' ),
	'test_recipient' => $recipient,
	'scenarios' => array(),
);

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable' );
}

$settings = new Settings();
$repo     = new GiftCardRepository( $wpdb );
$ledger   = new GiftCardLedger( $repo, new GiftCardTransactionRepository( $wpdb ) );
$checker  = new GiftCardBalanceChecker( $ledger );
$redemption = new GiftCardRedemptionService( $ledger );
$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';

function gcqa_row( string $name, string $status, array $extra = array() ): array {
	return array_merge(
		array(
			'scenario' => $name,
			'status'   => $status,
		),
		$extra
	);
}

// Balance checker enabled.
$lookup_ok = $checker->lookup( 'INVALID-CODE-FOR-QA' );
$evidence['scenarios'][] = gcqa_row(
	'balance_checker_invalid_code',
	empty( $lookup_ok['ok'] ) ? 'pass' : 'fail',
	array( 'error_generic' => (string) ( $lookup_ok['error'] ?? '' ) )
);

$issued = $ledger->issue( 30.0, $currency, null, $recipient );
$plain  = $issued->get_plain_code();
$card_id = (int) $issued->get_card()->get_id();
$valid = $checker->lookup( $plain ?? '' );
$json_valid = wp_json_encode( $valid );
$evidence['scenarios'][] = gcqa_row(
	'balance_checker_valid_masked',
	! empty( $valid['ok'] ) && is_string( $json_valid ) && ! str_contains( $json_valid, (string) $plain ) ? 'pass' : 'fail',
	array(
		'gift_card_id' => $card_id,
		'masked_code'  => (string) ( $valid['masked_code'] ?? '' ),
	)
);

$rate_key = GiftCardBalanceChecker::rate_limit_transient_key( '127.0.0.1' );
$evidence['scenarios'][] = gcqa_row(
	'balance_checker_rate_limit_key',
	str_starts_with( $rate_key, 'mp_cp_gc_balance_' ) ? 'pass' : 'fail',
	array( 'rate_limit_key' => $rate_key )
);

$prev_checker = $settings->gift_card_balance_checker_enabled();
update_option( Settings::OPTION_GIFT_CARD_BALANCE_CHECKER, 'no' );
$html_disabled = do_shortcode( '[mp_cp_gift_card_balance]' );
update_option( Settings::OPTION_GIFT_CARD_BALANCE_CHECKER, $prev_checker ? 'yes' : 'no' );
$evidence['scenarios'][] = gcqa_row(
	'balance_checker_disabled_shortcode',
	str_contains( $html_disabled, 'unavailable' ) ? 'pass' : 'partial',
	array( 'notes' => 'Shortcode should show unavailable message when disabled.' )
);

$mail_diag = ( new GiftCardMailDiagnostics( $wpdb ) )->analyze();
$delivery_diag = ( new GiftCardDeliveryDiagnostics( $wpdb ) )->analyze();
$evidence['scenarios'][] = gcqa_row(
	'mail_diagnostics',
	'pass',
	array(
		'wp_mail_likely_failing' => ! empty( $mail_diag['wp_mail_likely_failing'] ),
		'recent_delivery_failed' => (int) ( $mail_diag['recent_delivery_failed'] ?? 0 ),
		'delivery_failed_orders' => count( $delivery_diag['delivery_failed'] ?? array() ),
	)
);

$customer_id = 0;
if ( function_exists( 'get_user_by' ) ) {
	$user = get_user_by( 'email', $recipient );
	if ( $user ) {
		$customer_id = (int) $user->ID;
	}
}
$svc = new GiftCardCustomerService( $repo, $wpdb );
$purchased = $customer_id > 0 ? $svc->list_purchased( $customer_id ) : array();
$received  = $svc->list_received( $recipient );
$evidence['scenarios'][] = gcqa_row(
	'my_account_lists',
	'pass',
	array(
		'customer_id' => $customer_id,
		'customer_email' => $recipient,
		'purchased_count' => count( $purchased ),
		'received_count' => count( $received ),
		'endpoint' => GiftCardMyAccount::ENDPOINT_GIFT_CARDS,
	)
);

$payable = 50.0;
$partial = $redemption->preview_apply_amount( $issued->get_card(), $payable );
$evidence['scenarios'][] = gcqa_row(
	'checkout_redemption_partial_preview',
	$partial > 0 && $partial < $issued->get_card()->get_balance() ? 'pass' : 'partial',
	array(
		'gift_card_id' => $card_id,
		'preview_amount' => $partial,
		'card_balance' => $issued->get_card()->get_balance(),
	)
);

$full_card = $ledger->issue( 10.0, $currency, null, null );
$full_amt = $redemption->preview_apply_amount( $full_card->get_card(), 10.0 );
$evidence['scenarios'][] = gcqa_row(
	'checkout_redemption_full_preview',
	abs( $full_amt - 10.0 ) < 0.01 ? 'pass' : 'fail',
	array( 'gift_card_id' => (int) $full_card->get_card()->get_id(), 'preview_amount' => $full_amt )
);

if ( $customer_id > 0 ) {
	$wallet = new StoreCreditWallet( new StoreCreditAccountService( $repo ), $ledger );
	$wallet->grant_credit( $customer_id, 5.0, $currency, 'QA grant' );
	$bal = $wallet->get_balance( $customer_id, $currency );
	$evidence['scenarios'][] = gcqa_row(
		'store_credit_grant',
		$bal >= 5.0 ? 'pass' : 'fail',
		array( 'customer_id' => $customer_id, 'balance' => $bal )
	);
}

$catalog_ids = GiftCardQaProductSetup::find_published_gift_card_product_ids( 5 );
$qa_ids      = array();
if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
	$qa_id = (int) wc_get_product_id_by_sku( GiftCardQaProductSetup::PRODUCT_SKU );
	if ( $qa_id > 0 ) {
		$qa_ids[] = $qa_id;
	}
}
$product_id = $qa_ids[0] ?? ( $catalog_ids[0] ?? 0 );
$evidence['scenarios'][] = gcqa_row(
	'gift_card_product_detected',
	$product_id > 0 ? 'pass' : 'partial',
	array(
		'product_id'    => $product_id,
		'catalog_count' => count( $catalog_ids ),
		'notes'         => 'Run gift-card-product-setup.php if empty. Browser checkout optional.',
	)
);

$encoded = wp_json_encode( $evidence, JSON_PRETTY_PRINT );
WP_CLI::log( is_string( $encoded ) ? $encoded : '{}' );
