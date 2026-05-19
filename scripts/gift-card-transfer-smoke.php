<?php
/**
 * Smoke: gift card recipient transfer (unused cards only).
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-transfer-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run via WP-CLI eval-file inside WordPress.\n";
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );


use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardCustomerService;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransferService;
use MP\CommercePromotions\GiftCard\GiftCardTransferStore;
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

echo "=== Gift card transfer smoke ===\n";

$suffix = (string) time();
$from_email = 'transfer-smoke-from-' . $suffix . '@example.org';
$to_email   = 'transfer-smoke-to-' . $suffix . '@example.org';

$repo      = new GiftCardRepository( $wpdb );
$tx        = new GiftCardTransactionRepository( $wpdb );
$ledger    = new GiftCardLedger( $repo, $tx );
$settings  = new Settings();
$settings->set_gift_card_delivery_email_enabled( true );
$transfers = new GiftCardTransferService( $ledger, $repo, $settings );
$customers = new GiftCardCustomerService( $repo, $wpdb );

$issued = $ledger->issue( 1.0, 'EUR', null, $from_email, 'smoke', null, 2 );
$id     = (int) $issued->get_card()->get_id();
$plain  = $issued->get_plain_code();

if ( $id <= 0 ) {
	$fail( 'issue card' );
	exit( 1 );
}
$pass( 'issued card #' . $id );

$received_before = $customers->list_received( $to_email );
if ( $received_before === array() ) {
	$pass( 'unregistered recipient not in My Account before transfer' );
} else {
	$fail( 'unregistered recipient not in My Account before transfer' );
}

$result = $transfers->transfer_to_new_recipient(
	$id,
	$to_email,
	'CLI transfer smoke',
	GiftCardTransferService::INITIATED_BY_ADMIN,
	null,
	'Smoke Recipient',
	'Transfer smoke message'
);

if ( ! empty( $result['success'] ) ) {
	$pass( 'transfer succeeded' );
} else {
	$fail( 'transfer succeeded', (string) ( $result['message'] ?? '' ) );
}

$old = $repo->find( $id );
if ( $old !== null && $old->get_status() === GiftCard::STATUS_VOIDED ) {
	$pass( 'old card voided' );
} else {
	$fail( 'old card voided' );
}

$new_id = (int) ( $result['new_gift_card_id'] ?? 0 );
$new    = $repo->find( $new_id );
if ( $new !== null && $new->get_recipient_email() === $to_email ) {
	$pass( 'new card has recipient email' );
} else {
	$fail( 'new card has recipient email' );
}

$link = ( new GiftCardTransferStore() )->get_replacement_id( $id );
if ( $link === $new_id ) {
	$pass( 'transfer link recorded' );
} else {
	$fail( 'transfer link recorded' );
}

$opt = wp_json_encode( get_option( GiftCardTransferStore::OPTION_KEY, array() ) );
if ( is_string( $opt ) && strpos( $opt, $plain ) === false ) {
	$pass( 'plain code not in transfer option' );
} else {
	$fail( 'plain code not in transfer option' );
}

$partial = $ledger->issue( 2.0, 'EUR', null, 'partial-' . $suffix . '@example.org' );
$partial_id = (int) $partial->get_card()->get_id();
$ledger->redeem( $partial_id, 0.5, 99999 );
$blocked = $transfers->transfer_to_new_recipient(
	$partial_id,
	'blocked-' . $suffix . '@example.org',
	'Should block',
	GiftCardTransferService::INITIATED_BY_ADMIN
);
if ( empty( $blocked['success'] ) ) {
	$pass( 'partially used card blocked' );
} else {
	$fail( 'partially used card blocked' );
}

echo "=== Done; failures: {$failures} ===\n";
exit( $failures > 0 ? 1 : 0 );
