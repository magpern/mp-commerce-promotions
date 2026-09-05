<?php
/**
 * DEV promotion-vs-bulk acceptance (separate WP-CLI process from bulk cart tests).
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

$GLOBALS['mp_cp_bp_accept_ok']   = 0;
$GLOBALS['mp_cp_bp_accept_fail'] = 0;

function bp_promo_assert( bool $cond, string $label ): void {
	if ( $cond ) {
		++$GLOBALS['mp_cp_bp_accept_ok'];
		echo "OK  {$label}\n";
		return;
	}
	++$GLOBALS['mp_cp_bp_accept_fail'];
	echo "FAIL {$label}\n";
}

$fixture = get_page_by_path( 'bulk-pricing-fixture', OBJECT, 'product' );
if ( ! $fixture ) {
	WP_CLI::error( 'Fixture missing — run bulk-pricing-fixtures.php first.' );
}

$product_id         = (int) $fixture->ID;
$fixture_promo_uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

global $wpdb;
$wpdb->delete( $wpdb->prefix . 'mp_cp_promotions', array( 'uuid' => $fixture_promo_uuid ) );

wc_load_cart();
WC()->cart->empty_cart();

$promo_repo = new PromotionRepository( $wpdb );
$promo_id   = $promo_repo->insert(
	Promotion::from_array(
		array(
			'uuid'                      => $fixture_promo_uuid,
			'name'                      => 'Bulk Accept Fixture Promo (DEV)',
			'status'                    => 'active',
			'priority'                  => 5,
			'ends_at'                   => '2099-12-31 23:59:59',
			'conditions'                => array(
				array(
					'type'        => RuleTypes::CONDITION_PRODUCT_IN_CART,
					'product_ids' => array( $product_id ),
				),
			),
			'actions'                   => array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 25,
				),
			),
			'restrictions'              => array(),
			'usage_count'               => 0,
			'application_mode'          => 'stackable',
			'stop_processing'           => false,
			'discount_application_mode' => PromotionDiscountApplicationMode::LINE_ITEM,
			'coupon_behavior'           => 'coexist',
		)
	)
);

bp_promo_assert( $promo_id > 0, 'fixture promotion persisted' );

WC()->cart->add_to_cart( $product_id, 3 );
WC()->cart->calculate_totals();
$line = reset( WC()->cart->get_cart() );
$unit = is_array( $line ) && isset( $line['data'] ) ? (float) $line['data']->get_price() : 0.0;

bp_promo_assert( $unit > 0 && $unit < 95.0, 'promotion beats bulk 5% tier for qty 3 (unit below 95)' );
bp_promo_assert(
	is_array( $line ) && ( $line[ LinePricingSource::CART_META_SOURCE ] ?? '' ) === LinePricingSource::PROMOTION,
	'pricing source is promotion when promotion wins'
);

$order = wc_create_order();
$order->set_currency( get_woocommerce_currency() );
foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
	$item = new WC_Order_Item_Product();
	$item->set_product( $values['data'] );
	$item->set_quantity( $values['quantity'] );
	$item->set_subtotal( (float) $values['line_subtotal'] );
	$item->set_total( (float) $values['line_total'] );
	$order->add_item( $item );
	do_action( 'woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $values, $order );
}
$order->calculate_totals();
$order->save();
$order_item = reset( $order->get_items() );
bp_promo_assert( $order_item instanceof WC_Order_Item_Product, 'order has product line' );
if ( $order_item ) {
	bp_promo_assert(
		$order_item->get_meta( LinePricingSource::ORDER_META_SOURCE ) === LinePricingSource::PROMOTION,
		'order line stores pricing source meta'
	);
}
$order->delete( true );

$wpdb->delete( $wpdb->prefix . 'mp_cp_promotions', array( 'uuid' => $fixture_promo_uuid ) );
WC()->cart->empty_cart();

$ok   = (int) $GLOBALS['mp_cp_bp_accept_ok'];
$fail = (int) $GLOBALS['mp_cp_bp_accept_fail'];
echo "\n== bulk pricing promotion acceptance: {$ok} ok, {$fail} fail ==\n";
if ( $fail > 0 ) {
	exit( 1 );
}
WP_CLI::success( 'Bulk pricing promotion acceptance passed.' );
