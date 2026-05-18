<?php
/**
 * WooCommerce session payload for applied customer store credit.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class StoreCreditSession {

	public const SESSION_KEY = 'mp_cp_applied_store_credit';

	/**
	 * @return array{account_id: int, applied_amount: float, currency: string}|null
	 */
	public static function get(): ?array {
		$raw = CartSessionHelper::get( self::SESSION_KEY );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$id = isset( $raw['account_id'] ) ? (int) $raw['account_id'] : 0;
		if ( $id <= 0 ) {
			return null;
		}

		return array(
			'account_id'     => $id,
			'applied_amount' => GiftCardAmount::money( (float) ( $raw['applied_amount'] ?? 0 ) ),
			'currency'       => isset( $raw['currency'] ) ? strtoupper( (string) $raw['currency'] ) : '',
		);
	}

	/**
	 * @param array{account_id: int, applied_amount: float, currency: string} $payload
	 */
	public static function set( array $payload ): void {
		CartSessionHelper::set(
			self::SESSION_KEY,
			array(
				'account_id'     => (int) $payload['account_id'],
				'applied_amount' => GiftCardAmount::money( (float) $payload['applied_amount'] ),
				'currency'       => strtoupper( (string) $payload['currency'] ),
			)
		);
	}

	public static function clear(): void {
		CartSessionHelper::clear( self::SESSION_KEY );
	}
}
