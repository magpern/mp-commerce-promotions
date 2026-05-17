<?php
/**
 * Merchant decision-support recommendations (heuristic, read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\PromotionDateHelper;

final class PromotionRecommendationEngine {

	public const SEVERITY_INFO = 'info';

	public const SEVERITY_WARNING = 'warning';

	public const SEVERITY_CRITICAL = 'critical';

	private PromotionRepository $promotions;

	private RedemptionRepository $redemptions;

	private PlannerTelemetryRepository $telemetry;

	private PromotionConflictAnalyzer $conflicts;

	private PromotionHealthMonitor $health;

	public function __construct(
		PromotionRepository $promotions,
		RedemptionRepository $redemptions,
		PlannerTelemetryRepository $telemetry,
		?PromotionConflictAnalyzer $conflicts = null,
		?PromotionHealthMonitor $health = null
	) {
		$this->promotions  = $promotions;
		$this->redemptions = $redemptions;
		$this->telemetry   = $telemetry;
		$this->conflicts   = $conflicts ?? new PromotionConflictAnalyzer();
		$this->health      = $health ?? new PromotionHealthMonitor( $promotions, $this->conflicts );
	}

	/**
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	public function recommend( int $limit = 500 ): array {
		$promotions = $this->promotions->find_filtered( array( 'limit' => min( 500, max( 1, $limit ) ) ) );
		$issues     = $this->health->analyze( $limit );

		$issues = array_merge( $issues, $this->detect_missing_end_dates( $promotions ) );
		$issues = array_merge( $issues, $this->detect_zero_redemptions( $promotions ) );
		$issues = array_merge( $issues, $this->detect_unused_telemetry( $promotions ) );
		$issues = array_merge( $issues, $this->detect_excessive_cooldowns( $promotions ) );
		$issues = array_merge( $issues, $this->map_conflicts_to_recommendations( $promotions ) );

		return $issues;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_missing_end_dates( array $promotions ): array {
		$issues = array();
		foreach ( $promotions as $promotion ) {
			if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
				continue;
			}
			if ( $promotion->get_ends_at() === null || trim( (string) $promotion->get_ends_at() ) === '' ) {
				$id = $promotion->get_id();
				if ( $id === null ) {
					continue;
				}
				$issues[] = $this->issue(
					self::SEVERITY_WARNING,
					'missing_end_date',
					array( $id ),
					sprintf(
						__( 'Promotion %d is active without an end date.', 'mp-commerce-promotions' ),
						$id
					)
				);
			}
		}
		return $issues;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_zero_redemptions( array $promotions ): array {
		$issues = array();
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $promotion->get_status() !== PromotionStatus::ACTIVE ) {
				continue;
			}
			if ( $this->redemptions->count_recorded_for_promotion( $id ) === 0 ) {
				$issues[] = $this->issue(
					self::SEVERITY_INFO,
					'zero_redemptions',
					array( $id ),
					sprintf(
						__( 'Promotion %d has no recorded redemptions yet.', 'mp-commerce-promotions' ),
						$id
					)
				);
			}
		}
		return $issues;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_unused_telemetry( array $promotions ): array {
		$issues = array();
		$top    = $this->telemetry->top_by_column( 'selected_count', 100 );
		$seen   = array();
		foreach ( $top as $row ) {
			$seen[ (int) ( $row['promotion_id'] ?? 0 ) ] = true;
		}

		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $promotion->get_status() !== PromotionStatus::ACTIVE ) {
				continue;
			}
			if ( ! isset( $seen[ $id ] ) ) {
				$issues[] = $this->issue(
					self::SEVERITY_INFO,
					'unused_promotion',
					array( $id ),
					sprintf(
						__( 'Promotion %d has no planner telemetry selections yet.', 'mp-commerce-promotions' ),
						$id
					)
				);
			}
		}
		return $issues;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_excessive_cooldowns( array $promotions ): array {
		$issues = array();
		foreach ( $promotions as $promotion ) {
			$cooldown = $promotion->get_cooldown_hours();
			if ( $cooldown === null || $cooldown < 168 ) {
				continue;
			}
			$id = $promotion->get_id();
			if ( $id === null ) {
				continue;
			}
			$issues[] = $this->issue(
				self::SEVERITY_WARNING,
				'excessive_cooldown',
				array( $id ),
				sprintf(
					__( 'Promotion %d cooldown (%d hours) may be excessive.', 'mp-commerce-promotions' ),
					$id,
					$cooldown
				)
			);
		}
		return $issues;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	private function map_conflicts_to_recommendations( array $promotions ): array {
		$active = array_values(
			array_filter(
				$promotions,
				static fn ( Promotion $p ): bool => $p->get_status() === PromotionStatus::ACTIVE
			)
		);

		$issues = array();
		foreach ( $this->conflicts->analyze( $active ) as $conflict ) {
			$severity = isset( $conflict['severity'] ) ? (string) $conflict['severity'] : self::SEVERITY_INFO;
			$issues[] = array(
				'severity'       => $severity === 'warning' ? self::SEVERITY_WARNING : self::SEVERITY_INFO,
				'code'           => 'campaign_overlap_' . (string) ( $conflict['type'] ?? 'unknown' ),
				'promotion_ids'  => isset( $conflict['promotion_ids'] ) && is_array( $conflict['promotion_ids'] )
					? array_map( 'intval', $conflict['promotion_ids'] )
					: array(),
				'message'        => (string) ( $conflict['message'] ?? '' ),
			);
		}

		return $issues;
	}

	/**
	 * @param list<int> $promotion_ids
	 * @return array{severity: string, code: string, promotion_ids: list<int>, message: string}
	 */
	private function issue( string $severity, string $code, array $promotion_ids, string $message ): array {
		return array(
			'severity'      => $severity,
			'code'          => $code,
			'promotion_ids' => $promotion_ids,
			'message'       => $message,
		);
	}
}
