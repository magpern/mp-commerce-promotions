<?php
/**
 * Pending scheduled gift card deliveries (no gift_card_id until fulfillment).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardPendingDeliveryState {

	public const META_PENDING = '_mp_cp_pending_gift_card_deliveries';

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function get_pending( $order ): array {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}

		$raw = $order->get_meta( self::META_PENDING, true );
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
			$sanitized = self::sanitize_row( $row );
			if ( $sanitized !== null ) {
				$out[] = $sanitized;
			}
		}

		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	public static function set_pending( $order, array $rows ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$stored = array();
		foreach ( $rows as $row ) {
			$sanitized = self::sanitize_row( $row );
			if ( $sanitized !== null ) {
				$stored[] = $sanitized;
			}
		}

		$order->update_meta_data( self::META_PENDING, wp_json_encode( array_values( $stored ) ) );
	}

	public static function has_slot( $order, int $order_item_id, int $unit_index ): bool {
		foreach ( self::get_pending( $order ) as $row ) {
			if ( (int) ( $row['order_item_id'] ?? 0 ) === $order_item_id && (int) ( $row['unit_index'] ?? 0 ) === $unit_index ) {
				return true;
			}
		}

		return false;
	}

	public static function slot_fulfilled( $order, int $order_item_id, int $unit_index ): bool {
		return GiftCardGeneratedOrderState::has_slot( $order, $order_item_id, $unit_index )
			|| self::has_slot( $order, $order_item_id, $unit_index );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function add_pending( $order, array $row ): void {
		$rows   = self::get_pending( $order );
		$rows[] = $row;
		self::set_pending( $order, $rows );
	}

	/**
	 * @param array<string, mixed> $patch
	 */
	public static function update_row( $order, int $order_item_id, int $unit_index, array $patch ): bool {
		$rows    = self::get_pending( $order );
		$updated = false;

		foreach ( $rows as $index => $row ) {
			if ( (int) ( $row['order_item_id'] ?? 0 ) !== $order_item_id || (int) ( $row['unit_index'] ?? 0 ) !== $unit_index ) {
				continue;
			}
			$rows[ $index ] = self::sanitize_row( array_merge( $row, $patch ) ) ?? $row;
			$updated        = true;
			break;
		}

		if ( $updated ) {
			self::set_pending( $order, $rows );
		}

		return $updated;
	}

	public static function remove_slot( $order, int $order_item_id, int $unit_index ): void {
		$rows = self::get_pending( $order );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( (int) ( $row['order_item_id'] ?? 0 ) === $order_item_id && (int) ( $row['unit_index'] ?? 0 ) === $unit_index ) {
				continue;
			}
			$out[] = $row;
		}
		self::set_pending( $order, $out );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	public static function build_row(
		int $order_item_id,
		int $unit_index,
		float $amount,
		string $currency,
		array $delivery,
		string $paid_at,
		?int $expiry_days,
		array $overrides = array()
	): array {
		$delivery = GiftCardLineItemMeta::normalize_array( $delivery );

		$row = array(
			'order_item_id'    => $order_item_id,
			'unit_index'       => $unit_index,
			'amount'           => GiftCard::money( $amount ),
			'currency'         => GiftCardCurrency::normalize( $currency ),
			'recipient_email'  => $delivery['recipient_email'],
			'recipient_name'   => $delivery['recipient_name'],
			'message'          => $delivery['message'],
			'delivery_timing'  => $delivery['delivery_timing'],
			'scheduled_for'    => $delivery['scheduled_for'],
			'delivery_status'  => GiftCardDeliveryStatus::PENDING_SCHEDULED,
			'paid_at'          => $paid_at,
			'expiry_days'      => $expiry_days,
		);

		return array_merge( $row, $overrides );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null
	 */
	public static function sanitize_row( array $row ): ?array {
		unset( $row['plain_code'] );

		$item_id = (int) ( $row['order_item_id'] ?? 0 );
		if ( $item_id <= 0 ) {
			return null;
		}

		$status = isset( $row['delivery_status'] ) ? (string) $row['delivery_status'] : GiftCardDeliveryStatus::PENDING_SCHEDULED;
		if ( ! GiftCardDeliveryStatus::is_valid( $status ) ) {
			$status = GiftCardDeliveryStatus::PENDING_SCHEDULED;
		}

		$clean = array(
			'order_item_id'   => $item_id,
			'unit_index'      => (int) ( $row['unit_index'] ?? 0 ),
			'amount'          => GiftCard::money( (float) ( $row['amount'] ?? 0 ) ),
			'currency'        => GiftCardCurrency::normalize( (string) ( $row['currency'] ?? '' ) ),
			'recipient_email' => sanitize_email( (string) ( $row['recipient_email'] ?? '' ) ),
			'recipient_name'  => sanitize_text_field( (string) ( $row['recipient_name'] ?? '' ) ),
			'message'         => sanitize_textarea_field( (string) ( $row['message'] ?? '' ) ),
			'delivery_timing' => (string) ( $row['delivery_timing'] ?? GiftCardLineItemMeta::TIMING_SEND_ON_DATE ),
			'scheduled_for'   => sanitize_text_field( (string) ( $row['scheduled_for'] ?? '' ) ),
			'delivery_status' => $status,
			'paid_at'         => sanitize_text_field( (string) ( $row['paid_at'] ?? '' ) ),
		);

		if ( isset( $row['expiry_days'] ) && $row['expiry_days'] !== '' && $row['expiry_days'] !== null ) {
			$clean['expiry_days'] = max( 0, (int) $row['expiry_days'] );
		}
		if ( isset( $row['delivery_error'] ) && (string) $row['delivery_error'] !== '' ) {
			$clean['delivery_error'] = sanitize_text_field( (string) $row['delivery_error'] );
		}

		return $clean;
	}

	public static function is_due( array $row, ?\DateTimeImmutable $now = null ): bool {
		if ( (string) ( $row['delivery_status'] ?? '' ) !== GiftCardDeliveryStatus::PENDING_SCHEDULED ) {
			return false;
		}

		$scheduled = (string) ( $row['scheduled_for'] ?? '' );
		if ( $scheduled === '' ) {
			return true;
		}

		$target = \DateTimeImmutable::createFromFormat( 'Y-m-d', $scheduled );
		if ( $target === false ) {
			return true;
		}

		$now = $now ?? self::site_today();
		return $target <= $now;
	}

	private static function site_today(): \DateTimeImmutable {
		if ( function_exists( 'current_time' ) ) {
			$mysql = current_time( 'Y-m-d' );
			$dt    = \DateTimeImmutable::createFromFormat( 'Y-m-d', $mysql );
			if ( $dt !== false ) {
				return $dt;
			}
		}

		return new \DateTimeImmutable( 'today' );
	}
}
