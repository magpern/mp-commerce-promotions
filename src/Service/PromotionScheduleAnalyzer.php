<?php
/**
 * Schedule overlap and collision forecasting across promotions (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\PromotionDateHelper;
use MP\CommercePromotions\Engine\RuleTypes;

final class PromotionScheduleAnalyzer {

	public const CODE_OVERLAPPING_CAMPAIGN_WINDOW = 'overlapping_campaign_window';
	public const CODE_EXCLUSIVE_OVERLAP           = 'exclusive_overlap';
	public const CODE_HIGH_DISCOUNT_OVERLAP       = 'high_discount_overlap';
	public const CODE_SEASONAL_OVERLAP            = 'seasonal_overlap';

	/**
	 * @param list<Promotion>   $catalog   Peer promotions (typically active + scheduled).
	 * @param Promotion|null    $subject   When set, only emit issues involving this promotion.
	 * @return list<array{code: string, severity: string, promotion_ids: list<int>, message: string}>
	 */
	public function analyze( array $catalog, ?Promotion $subject = null ): array {
		$indexed = $this->index_promotions( $catalog );
		if ( count( $indexed ) < 2 ) {
			return array();
		}

		$issues = array();
		$ids    = array_keys( $indexed );
		$seen   = array();

		foreach ( $ids as $id_a ) {
			foreach ( $ids as $id_b ) {
				if ( $id_a >= $id_b ) {
					continue;
				}

				if ( $subject !== null ) {
					$subject_id = $subject->get_id();
					if ( $subject_id === null || ( $id_a !== $subject_id && $id_b !== $subject_id ) ) {
						continue;
					}
				}

				$key = $id_a . ':' . $id_b;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$a = $indexed[ $id_a ];
				$b = $indexed[ $id_b ];

				if ( ! $this->windows_overlap( $a, $b ) ) {
					continue;
				}

				$pair_ids = array( $id_a, $id_b );

				$issues[] = $this->row(
					self::CODE_OVERLAPPING_CAMPAIGN_WINDOW,
					'info',
					$pair_ids,
					sprintf(
						/* translators: 1: promotion A id, 2: promotion B id */
						__( 'Promotions %1$d and %2$d have overlapping schedule windows.', 'mp-commerce-promotions' ),
						$id_a,
						$id_b
					)
				);

				if ( $this->both_exclusive( $a, $b ) ) {
					$issues[] = $this->row(
						self::CODE_EXCLUSIVE_OVERLAP,
						'warning',
						$pair_ids,
						sprintf(
							/* translators: 1: promotion A id, 2: promotion B id */
							__( 'Exclusive promotions %1$d and %2$d overlap in time; only one may apply per cart.', 'mp-commerce-promotions' ),
							$id_a,
							$id_b
						)
					);
				}

				if ( $this->high_discount_overlap( $a, $b ) ) {
					$issues[] = $this->row(
						self::CODE_HIGH_DISCOUNT_OVERLAP,
						'warning',
						$pair_ids,
						sprintf(
							/* translators: 1: promotion A id, 2: promotion B id */
							__( 'Promotions %1$d and %2$d have overlapping scoped percentage discounts during the same window (combined rates may exceed 100%% on shared lines).', 'mp-commerce-promotions' ),
							$id_a,
							$id_b
						)
					);
				}

				if ( $this->seasonal_label_overlap( $a, $b ) ) {
					$issues[] = $this->row(
						self::CODE_SEASONAL_OVERLAP,
						'info',
						$pair_ids,
						sprintf(
							/* translators: 1: campaign label, 2: promotion ids */
							__( 'Campaign label "%1$s" is shared by promotions %2$s with overlapping schedules.', 'mp-commerce-promotions' ),
							(string) $a->get_campaign_label(),
							implode( ', ', array_map( 'strval', $pair_ids ) )
						)
					);
				}
			}
		}

		return $issues;
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
			if ( ! $this->is_schedulable_status( $promotion->get_status() ) ) {
				continue;
			}
			$indexed[ $id ] = $promotion;
		}

		return $indexed;
	}

	private function is_schedulable_status( string $status ): bool {
		return in_array(
			$status,
			array(
				PromotionStatus::ACTIVE,
				PromotionStatus::PAUSED,
				PromotionStatus::DRAFT,
			),
			true
		);
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

	private function both_exclusive( Promotion $a, Promotion $b ): bool {
		return $a->get_application_mode() === PromotionApplicationMode::EXCLUSIVE
			&& $b->get_application_mode() === PromotionApplicationMode::EXCLUSIVE;
	}

	private function seasonal_label_overlap( Promotion $a, Promotion $b ): bool {
		$label_a = $a->get_campaign_label();
		$label_b = $b->get_campaign_label();
		if ( $label_a === null || $label_a === '' || $label_b === null || $label_b === '' ) {
			return false;
		}

		return $label_a === $label_b;
	}

	private function high_discount_overlap( Promotion $a, Promotion $b ): bool {
		$keys_a = $this->percentage_scope_keys( $a );
		$keys_b = $this->percentage_scope_keys( $b );
		if ( $keys_a === array() || $keys_b === array() ) {
			return false;
		}

		$shared = array_intersect( $keys_a, $keys_b );
		if ( $shared === array() ) {
			return false;
		}

		$sum = $this->max_percentage_on_promotion( $a ) + $this->max_percentage_on_promotion( $b );

		return $sum > 100.0;
	}

	/**
	 * @return list<string>
	 */
	private function percentage_scope_keys( Promotion $promotion ): array {
		$keys = array();
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) || ( $action['type'] ?? '' ) !== RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
				continue;
			}
			$product_ids = isset( $action['product_ids'] ) && is_array( $action['product_ids'] )
				? CartItemSelector::normalize_positive_int_list( $action['product_ids'] )
				: array();
			$category_ids = isset( $action['category_ids'] ) && is_array( $action['category_ids'] )
				? CartItemSelector::normalize_positive_int_list( $action['category_ids'] )
				: array();
			if ( $product_ids === array() && $category_ids === array() ) {
				$keys[] = 'cart';
				continue;
			}
			foreach ( $product_ids as $pid ) {
				$keys[] = 'product:' . $pid;
			}
			foreach ( $category_ids as $cid ) {
				$keys[] = 'category:' . $cid;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	private function max_percentage_on_promotion( Promotion $promotion ): float {
		$max = 0.0;
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) || ( $action['type'] ?? '' ) !== RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
				continue;
			}
			$pct = isset( $action['percentage'] ) ? (float) $action['percentage'] : 0.0;
			if ( $pct > $max ) {
				$max = $pct;
			}
		}

		return $max;
	}

	/**
	 * @param list<int> $promotion_ids
	 * @return array{code: string, severity: string, promotion_ids: list<int>, message: string}
	 */
	private function row( string $code, string $severity, array $promotion_ids, string $message ): array {
		sort( $promotion_ids, SORT_NUMERIC );

		return array(
			'code'          => $code,
			'severity'      => $severity,
			'promotion_ids' => array_values( $promotion_ids ),
			'message'       => $message,
		);
	}
}
