<?php
/**
 * Order meta for gift cards generated from product purchases.
 *
 * Full gift card codes are never persisted here — only masked last4 and delivery metadata.
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
	 * @return list<array<string, mixed>>
	 */
	public static function get_generated( $order ): array {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}

		$raw = $order->get_meta( self::META_GENERATED, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sanitized = self::sanitize_row_from_storage( $row );
			if ( $sanitized !== null ) {
				$out[] = $sanitized;
			}
		}

		return $out;
	}

	/**
	 * @param \WC_Order $order
	 * @param list<array<string, mixed>> $rows
	 */
	public static function set_generated( $order, array $rows ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$stored = array();
		foreach ( $rows as $row ) {
			$sanitized = self::sanitize_row_for_storage( $row );
			if ( $sanitized !== null ) {
				$stored[] = $sanitized;
			}
		}

		$order->update_meta_data( self::META_GENERATED, wp_json_encode( array_values( $stored ) ) );
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function has_slot( $order, int $order_item_id, int $unit_index ): bool {
		foreach ( self::get_generated( $order ) as $row ) {
			if ( (int) ( $row['order_item_id'] ?? 0 ) === $order_item_id && (int) ( $row['unit_index'] ?? 0 ) === $unit_index ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param \WC_Order $order
	 * @return array<string, mixed>|null
	 */
	public static function find_row_by_gift_card_id( $order, int $gift_card_id ): ?array {
		foreach ( self::get_generated( $order ) as $row ) {
			if ( (int) ( $row['gift_card_id'] ?? 0 ) === $gift_card_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @param \WC_Order $order
	 * @param array<string, mixed> $patch
	 */
	public static function update_row( $order, int $gift_card_id, array $patch ): bool {
		$rows    = self::get_generated( $order );
		$updated = false;

		foreach ( $rows as $index => $row ) {
			if ( (int) ( $row['gift_card_id'] ?? 0 ) !== $gift_card_id ) {
				continue;
			}
			$rows[ $index ] = self::sanitize_row_for_storage( array_merge( $row, $patch ) ) ?? $row;
			$updated        = true;
			break;
		}

		if ( $updated ) {
			self::set_generated( $order, $rows );
		}

		return $updated;
	}

	/**
	 * Replace a generated row (e.g. after reissue).
	 *
	 * @param \WC_Order $order
	 * @param array<string, mixed> $new_row
	 */
	public static function replace_row( $order, int $old_gift_card_id, array $new_row ): bool {
		$rows    = self::get_generated( $order );
		$updated = false;

		foreach ( $rows as $index => $row ) {
			if ( (int) ( $row['gift_card_id'] ?? 0 ) !== $old_gift_card_id ) {
				continue;
			}
			$rows[ $index ] = self::sanitize_row_for_storage( $new_row ) ?? $row;
			$updated        = true;
			break;
		}

		if ( $updated ) {
			self::set_generated( $order, $rows );
		}

		return $updated;
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

	/**
	 * Build a storage row from a newly issued card (no plain code).
	 *
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	public static function row_from_card(
		GiftCard $card,
		int $order_item_id,
		int $unit_index,
		string $delivery_status = GiftCardDeliveryStatus::PENDING,
		array $overrides = array()
	): array {
		$last4 = $card->get_code_last4();

		$row = array(
			'gift_card_id'     => (int) $card->get_id(),
			'order_item_id'    => $order_item_id,
			'unit_index'       => $unit_index,
			'code_last4'       => $last4,
			'masked_code'      => self::masked_code( $last4 ),
			'amount'           => $card->get_initial_amount(),
			'currency'         => $card->get_currency(),
			'status'           => $card->get_status(),
			'delivery_status'  => $delivery_status,
		);

		return array_merge( $row, $overrides );
	}

	public static function masked_code( string $last4 ): string {
		$last4 = preg_replace( '/\D/', '', $last4 ) ?? '';
		$last4 = substr( $last4, -4 );
		if ( strlen( $last4 ) < 4 ) {
			$last4 = str_pad( $last4, 4, '0', STR_PAD_LEFT );
		}

		return '****' . $last4;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null
	 */
	public static function sanitize_row_for_storage( array $row ): ?array {
		$card_id = isset( $row['gift_card_id'] ) ? (int) $row['gift_card_id'] : 0;
		if ( $card_id <= 0 ) {
			return null;
		}

		$last4 = isset( $row['code_last4'] ) ? (string) $row['code_last4'] : '';
		if ( $last4 === '' && isset( $row['masked_code'] ) ) {
			$masked = (string) $row['masked_code'];
			if ( str_starts_with( $masked, '****' ) ) {
				$last4 = substr( $masked, 4 );
			}
		}

		$delivery = isset( $row['delivery_status'] ) ? (string) $row['delivery_status'] : GiftCardDeliveryStatus::UNKNOWN;
		if ( ! GiftCardDeliveryStatus::is_valid( $delivery ) ) {
			$delivery = GiftCardDeliveryStatus::UNKNOWN;
		}

		$clean = array(
			'gift_card_id'    => $card_id,
			'order_item_id'   => (int) ( $row['order_item_id'] ?? 0 ),
			'unit_index'      => (int) ( $row['unit_index'] ?? 0 ),
			'code_last4'      => $last4,
			'masked_code'     => isset( $row['masked_code'] ) && (string) $row['masked_code'] !== ''
				? (string) $row['masked_code']
				: self::masked_code( $last4 ),
			'amount'          => GiftCard::money( (float) ( $row['amount'] ?? 0 ) ),
			'currency'        => GiftCardCurrency::normalize( (string) ( $row['currency'] ?? '' ) ),
			'status'          => (string) ( $row['status'] ?? GiftCard::STATUS_ACTIVE ),
			'delivery_status' => $delivery,
		);

		if ( isset( $row['delivered_to'] ) && (string) $row['delivered_to'] !== '' ) {
			$clean['delivered_to'] = sanitize_email( (string) $row['delivered_to'] );
		}
		if ( isset( $row['delivered_at'] ) && (string) $row['delivered_at'] !== '' ) {
			$clean['delivered_at'] = (string) $row['delivered_at'];
		}
		if ( isset( $row['delivery_error'] ) && (string) $row['delivery_error'] !== '' ) {
			$clean['delivery_error'] = sanitize_text_field( (string) $row['delivery_error'] );
		}
		if ( isset( $row['reissued_from_gift_card_id'] ) && (int) $row['reissued_from_gift_card_id'] > 0 ) {
			$clean['reissued_from_gift_card_id'] = (int) $row['reissued_from_gift_card_id'];
		}

		return $clean;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null
	 */
	private static function sanitize_row_from_storage( array $row ): ?array {
		unset( $row['plain_code'] );
		$sanitized = self::sanitize_row_for_storage( $row );
		if ( $sanitized === null ) {
			return null;
		}
		if ( ! isset( $row['delivery_status'] ) || (string) $row['delivery_status'] === '' ) {
			$sanitized['delivery_status'] = GiftCardDeliveryStatus::UNKNOWN;
		}

		return $sanitized;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	public static function strip_plain_codes( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			unset( $row['plain_code'] );
			$sanitized = self::sanitize_row_for_storage( $row );
			if ( $sanitized !== null ) {
				$out[] = $sanitized;
			}
		}

		return $out;
	}

	public static function row_contains_plain_code( array $row ): bool {
		return isset( $row['plain_code'] ) && is_string( $row['plain_code'] ) && $row['plain_code'] !== '';
	}
}
