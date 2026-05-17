<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\PromotionRestrictionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionCooldownTest extends TestCase {

	public function test_normalize_cooldown_hours_rejects_zero(): void {
		$this->expectException( \InvalidArgumentException::class );
		Promotion::normalize_cooldown_hours( 0 );
	}

	public function test_cooldown_requires_logged_in_customer(): void {
		$data = PromotionTestFixtures::active_promotion(
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) )
		)->to_array();
		$data['id']             = 1;
		$data['cooldown_hours'] = 24;
		$promotion              = Promotion::from_array( $data );

		$evaluator = new PromotionRestrictionEvaluator();
		$trace     = $evaluator->evaluate_restrictions(
			$promotion,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertNotNull( $trace );
		$this->assertSame( ConditionTrace::REASON_CUSTOMER_REQUIRED, $trace->get_reason_code() );
	}

	public function test_no_cooldown_trace_without_redemption_repository(): void {
		$data = PromotionTestFixtures::active_promotion_with_id(
			5,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) )
		)->to_array();
		$data['cooldown_hours'] = 48;
		$promotion              = Promotion::from_array( $data );

		$evaluator = new PromotionRestrictionEvaluator( null );
		$trace     = $evaluator->evaluate_restrictions(
			$promotion,
			PromotionTestFixtures::cart_context( 99, 100.0 )
		);

		$this->assertNull( $trace );
	}
}
