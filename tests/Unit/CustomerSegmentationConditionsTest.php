<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\Condition\CustomerAverageOrderValueCondition;
use MP\CommercePromotions\Engine\Condition\CustomerLifetimeSpendCondition;
use MP\CommercePromotions\Engine\Condition\CustomerOrderCountCondition;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class CustomerSegmentationConditionsTest extends TestCase {

	public function test_lifetime_spend_passes_when_threshold_met(): void {
		$condition = new CustomerLifetimeSpendCondition( '>=', 500.0 );
		$context   = PromotionTestFixtures::cart_context(
			42,
			100.0,
			array(),
			array( 'lifetime_spend' => 600.0 )
		);

		$result = $condition->evaluate( $context );
		$this->assertTrue( $result->passed() );
	}

	public function test_order_count_fails_without_customer(): void {
		$condition = new CustomerOrderCountCondition( '>=', 3.0 );
		$result    = $condition->evaluate( PromotionTestFixtures::cart_context( null, 50.0 ) );

		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_CUSTOMER_REQUIRED, $result->get_reason_code() );
	}

	public function test_average_order_value_fails_when_metadata_missing(): void {
		$condition = new CustomerAverageOrderValueCondition( '>=', 75.0 );
		$result    = $condition->evaluate( PromotionTestFixtures::cart_context( 7, 50.0 ) );

		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_METADATA_MISSING, $result->get_reason_code() );
	}
}
