<?php
/**
 * WordPress-local datetime comparison for promotion windows.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class PromotionDateHelper {

	private function __construct() {
	}

	public static function now_timestamp(): int {
		if ( function_exists( 'current_time' ) ) {
			return (int) current_time( 'timestamp' );
		}

		return time();
	}

	public static function parse_mysql_datetime( ?string $value ): ?int {
		if ( $value === null ) {
			return null;
		}

		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}

		$timestamp = strtotime( $value );
		if ( $timestamp === false ) {
			return null;
		}

		return $timestamp;
	}
}
