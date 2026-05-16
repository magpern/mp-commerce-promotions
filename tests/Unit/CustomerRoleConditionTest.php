<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Condition\CustomerRoleCondition;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class CustomerRoleConditionTest extends TestCase {

	public function test_passes_with_matching_role_case_insensitive(): void {
		$condition = new CustomerRoleCondition( array( 'VIP' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context(
				1,
				10.0,
				array(),
				array( 'customer_roles' => array( 'customer', 'vip' ) )
			)
		);

		$this->assertTrue( $result->passed() );
		$this->assertSame( RuleTypes::CONDITION_CUSTOMER_ROLE, $condition->get_type() );
	}

	public function test_fails_with_no_matching_role(): void {
		$condition = new CustomerRoleCondition( array( 'wholesale' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context(
				1,
				10.0,
				array(),
				array( 'customer_roles' => array( 'customer' ) )
			)
		);

		$this->assertFalse( $result->passed() );
	}

	public function test_fails_with_missing_metadata(): void {
		$condition = new CustomerRoleCondition( array( 'customer' ) );
		$result    = $condition->evaluate( PromotionTestFixtures::cart_context( 1, 10.0 ) );

		$this->assertFalse( $result->passed() );
	}

	public function test_fails_with_empty_customer_roles_metadata(): void {
		$condition = new CustomerRoleCondition( array( 'customer' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context(
				1,
				10.0,
				array(),
				array( 'customer_roles' => array() )
			)
		);

		$this->assertFalse( $result->passed() );
	}

	public function test_invalid_roles_config_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new CustomerRoleCondition( array( '', '  ' ) );
	}
}
