<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Condition\BillingCountryCondition;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class BillingCountryConditionTest extends TestCase {

	public function test_passes_se_against_se_list(): void {
		$condition = new BillingCountryCondition( array( 'SE' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'billing_country' => 'SE' ) )
		);

		$this->assertTrue( $result->passed() );
		$this->assertSame( RuleTypes::CONDITION_BILLING_COUNTRY, $condition->get_type() );
	}

	public function test_passes_with_lowercase_metadata_and_config(): void {
		$condition = new BillingCountryCondition( array( 'se', 'no' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'billing_country' => 'se' ) )
		);

		$this->assertTrue( $result->passed() );
	}

	public function test_fails_missing_metadata(): void {
		$condition = new BillingCountryCondition( array( 'SE' ) );
		$result    = $condition->evaluate( PromotionTestFixtures::cart_context( null, 10.0 ) );

		$this->assertFalse( $result->passed() );
	}

	public function test_fails_non_matching_country(): void {
		$condition = new BillingCountryCondition( array( 'SE' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'billing_country' => 'US' ) )
		);

		$this->assertFalse( $result->passed() );
	}

	public function test_invalid_countries_config_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new BillingCountryCondition( array( '' ) );
	}
}
