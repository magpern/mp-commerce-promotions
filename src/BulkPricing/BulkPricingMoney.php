<?php
/**
 * Integer minor-unit money helpers for bulk pricing.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

final class BulkPricingMoney {

	public static function factor( int $decimals ): int {
		$decimals = max( 0, min( 4, $decimals ) );

		return (int) pow( 10, $decimals );
	}

	public static function to_minor( float $amount, int $decimals ): int {
		$factor = self::factor( $decimals );

		return (int) round( $amount * $factor );
	}

	public static function from_minor( int $minor, int $decimals ): float {
		$factor = self::factor( $decimals );

		if ( $factor <= 0 ) {
			return 0.0;
		}

		return round( $minor / $factor, $decimals );
	}

	public static function apply_percentage_minor( int $minor, int $percentage ): int {
		$percentage = max( 0, min( 100, $percentage ) );
		if ( $minor <= 0 ) {
			return 0;
		}

		return (int) round( $minor * ( 100 - $percentage ) / 100 );
	}

	public static function line_total_minor( int $unit_minor, int $quantity ): int {
		$quantity = max( 1, $quantity );

		return $unit_minor * $quantity;
	}
}
