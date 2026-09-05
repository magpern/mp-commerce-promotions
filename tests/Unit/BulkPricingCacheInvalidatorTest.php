<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\BulkPricing\BulkPricingCacheInvalidator;
use MP\CommercePromotions\BulkPricing\BulkPricingConfig;
use MP\CommercePromotions\BulkPricing\LinePriceSnapshot;
use PHPUnit\Framework\TestCase;

final class BulkPricingCacheInvalidatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mp_cp_test_transients'] = array();
		$GLOBALS['mp_cp_test_options']    = array();
	}

	public function test_contract_cache_version_includes_epoch_and_currency(): void {
		update_option( BulkPricingCacheInvalidator::OPTION_CACHE_EPOCH, 3 );
		$invalidator = new BulkPricingCacheInvalidator();
		$snapshot    = new LinePriceSnapshot( 1, 10000, 10000, 'SEK', 'EUR', 'regular', 2, 'hash-a' );
		$config      = new BulkPricingConfig(
			true,
			array(
				array(
					'min_quantity'        => 3,
					'discount_percentage' => 5,
					'anchor_quantity'     => 3,
					'badge'               => null,
					'sort_order'          => 1,
				),
			)
		);

		$v1 = $invalidator->contract_cache_version( $snapshot, $config );
		update_option( BulkPricingCacheInvalidator::OPTION_CACHE_EPOCH, 4 );
		$v2 = $invalidator->contract_cache_version( $snapshot, $config );

		$this->assertNotSame( $v1, $v2 );
	}
}
