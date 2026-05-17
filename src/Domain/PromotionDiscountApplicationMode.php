<?php
/**
 * How storefront discounts are applied (fee vs native line prices).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

final class PromotionDiscountApplicationMode {

	public const FEE_BASED = 'fee_based';

	public const LINE_ITEM = 'line_item';

	public const HYBRID = 'hybrid';

	public const DEFAULT_MODE = self::FEE_BASED;

	/** @var list<string> */
	private const VALID = array(
		self::FEE_BASED,
		self::LINE_ITEM,
		self::HYBRID,
	);

	/** @var list<string> */
	public const LINE_CAPABLE_ACTIONS = array(
		'percentage_discount',
		'fixed_amount_discount',
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

	public static function uses_line_mutation( string $mode ): bool {
		$mode = self::normalize( $mode );

		return $mode === self::LINE_ITEM || $mode === self::HYBRID;
	}

	public static function allows_fee_fallback( string $mode ): bool {
		$mode = self::normalize( $mode );

		return $mode === self::FEE_BASED || $mode === self::HYBRID;
	}

	public static function is_line_capable_action( string $action_type ): bool {
		return in_array( $action_type, self::LINE_CAPABLE_ACTIONS, true );
	}
}
