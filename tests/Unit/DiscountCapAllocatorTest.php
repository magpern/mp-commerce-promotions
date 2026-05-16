<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Woo\DiscountCapAllocator;
use PHPUnit\Framework\TestCase;

final class DiscountCapAllocatorTest extends TestCase {

	public function test_sum_capped_discounts_stops_at_subtotal(): void {
		$total = DiscountCapAllocator::sum_capped_discounts(
			100.0,
			array( 80.0, 50.0 )
		);

		$this->assertSame( 100.0, $total );
	}

	public function test_clamp_to_remaining(): void {
		$this->assertSame( 20.0, DiscountCapAllocator::clamp_to_remaining( 50.0, 20.0 ) );
		$this->assertSame( 0.0, DiscountCapAllocator::clamp_to_remaining( 10.0, 0.0 ) );
	}
}
