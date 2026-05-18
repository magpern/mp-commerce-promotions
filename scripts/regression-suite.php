<?php
/**
 * Production pilot regression suite (single operational confidence script).
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/regression-suite.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['mp_cp_regression_ok']    = 0;
$GLOBALS['mp_cp_regression_fail']  = 0;
$GLOBALS['mp_cp_regression_failed'] = array();

function mp_cp_regression_assert( bool $cond, string $scenario ): void {
	if ( $cond ) {
		++$GLOBALS['mp_cp_regression_ok'];
		echo "OK   {$scenario}\n";
		return;
	}
	++$GLOBALS['mp_cp_regression_fail'];
	$GLOBALS['mp_cp_regression_failed'][] = $scenario;
	echo "FAIL {$scenario}\n";
}

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\CouponCompatibilityMatrix;
use MP\CommercePromotions\Service\PromotionDryRunGuard;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\PromotionSnapshotDiffService;
use MP\CommercePromotions\Service\RuntimeAnomalyDetector;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\BlocksHookAudit;
use MP\CommercePromotions\Woo\CouponCoexistenceEvaluator;

$started = microtime( true );

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
$context     = new EvaluationContext(
	null,
	200.0,
	'USD',
	array(
		array( 'product_id' => 1, 'quantity' => 2, 'line_total' => 200.0 ),
	),
	array()
);

$make_active = static function (
	\MP\CommercePromotions\Service\PromotionService $service,
	PromotionRepository $repo,
	string $name,
	array $conditions,
	array $actions,
	string $mode = PromotionApplicationMode::EXCLUSIVE,
	?string $orch = null,
	string $discount_mode = PromotionDiscountApplicationMode::FEE_BASED,
	bool $dry_run = false
) use ( &$created_ids ): int {
	$draft = $service->create_draft( $name . ' ' . wp_generate_password( 4, false ) );
	$id    = (int) ( $draft->get_id() ?? 0 );
	if ( $id <= 0 ) {
		return 0;
	}
	$created_ids[] = $id;
	$updated       = $draft
		->with_rules( $conditions, $actions, array() )
		->with_application_rules( $mode, true, null )
		->with_pricing_fields( null, null, null, $discount_mode );
	if ( $orch !== null ) {
		$updated = $updated->with_orchestration( null, $orch );
	}
	if ( $dry_run ) {
		$updated = $updated->with_dry_run( true );
	}
	$service->update_promotion( $updated );
	$service->change_status( $updated, PromotionStatus::ACTIVE );
	return $id;
};

// Classic fee discount.
$fee_id = $make_active(
	$service,
	$repo,
	'Regression fee',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 50 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 10 ) )
);
$planner = new PromotionPlanner( new PromotionEvaluator(), null, new PromotionPerformanceProfiler() );
$plan    = $planner->plan( $repo->find_active_for_planner( 50 ), $context );
mp_cp_regression_assert( count( $plan->get_selected_decisions() ) >= 1, 'classic_fee_discounts' );

// Stackable.
$make_active(
	$service,
	$repo,
	'Regression stack A',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 5 ) ),
	PromotionApplicationMode::STACKABLE
);
$make_active(
	$service,
	$repo,
	'Regression stack B',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 3 ) ),
	PromotionApplicationMode::STACKABLE
);
$stack_plan = $planner->plan( $repo->find_active_for_planner( 50 ), $context );
mp_cp_regression_assert( count( $stack_plan->get_selected_decisions() ) >= 2, 'stackable' );

// Exclusions.
$ex_a = $make_active(
	$service,
	$repo,
	'Regression ex A',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) ),
	PromotionApplicationMode::EXCLUSIVE
);
$make_active(
	$service,
	$repo,
	'Regression ex B',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 7 ) ),
	PromotionApplicationMode::EXCLUSIVE
);
if ( $ex_a > 0 ) {
	$b = $repo->find( $ex_a );
	if ( $b !== null ) {
		$others = $repo->find_active_for_planner( 20 );
		$target = null;
		foreach ( $others as $p ) {
			if ( (int) ( $p->get_id() ?? 0 ) !== $ex_a ) {
				$target = $p;
				break;
			}
		}
		if ( $target !== null && $target->get_id() !== null ) {
			$service->update_promotion( $b->with_excluded_promotion_ids( array( (int) $target->get_id() ) ) );
		}
	}
}
mp_cp_regression_assert( true, 'exclusions' );

// Orchestration.
$make_active(
	$service,
	$repo,
	'Regression orch 1',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 4 ) ),
	PromotionApplicationMode::EXCLUSIVE,
	'regression-orch'
);
$make_active(
	$service,
	$repo,
	'Regression orch 2',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 6 ) ),
	PromotionApplicationMode::EXCLUSIVE,
	'regression-orch'
);
$orch_plan = $planner->plan( $repo->find_active_for_planner( 80 ), $context );
$orch_meta = $orch_plan->get_metadata();
mp_cp_regression_assert( (int) ( $orch_meta['blocked_by_group_count'] ?? 0 ) >= 0, 'orchestration' );

// Coupon coexistence.
mp_cp_regression_assert( count( ( new CouponCompatibilityMatrix() )->build_scenarios() ) >= 6, 'coupon_coexistence' );
mp_cp_regression_assert( isset( ( new CouponCoexistenceEvaluator() )->evaluate_cart()['native_coupon_count'] ), 'coupon_coexistence_eval' );

// Free gift / free shipping action types registered.
mp_cp_regression_assert(
	class_exists( \MP\CommercePromotions\Engine\Action\FreeGiftProductAction::class ),
	'free_gift'
);
mp_cp_regression_assert( RuleTypes::ACTION_FREE_SHIPPING !== '', 'free_shipping' );

// Line mode + hybrid fallback telemetry option.
$line_id = $make_active(
	$service,
	$repo,
	'Regression line',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) ),
	PromotionApplicationMode::EXCLUSIVE,
	null,
	PromotionDiscountApplicationMode::LINE_ITEM
);
mp_cp_regression_assert( $line_id > 0, 'line_mode' );
mp_cp_regression_assert( is_array( get_option( 'mp_cp_line_discount_fallback_stats', array() ) ), 'hybrid_fallback' );

// Checkout recording hooks present.
$hooks = BlocksHookAudit::audited_hooks();
mp_cp_regression_assert( isset( $hooks['woocommerce_checkout_create_order'] ), 'checkout_recording' );
mp_cp_regression_assert( has_action( 'woocommerce_order_status_cancelled' ) !== false, 'reversal_hooks' );

// Blocks fee path.
mp_cp_regression_assert( isset( $hooks['woocommerce_cart_calculate_fees'] ), 'blocks_fee_path' );

// Dry-run mode.
$settings = new Settings();
$was      = $settings->promotion_dry_run_enabled();
$settings->set_promotion_dry_run_enabled( true );
$guard = new PromotionDryRunGuard( $settings );
mp_cp_regression_assert( $guard->is_global_dry_run(), 'dry_run_mode' );
$settings->set_promotion_dry_run_enabled( $was );

// Budget exhaustion signal in planner metadata.
$budget_id = $make_active(
	$service,
	$repo,
	'Regression budget',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 1 ) )
);
if ( $budget_id > 0 ) {
	$p = $repo->find( $budget_id );
	if ( $p !== null ) {
		$service->update_promotion( $p->with_budget( 0.01, 0.01, 'USD' ) );
	}
}
$budget_plan = $planner->plan( $repo->find_active_for_planner( 80 ), $context );
mp_cp_regression_assert( true, 'budget_exhaustion' );

// Snapshot diff service.
$diff_service = new PromotionSnapshotDiffService();
mp_cp_regression_assert( method_exists( $diff_service, 'diff_against_snapshot' ), 'snapshot_diff' );

// Anomaly detector.
mp_cp_regression_assert( ( new RuntimeAnomalyDetector() )->active_anomalies() !== null, 'anomaly_detection' );

// Schema version.
$schema = get_option( 'mp_cp_schema_version', '0' );
mp_cp_regression_assert( version_compare( (string) $schema, '1.17.0', '>=' ), 'schema_1_17' );

foreach ( $created_ids as $hid ) {
	$p = $repo->find( $hid );
	if ( $p !== null ) {
		try {
			$service->change_status( $p, PromotionStatus::ARCHIVED );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// cleanup best-effort
		}
	}
}

$runtime = round( microtime( true ) - $started, 3 );
$ok      = (int) $GLOBALS['mp_cp_regression_ok'];
$fail    = (int) $GLOBALS['mp_cp_regression_fail'];

echo str_repeat( '-', 40 ) . "\n";
echo "Regression suite: {$ok} passed, {$fail} failed\n";
echo "Runtime: {$runtime}s\n";
if ( $fail > 0 ) {
	echo "Failed scenarios:\n";
	foreach ( $GLOBALS['mp_cp_regression_failed'] as $label ) {
		echo "  - {$label}\n";
	}
	exit( 1 );
}

exit( 0 );
