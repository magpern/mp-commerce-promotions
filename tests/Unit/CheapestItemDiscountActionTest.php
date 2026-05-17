<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;
use PHPUnit\Framework\TestCase;

final class CheapestItemDiscountActionTest extends TestCase {

	/**
	 * @return EvaluationContext
	 */
	private function sample_context(): EvaluationContext {
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
				),
				array(
					'product_id'    => 101,
					'quantity'      => 2.0,
					'line_subtotal' => 60.0,
					'unit_price'    => 30.0,
					'categories'    => array( 10 ),
				),
				array(
					'product_id'    => 102,
					'quantity'      => 1.0,
					'line_subtotal' => 20.0,
					'unit_price'    => 20.0,
					'categories'    => array( 20 ),
				),
			),
			array()
		);
	}

	public function test_category_scope_100_percent_off_cheapest_unit(): void {
		$action = CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_CATEGORY,
				'category_ids'        => array( 10 ),
				'discount_percentage' => 100,
				'required_quantity'   => 3,
				'discounted_quantity' => 1,
			)
		);

		$result = $action->preview( $this->sample_context() );

		$this->assertSame( 30.0, $result->get_payload()['discount_amount'] );
		$this->assertSame( 1, $result->get_payload()['discounted_units'] );
		$this->assertSame( CheapestItemDiscountAction::SCOPE_CATEGORY, $result->get_payload()['scope'] );
	}

	public function test_category_scope_two_units_free(): void {
		$action = CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_CATEGORY,
				'category_ids'        => array( 10 ),
				'discount_percentage' => 100,
				'required_quantity'   => 3,
				'discounted_quantity' => 2,
			)
		);

		$result = $action->preview( $this->sample_context() );

		$this->assertSame( 60.0, $result->get_payload()['discount_amount'] );
		$this->assertSame( 2, $result->get_payload()['discounted_units'] );
	}

	public function test_product_scope_50_percent_off_cheapest(): void {
		$action = CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_PRODUCTS,
				'product_ids'         => array( 100, 101 ),
				'discount_percentage' => 50,
				'required_quantity'   => 2,
				'discounted_quantity' => 1,
			)
		);

		$result = $action->preview( $this->sample_context() );

		$this->assertSame( 15.0, $result->get_payload()['discount_amount'] );
	}

	public function test_insufficient_quantity_returns_not_applicable(): void {
		$action = CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_CATEGORY,
				'category_ids'        => array( 20 ),
				'discount_percentage' => 100,
				'required_quantity'   => 3,
				'discounted_quantity' => 1,
			)
		);

		$result = $action->preview( $this->sample_context() );

		$this->assertSame( 0.0, $result->get_payload()['discount_amount'] );
		$this->assertTrue( $result->get_payload()['not_applicable'] );
		$this->assertSame( CheapestItemDiscountAction::REASON_INSUFFICIENT, $result->get_payload()['reason'] );
	}

	public function test_invalid_scope_throws(): void {
		$this->expectException( InvalidArgumentException::class );

		CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => 'invalid',
				'category_ids'        => array( 1 ),
				'discount_percentage' => 10,
				'required_quantity'   => 1,
				'discounted_quantity' => 1,
			)
		);
	}

	public function test_discounted_quantity_greater_than_required_throws(): void {
		$this->expectException( InvalidArgumentException::class );

		CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_CATEGORY,
				'category_ids'        => array( 10 ),
				'discount_percentage' => 100,
				'required_quantity'   => 2,
				'discounted_quantity' => 3,
			)
		);
	}

	public function test_product_scope_with_variation_ids(): void {
		$action = CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_PRODUCTS,
				'product_ids'         => array( 100 ),
				'variation_ids'       => array( 101 ),
				'discount_percentage' => 100,
				'required_quantity'   => 2,
				'discounted_quantity' => 1,
			)
		);

		$context = new EvaluationContext(
			null,
			60.0,
			'USD',
			array(
				array(
					'product_id'    => 100,
					'variation_id'  => 101,
					'quantity'      => 2.0,
					'line_subtotal' => 60.0,
					'unit_price'    => 30.0,
				),
			),
			array()
		);

		$result = $action->preview( $context );
		$this->assertSame( 30.0, $result->get_payload()['discount_amount'] );
	}

	public function test_invalid_discount_percentage_throws(): void {
		$this->expectException( InvalidArgumentException::class );

		CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_CATEGORY,
				'category_ids'        => array( 10 ),
				'discount_percentage' => 150,
				'required_quantity'   => 1,
				'discounted_quantity' => 1,
			)
		);
	}
}
