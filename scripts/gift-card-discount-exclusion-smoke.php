<?php
/**
 * WP-CLI smoke: gift card products excluded from promotion discounts.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-discount-exclusion-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\EligibleCartScope;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardPromotionExclusion;
use MP\CommercePromotions\Woo\CartContextBuilder;

$GLOBALS['gcd_smoke_failures'] = 0;

function gcd_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['gcd_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'WC' ) ) {
	WP_CLI::error( 'WooCommerce unavailable' );
}

$gift = new WC_Product_Simple();
$gift->set_name( 'Smoke GC discount exclusion ' . wp_generate_password( 3, false ) );
$gift->set_regular_price( '100' );
$gift->set_status( 'publish' );
$gift_id = (int) $gift->save();

$normal = new WC_Product_Simple();
$normal->set_name( 'Smoke normal product ' . wp_generate_password( 3, false ) );
$normal->set_regular_price( '50' );
$normal->set_status( 'publish' );
$normal_id = (int) $normal->save();

GiftCardProductMeta::save(
	$gift_id,
	array(
		'sells'        => GiftCardProductMeta::VALUE_YES,
		'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
		'fixed_amount' => '100',
	)
);

gcd_smoke_assert( GiftCardPromotionExclusion::products()->product_sells_gift_card( $gift_id, 0 ), 'gift card product detected' );

$promotion = Promotion::from_array(
	array(
		'uuid'       => wp_generate_uuid4(),
		'name'       => 'Smoke 10% auto',
		'status'     => PromotionStatus::ACTIVE,
		'priority'   => 1,
		'conditions' => array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 10,
			),
		),
		'actions'    => array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 10,
			),
		),
	)
);

$evaluator = new PromotionEvaluator();

$gift_only_items = array(
	array(
		'product_id'    => $gift_id,
		'quantity'      => 1.0,
		'line_subtotal' => 100.0,
		'unit_price'    => 100.0,
	),
);
$gift_only_context = new EvaluationContext( null, 0.0, 'EUR', $gift_only_items, array(
	GiftCardPromotionExclusion::TRACE_COUNT_KEY    => 1,
	GiftCardPromotionExclusion::TRACE_SUBTOTAL_KEY => 100.0,
) );
$gift_only_result = $evaluator->evaluate( $promotion, $gift_only_context );
gcd_smoke_assert( ! $gift_only_result->is_eligible(), 'gift-card-only cart does not qualify for automatic discount' );

$mixed_items = array(
	array(
		'product_id'    => $gift_id,
		'quantity'      => 1.0,
		'line_subtotal' => 100.0,
		'unit_price'    => 100.0,
	),
	array(
		'product_id'    => $normal_id,
		'quantity'      => 1.0,
		'line_subtotal' => 50.0,
		'unit_price'    => 50.0,
	),
);
$mixed_context = new EvaluationContext( null, 50.0, 'EUR', $mixed_items, array() );
$mixed_result = $evaluator->evaluate( $promotion, $mixed_context );
gcd_smoke_assert( $mixed_result->is_eligible(), 'mixed cart promotion eligible' );
$eligible_subtotal = EligibleCartScope::subtotal( GiftCardPromotionExclusion::without_gift_card_products( $mixed_items ) );
gcd_smoke_assert( $eligible_subtotal === 50.0, 'mixed cart eligible subtotal is 50 not 150' );
gcd_smoke_assert( round( $eligible_subtotal * 0.1, 4 ) === 5.0, 'mixed cart discount basis is 5 on 50' );

$cheapest_promo = Promotion::from_array(
	array(
		'uuid'       => wp_generate_uuid4(),
		'name'       => 'Smoke cheapest',
		'status'     => PromotionStatus::ACTIVE,
		'priority'   => 1,
		'conditions' => array(),
		'actions'    => array(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_PRODUCTS,
				'product_ids'         => array( $gift_id, $normal_id ),
				'discount_percentage' => 100,
				'required_quantity'   => 1,
				'discounted_quantity' => 1,
			),
		),
	)
);
$cheapest_result  = $evaluator->evaluate( $cheapest_promo, $mixed_context );
$cheapest_actions = $cheapest_result->get_action_results();
$cheapest_payload = isset( $cheapest_actions[0]['payload'] ) && is_array( $cheapest_actions[0]['payload'] )
	? $cheapest_actions[0]['payload']
	: array();
gcd_smoke_assert(
	isset( $cheapest_payload['discount_amount'] ) && (float) $cheapest_payload['discount_amount'] === 50.0,
	'cheapest item promo selects normal product not gift card'
);

if ( function_exists( 'wc_load_cart' ) && WC()->cart ) {
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $gift_id, 1 );
	WC()->cart->add_to_cart( $normal_id, 1 );
	WC()->cart->calculate_totals();

	$built = ( new CartContextBuilder() )->build_from_cart();
	gcd_smoke_assert(
		(int) ( $built->get_metadata()[ GiftCardPromotionExclusion::TRACE_COUNT_KEY ] ?? 0 ) >= 1,
		'cart context traces excluded gift card lines'
	);
	gcd_smoke_assert(
		( $built->get_cart_subtotal() ?? 0.0 ) <= 50.01,
		'cart context promotion subtotal excludes gift card value'
	);

	$line_key = '';
	foreach ( WC()->cart->get_cart() as $key => $row ) {
		if ( is_array( $row ) && (int) ( $row['product_id'] ?? 0 ) === $gift_id ) {
			$line_key = (string) $key;
			break;
		}
	}
	if ( $line_key !== '' ) {
		$product = WC()->cart->cart_contents[ $line_key ]['data'] ?? null;
		$price_before = $product instanceof WC_Product ? (float) $product->get_price() : 0.0;
		WC()->cart->calculate_totals();
		$price_after = $product instanceof WC_Product ? (float) $product->get_price() : 0.0;
		gcd_smoke_assert( abs( $price_before - $price_after ) < 0.01, 'gift card line price not mutated by cart totals pass' );
	}
}

$scoped = EligibleCartScope::filter_items( $mixed_items );
gcd_smoke_assert( count( $scoped ) === 1, 'eligible scope contains one non-gift-card line' );

if ( $GLOBALS['gcd_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Gift card discount exclusion smoke finished with ' . (int) $GLOBALS['gcd_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Gift card discount exclusion smoke passed.' );
