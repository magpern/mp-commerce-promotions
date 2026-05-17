<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\PromotionRestrictionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionBudgetTest extends TestCase {

	public function test_budget_fields_from_array_and_exhausted(): void {
		$promotion = Promotion::from_array(
			array(
				'id'              => 5,
				'uuid'            => '11111111-1111-4111-8111-111111111111',
				'name'            => 'Budget promo',
				'status'          => PromotionStatus::ACTIVE,
				'budget_amount'   => 100.0,
				'budget_spent'    => 100.0,
				'budget_currency' => 'USD',
				'conditions'      => array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
				'actions'         => array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) ),
			)
		);

		$this->assertTrue( $promotion->has_budget_cap() );
		$this->assertTrue( $promotion->is_budget_exhausted() );
		$this->assertSame( 100.0, $promotion->get_budget_utilization_percent() );
	}

	public function test_with_budget_preserves_spent_when_amount_changes(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'          => '11111111-1111-4111-8111-111111111111',
				'name'          => 'Budget promo',
				'status'        => PromotionStatus::DRAFT,
				'budget_amount' => 50.0,
				'budget_spent'  => 12.5,
			)
		);

		$updated = $promotion->with_budget( 75.0, null, 'EUR' );
		$this->assertSame( 75.0, $updated->get_budget_amount() );
		$this->assertSame( 12.5, $updated->get_budget_spent() );
		$this->assertSame( 'EUR', $updated->get_budget_currency() );
		$this->assertFalse( $updated->is_budget_exhausted() );
	}

	public function test_restriction_evaluator_budget_exhausted_trace(): void {
		$promotion = Promotion::from_array(
			array(
				'id'            => 9,
				'uuid'          => '11111111-1111-4111-8111-111111111111',
				'name'          => 'Exhausted',
				'status'        => PromotionStatus::ACTIVE,
				'budget_amount' => 20.0,
				'budget_spent'  => 25.0,
				'conditions'    => array(),
				'actions'       => array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) ),
			)
		);

		$evaluator = new PromotionRestrictionEvaluator();
		$trace     = $evaluator->evaluate_restrictions( $promotion, PromotionTestFixtures::cart_context( null, 100.0 ) );

		$this->assertNotNull( $trace );
		$this->assertFalse( $trace->passed() );
		$this->assertSame( ConditionTrace::REASON_PROMOTION_BUDGET_EXHAUSTED, $trace->get_reason_code() );
	}
}
