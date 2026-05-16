<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionEvaluatorRestrictionsTest extends TestCase {

	public function test_evaluator_fails_when_global_usage_limit_reached(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);
		$data                = $promotion->to_array();
		$data['usage_limit'] = 2;
		$data['usage_count'] = 2;
		$promotion           = \MP\CommercePromotions\Domain\Promotion::from_array( $data );

		$evaluator = new PromotionEvaluator();
		$result    = $evaluator->evaluate( $promotion, PromotionTestFixtures::cart_context( null, 100.0 ) );

		$this->assertFalse( $result->is_eligible() );
		$this->assertSame(
			ConditionTrace::REASON_USAGE_LIMIT_REACHED,
			$result->get_condition_traces()[0]['reason_code']
		);
	}

	public function test_evaluator_passes_below_usage_limit(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);
		$data                = $promotion->to_array();
		$data['usage_limit'] = 5;
		$data['usage_count'] = 2;
		$promotion           = \MP\CommercePromotions\Domain\Promotion::from_array( $data );

		$evaluator = new PromotionEvaluator();
		$result    = $evaluator->evaluate( $promotion, PromotionTestFixtures::cart_context( null, 100.0 ) );

		$this->assertTrue( $result->is_eligible() );
	}

	public function test_minimum_cart_quantity_condition_in_evaluator(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'     => RuleTypes::CONDITION_MINIMUM_CART_QUANTITY,
					'quantity' => 2,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		);

		$evaluator = new PromotionEvaluator();
		$result    = $evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context(
				null,
				10.0,
				array(
					array(
						'product_id' => 1,
						'quantity'   => 1.0,
					),
				)
			)
		);

		$this->assertFalse( $result->is_eligible() );

		$result_ok = $evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context(
				null,
				10.0,
				array(
					array(
						'product_id' => 1,
						'quantity'   => 2.0,
					),
				)
			)
		);

		$this->assertTrue( $result_ok->is_eligible() );
	}
}
