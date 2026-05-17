<?php
/**
 * Read-only promotion configuration health checks for diagnostics.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\PromotionDateHelper;
use MP\CommercePromotions\Engine\RuleTypes;

final class PromotionHealthMonitor {

	public const SEVERITY_INFO = 'info';

	public const SEVERITY_WARNING = 'warning';

	public const SEVERITY_CRITICAL = 'critical';

	private const ORCHESTRATION_GROUP_WARN_THRESHOLD = 5;

	private PromotionRepository $promotions;

	private ?PromotionConflictAnalyzer $conflict_analyzer;

	public function __construct(
		PromotionRepository $promotions,
		?PromotionConflictAnalyzer $conflict_analyzer = null
	) {
		$this->promotions         = $promotions;
		$this->conflict_analyzer  = $conflict_analyzer ?? new PromotionConflictAnalyzer();
	}

	/**
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	public function analyze( int $limit = 500 ): array {
		$promotions = $this->promotions->find_filtered(
			array(
				'limit' => min( 500, max( 1, $limit ) ),
			)
		);

		$issues = array();
		$issues = array_merge( $issues, $this->detect_invalid_dates( $promotions ) );
		$issues = array_merge( $issues, $this->detect_impossible_budgets( $promotions ) );
		$issues = array_merge( $issues, $this->detect_orphaned_exclusions( $promotions ) );
		$issues = array_merge( $issues, $this->detect_expired_active( $promotions ) );
		$issues = array_merge( $issues, $this->detect_zero_actions( $promotions ) );
		$issues = array_merge( $issues, $this->detect_invalid_json_shapes( $promotions ) );
		$issues = array_merge( $issues, $this->detect_orchestration_congestion( $promotions ) );
		$issues = array_merge( $issues, $this->detect_free_shipping_overload( $promotions ) );

		return $issues;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_invalid_dates( array $promotions ): array {
		$issues = array();
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			$starts = $promotion->get_starts_at();
			$ends   = $promotion->get_ends_at();
			if ( $starts === null || $ends === null ) {
				continue;
			}

			$starts_ts = PromotionDateHelper::parse_mysql_datetime( $starts );
			$ends_ts   = PromotionDateHelper::parse_mysql_datetime( $ends );
			if ( $starts_ts !== null && $ends_ts !== null && $starts_ts > $ends_ts ) {
				$issues[] = $this->issue(
					self::SEVERITY_CRITICAL,
					'invalid_date_window',
					array( $id ),
					sprintf(
						/* translators: %d: promotion id */
						__( 'Promotion %d has starts_at after ends_at.', 'mp-commerce-promotions' ),
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
	private function detect_impossible_budgets( array $promotions ): array {
		$issues = array();
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			if ( ! $promotion->has_budget_cap() ) {
				continue;
			}

			if ( $promotion->get_budget_currency() === null || $promotion->get_budget_currency() === '' ) {
				$issues[] = $this->issue(
					self::SEVERITY_WARNING,
					'budget_without_currency',
					array( $id ),
					sprintf(
						__( 'Promotion %d has a budget cap without currency.', 'mp-commerce-promotions' ),
						$id
					)
				);
			}

			if ( $promotion->get_budget_spent() > (float) $promotion->get_budget_amount() * 1.5 ) {
				$issues[] = $this->issue(
					self::SEVERITY_WARNING,
					'budget_spent_anomaly',
					array( $id ),
					sprintf(
						__( 'Promotion %d budget_spent greatly exceeds budget_amount (possible drift).', 'mp-commerce-promotions' ),
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
	private function detect_orphaned_exclusions( array $promotions ): array {
		$known = array();
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id !== null && $id > 0 ) {
				$known[ $id ] = true;
			}
		}

		$issues = array();
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			$orphans = array();
			foreach ( $promotion->get_excluded_promotion_ids() as $excluded_id ) {
				if ( ! isset( $known[ $excluded_id ] ) ) {
					$orphans[] = $excluded_id;
				}
			}

			if ( $orphans !== array() ) {
				$issues[] = $this->issue(
					self::SEVERITY_WARNING,
					'orphaned_exclusion',
					array( $id ),
					sprintf(
						/* translators: 1: promotion id, 2: orphan ids */
						__( 'Promotion %1$d references missing excluded promotion IDs: %2$s.', 'mp-commerce-promotions' ),
						$id,
						implode( ', ', array_map( 'strval', $orphans ) )
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
	private function detect_expired_active( array $promotions ): array {
		$ids = array();
		$now = PromotionDateHelper::now_timestamp();

		foreach ( $promotions as $promotion ) {
			if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
				continue;
			}
			$ends_ts = PromotionDateHelper::parse_mysql_datetime( $promotion->get_ends_at() );
			if ( $ends_ts !== null && $now > $ends_ts ) {
				$id = $promotion->get_id();
				if ( $id !== null && $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		if ( $ids === array() ) {
			return array();
		}

		return array(
			$this->issue(
				self::SEVERITY_WARNING,
				'expired_active',
				$ids,
				sprintf(
					/* translators: %s: promotion ids */
					__( '%d active promotion(s) are past ends_at.', 'mp-commerce-promotions' ),
					count( $ids )
				)
			),
		);
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_zero_actions( array $promotions ): array {
		$issues = array();
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			$actions = $promotion->get_actions();
			if ( ! is_array( $actions ) || $actions === array() ) {
				if ( $promotion->get_status() === PromotionStatus::ACTIVE ) {
					$issues[] = $this->issue(
						self::SEVERITY_CRITICAL,
						'active_no_actions',
						array( $id ),
						sprintf(
							__( 'Active promotion %d has no actions configured.', 'mp-commerce-promotions' ),
							$id
						)
					);
				} else {
					$issues[] = $this->issue(
						self::SEVERITY_INFO,
						'zero_actions',
						array( $id ),
						sprintf(
							__( 'Promotion %d has no actions configured.', 'mp-commerce-promotions' ),
							$id
						)
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_invalid_json_shapes( array $promotions ): array {
		$issues = array();
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			foreach ( array( 'conditions', 'actions', 'restrictions' ) as $field ) {
				$getter = 'get_' . $field;
				if ( ! method_exists( $promotion, $getter ) ) {
					continue;
				}
				/** @var array<mixed> $value */
				$value = $promotion->$getter();
				if ( ! is_array( $value ) ) {
					$issues[] = $this->issue(
						self::SEVERITY_CRITICAL,
						'invalid_json_normalization',
						array( $id ),
						sprintf(
							/* translators: 1: promotion id, 2: field name */
							__( 'Promotion %1$d field %2$s is not a valid array.', 'mp-commerce-promotions' ),
							$id,
							$field
						)
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_orchestration_congestion( array $promotions ): array {
		$active = array();
		foreach ( $promotions as $promotion ) {
			if ( $promotion->get_status() === PromotionStatus::ACTIVE ) {
				$active[] = $promotion;
			}
		}

		$conflicts = $this->conflict_analyzer->analyze( $active );
		$issues    = array();

		foreach ( $conflicts as $conflict ) {
			if ( ( $conflict['type'] ?? '' ) !== PromotionConflictAnalyzer::TYPE_ORCHESTRATION_CONGESTION ) {
				continue;
			}
			$issues[] = $this->issue(
				self::SEVERITY_WARNING,
				'orchestration_congestion',
				$conflict['promotion_ids'] ?? array(),
				(string) ( $conflict['message'] ?? '' )
			);
		}

		/** @var array<string, list<int>> $group_map */
		$group_map = array();
		foreach ( $promotions as $promotion ) {
			$group = $promotion->get_orchestration_group();
			$id    = $promotion->get_id();
			if ( $group === null || $group === '' || $id === null || $id <= 0 ) {
				continue;
			}
			$group_map[ $group ]   = $group_map[ $group ] ?? array();
			$group_map[ $group ][] = $id;
		}

		foreach ( $group_map as $group => $ids ) {
			if ( count( $ids ) >= self::ORCHESTRATION_GROUP_WARN_THRESHOLD ) {
				$issues[] = $this->issue(
					self::SEVERITY_INFO,
					'large_orchestration_group',
					$ids,
					sprintf(
						/* translators: 1: group name, 2: count */
						__( 'Orchestration group "%1$s" has %2$d promotions.', 'mp-commerce-promotions' ),
						$group,
						count( $ids )
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
	private function detect_free_shipping_overload( array $promotions ): array {
		$ids = array();
		foreach ( $promotions as $promotion ) {
			if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
				continue;
			}
			foreach ( $promotion->get_actions() as $action ) {
				if ( is_array( $action ) && ( $action['type'] ?? '' ) === RuleTypes::ACTION_FREE_SHIPPING ) {
					$id = $promotion->get_id();
					if ( $id !== null && $id > 0 ) {
						$ids[] = $id;
					}
					break;
				}
			}
		}

		if ( count( $ids ) < 2 ) {
			return array();
		}

		return array(
			$this->issue(
				self::SEVERITY_INFO,
				'free_shipping_overload',
				$ids,
				sprintf(
					__( '%d active promotions include free shipping actions.', 'mp-commerce-promotions' ),
					count( $ids )
				)
			),
		);
	}

	/**
	 * @param list<int> $promotion_ids
	 * @return array{severity: string, code: string, promotion_ids: list<int>, message: string}
	 */
	private function issue( string $severity, string $code, array $promotion_ids, string $message ): array {
		$promotion_ids = array_values( array_unique( array_map( 'intval', $promotion_ids ) ) );
		sort( $promotion_ids, SORT_NUMERIC );

		return array(
			'severity'      => $severity,
			'code'          => $code,
			'promotion_ids' => $promotion_ids,
			'message'       => $message,
		);
	}
}
