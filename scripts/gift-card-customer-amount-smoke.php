<?php
/**
 * WP-CLI smoke: customer-entered gift card product amounts.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-customer-amount-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardOrderGenerator;
use MP\CommercePromotions\GiftCard\GiftCardProductCustomerAmount;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\GiftCardCustomerAmountCart;
use MP\CommercePromotions\Woo\GiftCardProductPriceDisplay;

$GLOBALS['gcca_smoke_failures'] = 0;

function gcca_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['gcca_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_create_order' ) ) {
	WP_CLI::error( 'WooCommerce product/order APIs unavailable' );
}

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable' );
}

$products = new GiftCardProductService();
$cart     = new GiftCardCustomerAmountCart( $products );

$product = new WC_Product_Simple();
$product->set_name( 'Smoke customer amount gift card ' . wp_generate_password( 4, false ) );
$product->set_status( 'publish' );
$product->set_regular_price( '' );
$product->set_virtual( true );
$product_id = (int) $product->save();
gcca_smoke_assert( $product_id > 0, 'create simple product' );

GiftCardProductMeta::save(
	$product_id,
	array(
		'sells'             => GiftCardProductMeta::VALUE_YES,
		'amount_mode'       => GiftCardProductMeta::AMOUNT_MODE_CUSTOMER_AMOUNT,
		'min_amount'        => '10',
		'max_amount'        => '500',
		'suggested_amounts' => '25,50,100',
		'default_amount'    => '',
		'fixed_amount'      => '',
		'expiry_days'       => '365',
	)
);

$config = GiftCardProductMeta::read( $product_id );
gcca_smoke_assert( GiftCardProductCustomerAmount::is_customer_amount_mode( $config ), 'product uses customer_amount mode' );

$price_display = new GiftCardProductPriceDisplay( $products );
$wc_product    = wc_get_product( $product_id );
$html          = $price_display->filter_price_html( '', $wc_product );
gcca_smoke_assert(
	strpos( $html, 'Choose amount' ) !== false || strpos( $html, 'From' ) !== false,
	'price display shows choose amount or from min (not empty zero price)'
);
gcca_smoke_assert(
	! preg_match( '/class="price">\s*<span[^>]*>0[,.]00/', $html ),
	'price display is not a bare zero amount'
);

$config_for_validation = GiftCardProductMeta::read( $product_id );
gcca_smoke_assert(
	GiftCardProductCustomerAmount::validate_customer_amount( 0.0, $config_for_validation ) !== null,
	'reject zero amount'
);
gcca_smoke_assert(
	GiftCardProductCustomerAmount::validate_customer_amount( 600.0, $config_for_validation ) !== null,
	'reject over max amount'
);
gcca_smoke_assert(
	GiftCardProductCustomerAmount::validate_customer_amount( 50.0, $config_for_validation ) === null,
	'accept valid amount 50'
);

$_POST[ GiftCardProductCustomerAmount::POST_FIELD ] = '50';
$cart_data = $cart->add_cart_item_data( array(), $product_id, 0 );
gcca_smoke_assert(
	isset( $cart_data[ GiftCardProductCustomerAmount::CART_ITEM_KEY ] )
	&& (float) $cart_data[ GiftCardProductCustomerAmount::CART_ITEM_KEY ] === 50.0,
	'add to cart stores chosen amount 50'
);

$repo      = new GiftCardRepository( $wpdb );
$tx        = new GiftCardTransactionRepository( $wpdb );
$ledger    = new GiftCardLedger( $repo, $tx );
$generator = new GiftCardOrderGenerator( $ledger, $products, new Settings() );

$order = wc_create_order();
$order->add_product( $wc_product, 2 );
$items = $order->get_items( 'line_item' );
$item  = reset( $items );
if ( is_object( $item ) ) {
	GiftCardProductCustomerAmount::write_amount_to_order_item( $item, 50.0 );
	$item->set_total( 100.0 );
	$item->save();
}
$order->set_billing_email( 'postmaster@biopentra.eu' );
$order->calculate_totals();
$order->set_status( 'processing', '', true );
$order->save();

$generated = $generator->generate_for_order( $order );
gcca_smoke_assert( count( $generated ) === 2, 'quantity 2 generates two cards' );
$card = $repo->find( (int) ( $generated[0]['gift_card_id'] ?? 0 ) );
gcca_smoke_assert( $card !== null && $card->get_initial_amount() === 50.0, 'generated card amount 50' );
gcca_smoke_assert(
	GiftCardGeneratedOrderState::is_generation_complete( $order ),
	'order generation complete'
);

if ( $GLOBALS['gcca_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Gift card customer amount smoke finished with ' . (int) $GLOBALS['gcca_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Gift card customer amount smoke passed.' );
