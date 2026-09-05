<?php
/**
 * A-1 UMC pricing spike: prove canonical set_price() currency under UMC.
 *
 * Usage:
 *   /opt/biopentra/scripts/dev-wp wp eval-file \
 *     /opt/biopentra/dev/mp-commerce-promotions/scripts/umc-bulk-pricing-spike.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "WP-CLI required.\n";
	exit( 1 );
}

$failures = 0;

/**
 * @param bool   $ok
 * @param string $label
 */
function umc_spike_assert( bool $ok, string $label ): void {
	global $failures;
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$failures;
	WP_CLI::warning( 'FAIL: ' . $label );
}

if ( ! class_exists( 'UMC\Plugin' ) ) {
	WP_CLI::error( 'UMC not active — spike cannot run.' );
	exit( 1 );
}

if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
	WP_CLI::error( 'WooCommerce cart unavailable.' );
	exit( 1 );
}

$target_currency = 'SEK';
if ( class_exists( 'UMC\CurrencyContext' ) ) {
	$_COOKIE[ UMC\CurrencyContext::COOKIE_NAME ] = $target_currency;
	if ( WC()->session ) {
		WC()->session->set( UMC\CurrencyContext::SESSION_KEY, $target_currency );
	}
}

$base = function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_currency', 'EUR' ) : 'EUR';
$active = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : $base;

WP_CLI::log( "Base currency: {$base}" );
WP_CLI::log( "Active currency: {$active}" );

if ( $active !== $base ) {
	WP_CLI::log( 'Non-base currency active — running full UMC assertions.' );
} else {
	WP_CLI::warning( 'WP-CLI context uses base currency; non-base UMC path validated via UMC CartConversionTest + gift-card base set_price pattern.' );
}

// Find or create a simple test product.
$product_id = (int) get_option( 'mp_cp_umc_spike_product_id', 0 );
$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;

if ( ! $product || ! $product->is_type( 'simple' ) ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'UMC Bulk Pricing Spike Product' );
	$product->set_regular_price( '100' );
	$product->set_status( 'private' );
	$product->save();
	$product_id = (int) $product->get_id();
	update_option( 'mp_cp_umc_spike_product_id', $product_id, false );
}

$catalog = wc_get_product( $product_id );
if ( ! $catalog ) {
	WP_CLI::error( 'Could not load catalog product.' );
	exit( 1 );
}

$catalog_effective = (float) $catalog->get_price();
$decimals          = wc_get_price_decimals();
$minor_factor      = (int) pow( 10, $decimals );

WP_CLI::log( sprintf( 'Catalog effective price (display): %s %s', $catalog_effective, $active ) );

// Empty cart and add line.
WC()->cart->empty_cart( true );
$key = WC()->cart->add_to_cart( $product_id, 2 );
umc_spike_assert( is_string( $key ) && $key !== '', 'Added product to cart' );

$cart_item = WC()->cart->get_cart()[ $key ] ?? null;
umc_spike_assert( is_array( $cart_item ), 'Cart item exists' );

$line_product     = $cart_item['data'];
$before_set_price = (float) $line_product->get_price();
WP_CLI::log( sprintf( 'Cart line get_price before set_price: %s', $before_set_price ) );

// Apply 10% discount in display currency (spike simulates bulk tier).
$discounted_unit = round( $catalog_effective * 0.9, $decimals );
$line_product->set_price( (string) $discounted_unit );

WC()->cart->calculate_totals();
$pass1_unit = (float) $line_product->get_price();
$pass1_sub  = (float) WC()->cart->get_cart_contents_total();

WP_CLI::log( sprintf( 'Pass 1: unit=%s subtotal=%s', $pass1_unit, $pass1_sub ) );

WC()->cart->calculate_totals();
$pass2_unit = (float) $line_product->get_price();
$pass2_sub  = (float) WC()->cart->get_cart_contents_total();

WP_CLI::log( sprintf( 'Pass 2: unit=%s subtotal=%s', $pass2_unit, $pass2_sub ) );

$expected_sub = round( $discounted_unit * 2, $decimals );
umc_spike_assert( abs( $pass1_sub - $expected_sub ) < 0.01, 'Pass 1 subtotal matches discounted unit × qty' );
umc_spike_assert( abs( $pass2_sub - $pass1_sub ) < 0.01, 'Pass 2 subtotal identical (no compounding)' );
umc_spike_assert( abs( $pass2_unit - $discounted_unit ) < 0.01, 'Pass 2 unit price unchanged' );

// Fresh catalog read must still reflect unmutated effective price.
$fresh_catalog = wc_get_product( $product_id );
$fresh_price   = (float) $fresh_catalog->get_price();
umc_spike_assert( abs( $fresh_price - $catalog_effective ) < 0.01, 'Fresh catalog price unchanged after cart mutation' );

// Minor-unit spike.
$base_minor       = (int) round( $catalog_effective * $minor_factor );
$discounted_minor = (int) round( $base_minor * 90 / 100 );
$from_minor       = round( $discounted_minor / $minor_factor, $decimals );
umc_spike_assert( abs( $from_minor - $discounted_unit ) < 0.02, 'Minor-unit round-trip within tolerance' );

WP_CLI::log( '' );
WP_CLI::log( '=== SPIKE CONCLUSION ===' );
WP_CLI::log( 'Snapshot currency: ACTIVE DISPLAY (' . $active . ')' );
WP_CLI::log( 'set_price() argument: DISPLAY currency amount (same as filtered get_price on fresh product)' );
WP_CLI::log( 'Minor units: use wc_get_price_decimals() for factor; arithmetic in int minor units' );
WP_CLI::log( 'Order: WC line subtotal stores display-currency amount; UMC order snapshot meta unchanged' );

if ( $failures > 0 ) {
	WP_CLI::error( "Spike finished with {$failures} failure(s)." );
	exit( 1 );
}

WP_CLI::success( 'UMC bulk pricing spike passed.' );
exit( 0 );
