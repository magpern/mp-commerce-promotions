<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Woo\WooCompatibility;
use PHPUnit\Framework\TestCase;

final class WooCompatibilityTest extends TestCase {

	public function test_is_hpos_enabled_false_without_woocommerce(): void {
		$this->assertFalse( WooCompatibility::is_hpos_enabled() );
	}

	public function test_declare_feature_compatibility_no_op_without_features_util(): void {
		WooCompatibility::declare_feature_compatibility();
		$this->assertTrue( true );
	}
}
