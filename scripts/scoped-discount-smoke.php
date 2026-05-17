<?php
/**
 * WP-CLI smoke: scoped subtotals and scoped discount previews.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/scoped-discount-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\MaximumEligibleSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\MinimumEligibleSubtotalCondition;
use MP\CommercePromotions\Engine\EligibleCartScope;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\SimpleRuleBuilder;

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
	$items = array(
		array(
			'product_id'    => 100,
			'variation_id'  => 101,
			'quantity'      => 2.0,
			'line_subtotal' => 60.0,
			'categories'    => array( 10 ),
			'on_sale'       => false,
		),
		array(
			'product_id'    => 200,
			'quantity'      => 1.0,
			'line_subtotal' => 30.0,
			'categories'    => array( 20 ),
			'on_sale'       => true,
		),
	);

	$context = new EvaluationContext( null, 90.0, 'EUR', $items, array() );

	$cat_items = EligibleCartScope::filter_items( $items, array(), array(), array( 10 ) );
	smoke_assert( EligibleCartScope::subtotal( $cat_items ) === 60.0, 'category-scoped subtotal' );

	$product_items = EligibleCartScope::filter_items( $items, array( 100 ), array( 101 ), array() );
	smoke_assert( count( $product_items ) === 1, 'product/variation scoped filter' );

	$min_condition = MinimumEligibleSubtotalCondition::from_config(
		array(
			'type'         => RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL,
			'amount'       => 50,
			'category_ids' => array( 10 ),
		)
	);
	smoke_assert( $min_condition->evaluate( $context )->passed(), 'minimum_eligible_subtotal passes' );

	$max_condition = MaximumEligibleSubtotalCondition::from_config(
		array(
			'type'   => RuleTypes::CONDITION_MAXIMUM_ELIGIBLE_SUBTOTAL,
			'amount' => 55,
		)
	);
	smoke_assert( ! $max_condition->evaluate( $context )->passed(), 'maximum_eligible_subtotal fails over cap' );

	$pct = PercentageDiscountAction::from_config(
		array(
			'type'         => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
			'percentage'   => 20,
			'category_ids' => array( 10 ),
		)
	);
	$pct_payload = $pct->preview( $context )->get_payload();
	smoke_assert( $pct_payload['calculated_discount'] === 12.0, 'scoped percentage calculated_discount' );

	$fixed = FixedAmountDiscountAction::from_config(
		array(
			'type'         => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
			'amount'       => 100,
			'product_ids'  => array( 200 ),
		)
	);
	$fixed_payload = $fixed->preview( $context )->get_payload();
	smoke_assert( $fixed_payload['applied_discount'] === 30.0, 'scoped fixed capped to eligible subtotal' );

	$sale_pct = PercentageDiscountAction::from_config(
		array(
			'type'               => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
			'percentage'         => 10,
			'exclude_sale_items' => true,
		)
	);
	$sale_payload = $sale_pct->preview( $context )->get_payload();
	smoke_assert( $sale_payload['eligible_subtotal'] === 60.0 && $sale_payload['sale_items_excluded_count'] === 1, 'sale exclusion on percentage scope' );

	$cheapest = CheapestItemDiscountAction::from_config(
		array(
			'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
			'scope'               => CheapestItemDiscountAction::SCOPE_PRODUCTS,
			'product_ids'         => array( 100 ),
			'variation_ids'       => array( 101 ),
			'discount_percentage' => 100,
			'required_quantity'   => 2,
			'discounted_quantity' => 1,
			'exclude_sale_items'  => true,
		)
	);
	$cheapest_payload = $cheapest->preview( $context )->get_payload();
	smoke_assert( ( $cheapest_payload['discount_amount'] ?? 0 ) === 30.0, 'cheapest item via EligibleCartScope' );

	$built = SimpleRuleBuilder::build_from_post(
		array(
			'mp_cp_builder_condition_type'       => RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL,
			'mp_cp_builder_eligible_amount'      => '50',
			'mp_cp_builder_eligible_category_ids' => '10',
			'mp_cp_builder_action_type'          => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
			'mp_cp_builder_percentage'           => '15',
			'mp_cp_builder_action_category_ids'  => '10',
		)
	);
	smoke_assert(
		$built['conditions'][0]['type'] === RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL
		&& $built['actions'][0]['category_ids'] === array( 10 ),
		'SimpleRuleBuilder scoped output'
	);

} catch ( Throwable $e ) {
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'Exception: ' . $e->getMessage() );
}

$failures = (int) ( $GLOBALS['smoke_failures'] ?? 0 );
if ( $failures > 0 ) {
	WP_CLI::error( "scoped-discount-smoke finished with {$failures} failure(s)." );
}

WP_CLI::success( 'scoped-discount-smoke completed.' );
