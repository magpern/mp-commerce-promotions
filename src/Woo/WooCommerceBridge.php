<?php
/**
 * WooCommerce integration boundary (detection only; no discount hooks yet).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class WooCommerceBridge {

	private bool $available = false;

	private ?CartContextBuilder $cart_context_builder = null;

	public function init(): void {
		$this->available = class_exists( \WooCommerce::class, false )
			&& function_exists( 'WC' );
	}

	public function is_available(): bool {
		return $this->available;
	}

	public function set_cart_context_builder( ?CartContextBuilder $builder ): void {
		$this->cart_context_builder = $builder;
	}

	public function get_cart_context_builder(): ?CartContextBuilder {
		return $this->cart_context_builder;
	}
}
