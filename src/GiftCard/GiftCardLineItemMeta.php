<?php
/**
 * Cart/order line item meta for gift card recipient delivery.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardLineItemMeta {

	public const KEY_RECIPIENT_EMAIL = '_mp_cp_gc_recipient_email';

	public const KEY_RECIPIENT_NAME = '_mp_cp_gc_recipient_name';

	public const KEY_MESSAGE = '_mp_cp_gc_message';

	public const KEY_DELIVERY_TIMING = '_mp_cp_gc_delivery_timing';

	public const KEY_SCHEDULED_FOR = '_mp_cp_gc_scheduled_for';

	public const TIMING_SEND_NOW = 'send_now';

	public const TIMING_SEND_ON_DATE = 'send_on_date';

	/**
	 * @return array{
	 *   recipient_email: string,
	 *   recipient_name: string,
	 *   message: string,
	 *   delivery_timing: string,
	 *   scheduled_for: string
	 * }
	 */
	public static function read_from_cart_item( array $cart_item ): array {
		return self::normalize_array( $cart_item['mp_cp_gift_card_delivery'] ?? array() );
	}

	/**
	 * @return array{
	 *   recipient_email: string,
	 *   recipient_name: string,
	 *   message: string,
	 *   delivery_timing: string,
	 *   scheduled_for: string
	 * }
	 */
	public static function read_from_order_item( $item ): array {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return self::empty();
		}

		return self::normalize_array(
			array(
				'recipient_email'  => (string) $item->get_meta( self::KEY_RECIPIENT_EMAIL, true ),
				'recipient_name'   => (string) $item->get_meta( self::KEY_RECIPIENT_NAME, true ),
				'message'          => (string) $item->get_meta( self::KEY_MESSAGE, true ),
				'delivery_timing'  => (string) $item->get_meta( self::KEY_DELIVERY_TIMING, true ),
				'scheduled_for'    => (string) $item->get_meta( self::KEY_SCHEDULED_FOR, true ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function write_to_order_item( $item, array $data ): void {
		if ( ! is_object( $item ) || ! method_exists( $item, 'update_meta_data' ) ) {
			return;
		}

		$normalized = self::normalize_array( $data );
		$item->update_meta_data( self::KEY_RECIPIENT_EMAIL, $normalized['recipient_email'] );
		$item->update_meta_data( self::KEY_RECIPIENT_NAME, $normalized['recipient_name'] );
		$item->update_meta_data( self::KEY_MESSAGE, $normalized['message'] );
		$item->update_meta_data( self::KEY_DELIVERY_TIMING, $normalized['delivery_timing'] );
		$item->update_meta_data( self::KEY_SCHEDULED_FOR, $normalized['scheduled_for'] );
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array{
	 *   recipient_email: string,
	 *   recipient_name: string,
	 *   message: string,
	 *   delivery_timing: string,
	 *   scheduled_for: string
	 * }
	 */
	public static function normalize_array( array $raw ): array {
		$timing = isset( $raw['delivery_timing'] ) ? sanitize_key( (string) $raw['delivery_timing'] ) : self::TIMING_SEND_NOW;
		if ( ! in_array( $timing, array( self::TIMING_SEND_NOW, self::TIMING_SEND_ON_DATE ), true ) ) {
			$timing = self::TIMING_SEND_NOW;
		}

		return array(
			'recipient_email' => isset( $raw['recipient_email'] ) ? sanitize_email( (string) $raw['recipient_email'] ) : '',
			'recipient_name'  => isset( $raw['recipient_name'] ) ? sanitize_text_field( (string) $raw['recipient_name'] ) : '',
			'message'         => isset( $raw['message'] ) ? sanitize_textarea_field( (string) $raw['message'] ) : '',
			'delivery_timing' => $timing,
			'scheduled_for'   => isset( $raw['scheduled_for'] ) ? sanitize_text_field( (string) $raw['scheduled_for'] ) : '',
		);
	}

	/**
	 * @return array{recipient_email: string, recipient_name: string, message: string, delivery_timing: string, scheduled_for: string}
	 */
	public static function empty(): array {
		return array(
			'recipient_email' => '',
			'recipient_name'  => '',
			'message'         => '',
			'delivery_timing' => self::TIMING_SEND_NOW,
			'scheduled_for'   => '',
		);
	}
}
