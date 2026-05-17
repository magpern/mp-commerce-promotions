<?php
/**
 * Campaign overlap simulation using conflict and schedule analyzers.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;

final class PromotionOverlapSimulator {

	private PromotionRepository $promotions;

	private PromotionConflictAnalyzer $conflicts;

	private PromotionScheduleAnalyzer $schedule;

	public function __construct(
		PromotionRepository $promotions,
		?PromotionConflictAnalyzer $conflicts = null,
		?PromotionScheduleAnalyzer $schedule = null
	) {
		$this->promotions = $promotions;
		$this->conflicts  = $conflicts ?? new PromotionConflictAnalyzer();
		$this->schedule   = $schedule ?? new PromotionScheduleAnalyzer();
	}

	/**
	 * @return list<array{severity: string, type: string, promotion_ids: list<int>, message: string, estimated_impact: string}>
	 */
	public function simulate_overlap( ?array $promotion_ids = null ): array {
		$promotions = $this->load_promotions( $promotion_ids );
		$active     = array_values(
			array_filter(
				$promotions,
				static fn ( Promotion $p ): bool => in_array(
					$p->get_status(),
					array( PromotionStatus::ACTIVE, PromotionStatus::DRAFT, PromotionStatus::PAUSED ),
					true
				)
			)
		);

		$results = array();
		foreach ( $this->conflicts->simulate_overlap( $active ) as $row ) {
			$results[] = array(
				'severity'         => (string) ( $row['severity'] ?? 'info' ),
				'type'             => (string) ( $row['type'] ?? '' ),
				'promotion_ids'    => isset( $row['promotion_ids'] ) && is_array( $row['promotion_ids'] )
					? array_map( 'intval', $row['promotion_ids'] )
					: array(),
				'message'          => (string) ( $row['message'] ?? '' ),
				'estimated_impact' => $this->estimate_impact( $row ),
			);
		}

		foreach ( $active as $subject ) {
			$schedule_rows = $this->schedule->analyze( $active, $subject );
			foreach ( $schedule_rows as $schedule_row ) {
				$results[] = array(
					'severity'         => (string) ( $schedule_row['severity'] ?? 'info' ),
					'type'             => 'schedule_overlap',
					'promotion_ids'    => array( (int) ( $subject->get_id() ?? 0 ) ),
					'message'          => (string) ( $schedule_row['message'] ?? '' ),
					'estimated_impact' => 'scheduled_window_overlap',
				);
			}
		}

		return $results;
	}

	/**
	 * @param array<string, mixed> $conflict
	 */
	private function estimate_impact( array $conflict ): string {
		$type = isset( $conflict['type'] ) ? (string) $conflict['type'] : '';
		if ( $type === PromotionConflictAnalyzer::TYPE_FREE_SHIPPING_OVERLAP ) {
			return 'stackable_shipping_collision';
		}
		if ( $type === PromotionConflictAnalyzer::TYPE_ORCHESTRATION_CONGESTION ) {
			return 'orchestration_lane_contention';
		}
		if ( $type === PromotionConflictAnalyzer::TYPE_SCOPE_OVERLAP ) {
			return 'category_or_product_overlap';
		}

		return 'general_overlap';
	}

	/**
	 * @return list<Promotion>
	 */
	private function load_promotions( ?array $ids ): array {
		if ( $ids !== null && $ids !== array() ) {
			$out = array();
			foreach ( $ids as $id ) {
				$p = $this->promotions->find( (int) $id );
				if ( $p instanceof Promotion ) {
					$out[] = $p;
				}
			}
			return $out;
		}

		return $this->promotions->find_filtered( array( 'limit' => 100 ) );
	}
}
