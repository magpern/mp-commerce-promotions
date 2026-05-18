<?php
/**
 * Delivery metadata for manually issued gift cards (no plain codes).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardManualDeliveryStore {

	public const OPTION_KEY = 'mp_cp_gift_card_manual_delivery';

	private const MAX_ENTRIES = 500;

	/**
	 * @param array{
	 *   delivery_status: string,
	 *   delivered_to?: string,
	 *   delivered_at?: string,
	 *   delivery_error?: string
	 * } $meta
	 */
	public function record( int $gift_card_id, array $meta ): void {
		if ( $gift_card_id <= 0 ) {
			return;
		}

		$allowed = array(
			'delivery_status',
			'delivered_to',
			'delivered_at',
			'delivery_error',
		);
		$row = array();
		foreach ( $allowed as $key ) {
			if ( ! isset( $meta[ $key ] ) ) {
				continue;
			}
			$value = $meta[ $key ];
			if ( ! is_string( $value ) || $value === '' ) {
				continue;
			}
			$row[ $key ] = $value;
		}

		if ( $row === array() || ! isset( $row['delivery_status'] ) ) {
			return;
		}

		if ( ! GiftCardDeliveryStatus::is_valid( $row['delivery_status'] ) ) {
			return;
		}

		$all = $this->load_all();
		$all[ (string) $gift_card_id ] = $row; // string keys avoid int-key loss in get_option round-trip
		if ( count( $all ) > self::MAX_ENTRIES ) {
			$all = array_slice( $all, -self::MAX_ENTRIES, null, true );
		}

		$this->save_all( $all );
	}

	/**
	 * @return array{
	 *   delivery_status: string,
	 *   delivered_to?: string,
	 *   delivered_at?: string,
	 *   delivery_error?: string
	 * }|null
	 */
	public function get( int $gift_card_id ): ?array {
		if ( $gift_card_id <= 0 ) {
			return null;
		}

		$all = $this->load_all();
		$row = $all[ (string) $gift_card_id ] ?? null;
		if ( ! is_array( $row ) || ! isset( $row['delivery_status'] ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function load_all(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $id => $row ) {
			if ( is_int( $id ) ) {
				$id = (string) $id;
			}
			if ( ! is_string( $id ) || $id === '' || ! is_array( $row ) ) {
				continue;
			}
			$status = isset( $row['delivery_status'] ) ? (string) $row['delivery_status'] : '';
			if ( $status === '' || ! GiftCardDeliveryStatus::is_valid( $status ) ) {
				continue;
			}
			$clean = array( 'delivery_status' => $status );
			foreach ( array( 'delivered_to', 'delivered_at', 'delivery_error' ) as $key ) {
				if ( isset( $row[ $key ] ) && is_string( $row[ $key ] ) && $row[ $key ] !== '' ) {
					$clean[ $key ] = $row[ $key ];
				}
			}
			$out[ $id ] = $clean;
		}

		return $out;
	}

	/**
	 * @param array<string, array<string, string>> $all
	 */
	private function save_all( array $all ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $all, false );
		}
	}
}
