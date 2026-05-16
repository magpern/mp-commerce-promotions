<?php
/**
 * Promotion application strategy identifiers.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

final class PromotionApplicationMode {

	public const EXCLUSIVE = 'exclusive';

	public const STACKABLE = 'stackable';

	private function __construct() {
	}

	public static function is_valid( string $mode ): bool {
		return in_array( $mode, array( self::EXCLUSIVE, self::STACKABLE ), true );
	}
}
