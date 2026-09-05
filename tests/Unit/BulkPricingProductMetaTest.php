<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\BulkPricing\BulkPricingConfig;
use MP\CommercePromotions\BulkPricing\BulkPricingProductMeta;
use PHPUnit\Framework\TestCase;

final class BulkPricingProductMetaTest extends TestCase {

	public function test_validate_duplicate_min_quantity_throws(): void {
		$meta = new BulkPricingProductMeta();
		$this->expectException( \InvalidArgumentException::class );
		$meta->validate_from_post(
			array(
				'mp_cp_bulk_pricing_enabled' => 'yes',
				'mp_cp_bulk_tiers'           => array(
					array(
						'min_quantity'        => 3,
						'discount_percentage' => 5,
					),
					array(
						'min_quantity'        => 3,
						'discount_percentage' => 10,
					),
				),
			)
		);
	}

	public function test_normalize_tier_row(): void {
		$row = BulkPricingConfig::normalize_tier_row(
			array(
				'min_quantity'        => 5,
				'discount_percentage' => 10,
				'anchor_quantity'   => 5,
				'badge'             => 'Best value',
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( 5, $row['min_quantity'] );
		$this->assertSame( 'Best value', $row['badge'] );
	}
}
