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

	/** Manual issue: no recipient email was provided. */
	public const NOT_REQUESTED = 'not_requested';

	/** Scheduled: card not generated yet; waiting for delivery date. */
	public const PENDING_SCHEDULED = 'pending_scheduled';

	public const CANCELLED = 'cancelled';

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
			self::NOT_REQUESTED,
			self::PENDING_SCHEDULED,
			self::CANCELLED,
			self::UNKNOWN,
		);
	}

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}
}
