<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Service\PromotionConcurrencyGuard;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use MP\CommercePromotions\Woo\CartContextBuilder;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;
use PHPUnit\Framework\TestCase;

final class ProductionHardeningClosureTest extends TestCase {

	public function test_checkout_recording_lock_contention(): void {
		if ( ! function_exists( 'set_transient' ) ) {
			$this->markTestSkipped( 'WordPress transient functions unavailable.' );
		}

		$guard = new PromotionConcurrencyGuard();
		$this->assertTrue( $guard->acquire_checkout_recording_lock( 424242 ) );
		$this->assertFalse( $guard->acquire_checkout_recording_lock( 424242 ) );
		$guard->release_checkout_recording_lock( 424242 );
		$this->assertTrue( $guard->acquire_checkout_recording_lock( 424242 ) );
		$guard->release_checkout_recording_lock( 424242 );
	}

	public function test_production_hardening_dashboard_keys(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			$wpdb = new \wpdb();
		}
		$reports  = new PromotionReports(
			new \MP\CommercePromotions\Domain\PromotionRepository( $wpdb ),
			new \MP\CommercePromotions\Domain\RedemptionRepository( $wpdb )
		);
		$settings = new Settings();
		$dash     = $reports->production_hardening_dashboard( $settings );

		$this->assertArrayHasKey( 'profiler', $dash );
		$this->assertArrayHasKey( 'safe_mode', $dash );
		$this->assertArrayHasKey( 'cron_automation_enabled', $dash );
		$this->assertArrayHasKey( 'compatibility_confidence', $dash );
	}

	public function test_planner_performance_includes_profiler(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			$wpdb = new \wpdb();
		}
		$reports = new PromotionReports(
			new \MP\CommercePromotions\Domain\PromotionRepository( $wpdb ),
			new \MP\CommercePromotions\Domain\RedemptionRepository( $wpdb )
		);
		$perf    = $reports->planner_performance();
		$this->assertArrayHasKey( 'profiler', $perf );
		$this->assertArrayHasKey( 'slow_runs', $perf );
	}

	public function test_cart_context_builder_without_redemptions_skips_count(): void {
		$builder  = new CartContextBuilder( null );
		$context  = EvaluationContext::from_array( array( 'customer_id' => 5, 'cart_subtotal' => 10.0 ) );
		$promotion = PromotionTestFixtures::active_promotion_with_id( 10, array(), array() );
		$enriched = $builder->enrich_context_for_promotion( $context, $promotion );
		$this->assertArrayNotHasKey( 'customer_promotion_redemption_count', $enriched->get_metadata() );
	}

	public function test_compatibility_audit_keys(): void {
		$audit = ( new PricingCompatibilityAnalyzer() )->audit_with_confidence( false );
		$this->assertArrayHasKey( 'confidence', $audit );
		$this->assertArrayHasKey( 'recommendations', $audit );
	}

	public function test_degraded_state_round_trip(): void {
		if ( ! function_exists( 'delete_option' ) ) {
			$this->markTestSkipped( 'Options API unavailable.' );
		}

		$profiler = new PromotionPerformanceProfiler();
		$profiler->record_planner_failure( 'unit-test' );
		$this->assertTrue( $profiler->is_storefront_degraded() );
		$profiler->clear_degraded_state();
		$this->assertFalse( $profiler->is_storefront_degraded() );
	}
}
