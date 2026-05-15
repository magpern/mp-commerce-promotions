<?php
/**
 * Promotion lifecycle status (string constants; no persistence).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

final class PromotionStatus {

	public const DRAFT = 'draft';

	public const ACTIVE = 'active';

	public const ARCHIVED = 'archived';

	private function __construct() {
	}

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::DRAFT,
			self::ACTIVE,
			self::ARCHIVED,
		);
	}

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}
}
