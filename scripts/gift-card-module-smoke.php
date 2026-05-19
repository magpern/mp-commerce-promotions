<?php
/**
 * Consolidated gift card & store credit module smoke.
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-module-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run via WP-CLI eval-file inside WordPress.\n";
	exit( 1 );
}

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryMailer;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardEmailPreview;
use MP\CommercePromotions\GiftCard\GiftCardIntegrityDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardMailDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardRedemptionService;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardScheduledDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransferService;
use MP\CommercePromotions\GiftCard\GiftCardTransferStore;
use MP\CommercePromotions\GiftCard\StoreCreditAccountService;
use MP\CommercePromotions\GiftCard\StoreCreditWallet;
use MP\CommercePromotions\Infrastructure\Database\MigrationRunner;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Service\Settings;

global $wpdb;

if ( ! $wpdb instanceof wpdb ) {
	echo "FAIL: wpdb unavailable\n";
	exit( 1 );
}

$failures = 0;
$pass     = static function ( string $label ) use ( &$failures ): void {
	echo "PASS: {$label}\n";
};
$fail     = static function ( string $label, string $detail = '' ) use ( &$failures ): void {
	++$failures;
	echo 'FAIL: ' . $label . ( $detail !== '' ? " ({$detail})" : '' ) . "\n";
};

echo "=== Gift card module smoke ===\n";

$migration = new MigrationRunner( $wpdb );
if ( $migration->needs_migration() ) {
	$migration->run();
}
$stored_schema = (string) get_option( MigrationRunner::OPTION_SCHEMA_VERSION, '' );
if ( version_compare( $stored_schema !== '' ? $stored_schema : '0', Schema::SCHEMA_VERSION, '>=' ) ) {
	$pass( 'schema current (' . Schema::SCHEMA_VERSION . ')' );
} else {
	$fail( 'schema current', 'stored=' . $stored_schema );
}

$repo      = new GiftCardRepository( $wpdb );
$tx        = new GiftCardTransactionRepository( $wpdb );
$ledger    = new GiftCardLedger( $repo, $tx );
$settings  = new Settings();
$redemption = new GiftCardRedemptionService( $ledger );

$issued = $ledger->issue( 25.0, 'EUR', null, null, 'module-smoke' );
$plain  = $issued->get_plain_code();
$id     = (int) $issued->get_card()->get_id();
if ( $id > 0 && $plain !== '' ) {
	$pass( 'gift card issue #' . $id );
} else {
	$fail( 'gift card issue' );
}

$row_json = wp_json_encode( $repo->find( $id ) );
if ( is_string( $row_json ) && strpos( $row_json, $plain ) === false ) {
	$pass( 'no plain code on card row' );
} else {
	$fail( 'no plain code on card row' );
}

$preview = GiftCardEmailPreview::render( $settings );
if ( strpos( $preview, GiftCardEmailPreview::SAMPLE_MASKED_CODE ) !== false ) {
	$pass( 'email preview sample only' );
} else {
	$fail( 'email preview sample only' );
}

$settings->set_gift_card_delivery_email_enabled( false );
$mailer_off = new GiftCardDeliveryMailer( $settings );
$disabled   = $mailer_off->send_test_delivery_email(
	function_exists( 'get_option' ) ? sanitize_email( (string) get_option( 'admin_email' ) ) : 'qa@example.org'
);
$settings->set_gift_card_delivery_email_enabled( true );
if ( ( $disabled['delivery_status'] ?? '' ) === GiftCardDeliveryStatus::DISABLED ) {
	$pass( 'mail disabled setting respected' );
} else {
	$fail( 'mail disabled setting respected' );
}

$void_issue = $ledger->issue( 5.0, 'EUR' );
$void_id    = (int) $void_issue->get_card()->get_id();
$ledger->void_card( $void_id, 'smoke' );
$void_card  = $repo->find( $void_id );
if ( $void_card !== null && $redemption->redeemability_error( $void_card ) !== null ) {
	$pass( 'voided card blocked at redemption' );
} else {
	$fail( 'voided card blocked at redemption' );
}

$transfers = new GiftCardTransferService( $ledger, $repo, $settings );
$suffix    = (string) time();
$xfer      = $ledger->issue( 3.0, 'EUR', null, 'xfer-' . $suffix . '@example.org', 'smoke', null, 2 );
$xfer_id   = (int) $xfer->get_card()->get_id();
$xfer_plain = $xfer->get_plain_code();
$result    = $transfers->transfer_to_new_recipient(
	$xfer_id,
	'xfer-to-' . $suffix . '@example.org',
	'module smoke transfer',
	GiftCardTransferService::INITIATED_BY_ADMIN
);
if ( ! empty( $result['success'] ) ) {
	$pass( 'transfer unused card' );
} else {
	$fail( 'transfer unused card', (string) ( $result['message'] ?? '' ) );
}
$opt_xfer = wp_json_encode( get_option( GiftCardTransferStore::OPTION_KEY, array() ) );
if ( is_string( $opt_xfer ) && strpos( $opt_xfer, $xfer_plain ) === false ) {
	$pass( 'transfer option has no plain code' );
} else {
	$fail( 'transfer option has no plain code' );
}

$partial = $ledger->issue( 4.0, 'EUR' );
$partial_id = (int) $partial->get_card()->get_id();
$ledger->redeem( $partial_id, 1.0, 999001 );
$blocked = $transfers->transfer_to_new_recipient(
	$partial_id,
	'blocked-' . $suffix . '@example.org',
	'block',
	GiftCardTransferService::INITIATED_BY_ADMIN
);
if ( empty( $blocked['success'] ) ) {
	$pass( 'partial transfer blocked' );
} else {
	$fail( 'partial transfer blocked' );
}

$depleted_xfer = $ledger->issue( 2.0, 'EUR' );
$depleted_id   = (int) $depleted_xfer->get_card()->get_id();
$ledger->redeem( $depleted_id, 2.0, 999002 );
$depleted_card = $repo->find( $depleted_id );
if ( $depleted_card !== null && ! $transfers->can_transfer( $depleted_card ) ) {
	$pass( 'depleted card transfer blocked' );
} else {
	$fail( 'depleted card transfer blocked' );
}

$accounts = new StoreCreditAccountService( $repo );
$wallet   = new StoreCreditWallet( $accounts, $ledger );
$customer = 2;
$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR';
$wallet->grant_credit( $customer, 5.0, $currency, 'module smoke grant' );
$balance  = $wallet->get_balance( $customer, $currency );
if ( $balance >= 5.0 ) {
	$pass( 'store credit grant' );
} else {
	$fail( 'store credit grant' );
}

$reports = ( new GiftCardReports( $wpdb ) )->summary();
$required_report_keys = array(
	'gift_card_outstanding_liability',
	'store_credit_outstanding_liability',
	'gift_cards_sold_from_products',
);
foreach ( $required_report_keys as $key ) {
	if ( array_key_exists( $key, $reports ) ) {
		$pass( 'reports key ' . $key );
	} else {
		$fail( 'reports key ' . $key );
	}
}

$mail_diag = ( new GiftCardMailDiagnostics( $wpdb ) )->analyze();
if ( isset( $mail_diag['email_style'], $mail_diag['woo_email_style_available'], $mail_diag['settings_summary']['email_template'] ) ) {
	$pass( 'mail diagnostics keys' );
} else {
	$fail( 'mail diagnostics keys' );
}

$integrity = ( new GiftCardIntegrityDiagnostics( $wpdb, $repo, $ledger ) )->analyze();
if ( isset( $integrity['store_credit_missing_owner'], $integrity['store_credit_unexpected_code_hash'] ) ) {
	$pass( 'integrity diagnostics keys' );
} else {
	$fail( 'integrity diagnostics keys' );
}

$scheduled = ( new GiftCardScheduledDiagnostics( $wpdb ) )->analyze();
if ( isset( $scheduled['overdue'], $scheduled['unpaid_pending'], $scheduled['failed_scheduled'] ) ) {
	$pass( 'scheduled diagnostics keys' );
} else {
	$fail( 'scheduled diagnostics keys' );
}

echo "=== Done; failures: {$failures} ===\n";
exit( $failures > 0 ? 1 : 0 );
