<?php
/**
 * Gift card / store credit redemption UI smoke.
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-redemption-ui-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run via WP-CLI eval-file inside WordPress.\n";
	exit( 1 );
}

use MP\CommercePromotions\Woo\GiftCardRedemptionCheckout;
use MP\CommercePromotions\Woo\GiftCardSession;

$failures = 0;
$pass     = static function ( string $label ) use ( &$failures ): void {
	echo "PASS: {$label}\n";
};
$fail     = static function ( string $label, string $detail = '' ) use ( &$failures ): void {
	++$failures;
	echo 'FAIL: ' . $label . ( $detail !== '' ? " ({$detail})" : '' ) . "\n";
};

echo "=== Gift card redemption UI smoke ===\n";

$src_path = dirname( __DIR__ ) . '/src/Woo/GiftCardRedemptionCheckout.php';
$src      = is_readable( $src_path ) ? (string) file_get_contents( $src_path ) : '';

$required = array(
	'mp-cp-credit-accordion',
	'mp-cp-credit-accordion__toggle',
	'mp-cp-credit-accordion__body',
	'mp-cp-credit-chip',
	'mp-cp-credit-help',
	'mp_cp_gift_card_nonce',
	'mp_cp_gift_card_action',
	'mp_cp_store_credit_nonce',
);

foreach ( $required as $needle ) {
	if ( $src !== '' && strpos( $src, $needle ) !== false ) {
		$pass( 'markup contains ' . $needle );
	} else {
		$fail( 'markup contains ' . $needle );
	}
}

if ( $src !== '' && strpos( $src, 'mp-cp-gc-title' ) === false ) {
	$pass( 'legacy large panel title removed' );
} else {
	$fail( 'legacy large panel title removed' );
}

if ( ! GiftCardRedemptionCheckout::should_expand_accordion( null, null, 0.0, false, false ) ) {
	$pass( 'default collapsed logic' );
} else {
	$fail( 'default collapsed logic' );
}

GiftCardSession::set(
	array(
		'gift_card_id'   => 99999,
		'code_last4'     => 'ABCD',
		'applied_amount' => 12.5,
	)
);
if ( GiftCardRedemptionCheckout::should_expand_accordion( GiftCardSession::get(), null, 0.0, false, false ) ) {
	$pass( 'applied gift card expands accordion' );
} else {
	$fail( 'applied gift card expands accordion' );
}
GiftCardSession::clear();

if ( GiftCardRedemptionCheckout::should_expand_accordion( null, null, 20.0, true, false ) ) {
	$pass( 'available store credit expands accordion' );
} else {
	$fail( 'available store credit expands accordion' );
}

$css_path = dirname( __DIR__ ) . '/assets/css/gift-card-customer.css';
$css      = is_readable( $css_path ) ? (string) file_get_contents( $css_path ) : '';
if ( $css !== '' && strpos( $css, '.mp-cp-credit-accordion__toggle' ) !== false ) {
	$pass( 'accordion styles present' );
} else {
	$fail( 'accordion styles present' );
}

$js_path = dirname( __DIR__ ) . '/assets/js/gift-card-credit-accordion.js';
if ( is_readable( $js_path ) ) {
	$pass( 'accordion script present' );
} else {
	$fail( 'accordion script present' );
}

echo "=== Done; failures: {$failures} ===\n";
exit( $failures > 0 ? 1 : 0 );
