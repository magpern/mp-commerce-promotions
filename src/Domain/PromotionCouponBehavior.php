<?php
/**
 * Native Woo coupon coexistence modes for promotions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

final class PromotionCouponBehavior {

	public const COEXIST = 'coexist';

	public const BLOCK_NATIVE = 'block_native';

	public const REQUIRE_NO_COUPON = 'require_no_coupon';

	public const DEFAULT_BEHAVIOR = self::COEXIST;

	/** @var list<string> */
	private const VALID = array(
		self::COEXIST,
		self::BLOCK_NATIVE,
		self::REQUIRE_NO_COUPON,
	);

	public static function is_valid( ?string $behavior ): bool {
		if ( $behavior === null || trim( $behavior ) === '' ) {
			return false;
		}

		return in_array( sanitize_key( $behavior ), self::VALID, true );
	}

	public static function normalize( ?string $behavior ): string {
		if ( $behavior === null || trim( $behavior ) === '' ) {
			return self::DEFAULT_BEHAVIOR;
		}

		$key = sanitize_key( $behavior );

		return self::is_valid( $key ) ? $key : self::DEFAULT_BEHAVIOR;
	}
}
