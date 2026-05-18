<?php
/**
 * Order meta for customer store credit checkout redemptions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class StoreCreditOrderState {

	public const META_REDEMPTIONS = '_mp_cp_store_credit_redemptions';

	public const META_REDEMPTION_RECORDED = '_mp_cp_store_credit_redemption_recorded';

	public const META_REDEMPTION_REVERSED = '_mp_cp_store_credit_redemption_reversed';

	public const META_VALUE_YES = 'yes';

	/**
	 * @param \WC_Order $order
	 * @return list<array{account_id: int, amount: float, currency: string}>
	 */
	public static function get_redemptions( $order ): array {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}

		$raw = $order->get_meta( self::META_REDEMPTIONS, true );
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$out = array();
				foreach ( $decoded as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$id = isset( $row['account_id'] ) ? (int) $row['account_id'] : 0;
					if ( $id <= 0 ) {
						continue;
					}
					$out[] = array(
						'account_id' => $id,
						'amount'     => GiftCardAmount::money( (float) ( $row['amount'] ?? 0 ) ),
						'currency'   => isset( $row['currency'] ) ? strtoupper( (string) $row['currency'] ) : '',
					);
				}

				return $out;
			}
		}

		return array();
	}

	/**
	 * @param \WC_Order $order
	 * @param list<array{account_id: int, amount: float, currency: string}> $rows
	 */
	public static function set_redemptions( $order, array $rows ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$order->update_meta_data( self::META_REDEMPTIONS, wp_json_encode( array_values( $rows ) ) );
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function has_recorded( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		return $order->get_meta( self::META_REDEMPTION_RECORDED, true ) === self::META_VALUE_YES;
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function mark_recorded( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$order->update_meta_data( self::META_REDEMPTION_RECORDED, self::META_VALUE_YES );
		$order->delete_meta_data( self::META_REDEMPTION_REVERSED );
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function is_reversed( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		return $order->get_meta( self::META_REDEMPTION_REVERSED, true ) === self::META_VALUE_YES;
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function mark_reversed( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$order->update_meta_data( self::META_REDEMPTION_REVERSED, self::META_VALUE_YES );
	}
}
