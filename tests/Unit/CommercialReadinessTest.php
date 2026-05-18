<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\CompatibilityStatus;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Service\SupportBundleExporter;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class CommercialReadinessTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mp_cp_test_options'] = array();
	}

	public function test_settings_defaults_enable_storefront_features(): void {
		$settings = new Settings();
		$this->assertTrue( $settings->cart_discounts_enabled() );
		$this->assertTrue( $settings->planner_telemetry_enabled() );
		$this->assertTrue( $settings->csv_export_enabled() );
		$this->assertTrue( $settings->free_gift_enabled() );
		$this->assertFalse( $settings->delete_data_on_uninstall() );
		$this->assertTrue( $settings->retain_data_on_uninstall() );
	}

	public function test_telemetry_setting_persists(): void {
		$settings = new Settings();
		$settings->set_planner_telemetry_enabled( false );
		$this->assertFalse( $settings->planner_telemetry_enabled() );
	}

	public function test_csv_export_setting_persists(): void {
		$settings = new Settings();
		$settings->set_csv_export_enabled( false );
		$this->assertFalse( $settings->csv_export_enabled() );
	}

	public function test_delete_on_uninstall_requires_explicit_yes(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->delete_data_on_uninstall() );
		$settings->set_delete_data_on_uninstall( true );
		$this->assertTrue( $settings->delete_data_on_uninstall() );
	}

	public function test_compatibility_status_collects_expected_keys(): void {
		$status = ( new CompatibilityStatus() )->collect();
		$this->assertArrayHasKey( 'wordpress_version', $status );
		$this->assertArrayHasKey( 'woocommerce_version', $status );
		$this->assertArrayHasKey( 'php_version', $status );
		$this->assertArrayHasKey( 'hpos_enabled', $status );
		$this->assertArrayHasKey( 'discount_strategy', $status );
		$this->assertFalse( $status['cart_checkout_blocks_declared'] );
		$this->assertArrayHasKey( 'block_compatibility_status', $status );
		$this->assertArrayHasKey( 'block_pages_present', $status );
	}

	public function test_support_bundle_redacts_sensitive_keys(): void {
		$exporter = new SupportBundleExporter( new Settings() );
		$bundle   = $exporter->build();
		$json     = wp_json_encode( $bundle );
		$this->assertIsString( $json );
		$this->assertStringNotContainsString( '@example.com', $json );
		$this->assertArrayHasKey( 'redaction_notice', $bundle );
		$this->assertArrayHasKey( 'settings', $bundle );
	}

	public function test_validator_warns_when_free_gift_disabled(): void {
		$settings = new Settings();
		$settings->set_free_gift_enabled( false );

		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
					'product_id' => 1,
				),
			)
		);

		$validator = new PromotionRuleValidator();
		$issues    = $validator->validate( $promotion );
		$messages  = implode( ' ', array_column( $issues, 'message' ) );
		$this->assertStringContainsString( 'Free gift actions are disabled', $messages );
	}

	public function test_feature_flags_export_structure(): void {
		$flags = ( new Settings() )->to_feature_flags();
		$this->assertArrayHasKey( 'csv_export', $flags );
		$this->assertArrayHasKey( 'planner_telemetry', $flags );
	}
}
