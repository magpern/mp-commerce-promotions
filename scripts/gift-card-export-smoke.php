<?php
/**
 * Smoke: gift card ledger CSV exports (no full codes or hashes).
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-export-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run via WP-CLI eval-file inside WordPress.\n";
	exit( 1 );
}

use MP\CommercePromotions\Admin\GiftCardExportHandler;
use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardExportTracker;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardLedgerExporter;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;

global $wpdb;

if ( ! $wpdb instanceof wpdb ) {
	echo "FAIL: wpdb unavailable\n";
	exit( 1 );
}

$failures = 0;
$pass     = static function ( string $label ) use ( &$failures ): void {
	echo "PASS: {$label}\n";
};
$fail = static function ( string $label, string $detail = '' ) use ( &$failures ): void {
	++$failures;
	echo 'FAIL: ' . $label . ( $detail !== '' ? " ({$detail})" : '' ) . "\n";
};

echo "=== Gift card export smoke ===\n";

$handler_src = (string) file_get_contents(
	dirname( __DIR__ ) . '/src/Admin/GiftCardExportHandler.php'
);
if ( strpos( $handler_src, 'manage_woocommerce' ) !== false
	&& strpos( $handler_src, GiftCardExportHandler::SUBMIT_GIFT_CARDS ) !== false
	&& strpos( $handler_src, 'gift_card.export_csv' ) !== false ) {
	$pass( 'export handler enforces capability, POST, and audit' );
} else {
	$fail( 'export handler enforces capability, POST, and audit' );
}

$repo       = new GiftCardRepository( $wpdb );
$tx_repo    = new GiftCardTransactionRepository( $wpdb );
$reports    = new GiftCardReports( $wpdb );
$exporter   = new GiftCardLedgerExporter( $repo, $tx_repo, $reports );
$ledger     = new GiftCardLedger( $repo, $tx_repo );

delete_option( GiftCardExportTracker::OPTION_TIMESTAMPS );

$issued = $ledger->issue( 12.5, 'EUR', null, null, 'export-smoke' );
$plain  = $issued->get_plain_code();
$card   = $issued->get_card();
$card_id = $card->get_id();

$gift_csv = $exporter->gift_cards_csv();
$header   = strtok( $gift_csv, "\n" ) ?: '';
if ( strpos( $header, 'masked_code' ) !== false && strpos( $header, 'code_hash' ) === false ) {
	$pass( 'gift cards CSV headers' );
} else {
	$fail( 'gift cards CSV headers', $header );
}

if ( $plain !== '' && strpos( $gift_csv, $plain ) === false ) {
	$pass( 'gift cards export omits full code' );
} else {
	$fail( 'gift cards export omits full code' );
}

$hash = GiftCardRepository::hash_plain_code( $plain );
if ( strpos( $gift_csv, $hash ) === false ) {
	$pass( 'gift cards export omits code hash' );
} else {
	$fail( 'gift cards export omits code hash' );
}

if ( $card_id !== null && strpos( $gift_csv, '****' ) !== false ) {
	$pass( 'gift cards export includes masked code' );
} else {
	$fail( 'gift cards export includes masked code' );
}

GiftCardExportTracker::record_export( GiftCardExportTracker::TYPE_GIFT_CARDS );
$tx_csv = $exporter->transactions_csv();
if ( strpos( $tx_csv, 'transaction_type' ) !== false && strpos( $tx_csv, 'issued' ) !== false ) {
	$pass( 'transactions export includes ledger rows' );
} else {
	$fail( 'transactions export includes ledger rows' );
}

GiftCardExportTracker::record_export( GiftCardExportTracker::TYPE_TRANSACTIONS );
$liability_csv = $exporter->liability_summary_csv();
if ( strpos( $liability_csv, 'currency' ) !== false
	&& strpos( $liability_csv, 'combined_liability' ) !== false
	&& strpos( $liability_csv, 'EUR' ) !== false ) {
	$pass( 'liability summary groups by currency' );
} else {
	$fail( 'liability summary groups by currency' );
}

GiftCardExportTracker::record_export( GiftCardExportTracker::TYPE_LIABILITY );
$timestamps = GiftCardExportTracker::get_timestamps();
if ( isset( $timestamps[ GiftCardExportTracker::TYPE_GIFT_CARDS ] )
	&& isset( $timestamps[ GiftCardExportTracker::TYPE_TRANSACTIONS ] )
	&& isset( $timestamps[ GiftCardExportTracker::TYPE_LIABILITY ] ) ) {
	$pass( 'last export timestamps recorded' );
} else {
	$fail( 'last export timestamps recorded' );
}

try {
	GiftCardExportHandler::assert_csv_has_no_secrets( $gift_csv );
	$pass( 'export secret guard accepts masked export' );
} catch ( \Throwable $e ) {
	$fail( 'export secret guard accepts masked export', $e->getMessage() );
}

$doc = dirname( __DIR__ ) . '/docs/GIFT_CARD_BACKUP_EXPORT.md';
if ( is_readable( $doc ) && strpos( (string) file_get_contents( $doc ), 'mp_cp_gift_cards' ) !== false ) {
	$pass( 'backup export documentation present' );
} else {
	$fail( 'backup export documentation present' );
}

echo "=== Done; failures: {$failures} ===\n";
exit( $failures > 0 ? 1 : 0 );
