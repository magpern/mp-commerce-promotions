<?php
/**
 * Gift card email delivery status values (order meta).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardDeliveryStatus {

	public const PENDING = 'pending';

	public const SENT = 'sent';

	public const FAILED = 'failed';

	public const DISABLED = 'disabled';

	/** Legacy rows before delivery tracking was added. */
	public const UNKNOWN = 'unknown';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::PENDING,
			self::SENT,
			self::FAILED,
			self::DISABLED,
			self::UNKNOWN,
		);
	}

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}
}
