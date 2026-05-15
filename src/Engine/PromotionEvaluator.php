<?php
/**
 * Non-persistent promotion evaluation (demo condition/action types only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Action\ActionInterface;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\ConditionInterface;
use MP\CommercePromotions\Engine\Condition\MinimumSubtotalCondition;

final class PromotionEvaluator {

	public function evaluate( Promotion $promotion, EvaluationContext $context ): EvaluationResult {
		if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
			return EvaluationResult::ineligible(
				array( 'Promotion is not active.' )
			);
		}

		$conditions = $promotion->get_conditions();
		if ( ! is_array( $conditions ) ) {
			return EvaluationResult::ineligible( array( 'Promotion conditions must be an array.' ) );
		}

		foreach ( $conditions as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				return EvaluationResult::ineligible(
					array( sprintf( 'Condition at index %s must be an object.', (string) $index ) )
				);
			}

			$type = isset( $raw['type'] ) ? (string) $raw['type'] : '';

			$cond = $this->resolve_condition( $type, $raw );
			if ( $cond instanceof EvaluationResult ) {
				return $cond;
			}
			if ( ! $cond instanceof ConditionInterface ) {
				return EvaluationResult::ineligible(
					array( sprintf( 'Unknown condition type: %s', $type !== '' ? $type : '(empty)' ) )
				);
			}

			$result = $cond->evaluate( $context );
			if ( ! $result->passed() ) {
				$msg = $result->get_message();
				return EvaluationResult::ineligible(
					array( $msg !== null && $msg !== '' ? $msg : 'A condition did not pass.' )
				);
			}
		}

		$actions = $promotion->get_actions();
		if ( ! is_array( $actions ) ) {
			return EvaluationResult::ineligible( array( 'Promotion actions must be an array.' ) );
		}

		$action_previews = array();

		foreach ( $actions as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				return EvaluationResult::ineligible(
					array( sprintf( 'Action at index %s must be an object.', (string) $index ) )
				);
			}

			$type = isset( $raw['type'] ) ? (string) $raw['type'] : '';

			$action = $this->resolve_action( $type, $raw );
			if ( $action instanceof EvaluationResult ) {
				return $action;
			}
			if ( ! $action instanceof ActionInterface ) {
				return EvaluationResult::ineligible(
					array( sprintf( 'Unknown action type: %s', $type !== '' ? $type : '(empty)' ) )
				);
			}

			$preview           = $action->preview( $context );
			$action_previews[] = $preview->to_array();
		}

		return EvaluationResult::eligible( $action_previews, array() );
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return ConditionInterface|EvaluationResult|null
	 */
	private function resolve_condition( string $type, array $raw ) {
		if ( $type === 'minimum_subtotal' ) {
			if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
				return EvaluationResult::ineligible(
					array( 'Invalid minimum_subtotal condition configuration.' )
				);
			}
			try {
				return new MinimumSubtotalCondition( (float) $raw['amount'] );
			} catch ( \InvalidArgumentException $e ) {
				return EvaluationResult::ineligible(
					array( 'Invalid minimum_subtotal condition configuration.' )
				);
			}
		}

		if ( $type === '' ) {
			return null;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return ActionInterface|EvaluationResult|null
	 */
	private function resolve_action( string $type, array $raw ) {
		if ( $type === 'percentage_discount' ) {
			if ( ! isset( $raw['percentage'] ) || ! is_numeric( $raw['percentage'] ) ) {
				return EvaluationResult::ineligible(
					array( 'Invalid percentage_discount action configuration.' )
				);
			}
			try {
				return new PercentageDiscountAction( (float) $raw['percentage'] );
			} catch ( \InvalidArgumentException $e ) {
				return EvaluationResult::ineligible(
					array( 'Invalid percentage_discount action configuration.' )
				);
			}
		}

		if ( $type === '' ) {
			return null;
		}

		return null;
	}
}
