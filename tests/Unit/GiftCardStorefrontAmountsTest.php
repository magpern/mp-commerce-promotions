<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardStorefrontAmounts;
use PHPUnit\Framework\TestCase;

final class GiftCardStorefrontAmountsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mp_cp_test_options']['woocommerce_currency'] = 'EUR';
		$GLOBALS['mp_cp_test_display_currency']                = 'EUR';
		unset( $GLOBALS['WOOCS'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mp_cp_test_display_currency'], $GLOBALS['WOOCS'] );
		parent::tearDown();
	}

	public function test_round_up_to_nearest_ten(): void {
		$this->assertSame( 50.0, GiftCardStorefrontAmounts::round_up_to_nearest_ten( 45.0 ) );
		$this->assertSame( 200.0, GiftCardStorefrontAmounts::round_up_to_nearest_ten( 192.0 ) );
		$this->assertSame( 10.0, GiftCardStorefrontAmounts::round_up_to_nearest_ten( 10.0 ) );
		$this->assertSame( 30.0, GiftCardStorefrontAmounts::round_up_to_nearest_ten( 25.0 ) );
	}

	public function test_storefront_config_without_conversion_rounds_min_and_suggested(): void {
		$result = GiftCardStorefrontAmounts::storefront_config(
			array(
				'min_amount'        => 10.0,
				'max_amount'        => 500.0,
				'suggested_amounts' => array( 25.0, 50.0, 100.0 ),
				'default_amount'    => 50.0,
			)
		);

		$this->assertSame( 10.0, $result['min_amount'] );
		$this->assertSame( 500.0, $result['max_amount'] );
		$this->assertSame( 50.0, $result['default_amount'] );
		$this->assertSame( array( 30.0, 50.0, 100.0 ), $result['suggested_amounts'] );
	}

	public function test_storefront_config_converts_and_rounds_with_woocs(): void {
		$GLOBALS['mp_cp_test_display_currency'] = 'SEK';
		$GLOBALS['WOOCS']                       = new WoocsRateStub( 11.5 );

		$result = GiftCardStorefrontAmounts::storefront_config(
			array(
				'min_amount'        => 10.0,
				'max_amount'        => 500.0,
				'suggested_amounts' => array( 25.0, 50.0, 100.0 ),
				'default_amount'    => 50.0,
			)
		);

		$this->assertSame( 120.0, $result['min_amount'] );
		$this->assertSame( 5750.0, $result['max_amount'] );
		$this->assertSame( 575.0, $result['default_amount'] );
		$this->assertSame( array( 290.0, 580.0, 1150.0 ), $result['suggested_amounts'] );
	}

	public function test_base_amount_from_display(): void {
		$GLOBALS['mp_cp_test_display_currency'] = 'SEK';
		$GLOBALS['WOOCS']                       = new WoocsRateStub( 11.5 );

		$this->assertSame( 10.43, GiftCardStorefrontAmounts::base_amount_from_display( 120.0 ) );
	}

	public function test_convert_display_to_base_round_trips_through_woocs(): void {
		$GLOBALS['mp_cp_test_display_currency'] = 'SEK';
		$GLOBALS['WOOCS']                       = new WoocsRateStub( 11.5 );

		$base    = GiftCardStorefrontAmounts::convert_display_to_base( 120.0 );
		$display = (float) $GLOBALS['WOOCS']->woocs_exchange_value( $base );

		$this->assertSame( 10.4348, $base );
		$this->assertSame( 120.0, $display );
	}

	public function test_storefront_config_converts_with_universal_multicurrency_rate(): void {
		if ( ! class_exists( \UMC\Settings::class ) ) {
			$this->markTestSkipped( 'Universal Multicurrency is not loaded in the unit test bootstrap.' );
		}

		$GLOBALS['mp_cp_test_display_currency'] = 'SEK';
		unset( $GLOBALS['WOOCS'] );

		$result = GiftCardStorefrontAmounts::storefront_config(
			array(
				'min_amount'        => 10.0,
				'max_amount'        => 500.0,
				'suggested_amounts' => array( 25.0, 50.0, 100.0 ),
				'default_amount'    => 50.0,
			)
		);

		$this->assertGreaterThan( 10.0, (float) $result['min_amount'] );
		$this->assertGreaterThan( 100.0, (float) $result['default_amount'] );
		$this->assertNotSame( 10.0, (float) $result['min_amount'] );
	}

	public function test_conversion_filters_override_builtin_adapters(): void {
		$GLOBALS['mp_cp_test_display_currency'] = 'SEK';
		unset( $GLOBALS['WOOCS'] );

		$to_display = static function ( $converted, float $base_amount ): ?float {
			unset( $converted );
			return $base_amount * 10.0;
		};
		$to_base    = static function ( $base_amount, float $display_amount ): ?float {
			unset( $base_amount );
			return $display_amount / 10.0;
		};

		add_filter( GiftCardStorefrontAmounts::FILTER_CONVERT_BASE_TO_DISPLAY, $to_display, 10, 2 );
		add_filter( GiftCardStorefrontAmounts::FILTER_CONVERT_DISPLAY_TO_BASE, $to_base, 10, 2 );

		try {
			$this->assertSame( 100.0, GiftCardStorefrontAmounts::convert_base_to_display( 10.0 ) );
			$this->assertSame( 10.0, GiftCardStorefrontAmounts::base_amount_from_display( 100.0 ) );
		} finally {
			remove_filter( GiftCardStorefrontAmounts::FILTER_CONVERT_BASE_TO_DISPLAY, $to_display, 10 );
			remove_filter( GiftCardStorefrontAmounts::FILTER_CONVERT_DISPLAY_TO_BASE, $to_base, 10 );
		}
	}
}

final class WoocsRateStub {

	private float $rate;

	public function __construct( float $rate ) {
		$this->rate = $rate;
	}

	/**
	 * @return float|string
	 */
	public function woocs_exchange_value( float $value ) {
		return number_format( $value * $this->rate, 2, '.', '' );
	}

	/**
	 * @return array<string, array<string, float|int>>
	 */
	public function get_currencies(): array {
		return array(
			'SEK' => array(
				'rate'     => $this->rate,
				'decimals' => 2,
			),
		);
	}

	public function back_convert( float $amount, float $rate, ?int $decimals = 4 ): float {
		unset( $decimals );
		if ( $rate <= 0 ) {
			return $amount;
		}

		return round( $amount / $rate, 2 );
	}
}
