<?php
/**
 * GA stabilization smoke: ecosystem matrix, health score, complexity, merchant safety.
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/ga-stabilization-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Service\EcosystemCompatibilityRegistry;
use MP\CommercePromotions\Service\KnownLimitationsRegistry;
use MP\CommercePromotions\Service\MerchantSafetyAdvisor;
use MP\CommercePromotions\Service\PromotionComplexityScorer;
use MP\CommercePromotions\Service\PromotionConcurrencyGuard;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Service\SystemHealthService;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;

$ok   = 0;
$fail = 0;

function ga_smoke_assert( bool $cond, string $label ): void {
	global $ok, $fail;
	if ( $cond ) {
		++$ok;
		echo "OK  {$label}\n";
		return;
	}
	++$fail;
	echo "FAIL {$label}\n";
}

$registry = new EcosystemCompatibilityRegistry();
$matrix   = $registry->build_matrix( false );
ga_smoke_assert( count( $matrix ) >= 10, 'ecosystem matrix rows' );

$summary = $registry->summarize( false );
ga_smoke_assert( isset( $summary['score'] ), 'ecosystem summarize score' );

$limitation = KnownLimitationsRegistry::lookup( 'multi_currency_plugin' );
ga_smoke_assert( $limitation['summary'] !== '', 'known limitations lookup' );

$pricing = ( new PricingCompatibilityAnalyzer() )->audit_with_confidence( false );
ga_smoke_assert( isset( $pricing['confidence'] ), 'pricing compatibility audit' );

$profiler = new PromotionPerformanceProfiler();
$profiler->record_planner_run(
	array(
		'duration_ms'           => 50,
		'evaluator_calls'       => 2,
		'condition_checks'      => 2,
		'action_count'          => 1,
		'promotions_considered' => 5,
		'selected_count'        => 1,
	)
);
$perf_summary = $profiler->get_report_summary();
ga_smoke_assert( ! empty( $perf_summary['timing_buckets'] ), 'planner timing buckets' );

$health = new SystemHealthService(
	new Settings(),
	$profiler,
	new PromotionConcurrencyGuard()
);
$health_data = $health->collect( false );
ga_smoke_assert( (int) ( $health_data['score'] ?? 0 ) >= 0, 'system health score' );

global $wpdb;
if ( $wpdb instanceof wpdb ) {
	$repo    = new PromotionRepository( $wpdb );
	$scorer  = new PromotionComplexityScorer( $repo );
	$scored  = $scorer->score_active_promotions( 20 );
	ga_smoke_assert( is_array( $scored ), 'complexity scorer sample' );

	$advisor = new MerchantSafetyAdvisor( $repo );
	$catalog = $advisor->analyze_catalog( 50 );
	ga_smoke_assert( is_array( $catalog ), 'merchant safety catalog scan' );
}

$settings = new Settings();
ga_smoke_assert( method_exists( $settings, 'promotion_dry_run_enabled' ), 'promotion dry-run setting' );
$settings->set_promotion_dry_run_enabled( true );
ga_smoke_assert( $settings->promotion_dry_run_enabled(), 'promotion dry-run toggle persistence' );
$settings->set_promotion_dry_run_enabled( false );

$preview = new \MP\CommercePromotions\Service\ScheduleConflictPreviewService();
ga_smoke_assert( is_array( $preview->preview_site_summary( array(), 5 ) ), 'schedule conflict preview service' );

echo str_repeat( '-', 40 ) . "\n";
echo "GA stabilization smoke: {$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
