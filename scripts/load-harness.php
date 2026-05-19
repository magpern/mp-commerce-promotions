<?php
/**
 * Lightweight planner load harness (no orders created).
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/load-harness.php
 *
 * Optional defines:
 *   MP_CP_LOAD_PROMOTIONS=100
 *   MP_CP_LOAD_ITERATIONS=50
 *   MP_CP_LOAD_POOL=mixed|orchestration|line (default mixed)
 *   MP_CP_LOAD_CARTS=3 (multi-cart batch count)
 *   MP_CP_LOAD_ANOMALY_SIM=1 (simulate slow planner samples)
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PlannerContextCache;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\RuntimeAnomalyDetector;

$promo_target   = defined( 'MP_CP_LOAD_PROMOTIONS' ) ? (int) MP_CP_LOAD_PROMOTIONS : 50;
$iterations     = defined( 'MP_CP_LOAD_ITERATIONS' ) ? (int) MP_CP_LOAD_ITERATIONS : 20;
$pool           = defined( 'MP_CP_LOAD_POOL' ) ? (string) MP_CP_LOAD_POOL : 'mixed';
$cart_batches   = defined( 'MP_CP_LOAD_CARTS' ) ? (int) MP_CP_LOAD_CARTS : 3;
$anomaly_sim    = defined( 'MP_CP_LOAD_ANOMALY_SIM' ) && (string) MP_CP_LOAD_ANOMALY_SIM === '1';
$promo_target   = max( 10, min( 200, $promo_target ) );
$iterations     = max( 5, min( 200, $iterations ) );
$cart_batches   = max( 1, min( 10, $cart_batches ) );

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

$cart_contexts = array();
for ( $c = 0; $c < $cart_batches; ++$c ) {
	$subtotal = 80.0 + ( $c * 45 );
	$cart_contexts[] = new EvaluationContext(
		null,
		$subtotal,
		'USD',
		array(
			array( 'product_id' => 1 + $c, 'quantity' => 1 + $c, 'line_total' => $subtotal ),
		),
		array()
	);
}

$created_ids = array();
for ( $i = 0; $i < $promo_target; ++$i ) {
	$mode = PromotionApplicationMode::EXCLUSIVE;
	$orch = 'load-' . ( $i % 5 );
	$discount_mode = PromotionDiscountApplicationMode::FEE_BASED;

	if ( $pool === 'orchestration' ) {
		$mode = ( $i % 3 === 0 ) ? PromotionApplicationMode::STACKABLE : PromotionApplicationMode::EXCLUSIVE;
	} elseif ( $pool === 'line' ) {
		$discount_mode = ( $i % 2 === 0 )
			? PromotionDiscountApplicationMode::LINE_ITEM
			: PromotionDiscountApplicationMode::HYBRID;
		$orch = null;
	} else {
		$mode          = ( $i % 2 === 0 ) ? PromotionApplicationMode::STACKABLE : PromotionApplicationMode::EXCLUSIVE;
		$discount_mode = ( $i % 7 === 0 ) ? PromotionDiscountApplicationMode::LINE_ITEM : PromotionDiscountApplicationMode::FEE_BASED;
	}

	$draft = $service->create_draft( 'Load harness ' . $pool . ' ' . $i . ' ' . wp_generate_password( 4, false ) );
	$id    = (int) ( $draft->get_id() ?? 0 );
	if ( $id <= 0 ) {
		continue;
	}
	$created_ids[] = $id;
	$updated = $draft
		->with_application_rules( $mode, true, null )
		->with_rules(
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 5 + ( $i % 20 ) ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 3 + ( $i % 12 ) ) ),
			array()
		)
		->with_pricing_fields( null, null, null, $discount_mode );
	if ( $orch !== null ) {
		$updated = $updated->with_orchestration( null, $orch );
	}
	$service->update_promotion( $updated );
	$service->change_status( $updated, PromotionStatus::ACTIVE );
}

$active  = $repo->find_active_for_planner( 250 );
$profiler = new PromotionPerformanceProfiler();
$planner  = new PromotionPlanner( new PromotionEvaluator(), null, $profiler );
$detector = new RuntimeAnomalyDetector();

$durations      = array();
$memory_samples = array();
$selected_total = 0;
$skipped_total  = 0;

for ( $n = 0; $n < $iterations; ++$n ) {
	PromotionRepository::clear_request_cache();
	PlannerContextCache::reset_request_cache();
	AllocationContextCache::reset_request_cache();

	$context = $cart_contexts[ $n % count( $cart_contexts ) ];
	$start   = microtime( true );
	$plan    = $planner->plan( $active, $context );
	$ms      = ( microtime( true ) - $start ) * 1000;
	$durations[] = $ms;
	$memory_samples[] = memory_get_usage( true );
	$selected_total += count( $plan->get_selected_decisions() );
	$skipped_total  += count( $plan->get_decisions() ) - count( $plan->get_selected_decisions() );

	if ( $anomaly_sim && $n % 7 === 0 ) {
		$detector->record_planner_sample(
			array(
				'duration_ms'            => 600,
				'promotions_considered'  => count( $active ),
				'selected_count'         => 1,
				'blocked_by_budget_count'=> 2,
				'coupon_conflict_count'  => 1,
			)
		);
	}
}

sort( $durations );
$p95_index = (int) floor( 0.95 * max( 0, count( $durations ) - 1 ) );
$avg       = array_sum( $durations ) / max( 1, count( $durations ) );
$p95       = $durations[ $p95_index ] ?? 0;
$mem_start = $memory_samples[0] ?? 0;
$mem_end   = $memory_samples[ count( $memory_samples ) - 1 ] ?? 0;
$mem_trend = $mem_end - $mem_start;

$summary = $profiler->get_report_summary();
$mem_mb  = round( memory_get_peak_usage( true ) / 1024 / 1024, 2 );

foreach ( $created_ids as $hid ) {
	$p = $repo->find( $hid );
	if ( $p !== null ) {
		$service->change_status( $p, PromotionStatus::ARCHIVED );
	}
}

$anomalies = $detector->active_anomalies( $profiler );

echo "Load harness results\n";
echo "  pool: {$pool}\n";
echo "  cart_batches: {$cart_batches}\n";
echo "  promotions_active: " . count( $active ) . "\n";
echo "  iterations: {$iterations}\n";
echo "  avg_runtime_ms: " . round( $avg, 2 ) . "\n";
echo "  p95_runtime_ms: " . round( $p95, 2 ) . "\n";
echo "  avg_selected: " . round( $selected_total / max( 1, $iterations ), 2 ) . "\n";
echo "  avg_skipped: " . round( $skipped_total / max( 1, $iterations ), 2 ) . "\n";
echo "  cache_hit_rate_pct: " . ( $summary['allocation_cache_hit_rate'] ?? 0 ) . "\n";
echo "  peak_memory_mb: {$mem_mb}\n";
echo "  memory_trend_bytes: {$mem_trend}\n";
echo "  blocked_by_coupon_total: " . ( $summary['blocked_by_coupon_count'] ?? 0 ) . "\n";
echo "  anomaly_indicators: " . count( $anomalies ) . "\n";
if ( $anomalies !== array() ) {
	echo "  anomaly_codes: " . implode( ', ', array_column( $anomalies, 'code' ) ) . "\n";
}

exit( 0 );
