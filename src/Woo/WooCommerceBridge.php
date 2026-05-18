<?php
/**
 * WooCommerce integration boundary: detection, hook registration, service wiring.
 *
 * Registered WooCommerce hooks (priorities unchanged unless noted):
 *
 * Cart fee (negative fee discount):
 * - `woocommerce_cart_calculate_fees` → CartPromotionApplier::apply (priority 20)
 *
 * Free gift line price:
 * - `woocommerce_before_calculate_totals` → CartPromotionApplier::zero_free_gift_line_prices (priority 20)
 *
 * Checkout recording:
 * - `woocommerce_checkout_create_order` → OrderPromotionRecorder::record_on_order_create (10, 2 args)
 *
 * Order reversal (usage_count / redemption status):
 * - `woocommerce_order_status_cancelled` → OrderPromotionRecorder::on_order_status_reversal (10, 2)
 * - `woocommerce_order_status_failed` → OrderPromotionRecorder::on_order_status_reversal (10, 2)
 * - `woocommerce_order_status_refunded` → OrderPromotionRecorder::on_order_status_reversal (10, 2)
 * - `woocommerce_order_status_processing` / `completed` → OrderPromotionRecorder::restore_on_order_paid_status (10, 2)
 * - `woocommerce_before_trash_order` → OrderPromotionRecorder::on_woocommerce_before_trash_order (10, 2)
 * - `woocommerce_before_delete_order` → OrderPromotionRecorder::on_woocommerce_before_delete_order (10, 2)
 * - `before_trash_post` → OrderPromotionRecorder::on_before_trash_post_for_reversal (10, 1)
 * - `before_delete_post` → OrderPromotionRecorder::on_before_delete_post_for_reversal (10, 2)
 *
 * Promotion code in standard coupon field (virtual coupon, 0 native WC discount):
 * - `woocommerce_get_shop_coupon_data` → PromotionCodeCouponBridge::filter_shop_coupon_data (10, 2)
 * - `woocommerce_coupon_is_valid` → PromotionCodeCouponBridge::filter_coupon_is_valid (10, 3)
 *
 * Cart/Checkout Blocks: discounts use the hooks above during `WC()->cart` recalculation
 * (Store API cart/checkout). Block checkout recording uses `woocommerce_store_api_checkout_order_processed`.
 * `cart_checkout_blocks` declared in `WooCompatibility`. Optional `BlocksHookAudit` when
 * `WP_DEBUG` and `mp_cp_blocks_hook_debug` are enabled.
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

	private bool $cart_gift_price_hook_registered = false;

	private bool $cart_line_discount_hook_registered = false;

	private ?OrderPromotionRecorder $order_promotion_recorder = null;

	private bool $order_checkout_hook_registered = false;

	private bool $order_reversal_hooks_registered = false;

	private bool $order_restore_hooks_registered = false;

	private ?PromotionCodeCouponBridge $promotion_code_coupon_bridge = null;

	private bool $promotion_code_coupon_hooks_registered = false;

	/**
	 * Detect WooCommerce availability (call once during plugin bootstrap).
	 */
	public function init(): void {
		$this->available = class_exists( \WooCommerce::class, false )
			&& function_exists( 'WC' );
	}

	/**
	 * Whether WooCommerce is loaded.
	 */
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

		if ( $this->cart_promotion_applier !== null && $this->available ) {
			$this->register_cart_fee_hook();
		}
	}

	public function get_cart_promotion_applier(): ?CartPromotionApplier {
		return $this->cart_promotion_applier;
	}

	public function set_order_promotion_recorder( ?OrderPromotionRecorder $recorder ): void {
		$this->order_promotion_recorder = $recorder;

		if ( $this->order_promotion_recorder !== null && $this->available ) {
			$this->register_order_checkout_hook();
			$this->register_order_reversal_hooks();
			$this->register_order_restore_hooks();
		}
	}

	public function get_order_promotion_recorder(): ?OrderPromotionRecorder {
		return $this->order_promotion_recorder;
	}

	public function set_promotion_code_coupon_bridge( ?PromotionCodeCouponBridge $bridge ): void {
		$this->promotion_code_coupon_bridge = $bridge;

		if ( $this->promotion_code_coupon_bridge !== null && $this->available ) {
			$this->register_promotion_code_coupon_hooks();
		}
	}

	public function get_promotion_code_coupon_bridge(): ?PromotionCodeCouponBridge {
		return $this->promotion_code_coupon_bridge;
	}

	/**
	 * Cart negative-fee application during totals calculation.
	 */
	private function register_cart_fee_hook(): void {
		if ( $this->cart_fee_hook_registered || $this->cart_promotion_applier === null ) {
			return;
		}

		add_action(
			'woocommerce_cart_calculate_fees',
			array( $this->cart_promotion_applier, 'apply' ),
			20
		);
		$this->cart_fee_hook_registered = true;

		if ( ! $this->cart_line_discount_hook_registered ) {
			add_action(
				'woocommerce_before_calculate_totals',
				array( $this->cart_promotion_applier, 'prepare_line_discount_cycle' ),
				15
			);
			$this->cart_line_discount_hook_registered = true;
		}

		if ( ! $this->cart_gift_price_hook_registered ) {
			add_action(
				'woocommerce_before_calculate_totals',
				array( $this->cart_promotion_applier, 'zero_free_gift_line_prices' ),
				20
			);
			$this->cart_gift_price_hook_registered = true;
		}
	}

	/**
	 * Persist session promotion to order meta, redemptions, and audit on checkout.
	 */
	private function register_order_checkout_hook(): void {
		if ( $this->order_checkout_hook_registered || $this->order_promotion_recorder === null ) {
			return;
		}

		add_action(
			'woocommerce_checkout_create_order',
			array( $this->order_promotion_recorder, 'record_on_order_create' ),
			10,
			2
		);
		add_action(
			'woocommerce_checkout_order_processed',
			array( $this->order_promotion_recorder, 'record_on_checkout_processed' ),
			20,
			2
		);
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			array( $this->order_promotion_recorder, 'record_on_order_create' ),
			10,
			1
		);
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			array( $this->order_promotion_recorder, 'record_on_checkout_processed' ),
			20,
			1
		);
		$this->order_checkout_hook_registered = true;
	}

	/**
	 * Reverse redemption and decrement usage when orders are cancelled, failed, refunded, or removed.
	 */
	private function register_order_reversal_hooks(): void {
		if ( $this->order_reversal_hooks_registered || $this->order_promotion_recorder === null ) {
			return;
		}

		$recorder = $this->order_promotion_recorder;

		add_action( 'woocommerce_order_status_cancelled', array( $recorder, 'on_order_status_reversal' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed', array( $recorder, 'on_order_status_reversal' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( $recorder, 'on_order_status_reversal' ), 10, 2 );

		add_action( 'woocommerce_before_trash_order', array( $recorder, 'on_woocommerce_before_trash_order' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order', array( $recorder, 'on_woocommerce_before_delete_order' ), 10, 2 );

		add_action( 'before_trash_post', array( $recorder, 'on_before_trash_post_for_reversal' ), 10, 1 );
		add_action( 'before_delete_post', array( $recorder, 'on_before_delete_post_for_reversal' ), 10, 2 );

		$this->order_reversal_hooks_registered = true;
	}

	/**
	 * Restore reversed redemptions when orders re-enter processing or completed.
	 */
	private function register_order_restore_hooks(): void {
		if ( $this->order_restore_hooks_registered || $this->order_promotion_recorder === null ) {
			return;
		}

		$recorder = $this->order_promotion_recorder;

		add_action( 'woocommerce_order_status_processing', array( $recorder, 'restore_on_order_paid_status' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $recorder, 'restore_on_order_paid_status' ), 10, 2 );

		$this->order_restore_hooks_registered = true;
	}

	/**
	 * Virtual coupon bridge so promotion codes work in the WooCommerce coupon field.
	 */
	private function register_promotion_code_coupon_hooks(): void {
		if ( $this->promotion_code_coupon_hooks_registered || $this->promotion_code_coupon_bridge === null ) {
			return;
		}

		$this->promotion_code_coupon_bridge->register_hooks();
		$this->promotion_code_coupon_hooks_registered = true;
	}
}
