<?php
/**
 * Per-product bulk pricing bracket configuration.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

final class BulkPricingConfig {

	public const SCHEMA_VERSION = 1;

	/**
	 * @param list<array{
	 *   min_quantity: int,
	 *   discount_percentage: int,
	 *   anchor_quantity?: int,
	 *   badge?: string,
	 *   sort_order?: int
	 * }> $tiers
	 */
	public function __construct(
		private bool $enabled,
		private array $tiers
	) {
	}

	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * @return list<array{
	 *   min_quantity: int,
	 *   discount_percentage: int,
	 *   anchor_quantity: int,
	 *   badge: ?string,
	 *   sort_order: int
	 * }>
	 */
	public function get_tiers(): array {
		return $this->tiers;
	}

	public function has_valid_tiers(): bool {
		return $this->enabled && $this->tiers !== array();
	}

	/**
	 * Highest bracket for quantity.
	 */
	public function resolve_bracket_for_quantity( int $quantity ): ?array {
		if ( $quantity <= 0 || $this->tiers === array() ) {
			return null;
		}

		$matched = null;
		foreach ( $this->tiers as $tier ) {
			if ( $quantity >= (int) $tier['min_quantity'] ) {
				if ( $matched === null || (int) $tier['min_quantity'] > (int) $matched['min_quantity'] ) {
					$matched = $tier;
				}
			}
		}

		return $matched;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{min_quantity:int,discount_percentage:int,anchor_quantity:int,badge:?string,sort_order:int}|null
	 */
	public static function normalize_tier_row( array $row ): ?array {
		$min = isset( $row['min_quantity'] ) ? (int) $row['min_quantity'] : 0;
		if ( $min <= 0 ) {
			return null;
		}

		$pct = isset( $row['discount_percentage'] ) ? (int) $row['discount_percentage'] : 0;
		$pct = max( 0, min( 100, $pct ) );

		$anchor = isset( $row['anchor_quantity'] ) ? (int) $row['anchor_quantity'] : $min;
		if ( $anchor <= 0 ) {
			$anchor = $min;
		}

		$badge = isset( $row['badge'] ) ? trim( (string) $row['badge'] ) : '';
		$badge = $badge !== '' ? $badge : null;

		$sort = isset( $row['sort_order'] ) ? (int) $row['sort_order'] : $min;

		return array(
			'min_quantity'        => $min,
			'discount_percentage'   => $pct,
			'anchor_quantity'     => $anchor,
			'badge'               => $badge,
			'sort_order'          => $sort,
		);
	}
}
