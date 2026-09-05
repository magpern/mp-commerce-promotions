<?php
/**
 * Product meta persistence for bulk pricing brackets.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

final class BulkPricingProductMeta {

	public const META_ENABLED = '_mp_cp_bulk_pricing_enabled';

	public const META_TIERS = '_mp_cp_bulk_pricing_tiers';

	public const META_SCHEMA = '_mp_cp_bulk_pricing_schema_version';

	public const VALUE_YES = 'yes';

	public const VALUE_NO = 'no';

	public const MAX_TIERS = 8;

	public function read( int $product_id ): BulkPricingConfig {
		if ( $product_id <= 0 ) {
			return new BulkPricingConfig( false, array() );
		}

		$enabled = get_post_meta( $product_id, self::META_ENABLED, true ) === self::VALUE_YES;
		$raw     = (string) get_post_meta( $product_id, self::META_TIERS, true );
		$tiers   = $this->decode_tiers( $raw );

		return new BulkPricingConfig( $enabled, $tiers );
	}

	/**
	 * @param list<array<string, mixed>> $tiers
	 */
	public function write( int $product_id, bool $enabled, array $tiers ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		update_post_meta( $product_id, self::META_ENABLED, $enabled ? self::VALUE_YES : self::VALUE_NO );
		update_post_meta( $product_id, self::META_TIERS, wp_json_encode( $tiers ) );
		update_post_meta( $product_id, self::META_SCHEMA, (string) BulkPricingConfig::SCHEMA_VERSION );
	}

	/**
	 * @param array<string, mixed> $post
	 * @return array{enabled:bool,tiers:list<array<string,mixed>>}
	 */
	public function validate_from_post( array $post ): array {
		$enabled = ! empty( $post['mp_cp_bulk_pricing_enabled'] );
		$raw     = isset( $post['mp_cp_bulk_tiers'] ) && is_array( $post['mp_cp_bulk_tiers'] )
			? $post['mp_cp_bulk_tiers']
			: array();

		$tiers    = array();
		$seen_min = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalized = BulkPricingConfig::normalize_tier_row( $row );
			if ( $normalized === null ) {
				continue;
			}
			if ( isset( $seen_min[ $normalized['min_quantity'] ] ) ) {
				throw new \InvalidArgumentException( 'duplicate_min_quantity' );
			}
			$seen_min[ $normalized['min_quantity'] ] = true;
			$tiers[] = $normalized;
		}

		usort(
			$tiers,
			static function ( array $a, array $b ): int {
				return (int) $a['sort_order'] <=> (int) $b['sort_order'];
			}
		);

		if ( $enabled && $tiers === array() ) {
			throw new \InvalidArgumentException( 'tiers_required_when_enabled' );
		}

		if ( count( $tiers ) > self::MAX_TIERS ) {
			throw new \InvalidArgumentException( 'too_many_tiers' );
		}

		return array(
			'enabled' => $enabled,
			'tiers'   => $tiers,
		);
	}

	/**
	 * @return list<array{min_quantity:int,discount_percentage:int,anchor_quantity:int,badge:?string,sort_order:int}>
	 */
	private function decode_tiers( string $raw ): array {
		if ( $raw === '' ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$tiers = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalized = BulkPricingConfig::normalize_tier_row( $row );
			if ( $normalized !== null ) {
				$tiers[] = $normalized;
			}
		}

		usort(
			$tiers,
			static function ( array $a, array $b ): int {
				return (int) $a['sort_order'] <=> (int) $b['sort_order'];
			}
		);

		return $tiers;
	}
}
