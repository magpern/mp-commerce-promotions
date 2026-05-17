<?php
/**
 * Human-readable summaries for promotion evaluation plans (admin/debug).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

use MP\CommercePromotions\Engine\RuleTypes;

final class PromotionPlanExplainer {

	/**
	 * @return array{
	 *     selected: list<array<string, mixed>>,
	 *     skipped: list<array<string, mixed>>,
	 *     summary_lines: list<string>,
	 *     stop_processing: list<string>,
	 *     exclusions: list<string>,
	 *     max_applications: list<string>,
	 *     orchestration_group_blocked: list<string>,
	 *     blocked_by_cooldown: list<string>,
	 *     plan_metrics: array<string, mixed>
	 * }
	 */
	public static function explain( PromotionEvaluationPlan $plan ): array {
		$selected = self::summarize_selected( $plan );
		$skipped  = self::summarize_skipped( $plan );

		$stop_processing              = array();
		$exclusions                   = array();
		$max_applications             = array();
		$orchestration_group_blocked  = array();
		$blocked_by_cooldown          = array();
		$summary_lines                = array();

		foreach ( $selected as $row ) {
			$summary_lines[] = (string) $row['summary'];
		}

		foreach ( $skipped as $row ) {
			$summary_lines[] = (string) $row['summary'];
			$reason          = isset( $row['reason_code'] ) ? (string) $row['reason_code'] : '';

			if ( $reason === PromotionEvaluationDecision::REASON_STOPPED_PROCESSING
				|| $reason === PromotionEvaluationDecision::REASON_BLOCKED_EXCLUSIVE ) {
				$stop_processing[] = (string) $row['summary'];
			}

			if ( $reason === PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED ) {
				$exclusions[] = (string) $row['summary'];
				if ( isset( $row['excluded_by_promotion_id'] ) && $row['excluded_by_promotion_id'] !== null ) {
					$exclusions[] = sprintf(
						/* translators: 1: skipped promotion id, 2: excluding promotion id */
						__( 'Promotion %1$d is listed in excluded_promotion_ids of promotion %2$d.', 'mp-commerce-promotions' ),
						(int) $row['promotion_id'],
						(int) $row['excluded_by_promotion_id']
					);
				}
			}

			if ( $reason === PromotionEvaluationDecision::REASON_MAX_APPLICATIONS_REACHED ) {
				$max_applications[] = (string) $row['summary'];
			}

			if ( $reason === PromotionEvaluationDecision::REASON_ORCHESTRATION_GROUP_BLOCKED ) {
				$orchestration_group_blocked[] = (string) $row['summary'];
			}

			if ( $reason === PromotionEvaluationDecision::REASON_BLOCKED_BY_COOLDOWN ) {
				$blocked_by_cooldown[] = (string) $row['summary'];
			}
		}

		$plan_metrics = $plan->get_metrics();
		if ( $plan_metrics !== array() ) {
			$summary_lines[] = self::format_plan_metrics_summary( $plan_metrics );
		}

		return array(
			'selected'                     => $selected,
			'skipped'                      => $skipped,
			'summary_lines'                => $summary_lines,
			'stop_processing'              => $stop_processing,
			'exclusions'                   => $exclusions,
			'max_applications'             => $max_applications,
			'orchestration_group_blocked'  => $orchestration_group_blocked,
			'blocked_by_cooldown'          => $blocked_by_cooldown,
			'plan_metrics'                 => $plan_metrics,
		);
	}

	/**
	 * @param array<string, mixed> $metrics
	 */
	private static function format_plan_metrics_summary( array $metrics ): string {
		$selected = isset( $metrics['selected_count'] ) ? (int) $metrics['selected_count'] : 0;
		$skipped  = isset( $metrics['skipped_count'] ) ? (int) $metrics['skipped_count'] : 0;
		$group    = isset( $metrics['blocked_by_group_count'] ) ? (int) $metrics['blocked_by_group_count'] : 0;
		$cooldown = isset( $metrics['blocked_by_cooldown_count'] ) ? (int) $metrics['blocked_by_cooldown_count'] : 0;

		return sprintf(
			/* translators: 1: selected count, 2: skipped count, 3: orchestration blocks, 4: cooldown blocks */
			__( 'Plan metrics: %1$d selected, %2$d skipped (%3$d orchestration group, %4$d cooldown).', 'mp-commerce-promotions' ),
			$selected,
			$skipped,
			$group,
			$cooldown
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function summarize_selected( PromotionEvaluationPlan $plan ): array {
		$rows = array();

		foreach ( $plan->get_selected_decisions() as $decision ) {
			$promotion = $decision->get_promotion();
			$pid       = $decision->get_promotion_id();
			$label     = self::promotion_label( $decision->get_promotion_name(), $pid );

			$mode = $promotion->get_application_mode();
			$summary = sprintf(
				/* translators: 1: promotion label, 2: application mode */
				__( 'Promotion %1$s selected because eligible (%2$s).', 'mp-commerce-promotions' ),
				$label,
				$mode
			);

			if ( $promotion->should_stop_processing() ) {
				$summary .= ' ' . __( 'Stop processing is enabled; later promotions in plan order may be skipped.', 'mp-commerce-promotions' );
			}

			$excluded = $promotion->get_excluded_promotion_ids();
			if ( $excluded !== array() ) {
				$summary .= ' ' . sprintf(
					/* translators: %s: comma-separated promotion IDs */
					__( 'Excludes promotion IDs: %s.', 'mp-commerce-promotions' ),
					implode( ', ', array_map( 'strval', $excluded ) )
				);
			}

			$rows[] = array(
				'promotion_id'   => $pid,
				'promotion_name' => $decision->get_promotion_name(),
				'reason_code'    => 'selected',
				'summary'        => $summary,
			);
		}

		return $rows;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function summarize_skipped( PromotionEvaluationPlan $plan ): array {
		$selected_by_id = array();
		foreach ( $plan->get_selected_decisions() as $selected ) {
			$sid = $selected->get_promotion_id();
			if ( $sid !== null && $sid > 0 ) {
				$selected_by_id[ $sid ] = $selected;
			}
		}

		$rows = array();

		foreach ( $plan->get_decisions() as $decision ) {
			if ( $decision->is_selected() ) {
				continue;
			}

			$pid    = $decision->get_promotion_id();
			$label  = self::promotion_label( $decision->get_promotion_name(), $pid );
			$reason = $decision->get_skipped_reason() ?? PromotionEvaluationDecision::REASON_NOT_ELIGIBLE;

			$row = array(
				'promotion_id'   => $pid,
				'promotion_name' => $decision->get_promotion_name(),
				'reason_code'    => $reason,
				'eligible'       => $decision->get_result()->is_eligible(),
			);

			if ( $reason === PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED ) {
				$excluder_id = self::find_excluding_promotion_id( $pid, $selected_by_id );
				$row['excluded_by_promotion_id'] = $excluder_id;
				$row['summary']                  = $excluder_id !== null
					? sprintf(
						/* translators: 1: skipped promotion label, 2: excluding promotion id */
						__( 'Promotion %1$s skipped because excluded by promotion %2$d.', 'mp-commerce-promotions' ),
						$label,
						$excluder_id
					)
					: sprintf(
						/* translators: %s: promotion label */
						__( 'Promotion %s skipped because excluded by an earlier selected promotion.', 'mp-commerce-promotions' ),
						$label
					);
			} elseif ( $reason === PromotionEvaluationDecision::REASON_MAX_APPLICATIONS_REACHED ) {
				$meta = $decision->get_metadata();
				$limit = isset( $meta['max_applications_limit'] ) ? (int) $meta['max_applications_limit'] : 0;
				$row['summary'] = sprintf(
					/* translators: 1: promotion label, 2: max applications limit */
					__( 'Promotion %1$s skipped because max applications limit %2$d was reached.', 'mp-commerce-promotions' ),
					$label,
					$limit
				);
			} elseif ( $reason === PromotionEvaluationDecision::REASON_BLOCKED_EXCLUSIVE ) {
				$row['summary'] = sprintf(
					/* translators: %s: promotion label */
					__( 'Promotion %s skipped because an exclusive promotion was selected earlier in the plan.', 'mp-commerce-promotions' ),
					$label
				);
			} elseif ( $reason === PromotionEvaluationDecision::REASON_STOPPED_PROCESSING ) {
				$row['summary'] = sprintf(
					/* translators: %s: promotion label */
					__( 'Promotion %s skipped because a selected promotion has stop processing enabled.', 'mp-commerce-promotions' ),
					$label
				);
			} elseif ( $reason === PromotionEvaluationDecision::REASON_ORCHESTRATION_GROUP_BLOCKED ) {
				$meta = $decision->get_metadata();
				$group = isset( $meta['orchestration_group'] ) ? (string) $meta['orchestration_group'] : '';
				$winner = isset( $meta['winning_promotion_id'] ) ? (int) $meta['winning_promotion_id'] : 0;
				$row['orchestration_group'] = $group !== '' ? $group : null;
				$row['winning_promotion_id'] = $winner > 0 ? $winner : null;
				$row['summary'] = $winner > 0 && $group !== ''
					? sprintf(
						/* translators: 1: skipped promotion label, 2: orchestration group, 3: winning promotion id */
						__( 'Promotion %1$s skipped because orchestration group "%2$s" already selected promotion %3$d.', 'mp-commerce-promotions' ),
						$label,
						$group,
						$winner
					)
					: sprintf(
						/* translators: %s: promotion label */
						__( 'Promotion %s skipped because another promotion in the same orchestration group was already selected.', 'mp-commerce-promotions' ),
						$label
					);
			} elseif ( $reason === PromotionEvaluationDecision::REASON_BLOCKED_BY_COOLDOWN ) {
				$row['summary'] = sprintf(
					/* translators: %s: promotion label */
					__( 'Promotion %s skipped because promotion cooldown is active for this customer.', 'mp-commerce-promotions' ),
					$label
				);
			} else {
				$trace_reason = self::first_failed_condition_reason( $decision->get_result() );
				$row['summary'] = sprintf(
					/* translators: 1: promotion label, 2: reason code */
					__( 'Promotion %1$s skipped because not eligible (%2$s).', 'mp-commerce-promotions' ),
					$label,
					$trace_reason ?? $reason
				);
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * @param array<int, PromotionEvaluationDecision> $selected_by_id
	 */
	private static function find_excluding_promotion_id( ?int $skipped_id, array $selected_by_id ): ?int {
		if ( $skipped_id === null || $skipped_id <= 0 ) {
			return null;
		}

		foreach ( $selected_by_id as $selected_id => $decision ) {
			$excluded = $decision->get_promotion()->get_excluded_promotion_ids();
			if ( in_array( $skipped_id, $excluded, true ) ) {
				return $selected_id;
			}
		}

		return null;
	}

	private static function promotion_label( string $name, ?int $id ): string {
		if ( $id !== null && $id > 0 ) {
			return sprintf( '%s (#%d)', $name, $id );
		}

		return $name;
	}

	private static function first_failed_condition_reason( EvaluationResult $result ): ?string {
		foreach ( $result->get_condition_traces() as $trace ) {
			if ( ! is_array( $trace ) || ! empty( $trace['passed'] ) ) {
				continue;
			}
			$code = isset( $trace['reason_code'] ) ? trim( (string) $trace['reason_code'] ) : '';
			if ( $code !== '' ) {
				return $code;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $explained
	 * @return array<string, mixed>
	 */
	public static function enrich_explanation(
		array $explained,
		PromotionEvaluationPlan $plan,
		EvaluationContext $context
	): array {
		$subtotal = $context->get_cart_subtotal() ?? 0.0;
		$selected = $plan->get_selected_decisions();
		$estimated_savings = 0.0;

		foreach ( $selected as $decision ) {
			foreach ( $decision->get_promotion()->get_actions() as $action ) {
				if ( ! is_array( $action ) ) {
					continue;
				}
				$type = isset( $action['type'] ) ? (string) $action['type'] : '';
				if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT && isset( $action['percentage'] ) ) {
					$estimated_savings += $subtotal * ( (float) $action['percentage'] / 100 );
				} elseif ( $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT && isset( $action['amount'] ) ) {
					$estimated_savings += (float) $action['amount'];
				}
			}
		}

		$explained['estimated_savings'] = round( $estimated_savings, 2 );
		$explained['orchestration_chain'] = $explained['orchestration_group_blocked'] ?? array();
		$explained['cooldown_chain']      = $explained['blocked_by_cooldown'] ?? array();
		$explained['overlap_warnings']    = array();
		$explained['recommendation_hints'] = array();
		if ( count( $explained['orchestration_group_blocked'] ?? array() ) > 0 ) {
			$explained['recommendation_hints'][] = __(
				'Review orchestration groups: multiple promotions competed for the same lane.',
				'mp-commerce-promotions'
			);
		}
		if ( count( $explained['blocked_by_cooldown'] ?? array() ) > 0 ) {
			$explained['recommendation_hints'][] = __(
				'Cooldown blocked one or more promotions for this customer context.',
				'mp-commerce-promotions'
			);
		}
		$explained['forecast_impact_hints'] = array(
			sprintf(
				/* translators: %s: formatted subtotal */
				__( 'Simulated cart subtotal: %s', 'mp-commerce-promotions' ),
				number_format( $subtotal, 2, '.', '' )
			),
		);

		foreach ( $explained['skipped'] ?? array() as $row ) {
			if ( isset( $row['reason_code'] ) && $row['reason_code'] !== 'selected' ) {
				$explained['why_lost_summaries'][] = sprintf(
					/* translators: 1: promotion name, 2: reason */
					__( '%1$s lost: %2$s', 'mp-commerce-promotions' ),
					(string) ( $row['promotion_name'] ?? '' ),
					(string) ( $row['summary'] ?? $row['reason_code'] )
				);
			}
		}

		if ( ! isset( $explained['why_lost_summaries'] ) ) {
			$explained['why_lost_summaries'] = array();
		}

		return $explained;
	}

	private function __construct() {
	}
}
