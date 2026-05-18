<?php
/**
 * Append-only gift card ledger transaction.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;

final class GiftCardTransaction {

	public const TYPE_ISSUED = 'issued';

	public const TYPE_REDEEMED = 'redeemed';

	public const TYPE_REFUNDED = 'refunded';

	public const TYPE_ADJUSTED = 'adjusted';

	public const TYPE_EXPIRED = 'expired';

	public const TYPE_VOIDED = 'voided';

	public const TYPE_REFUND_TO_CREDIT = 'refund_to_credit';

	/**
	 * @var list<string>
	 */
	private const ALLOWED_TYPES = array(
		self::TYPE_ISSUED,
		self::TYPE_REDEEMED,
		self::TYPE_REFUNDED,
		self::TYPE_ADJUSTED,
		self::TYPE_EXPIRED,
		self::TYPE_VOIDED,
		self::TYPE_REFUND_TO_CREDIT,
	);

	private ?int $id;

	private int $gift_card_id;

	private string $transaction_type;

	private float $amount;

	private float $balance_after;

	private ?int $order_id;

	private ?int $customer_id;

	private ?string $note;

	private ?string $created_at;

	public function __construct(
		?int $id,
		int $gift_card_id,
		string $transaction_type,
		float $amount,
		float $balance_after,
		?int $order_id = null,
		?int $customer_id = null,
		?string $note = null,
		?string $created_at = null
	) {
		if ( $gift_card_id <= 0 ) {
			throw new InvalidArgumentException( 'GiftCardTransaction gift_card_id must be > 0.' );
		}

		if ( ! in_array( $transaction_type, self::ALLOWED_TYPES, true ) ) {
			throw new InvalidArgumentException( 'GiftCardTransaction transaction_type is invalid.' );
		}

		if ( $balance_after < 0 ) {
			throw new InvalidArgumentException( 'GiftCardTransaction balance_after cannot be negative.' );
		}

		$this->id               = $id;
		$this->gift_card_id     = $gift_card_id;
		$this->transaction_type = $transaction_type;
		$this->amount           = $amount;
		$this->balance_after    = $balance_after;
		$this->order_id         = $order_id;
		$this->customer_id      = $customer_id;
		$this->note             = $note;
		$this->created_at       = $created_at;
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_gift_card_id(): int {
		return $this->gift_card_id;
	}

	public function get_transaction_type(): string {
		return $this->transaction_type;
	}

	public function get_amount(): float {
		return $this->amount;
	}

	public function get_balance_after(): float {
		return $this->balance_after;
	}

	public function get_order_id(): ?int {
		return $this->order_id;
	}

	public function get_customer_id(): ?int {
		return $this->customer_id;
	}

	public function get_note(): ?string {
		return $this->note;
	}

	public function get_created_at(): ?string {
		return $this->created_at;
	}
}
