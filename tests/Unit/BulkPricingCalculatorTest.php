<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\BulkPricing\BulkPricingCalculator;
use MP\CommercePromotions\BulkPricing\BulkPricingConfig;
use MP\CommercePromotions\BulkPricing\BulkPricingMoney;
use MP\CommercePromotions\BulkPricing\LinePriceSnapshot;
use PHPUnit\Framework\TestCase;

final class BulkPricingCalculatorTest extends TestCase {

	public function test_bracket_qty_four_uses_three_plus_tier(): void {
		$config = new BulkPricingConfig(
			true,
			array(
				array(
					'min_quantity'        => 1,
					'discount_percentage' => 0,
					'anchor_quantity'     => 1,
					'badge'               => null,
					'sort_order'          => 1,
				),
				array(
					'min_quantity'        => 3,
					'discount_percentage' => 5,
					'anchor_quantity'     => 3,
					'badge'               => null,
					'sort_order'          => 2,
				),
				array(
					'min_quantity'        => 10,
					'discount_percentage' => 15,
					'anchor_quantity'     => 10,
					'badge'               => null,
					'sort_order'          => 3,
				),
			)
		);

		$snapshot = new LinePriceSnapshot( 1, 10000, 10000, 'EUR', 'EUR', 'regular', 2, 'hash' );
		$calc     = new BulkPricingCalculator();
		$quote    = $calc->quote_line( $snapshot, $config, 4 );

		$this->assertNotNull( $quote );
		$this->assertSame( 3, $quote->get_tier_min_quantity() );
		$this->assertSame( 5, $quote->get_discount_percentage() );
		$this->assertSame( 9500, $quote->get_unit_minor() );
		$this->assertSame( 38000, $quote->get_line_total_minor() );
	}

	public function test_bracket_qty_eleven_uses_ten_plus(): void {
		$config = new BulkPricingConfig(
			true,
			array(
				array(
					'min_quantity'        => 10,
					'discount_percentage' => 15,
					'anchor_quantity'     => 10,
					'badge'               => null,
					'sort_order'          => 1,
				),
			)
		);
		$snapshot = new LinePriceSnapshot( 1, 20000, 20000, 'EUR', 'EUR', 'regular', 2, 'hash' );
		$quote    = ( new BulkPricingCalculator() )->quote_line( $snapshot, $config, 11 );

		$this->assertNotNull( $quote );
		$this->assertSame( 10, $quote->get_tier_min_quantity() );
		$this->assertSame( 17000, $quote->get_unit_minor() );
	}

	public function test_money_apply_percentage(): void {
		$this->assertSame( 9500, BulkPricingMoney::apply_percentage_minor( 10000, 5 ) );
		$this->assertSame( 10000, BulkPricingMoney::apply_percentage_minor( 10000, 0 ) );
	}
}
