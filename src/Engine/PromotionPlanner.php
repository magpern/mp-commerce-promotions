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
				$decisions[] = new PromotionEvaluationDecision(
					$promotion,
					$result,
					false,
					PromotionEvaluationDecision::REASON_NOT_ELIGIBLE
				);
				continue;
			}

			$decisions[] = new PromotionEvaluationDecision(
				$promotion,
				$result,
				true,
				null
			);

			$is_exclusive = $promotion->get_application_mode() === PromotionApplicationMode::EXCLUSIVE;
			if ( $is_exclusive ) {
				$exclusive_was_selected = true;
			}

			if ( $is_exclusive || $promotion->should_stop_processing() ) {
				$stop_further_selection = true;
			}
		}

		return new PromotionEvaluationPlan( $decisions );
	}

	private function build_skipped_decision( Promotion $promotion, string $reason ): PromotionEvaluationDecision {
		$messages = array(
			PromotionEvaluationDecision::REASON_BLOCKED_EXCLUSIVE => 'Skipped: blocked by an exclusive promotion already selected in this plan.',
			PromotionEvaluationDecision::REASON_STOPPED_PROCESSING => 'Skipped: processing stopped after a prior selected promotion.',
		);

		$message = $messages[ $reason ] ?? 'Skipped by promotion application plan.';

		return new PromotionEvaluationDecision(
			$promotion,
			EvaluationResult::ineligible( array( $message ) ),
			false,
			$reason
		);
	}
}
