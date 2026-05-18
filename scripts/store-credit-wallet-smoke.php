<?php
/**
 * WP-CLI smoke: store credit wallet foundation (schema 1.19.0).
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/store-credit-wallet-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardIntegrityDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\GiftCard\StoreCreditAccountService;
use MP\CommercePromotions\GiftCard\StoreCreditWallet;
use MP\CommercePromotions\Infrastructure\Database\MigrationRunner;
use MP\CommercePromotions\Infrastructure\Database\Schema;

$GLOBALS['sc_smoke_failures'] = 0;

function sc_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['sc_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

sc_smoke_assert( Schema::SCHEMA_VERSION === '1.19.0', 'schema version 1.19.0' );

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable' );
}

$migration = new MigrationRunner( $wpdb );
if ( $migration->needs_migration() ) {
	$migration->run();
}

$stored_schema = get_option( MigrationRunner::OPTION_SCHEMA_VERSION, '' );
sc_smoke_assert(
	$stored_schema === '' || version_compare( (string) $stored_schema, '1.19.0', '>=' ),
	'stored schema >= 1.19.0 after migration'
);

$repo     = new GiftCardRepository( $wpdb );
$tx       = new GiftCardTransactionRepository( $wpdb );
$ledger   = new GiftCardLedger( $repo, $tx );
$accounts = new StoreCreditAccountService( $repo );
$wallet   = new StoreCreditWallet( $accounts, $ledger );

$customer_id = 999001;
$currency    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';

$created = $accounts->find_or_create_wallet( $customer_id, $currency );
sc_smoke_assert( $created->is_store_credit_wallet(), 'create customer wallet' );

$wallet->grant_credit( $customer_id, 80.0, $currency, 'smoke grant' );
sc_smoke_assert( $wallet->get_balance( $customer_id, $currency ) === 80.0, 'grant credit balance 80' );

$card = $accounts->find_wallet( $customer_id, $currency );
$account_id = $card !== null ? $card->get_id() : null;
sc_smoke_assert( $account_id !== null && $account_id > 0, 'wallet has id' );

if ( $account_id !== null ) {
	$ledger->redeem( $account_id, 25.0, 999002, $customer_id, 'smoke partial checkout' );
	sc_smoke_assert( $wallet->get_balance( $customer_id, $currency ) === 55.0, 'partial debit ledger' );

	$ledger->refund_redemption( $account_id, 25.0, 999002, 'smoke reversal' );
	sc_smoke_assert( $wallet->get_balance( $customer_id, $currency ) === 80.0, 'reversal restores balance' );
}

$reports = new GiftCardReports( $wpdb );
$summary = $reports->summary();
$report_keys = array(
	'gift_card_outstanding_liability',
	'store_credit_outstanding_liability',
	'combined_outstanding_liability',
	'store_credit_issued',
	'store_credit_redeemed',
	'refund_to_credit_total',
	'manual_adjustment_total',
);
foreach ( $report_keys as $key ) {
	sc_smoke_assert( array_key_exists( $key, $summary ), 'report key: ' . $key );
}
sc_smoke_assert( array_key_exists( 'liability_by_currency', $summary ), 'report key: liability_by_currency' );
sc_smoke_assert( is_array( $summary['liability_by_currency'] ), 'liability_by_currency is array' );

$diag   = new GiftCardIntegrityDiagnostics( $wpdb, $repo, $ledger );
$issues = $diag->analyze();
$diag_keys = array(
	'store_credit_missing_owner',
	'store_credit_unexpected_code_hash',
	'negative_balance',
	'balance_mismatch',
);
foreach ( $diag_keys as $key ) {
	sc_smoke_assert( array_key_exists( $key, $issues ), 'diagnostics key: ' . $key );
}

if ( $GLOBALS['sc_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Store credit wallet smoke finished with ' . $GLOBALS['sc_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Store credit wallet smoke passed.' );
