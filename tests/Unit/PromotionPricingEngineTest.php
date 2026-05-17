<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Domain\PromotionPriorityTier;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Engine\EvaluationResult;
use MP\CommercePromotions\Engine\DiscountAllocationEngine;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;
use MP\CommercePromotions\Engine\PromotionPlanExplainer;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionConflictAnalyzer;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use MP\CommercePromotions\Woo\CouponCoexistenceEvaluator;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;
use MP\CommercePromotions\Woo\TaxAwareDiscountCalculator;
use PHPUnit\Framework\TestCase;

final class PromotionPricingEngineTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AllocationContextCache::reset_request_cache();
	}

	public function test_priority_tier_sorts_before_numeric_priority(): void {
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) );

		$low_tier_high_num = PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act );
		$data              = $low_tier_high_num->to_array();
		$data['priority']  = 100;
		$data['priority_tier'] = PromotionPriorityTier::STOREFRONT;
		$low_tier_high_num = Promotion::from_array( $data );

		$high_tier_low_num = PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act );
		$data              = $high_tier_low_num->to_array();
		$data['priority']  = 1;
		$data['priority_tier'] = PromotionPriorityTier::OVERRIDE;
		$high_tier_low_num = Promotion::from_array( $data );

		$sorted = PromotionPriorityTier::sort_promotions( array( $low_tier_high_num, $high_tier_low_num ) );
		$this->assertSame( 2, $sorted[0]->get_id() );
	}

	public function test_allocation_engine_proportional_lines(): void {
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) );
		$promotion = PromotionTestFixtures::active_promotion_with_id( 5, $cond, $act );

		$context = PromotionTestFixtures::cart_context(
			null,
			100.0,
			array(
				array( 'key' => 'a', 'product_id' => 1, 'quantity' => 1, 'line_subtotal' => 60.0 ),
				array( 'key' => 'b', 'product_id' => 2, 'quantity' => 1, 'line_subtotal' => 40.0 ),
			),
			array( 'shipping_total' => 10.0 )
		);

		$decision = new PromotionEvaluationDecision(
			$promotion,
			EvaluationResult::eligible( array(), array() ),
			true,
			null
		);
		$result   = ( new DiscountAllocationEngine() )->allocate( $context, array( $decision ) );

		$this->assertGreaterThan( 0, $result->get_total_allocated() );
		$this->assertNotEmpty( $result->get_line_allocations() );
		$this->assertGreaterThan( 0.0, $result->get_effective_discount_rate() );
	}

	public function test_allocation_cache_hit_on_repeat(): void {
		AllocationContextCache::reset_request_cache();
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 15 ) );
		$promotion = PromotionTestFixtures::active_promotion_with_id( 7, $cond, $act );
		$context   = PromotionTestFixtures::cart_context( null, 50.0 );
		$decision  = new PromotionEvaluationDecision(
			$promotion,
			EvaluationResult::eligible( array(), array() ),
			true,
			null
		);
		$engine    = new DiscountAllocationEngine();

		$engine->allocate( $context, array( $decision ) );
		$engine->allocate( $context, array( $decision ) );

		$metrics = AllocationContextCache::request_metrics();
		$this->assertGreaterThanOrEqual( 1, (int) ( $metrics['allocation_hits'] ?? 0 ) );
	}

	public function test_tax_aware_calculator_estimates(): void {
		$calc    = new TaxAwareDiscountCalculator();
		$context = PromotionTestFixtures::cart_context( null, 100.0, array(), array( 'shipping_total' => 5.0 ) );
		$out     = $calc->estimate_for_allocation( $context, 20.0, 5.0 );
		$this->assertArrayHasKey( 'before_tax_discount', $out );
		$this->assertArrayHasKey( 'after_tax_discount', $out );
		$this->assertArrayHasKey( 'estimated_tax_impact', $out );
	}

	public function test_coupon_coexistence_block_native(): void {
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) );
		$promotion = PromotionTestFixtures::active_promotion_with_id( 3, $cond, $act );
		$data      = $promotion->to_array();
		$data['coupon_behavior'] = PromotionCouponBehavior::BLOCK_NATIVE;
		$promotion = Promotion::from_array( $data );

		$context = PromotionTestFixtures::cart_context(
			null,
			50.0,
			array(),
			array( 'native_coupon_codes' => array( 'SAVE10' ) )
		);

		$eval = new CouponCoexistenceEvaluator();
		$out  = $eval->evaluate_promotion( $promotion, $context );
		$this->assertFalse( $out['allowed'] );
		$this->assertSame( PromotionEvaluationDecision::REASON_BLOCKED_BY_COUPON, $out['reason'] );
	}

	public function test_planner_skips_when_blocked_by_coupon(): void {
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) );
		$promotion = PromotionTestFixtures::active_promotion_with_id( 4, $cond, $act );
		$data      = $promotion->to_array();
		$data['coupon_behavior'] = PromotionCouponBehavior::BLOCK_NATIVE;
		$promotion = Promotion::from_array( $data );

		$context = PromotionTestFixtures::cart_context(
			null,
			80.0,
			array(),
			array( 'native_coupon_codes' => array( 'WELCOME' ) )
		);

		$plan = ( new PromotionPlanner() )->plan( array( $promotion ), $context );
		$this->assertSame( 0, $plan->get_metrics()['selected_count'] );
		$this->assertGreaterThanOrEqual( 1, $plan->get_metrics()['blocked_by_coupon_count'] ?? 0 );
	}

	public function test_tier_congestion_conflict(): void {
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) );

		$promotions = array();
		foreach ( array( 10, 11, 12 ) as $id ) {
			$p    = PromotionTestFixtures::active_promotion_with_id( $id, $cond, $act );
			$data = $p->to_array();
			$data['priority_tier'] = PromotionPriorityTier::CAMPAIGN;
			$data['starts_at']     = '2026-01-01 00:00:00';
			$data['ends_at']       = '2026-12-31 23:59:59';
			$promotions[]          = Promotion::from_array( $data );
		}

		$conflicts = ( new PromotionConflictAnalyzer() )->analyze( $promotions );
		$types     = array_column( $conflicts, 'type' );
		$this->assertContains( PromotionConflictAnalyzer::TYPE_TIER_CONGESTION, $types );
	}

	public function test_compatibility_analyzer_returns_findings(): void {
		$findings = ( new PricingCompatibilityAnalyzer() )->analyze( false );
		$this->assertIsArray( $findings );
		foreach ( $findings as $finding ) {
			$this->assertArrayHasKey( 'severity', $finding );
			$this->assertArrayHasKey( 'message', $finding );
		}
	}

	public function test_explainability_includes_allocation_fields(): void {
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) );
		$promotion = PromotionTestFixtures::active_promotion_with_id( 8, $cond, $act );
		$context   = PromotionTestFixtures::cart_context( null, 100.0 );
		$decision  = new PromotionEvaluationDecision(
			$promotion,
			EvaluationResult::eligible( array(), array() ),
			true,
			null
		);
		$allocation = ( new DiscountAllocationEngine() )->allocate( $context, array( $decision ) );

		$plan = new PromotionEvaluationPlan( array( $decision ) );
		$base = PromotionPlanExplainer::explain( $plan );
		$rich = PromotionPlanExplainer::enrich_explanation( $base, $plan, $context, $allocation );

		$this->assertArrayHasKey( 'allocation_summary', $rich );
		$this->assertArrayHasKey( 'effective_savings_rate', $rich );
	}

	public function test_validator_pricing_warnings(): void {
		$promotion = Promotion::from_array(
			array(
				'id'               => 20,
				'uuid'             => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
				'name'             => 'Heavy discount',
				'status'           => PromotionStatus::ACTIVE,
				'starts_at'        => '2026-01-01 00:00:00',
				'ends_at'          => '2026-12-31 23:59:59',
				'application_mode' => 'stackable',
				'coupon_behavior'  => PromotionCouponBehavior::COEXIST,
				'actions'          => array(
					array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 90 ),
					array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ),
				),
			)
		);

		$issues   = ( new PromotionRuleValidator() )->validate( $promotion );
		$messages = strtolower( implode( ' ', array_column( $issues, 'message' ) ) );
		$this->assertStringContainsString( 'shipping', $messages );
	}

	public function test_allocation_result_serializes_summary(): void {
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 5 ) );
		$promotion = PromotionTestFixtures::active_promotion_with_id( 9, $cond, $act );
		$context   = PromotionTestFixtures::cart_context( null, 25.0 );
		$decision  = new PromotionEvaluationDecision(
			$promotion,
			EvaluationResult::eligible( array(), array() ),
			true,
			null
		);
		$result = ( new DiscountAllocationEngine() )->allocate( $context, array( $decision ) );
		$array  = $result->to_array();
		$this->assertArrayHasKey( 'total_allocated', $array );
		$this->assertArrayHasKey( 'effective_discount_rate', $array );
	}
}
