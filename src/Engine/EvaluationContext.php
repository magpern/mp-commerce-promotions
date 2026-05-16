<?php
/**
 * Generic evaluation context (no WooCommerce types).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

use InvalidArgumentException;

final class EvaluationContext {

	private ?int $customer_id;

	private ?float $cart_subtotal;

	private ?string $currency;

	/** @var array<mixed> */
	private array $items;

	/** @var array<mixed> */
	private array $metadata;

	/**
	 * @param array<mixed> $items
	 * @param array<mixed> $metadata
	 */
	public function __construct(
		?int $customer_id,
		?float $cart_subtotal,
		?string $currency,
		array $items,
		array $metadata
	) {
		if ( $cart_subtotal !== null && $cart_subtotal < 0 ) {
			throw new InvalidArgumentException( 'cart_subtotal must be null or >= 0.' );
		}

		$this->customer_id   = $customer_id;
		$this->cart_subtotal = $cart_subtotal;
		$this->currency      = $currency;
		$this->items         = $items;
		$this->metadata      = $metadata;
	}

	public static function from_array( array $data ): self {
		$customer_id = isset( $data['customer_id'] ) && $data['customer_id'] !== '' && $data['customer_id'] !== null
			? (int) $data['customer_id']
			: null;
		if ( $customer_id !== null && $customer_id <= 0 ) {
			$customer_id = null;
		}

		$cart_subtotal = null;
		if ( array_key_exists( 'cart_subtotal', $data ) && $data['cart_subtotal'] !== null && $data['cart_subtotal'] !== '' ) {
			$cart_subtotal = (float) $data['cart_subtotal'];
		}

		$currency = isset( $data['currency'] ) && is_string( $data['currency'] ) && $data['currency'] !== ''
			? $data['currency']
			: null;

		if ( isset( $data['items'] ) && ! is_array( $data['items'] ) ) {
			throw new InvalidArgumentException( 'items must be an array.' );
		}
		if ( isset( $data['metadata'] ) && ! is_array( $data['metadata'] ) ) {
			throw new InvalidArgumentException( 'metadata must be an array.' );
		}

		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$meta  = isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? $data['metadata'] : array();

		return new self( $customer_id, $cart_subtotal, $currency, $items, $meta );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'customer_id'   => $this->customer_id,
			'cart_subtotal' => $this->cart_subtotal,
			'currency'      => $this->currency,
			'items'         => $this->items,
			'metadata'      => $this->metadata,
		);
	}

	public function get_customer_id(): ?int {
		return $this->customer_id;
	}

	public function get_cart_subtotal(): ?float {
		return $this->cart_subtotal;
	}

	public function get_currency(): ?string {
		return $this->currency;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_items(): array {
		return $this->items;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_metadata(): array {
		return $this->metadata;
	}
}
