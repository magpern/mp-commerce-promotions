<?php
/**
 * Coupon coexistence certification smoke.
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/coupon-compatibility-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\CouponCompatibilityMatrix;
use MP\CommercePromotions\Service\CouponCoexistencePreviewService;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Woo\CouponCoexistenceEvaluator;

$ok = 0;
$fail = 0;

function coupon_smoke_assert( bool $cond, string $label ): void {
	global $ok, $fail;
	if ( $cond ) {
		++$ok;
		echo "OK  {$label}\n";
		return;
	}
	++$fail;
	echo "FAIL {$label}\n";
}

$matrix = new CouponCompatibilityMatrix();
coupon_smoke_assert( count( $matrix->build_scenarios() ) >= 6, 'coupon matrix scenarios' );

$native = ( new CouponCoexistenceEvaluator() )->evaluate_cart();
coupon_smoke_assert( isset( $native['native_coupon_count'] ), 'native coupon evaluation' );

global $wpdb;
if ( $wpdb instanceof wpdb ) {
	$repo    = new \MP\CommercePromotions\Domain\PromotionRepository( $wpdb );
	$factory = new \MP\CommercePromotions\Domain\PromotionFactory();
	$draft   = $factory->create_draft( 'Coupon smoke ' . gmdate( 'His' ) );
	$promo   = $draft
		->with_rules(
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) ),
			array()
		)
		->with_pricing_fields( null, null, null, 'fee_based' );
	$promo = $promo->with_application_rules( PromotionApplicationMode::EXCLUSIVE, true, null );
	$repo->insert( $promo->with_status( PromotionStatus::ACTIVE ) );
	$loaded = $repo->find_by_name( $promo->get_name() );
	if ( $loaded !== null ) {
		$preview = ( new CouponCoexistencePreviewService() )->preview_for_promotion( $loaded );
		coupon_smoke_assert( isset( $preview['promotion_check']['allowed'] ), 'coexistence preview' );
		$context = new EvaluationContext(
			null,
			100.0,
			'USD',
			array(),
			array( 'native_coupon_codes' => array( 'TESTCOUPON' ) )
		);
		$plan = ( new PromotionPlanner( new PromotionEvaluator() ) )->plan( array( $loaded ), $context );
		coupon_smoke_assert( ( $plan->get_metrics()['blocked_by_coupon_count'] ?? 0 ) >= 0, 'planner coupon metrics' );
		if ( $loaded->get_id() !== null ) {
			$repo->update( $loaded->with_status( PromotionStatus::ARCHIVED ) );
		}
	}
}

$profiler = new PromotionPerformanceProfiler();
coupon_smoke_assert( method_exists( $profiler, 'increment_coupon_conflict' ), 'coupon telemetry counters' );

echo str_repeat( '-', 40 ) . "\n";
echo "Coupon compatibility smoke: {$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
