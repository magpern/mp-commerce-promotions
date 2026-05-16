<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Condition\FirstOrderCondition;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class FirstOrderConditionTest extends TestCase {

	public function test_passes_when_no_previous_orders(): void {
		$condition = new FirstOrderCondition();
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context( 1, 10.0, array(), array( 'has_previous_orders' => false ) )
		);

		$this->assertTrue( $result->passed() );
		$this->assertSame( RuleTypes::CONDITION_FIRST_ORDER, $condition->get_type() );
	}

	public function test_fails_when_has_previous_orders(): void {
		$condition = new FirstOrderCondition();
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context( 1, 10.0, array(), array( 'has_previous_orders' => true ) )
		);

		$this->assertFalse( $result->passed() );
	}

	public function test_fails_when_metadata_missing(): void {
		$condition = new FirstOrderCondition();
		$result    = $condition->evaluate( PromotionTestFixtures::cart_context( 1, 10.0 ) );

		$this->assertFalse( $result->passed() );
		$this->assertStringContainsString( 'has_previous_orders', (string) $result->get_message() );
	}
}
