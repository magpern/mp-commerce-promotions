<?php
/**
 * Smoke: shopper remove / restore Commerce promotions in cart.
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/promotion-remove-from-cart-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run via WP-CLI eval-file inside WordPress.\n";
	exit( 1 );
}

use MP\CommercePromotions\Woo\AppliedPromotionSession;
use MP\CommercePromotions\Woo\CartPromotionApplier;
use MP\CommercePromotions\Woo\CartPromotionRemovalController;
use MP\CommercePromotions\Woo\CartSessionHelper;
use MP\CommercePromotions\Woo\PromotionCartExclusionSession;
use MP\CommercePromotions\Woo\PromotionFeeLabelResolver;

$failures = 0;
$pass     = static function ( string $label ) use ( &$failures ): void {
	echo "PASS: {$label}\n";
};
$fail = static function ( string $label, string $detail = '' ) use ( &$failures ): void {
	++$failures;
	echo 'FAIL: ' . $label . ( $detail !== '' ? " ({$detail})" : '' ) . "\n";
};

echo "=== Promotion remove from cart smoke ===\n";

$src = (string) file_get_contents(
	dirname( __DIR__ ) . '/src/Woo/CartPromotionApplier.php'
);
$controller_src = (string) file_get_contents(
	dirname( __DIR__ ) . '/src/Woo/CartPromotionRemovalController.php'
);

if ( strpos( $src, 'PromotionCartExclusionSession::filter_promotions' ) !== false ) {
	$pass( 'cart applier filters session-excluded promotions' );
} else {
	$fail( 'cart applier filters session-excluded promotions' );
}

if ( strpos( $src, 'PromotionCartExclusionSession::is_excluded' ) !== false ) {
	$pass( 'cart applier skips excluded decisions' );
} else {
	$fail( 'cart applier skips excluded decisions' );
}

if ( strpos( $controller_src, 'woocommerce_cart_totals_fee_html' ) !== false
	&& strpos( $controller_src, 'mp-cp-cart-promotion-remove' ) !== false ) {
	$pass( 'cart totals fee remove link hook present' );
} else {
	$fail( 'cart totals fee remove link hook present' );
}

$exclusion_src = (string) file_get_contents(
	dirname( __DIR__ ) . '/src/Woo/PromotionCartExclusionSession.php'
);
if ( strpos( $exclusion_src, PromotionCartExclusionSession::SESSION_KEY ) !== false
	&& strpos( $controller_src, 'PromotionCartExclusionSession' ) !== false ) {
	$pass( 'session exclusion key defined' );
} else {
	$fail( 'session exclusion key defined' );
}

$entry = array(
	'promotion_id'    => 9001,
	'promotion_uuid'  => '00000000-0000-4000-8000-000000009001',
	'promotion_name'  => 'Smoke remove promo',
	'discount_amount' => 1.0,
	'action_type'     => CartPromotionApplier::ACTION_PERCENTAGE_DISCOUNT,
);

$label = PromotionFeeLabelResolver::label_from_entry( $entry );
if ( $label !== null && PromotionFeeLabelResolver::promotion_id_from_fee_label( $label, array( $entry ) ) === 9001 ) {
	$pass( 'fee label maps to promotion id' );
} else {
	$fail( 'fee label maps to promotion id', (string) $label );
}

if ( strpos( $label ?? '', 'Smoke remove promo' ) !== false && strpos( $label ?? '', 'Commerce promotion:' ) === 0 ) {
	$pass( 'fee label uses commerce promotion prefix' );
} else {
	$fail( 'fee label uses commerce promotion prefix' );
}

if ( CartSessionHelper::has_wc_session() ) {
	PromotionCartExclusionSession::clear_all();
	PromotionCartExclusionSession::exclude( 9001 );
	if ( PromotionCartExclusionSession::is_excluded( 9001 ) ) {
		$pass( 'exclude stores promotion id in session' );
	} else {
		$fail( 'exclude stores promotion id in session' );
	}

	CartSessionHelper::set_applied_promotion(
		AppliedPromotionSession::build_session_payload( array( $entry ) )
	);
	PromotionCartExclusionSession::exclude( 9001 );
	$session_entries = AppliedPromotionSession::entries_from_session(
		CartSessionHelper::get_applied_promotion()
	);
	if ( $session_entries !== array() && (int) $session_entries[0]['promotion_id'] === 9001 ) {
		$pass( 'applied promotion session still readable after exclude' );
	} else {
		$fail( 'applied promotion session still readable after exclude' );
	}

	PromotionCartExclusionSession::clear_all();
	if ( ! PromotionCartExclusionSession::has_exclusions() ) {
		$pass( 'restore clears session exclusions' );
	} else {
		$fail( 'restore clears session exclusions' );
	}
} else {
	$pass( 'wc session unavailable — skip live session checks' );
}

if ( has_action( 'woocommerce_cart_totals_fee_html' ) ) {
	$pass( 'fee html filter registered' );
} else {
	$fail( 'fee html filter registered' );
}

if ( CartPromotionRemovalController::NONCE_REMOVE === 'mp_cp_remove_promotion' ) {
	$pass( 'remove nonce action constant' );
} else {
	$fail( 'remove nonce action constant' );
}

echo "=== Done; failures: {$failures} ===\n";
exit( $failures > 0 ? 1 : 0 );
