<?php
/**
 * Closure smoke for production hardening follow-ups.
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/production-hardening-closure-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file inside WordPress.\n" );
	exit( 1 );
}

use MP\CommercePromotions\Domain\AutomationRunRepository;
use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRepository;
use MP\CommercePromotions\Service\PromotionConcurrencyGuard;
use MP\CommercePromotions\Service\PromotionDataRetentionService;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;

$GLOBALS['mp_cp_closure_ok']   = 0;
$GLOBALS['mp_cp_closure_fail'] = 0;

function mp_cp_closure_assert( bool $condition, string $label ): void {
	if ( $condition ) {
		++$GLOBALS['mp_cp_closure_ok'];
		echo "OK  {$label}\n";
		return;
	}
	++$GLOBALS['mp_cp_closure_fail'];
	echo "FAIL {$label}\n";
}

$settings = new Settings();
global $wpdb;

$reports = null;
if ( $wpdb instanceof wpdb ) {
	$reports = new PromotionReports(
		new PromotionRepository( $wpdb ),
		new RedemptionRepository( $wpdb ),
		new PlannerTelemetryRepository( $wpdb ),
		new AutomationRunRepository( $wpdb ),
		null,
		new SimulationScenarioRepository( $wpdb )
	);
}

if ( $reports !== null ) {
	$dash = $reports->production_hardening_dashboard( $settings );
	mp_cp_closure_assert( isset( $dash['profiler'] ), 'production_hardening_dashboard profiler key' );
	mp_cp_closure_assert( isset( $dash['compatibility_confidence'] ), 'production_hardening_dashboard confidence key' );
	mp_cp_closure_assert( array_key_exists( 'safe_mode', $dash ), 'production_hardening_dashboard safe_mode key' );
	$perf = $reports->planner_performance();
	mp_cp_closure_assert( isset( $perf['profiler'] ), 'planner_performance profiler key' );
}

$settings->set_safe_mode_enabled( true );
mp_cp_closure_assert( ! $settings->automatic_promotions_enabled(), 'safe mode effective' );
$settings->set_safe_mode_enabled( false );

$profiler = new PromotionPerformanceProfiler();
$profiler->record_planner_failure( 'closure-smoke-test' );
mp_cp_closure_assert( $profiler->is_storefront_degraded(), 'degraded mode can be set' );
$profiler->clear_degraded_state();
mp_cp_closure_assert( ! $profiler->is_storefront_degraded(), 'degraded mode can be cleared' );

$guard = new PromotionConcurrencyGuard();
mp_cp_closure_assert( $guard->acquire_checkout_recording_lock( 999001 ), 'checkout lock acquire' );
mp_cp_closure_assert( ! $guard->acquire_checkout_recording_lock( 999001 ), 'checkout lock contention' );
$guard->release_checkout_recording_lock( 999001 );

$analyzer = new PricingCompatibilityAnalyzer();
$audit    = $analyzer->audit_with_confidence( false );
mp_cp_closure_assert( isset( $audit['confidence'], $audit['recommendations'] ), 'compatibility confidence keys' );

if ( $wpdb instanceof wpdb ) {
	$retention = new PromotionDataRetentionService( $wpdb, $settings );
	$preview   = $retention->run_daily_cleanup( true );
	mp_cp_closure_assert( isset( $preview['dry_run'] ) && $preview['dry_run'] === true, 'cleanup dry-run summary' );
}

$audit_script = dirname( __DIR__ ) . '/scripts/release-audit.sh';
mp_cp_closure_assert( is_readable( $audit_script ), 'release-audit.sh readable' );

$ok   = (int) ( $GLOBALS['mp_cp_closure_ok'] ?? 0 );
$fail = (int) ( $GLOBALS['mp_cp_closure_fail'] ?? 0 );
echo "\nClosure smoke: {$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
