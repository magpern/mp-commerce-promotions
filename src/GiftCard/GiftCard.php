<?php
/**
 * Stored-value gift card row (plain code never stored).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;

final class GiftCard {

	public const STATUS_ACTIVE = 'active';

	public const STATUS_DEPLETED = 'depleted';

	public const STATUS_EXPIRED = 'expired';

	public const STATUS_VOIDED = 'voided';

	/**
	 * @var list<string>
	 */
	private const ALLOWED_STATUSES = array(
		self::STATUS_ACTIVE,
		self::STATUS_DEPLETED,
		self::STATUS_EXPIRED,
		self::STATUS_VOIDED,
	);

	private ?int $id;

	private string $gift_card_uuid;

	private string $code_hash;

	private string $code_last4;

	private float $initial_amount;

	private float $balance;

	private string $currency;

	private string $status;

	private ?string $expires_at;

	private ?int $created_order_id;

	private ?int $purchaser_customer_id;

	private ?string $recipient_email;

	private ?string $created_at;

	private ?string $updated_at;

	public function __construct(
		?int $id,
		string $gift_card_uuid,
		string $code_hash,
		string $code_last4,
		float $initial_amount,
		float $balance,
		string $currency,
		string $status,
		?string $expires_at = null,
		?int $created_order_id = null,
		?int $purchaser_customer_id = null,
		?string $recipient_email = null,
		?string $created_at = null,
		?string $updated_at = null
	) {
		$gift_card_uuid = trim( $gift_card_uuid );
		if ( $gift_card_uuid === '' ) {
			throw new InvalidArgumentException( 'GiftCard gift_card_uuid is required.' );
		}

		$code_hash = trim( $code_hash );
		if ( strlen( $code_hash ) !== 64 ) {
			throw new InvalidArgumentException( 'GiftCard code_hash must be 64 characters.' );
		}

		$code_last4 = trim( $code_last4 );
		if ( $code_last4 === '' ) {
			throw new InvalidArgumentException( 'GiftCard code_last4 is required.' );
		}

		if ( $initial_amount < 0 || $balance < 0 ) {
			throw new InvalidArgumentException( 'GiftCard amounts cannot be negative.' );
		}

		$currency = strtoupper( trim( $currency ) );
		if ( $currency === '' ) {
			throw new InvalidArgumentException( 'GiftCard currency is required.' );
		}

		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			throw new InvalidArgumentException( 'GiftCard status is invalid.' );
		}

		$this->id                    = $id;
		$this->gift_card_uuid        = $gift_card_uuid;
		$this->code_hash             = $code_hash;
		$this->code_last4            = $code_last4;
		$this->initial_amount        = $initial_amount;
		$this->balance               = $balance;
		$this->currency              = $currency;
		$this->status                = $status;
		$this->expires_at            = $expires_at;
		$this->created_order_id      = $created_order_id;
		$this->purchaser_customer_id = $purchaser_customer_id;
		$this->recipient_email       = $recipient_email;
		$this->created_at            = $created_at;
		$this->updated_at            = $updated_at;
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_gift_card_uuid(): string {
		return $this->gift_card_uuid;
	}

	public function get_code_hash(): string {
		return $this->code_hash;
	}

	public function get_code_last4(): string {
		return $this->code_last4;
	}

	public function get_initial_amount(): float {
		return $this->initial_amount;
	}

	public function get_balance(): float {
		return $this->balance;
	}

	public function get_currency(): string {
		return $this->currency;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_expires_at(): ?string {
		return $this->expires_at;
	}

	public function get_created_order_id(): ?int {
		return $this->created_order_id;
	}

	public function get_purchaser_customer_id(): ?int {
		return $this->purchaser_customer_id;
	}

	public function get_recipient_email(): ?string {
		return $this->recipient_email;
	}

	public function get_created_at(): ?string {
		return $this->created_at;
	}

	public function get_updated_at(): ?string {
		return $this->updated_at;
	}

	public function with_balance_and_status( float $balance, string $status ): self {
		return new self(
			$this->id,
			$this->gift_card_uuid,
			$this->code_hash,
			$this->code_last4,
			$this->initial_amount,
			$balance,
			$this->currency,
			$status,
			$this->expires_at,
			$this->created_order_id,
			$this->purchaser_customer_id,
			$this->recipient_email,
			$this->created_at,
			$this->updated_at
		);
	}

	public function is_expired_at( string $now_mysql ): bool {
		if ( $this->expires_at === null || $this->expires_at === '' ) {
			return false;
		}

		return strcmp( $this->expires_at, $now_mysql ) < 0;
	}

	public function can_redeem( float $amount, string $now_mysql ): bool {
		if ( $amount <= 0 ) {
			return false;
		}

		if ( $this->status !== self::STATUS_ACTIVE ) {
			return false;
		}

		if ( $this->is_expired_at( $now_mysql ) ) {
			return false;
		}

		return $this->balance >= self::money( $amount ) - 0.0001;
	}

	public static function money( float $amount ): float {
		return round( max( 0.0, $amount ), 2 );
	}
}
