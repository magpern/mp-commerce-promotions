<?php
/**
 * Per-cart-line discount slice from a promotion.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class AppliedLineDiscount {

	public const META_ORIGINAL_PRICE = 'mp_cp_original_line_unit_price';

	public const META_MUTATED_BY = 'mp_cp_line_discount_promotion_id';

	private string $cart_item_key;

	private int $product_id;

	private ?int $variation_id;

	private int $quantity;

	private float $allocated_amount;

	private int $promotion_id;

	private string $action_type;

	private string $tax_mode_estimate;

	/** @var array<string, mixed> */
	private array $meta;

	/**
	 * @param array<string, mixed> $meta
	 */
	public function __construct(
		string $cart_item_key,
		int $product_id,
		?int $variation_id,
		int $quantity,
		float $allocated_amount,
		int $promotion_id,
		string $action_type,
		string $tax_mode_estimate = 'unknown',
		array $meta = array()
	) {
		$this->cart_item_key      = $cart_item_key;
		$this->product_id         = $product_id;
		$this->variation_id       = $variation_id;
		$this->quantity           = max( 1, $quantity );
		$this->allocated_amount   = max( 0.0, round( $allocated_amount, 4 ) );
		$this->promotion_id       = $promotion_id;
		$this->action_type        = $action_type;
		$this->tax_mode_estimate  = $tax_mode_estimate;
		$this->meta               = $meta;
	}

	public function get_cart_item_key(): string {
		return $this->cart_item_key;
	}

	public function get_product_id(): int {
		return $this->product_id;
	}

	public function get_variation_id(): ?int {
		return $this->variation_id;
	}

	public function get_quantity(): int {
		return $this->quantity;
	}

	public function get_allocated_amount(): float {
		return $this->allocated_amount;
	}

	public function get_promotion_id(): int {
		return $this->promotion_id;
	}

	public function get_action_type(): string {
		return $this->action_type;
	}

	public function get_tax_mode_estimate(): string {
		return $this->tax_mode_estimate;
	}

	/** @return array<string, mixed> */
	public function get_meta(): array {
		return $this->meta;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'cart_item_key'      => $this->cart_item_key,
			'product_id'         => $this->product_id,
			'variation_id'       => $this->variation_id,
			'quantity'           => $this->quantity,
			'allocated_amount'   => $this->allocated_amount,
			'promotion_id'       => $this->promotion_id,
			'action_type'        => $this->action_type,
			'tax_mode_estimate'  => $this->tax_mode_estimate,
			'meta'               => $this->meta,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_array( array $row ): ?self {
		$key = isset( $row['cart_item_key'] ) ? (string) $row['cart_item_key'] : '';
		$pid = isset( $row['promotion_id'] ) ? (int) $row['promotion_id'] : 0;
		if ( $key === '' || $pid <= 0 ) {
			return null;
		}

		return new self(
			$key,
			isset( $row['product_id'] ) ? (int) $row['product_id'] : 0,
			isset( $row['variation_id'] ) && is_numeric( $row['variation_id'] ) ? (int) $row['variation_id'] : null,
			isset( $row['quantity'] ) ? (int) $row['quantity'] : 1,
			isset( $row['allocated_amount'] ) ? (float) $row['allocated_amount'] : 0.0,
			$pid,
			isset( $row['action_type'] ) ? (string) $row['action_type'] : '',
			isset( $row['tax_mode_estimate'] ) ? (string) $row['tax_mode_estimate'] : 'unknown',
			isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array()
		);
	}
}
