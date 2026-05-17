<?php
/**
 * WP-CLI smoke: conflict analysis heuristics and planner explainability (in-memory).
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/conflict-analysis-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionPlanExplainer;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionConflictAnalyzer;

$GLOBALS['smoke_failures'] = 0;

/**
 * @param list<string> $types
 */
function conflict_smoke_assert_types( array $conflicts, string $expected_type, string $label ): void {
	$types = array_column( $conflicts, 'type' );
	conflict_smoke_assert( in_array( $expected_type, $types, true ), $label );
}

function conflict_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

function conflict_smoke_promotion(
	int $id,
	array $conditions,
	array $actions,
	string $mode = PromotionApplicationMode::STACKABLE,
	bool $stop = false,
	?int $max_applications = null,
	array $excluded = array(),
	int $priority = 10
): \MP\CommercePromotions\Domain\Promotion {
	$data = array(
		'id'                     => $id,
		'uuid'                   => sprintf( '00000000-0000-4000-8000-%012d', $id ),
		'name'                   => 'Smoke ' . $id,
		'status'                 => 'active',
		'priority'               => $priority,
		'conditions'             => $conditions,
		'actions'                => $actions,
		'application_mode'       => $mode,
		'stop_processing'        => $stop,
		'max_applications'       => $max_applications,
		'excluded_promotion_ids' => $excluded,
	);

	return \MP\CommercePromotions\Domain\Promotion::from_array( $data );
}

function conflict_smoke_cart_context( float $subtotal = 100.0 ): \MP\CommercePromotions\Engine\EvaluationContext {
	return new \MP\CommercePromotions\Engine\EvaluationContext( null, $subtotal, 'USD', array(), array() );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "WP-CLI required.\n";
	exit( 1 );
}

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$analyzer = new PromotionConflictAnalyzer();
$planner  = new PromotionPlanner();
$cond     = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
$pct      = array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) );

$schema = get_option( 'mp_cp_schema_version', '' );
conflict_smoke_assert( $schema === '1.8.0', 'schema version 1.8.0 (got ' . $schema . ')' );

$a = conflict_smoke_promotion( 10, $cond, $pct, PromotionApplicationMode::STACKABLE, false, null, array( 20 ) );
$b = conflict_smoke_promotion( 20, $cond, $pct, PromotionApplicationMode::STACKABLE, false, null, array( 10 ) );
$mutual = $analyzer->analyze( array( $a, $b ) );
conflict_smoke_assert_types( $mutual, PromotionConflictAnalyzer::TYPE_MUTUAL_EXCLUSION, 'mutual exclusion detected' );

$exclusive = conflict_smoke_promotion( 1, $cond, $pct, PromotionApplicationMode::EXCLUSIVE, true, null, array(), 100 );
$stackable = conflict_smoke_promotion( 2, $cond, $pct, PromotionApplicationMode::STACKABLE, false, null, array(), 5 );
$low       = conflict_smoke_promotion( 3, $cond, $pct, PromotionApplicationMode::STACKABLE, false, null, array(), 1 );
$shadow    = $analyzer->analyze( array( $exclusive, $stackable, $low ) );
conflict_smoke_assert_types( $shadow, PromotionConflictAnalyzer::TYPE_EXCLUSIVE_VS_STACKABLE, 'exclusive vs stackable warning' );
conflict_smoke_assert_types( $shadow, PromotionConflictAnalyzer::TYPE_PRIORITY_SHADOWING, 'priority shadowing detected' );

$gift_a = conflict_smoke_promotion(
	30,
	$cond,
	array( array( 'type' => RuleTypes::ACTION_FREE_GIFT_PRODUCT, 'product_id' => 99, 'quantity' => 1 ) )
);
$gift_b = conflict_smoke_promotion(
	31,
	$cond,
	array( array( 'type' => RuleTypes::ACTION_FREE_GIFT_PRODUCT, 'product_id' => 99, 'quantity' => 1 ) )
);
$gift_conflicts = $analyzer->analyze( array( $gift_a, $gift_b ) );
conflict_smoke_assert_types( $gift_conflicts, PromotionConflictAnalyzer::TYPE_GIFT_OVERLAP, 'gift overlap detected' );

$ship_a = conflict_smoke_promotion( 40, $cond, array( array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ) ) );
$ship_b = conflict_smoke_promotion( 41, $cond, array( array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ) ) );
$ship_conflicts = $analyzer->analyze( array( $ship_a, $ship_b ) );
conflict_smoke_assert_types( $ship_conflicts, PromotionConflictAnalyzer::TYPE_FREE_SHIPPING_OVERLAP, 'free shipping overlap detected' );

$blocker = conflict_smoke_promotion( 12, $cond, $pct, PromotionApplicationMode::STACKABLE, false, null, array( 15 ), 50 );
$skipped = conflict_smoke_promotion( 15, $cond, $pct );
$plan    = $planner->plan( array( $blocker, $skipped ), conflict_smoke_cart_context() );
$explain = PromotionPlanExplainer::explain( $plan );

$has_exclusion_skip = false;
foreach ( $explain['skipped'] as $row ) {
	if ( ( $row['reason_code'] ?? '' ) === PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED ) {
		$has_exclusion_skip = true;
	}
}
conflict_smoke_assert( $has_exclusion_skip, 'plan explanation includes excluded_by_selected skip' );

$joined = implode( ' ', $explain['summary_lines'] ?? array() );
conflict_smoke_assert( strpos( $joined, '15' ) !== false && strpos( $joined, '12' ) !== false, 'summary lines mention skipped and excluding promotions' );

$cap_first = conflict_smoke_promotion( 1, $cond, $pct, PromotionApplicationMode::STACKABLE, false, 2, array(), 10 );
$cap_second = conflict_smoke_promotion( 2, $cond, $pct );
$cap_third  = conflict_smoke_promotion( 3, $cond, $pct );
$cap_plan   = $planner->plan( array( $cap_first, $cap_second, $cap_third ), conflict_smoke_cart_context() );
$cap_explain = PromotionPlanExplainer::explain( $cap_plan );

$has_max_skip = false;
foreach ( $cap_explain['skipped'] as $row ) {
	if ( ( $row['reason_code'] ?? '' ) === PromotionEvaluationDecision::REASON_MAX_APPLICATIONS_REACHED ) {
		$has_max_skip = true;
	}
}
conflict_smoke_assert( $has_max_skip, 'plan explanation includes max_applications_reached skip' );
conflict_smoke_assert( ( $cap_explain['max_applications'] ?? array() ) !== array(), 'max_applications section populated' );

if ( (int) $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( 'Conflict analysis smoke finished with ' . (int) $GLOBALS['smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Conflict analysis smoke passed.' );
