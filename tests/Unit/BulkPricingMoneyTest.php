<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\BulkPricing\BulkPricingConfig;
use MP\CommercePromotions\BulkPricing\BulkPricingMoney;
use PHPUnit\Framework\TestCase;

final class BulkPricingMoneyTest extends TestCase {

	public function test_to_and_from_minor_round_trip(): void {
		$this->assertSame( 10050, BulkPricingMoney::to_minor( 100.5, 2 ) );
		$this->assertSame( 100.5, BulkPricingMoney::from_minor( 10050, 2 ) );
	}

	public function test_apply_percentage_minor(): void {
		$this->assertSame( 9500, BulkPricingMoney::apply_percentage_minor( 10000, 5 ) );
		$this->assertSame( 8500, BulkPricingMoney::apply_percentage_minor( 10000, 15 ) );
	}

	public function test_line_total_minor(): void {
		$this->assertSame( 30000, BulkPricingMoney::line_total_minor( 10000, 3 ) );
	}
}

final class BulkPricingConfigTest extends TestCase {

	public function test_resolve_bracket_qty_four_uses_three_plus(): void {
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

		$bracket = $config->resolve_bracket_for_quantity( 4 );
		$this->assertNotNull( $bracket );
		$this->assertSame( 3, $bracket['min_quantity'] );
	}

	public function test_resolve_bracket_qty_eleven_uses_ten_plus(): void {
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

		$bracket = $config->resolve_bracket_for_quantity( 11 );
		$this->assertNotNull( $bracket );
		$this->assertSame( 10, $bracket['min_quantity'] );
	}
}
