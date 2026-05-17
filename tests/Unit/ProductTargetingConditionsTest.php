<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\Condition\CategoryInCartCondition;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\Condition\ExcludeSaleItemsCondition;
use MP\CommercePromotions\Engine\Condition\ProductInCartCondition;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;
use PHPUnit\Framework\TestCase;

final class ProductTargetingConditionsTest extends TestCase {

	public function test_product_in_cart_passes_with_variation(): void {
		$condition = ProductInCartCondition::from_config(
			array(
				'type'        => RuleTypes::CONDITION_PRODUCT_IN_CART,
				'product_ids' => array( 3703 ),
			)
		);

		$context = new EvaluationContext(
			null,
			50.0,
			'EUR',
			array(
				array(
					'product_id'   => 3702,
					'variation_id' => 3703,
					'quantity'     => 1.0,
				),
			),
			array()
		);

		$result = $condition->evaluate( $context );
		$this->assertTrue( $result->passed() );
		$this->assertContains( 3703, $result->get_observed()['matched_ids'] );
	}

	public function test_product_in_cart_fails_with_reason_code(): void {
		$condition = new ProductInCartCondition( array( 999 ) );
		$result    = $condition->evaluate( new EvaluationContext( null, 0.0, 'EUR', array(), array() ) );

		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_REQUIRED_PRODUCT_MISSING, $result->get_reason_code() );
	}

	public function test_category_in_cart_passes(): void {
		$condition = CategoryInCartCondition::from_config(
			array(
				'type'         => RuleTypes::CONDITION_CATEGORY_IN_CART,
				'category_ids' => array( 10 ),
			)
		);

		$context = new EvaluationContext(
			null,
			50.0,
			'EUR',
			array(
				array(
					'product_id' => 1,
					'quantity'   => 1.0,
					'categories' => array( 10, 11 ),
				),
			),
			array()
		);

		$result = $condition->evaluate( $context );
		$this->assertTrue( $result->passed() );
		$this->assertContains( 10, $result->get_observed()['matched_category_ids'] );
	}

	public function test_exclude_sale_items_fails_when_sale_present(): void {
		$condition = new ExcludeSaleItemsCondition();
		$context   = new EvaluationContext(
			null,
			50.0,
			'EUR',
			array(
				array( 'product_id' => 1, 'quantity' => 1.0, 'on_sale' => true ),
			),
			array()
		);

		$result = $condition->evaluate( $context );
		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_SALE_ITEMS_PRESENT, $result->get_reason_code() );
		$this->assertSame( 1, $result->get_observed()['sale_item_count'] );
	}

	public function test_cheapest_item_excludes_sale_units_from_pool(): void {
		$action = CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_PRODUCTS,
				'product_ids'         => array( 100, 101 ),
				'discount_percentage' => 100,
				'required_quantity'   => 2,
				'discounted_quantity' => 1,
				'exclude_sale_items'  => true,
			)
		);

		$context = new EvaluationContext(
			null,
			100.0,
			'USD',
			array(
				array(
					'product_id'    => 100,
					'quantity'      => 1.0,
					'line_subtotal' => 10.0,
					'unit_price'    => 10.0,
					'on_sale'       => true,
				),
				array(
					'product_id'    => 101,
					'quantity'      => 2.0,
					'line_subtotal' => 60.0,
					'unit_price'    => 30.0,
					'on_sale'       => false,
				),
			),
			array()
		);

		$result  = $action->preview( $context );
		$payload = $result->get_payload();
		$this->assertFalse( $payload['not_applicable'] ?? false );
		$this->assertSame( 30.0, $payload['discount_amount'] );
		$this->assertTrue( $payload['sale_items_excluded'] );
		$this->assertSame( 3, $payload['eligible_units_raw'] );
		$this->assertSame( 2, $payload['eligible_units'] );
	}
}
