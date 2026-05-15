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

			$cid = (int) $order->get_customer_id();
			$customer_id = $cid > 0 ? $cid : null;

			$currency = $order->get_currency();
			if ( ! is_string( $currency ) || $currency === '' ) {
				$currency = null;
			}

			$now = current_time( 'mysql' );

			$redemption = new Redemption(
				null,
				$promotion_id,
				$order_id,
				$customer_id,
				null,
				$discount,
				$currency,
				'recorded',
				$now,
				$now
			);

			$new_id = $this->redemptions->insert( $redemption );
			if ( $new_id <= 0 ) {
				return;
			}

			$order->update_meta_data( '_mp_cp_promotion_id', (string) $promotion_id );
			$order->update_meta_data( '_mp_cp_promotion_uuid', sanitize_text_field( $uuid ) );
			$order->update_meta_data( '_mp_cp_promotion_name', sanitize_text_field( $name ) );
			$discount_meta = function_exists( 'wc_format_decimal' )
				? wc_format_decimal( $discount )
				: (string) $discount;
			$order->update_meta_data( '_mp_cp_discount_amount', $discount_meta );
			$order->update_meta_data( '_mp_cp_action_type', sanitize_text_field( $action_type ) );
			$order->update_meta_data( '_mp_cp_percentage', function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $percentage ) : (string) $percentage );

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
}
