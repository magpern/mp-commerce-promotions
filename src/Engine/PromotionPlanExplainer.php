<?php
/**
 * Human-readable summaries for promotion evaluation plans (admin/debug).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class PromotionPlanExplainer {

	/**
	 * @return array{
	 *     selected: list<array<string, mixed>>,
	 *     skipped: list<array<string, mixed>>,
	 *     summary_lines: list<string>,
	 *     stop_processing: list<string>,
	 *     exclusions: list<string>,
	 *     max_applications: list<string>
	 * }
	 */
	public static function explain( PromotionEvaluationPlan $plan ): array {
		$selected = self::summarize_selected( $plan );
		$skipped  = self::summarize_skipped( $plan );

		$stop_processing   = array();
		$exclusions        = array();
		$max_applications  = array();
		$summary_lines     = array();

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
		}

		return array(
			'selected'          => $selected,
			'skipped'           => $skipped,
			'summary_lines'     => $summary_lines,
			'stop_processing'   => $stop_processing,
			'exclusions'        => $exclusions,
			'max_applications'  => $max_applications,
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

	private function __construct() {
	}
}
