<?php
/**
 * Plans which promotions would apply for a cart context (no fee application).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;

final class PromotionPlanner {

	private PromotionEvaluator $evaluator;

	public function __construct( ?PromotionEvaluator $evaluator = null ) {
		$this->evaluator = $evaluator ?? new PromotionEvaluator();
	}

	/**
	 * @param list<Promotion> $promotions Evaluated in given order (caller should sort by priority/id).
	 */
	public function plan( array $promotions, EvaluationContext $context ): PromotionEvaluationPlan {
		/** @var list<PromotionEvaluationDecision> $decisions */
		$decisions              = array();
		$stop_further_selection = false;
		$exclusive_was_selected = false;
		/** @var array<int, true> $active_exclusion_ids */
		$active_exclusion_ids = array();
		$selected_count            = 0;
		$plan_max_applications     = null;
		$blocked_by_group_count     = 0;
		$blocked_by_cooldown_count  = 0;
		$blocked_by_budget_count    = 0;
		$blocked_by_exclusion_count = 0;
		/** @var array<string, int> $orchestration_group_winner */
		$orchestration_group_winner = array();

		foreach ( $promotions as $promotion ) {
			if ( $stop_further_selection ) {
				$reason      = $exclusive_was_selected
					? PromotionEvaluationDecision::REASON_BLOCKED_EXCLUSIVE
					: PromotionEvaluationDecision::REASON_STOPPED_PROCESSING;
				$decisions[] = $this->build_skipped_decision( $promotion, $reason );
				continue;
			}

			$result = $this->evaluator->evaluate( $promotion, $context );
			if ( ! $result->is_eligible() ) {
				$skip_reason = $this->resolve_ineligibility_reason( $result );
				if ( $skip_reason === 'promotion_cooldown_active' ) {
					$skip_reason = PromotionEvaluationDecision::REASON_BLOCKED_BY_COOLDOWN;
					++$blocked_by_cooldown_count;
				} elseif ( $skip_reason === 'promotion_budget_exhausted' ) {
					++$blocked_by_budget_count;
				}
				$decisions[] = new PromotionEvaluationDecision(
					$promotion,
					$result,
					false,
					$skip_reason
				);
				continue;
			}

			$promotion_id = $promotion->get_id();
			$orch_group   = $promotion->get_orchestration_group();
			if ( $orch_group !== null && $orch_group !== '' ) {
				if ( isset( $orchestration_group_winner[ $orch_group ] ) ) {
					$decisions[] = $this->build_skipped_decision(
						$promotion,
						PromotionEvaluationDecision::REASON_ORCHESTRATION_GROUP_BLOCKED,
						array(
							'orchestration_group'        => $orch_group,
							'winning_promotion_id'       => $orchestration_group_winner[ $orch_group ],
						)
					);
					++$blocked_by_group_count;
					continue;
				}
			}
			if ( $promotion_id !== null && $promotion_id > 0 && isset( $active_exclusion_ids[ $promotion_id ] ) ) {
				$decisions[] = $this->build_skipped_decision(
					$promotion,
					PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED
				);
				++$blocked_by_exclusion_count;
				continue;
			}

			if ( $plan_max_applications !== null && $selected_count >= $plan_max_applications ) {
				$decisions[] = $this->build_skipped_decision(
					$promotion,
					PromotionEvaluationDecision::REASON_MAX_APPLICATIONS_REACHED,
					array(
						'max_applications_limit' => $plan_max_applications,
						'selected_count'         => $selected_count,
					)
				);
				continue;
			}

			$decisions[] = new PromotionEvaluationDecision(
				$promotion,
				$result,
				true,
				null,
				array(
					'selected_count' => $selected_count + 1,
				)
			);

			++$selected_count;

			if ( $orch_group !== null && $orch_group !== '' && $promotion_id !== null && $promotion_id > 0 ) {
				$orchestration_group_winner[ $orch_group ] = $promotion_id;
			}

			$promotion_max = $promotion->get_max_applications();
			if ( $promotion_max !== null ) {
				$plan_max_applications = $plan_max_applications === null
					? $promotion_max
					: min( $plan_max_applications, $promotion_max );
			}

			foreach ( $promotion->get_excluded_promotion_ids() as $excluded_id ) {
				$active_exclusion_ids[ $excluded_id ] = true;
			}

			$is_exclusive = $promotion->get_application_mode() === PromotionApplicationMode::EXCLUSIVE;
			if ( $is_exclusive ) {
				$exclusive_was_selected = true;
			}

			if ( $is_exclusive || $promotion->should_stop_processing() ) {
				$stop_further_selection = true;
			}
		}

		$skipped_count = 0;
		foreach ( $decisions as $decision ) {
			if ( ! $decision->is_selected() ) {
				++$skipped_count;
			}
		}

		return new PromotionEvaluationPlan(
			$decisions,
			array(
				'selected_count'            => $selected_count,
				'skipped_count'             => $skipped_count,
				'blocked_by_group_count'     => $blocked_by_group_count,
				'blocked_by_cooldown_count'  => $blocked_by_cooldown_count,
				'blocked_by_budget_count'    => $blocked_by_budget_count,
				'blocked_by_exclusion_count' => $blocked_by_exclusion_count,
			)
		);
	}

	private function resolve_ineligibility_reason( EvaluationResult $result ): string {
		foreach ( $result->get_condition_traces() as $trace ) {
			if ( ! is_array( $trace ) ) {
				continue;
			}
			if ( ! empty( $trace['passed'] ) ) {
				continue;
			}
			$reason_code = isset( $trace['reason_code'] ) ? trim( (string) $trace['reason_code'] ) : '';
			if ( $reason_code !== '' ) {
				return $reason_code;
			}
		}

		return PromotionEvaluationDecision::REASON_NOT_ELIGIBLE;
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	private function build_skipped_decision(
		Promotion $promotion,
		string $reason,
		array $metadata = array()
	): PromotionEvaluationDecision {
		$messages = array(
			PromotionEvaluationDecision::REASON_BLOCKED_EXCLUSIVE => 'Skipped: blocked by an exclusive promotion already selected in this plan.',
			PromotionEvaluationDecision::REASON_STOPPED_PROCESSING => 'Skipped: processing stopped after a prior selected promotion.',
			PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED => 'Skipped: excluded by a previously selected promotion in this plan.',
			PromotionEvaluationDecision::REASON_MAX_APPLICATIONS_REACHED => 'Skipped: plan max applications limit reached.',
			PromotionEvaluationDecision::REASON_ORCHESTRATION_GROUP_BLOCKED => 'Skipped: another promotion in the same orchestration group was already selected.',
			PromotionEvaluationDecision::REASON_BLOCKED_BY_COOLDOWN => 'Skipped: promotion cooldown is active for this customer.',
		);

		$message = $messages[ $reason ] ?? 'Skipped by promotion application plan.';

		return new PromotionEvaluationDecision(
			$promotion,
			EvaluationResult::ineligible( array( $message ) ),
			false,
			$reason,
			$metadata
		);
	}
}
