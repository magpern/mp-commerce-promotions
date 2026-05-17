<?php
/**
 * WP-CLI smoke: product targeting, variations, sale exclusion.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/product-targeting-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\Condition\CategoryInCartCondition;
use MP\CommercePromotions\Engine\Condition\ExcludeSaleItemsCondition;
use MP\CommercePromotions\Engine\Condition\ProductInCartCondition;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
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
			'unit_price'    => 30.0,
			'categories'    => array( 10 ),
			'on_sale'       => false,
		),
		array(
			'product_id'    => 200,
			'quantity'      => 1.0,
			'line_subtotal' => 15.0,
			'unit_price'    => 15.0,
			'categories'    => array( 20 ),
			'on_sale'       => true,
		),
	);

	$context = new EvaluationContext( null, 75.0, 'EUR', $items, array() );

	smoke_assert(
		CartItemSelector::item_matches_product_or_variation( $items[0], array( 100 ), array( 101 ) ),
		'variation-aware product match'
	);

	smoke_assert(
		count( CartItemSelector::items_matching_categories( $context, array( 10 ) ) ) === 1,
		'category matching'
	);

	$product_condition = ProductInCartCondition::from_config(
		array(
			'type'        => RuleTypes::CONDITION_PRODUCT_IN_CART,
			'product_ids' => array( 101 ),
		)
	);
	smoke_assert( $product_condition->evaluate( $context )->passed(), 'product_in_cart passes' );

	$category_condition = CategoryInCartCondition::from_config(
		array(
			'type'         => RuleTypes::CONDITION_CATEGORY_IN_CART,
			'category_ids' => array( 20 ),
		)
	);
	smoke_assert( $category_condition->evaluate( $context )->passed(), 'category_in_cart passes' );

	$sale_condition = new ExcludeSaleItemsCondition();
	smoke_assert( ! $sale_condition->evaluate( $context )->passed(), 'exclude_sale_items fails when sale line present' );

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
	$preview = $cheapest->preview( $context );
	smoke_assert( ( $preview->get_payload()['discount_amount'] ?? 0 ) === 30.0, 'cheapest item with variation IDs and sale exclusion' );

	$promotion = Promotion::from_array(
		array(
			'uuid'                  => '22222222-2222-4222-8222-222222222222',
			'name'                  => 'Smoke targeting exclude',
			'status'                => PromotionStatus::ACTIVE,
			'conditions'            => array( array( 'type' => 'minimum_subtotal', 'amount' => 1 ) ),
			'actions'               => array( array( 'type' => 'percentage_discount', 'percentage' => 5 ) ),
			'excluded_product_ids'  => array( 200 ),
			'excluded_category_ids' => array(),
		)
	);

	$filtered = CartItemSelector::filter_items_for_promotion( $items, $promotion );
	smoke_assert( count( $filtered ) === 1 && (int) $filtered[0]['product_id'] === 100, 'promotion excluded_product_ids filters lines' );

	$builder_post = array(
		'mp_cp_builder_condition_type' => RuleTypes::CONDITION_PRODUCT_IN_CART,
		'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
		'mp_cp_builder_product_ids'    => '100,101',
		'mp_cp_builder_percentage'     => '10',
	);
	$built        = SimpleRuleBuilder::build_from_post( $builder_post );
	smoke_assert(
		$built['conditions'][0]['type'] === RuleTypes::CONDITION_PRODUCT_IN_CART,
		'SimpleRuleBuilder product_in_cart'
	);

	$evaluator = new PromotionEvaluator();
	$result    = $evaluator->evaluate( $promotion, $context );
	smoke_assert( $result->is_eligible(), 'evaluator uses scoped items after exclusion' );

} catch ( Throwable $e ) {
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'Exception: ' . $e->getMessage() );
}

$failures = (int) ( $GLOBALS['smoke_failures'] ?? 0 );
if ( $failures > 0 ) {
	WP_CLI::error( "product-targeting-smoke finished with {$failures} failure(s)." );
}

WP_CLI::success( 'product-targeting-smoke completed.' );
