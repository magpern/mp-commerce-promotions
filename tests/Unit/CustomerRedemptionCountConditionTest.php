<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\Condition\CustomerRedemptionCountCondition;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class CustomerRedemptionCountConditionTest extends TestCase {

	public function test_passes_when_count_below_threshold(): void {
		$condition = new CustomerRedemptionCountCondition( '<', 1.0 );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context(
				5,
				10.0,
				array(),
				array( 'customer_redemption_count' => 0 )
			)
		);

		$this->assertTrue( $result->passed() );
		$this->assertSame( RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT, $condition->get_type() );
	}

	public function test_fails_when_count_meets_threshold(): void {
		$condition = new CustomerRedemptionCountCondition( '<', 1.0 );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context(
				5,
				10.0,
				array(),
				array( 'customer_redemption_count' => 1 )
			)
		);

		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_REDEMPTION_COUNT_NOT_MET, $result->get_reason_code() );
	}

	public function test_fails_when_metadata_missing(): void {
		$condition = new CustomerRedemptionCountCondition( '<', 1.0 );
		$result    = $condition->evaluate( PromotionTestFixtures::cart_context( 5, 10.0 ) );

		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_METADATA_MISSING, $result->get_reason_code() );
	}

	public function test_negative_count_in_constructor_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new CustomerRedemptionCountCondition( '<', -1.0 );
	}

	public function test_invalid_operator_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new CustomerRedemptionCountCondition( '!=', 1.0 );
	}
}
