<?php
/**
 * WooCommerce integration boundary (detection, cart context, cart fee hook, checkout recording).
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

	private ?OrderPromotionRecorder $order_promotion_recorder = null;

	private bool $order_checkout_hook_registered = false;

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

	public function set_order_promotion_recorder( ?OrderPromotionRecorder $recorder ): void {
		$this->order_promotion_recorder = $recorder;

		if (
			$this->order_promotion_recorder !== null
			&& $this->available
			&& ! $this->order_checkout_hook_registered
		) {
			add_action(
				'woocommerce_checkout_create_order',
				array( $this->order_promotion_recorder, 'record_on_order_create' ),
				10,
				2
			);
			$this->order_checkout_hook_registered = true;
		}
	}

	public function get_order_promotion_recorder(): ?OrderPromotionRecorder {
		return $this->order_promotion_recorder;
	}
}
