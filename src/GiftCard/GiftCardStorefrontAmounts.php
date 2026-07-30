<?php
/**
 * Storefront gift card amounts: multi-currency conversion and display rounding.
 *
 * Product meta stores bounds in WooCommerce base currency. Built-in adapters
 * cover WOOCS and Universal Multicurrency; third-party switchers can hook the
 * conversion filters documented below.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardStorefrontAmounts {

	/**
	 * Convert a base-currency amount to the active storefront currency.
	 *
	 * Return a numeric amount to override built-in adapters (WOOCS, UMC). Return
	 * null to defer to the next provider.
	 *
	 * @param null|float|string $converted     Default null (defer).
	 * @param float             $base_amount   Amount in shop base currency.
	 * @param string            $display_code  Active storefront currency code.
	 * @param string            $base_code     Shop base currency code.
	 */
	public const FILTER_CONVERT_BASE_TO_DISPLAY = 'mp_cp_gift_card_convert_base_to_display';

	/**
	 * Convert a storefront-currency amount back to shop base currency.
	 *
	 * Return a numeric amount to override built-in adapters. Return null to defer.
	 *
	 * @param null|float|string $base_amount    Default null (defer).
	 * @param float             $display_amount Amount in the active storefront currency.
	 * @param string            $display_code   Active storefront currency code.
	 * @param string            $base_code      Shop base currency code.
	 */
	public const FILTER_CONVERT_DISPLAY_TO_BASE = 'mp_cp_gift_card_convert_display_to_base';

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

		$filtered = self::filter_convert_base_to_display( $base_amount );
		if ( $filtered !== null ) {
			return $filtered;
		}

		$woocs = self::woocs_instance();
		if ( $woocs !== null && method_exists( $woocs, 'woocs_exchange_value' ) ) {
			return GiftCard::money( (float) $woocs->woocs_exchange_value( $base_amount ) );
		}

		// Only trust the filter when it actually changes the amount. Test stubs for
		// apply_filters return the input unchanged, which would skip WOOCS conversion.
		if ( function_exists( 'apply_filters' ) ) {
			/** @var mixed $converted */
			$converted = apply_filters( 'woocs_convert_price', $base_amount, false );
			if ( is_numeric( $converted ) && GiftCard::money( (float) $converted ) !== $base_amount ) {
				return GiftCard::money( (float) $converted );
			}
		}

		$umc = self::umc_convert_base_to_display( $base_amount );
		if ( $umc !== null ) {
			return $umc;
		}

		return $base_amount;
	}

	public static function convert_display_to_base( float $display_amount ): float {
		$display_amount = GiftCard::money( $display_amount );
		if ( $display_amount <= 0 || ! self::needs_conversion() ) {
			return $display_amount;
		}

		$filtered = self::filter_convert_display_to_base( $display_amount );
		if ( $filtered !== null ) {
			return $filtered;
		}

		$rate = self::display_currency_rate();
		if ( $rate <= 0 ) {
			return $display_amount;
		}

		// Keep extra precision for WC line prices — GiftCard::money() would truncate
		// (e.g. 10.4348 → 10.43) and WOOCS would show 119.95 kr instead of 120 kr.
		return round( $display_amount / $rate, self::base_price_precision() );
	}

	/**
	 * Storefront input (e.g. 120 SEK) → ledger/base amount (e.g. 10.43 EUR).
	 */
	public static function base_amount_from_display( float $display_amount ): float {
		return GiftCard::money( self::convert_display_to_base( $display_amount ) );
	}

	/**
	 * Ledger/base amount formatted for the active storefront currency label.
	 */
	public static function display_amount_from_base( float $base_amount ): float {
		if ( ! self::needs_conversion() ) {
			return GiftCard::money( $base_amount );
		}

		return GiftCard::money( self::convert_base_to_display( $base_amount ) );
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

	private static function filter_convert_base_to_display( float $base_amount ): ?float {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}

		/** @var mixed $converted */
		$converted = apply_filters(
			self::FILTER_CONVERT_BASE_TO_DISPLAY,
			null,
			$base_amount,
			self::display_currency(),
			self::base_currency()
		);

		return self::normalize_filtered_amount( $converted );
	}

	private static function filter_convert_display_to_base( float $display_amount ): ?float {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}

		/** @var mixed $base_amount */
		$base_amount = apply_filters(
			self::FILTER_CONVERT_DISPLAY_TO_BASE,
			null,
			$display_amount,
			self::display_currency(),
			self::base_currency()
		);

		if ( $base_amount === null || $base_amount === '' || ! is_numeric( $base_amount ) ) {
			return null;
		}

		return round( (float) $base_amount, self::base_price_precision() );
	}

	/**
	 * @param mixed $amount Filter return value.
	 */
	private static function normalize_filtered_amount( $amount ): ?float {
		if ( $amount === null || $amount === '' || ! is_numeric( $amount ) ) {
			return null;
		}

		$value = GiftCard::money( (float) $amount );

		return $value > 0 ? $value : null;
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

		$umc_rate = self::umc_display_currency_rate();
		if ( $umc_rate > 0 ) {
			return $umc_rate;
		}

		return 0.0;
	}

	/**
	 * Decimals for storing gift card line prices in base currency before the active switcher converts them back for display.
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

		$umc_decimals = self::umc_display_currency_decimals();
		if ( $umc_decimals !== null ) {
			return max( 4, $umc_decimals + 2 );
		}

		if ( function_exists( 'wc_get_price_decimals' ) ) {
			return max( 4, (int) wc_get_price_decimals() + 2 );
		}

		return 4;
	}

	private static function umc_convert_base_to_display( float $base_amount ): ?float {
		$converter = self::umc_converter();
		if ( $converter === null ) {
			return null;
		}

		try {
			$converted = $converter->convert( $base_amount, self::display_currency() );

			return GiftCard::money( (float) $converted );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function umc_display_currency_rate(): float {
		if ( ! class_exists( \UMC\Settings::class ) ) {
			return 0.0;
		}

		$rate = ( new \UMC\Settings() )->get_rate( self::display_currency() );
		if ( $rate !== null && is_numeric( $rate ) && (float) $rate > 0 ) {
			return (float) $rate;
		}

		return 0.0;
	}

	private static function umc_display_currency_decimals(): ?int {
		if ( ! class_exists( \UMC\Settings::class ) ) {
			return null;
		}

		$config = ( new \UMC\Settings() )->get_currency_config( self::display_currency() );
		if ( ! is_array( $config ) || ! isset( $config['decimals'] ) || ! is_numeric( $config['decimals'] ) ) {
			return null;
		}

		return max( 0, (int) $config['decimals'] );
	}

	private static function umc_converter(): ?\UMC\Converter {
		static $converter   = null;
		static $resolved    = false;
		static $unavailable = false;

		if ( $resolved ) {
			return $unavailable ? null : $converter;
		}

		$resolved = true;

		if ( ! class_exists( \UMC\Converter::class )
			|| ! class_exists( \UMC\Settings::class )
			|| ! class_exists( \UMC\CurrencyRegistry::class )
			|| ! class_exists( \UMC\Currency::class )
			|| ! class_exists( \UMC\Rates\ManualRateProvider::class ) ) {
			$unavailable = true;

			return null;
		}

		$settings = new \UMC\Settings();
		$code     = self::base_currency();
		$decimals = function_exists( 'wc_get_price_decimals' )
			? max( 0, min( \UMC\Currency::MAX_DECIMALS, (int) wc_get_price_decimals() ) )
			: \UMC\Currency::DEFAULT_DECIMALS;
		$position = function_exists( 'get_option' )
			? (string) get_option( 'woocommerce_currency_pos', \UMC\Currency::DEFAULT_POSITION )
			: \UMC\Currency::DEFAULT_POSITION;

		if ( ! in_array( $position, \UMC\Currency::POSITIONS, true ) ) {
			$position = \UMC\Currency::DEFAULT_POSITION;
		}

		$base     = new \UMC\Currency( $code, $decimals, '', $position, true );
		$registry = new \UMC\CurrencyRegistry( $settings, $base );
		$rates    = new \UMC\Rates\ManualRateProvider( $settings, $registry->get_base_code() );
		$converter = new \UMC\Converter( $rates, $registry );

		return $converter;
	}
}
