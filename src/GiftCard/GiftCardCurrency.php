<?php
/**
 * WooCommerce-aligned gift card / store credit currency codes.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;

final class GiftCardCurrency {

	public const FILTER_ALLOWED = 'mp_cp_gift_card_allowed_currencies';

	/**
	 * Store currency (WooCommerce default).
	 */
	public static function store_currency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$code = get_woocommerce_currency();
			if ( is_string( $code ) && $code !== '' ) {
				return self::normalize( $code );
			}
		}

		return 'EUR';
	}

	/**
	 * All currencies registered with WooCommerce (code => label).
	 *
	 * @return array<string, string>
	 */
	public static function woocommerce_currencies(): array {
		if ( function_exists( 'get_woocommerce_currencies' ) ) {
			$all = get_woocommerce_currencies();
			if ( is_array( $all ) ) {
				$out = array();
				foreach ( $all as $code => $label ) {
					$normalized = self::normalize( (string) $code );
					if ( $normalized !== '' ) {
						$out[ $normalized ] = is_string( $label ) && $label !== '' ? $label : $normalized;
					}
				}
				if ( $out !== array() ) {
					return $out;
				}
			}
		}

		return array(
			'EUR' => 'Euro',
			'USD' => 'US dollar',
			'GBP' => 'Pound sterling',
		);
	}

	/**
	 * Allowed currencies for issuing / granting (after optional filter).
	 *
	 * Filter `mp_cp_gift_card_allowed_currencies` receives a list of currency codes.
	 * Return a subset to restrict the admin dropdown.
	 *
	 * @return array<string, string> code => label
	 */
	public static function allowed_currencies(): array {
		$all  = self::woocommerce_currencies();
		$codes = array_keys( $all );

		$filtered = $codes;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * @param list<string> $codes
			 */
			$filtered = apply_filters( self::FILTER_ALLOWED, $codes );
		}
		if ( ! is_array( $filtered ) ) {
			return $all;
		}

		$allowed = array();
		foreach ( $filtered as $code ) {
			$normalized = self::normalize( (string) $code );
			if ( $normalized !== '' && isset( $all[ $normalized ] ) ) {
				$allowed[ $normalized ] = $all[ $normalized ];
			}
		}

		if ( $allowed === array() ) {
			return $all;
		}

		return $allowed;
	}

	public static function normalize( string $currency ): string {
		return strtoupper( trim( $currency ) );
	}

	public static function is_allowed( string $currency ): bool {
		$currency = self::normalize( $currency );

		return $currency !== '' && isset( self::allowed_currencies()[ $currency ] );
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public static function validate( string $currency ): string {
		$currency = self::normalize( $currency );
		if ( $currency === '' ) {
			$currency = self::store_currency();
		}

		if ( ! self::is_allowed( $currency ) ) {
			$message = function_exists( '__' )
				? sprintf(
					/* translators: %s: currency code */
					__( 'Currency "%s" is not a valid WooCommerce currency for gift cards.', 'mp-commerce-promotions' ),
					$currency
				)
				: sprintf( 'Currency "%s" is not a valid WooCommerce currency for gift cards.', $currency );
			throw new InvalidArgumentException( $message );
		}

		return $currency;
	}
}
