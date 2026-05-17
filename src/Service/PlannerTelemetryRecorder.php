<?php
/**
 * Records aggregate planner outcomes into telemetry storage (no PII).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;

final class PlannerTelemetryRecorder {

	private PlannerTelemetryRepository $telemetry;

	private ?PromotionPerformanceProfiler $profiler;

	public function __construct(
		PlannerTelemetryRepository $telemetry,
		?PromotionPerformanceProfiler $profiler = null
	) {
		$this->telemetry = $telemetry;
		$this->profiler  = $profiler;
	}

	public function record_plan( PromotionEvaluationPlan $plan ): void {
		$started = microtime( true );
		foreach ( $plan->get_decisions() as $decision ) {
			$promotion_id = $decision->get_promotion_id();
			if ( $promotion_id === null || $promotion_id <= 0 ) {
				continue;
			}

			$deltas = array(
				'selected'             => 0,
				'skipped'              => 0,
				'blocked_by_group'     => 0,
				'blocked_by_cooldown'  => 0,
				'blocked_by_budget'    => 0,
				'blocked_by_exclusion' => 0,
			);

			if ( $decision->is_selected() ) {
				$deltas['selected'] = 1;
			} else {
				$deltas['skipped'] = 1;
				$reason            = $decision->get_skipped_reason() ?? '';
				if ( $reason === PromotionEvaluationDecision::REASON_ORCHESTRATION_GROUP_BLOCKED ) {
					$deltas['blocked_by_group'] = 1;
				} elseif ( $reason === PromotionEvaluationDecision::REASON_BLOCKED_BY_COOLDOWN ) {
					$deltas['blocked_by_cooldown'] = 1;
				} elseif ( $reason === PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED ) {
					$deltas['blocked_by_exclusion'] = 1;
				} elseif ( $reason === ConditionTrace::REASON_PROMOTION_BUDGET_EXHAUSTED
					|| $reason === 'promotion_budget_exhausted' ) {
					$deltas['blocked_by_budget'] = 1;
				}
			}

			$this->telemetry->increment( $promotion_id, $deltas );
		}

		if ( $this->profiler !== null ) {
			$this->profiler->record_telemetry_write( ( microtime( true ) - $started ) * 1000 );
		}
	}
}
