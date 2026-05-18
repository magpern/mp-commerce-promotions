<?php
/**
 * Order meta for gift cards generated from product purchases.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardGeneratedOrderState {

	public const META_GENERATED = '_mp_cp_generated_gift_cards';

	public const META_GENERATION_COMPLETE = '_mp_cp_gift_cards_generated';

	public const META_REVERSAL_HANDLED = '_mp_cp_gift_cards_reversal_handled';

	public const META_VALUE_YES = 'yes';

	/**
	 * @return list<array{gift_card_id: int, order_item_id: int, unit_index: int, plain_code?: string}>
	 */
	public static function get_generated( $order ): array {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}

		$raw = $order->get_meta( self::META_GENERATED, true );
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$out = array();
				foreach ( $decoded as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$card_id = isset( $row['gift_card_id'] ) ? (int) $row['gift_card_id'] : 0;
					if ( $card_id <= 0 ) {
						continue;
					}
					$entry = array(
						'gift_card_id'  => $card_id,
						'order_item_id' => (int) ( $row['order_item_id'] ?? 0 ),
						'unit_index'    => (int) ( $row['unit_index'] ?? 0 ),
					);
					if ( isset( $row['plain_code'] ) && is_string( $row['plain_code'] ) && $row['plain_code'] !== '' ) {
						$entry['plain_code'] = $row['plain_code'];
					}
					$out[] = $entry;
				}

				return $out;
			}
		}

		return array();
	}

	/**
	 * @param \WC_Order $order
	 * @param list<array{gift_card_id: int, order_item_id: int, unit_index: int, plain_code?: string}> $rows
	 */
	public static function set_generated( $order, array $rows ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$order->update_meta_data( self::META_GENERATED, wp_json_encode( array_values( $rows ) ) );
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function has_slot( $order, int $order_item_id, int $unit_index ): bool {
		foreach ( self::get_generated( $order ) as $row ) {
			if ( (int) $row['order_item_id'] === $order_item_id && (int) $row['unit_index'] === $unit_index ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function is_generation_complete( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		return $order->get_meta( self::META_GENERATION_COMPLETE, true ) === self::META_VALUE_YES;
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function mark_generation_complete( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$order->update_meta_data( self::META_GENERATION_COMPLETE, self::META_VALUE_YES );
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function is_reversal_handled( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		return $order->get_meta( self::META_REVERSAL_HANDLED, true ) === self::META_VALUE_YES;
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function mark_reversal_handled( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$order->update_meta_data( self::META_REVERSAL_HANDLED, self::META_VALUE_YES );
	}
}
