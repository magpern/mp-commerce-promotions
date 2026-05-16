<?php
/**
 * Builds manual promotion codes (hashed; plain code not retained).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;

final class PromotionCodeFactory {

	/**
	 * @throws InvalidArgumentException
	 */
	public function create_manual_code(
		int $promotion_id,
		string $plain_code,
		?int $usage_limit = null,
		?string $expires_at = null
	): PromotionCode {
		if ( $promotion_id <= 0 ) {
			throw new InvalidArgumentException( 'promotion_id must be > 0.' );
		}

		$normalized = self::normalize_plain_code( $plain_code );
		self::assert_plain_code_valid( $normalized );

		if ( $usage_limit !== null && $usage_limit < 0 ) {
			throw new InvalidArgumentException( 'usage_limit must be null or >= 0.' );
		}

		$expires_at = self::normalize_expires_at( $expires_at );

		return new PromotionCode(
			null,
			$promotion_id,
			PromotionCodeRepository::hash_plain_code( $normalized ),
			self::derive_code_last4( $normalized ),
			PromotionCode::STATUS_ACTIVE,
			$usage_limit,
			0,
			$expires_at,
			null,
			null
		);
	}

	public static function normalize_plain_code( string $plain_code ): string {
		return strtoupper( trim( $plain_code ) );
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public static function assert_plain_code_valid( string $normalized ): void {
		if ( strlen( $normalized ) < 4 ) {
			throw new InvalidArgumentException( 'Promotion code must be at least 4 characters.' );
		}

		if ( ! preg_match( '/^[A-Z0-9-]+$/', $normalized ) ) {
			throw new InvalidArgumentException( 'Promotion code may only contain A-Z, 0-9, and hyphens.' );
		}
	}

	public static function derive_code_last4( string $normalized ): string {
		$len = strlen( $normalized );
		if ( $len <= 4 ) {
			return $normalized;
		}

		return substr( $normalized, -4 );
	}

	private static function normalize_expires_at( ?string $expires_at ): ?string {
		if ( $expires_at === null ) {
			return null;
		}

		$trimmed = trim( $expires_at );
		if ( $trimmed === '' ) {
			return null;
		}

		return $trimmed;
	}
}
