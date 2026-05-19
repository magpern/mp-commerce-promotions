<?php
/**
 * WP-CLI smoke: cheapest_item_discount action planning (evaluator only).
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/cheapest-item-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Service\PromotionRuleValidator;
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

function smoke_sample_context(): EvaluationContext {
	return new EvaluationContext(
		null,
		160.0,
		'USD',
		array(
			array(
				'product_id'    => 100,
				'quantity'      => 1.0,
				'line_subtotal' => 50.0,
				'unit_price'    => 50.0,
				'categories'    => array( 10 ),
				'product_name'  => 'Item A',
			),
			array(
				'product_id'    => 101,
				'quantity'      => 2.0,
				'line_subtotal' => 60.0,
				'unit_price'    => 30.0,
				'categories'    => array( 10 ),
				'product_name'  => 'Item B',
			),
			array(
				'product_id'    => 102,
				'quantity'      => 1.0,
				'line_subtotal' => 20.0,
				'unit_price'    => 20.0,
				'categories'    => array( 20 ),
				'product_name'  => 'Item C',
			),
		),
		array( 'source' => 'smoke' )
	);
}

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "WP-CLI required.\n";
	exit( 1 );
}

$context = smoke_sample_context();

$category_one_free = CheapestItemDiscountAction::from_config(
	array(
		'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
		'scope'               => CheapestItemDiscountAction::SCOPE_CATEGORY,
		'category_ids'        => array( 10 ),
		'discount_percentage' => 100,
		'required_quantity'   => 3,
		'discounted_quantity' => 1,
	)
);
$payload = $category_one_free->preview( $context )->get_payload();
smoke_assert(
	isset( $payload['discount_amount'] ) && abs( (float) $payload['discount_amount'] - 30.0 ) < 0.0001,
	'Category 10: buy 3 get 1 free (100%) discounts 30'
);

$category_two_free = CheapestItemDiscountAction::from_config(
	array(
		'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
		'scope'               => CheapestItemDiscountAction::SCOPE_CATEGORY,
		'category_ids'        => array( 10 ),
		'discount_percentage' => 100,
		'required_quantity'   => 3,
		'discounted_quantity' => 2,
	)
);
$payload_two = $category_two_free->preview( $context )->get_payload();
smoke_assert(
	isset( $payload_two['discount_amount'] ) && abs( (float) $payload_two['discount_amount'] - 60.0 ) < 0.0001,
	'Category 10: discounted_quantity 2 at 100% discounts 60'
);

$products_half = CheapestItemDiscountAction::from_config(
	array(
		'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
		'scope'               => CheapestItemDiscountAction::SCOPE_PRODUCTS,
		'product_ids'         => array( 100, 101 ),
		'discount_percentage' => 50,
		'required_quantity'   => 2,
		'discounted_quantity' => 1,
	)
);
$payload_products = $products_half->preview( $context )->get_payload();
smoke_assert(
	isset( $payload_products['discount_amount'] ) && abs( (float) $payload_products['discount_amount'] - 15.0 ) < 0.0001,
	'Products 100,101: 50% off cheapest unit discounts 15'
);

$insufficient = CheapestItemDiscountAction::from_config(
	array(
		'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
		'scope'               => CheapestItemDiscountAction::SCOPE_CATEGORY,
		'category_ids'        => array( 20 ),
		'discount_percentage' => 100,
		'required_quantity'   => 3,
		'discounted_quantity' => 1,
	)
);
$payload_bad = $insufficient->preview( $context )->get_payload();
smoke_assert(
	! empty( $payload_bad['not_applicable'] ) && (float) $payload_bad['discount_amount'] === 0.0,
	'Insufficient eligible units returns not_applicable with discount_amount 0'
);

$builder_category = SimpleRuleBuilder::build_from_post(
	array(
		'mp_cp_builder_condition_type'              => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
		'mp_cp_builder_amount'                      => '1',
		'mp_cp_builder_action_type'                 => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
		'mp_cp_builder_cheapest_scope'              => CheapestItemDiscountAction::SCOPE_CATEGORY,
		'mp_cp_builder_cheapest_category_ids'       => '10',
		'mp_cp_builder_cheapest_required_quantity'    => '3',
		'mp_cp_builder_cheapest_discounted_quantity' => '1',
		'mp_cp_builder_cheapest_discount_percentage'  => '100',
	)
);
$built_action = CheapestItemDiscountAction::from_config( $builder_category['actions'][0] );
$built_payload = $built_action->preview( $context )->get_payload();
smoke_assert(
	isset( $built_payload['discount_amount'] ) && abs( (float) $built_payload['discount_amount'] - 30.0 ) < 0.0001,
	'SimpleRuleBuilder category config previews discount 30'
);

$builder_products = SimpleRuleBuilder::build_from_post(
	array(
		'mp_cp_builder_condition_type'              => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
		'mp_cp_builder_amount'                      => '1',
		'mp_cp_builder_action_type'                 => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
		'mp_cp_builder_cheapest_scope'              => CheapestItemDiscountAction::SCOPE_PRODUCTS,
		'mp_cp_builder_cheapest_product_ids'        => '100, 101',
		'mp_cp_builder_cheapest_required_quantity'    => '2',
		'mp_cp_builder_cheapest_discounted_quantity' => '1',
		'mp_cp_builder_cheapest_discount_percentage'  => '50',
	)
);
$built_products_action = CheapestItemDiscountAction::from_config( $builder_products['actions'][0] );
$built_products_payload = $built_products_action->preview( $context )->get_payload();
smoke_assert(
	isset( $built_products_payload['discount_amount'] ) && abs( (float) $built_products_payload['discount_amount'] - 15.0 ) < 0.0001,
	'SimpleRuleBuilder products config previews discount 15'
);

$validator = new PromotionRuleValidator();
$invalid_promotion = Promotion::from_array(
	array(
		'uuid'       => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
		'name'       => 'Smoke invalid cheapest',
		'status'     => PromotionStatus::ACTIVE,
		'conditions' => array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1.0,
			),
		),
		'actions'    => array(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => 'category',
				'category_ids'        => array( 10 ),
				'discount_percentage' => 100,
				'required_quantity'   => 2,
				'discounted_quantity' => 9,
			),
		),
	)
);
$validator_issues = $validator->validate( $invalid_promotion );
$has_specific_error = false;
foreach ( $validator_issues as $issue ) {
	if ( isset( $issue['message'] ) && strpos( (string) $issue['message'], 'discounted_quantity must be <= required_quantity' ) !== false ) {
		$has_specific_error = true;
		break;
	}
}
smoke_assert( $has_specific_error, 'Validator reports discounted_quantity <= required_quantity error' );

WP_CLI::log( 'CartPromotionApplier applies discount_amount as a negative fee when a promotion is eligible on the storefront.' );

if ( $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d smoke assertion(s) failed.', (int) $GLOBALS['smoke_failures'] ) );
}

WP_CLI::success( 'cheapest-item-smoke completed.' );
