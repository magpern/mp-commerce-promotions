<?php
/**
 * Discount allocation methodology (metadata; storefront still fee-based).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

final class PromotionAllocationMode {

	public const PROPORTIONAL = 'proportional';

	public const LINE_EQUAL = 'line_equal';

	public const SHIPPING_FIRST = 'shipping_first';

	public const DEFAULT_MODE = self::PROPORTIONAL;

	/** @var list<string> */
	private const VALID = array(
		self::PROPORTIONAL,
		self::LINE_EQUAL,
		self::SHIPPING_FIRST,
	);

	public static function is_valid( ?string $mode ): bool {
		if ( $mode === null || trim( $mode ) === '' ) {
			return false;
		}

		return in_array( sanitize_key( $mode ), self::VALID, true );
	}

	public static function normalize( ?string $mode ): string {
		if ( $mode === null || trim( $mode ) === '' ) {
			return self::DEFAULT_MODE;
		}

		$key = sanitize_key( $mode );

		return self::is_valid( $key ) ? $key : self::DEFAULT_MODE;
	}
}
