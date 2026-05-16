<?php
/**
 * Shared quantity operator comparison for cart line conditions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

final class QuantityComparator {

	private const EPSILON = 0.00001;

	/**
	 * @var list<string>
	 */
	private const OPERATORS = array( '>=', '>', '=', '<=', '<' );

	public static function supports( string $operator ): bool {
		return in_array( $operator, self::OPERATORS, true );
	}

	public static function compare( float $actual, string $operator, float $expected ): bool {
		if ( ! self::supports( $operator ) ) {
			return false;
		}

		switch ( $operator ) {
			case '>=':
				return $actual >= $expected;
			case '>':
				return $actual > $expected;
			case '=':
				return abs( $actual - $expected ) < self::EPSILON;
			case '<=':
				return $actual <= $expected;
			case '<':
				return $actual < $expected;
			default:
				return false;
		}
	}
}
