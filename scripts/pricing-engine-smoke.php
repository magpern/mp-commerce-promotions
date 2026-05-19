<?php
/**
 * WP-CLI smoke: pricing allocation, tax, shipping, coexistence, tiers, profitability, cache.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/pricing-engine-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Domain\PromotionPriorityTier;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Engine\DiscountAllocationEngine;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionConflictAnalyzer;
use MP\CommercePromotions\Service\PromotionPricingRecovery;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Woo\CouponCoexistenceEvaluator;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;
use MP\CommercePromotions\Woo\TaxAwareDiscountCalculator;

$GLOBALS['pricing_smoke_failures'] = 0;

function pricing_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['pricing_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "WP-CLI required.\n";
	exit( 1 );
}

global $wpdb;

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$schema = get_option( 'mp_cp_schema_version', '' );
pricing_smoke_assert( $schema === '1.14.0', 'schema version 1.14.0 (got ' . $schema . ')' );

$context = new EvaluationContext(
	null,
	120.0,
	'USD',
	array(
		array( 'key' => 'line-1', 'product_id' => 1, 'quantity' => 2, 'line_subtotal' => 80.0 ),
		array( 'key' => 'line-2', 'product_id' => 2, 'quantity' => 1, 'line_subtotal' => 40.0 ),
	),
	array( 'shipping_total' => 12.0, 'native_coupon_codes' => array() )
);

$promotion_row = array(
	'id'               => 9001,
	'uuid'             => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
	'name'             => 'Smoke pricing',
	'status'           => PromotionStatus::ACTIVE,
	'priority'         => 5,
	'priority_tier'    => PromotionPriorityTier::CAMPAIGN,
	'coupon_behavior'  => PromotionCouponBehavior::COEXIST,
	'allocation_mode'  => 'proportional',
	'starts_at'        => '2020-01-01 00:00:00',
	'ends_at'          => '2099-12-31 23:59:59',
	'application_mode' => 'stackable',
	'conditions'       => array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	'actions'          => array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) ),
	'restrictions'     => array(),
);

$promotion = \MP\CommercePromotions\Domain\Promotion::from_array( $promotion_row );
$decision  = new PromotionEvaluationDecision(
	$promotion,
	\MP\CommercePromotions\Engine\EvaluationResult::eligible( array(), array() ),
	true,
	null
);
$engine    = new DiscountAllocationEngine();
$result    = $engine->allocate( $context, array( $decision ) );

pricing_smoke_assert( $result->get_total_allocated() > 0, 'allocation engine returns totals' );
pricing_smoke_assert( $result->get_effective_discount_rate() >= 0, 'effective discount rate' );
pricing_smoke_assert( $result->get_line_allocations() !== array(), 'per-line allocations' );

$tax = ( new TaxAwareDiscountCalculator() )->estimate_for_allocation( $context, $result->get_total_allocated(), 12.0 );
pricing_smoke_assert( isset( $tax['estimated_tax_impact'] ), 'tax-aware estimates' );

$coexist = ( new CouponCoexistenceEvaluator() )->evaluate_promotion( $promotion, $context );
pricing_smoke_assert( $coexist['allowed'] === true, 'coupon coexistence allow path' );

$planner = new PromotionPlanner();
$plan    = $planner->plan( array( $promotion ), $context );
pricing_smoke_assert( $plan->get_metrics()['selected_count'] >= 1, 'planner selects promotion' );

AllocationContextCache::reset_request_cache();
$engine->allocate( $context, array( $decision ) );
$engine->allocate( $context, array( $decision ) );
$perf = AllocationContextCache::request_metrics();
pricing_smoke_assert( (int) ( $perf['allocation_hits'] ?? 0 ) >= 1, 'allocation cache metrics' );

$compat = ( new PricingCompatibilityAnalyzer() )->analyze( false );
pricing_smoke_assert( is_array( $compat ), 'compatibility analyzer' );

$repo        = new PromotionRepository( $wpdb );
$redemptions = new RedemptionRepository( $wpdb );
$reports     = new PromotionReports( $repo, $redemptions );
$pricing     = $reports->pricing_analytics();
pricing_smoke_assert( isset( $pricing['priority_tier_counts'] ), 'pricing analytics tier counts' );
$profit = $reports->profitability_analytics();
pricing_smoke_assert( isset( $profit['average_discount_rate'] ), 'profitability analytics' );

$snapshots = new \MP\CommercePromotions\Domain\PromotionSnapshotRepository( $wpdb );
$recovery  = new PromotionPricingRecovery( $repo, $snapshots );
$rebuild  = $recovery->rebuild_allocation_summaries( true );
pricing_smoke_assert( isset( $rebuild['promotions_processed'] ), 'rebuild allocation summaries dry-run' );
$tiers = $recovery->normalize_invalid_priority_tiers( true );
pricing_smoke_assert( isset( $tiers['changed'] ), 'normalize priority tiers dry-run' );

if ( $GLOBALS['pricing_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Pricing engine smoke finished with ' . $GLOBALS['pricing_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Pricing engine smoke passed.' );
