<?php
/**
 * WP-CLI smoke: gift card ledger foundation (schema 1.18.0).
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-ledger-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Admin\GiftCardsPage;
use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\Infrastructure\Database\MigrationRunner;
use MP\CommercePromotions\Infrastructure\Database\Schema;

$GLOBALS['gc_smoke_failures'] = 0;

function gc_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['gc_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

gc_smoke_assert( Schema::SCHEMA_VERSION === '1.19.0', 'schema version 1.19.0' );

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable' );
}

$migration = new MigrationRunner( $wpdb );
if ( $migration->needs_migration() ) {
	$migration->run();
}

$stored_schema = get_option( MigrationRunner::OPTION_SCHEMA_VERSION, '' );
gc_smoke_assert(
	$stored_schema === '' || version_compare( (string) $stored_schema, '1.19.0', '>=' ),
	'stored schema >= 1.19.0 after migration'
);

$repo   = new GiftCardRepository( $wpdb );
$tx     = new GiftCardTransactionRepository( $wpdb );
$ledger = new GiftCardLedger( $repo, $tx );

$issued = $ledger->issue( 100.0, 'EUR', null, null, 'smoke test issue' );
$plain  = $issued->get_plain_code();
gc_smoke_assert( $plain !== '', 'issue returns plain code' );
WP_CLI::log( 'Plain code (smoke only): ' . $plain );

$card = $ledger->find_by_plain_code( $plain );
gc_smoke_assert( $card !== null, 'lookup by plain code works' );
gc_smoke_assert( $card !== null && $card->get_balance() === 100.0, 'initial balance 100' );

$id = $card !== null ? $card->get_id() : null;
gc_smoke_assert( $id !== null && $id > 0, 'card has id' );

if ( $id !== null ) {
	$ledger->redeem( $id, 30.0, 900001, null, 'smoke partial' );
	$after = $ledger->find( $id );
	gc_smoke_assert( $after !== null && $after->get_balance() === 70.0, 'partial redeem leaves 70 balance' );

	$ledger->refund_redemption( $id, 30.0, 900001, 'smoke reversal' );
	$restored = $ledger->find( $id );
	gc_smoke_assert( $restored !== null && $restored->get_balance() === 100.0, 'reversal restores balance' );

	$void_test = $ledger->issue( 5.0, 'EUR', null, null, 'void target' );
	$void_id   = $void_test->get_card()->get_id();
	if ( $void_id !== null ) {
		$ledger->void_card( $void_id, 'smoke void' );
		$voided = false;
		try {
			$ledger->redeem( $void_id, 1.0, 900002 );
		} catch ( InvalidArgumentException $e ) {
			$voided = true;
		}
		gc_smoke_assert( $voided, 'void prevents redemption' );
	}
}

gc_smoke_assert( class_exists( GiftCardsPage::class ), 'GiftCardsPage class loaded' );

$reports = new GiftCardReports( $wpdb );
$summary = $reports->summary();
$keys    = array(
	'active_outstanding_liability',
	'total_issued',
	'total_redeemed',
	'total_adjusted',
	'total_voided',
	'depleted_count',
	'expired_count',
);
foreach ( $keys as $key ) {
	gc_smoke_assert( array_key_exists( $key, $summary ), 'reports summary key: ' . $key );
}

if ( $GLOBALS['gc_smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( 'Gift card ledger smoke finished with %d failure(s).', (int) $GLOBALS['gc_smoke_failures'] ) );
}

WP_CLI::success( 'Gift card ledger smoke passed.' );
