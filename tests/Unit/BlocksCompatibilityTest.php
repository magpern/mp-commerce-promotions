<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Service\BlockTestPages;
use MP\CommercePromotions\Service\CompatibilityStatus;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Service\SupportBundleExporter;
use MP\CommercePromotions\Woo\BlocksHookAudit;
use MP\CommercePromotions\Woo\WooCompatibility;
use PHPUnit\Framework\TestCase;

final class BlocksCompatibilityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mp_cp_test_options'] = array();
	}

	public function test_block_content_detection(): void {
		$this->assertTrue( BlockTestPages::is_block_cart_content( '<!-- wp:woocommerce/cart /-->' ) );
		$this->assertTrue( BlockTestPages::is_block_checkout_content( '<!-- wp:woocommerce/checkout /-->' ) );
		$this->assertFalse( BlockTestPages::is_block_cart_content( '[woocommerce_cart]' ) );
	}

	public function test_block_status_normalization(): void {
		$this->assertSame( BlockTestPages::STATUS_NOT_TESTED, BlockTestPages::normalize_status( '' ) );
		$this->assertSame( BlockTestPages::STATUS_PASSED, BlockTestPages::normalize_status( 'passed' ) );
		$this->assertSame( BlockTestPages::STATUS_NOT_TESTED, BlockTestPages::normalize_status( 'invalid' ) );
	}

	public function test_compatibility_status_includes_block_keys(): void {
		$GLOBALS['mp_cp_test_options'][ BlockTestPages::OPTION_COMPATIBILITY_STATUS ] = BlockTestPages::STATUS_PARTIAL;
		$GLOBALS['mp_cp_test_options'][ BlockTestPages::OPTION_COMPATIBILITY_NOTES ]  = 'Cart fees verified in block cart.';

		$status = ( new CompatibilityStatus() )->collect();

		$this->assertArrayHasKey( 'block_cart_page_id', $status );
		$this->assertArrayHasKey( 'block_checkout_page_id', $status );
		$this->assertArrayHasKey( 'block_pages_present', $status );
		$this->assertArrayHasKey( 'block_compatibility_status', $status );
		$this->assertArrayHasKey( 'block_compatibility_notes', $status );
		$this->assertArrayHasKey( 'blocks_hook_audit_hooks', $status );
		$this->assertFalse( $status['cart_checkout_blocks_declared'] );
		$this->assertSame( BlockTestPages::STATUS_PARTIAL, $status['block_compatibility_status'] );
	}

	public function test_support_bundle_includes_block_status(): void {
		$bundle = ( new SupportBundleExporter( new Settings() ) )->build();
		$env    = $bundle['environment'] ?? array();
		$this->assertIsArray( $env );
		$this->assertArrayHasKey( 'block_compatibility_status', $env );
		$this->assertFalse( $env['cart_checkout_blocks_declared'] ?? true );
	}

	public function test_blocks_hook_debug_defaults_off(): void {
		$this->assertFalse( ( new Settings() )->blocks_hook_debug_enabled() );
	}

	public function test_blocks_hook_audit_lists_expected_hooks(): void {
		$hooks = BlocksHookAudit::audited_hooks();
		$this->assertArrayHasKey( 'woocommerce_cart_calculate_fees', $hooks );
		$this->assertArrayHasKey( 'woocommerce_before_calculate_totals', $hooks );
		$this->assertArrayHasKey( 'woocommerce_checkout_create_order', $hooks );
	}

	public function test_woocommerce_blocks_not_declared_in_features_util(): void {
		$this->assertFalse( WooCompatibility::is_cart_checkout_blocks_declared() );
	}
}
