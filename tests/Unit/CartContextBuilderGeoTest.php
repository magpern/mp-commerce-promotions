<?php
/**
 * CartContextBuilder <-> Universal Geo Context integration boundary.
 *
 * Exercises the real CartContextBuilder::build_from_cart() code path via the
 * WC()/WC_Cart test stub plus the universal_geo_get_country_code() test stub,
 * both added in tests/bootstrap.php. Confirms the fail-closed contract: no
 * UGC function, an unresolved/empty/non-string result, or a resolved country
 * all leave geo_country metadata absent or correctly normalized, with no
 * fallback to billing_country and no fatal.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Condition\GeoCountryCondition;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Woo\CartContextBuilder;
use PHPUnit\Framework\TestCase;

final class CartContextBuilderGeoTest extends TestCase {

	private const PRODUCT_ID = 91001;

	protected function setUp(): void {
		global $mp_cp_test_wc, $mp_cp_test_geo_country;
		$mp_cp_test_wc          = null;
		$mp_cp_test_geo_country = null;
	}

	private function seed_cart(): void {
		$wc = \WC();
		$wc->cart->set_test_cart(
			array(
				'item_key' => array(
					'product_id'    => self::PRODUCT_ID,
					'variation_id'  => 0,
					'quantity'      => 1,
					'line_subtotal' => 100.0,
				),
			),
			100.0
		);
	}

	public function test_unresolved_country_omits_geo_metadata(): void {
		$this->seed_cart();
		// $mp_cp_test_geo_country left at its setUp() default (null) — the
		// same metadata-omission outcome as universal_geo_get_country_code()
		// being entirely undefined (both are the fail-closed default state).
		$context = ( new CartContextBuilder() )->build_from_cart();

		$this->assertArrayNotHasKey( 'geo_country', $context->get_metadata() );
	}

	public function test_empty_string_country_omits_geo_metadata(): void {
		global $mp_cp_test_geo_country;
		$mp_cp_test_geo_country = '';

		$this->seed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$this->assertArrayNotHasKey( 'geo_country', $context->get_metadata() );
	}

	public function test_whitespace_only_country_omits_geo_metadata(): void {
		global $mp_cp_test_geo_country;
		$mp_cp_test_geo_country = '   ';

		$this->seed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$this->assertArrayNotHasKey( 'geo_country', $context->get_metadata() );
	}

	public function test_non_string_country_is_treated_as_absent(): void {
		global $mp_cp_test_geo_country;
		$mp_cp_test_geo_country = 123;

		$this->seed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$this->assertArrayNotHasKey( 'geo_country', $context->get_metadata() );
	}

	public function test_lowercase_country_is_uppercased(): void {
		global $mp_cp_test_geo_country;
		$mp_cp_test_geo_country = 'de';

		$this->seed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$this->assertSame( 'DE', $context->get_metadata()['geo_country'] );
	}

	public function test_whitespace_padded_country_is_trimmed_and_uppercased(): void {
		global $mp_cp_test_geo_country;
		$mp_cp_test_geo_country = ' de ';

		$this->seed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$this->assertSame( 'DE', $context->get_metadata()['geo_country'] );
	}

	public function test_resolved_country_does_not_populate_billing_country(): void {
		global $mp_cp_test_geo_country;
		$mp_cp_test_geo_country = 'DE';

		$this->seed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$this->assertArrayNotHasKey( 'billing_country', $context->get_metadata() );
	}

	/**
	 * End-to-end fail-closed contract: builder -> metadata -> condition.
	 * Protects the whole chain, not just CartContextBuilder in isolation.
	 */
	public function test_unresolved_country_fails_geo_country_condition_end_to_end(): void {
		$this->seed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$condition = new GeoCountryCondition( array( 'DE' ) );
		$result    = $condition->evaluate( $context );

		$this->assertFalse( $result->passed() );
		$this->assertSame( RuleTypes::CONDITION_GEO_COUNTRY, $condition->get_type() );
	}
}
