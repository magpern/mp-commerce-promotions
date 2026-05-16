<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\PromotionRestrictionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionRestrictionEvaluatorTest extends TestCase {

	private PromotionRestrictionEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new PromotionRestrictionEvaluator();
	}

	public function test_usage_limit_reached_when_count_meets_limit(): void {
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
		$data                     = $promotion->to_array();
		$data['usage_limit']      = 5;
		$data['usage_count']      = 5;
		$promotion                = \MP\CommercePromotions\Domain\Promotion::from_array( $data );

		$trace = $this->evaluator->evaluate_restrictions(
			$promotion,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertNotNull( $trace );
		$this->assertSame( ConditionTrace::REASON_USAGE_LIMIT_REACHED, $trace->get_reason_code() );
	}

	public function test_customer_usage_limit_requires_logged_in_customer(): void {
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
		$data                         = $promotion->to_array();
		$data['customer_usage_limit'] = 1;
		$promotion                    = \MP\CommercePromotions\Domain\Promotion::from_array( $data );

		$trace = $this->evaluator->evaluate_restrictions(
			$promotion,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertNotNull( $trace );
		$this->assertSame( ConditionTrace::REASON_CUSTOMER_REQUIRED_FOR_USAGE_TRACKING, $trace->get_reason_code() );
	}

	public function test_promotion_not_started_when_starts_at_in_future(): void {
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
		$future  = gmdate( 'Y-m-d H:i:s', time() + 86400 );
		$promotion = $promotion->with_date_window( $future, null );

		$trace = $this->evaluator->evaluate_restrictions(
			$promotion,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertNotNull( $trace );
		$this->assertSame( ConditionTrace::REASON_PROMOTION_NOT_STARTED, $trace->get_reason_code() );
	}

	public function test_promotion_expired_when_ends_at_in_past(): void {
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
		$past      = gmdate( 'Y-m-d H:i:s', time() - 86400 );
		$promotion = $promotion->with_date_window( null, $past );

		$trace = $this->evaluator->evaluate_restrictions(
			$promotion,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertNotNull( $trace );
		$this->assertSame( ConditionTrace::REASON_PROMOTION_EXPIRED, $trace->get_reason_code() );
	}
}
