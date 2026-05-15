<?php
/**
 * Persists applied cart promotion to order meta, redemptions table, and audit on checkout.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Service\AuditLogger;
use Throwable;

final class OrderPromotionRecorder {

	private const META_REDEMPTION_RECORDED = '_mp_cp_redemption_recorded';

	private const META_REDEMPTION_REVERSED = '_mp_cp_redemption_reversed';

	private const META_VALUE_YES = 'yes';

	private RedemptionRepository $redemptions;

	private PromotionRepository $promotions;

	private AuditLogger $audit;

	public function __construct(
		RedemptionRepository $redemptions,
		PromotionRepository $promotions,
		AuditLogger $audit
	) {
		$this->redemptions = $redemptions;
		$this->promotions  = $promotions;
		$this->audit       = $audit;
	}

	/**
	 * @param mixed $order WooCommerce order object.
	 * @param mixed $data  Checkout posted data (unused).
	 */
	public function record_on_order_create( $order, $data = null ): void {
		try {
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				return;
			}

			if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
				return;
			}

			$raw = WC()->session->get( CartPromotionApplier::SESSION_KEY );
			if ( ! is_array( $raw ) ) {
				return;
			}

			$promotion_id = isset( $raw['promotion_id'] ) ? (int) $raw['promotion_id'] : 0;
			if ( $promotion_id <= 0 ) {
				return;
			}

			$uuid = isset( $raw['promotion_uuid'] ) ? (string) $raw['promotion_uuid'] : '';
			$name = isset( $raw['promotion_name'] ) ? (string) $raw['promotion_name'] : '';
			if ( ! isset( $raw['discount_amount'] ) || ! is_numeric( $raw['discount_amount'] ) ) {
				return;
			}

			$discount = (float) $raw['discount_amount'];
			if ( $discount < 0 ) {
				return;
			}

			$action_type = isset( $raw['action_type'] ) ? (string) $raw['action_type'] : '';
			if ( $action_type !== 'percentage_discount' ) {
				return;
			}

			$percentage = isset( $raw['percentage'] ) && is_numeric( $raw['percentage'] ) ? (float) $raw['percentage'] : null;
			if ( $percentage === null || $percentage <= 0 ) {
				return;
			}

			$order_id = (int) $order->get_id();
			if ( $order_id <= 0 ) {
				return;
			}

			$cid           = (int) $order->get_customer_id();
			$customer_id   = $cid > 0 ? $cid : null;
			$currency      = $order->get_currency();
			$currency      = is_string( $currency ) && $currency !== '' ? $currency : null;
			$now           = current_time( 'mysql' );

			if ( $this->redemptions->exists_for_order_and_promotion( $order_id, $promotion_id ) ) {
				$this->apply_promotion_meta_to_order(
					$order,
					$promotion_id,
					$uuid,
					$name,
					$discount,
					$action_type,
					$percentage
				);
				$order->update_meta_data( self::META_REDEMPTION_RECORDED, self::META_VALUE_YES );
				$order->save();
				$this->clear_applied_promotion_session();
				return;
			}

			$redemption = new Redemption(
				null,
				$promotion_id,
				$order_id,
				$customer_id,
				null,
				$discount,
				$currency,
				Redemption::STATUS_RECORDED,
				$now,
				$now
			);

			$new_id = $this->redemptions->insert( $redemption );
			if ( $new_id <= 0 ) {
				return;
			}

			$this->apply_promotion_meta_to_order(
				$order,
				$promotion_id,
				$uuid,
				$name,
				$discount,
				$action_type,
				$percentage
			);
			$order->update_meta_data( self::META_REDEMPTION_RECORDED, self::META_VALUE_YES );

			$promotion = $this->promotions->find( $promotion_id );
			if ( $promotion !== null ) {
				$updated = $promotion->with_usage_count( $promotion->get_usage_count() + 1 );
				$this->promotions->update( $updated );
			}

			$this->audit->log(
				'promotion.redeemed',
				$promotion_id,
				array(
					'promotion_id'    => $promotion_id,
					'order_id'        => $order_id,
					'discount_amount' => $discount,
					'action_type'     => $action_type,
				),
				null
			);

			$order->save();
			$this->clear_applied_promotion_session();
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[mp-commerce-promotions] OrderPromotionRecorder: %s',
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Reverse a recorded redemption when the order is cancelled, failed, refunded, trashed, or deleted.
	 * Idempotent via `_mp_cp_redemption_reversed` = yes.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function reverse_for_order( int $order_id ): void {
		try {
			if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
				return;
			}

			$order = wc_get_order( $order_id );
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				return;
			}

			if ( $order->get_meta( self::META_REDEMPTION_REVERSED, true ) === self::META_VALUE_YES ) {
				return;
			}

			if ( $order->get_meta( self::META_REDEMPTION_RECORDED, true ) !== self::META_VALUE_YES ) {
				return;
			}

			$promotion_id = (int) $order->get_meta( '_mp_cp_promotion_id', true );
			if ( $promotion_id <= 0 ) {
				return;
			}

			$redemption = $this->redemptions->find_recorded_for_order_and_promotion( $order_id, $promotion_id );
			if ( $redemption === null ) {
				return;
			}

			$reversed = $redemption->with_status( Redemption::STATUS_REVERSED );
			if ( ! $this->redemptions->update( $reversed ) ) {
				return;
			}

			$promotion = $this->promotions->find( $promotion_id );
			if ( $promotion !== null ) {
				$new_usage = max( 0, $promotion->get_usage_count() - 1 );
				$this->promotions->update( $promotion->with_usage_count( $new_usage ) );
			}

			$order->update_meta_data( self::META_REDEMPTION_REVERSED, self::META_VALUE_YES );
			$this->audit->log(
				'promotion.redemption_reversed',
				$promotion_id,
				array(
					'promotion_id' => $promotion_id,
					'order_id'     => $order_id,
				),
				null
			);
			$order->save();
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[mp-commerce-promotions] OrderPromotionRecorder::reverse_for_order: %s',
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * @param int|\WC_Order $order_id Order ID or order object (WooCommerce passes both shapes across hooks).
	 * @param mixed         $order    Optional WC_Order (second argument on status / trash / delete hooks).
	 */
	public function on_order_status_reversal( $order_id, $order = null ): void {
		$id = $this->resolve_order_id_from_hook_args( $order_id, $order );
		if ( $id > 0 ) {
			$this->reverse_for_order( $id );
		}
	}

	/**
	 * @param int|\WC_Order $order_id Order ID or order object.
	 * @param mixed         $order    Optional WC_Order.
	 */
	public function on_woocommerce_before_trash_order( $order_id, $order = null ): void {
		$id = $this->resolve_order_id_from_hook_args( $order_id, $order );
		if ( $id > 0 ) {
			$this->reverse_for_order( $id );
		}
	}

	/**
	 * @param int|\WC_Order $order_id Order ID or order object.
	 * @param mixed         $order    Optional WC_Order.
	 */
	public function on_woocommerce_before_delete_order( $order_id, $order = null ): void {
		$id = $this->resolve_order_id_from_hook_args( $order_id, $order );
		if ( $id > 0 ) {
			$this->reverse_for_order( $id );
		}
	}

	/**
	 * CPT fallback when the order is a `shop_order` post (legacy storage).
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_before_trash_post_for_reversal( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'shop_order' ) {
			return;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $post_id );
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
			return;
		}

		$this->reverse_for_order( $post_id );
	}

	/**
	 * CPT fallback when the order post is permanently deleted.
	 *
	 * @param int          $post_id Post ID.
	 * @param \WP_Post|null $post   Post object (optional).
	 */
	public function on_before_delete_post_for_reversal( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		if ( $post instanceof \WP_Post ) {
			if ( $post->post_type !== 'shop_order' ) {
				return;
			}
		} else {
			$p = get_post( $post_id );
			if ( ! $p instanceof \WP_Post || $p->post_type !== 'shop_order' ) {
				return;
			}
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $post_id );
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
			return;
		}

		$this->reverse_for_order( $post_id );
	}

	/**
	 * @param mixed $first  Typically order ID (int) or WC_Order.
	 * @param mixed $second Typically WC_Order or null.
	 */
	private function resolve_order_id_from_hook_args( $first, $second ): int {
		if ( is_object( $first ) && is_a( $first, 'WC_Order', false ) ) {
			return (int) $first->get_id();
		}
		if ( is_object( $second ) && is_a( $second, 'WC_Order', false ) ) {
			return (int) $second->get_id();
		}
		if ( is_numeric( $first ) ) {
			return (int) $first;
		}

		return 0;
	}

	/**
	 * @param \WC_Order $order
	 */
	private function apply_promotion_meta_to_order(
		$order,
		int $promotion_id,
		string $uuid,
		string $name,
		float $discount,
		string $action_type,
		float $percentage
	): void {
		$order->update_meta_data( '_mp_cp_promotion_id', (string) $promotion_id );
		$order->update_meta_data( '_mp_cp_promotion_uuid', sanitize_text_field( $uuid ) );
		$order->update_meta_data( '_mp_cp_promotion_name', sanitize_text_field( $name ) );
		$discount_meta = function_exists( 'wc_format_decimal' )
			? wc_format_decimal( $discount )
			: (string) $discount;
		$order->update_meta_data( '_mp_cp_discount_amount', $discount_meta );
		$order->update_meta_data( '_mp_cp_action_type', sanitize_text_field( $action_type ) );
		$order->update_meta_data(
			'_mp_cp_percentage',
			function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $percentage ) : (string) $percentage
		);
	}

	private function clear_applied_promotion_session(): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return;
		}

		$session = WC()->session;
		if ( $session instanceof \ArrayAccess ) {
			unset( $session[ CartPromotionApplier::SESSION_KEY ] );
		}
		if ( method_exists( $session, 'set' ) ) {
			$session->set( CartPromotionApplier::SESSION_KEY, null );
		}
	}
}
