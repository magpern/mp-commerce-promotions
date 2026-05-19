<?php
/**
 * WP-CLI smoke: promotion template presets and evaluator checks.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/promotion-template-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionTemplate;

$GLOBALS['smoke_failures'] = 0;

function smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

try {
	$shipping = PromotionTemplate::build(
		PromotionTemplate::TEMPLATE_FREE_SHIPPING_OVER_SUBTOTAL,
		array( 'amount' => 50 )
	);
	smoke_assert(
		$shipping['conditions'][0]['type'] === RuleTypes::CONDITION_MINIMUM_SUBTOTAL
		&& $shipping['actions'][0]['type'] === RuleTypes::ACTION_FREE_SHIPPING,
		'free_shipping_over_subtotal template'
	);

	$bogo = PromotionTemplate::build(
		PromotionTemplate::TEMPLATE_BUY_X_GET_Y_CHEAPEST_FREE,
		array(
			'scope'               => CheapestItemDiscountAction::SCOPE_PRODUCTS,
			'product_ids'         => array( 100 ),
			'required_quantity'   => 2,
			'discounted_quantity' => 1,
		)
	);

	$items = array(
		array(
			'product_id'    => 100,
			'quantity'      => 2.0,
			'line_subtotal' => 50.0,
			'unit_price'    => 25.0,
		),
	);

	$cheapest_action = CheapestItemDiscountAction::from_config( $bogo['actions'][0] );
	$discount        = $cheapest_action->preview( new EvaluationContext( null, 50.0, 'EUR', $items, array() ) );
	smoke_assert( ( $discount->get_payload()['discount_amount'] ?? 0 ) === 25.0, 'buy_x_get_y discount amount' );

	$first_order = PromotionTemplate::build(
		PromotionTemplate::TEMPLATE_FIRST_ORDER_DISCOUNT,
		array(
			'discount_type' => 'percentage',
			'percentage'    => 10,
		)
	);

	$promotion = Promotion::from_array(
		array(
			'uuid'       => '33333333-3333-4333-8333-333333333333',
			'name'       => 'Template smoke',
			'status'     => PromotionStatus::ACTIVE,
			'conditions' => $first_order['conditions'],
			'actions'    => $first_order['actions'],
		)
	);

	$context = new EvaluationContext(
		1,
		100.0,
		'EUR',
		array(
			array(
				'product_id'    => 1,
				'quantity'      => 1.0,
				'line_subtotal' => 100.0,
			),
		),
		array( 'has_previous_orders' => false )
	);

	$evaluator = new PromotionEvaluator();
	smoke_assert( $evaluator->evaluate( $promotion, $context )->is_eligible(), 'first_order_discount eligible with metadata' );

	$subtotal_context = new EvaluationContext( null, 60.0, 'EUR', array(), array() );
	$ship_promo       = Promotion::from_array(
		array(
			'uuid'       => '44444444-4444-4444-8444-444444444444',
			'name'       => 'Ship smoke',
			'status'     => PromotionStatus::ACTIVE,
			'conditions' => $shipping['conditions'],
			'actions'    => $shipping['actions'],
		)
	);
	smoke_assert( $evaluator->evaluate( $ship_promo, $subtotal_context )->is_eligible(), 'free_shipping_over_subtotal eligible' );

	$pct_built = PromotionTemplate::build(
		PromotionTemplate::TEMPLATE_PERCENT_OFF_CATEGORY,
		array(
			'category_ids' => array( 10 ),
			'percentage'   => 20,
		)
	);
	$pct_action = PercentageDiscountAction::from_config( $pct_built['actions'][0] );
	$pct_preview = $pct_action->preview(
		new EvaluationContext(
			null,
			100.0,
			'EUR',
			array(
				array(
					'product_id'    => 1,
					'quantity'      => 1.0,
					'line_subtotal' => 40.0,
					'categories'    => array( 10 ),
				),
			),
			array()
		)
	);
	smoke_assert( $pct_preview->get_payload()['calculated_discount'] === 8.0, 'percent_off_category scoped preview' );

	$invalid = false;
	try {
		PromotionTemplate::build( 'bad_key', array() );
	} catch ( InvalidArgumentException $e ) {
		$invalid = $e->getMessage() === 'invalid_template_key';
	}
	smoke_assert( $invalid, 'invalid template key throws' );

} catch ( Throwable $e ) {
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'Exception: ' . $e->getMessage() );
}

$failures = (int) ( $GLOBALS['smoke_failures'] ?? 0 );
if ( $failures > 0 ) {
	WP_CLI::error( "promotion-template-smoke finished with {$failures} failure(s)." );
}

WP_CLI::success( 'promotion-template-smoke completed.' );
