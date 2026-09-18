<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\Condition\GeoCountryCondition;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class GeoCountryConditionTest extends TestCase {

	public function test_passes_de_against_de_list(): void {
		$condition = new GeoCountryCondition( array( 'DE' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'geo_country' => 'DE' ) )
		);

		$this->assertTrue( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_PASSED, $result->get_reason_code() );
		$this->assertSame( RuleTypes::CONDITION_GEO_COUNTRY, $condition->get_type() );
	}

	public function test_passes_with_lowercase_metadata_and_config(): void {
		$condition = new GeoCountryCondition( array( 'de' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'geo_country' => 'de' ) )
		);

		$this->assertTrue( $result->passed() );
	}

	public function test_fails_non_matching_country(): void {
		$condition = new GeoCountryCondition( array( 'DE' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'geo_country' => 'SE' ) )
		);

		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_COUNTRY_NOT_MATCHED, $result->get_reason_code() );
	}

	public function test_fails_missing_metadata(): void {
		$condition = new GeoCountryCondition( array( 'DE' ) );
		$result    = $condition->evaluate( PromotionTestFixtures::cart_context( null, 10.0 ) );

		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_METADATA_MISSING, $result->get_reason_code() );
	}

	public function test_fails_empty_metadata(): void {
		$condition = new GeoCountryCondition( array( 'DE' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'geo_country' => '' ) )
		);

		$this->assertFalse( $result->passed() );
		$this->assertSame( ConditionTrace::REASON_METADATA_MISSING, $result->get_reason_code() );
	}

	public function test_matches_any_of_multiple_configured_countries(): void {
		$condition = new GeoCountryCondition( array( 'DE', 'AT', 'CH' ) );

		$this->assertTrue(
			$condition->evaluate(
				PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'geo_country' => 'AT' ) )
			)->passed()
		);
		$this->assertFalse(
			$condition->evaluate(
				PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'geo_country' => 'FR' ) )
			)->passed()
		);
	}

	public function test_normalizes_duplicate_and_mixed_case_configured_values(): void {
		$condition = new GeoCountryCondition( array( 'de', 'DE', 'At' ) );
		$result    = $condition->evaluate(
			PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'geo_country' => 'AT' ) )
		);

		$this->assertTrue( $result->passed() );
	}

	public function test_invalid_countries_config_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new GeoCountryCondition( array( '' ) );
	}

	public function test_empty_countries_config_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new GeoCountryCondition( array() );
	}
}
