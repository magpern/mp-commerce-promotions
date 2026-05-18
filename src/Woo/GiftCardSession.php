<?php
/**
 * WooCommerce session payload for applied gift card credit.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class GiftCardSession {

	public const SESSION_KEY = 'mp_cp_applied_gift_card';

	/**
	 * @return array{gift_card_id: int, code_last4: string, applied_amount: float}|null
	 */
	public static function get(): ?array {
		$raw = CartSessionHelper::get( self::SESSION_KEY );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$id = isset( $raw['gift_card_id'] ) ? (int) $raw['gift_card_id'] : 0;
		if ( $id <= 0 ) {
			return null;
		}

		return array(
			'gift_card_id'    => $id,
			'code_last4'      => isset( $raw['code_last4'] ) ? (string) $raw['code_last4'] : '',
			'applied_amount'  => GiftCardAmount::money( (float) ( $raw['applied_amount'] ?? 0 ) ),
		);
	}

	/**
	 * @param array{gift_card_id: int, code_last4: string, applied_amount: float} $payload
	 */
	public static function set( array $payload ): void {
		CartSessionHelper::set(
			self::SESSION_KEY,
			array(
				'gift_card_id'   => (int) $payload['gift_card_id'],
				'code_last4'     => (string) $payload['code_last4'],
				'applied_amount' => GiftCardAmount::money( (float) $payload['applied_amount'] ),
			)
		);
	}

	public static function clear(): void {
		CartSessionHelper::clear( self::SESSION_KEY );
	}
}
