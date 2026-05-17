<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PlannerContextCache;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;
use MP\CommercePromotions\Engine\PromotionPlanExplainer;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionForecastEngine;
use MP\CommercePromotions\Service\PromotionRecommendationEngine;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Service\SimulationScenario;
use PHPUnit\Framework\TestCase;

final class PromotionSimulationForecastingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		PlannerContextCache::reset_request_cache();
	}

	public function test_simulation_scenario_presets_validate(): void {
		foreach (
			array(
				SimulationScenario::PRESET_WHOLE_CART,
				SimulationScenario::PRESET_VIP_CUSTOMER,
				SimulationScenario::PRESET_COOLDOWN_ACTIVE,
				SimulationScenario::PRESET_HIGH_QUANTITY,
			) as $preset
		) {
			$scenario = SimulationScenario::from_preset( $preset );
			$this->assertTrue( $scenario->validate() );
			$this->assertNotSame( array(), $scenario->to_array() );
		}
	}

	public function test_scenario_round_trip_array(): void {
		$original = SimulationScenario::from_preset( SimulationScenario::PRESET_CATEGORY_CART );
		$restored = SimulationScenario::from_array( $original->to_array() );
		$this->assertSame( $original->to_array(), $restored->to_array() );
	}

	public function test_planner_cache_counters_increment(): void {
		PlannerContextCache::reset_request_cache();
		PlannerContextCache::record_simulated_run();
		$stats = PlannerContextCache::request_counters();
		$this->assertSame( 1, $stats['simulated_runs'] );
	}

	public function test_forecast_engine_reset_cache(): void {
		$GLOBALS['mp_cp_test_options'][ PromotionForecastEngine::OPTION_CACHE ] = array( 'generated_at' => '2020-01-01 00:00:00' );
		PromotionForecastEngine::reset_cache();
		$this->assertArrayNotHasKey( PromotionForecastEngine::OPTION_CACHE, $GLOBALS['mp_cp_test_options'] );
	}

	public function test_intelligence_validator_warnings(): void {
		$promotion = Promotion::from_array(
			array(
				'id'               => 10,
				'uuid'             => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
				'name'             => 'Intel',
				'status'           => PromotionStatus::ACTIVE,
				'starts_at'        => '2026-01-01 00:00:00',
				'ends_at'          => '2026-01-02 00:00:00',
				'cooldown_hours'   => 72,
				'application_mode' => 'stackable',
				'actions'          => array(
					array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ),
				),
			)
		);

		$validator = new PromotionRuleValidator();
		$issues    = $validator->validate( $promotion );
		$messages  = array_column( $issues, 'message' );
		$joined    = implode( ' ', $messages );
		$this->assertStringContainsString( 'Cooldown duration exceeds', $joined );
		$this->assertStringContainsString( 'free shipping', strtolower( $joined ) );
	}

	public function test_explainability_enrichment_structure(): void {
		$plan = new PromotionEvaluationPlan( array() );
		$ctx  = new EvaluationContext(
			null,
			125.0,
			'USD',
			array(
				array( 'product_id' => 1, 'quantity' => 1, 'line_subtotal' => 50.0 ),
			),
			array()
		);
		$base = PromotionPlanExplainer::explain( $plan );
		$rich = PromotionPlanExplainer::enrich_explanation( $base, $plan, $ctx );
		$this->assertArrayHasKey( 'estimated_savings', $rich );
		$this->assertArrayHasKey( 'overlap_warnings', $rich );
		$this->assertArrayHasKey( 'why_lost_summaries', $rich );
	}

	public function test_recommendation_engine_severity_constants(): void {
		$this->assertSame( 'info', PromotionRecommendationEngine::SEVERITY_INFO );
		$this->assertSame( 'critical', PromotionRecommendationEngine::SEVERITY_CRITICAL );
	}
}
