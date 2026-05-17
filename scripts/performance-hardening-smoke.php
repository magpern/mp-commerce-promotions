<?php
/**
 * Smoke checks for performance and production hardening milestone.
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/performance-hardening-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/performance-hardening-smoke.php\n" );
	exit( 1 );
}

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Service\PromotionConcurrencyGuard;
use MP\CommercePromotions\Service\PromotionCronScheduler;
use MP\CommercePromotions\Service\PromotionDataRetentionService;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;

$GLOBALS['mp_cp_smoke_ok']   = 0;
$GLOBALS['mp_cp_smoke_fail'] = 0;

function mp_cp_smoke_assert( bool $condition, string $label ): void {
	if ( $condition ) {
		++$GLOBALS['mp_cp_smoke_ok'];
		echo "OK  {$label}\n";
		return;
	}
	++$GLOBALS['mp_cp_smoke_fail'];
	echo "FAIL {$label}\n";
}

$settings = new Settings();
$profiler = new PromotionPerformanceProfiler();

$planner = new PromotionPlanner( null, null, $profiler );
$plan    = $planner->plan( array(), EvaluationContext::from_array( array( 'cart_subtotal' => 100.0 ) ) );
mp_cp_smoke_assert( $plan instanceof \MP\CommercePromotions\Engine\PromotionEvaluationPlan, 'planner runs with profiler' );

$aggregates = $profiler->get_aggregates();
mp_cp_smoke_assert( (int) ( $aggregates['planner_runs'] ?? 0 ) >= 1, 'profiler recorded planner run' );

$guard = new PromotionConcurrencyGuard();
mp_cp_smoke_assert( $guard->acquire_planner_lock(), 'planner lock acquire' );
$guard->release_planner_lock();
mp_cp_smoke_assert( ! get_transient( PromotionConcurrencyGuard::TRANSIENT_PLANNER_LOCK ), 'planner lock release' );

mp_cp_smoke_assert( method_exists( PromotionCronScheduler::class, 'register' ), 'cron scheduler exists' );
mp_cp_smoke_assert( ! $settings->cron_automation_enabled(), 'cron disabled by default' );

global $wpdb;
if ( $wpdb instanceof wpdb ) {
	$retention = new PromotionDataRetentionService( $wpdb, $settings );
	$estimates = $retention->storage_estimates();
	mp_cp_smoke_assert( isset( $estimates['promotions'] ), 'storage estimates' );
	$preview = $retention->run_daily_cleanup( true );
	mp_cp_smoke_assert( isset( $preview['dry_run'] ) && $preview['dry_run'] === true, 'retention dry-run cleanup' );
}

$settings->set_safe_mode_enabled( true );
mp_cp_smoke_assert( ! $settings->automatic_promotions_enabled(), 'safe mode disables automatic promotions' );
$settings->set_safe_mode_enabled( false );

$analyzer = new PricingCompatibilityAnalyzer();
$audit    = $analyzer->audit_with_confidence( false );
mp_cp_smoke_assert(
	in_array(
		(string) ( $audit['confidence'] ?? '' ),
		array(
			PricingCompatibilityAnalyzer::CONFIDENCE_HIGH,
			PricingCompatibilityAnalyzer::CONFIDENCE_MEDIUM,
			PricingCompatibilityAnalyzer::CONFIDENCE_LOW,
			PricingCompatibilityAnalyzer::CONFIDENCE_UNKNOWN,
		),
		true
	),
	'compatibility confidence scoring'
);

$audit_script = dirname( __DIR__ ) . '/scripts/release-audit.sh';
mp_cp_smoke_assert( is_readable( $audit_script ), 'release-audit.sh present' );

$ok   = (int) ( $GLOBALS['mp_cp_smoke_ok'] ?? 0 );
$fail = (int) ( $GLOBALS['mp_cp_smoke_fail'] ?? 0 );
echo "\nSmoke summary: {$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
