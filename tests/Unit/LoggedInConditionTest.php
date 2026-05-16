<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Condition\LoggedInCondition;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class LoggedInConditionTest extends TestCase {

	public function test_passes_when_customer_id_present(): void {
		$condition = new LoggedInCondition();
		$result    = $condition->evaluate( PromotionTestFixtures::cart_context( 99, 10.0 ) );

		$this->assertTrue( $result->passed() );
		$this->assertSame( RuleTypes::CONDITION_LOGGED_IN, $condition->get_type() );
	}

	public function test_fails_when_guest(): void {
		$condition = new LoggedInCondition();
		$result    = $condition->evaluate( PromotionTestFixtures::cart_context( null, 10.0 ) );

		$this->assertFalse( $result->passed() );
	}
}
