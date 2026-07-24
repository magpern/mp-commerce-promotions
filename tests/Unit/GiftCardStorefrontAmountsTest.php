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

	public function test_convert_display_to_base_round_trips_through_woocs(): void {
		$GLOBALS['mp_cp_test_display_currency'] = 'SEK';
		$GLOBALS['WOOCS']                       = new WoocsRateStub( 11.5 );

		$base    = GiftCardStorefrontAmounts::convert_display_to_base( 120.0 );
		$display = (float) $GLOBALS['WOOCS']->woocs_exchange_value( $base );

		$this->assertSame( 10.4348, $base );
		$this->assertSame( 120.0, $display );
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
