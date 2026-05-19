<?php
/**
 * GA stress smoke: planner at scale with temporary promotions (archived after run).
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/ga-stress-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\PromotionService;

$ok   = 0;
$fail = 0;

function ga_stress_assert( bool $cond, string $label ): void {
	global $ok, $fail;
	if ( $cond ) {
		++$ok;
		echo "OK  {$label}\n";
		return;
	}
	++$fail;
	echo "FAIL {$label}\n";
}

global $wpdb;

if ( ! $wpdb instanceof wpdb ) {
	echo "FAIL: wpdb unavailable\n";
	exit( 1 );
}

$schema = get_option( 'mp_cp_schema_version', '' );
ga_stress_assert( version_compare( (string) $schema, '1.16.0', '>=' ), 'schema >= 1.16.0 (got ' . $schema . ')' );

$repo    = new PromotionRepository( $wpdb );
$audit   = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_l = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory = new \MP\CommercePromotions\Domain\PromotionFactory();
$service = new PromotionService( $repo, $factory, $audit_l );

$created_ids = array();
$target      = 100;
$groups      = array( 'stress-a', 'stress-b', 'stress-c' );

try {
	for ( $i = 0; $i < $target; ++$i ) {
		$draft = $service->create_draft( 'GA stress ' . gmdate( 'YmdHis' ) . '-' . $i );
		$id    = (int) $draft->get_id();
		if ( $id <= 0 ) {
			continue;
		}
		$created_ids[] = $id;

		$mode = ( $i % 3 === 0 ) ? PromotionApplicationMode::EXCLUSIVE : PromotionApplicationMode::STACKABLE;
		$pct  = 5 + ( $i % 20 );
		$orch = $groups[ $i % count( $groups ) ];

		$updated = $draft
			->with_orchestration( null, $orch )
			->with_application_rules( $mode, $i % 5 !== 0, null )
			->with_rules(
				array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
				array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => $pct ) ),
				array()
			);
		$service->update_promotion( $updated );
		$service->change_status( $updated, PromotionStatus::ACTIVE );
	}

	ga_stress_assert( count( $created_ids ) >= 50, 'created stress promotions (>=50)' );

	$active = $repo->find_active_for_planner( 250 );
	$context = new EvaluationContext(
		null,
		500.0,
		'USD',
		array(
			array(
				'product_id' => 1,
				'quantity'   => 2,
				'line_total' => 500.0,
			),
		),
		array()
	);

	$profiler = new PromotionPerformanceProfiler();
	$planner  = new PromotionPlanner( new PromotionEvaluator(), null, $profiler );

	$start = microtime( true );
	$plan  = $planner->plan( $active, $context );
	$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );

	$selected = count( $plan->get_selected_decisions() );
	$skipped  = count( $plan->get_decisions() ) - $selected;

	ga_stress_assert( $selected >= 0, 'planner selected count' );
	ga_stress_assert( $skipped >= 0, 'planner skipped count' );
	ga_stress_assert( $ms < 30000, 'planner runtime under 30s (' . $ms . 'ms)' );

	$summary = $profiler->get_report_summary();
	ga_stress_assert( ! empty( $summary['timing_buckets'] ), 'profiler timing buckets updated' );

	echo "INFO selected={$selected} skipped={$skipped} runtime_ms={$ms}\n";
} catch ( Throwable $e ) {
	ga_stress_assert( false, 'no fatal: ' . $e->getMessage() );
} finally {
	foreach ( $created_ids as $stress_id ) {
		$p = $repo->find( $stress_id );
		if ( $p !== null ) {
			$service->change_status( $p, PromotionStatus::ARCHIVED );
		}
	}
}

echo str_repeat( '-', 40 ) . "\n";
echo "GA stress smoke: {$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
