<?php
/**
 * Promotion redemption row (order-level usage).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;

final class Redemption {

	public const STATUS_RECORDED = 'recorded';

	public const STATUS_REVERSED = 'reversed';

	/**
	 * @var list<string>
	 */
	private const ALLOWED_STATUSES = array(
		self::STATUS_RECORDED,
		self::STATUS_REVERSED,
	);

	private ?int $id;

	private int $promotion_id;

	private ?int $order_id;

	private ?int $customer_id;

	private ?string $code;

	private float $discount_amount;

	private ?string $currency;

	private string $status;

	private ?string $redeemed_at;

	private ?string $created_at;

	public function __construct(
		?int $id,
		int $promotion_id,
		?int $order_id,
		?int $customer_id,
		?string $code,
		float $discount_amount,
		?string $currency,
		string $status,
		?string $redeemed_at,
		?string $created_at
	) {
		if ( $promotion_id <= 0 ) {
			throw new InvalidArgumentException( 'Redemption promotion_id must be > 0.' );
		}
		if ( $discount_amount < 0 ) {
			throw new InvalidArgumentException( 'Redemption discount_amount must be >= 0.' );
		}

		$status = trim( $status );
		self::assert_valid_status( $status );

		$this->id              = $id;
		$this->promotion_id    = $promotion_id;
		$this->order_id        = $order_id;
		$this->customer_id     = $customer_id;
		$this->code            = $code;
		$this->discount_amount = $discount_amount;
		$this->currency        = $currency;
		$this->status          = $status;
		$this->redeemed_at     = $redeemed_at;
		$this->created_at      = $created_at;
	}

	public static function from_array( array $data ): self {
		$raw_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$id     = $raw_id > 0 ? $raw_id : null;

		$promotion_id = isset( $data['promotion_id'] ) ? (int) $data['promotion_id'] : 0;

		$order_id = isset( $data['order_id'] ) && $data['order_id'] !== null && $data['order_id'] !== ''
			? (int) $data['order_id']
			: null;
		if ( $order_id !== null && $order_id <= 0 ) {
			$order_id = null;
		}

		$customer_id = isset( $data['customer_id'] ) && $data['customer_id'] !== null && $data['customer_id'] !== ''
			? (int) $data['customer_id']
			: null;
		if ( $customer_id !== null && $customer_id <= 0 ) {
			$customer_id = null;
		}

		$code = isset( $data['code'] ) && is_string( $data['code'] ) && $data['code'] !== ''
			? $data['code']
			: null;

		$discount_amount = isset( $data['discount_amount'] ) && is_numeric( $data['discount_amount'] )
			? (float) $data['discount_amount']
			: 0.0;

		$currency = isset( $data['currency'] ) && is_string( $data['currency'] ) && $data['currency'] !== ''
			? $data['currency']
			: null;

		$status = isset( $data['status'] ) ? (string) $data['status'] : '';

		$redeemed_at = isset( $data['redeemed_at'] ) && is_string( $data['redeemed_at'] ) && $data['redeemed_at'] !== ''
			? $data['redeemed_at']
			: null;

		$created_at = isset( $data['created_at'] ) && is_string( $data['created_at'] ) && $data['created_at'] !== ''
			? $data['created_at']
			: null;

		return new self(
			$id,
			$promotion_id,
			$order_id,
			$customer_id,
			$code,
			$discount_amount,
			$currency,
			$status,
			$redeemed_at,
			$created_at
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'promotion_id'    => $this->promotion_id,
			'order_id'        => $this->order_id,
			'customer_id'     => $this->customer_id,
			'code'            => $this->code,
			'discount_amount' => $this->discount_amount,
			'currency'        => $this->currency,
			'status'          => $this->status,
			'redeemed_at'     => $this->redeemed_at,
			'created_at'      => $this->created_at,
		);
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_promotion_id(): int {
		return $this->promotion_id;
	}

	public function get_order_id(): ?int {
		return $this->order_id;
	}

	public function get_customer_id(): ?int {
		return $this->customer_id;
	}

	public function get_code(): ?string {
		return $this->code;
	}

	public function get_discount_amount(): float {
		return $this->discount_amount;
	}

	public function get_currency(): ?string {
		return $this->currency;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_redeemed_at(): ?string {
		return $this->redeemed_at;
	}

	public function get_created_at(): ?string {
		return $this->created_at;
	}

	public function with_status( string $status ): self {
		$status = trim( $status );
		self::assert_valid_status( $status );

		return new self(
			$this->id,
			$this->promotion_id,
			$this->order_id,
			$this->customer_id,
			$this->code,
			$this->discount_amount,
			$this->currency,
			$status,
			$this->redeemed_at,
			$this->created_at
		);
	}

	private static function assert_valid_status( string $status ): void {
		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			throw new InvalidArgumentException(
				'Redemption status must be one of: recorded, reversed.'
			);
		}
	}
}
