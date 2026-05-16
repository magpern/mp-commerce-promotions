<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Condition\CustomerEmailDomainCondition;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class CustomerEmailDomainConditionTest extends TestCase {

	public function test_passes_user_at_example_com(): void {
		$condition = new CustomerEmailDomainCondition( array( 'example.com' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context(
				null,
				10.0,
				array(),
				array( 'customer_email' => 'user@example.com' )
			)
		);

		$this->assertTrue( $result->passed() );
		$this->assertSame( RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN, $condition->get_type() );
	}

	public function test_case_insensitive_domain_match(): void {
		$condition = new CustomerEmailDomainCondition( array( 'Example.COM' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context(
				null,
				10.0,
				array(),
				array( 'customer_email' => 'user@example.com' )
			)
		);

		$this->assertTrue( $result->passed() );
	}

	public function test_fails_missing_email_metadata(): void {
		$condition = new CustomerEmailDomainCondition( array( 'example.com' ) );
		$result    = $condition->evaluate( PromotionTestFixtures::cart_context( null, 10.0 ) );

		$this->assertFalse( $result->passed() );
	}

	public function test_fails_invalid_email(): void {
		$condition = new CustomerEmailDomainCondition( array( 'example.com' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context(
				null,
				10.0,
				array(),
				array( 'customer_email' => 'not-an-email' )
			)
		);

		$this->assertFalse( $result->passed() );
	}

	public function test_fails_non_matching_domain(): void {
		$condition = new CustomerEmailDomainCondition( array( 'example.com' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context(
				null,
				10.0,
				array(),
				array( 'customer_email' => 'user@other.org' )
			)
		);

		$this->assertFalse( $result->passed() );
	}

	public function test_invalid_domain_config_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new CustomerEmailDomainCondition( array( '@invalid' ) );
	}
}
