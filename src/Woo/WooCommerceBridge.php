<?php
/**
 * WooCommerce integration boundary (detection, cart context, cart fee hook).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class WooCommerceBridge {

	private bool $available = false;

	private ?CartContextBuilder $cart_context_builder = null;

	private ?CartPromotionApplier $cart_promotion_applier = null;

	private bool $cart_fee_hook_registered = false;

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

	public function set_cart_promotion_applier( ?CartPromotionApplier $applier ): void {
		$this->cart_promotion_applier = $applier;

		if (
			$this->cart_promotion_applier !== null
			&& $this->available
			&& ! $this->cart_fee_hook_registered
		) {
			add_action(
				'woocommerce_cart_calculate_fees',
				array( $this->cart_promotion_applier, 'apply' ),
				20
			);
			$this->cart_fee_hook_registered = true;
		}
	}

	public function get_cart_promotion_applier(): ?CartPromotionApplier {
		return $this->cart_promotion_applier;
	}
}
