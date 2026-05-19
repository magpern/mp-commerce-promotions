<?php
/**
 * Session-scoped promotion IDs the shopper removed from the current cart.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;

final class PromotionCartExclusionSession {

	public const SESSION_KEY = 'mp_cp_excluded_promotion_ids';

	public const DISABLE_AUTOMATIC_KEY = 'mp_cp_disable_automatic_promotions';

	public const DISABLE_AUTOMATIC_VALUE = 'yes';

	/**
	 * @return list<int>
	 */
	public static function get_excluded_ids(): array {
		$raw = CartSessionHelper::get( self::SESSION_KEY );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return self::normalize_ids( $raw );
	}

	public static function is_excluded( int $promotion_id ): bool {
		if ( $promotion_id <= 0 ) {
			return false;
		}

		return in_array( $promotion_id, self::get_excluded_ids(), true );
	}

	public static function exclude( int $promotion_id ): void {
		if ( $promotion_id <= 0 ) {
			return;
		}

		$ids = self::get_excluded_ids();
		if ( in_array( $promotion_id, $ids, true ) ) {
			return;
		}

		$ids[] = $promotion_id;
		CartSessionHelper::set( self::SESSION_KEY, $ids );
	}

	public static function clear_all(): void {
		CartSessionHelper::clear( self::SESSION_KEY );
		self::clear_automatic_disabled();
	}

	public static function has_exclusions(): bool {
		return self::get_excluded_ids() !== array();
	}

	public static function is_automatic_disabled(): bool {
		$raw = CartSessionHelper::get( self::DISABLE_AUTOMATIC_KEY );
		if ( ! is_string( $raw ) ) {
			return false;
		}

		return $raw === self::DISABLE_AUTOMATIC_VALUE;
	}

	public static function disable_automatic_promotions(): void {
		CartSessionHelper::set( self::DISABLE_AUTOMATIC_KEY, self::DISABLE_AUTOMATIC_VALUE );
	}

	public static function clear_automatic_disabled(): void {
		CartSessionHelper::clear( self::DISABLE_AUTOMATIC_KEY );
	}

	public static function has_cart_promotion_adjustments(): bool {
		return self::has_exclusions() || self::is_automatic_disabled();
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<Promotion>
	 */
	public static function filter_promotions( array $promotions ): array {
		$excluded = self::get_excluded_ids();
		if ( $excluded === array() ) {
			return $promotions;
		}

		$filtered = array();
		foreach ( $promotions as $promotion ) {
			$pid = (int) ( $promotion->get_id() ?? 0 );
			if ( $pid <= 0 || in_array( $pid, $excluded, true ) ) {
				continue;
			}
			$filtered[] = $promotion;
		}

		return $filtered;
	}

	/**
	 * @param array<mixed> $raw
	 * @return list<int>
	 */
	public static function normalize_ids( array $raw ): array {
		$ids = array();
		foreach ( $raw as $value ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$ids = array_values( array_unique( $ids ) );

		return $ids;
	}

	/**
	 * @param list<int> $ids
	 * @param int       $promotion_id
	 * @return list<int>
	 */
	public static function add_id( array $ids, int $promotion_id ): array {
		if ( $promotion_id <= 0 ) {
			return $ids;
		}

		if ( in_array( $promotion_id, $ids, true ) ) {
			return $ids;
		}

		$ids[] = $promotion_id;

		return array_values( $ids );
	}
}
