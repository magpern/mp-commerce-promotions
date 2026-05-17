<?php
/**
 * Replays historical redemption context against current planner rules (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionPlanner;

final class PromotionReplayEngine {

	private PromotionRepository $promotions;

	private RedemptionRepository $redemptions;

	private PlannerTelemetryRepository $telemetry;

	private PromotionSimulationEngine $simulator;

	public function __construct(
		PromotionRepository $promotions,
		RedemptionRepository $redemptions,
		PlannerTelemetryRepository $telemetry,
		?PromotionSimulationEngine $simulator = null
	) {
		$this->promotions  = $promotions;
		$this->redemptions = $redemptions;
		$this->telemetry   = $telemetry;
		$this->simulator   = $simulator ?? new PromotionSimulationEngine( $promotions );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function replay_catalog( int $limit = 50 ): array {
		$active = $this->promotions->find_active( $limit );
		$rows   = array();

		foreach ( $active as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}
			$rows[] = $this->replay_promotion( $id );
		}

		return array(
			'replayed_at' => current_time( 'mysql' ),
			'promotions'  => $rows,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function replay_promotion( int $promotion_id ): array {
		$promotion = $this->promotions->find( $promotion_id );
		if ( $promotion === null ) {
			return array( 'promotion_id' => $promotion_id, 'error' => 'not_found' );
		}

		$historical_count = $this->redemptions->count_recorded_for_promotion( $promotion_id );
		$scenario         = SimulationScenario::from_array(
			array(
				'preset'        => SimulationScenario::PRESET_WHOLE_CART,
				'promotion_ids' => array( $promotion_id ),
				'metadata'      => array(
					'replay_historical_redemptions' => $historical_count,
				),
			)
		);

		$today = $this->simulator->simulate( $scenario, array( $promotion_id ) );

		$would_select_today = count( $today->to_array()['selected_promotions'] ) > 0;
		$changed_winner     = $historical_count > 0 && ! $would_select_today;

		return array(
			'promotion_id'              => $promotion_id,
			'historical_redemptions'    => $historical_count,
			'would_select_today'        => $would_select_today,
			'changed_winner'            => $changed_winner,
			'estimated_discount_today'  => $today->get_total_discount(),
			'newly_blocked'             => $changed_winner,
			'estimated_budget_delta'    => $today->get_total_discount(),
			'stackability_changed'      => false,
			'simulation'                => $today->to_array(),
		);
	}
}
