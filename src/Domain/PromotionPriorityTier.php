<?php
/**
 * Priority tier ordering for planner evaluation.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use MP\CommercePromotions\Domain\Promotion;

final class PromotionPriorityTier {

	public const OVERRIDE = 'override';

	public const RECOVERY = 'recovery';

	public const LOYALTY = 'loyalty';

	public const CAMPAIGN = 'campaign';

	public const STOREFRONT = 'storefront';

	public const DEFAULT_TIER = self::STOREFRONT;

	/** @var list<string> */
	private const VALID = array(
		self::OVERRIDE,
		self::RECOVERY,
		self::LOYALTY,
		self::CAMPAIGN,
		self::STOREFRONT,
	);

	/** @var array<string, int> */
	private const ORDER = array(
		self::OVERRIDE    => 0,
		self::RECOVERY    => 1,
		self::LOYALTY     => 2,
		self::CAMPAIGN    => 3,
		self::STOREFRONT  => 4,
	);

	public static function is_valid( ?string $tier ): bool {
		if ( $tier === null || trim( $tier ) === '' ) {
			return false;
		}

		return in_array( sanitize_key( $tier ), self::VALID, true );
	}

	public static function normalize( ?string $tier ): string {
		if ( $tier === null || trim( $tier ) === '' ) {
			return self::DEFAULT_TIER;
		}

		$key = sanitize_key( $tier );

		return self::is_valid( $key ) ? $key : self::DEFAULT_TIER;
	}

	public static function sort_key( string $tier ): int {
		return self::ORDER[ self::normalize( $tier ) ] ?? 99;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<Promotion>
	 */
	public static function sort_promotions( array $promotions ): array {
		usort(
			$promotions,
			static function ( Promotion $a, Promotion $b ): int {
				$ta = self::sort_key( $a->get_priority_tier() );
				$tb = self::sort_key( $b->get_priority_tier() );
				if ( $ta !== $tb ) {
					return $ta <=> $tb;
				}
				$pa = $a->get_priority();
				$pb = $b->get_priority();
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}

				return ( $a->get_id() ?? 0 ) <=> ( $b->get_id() ?? 0 );
			}
		);

		return $promotions;
	}
}
