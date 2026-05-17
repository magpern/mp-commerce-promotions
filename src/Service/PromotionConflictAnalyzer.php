<?php
/**
 * Heuristic conflict analysis across active promotions (read-only, no persistence).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionPriorityTier;
use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\PromotionDateHelper;
use MP\CommercePromotions\Engine\RuleTypes;

final class PromotionConflictAnalyzer {

	public const TYPE_MUTUAL_EXCLUSION         = 'mutual_exclusion';
	public const TYPE_EXCLUSION_CONFLICT       = 'exclusion_conflict';
	public const TYPE_EXCLUSIVE_VS_STACKABLE   = 'exclusive_vs_stackable';
	public const TYPE_SCOPE_OVERLAP            = 'scope_overlap';
	public const TYPE_MAX_APPLICATION_CONFLICT = 'max_application_conflict';
	public const TYPE_FREE_SHIPPING_OVERLAP    = 'free_shipping_overlap';
	public const TYPE_GIFT_OVERLAP             = 'gift_overlap';
	public const TYPE_USAGE_LIMIT_CONFLICT     = 'usage_limit_conflict';
	public const TYPE_PRIORITY_SHADOWING       = 'priority_shadowing';

	public const TYPE_ORCHESTRATION_CONGESTION = 'orchestration_congestion';

	public const TYPE_TIER_CONGESTION = 'tier_congestion';

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	public function analyze( array $promotions ): array {
		$indexed = $this->index_promotions( $promotions );
		if ( $indexed === array() ) {
			return array();
		}

		$conflicts = array();
		$conflicts = array_merge( $conflicts, $this->detect_exclusion_conflicts( $indexed ) );
		$conflicts = array_merge( $conflicts, $this->detect_exclusive_vs_stackable( $indexed ) );
		$conflicts = array_merge( $conflicts, $this->detect_scope_overlap( $indexed ) );
		$conflicts = array_merge( $conflicts, $this->detect_max_application_conflict( $indexed ) );
		$conflicts = array_merge( $conflicts, $this->detect_free_shipping_overlap( $indexed ) );
		$conflicts = array_merge( $conflicts, $this->detect_gift_overlap( $indexed ) );
		$conflicts = array_merge( $conflicts, $this->detect_usage_limit_conflict( $indexed ) );
		$conflicts = array_merge( $conflicts, $this->detect_priority_shadowing( $indexed ) );
		$conflicts = array_merge( $conflicts, $this->detect_orchestration_congestion( $indexed ) );
		$conflicts = array_merge( $conflicts, $this->detect_tier_congestion( $indexed ) );

		return $conflicts;
	}

	/**
	 * Simulate overlap mode — same heuristics as analyze(), with overlap impact emphasis.
	 *
	 * @param list<Promotion> $promotions
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	public function simulate_overlap( array $promotions ): array {
		$conflicts = $this->analyze( $promotions );
		foreach ( $conflicts as &$conflict ) {
			if ( isset( $conflict['type'] ) && $conflict['type'] === self::TYPE_ORCHESTRATION_CONGESTION ) {
				$conflict['severity'] = 'warning';
			}
		}
		unset( $conflict );

		return $conflicts;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return array<int, Promotion>
	 */
	private function index_promotions( array $promotions ): array {
		$indexed = array();
		foreach ( $promotions as $promotion ) {
			if ( ! $promotion instanceof Promotion ) {
				continue;
			}
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}
			$indexed[ $id ] = $promotion;
		}

		return $indexed;
	}

	/**
	 * @param array<int, Promotion> $indexed
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_exclusion_conflicts( array $indexed ): array {
		$conflicts = array();
		$ids       = array_keys( $indexed );
		$pair_seen = array();

		foreach ( $ids as $id_a ) {
			foreach ( $ids as $id_b ) {
				if ( $id_a >= $id_b ) {
					continue;
				}

				$key = $id_a . ':' . $id_b;
				if ( isset( $pair_seen[ $key ] ) ) {
					continue;
				}

				$a_excludes_b = in_array( $id_b, $indexed[ $id_a ]->get_excluded_promotion_ids(), true );
				$b_excludes_a = in_array( $id_a, $indexed[ $id_b ]->get_excluded_promotion_ids(), true );

				if ( ! $a_excludes_b && ! $b_excludes_a ) {
					continue;
				}

				$pair_seen[ $key ] = true;

				if ( $a_excludes_b && $b_excludes_a ) {
					$conflicts[] = $this->row(
						self::TYPE_MUTUAL_EXCLUSION,
						'warning',
						array( $id_a, $id_b ),
						sprintf(
							/* translators: 1: promotion A id, 2: promotion B id */
							__( 'Promotion %1$d and promotion %2$d mutually exclude each other.', 'mp-commerce-promotions' ),
							$id_a,
							$id_b
						)
					);
					continue;
				}

				if ( $a_excludes_b ) {
					$conflicts[] = $this->row(
						self::TYPE_EXCLUSION_CONFLICT,
						'info',
						array( $id_a, $id_b ),
						sprintf(
							/* translators: 1: excluding promotion id, 2: excluded promotion id */
							__( 'Promotion %1$d excludes promotion %2$d.', 'mp-commerce-promotions' ),
							$id_a,
							$id_b
						)
					);
				} else {
					$conflicts[] = $this->row(
						self::TYPE_EXCLUSION_CONFLICT,
						'info',
						array( $id_b, $id_a ),
						sprintf(
							/* translators: 1: excluding promotion id, 2: excluded promotion id */
							__( 'Promotion %1$d excludes promotion %2$d.', 'mp-commerce-promotions' ),
							$id_b,
							$id_a
						)
					);
				}
			}
		}

		return $conflicts;
	}

	/**
	 * @param array<int, Promotion> $indexed
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_exclusive_vs_stackable( array $indexed ): array {
		$exclusive_ids = array();
		$stackable_ids = array();

		foreach ( $indexed as $id => $promotion ) {
			if ( $promotion->get_application_mode() === PromotionApplicationMode::EXCLUSIVE ) {
				$exclusive_ids[] = $id;
			} else {
				$stackable_ids[] = $id;
			}
		}

		if ( $exclusive_ids === array() || $stackable_ids === array() ) {
			return array();
		}

		return array(
			$this->row(
				self::TYPE_EXCLUSIVE_VS_STACKABLE,
				'warning',
				array_merge( $exclusive_ids, $stackable_ids ),
				sprintf(
					/* translators: 1: count exclusive, 2: count stackable */
					__( '%1$d exclusive and %2$d stackable active promotions overlap; exclusive selections may block stackable promotions in the same cart plan.', 'mp-commerce-promotions' ),
					count( $exclusive_ids ),
					count( $stackable_ids )
				)
			),
		);
	}

	/**
	 * @param array<int, Promotion> $indexed
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_scope_overlap( array $indexed ): array {
		/** @var array<string, list<int>> $scope_key_to_ids */
		$scope_key_to_ids = array();

		foreach ( $indexed as $id => $promotion ) {
			foreach ( $this->extract_scope_keys( $promotion ) as $scope_key ) {
				if ( ! isset( $scope_key_to_ids[ $scope_key ] ) ) {
					$scope_key_to_ids[ $scope_key ] = array();
				}
				$scope_key_to_ids[ $scope_key ][] = $id;
			}
		}

		$conflicts = array();
		foreach ( $scope_key_to_ids as $scope_key => $promotion_ids ) {
			$promotion_ids = array_values( array_unique( $promotion_ids, SORT_NUMERIC ) );
			if ( count( $promotion_ids ) < 2 ) {
				continue;
			}

			$conflicts[] = $this->row(
				self::TYPE_SCOPE_OVERLAP,
				'info',
				$promotion_ids,
				sprintf(
					/* translators: 1: scope key, 2: comma-separated promotion IDs */
					__( 'Scoped discounts overlap on %1$s (promotions %2$s); fees may stack up to cart subtotal cap.', 'mp-commerce-promotions' ),
					$scope_key,
					implode( ', ', array_map( 'strval', $promotion_ids ) )
				)
			);
		}

		return $conflicts;
	}

	/**
	 * @return list<string>
	 */
	private function extract_scope_keys( Promotion $promotion ): array {
		$keys = array();

		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}

			$type = isset( $action['type'] ) ? (string) $action['type'] : '';
			if ( $type !== RuleTypes::ACTION_PERCENTAGE_DISCOUNT && $type !== RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				continue;
			}

			$product_ids  = isset( $action['product_ids'] ) && is_array( $action['product_ids'] )
				? CartItemSelector::normalize_positive_int_list( $action['product_ids'] )
				: array();
			$category_ids = isset( $action['category_ids'] ) && is_array( $action['category_ids'] )
				? CartItemSelector::normalize_positive_int_list( $action['category_ids'] )
				: array();

			foreach ( $product_ids as $pid ) {
				$keys[] = 'product:' . $pid;
			}
			foreach ( $category_ids as $cid ) {
				$keys[] = 'category:' . $cid;
			}
		}

		foreach ( $promotion->get_conditions() as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}
			$type = isset( $condition['type'] ) ? (string) $condition['type'] : '';
			if ( $type !== RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL ) {
				continue;
			}
			$category_ids = isset( $condition['category_ids'] ) && is_array( $condition['category_ids'] )
				? CartItemSelector::normalize_positive_int_list( $condition['category_ids'] )
				: array();
			$product_ids  = isset( $condition['product_ids'] ) && is_array( $condition['product_ids'] )
				? CartItemSelector::normalize_positive_int_list( $condition['product_ids'] )
				: array();
			foreach ( $category_ids as $cid ) {
				$keys[] = 'category:' . $cid;
			}
			foreach ( $product_ids as $pid ) {
				$keys[] = 'product:' . $pid;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * @param array<int, Promotion> $indexed
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_max_application_conflict( array $indexed ): array {
		$with_cap = array();
		foreach ( $indexed as $id => $promotion ) {
			$max = $promotion->get_max_applications();
			if ( $max !== null ) {
				$with_cap[ $id ] = $max;
			}
		}

		if ( count( $with_cap ) < 2 ) {
			return array();
		}

		$ids = array_keys( $with_cap );
		$min = min( $with_cap );

		return array(
			$this->row(
				self::TYPE_MAX_APPLICATION_CONFLICT,
				'warning',
				$ids,
				sprintf(
					/* translators: 1: number of promotions, 2: effective plan cap */
					__( '%1$d promotions define max_applications; planner uses the minimum cap (%2$d) among selected promotions.', 'mp-commerce-promotions' ),
					count( $with_cap ),
					$min
				)
			),
		);
	}

	/**
	 * @param array<int, Promotion> $indexed
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_free_shipping_overlap( array $indexed ): array {
		$ids = array();
		foreach ( $indexed as $id => $promotion ) {
			if ( $this->first_action_type( $promotion ) === RuleTypes::ACTION_FREE_SHIPPING ) {
				$ids[] = $id;
			}
		}

		if ( count( $ids ) < 2 ) {
			return array();
		}

		return array(
			$this->row(
				self::TYPE_FREE_SHIPPING_OVERLAP,
				'warning',
				$ids,
				sprintf(
					/* translators: %s: comma-separated promotion IDs */
					__( 'Multiple free_shipping promotions are active (%s); only the first selected free shipping fee typically applies.', 'mp-commerce-promotions' ),
					implode( ', ', array_map( 'strval', $ids ) )
				)
			),
		);
	}

	/**
	 * @param array<int, Promotion> $indexed
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_gift_overlap( array $indexed ): array {
		/** @var array<string, list<int>> $gift_key_to_ids */
		$gift_key_to_ids = array();

		foreach ( $indexed as $id => $promotion ) {
			foreach ( $promotion->get_actions() as $action ) {
				if ( ! is_array( $action ) || ( $action['type'] ?? '' ) !== RuleTypes::ACTION_FREE_GIFT_PRODUCT ) {
					continue;
				}
				$product_id = isset( $action['product_id'] ) ? (int) $action['product_id'] : 0;
				if ( $product_id <= 0 ) {
					continue;
				}
				$variation = isset( $action['variation_id'] ) ? (int) $action['variation_id'] : 0;
				$key       = $variation > 0 ? $product_id . ':v' . $variation : (string) $product_id;
				if ( ! isset( $gift_key_to_ids[ $key ] ) ) {
					$gift_key_to_ids[ $key ] = array();
				}
				$gift_key_to_ids[ $key ][] = $id;
			}
		}

		$conflicts = array();
		foreach ( $gift_key_to_ids as $gift_key => $promotion_ids ) {
			$promotion_ids = array_values( array_unique( $promotion_ids, SORT_NUMERIC ) );
			if ( count( $promotion_ids ) < 2 ) {
				continue;
			}

			$conflicts[] = $this->row(
				self::TYPE_GIFT_OVERLAP,
				'warning',
				$promotion_ids,
				sprintf(
					/* translators: 1: gift product key, 2: promotion IDs */
					__( 'Multiple free_gift_product promotions target the same gift (%1$s) on promotions %2$s.', 'mp-commerce-promotions' ),
					$gift_key,
					implode( ', ', array_map( 'strval', $promotion_ids ) )
				)
			);
		}

		return $conflicts;
	}

	/**
	 * @param array<int, Promotion> $indexed
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_usage_limit_conflict( array $indexed ): array {
		$limited_stackable = array();

		foreach ( $indexed as $id => $promotion ) {
			$has_limit = $promotion->get_usage_limit() !== null || $promotion->get_customer_usage_limit() !== null;
			if ( ! $has_limit ) {
				continue;
			}
			if ( $promotion->get_application_mode() === PromotionApplicationMode::STACKABLE ) {
				$limited_stackable[] = $id;
			}
		}

		if ( count( $limited_stackable ) < 2 ) {
			return array();
		}

		return array(
			$this->row(
				self::TYPE_USAGE_LIMIT_CONFLICT,
				'info',
				$limited_stackable,
				sprintf(
					/* translators: %s: promotion IDs */
					__( 'Stackable promotions %s have usage limits; customers may qualify for fewer combinations than expected.', 'mp-commerce-promotions' ),
					implode( ', ', array_map( 'strval', $limited_stackable ) )
				)
			),
		);
	}

	/**
	 * @param array<int, Promotion> $indexed
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_priority_shadowing( array $indexed ): array {
		$sorted = $indexed;
		uasort(
			$sorted,
			static function ( Promotion $a, Promotion $b ): int {
				$pa = $a->get_priority();
				$pb = $b->get_priority();
				if ( $pa !== $pb ) {
					return $pb <=> $pa;
				}

				$ida = $a->get_id() ?? 0;
				$idb = $b->get_id() ?? 0;

				return $ida <=> $idb;
			}
		);

		$top = reset( $sorted );
		if ( ! $top instanceof Promotion ) {
			return array();
		}

		$top_id = $top->get_id();
		if ( $top_id === null ) {
			return array();
		}

		if ( $top->get_application_mode() !== PromotionApplicationMode::EXCLUSIVE || ! $top->should_stop_processing() ) {
			return array();
		}

		$shadowed = array();
		foreach ( $sorted as $id => $promotion ) {
			if ( $id === $top_id ) {
				continue;
			}
			if ( $promotion->get_priority() < $top->get_priority() ) {
				$shadowed[] = $id;
			}
		}

		if ( $shadowed === array() ) {
			return array();
		}

		return array(
			$this->row(
				self::TYPE_PRIORITY_SHADOWING,
				'warning',
				array_merge( array( $top_id ), $shadowed ),
				sprintf(
					/* translators: 1: high-priority promotion id, 2: lower-priority ids */
					__( 'High-priority exclusive promotion %1$d (stop processing) may prevent lower-priority promotions (%2$s) from being selected when eligible.', 'mp-commerce-promotions' ),
					$top_id,
					implode( ', ', array_map( 'strval', $shadowed ) )
				)
			),
		);
	}

	/**
	 * @param array<int, Promotion> $indexed
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_orchestration_congestion( array $indexed ): array {
		/** @var array<string, list<Promotion>> $group_to_promotions */
		$group_to_promotions = array();

		foreach ( $indexed as $promotion ) {
			$group = $promotion->get_orchestration_group();
			if ( $group === null || $group === '' ) {
				continue;
			}
			if ( ! isset( $group_to_promotions[ $group ] ) ) {
				$group_to_promotions[ $group ] = array();
			}
			$group_to_promotions[ $group ][] = $promotion;
		}

		$conflicts = array();
		foreach ( $group_to_promotions as $group => $promotions ) {
			if ( count( $promotions ) < 2 ) {
				continue;
			}

			$overlapping_ids = $this->promotion_ids_with_overlapping_windows( $promotions );
			if ( count( $overlapping_ids ) < 2 ) {
				continue;
			}

			$conflicts[] = $this->row(
				self::TYPE_ORCHESTRATION_CONGESTION,
				'warning',
				$overlapping_ids,
				sprintf(
					/* translators: 1: orchestration group, 2: promotion IDs, 3: count */
					__( 'Orchestration group "%1$s" has %3$d active promotions with overlapping schedules (%2$s); only one can be selected per cart plan.', 'mp-commerce-promotions' ),
					$group,
					implode( ', ', array_map( 'strval', $overlapping_ids ) ),
					count( $overlapping_ids )
				)
			);
		}

		return $conflicts;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<int>
	 */
	private function promotion_ids_with_overlapping_windows( array $promotions ): array {
		$count = count( $promotions );
		if ( $count < 2 ) {
			return array();
		}

		for ( $i = 0; $i < $count; ++$i ) {
			for ( $j = $i + 1; $j < $count; ++$j ) {
				if ( $this->promotions_overlap_in_time( $promotions[ $i ], $promotions[ $j ] ) ) {
					$ids = array();
					foreach ( $promotions as $promotion ) {
						$id = $promotion->get_id();
						if ( $id !== null && $id > 0 ) {
							$ids[] = $id;
						}
					}

					return array_values( array_unique( $ids, SORT_NUMERIC ) );
				}
			}
		}

		return array();
	}

	/**
	 * @param array<int, Promotion> $indexed
	 * @return list<array{type: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	private function detect_tier_congestion( array $indexed ): array {
		/** @var array<string, list<Promotion>> $tier_to_promotions */
		$tier_to_promotions = array();

		foreach ( $indexed as $promotion ) {
			$tier = PromotionPriorityTier::normalize( $promotion->get_priority_tier() );
			if ( ! isset( $tier_to_promotions[ $tier ] ) ) {
				$tier_to_promotions[ $tier ] = array();
			}
			$tier_to_promotions[ $tier ][] = $promotion;
		}

		$conflicts = array();
		foreach ( $tier_to_promotions as $tier => $promotions ) {
			if ( count( $promotions ) < 3 ) {
				continue;
			}

			$overlapping_ids = $this->promotion_ids_with_overlapping_windows( $promotions );
			if ( count( $overlapping_ids ) < 3 ) {
				continue;
			}

			$conflicts[] = $this->row(
				self::TYPE_TIER_CONGESTION,
				'warning',
				$overlapping_ids,
				sprintf(
					/* translators: 1: priority tier, 2: promotion IDs, 3: count */
					__( 'Priority tier "%1$s" has %3$d overlapping promotions (%2$s); planner evaluates tier first then numeric priority.', 'mp-commerce-promotions' ),
					$tier,
					implode( ', ', array_map( 'strval', $overlapping_ids ) ),
					count( $overlapping_ids )
				)
			);
		}

		return $conflicts;
	}

	private function promotions_overlap_in_time( Promotion $a, Promotion $b ): bool {
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

	private function first_action_type( Promotion $promotion ): ?string {
		foreach ( $promotion->get_actions() as $action ) {
			if ( is_array( $action ) && isset( $action['type'] ) ) {
				return (string) $action['type'];
			}
		}

		return null;
	}

	/**
	 * @param list<int> $promotion_ids
	 * @return array{type: string, severity: string, promotion_ids: list<int>, message: string}
	 */
	private function row( string $type, string $severity, array $promotion_ids, string $message ): array {
		return array(
			'type'          => $type,
			'severity'      => $severity,
			'promotion_ids' => array_values( array_unique( array_map( 'intval', $promotion_ids ), SORT_NUMERIC ) ),
			'message'       => $message,
		);
	}
}
