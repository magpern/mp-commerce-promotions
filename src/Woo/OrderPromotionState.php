<?php
/**
 * Centralized order meta for applied promotions and redemption lifecycle flags.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class OrderPromotionState {

	public const META_REDEMPTION_RECORDED = '_mp_cp_redemption_recorded';

	public const META_REDEMPTION_REVERSED = '_mp_cp_redemption_reversed';

	public const META_APPLIED_PROMOTIONS = '_mp_cp_applied_promotions';

	public const META_VALUE_YES = 'yes';

	/**
	 * @param \WC_Order $order WooCommerce order.
	 * @return list<array<string, mixed>>
	 */
	public static function get_applied_promotions( $order ): array {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}

		$raw = $order->get_meta( self::META_APPLIED_PROMOTIONS, true );
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$out = array();
				foreach ( $decoded as $row ) {
					if ( is_array( $row ) ) {
						$out[] = $row;
					}
				}

				return $out;
			}
		}

		$legacy_id = (int) $order->get_meta( '_mp_cp_promotion_id', true );
		if ( $legacy_id <= 0 ) {
			return array();
		}

		return array(
			array(
				'promotion_id'    => $legacy_id,
				'promotion_uuid'  => (string) $order->get_meta( '_mp_cp_promotion_uuid', true ),
				'promotion_name'  => (string) $order->get_meta( '_mp_cp_promotion_name', true ),
				'discount_amount' => (float) $order->get_meta( '_mp_cp_discount_amount', true ),
				'action_type'     => (string) $order->get_meta( '_mp_cp_action_type', true ),
			),
		);
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 */
	public static function has_recorded_promotions( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		return $order->get_meta( self::META_REDEMPTION_RECORDED, true ) === self::META_VALUE_YES;
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 */
	public static function is_reversed( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		return $order->get_meta( self::META_REDEMPTION_REVERSED, true ) === self::META_VALUE_YES;
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 */
	public static function mark_recorded( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$order->update_meta_data( self::META_REDEMPTION_RECORDED, self::META_VALUE_YES );
		$order->delete_meta_data( self::META_REDEMPTION_REVERSED );
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 */
	public static function mark_reversed( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$order->update_meta_data( self::META_REDEMPTION_REVERSED, self::META_VALUE_YES );
	}

	/**
	 * @param \WC_Order              $order
	 * @param list<array<string, mixed>> $summaries
	 */
	public static function save_applied_promotions( $order, array $summaries ): void {
		if ( $summaries === array() || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$json = wp_json_encode( $summaries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( is_string( $json ) ) {
			$order->update_meta_data( self::META_APPLIED_PROMOTIONS, $json );
		}
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 * @return list<int>
	 */
	public static function promotion_ids_from_order( $order ): array {
		$ids = array();
		foreach ( self::get_applied_promotions( $order ) as $row ) {
			$id = isset( $row['promotion_id'] ) ? (int) $row['promotion_id'] : 0;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$legacy_id = (int) $order->get_meta( '_mp_cp_promotion_id', true );
		if ( $legacy_id > 0 ) {
			$ids[] = $legacy_id;
		}

		return array_values( array_unique( $ids ) );
	}
}
