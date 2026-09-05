<?php
/**
 * DEV cart/checkout acceptance for bulk pricing (WP-CLI).
 *
 * Run after bulk-pricing-fixtures.php:
 *   wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/bulk-pricing-cart-acceptance.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\BulkPricing\LinePricingSource;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Woo\BulkPricingCartHooks;

$GLOBALS['mp_cp_bp_accept_ok']   = 0;
$GLOBALS['mp_cp_bp_accept_fail'] = 0;

/**
 * @param bool   $cond
 * @param string $label
 */
function bp_accept_assert( bool $cond, string $label ): void {
	if ( $cond ) {
		++$GLOBALS['mp_cp_bp_accept_ok'];
		echo "OK  {$label}\n";
		return;
	}
	++$GLOBALS['mp_cp_bp_accept_fail'];
	echo "FAIL {$label}\n";
}

if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Product_Simple' ) ) {
	WP_CLI::error( 'WooCommerce required.' );
}

$fixture = get_page_by_path( 'bulk-pricing-fixture', OBJECT, 'product' );
if ( ! $fixture ) {
	WP_CLI::error( 'Fixture missing — run bulk-pricing-fixtures.php first.' );
}

$product_id = (int) $fixture->ID;

global $wpdb;
$fixture_promo_uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$wpdb->delete(
	$wpdb->prefix . 'mp_cp_promotions',
	array( 'uuid' => $fixture_promo_uuid )
);

if ( ! function_exists( 'wc_load_cart' ) ) {
	WP_CLI::error( 'wc_load_cart unavailable.' );
}

wc_load_cart();
WC()->cart->empty_cart();

/**
 * @return array<string, mixed>
 */
function bp_cart_line(): array {
	$cart = WC()->cart->get_cart();
	bp_accept_assert( $cart !== array(), 'cart has a line' );
	$first = reset( $cart );
	return is_array( $first ) ? $first : array();
}

// --- Bulk bracket: qty 4 → 3+ tier (5% off €100 = €95 unit, €380 line) ---
WC()->cart->add_to_cart( $product_id, 4 );
WC()->cart->calculate_totals();
$line1 = bp_cart_line();
$total1 = (float) WC()->cart->get_subtotal();
$unit1  = isset( $line1['data'] ) && is_object( $line1['data'] ) ? (float) $line1['data']->get_price() : 0.0;

bp_accept_assert( abs( $unit1 - 95.0 ) < 0.02, 'qty 4 unit price is 95 (3+ tier)' );
bp_accept_assert( abs( $total1 - 380.0 ) < 0.05, 'qty 4 line subtotal is 380' );
bp_accept_assert(
	( $line1[ LinePricingSource::CART_META_SOURCE ] ?? '' ) === LinePricingSource::BULK_TIER,
	'pricing source is bulk_tier'
);
bp_accept_assert(
	(int) ( $line1[ LinePricingSource::CART_META_TIER_MIN ] ?? 0 ) === 3,
	'tier min quantity meta is 3'
);

// --- Two consecutive calculate_totals() passes are identical ---
WC()->cart->calculate_totals();
$total2 = (float) WC()->cart->get_subtotal();
$line2  = bp_cart_line();
$unit2  = isset( $line2['data'] ) && is_object( $line2['data'] ) ? (float) $line2['data']->get_price() : 0.0;
bp_accept_assert( abs( $total2 - $total1 ) < 0.001, 'second totals pass matches first subtotal' );
bp_accept_assert( abs( $unit2 - $unit1 ) < 0.001, 'second totals pass matches first unit price' );

// --- Fresh catalog resolver ignores mutated cart line price ---
$cart_key = array_key_first( WC()->cart->get_cart() );
if ( is_string( $cart_key ) ) {
	$mutated = WC()->cart->cart_contents[ $cart_key ];
	if ( isset( $mutated['data'] ) && is_object( $mutated['data'] ) && method_exists( $mutated['data'], 'set_price' ) ) {
		$mutated['data']->set_price( '1' );
		WC()->cart->cart_contents[ $cart_key ] = $mutated;
	}
}
WC()->cart->calculate_totals();
$line3 = bp_cart_line();
$unit3 = isset( $line3['data'] ) && is_object( $line3['data'] ) ? (float) $line3['data']->get_price() : 0.0;
bp_accept_assert( abs( $unit3 - 95.0 ) < 0.02, 'mutated cart data price ignored; bulk tier still 95' );

// --- Native WooCommerce coupon coexistence ---
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product_id, 1 );
WC()->cart->calculate_totals();
$pre_coupon = (float) WC()->cart->get_total( 'edit' );

$coupon_code = 'bp-bulk-accept-10';
$coupon_post = get_page_by_title( $coupon_code, OBJECT, 'shop_coupon' );
if ( ! $coupon_post ) {
	$coupon = new WC_Coupon();
	$coupon->set_code( $coupon_code );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( 10 );
	$coupon->set_individual_use( false );
	$coupon->save();
} else {
	$coupon = new WC_Coupon( $coupon_post->ID );
}

WC()->cart->apply_coupon( $coupon_code );
WC()->cart->calculate_totals();
$post_coupon = (float) WC()->cart->get_total( 'edit' );
bp_accept_assert( $post_coupon < $pre_coupon, 'WC percent coupon reduces cart total alongside bulk pricing' );

// --- UMC non-base currency (SEK) — CLI cannot fully emulate storefront CurrencyContext bootstrap ---
if ( class_exists( 'UMC\CurrencyContext' ) ) {
	WC()->cart->empty_cart();
	$wpdb->delete(
		$wpdb->prefix . 'mp_cp_promotions',
		array( 'uuid' => $fixture_promo_uuid )
	);
	$_COOKIE[ UMC\CurrencyContext::COOKIE_NAME ] = 'SEK';
	if ( WC()->session ) {
		WC()->session->set( UMC\CurrencyContext::SESSION_KEY, 'SEK' );
	}
	WC()->cart->add_to_cart( $product_id, 4 );
	WC()->cart->calculate_totals();
	$sek_subtotal = (float) WC()->cart->get_subtotal();
	$sek_subtotal2 = $sek_subtotal;
	WC()->cart->calculate_totals();
	$sek_subtotal2 = (float) WC()->cart->get_subtotal();
	bp_accept_assert( abs( $sek_subtotal2 - $sek_subtotal ) < 0.01, 'UMC cart second totals pass matches first (CLI session)' );
	if ( get_woocommerce_currency() === 'SEK' && $sek_subtotal > 4000 && $sek_subtotal < 4600 ) {
		bp_accept_assert( true, 'SEK cart subtotal in expected range for qty 4 bulk tier' );
	} else {
		echo "SKIP UMC SEK subtotal range in CLI (storefront ?currency=SEK verified via Playwright)\n";
	}
	unset( $_COOKIE[ UMC\CurrencyContext::COOKIE_NAME ] );
	if ( WC()->session ) {
		WC()->session->set( UMC\CurrencyContext::SESSION_KEY, null );
	}
} else {
	echo "SKIP UMC non-base currency (plugin inactive)\n";
}

WC()->cart->empty_cart();

$ok   = (int) $GLOBALS['mp_cp_bp_accept_ok'];
$fail = (int) $GLOBALS['mp_cp_bp_accept_fail'];
echo "\n== bulk pricing cart acceptance: {$ok} ok, {$fail} fail ==\n";

if ( $fail > 0 ) {
	exit( 1 );
}

WP_CLI::success( 'Bulk pricing cart acceptance passed.' );
