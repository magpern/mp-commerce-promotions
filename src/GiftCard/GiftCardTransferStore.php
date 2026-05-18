<?php
/**
 * Transfer linkage between voided and replacement gift cards (no plain codes).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardTransferStore {

	public const OPTION_KEY = 'mp_cp_gift_card_transfer_links';

	private const MAX_ENTRIES = 500;

	/**
	 * @param array{new_gift_card_id: int, recipient_email: string, initiated_by: string, transferred_at: string} $meta
	 */
	public function record_transfer( int $old_gift_card_id, array $meta ): void {
		if ( $old_gift_card_id <= 0 || ! isset( $meta['new_gift_card_id'] ) ) {
			return;
		}

		$new_id = (int) $meta['new_gift_card_id'];
		if ( $new_id <= 0 ) {
			return;
		}

		$all = $this->load_all();

		$old_key = (string) $old_gift_card_id;
		$all[ $old_key ] = array(
			'new_gift_card_id' => (string) $new_id,
			'recipient_email'  => sanitize_email( (string) ( $meta['recipient_email'] ?? '' ) ),
			'initiated_by'     => sanitize_key( (string) ( $meta['initiated_by'] ?? '' ) ),
			'transferred_at'   => (string) ( $meta['transferred_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);

		$all[ (string) $new_id ] = array(
			'from_gift_card_id' => $old_key,
			'initiated_by'      => sanitize_key( (string) ( $meta['initiated_by'] ?? '' ) ),
			'transferred_at'    => (string) ( $meta['transferred_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);

		if ( count( $all ) > self::MAX_ENTRIES ) {
			$all = array_slice( $all, -self::MAX_ENTRIES, null, true );
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $all, false );
		}
	}

	public function get_replacement_id( int $old_gift_card_id ): ?int {
		$all = $this->load_all();
		$row = $all[ (string) $old_gift_card_id ] ?? null;
		if ( ! is_array( $row ) || ! isset( $row['new_gift_card_id'] ) ) {
			return null;
		}

		$id = (int) $row['new_gift_card_id'];

		return $id > 0 ? $id : null;
	}

	public function get_source_id( int $new_gift_card_id ): ?int {
		$all = $this->load_all();
		$row = $all[ (string) $new_gift_card_id ] ?? null;
		if ( ! is_array( $row ) || ! isset( $row['from_gift_card_id'] ) ) {
			return null;
		}

		$id = (int) $row['from_gift_card_id'];

		return $id > 0 ? $id : null;
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
		foreach ( $raw as $key => $row ) {
			if ( is_int( $key ) ) {
				$key = (string) $key;
			}
			if ( ! is_string( $key ) || $key === '' || ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			foreach ( $row as $field => $value ) {
				if ( is_string( $field ) && is_string( $value ) && $value !== '' ) {
					$clean[ $field ] = $value;
				}
			}
			if ( $clean !== array() ) {
				$out[ $key ] = $clean;
			}
		}

		return $out;
	}
}
