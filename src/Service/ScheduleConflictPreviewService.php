<?php
/**
 * Lightweight schedule + conflict preview (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\PromotionDateHelper;

final class ScheduleConflictPreviewService {

	public const TYPE_BUDGET_OVERLAP_RISK = 'budget_overlap_risk';

	private PromotionScheduleAnalyzer $schedule;

	private PromotionConflictAnalyzer $conflicts;

	public function __construct(
		?PromotionScheduleAnalyzer $schedule = null,
		?PromotionConflictAnalyzer $conflicts = null
	) {
		$this->schedule  = $schedule ?? new PromotionScheduleAnalyzer();
		$this->conflicts = $conflicts ?? new PromotionConflictAnalyzer();
	}

	/**
	 * @param list<Promotion> $catalog
	 * @return list<array{source: string, severity: string, type: string, promotion_ids: list<int>, message: string}>
	 */
	public function preview_for_promotion( Promotion $subject, array $catalog ): array {
		$peers = $this->schedulable_peers( $catalog );
		if ( $peers === array() ) {
			return array();
		}

		$subject_id = $subject->get_id();
		if ( $subject_id === null || $subject_id <= 0 ) {
			return array();
		}

		$rows = array();

		foreach ( $this->schedule->analyze( $peers, $subject ) as $schedule_row ) {
			$rows[] = array(
				'source'        => 'schedule',
				'severity'      => (string) ( $schedule_row['severity'] ?? 'info' ),
				'type'          => (string) ( $schedule_row['code'] ?? 'schedule_overlap' ),
				'promotion_ids' => isset( $schedule_row['promotion_ids'] ) && is_array( $schedule_row['promotion_ids'] )
					? array_map( 'intval', $schedule_row['promotion_ids'] )
					: array(),
				'message'       => (string) ( $schedule_row['message'] ?? '' ),
			);
		}

		$active = array_values(
			array_filter(
				$peers,
				static fn ( Promotion $p ): bool => $p->get_status() === PromotionStatus::ACTIVE
			)
		);

		foreach ( $this->conflicts->analyze( $active ) as $conflict ) {
			$ids = isset( $conflict['promotion_ids'] ) && is_array( $conflict['promotion_ids'] )
				? array_map( 'intval', $conflict['promotion_ids'] )
				: array();
			if ( ! in_array( $subject_id, $ids, true ) ) {
				continue;
			}
			$rows[] = array(
				'source'        => 'conflict',
				'severity'      => (string) ( $conflict['severity'] ?? 'info' ),
				'type'          => (string) ( $conflict['type'] ?? '' ),
				'promotion_ids' => $ids,
				'message'       => (string) ( $conflict['message'] ?? '' ),
			);
		}

		foreach ( $this->budget_overlap_warnings( $subject, $peers ) as $budget_row ) {
			$rows[] = $budget_row;
		}

		return $rows;
	}

	/**
	 * @param list<Promotion> $catalog
	 * @return list<array{source: string, severity: string, type: string, promotion_ids: list<int>, message: string}>
	 */
	public function preview_site_summary( array $catalog, int $limit = 25 ): array {
		$peers = $this->schedulable_peers( $catalog );
		$rows  = array();

		foreach ( $peers as $subject ) {
			foreach ( $this->preview_for_promotion( $subject, $peers ) as $row ) {
				$rows[] = $row;
				if ( count( $rows ) >= $limit ) {
					return $rows;
				}
			}
		}

		return $rows;
	}

	/**
	 * @param list<Promotion> $catalog
	 * @return list<Promotion>
	 */
	private function schedulable_peers( array $catalog ): array {
		$out = array();
		foreach ( $catalog as $promotion ) {
			if ( ! $promotion instanceof Promotion ) {
				continue;
			}
			$status = $promotion->get_status();
			if ( ! in_array( $status, array( PromotionStatus::ACTIVE, PromotionStatus::PAUSED, PromotionStatus::DRAFT ), true ) ) {
				continue;
			}
			$out[] = $promotion;
		}

		return $out;
	}

	/**
	 * @param list<Promotion> $peers
	 * @return list<array{source: string, severity: string, type: string, promotion_ids: list<int>, message: string}>
	 */
	private function budget_overlap_warnings( Promotion $subject, array $peers ): array {
		$subject_id = $subject->get_id();
		if ( $subject_id === null || $subject->get_budget_amount() === null ) {
			return array();
		}

		$warnings = array();
		foreach ( $peers as $peer ) {
			$peer_id = $peer->get_id();
			if ( $peer_id === null || $peer_id === $subject_id || $peer->get_budget_amount() === null ) {
				continue;
			}
			if ( ! $this->windows_overlap( $subject, $peer ) ) {
				continue;
			}

			$remaining_subject = max( 0.0, (float) $subject->get_budget_amount() - $subject->get_budget_spent() );
			$remaining_peer    = max( 0.0, (float) $peer->get_budget_amount() - $peer->get_budget_spent() );
			if ( $remaining_subject <= 0.0 && $remaining_peer <= 0.0 ) {
				continue;
			}

			$warnings[] = array(
				'source'        => 'budget',
				'severity'      => 'warning',
				'type'          => self::TYPE_BUDGET_OVERLAP_RISK,
				'promotion_ids' => array( $subject_id, $peer_id ),
				'message'       => sprintf(
					/* translators: 1: subject promotion id, 2: peer promotion id */
					__(
						'Budgeted promotions %1$d and %2$d overlap in time; combined checkout volume may exhaust one or both caps during the window.',
						'mp-commerce-promotions'
					),
					$subject_id,
					$peer_id
				),
			);
		}

		return $warnings;
	}

	private function windows_overlap( Promotion $a, Promotion $b ): bool {
		$start_a = PromotionDateHelper::parse_mysql_datetime( $a->get_starts_at() );
		$end_a   = PromotionDateHelper::parse_mysql_datetime( $a->get_ends_at() );
		$start_b = PromotionDateHelper::parse_mysql_datetime( $b->get_starts_at() );
		$end_b   = PromotionDateHelper::parse_mysql_datetime( $b->get_ends_at() );

		$range_start_a = $start_a ?? PHP_INT_MIN;
		$range_end_a   = $end_a ?? PHP_INT_MAX;
		$range_start_b = $start_b ?? PHP_INT_MIN;
		$range_end_b   = $end_b ?? PHP_INT_MAX;

		return $range_start_a <= $range_end_b && $range_start_b <= $range_end_a;
	}
}
