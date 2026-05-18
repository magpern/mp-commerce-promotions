<?php
/**
 * Lightweight planner load harness (no orders created).
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/load-harness.php
 * Optional env via constants before include:
 *   MP_CP_LOAD_PROMOTIONS=100 MP_CP_LOAD_ITERATIONS=50
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PlannerContextCache;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;

$promo_target  = defined( 'MP_CP_LOAD_PROMOTIONS' ) ? (int) MP_CP_LOAD_PROMOTIONS : 50;
$iterations    = defined( 'MP_CP_LOAD_ITERATIONS' ) ? (int) MP_CP_LOAD_ITERATIONS : 20;
$promo_target  = max( 10, min( 200, $promo_target ) );
$iterations    = max( 5, min( 200, $iterations ) );

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	echo "FAIL: wpdb unavailable\n";
	exit( 1 );
}

$repo    = new PromotionRepository( $wpdb );
$factory = new \MP\CommercePromotions\Domain\PromotionFactory();
$service = new \MP\CommercePromotions\Service\PromotionService(
	$repo,
	$factory,
	new \MP\CommercePromotions\Service\AuditLogger(
		new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb )
	)
);

$created_ids = array();
for ( $i = 0; $i < $promo_target; ++$i ) {
	$draft = $service->create_draft( 'Load harness ' . $i . ' ' . wp_generate_password( 4, false ) );
	$id    = (int) ( $draft->get_id() ?? 0 );
	if ( $id <= 0 ) {
		continue;
	}
	$created_ids[] = $id;
	$updated = $draft
		->with_orchestration( null, 'load-' . ( $i % 5 ) )
		->with_application_rules(
			( $i % 2 === 0 ) ? PromotionApplicationMode::STACKABLE : PromotionApplicationMode::EXCLUSIVE,
			true,
			null
		)
		->with_rules(
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 10 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 + ( $i % 15 ) ) ),
			array()
		);
	$service->update_promotion( $updated );
	$service->change_status( $updated, PromotionStatus::ACTIVE );
}

$active = $repo->find_active_for_planner( 250 );
$context = new EvaluationContext(
	null,
	250.0,
	'USD',
	array(
		array( 'product_id' => 1, 'quantity' => 2, 'line_total' => 250.0 ),
	),
	array()
);

$profiler = new PromotionPerformanceProfiler();
$planner  = new PromotionPlanner( new PromotionEvaluator(), null, $profiler );

$durations = array();
$selected_total = 0;
$skipped_total  = 0;

for ( $n = 0; $n < $iterations; ++$n ) {
	PromotionRepository::clear_request_cache();
	PlannerContextCache::reset_request_cache();
	AllocationContextCache::reset_request_cache();

	$start = microtime( true );
	$plan  = $planner->plan( $active, $context );
	$ms    = ( microtime( true ) - $start ) * 1000;
	$durations[] = $ms;
	$selected_total += count( $plan->get_selected_decisions() );
	$skipped_total  += count( $plan->get_decisions() ) - count( $plan->get_selected_decisions() );
}

sort( $durations );
$p95_index = (int) floor( 0.95 * ( count( $durations ) - 1 ) );
$avg       = array_sum( $durations ) / max( 1, count( $durations ) );
$p95       = $durations[ $p95_index ] ?? 0;

$summary = $profiler->get_report_summary();
$mem_mb  = round( memory_get_peak_usage( true ) / 1024 / 1024, 2 );

foreach ( $created_ids as $hid ) {
	$p = $repo->find( $hid );
	if ( $p !== null ) {
		$service->change_status( $p, PromotionStatus::ARCHIVED );
	}
}

echo "Load harness results\n";
echo "  promotions_active: " . count( $active ) . "\n";
echo "  iterations: {$iterations}\n";
echo "  avg_runtime_ms: " . round( $avg, 2 ) . "\n";
echo "  p95_runtime_ms: " . round( $p95, 2 ) . "\n";
echo "  avg_selected: " . round( $selected_total / max( 1, $iterations ), 2 ) . "\n";
echo "  avg_skipped: " . round( $skipped_total / max( 1, $iterations ), 2 ) . "\n";
echo "  cache_hit_rate_pct: " . ( $summary['allocation_cache_hit_rate'] ?? 0 ) . "\n";
echo "  peak_memory_mb: {$mem_mb}\n";
echo "  blocked_by_coupon_total: " . ( $summary['blocked_by_coupon_count'] ?? 0 ) . "\n";

exit( 0 );
