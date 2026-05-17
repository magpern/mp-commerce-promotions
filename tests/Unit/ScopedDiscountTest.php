<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\Condition\MaximumEligibleSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\MinimumEligibleSubtotalCondition;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;
use PHPUnit\Framework\TestCase;

final class ScopedDiscountTest extends TestCase {

	private function cart_context(): EvaluationContext {
		return new EvaluationContext(
			null,
			115.0,
			'USD',
			array(
				array(
					'product_id'    => 100,
					'quantity'      => 2.0,
					'line_subtotal' => 80.0,
					'categories'    => array( 10 ),
					'on_sale'       => false,
				),
				array(
					'product_id'    => 200,
					'quantity'      => 1.0,
					'line_subtotal' => 35.0,
					'categories'    => array( 20 ),
					'on_sale'       => true,
				),
			),
			array()
		);
	}

	public function test_minimum_eligible_subtotal_passes_with_scope(): void {
		$condition = MinimumEligibleSubtotalCondition::from_config(
			array(
				'type'         => RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL,
				'amount'       => 75.0,
				'category_ids' => array( 10 ),
			)
		);

		$result = $condition->evaluate( $this->cart_context() );
		$this->assertTrue( $result->passed() );
		$this->assertSame( 80.0, $result->get_observed()['eligible_subtotal'] );
		$this->assertSame( 1, $result->get_observed()['matched_items_count'] );
	}

	public function test_minimum_eligible_subtotal_fails_with_reason(): void {
		$condition = MinimumEligibleSubtotalCondition::from_config(
			array(
				'type'         => RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL,
				'amount'       => 100.0,
				'category_ids' => array( 10 ),
			)
		);

		$result = $condition->evaluate( $this->cart_context() );
		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_ELIGIBLE_SUBTOTAL_TOO_LOW, $result->get_reason_code() );
	}

	public function test_maximum_eligible_subtotal_fails_when_too_high(): void {
		$condition = MaximumEligibleSubtotalCondition::from_config(
			array(
				'type'   => RuleTypes::CONDITION_MAXIMUM_ELIGIBLE_SUBTOTAL,
				'amount' => 50.0,
			)
		);

		$result = $condition->evaluate( $this->cart_context() );
		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_ELIGIBLE_SUBTOTAL_TOO_HIGH, $result->get_reason_code() );
	}

	public function test_scoped_percentage_discount_preview(): void {
		$action = PercentageDiscountAction::from_config(
			array(
				'type'         => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage'   => 20,
				'category_ids' => array( 10 ),
			)
		);

		$payload = $action->preview( $this->cart_context() )->get_payload();
		$this->assertSame( 80.0, $payload['eligible_subtotal'] );
		$this->assertSame( 16.0, $payload['calculated_discount'] );
		$this->assertTrue( $payload['scoped'] );
	}

	public function test_scoped_percentage_excludes_sale_items(): void {
		$action = PercentageDiscountAction::from_config(
			array(
				'type'               => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage'         => 10,
				'exclude_sale_items' => true,
			)
		);

		$payload = $action->preview( $this->cart_context() )->get_payload();
		$this->assertSame( 80.0, $payload['eligible_subtotal'] );
		$this->assertSame( 1, $payload['sale_items_excluded_count'] );
	}

	public function test_scoped_fixed_amount_not_applicable_without_eligible_subtotal(): void {
		$action = FixedAmountDiscountAction::from_config(
			array(
				'type'         => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
				'amount'       => 10,
				'category_ids' => array( 99 ),
			)
		);

		$payload = $action->preview( $this->cart_context() )->get_payload();
		$this->assertTrue( $payload['not_applicable'] );
		$this->assertSame( 0.0, $payload['eligible_subtotal'] );
	}

	public function test_scoped_fixed_amount_caps_at_eligible_subtotal(): void {
		$action = FixedAmountDiscountAction::from_config(
			array(
				'type'         => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
				'amount'       => 100,
				'category_ids' => array( 10 ),
			)
		);

		$payload = $action->preview( $this->cart_context() )->get_payload();
		$this->assertSame( 80.0, $payload['applied_discount'] );
	}
}
