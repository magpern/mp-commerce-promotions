<?php
/**
 * Storefront gift card amounts: multi-currency conversion and display rounding.
 *
 * Product meta stores bounds in WooCommerce base currency. WOOCS (and similar)
 * switch the storefront currency without changing those stored values.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardStorefrontAmounts {

	/**
	 * Round up to the nearest whole ten (45 → 50, 192 → 200).
	 */
	public static function round_up_to_nearest_ten( float $amount ): float {
		if ( $amount <= 0 ) {
			return GiftCard::money( $amount );
		}

		return GiftCard::money( ceil( $amount / 10 ) * 10 );
	}

	public static function base_currency(): string {
		if ( function_exists( 'get_option' ) ) {
			$code = get_option( 'woocommerce_currency' );
			if ( is_string( $code ) && $code !== '' ) {
				return GiftCardCurrency::normalize( $code );
			}
		}

		return GiftCardCurrency::store_currency();
	}

	public static function display_currency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$code = get_woocommerce_currency();
			if ( is_string( $code ) && $code !== '' ) {
				return GiftCardCurrency::normalize( $code );
			}
		}

		return self::base_currency();
	}

	public static function needs_conversion(): bool {
		return self::display_currency() !== self::base_currency();
	}

	public static function convert_base_to_display( float $base_amount ): float {
		$base_amount = GiftCard::money( $base_amount );
		if ( $base_amount <= 0 || ! self::needs_conversion() ) {
			return $base_amount;
		}

		if ( function_exists( 'apply_filters' ) ) {
			/** @var mixed $converted */
			$converted = apply_filters( 'woocs_convert_price', $base_amount, false );
			if ( is_numeric( $converted ) ) {
				return GiftCard::money( (float) $converted );
			}
		}

		$woocs = self::woocs_instance();
		if ( $woocs !== null && method_exists( $woocs, 'woocs_exchange_value' ) ) {
			return GiftCard::money( (float) $woocs->woocs_exchange_value( $base_amount ) );
		}

		return $base_amount;
	}

	public static function convert_display_to_base( float $display_amount ): float {
		$display_amount = GiftCard::money( $display_amount );
		if ( $display_amount <= 0 || ! self::needs_conversion() ) {
			return $display_amount;
		}

		$rate = self::display_currency_rate();
		if ( $rate <= 0 ) {
			return $display_amount;
		}

		// Keep extra precision for WC line prices — GiftCard::money() would truncate
		// (e.g. 10.4348 → 10.43) and WOOCS would show 119.95 kr instead of 120 kr.
		return round( $display_amount / $rate, self::base_price_precision() );
	}

	public static function display_min( float $base_min ): float {
		if ( $base_min <= 0 ) {
			return 0.0;
		}

		return self::round_up_to_nearest_ten( self::convert_base_to_display( $base_min ) );
	}

	public static function display_suggested( float $base_amount ): float {
		return self::round_up_to_nearest_ten( self::convert_base_to_display( $base_amount ) );
	}

	public static function display_max( ?float $base_max ): ?float {
		if ( $base_max === null ) {
			return null;
		}

		$converted = self::convert_base_to_display( $base_max );

		return $converted > 0 ? $converted : null;
	}

	public static function display_default( ?float $base_default ): ?float {
		if ( $base_default === null || $base_default <= 0 ) {
			return null;
		}

		$converted = self::convert_base_to_display( $base_default );

		return $converted > 0 ? $converted : null;
	}

	/**
	 * Bounds and chips for the active storefront currency.
	 *
	 * @param array{
	 *   sells: bool,
	 *   amount_mode: string,
	 *   fixed_amount: float,
	 *   expiry_days: ?int,
	 *   recipient_mode: string,
	 *   min_amount: float,
	 *   max_amount: ?float,
	 *   suggested_amounts: list<float>,
	 *   default_amount: ?float
	 * } $config
	 * @return array<string, mixed>
	 */
	public static function storefront_config( array $config ): array {
		$min = (float) ( $config['min_amount'] ?? 0 );
		$max = $config['max_amount'] ?? null;
		$max = is_numeric( $max ) ? (float) $max : null;

		$default = $config['default_amount'] ?? null;
		$default = is_numeric( $default ) ? (float) $default : null;

		$suggested = isset( $config['suggested_amounts'] ) && is_array( $config['suggested_amounts'] )
			? $config['suggested_amounts']
			: array();

		$display_suggested = array();
		foreach ( $suggested as $amount ) {
			if ( ! is_numeric( $amount ) ) {
				continue;
			}
			$value = self::display_suggested( (float) $amount );
			if ( $value > 0 ) {
				$display_suggested[] = $value;
			}
		}
		$display_suggested = array_values( array_unique( $display_suggested ) );
		sort( $display_suggested, SORT_NUMERIC );

		$config['min_amount']        = self::display_min( $min );
		$config['max_amount']        = self::display_max( $max );
		$config['default_amount']    = self::display_default( $default );
		$config['suggested_amounts'] = $display_suggested;

		return $config;
	}

	private static function woocs_instance(): ?object {
		if ( isset( $GLOBALS['WOOCS'] ) && is_object( $GLOBALS['WOOCS'] ) ) {
			return $GLOBALS['WOOCS'];
		}

		return null;
	}

	private static function display_currency_rate(): float {
		$woocs = self::woocs_instance();
		if ( $woocs !== null && method_exists( $woocs, 'get_currencies' ) ) {
			$currencies = $woocs->get_currencies();
			$currency   = self::display_currency();
			if ( isset( $currencies[ $currency ]['rate'] ) && is_numeric( $currencies[ $currency ]['rate'] ) ) {
				return (float) $currencies[ $currency ]['rate'];
			}
		}

		return 0.0;
	}

	/**
	 * Decimals for storing gift card line prices in base currency before WOOCS converts them back for display.
	 */
	private static function base_price_precision(): int {
		$woocs = self::woocs_instance();
		if ( $woocs !== null && method_exists( $woocs, 'get_currency_price_num_decimals' ) ) {
			$display_decimals = (int) $woocs->get_currency_price_num_decimals(
				self::display_currency(),
				function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2
			);

			return max( 4, $display_decimals + 2 );
		}

		if ( function_exists( 'wc_get_price_decimals' ) ) {
			return max( 4, (int) wc_get_price_decimals() + 2 );
		}

		return 4;
	}
}
