<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Service\PromotionConcurrencyGuard;
use MP\CommercePromotions\Service\PromotionCronScheduler;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;
use PHPUnit\Framework\TestCase;

final class PerformanceHardeningTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( function_exists( 'delete_option' ) ) {
			\delete_option( PromotionPerformanceProfiler::OPTION_AGGREGATES );
			\delete_option( PromotionPerformanceProfiler::OPTION_DEGRADED_STATE );
			\delete_option( PromotionConcurrencyGuard::OPTION_WARNINGS );
		}
		if ( function_exists( 'delete_transient' ) ) {
			\delete_transient( PromotionConcurrencyGuard::TRANSIENT_PLANNER_LOCK );
			\delete_transient( PromotionConcurrencyGuard::TRANSIENT_AUTOMATION_LOCK );
		}
	}

	public function test_profiler_records_planner_run(): void {
		$profiler = new PromotionPerformanceProfiler();
		$planner  = new PromotionPlanner( null, null, $profiler );
		$planner->plan( array(), EvaluationContext::from_array( array( 'cart_subtotal' => 50.0 ) ) );

		$summary = $profiler->get_report_summary();
		$this->assertGreaterThanOrEqual( 1, (int) ( $summary['planner_runs'] ?? 0 ) );
		$this->assertGreaterThanOrEqual( 0.0, (float) ( $summary['average_planner_ms'] ?? 0 ) );
	}

	public function test_profiler_records_failure_and_degraded_state(): void {
		$profiler = new PromotionPerformanceProfiler();
		$profiler->record_planner_failure( 'test failure' );
		$this->assertTrue( $profiler->is_storefront_degraded() );
		$profiler->clear_degraded_state();
		$this->assertFalse( $profiler->is_storefront_degraded() );
	}

	public function test_concurrency_guard_records_warning_on_contention(): void {
		$guard = new PromotionConcurrencyGuard();
		$this->assertTrue( $guard->acquire_planner_lock() );
		$this->assertFalse( $guard->acquire_planner_lock() );
		$warnings = $guard->get_warnings();
		$this->assertNotEmpty( $warnings );
		$guard->release_planner_lock();
	}

	public function test_settings_safe_mode_disables_automatic_promotions(): void {
		$settings = new Settings();
		$settings->set_cart_discounts_enabled( true );
		$settings->set_safe_mode_enabled( true );
		$this->assertFalse( $settings->automatic_promotions_enabled() );
		$settings->set_safe_mode_enabled( false );
	}

	public function test_cron_disabled_by_default(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->cron_automation_enabled() );
	}

	public function test_telemetry_pause_blocks_planner_telemetry_flag(): void {
		$settings = new Settings();
		$settings->set_planner_telemetry_enabled( true );
		$settings->set_telemetry_paused( true );
		$this->assertFalse( $settings->planner_telemetry_enabled() );
		$settings->set_telemetry_paused( false );
	}

	public function test_compatibility_confidence_audit(): void {
		$analyzer = new PricingCompatibilityAnalyzer();
		$audit    = $analyzer->audit_with_confidence( false );
		$this->assertArrayHasKey( 'confidence', $audit );
		$this->assertContains(
			$audit['confidence'],
			array(
				PricingCompatibilityAnalyzer::CONFIDENCE_HIGH,
				PricingCompatibilityAnalyzer::CONFIDENCE_MEDIUM,
				PricingCompatibilityAnalyzer::CONFIDENCE_LOW,
				PricingCompatibilityAnalyzer::CONFIDENCE_UNKNOWN,
			)
		);
	}

	public function test_cron_scheduler_hooks_exist(): void {
		$this->assertTrue( method_exists( PromotionCronScheduler::class, 'run_hourly' ) );
		$this->assertTrue( method_exists( PromotionCronScheduler::class, 'run_daily' ) );
	}
}
