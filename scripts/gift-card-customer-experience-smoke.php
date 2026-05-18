<?php
/**
 * WP-CLI smoke: customer gift card experience.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-customer-experience-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\GiftCard\GiftCardBalanceChecker;
use MP\CommercePromotions\GiftCard\GiftCardCustomerDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardEmailTemplate;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\GiftCardMyAccount;

$GLOBALS['gcxp_smoke_failures'] = 0;

function gcxp_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['gcxp_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable' );
}

$settings = new Settings();
gcxp_smoke_assert( $settings->gift_card_balance_checker_enabled(), 'balance checker setting default on' );
gcxp_smoke_assert( $settings->gift_card_my_account_enabled(), 'my account setting default on' );
gcxp_smoke_assert( in_array( $settings->gift_card_email_template(), Settings::gift_card_email_templates(), true ), 'email template valid' );

gcxp_smoke_assert( shortcode_exists( 'mp_cp_gift_card_balance' ), 'balance shortcode registered' );

$html = do_shortcode( '[mp_cp_gift_card_balance]' );
gcxp_smoke_assert( is_string( $html ) && str_contains( $html, 'mp-cp-gift-card-balance-checker' ), 'shortcode renders balance form' );
gcxp_smoke_assert( ! str_contains( $html, 'plain_code' ), 'shortcode output has no plain_code key' );

$preview = GiftCardEmailTemplate::render_html(
	$settings->gift_card_email_template(),
	array(
		'site_name' => 'Smoke',
		'preview'   => true,
		'cards'     => array(
			array( 'masked_code' => '****SMOK', 'amount' => 20, 'currency' => 'EUR' ),
		),
	)
);
gcxp_smoke_assert( str_contains( $preview, '****SMOK' ), 'email template preview uses masked code' );

$repo   = new GiftCardRepository( $wpdb );
$ledger = new GiftCardLedger( $repo, new GiftCardTransactionRepository( $wpdb ) );
$issued = $ledger->issue( 15.0, function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR', null, null );
$lookup = ( new GiftCardBalanceChecker( $ledger ) )->lookup( $issued->get_plain_code() );
gcxp_smoke_assert( ! empty( $lookup['ok'] ), 'balance lookup works' );
gcxp_smoke_assert( ! str_contains( wp_json_encode( $lookup ), $issued->get_plain_code() ), 'lookup response does not echo full code' );

$summary = ( new GiftCardReports( $wpdb ) )->summary();
foreach (
	array(
		'gift_card_avg_amount',
		'gift_card_redeemed_ratio',
		'store_credit_utilization_ratio',
		'scheduled_pending',
	) as $key
) {
	gcxp_smoke_assert( array_key_exists( $key, $summary ), 'report key: ' . $key );
}

$diag = ( new GiftCardCustomerDiagnostics( $wpdb ) )->analyze();
gcxp_smoke_assert( is_array( $diag ) && isset( $diag['missing_balance_page'] ), 'customer diagnostics keys exist' );

gcxp_smoke_assert( GiftCardMyAccount::ENDPOINT_GIFT_CARDS === 'gift-cards', 'my account endpoint slug' );

if ( $GLOBALS['gcxp_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Gift card customer experience smoke finished with ' . (int) $GLOBALS['gcxp_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Gift card customer experience smoke passed.' );
