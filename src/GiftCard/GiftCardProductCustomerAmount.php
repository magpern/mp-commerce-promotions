<?php
/**
 * Customer-entered gift card amount: validation and catalog copy.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardProductCustomerAmount {

	public const CART_ITEM_KEY = 'mp_cp_gift_card_amount';

	public const ORDER_META_KEY = '_mp_cp_gift_card_amount';

	public const POST_FIELD = 'mp_cp_gift_card_customer_amount';

	/**
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
	 */
	public static function is_customer_amount_mode( array $config ): bool {
		return ( $config['amount_mode'] ?? '' ) === GiftCardProductMeta::AMOUNT_MODE_CUSTOMER_AMOUNT;
	}

	/**
	 * @return list<float>
	 */
	public static function parse_suggested_amounts( string $raw ): array {
		if ( trim( $raw ) === '' ) {
			return array();
		}

		$parts = preg_split( '/[\s,;]+/', $raw );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$amounts = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( $part === '' || ! is_numeric( $part ) ) {
				continue;
			}
			$value = GiftCard::money( (float) $part );
			if ( $value > 0 ) {
				$amounts[] = $value;
			}
		}

		$amounts = array_values( array_unique( $amounts ) );
		sort( $amounts, SORT_NUMERIC );

		return $amounts;
	}

	/**
	 * @param array<string, mixed> $input Raw admin save input.
	 * @return array{
	 *   min_amount: float,
	 *   max_amount: ?float,
	 *   suggested_amounts: list<float>,
	 *   default_amount: ?float,
	 *   errors: list<string>
	 * }
	 */
	public static function normalize_admin_settings( array $input ): array {
		$errors = array();

		$min_raw = isset( $input['min_amount'] ) ? trim( (string) $input['min_amount'] ) : '';
		$max_raw = isset( $input['max_amount'] ) ? trim( (string) $input['max_amount'] ) : '';
		$def_raw = isset( $input['default_amount'] ) ? trim( (string) $input['default_amount'] ) : '';
		$sug_raw = isset( $input['suggested_amounts'] ) ? (string) $input['suggested_amounts'] : '';

		$min = $min_raw !== '' && is_numeric( $min_raw ) ? GiftCard::money( (float) $min_raw ) : 0.0;
		if ( $min <= 0 ) {
			$errors[] = __( 'Minimum amount must be greater than zero.', 'mp-commerce-promotions' );
			$min = 0.01;
		}

		$max = null;
		if ( $max_raw !== '' ) {
			if ( ! is_numeric( $max_raw ) ) {
				$errors[] = __( 'Maximum amount must be a number.', 'mp-commerce-promotions' );
			} else {
				$max = GiftCard::money( (float) $max_raw );
				if ( $max < $min ) {
					$errors[] = __( 'Maximum amount must be greater than or equal to the minimum amount.', 'mp-commerce-promotions' );
					$max = $min;
				}
			}
		}

		$default = null;
		if ( $def_raw !== '' ) {
			if ( ! is_numeric( $def_raw ) ) {
				$errors[] = __( 'Default amount must be a number.', 'mp-commerce-promotions' );
			} else {
				$default = GiftCard::money( (float) $def_raw );
				if ( $default <= 0 ) {
					$errors[] = __( 'Default amount must be greater than zero.', 'mp-commerce-promotions' );
					$default = null;
				} elseif ( $default < $min || ( $max !== null && $default > $max ) ) {
					$errors[] = __( 'Default amount must be within the minimum and maximum range.', 'mp-commerce-promotions' );
					$default = null;
				}
			}
		}

		$suggested = self::parse_suggested_amounts( $sug_raw );
		foreach ( $suggested as $value ) {
			if ( $value < $min || ( $max !== null && $value > $max ) ) {
				$errors[] = __( 'Each suggested amount must be within the minimum and maximum range.', 'mp-commerce-promotions' );
				$suggested = array_values(
					array_filter(
						$suggested,
						static function ( float $v ) use ( $min, $max ): bool {
							if ( $v < $min ) {
								return false;
							}
							return $max === null || $v <= $max;
						}
					)
				);
				break;
			}
		}

		return array(
			'min_amount'          => $min,
			'max_amount'          => $max,
			'suggested_amounts'   => $suggested,
			'default_amount'      => $default,
			'errors'              => array_values( array_unique( $errors ) ),
		);
	}

	/**
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
	 */
	/**
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public static function storefront_config( array $config ): array {
		return GiftCardStorefrontAmounts::storefront_config( $config );
	}

	public static function validate_customer_amount( float $amount, array $config, bool $use_storefront_bounds = true ): ?string {
		if ( $use_storefront_bounds ) {
			$config = self::storefront_config( $config );
		}

		$amount = GiftCard::money( $amount );
		if ( $amount <= 0 ) {
			return __( 'Please enter a gift card amount greater than zero.', 'mp-commerce-promotions' );
		}

		$min = GiftCard::money( (float) ( $config['min_amount'] ?? 0 ) );
		if ( $min > 0 && $amount < $min ) {
			return sprintf(
				/* translators: %s: formatted minimum amount */
				__( 'Gift card amount must be at least %s.', 'mp-commerce-promotions' ),
				self::format_money( $min )
			);
		}

		$max = $config['max_amount'] ?? null;
		if ( $max !== null && is_numeric( $max ) ) {
			$max_val = GiftCard::money( (float) $max );
			if ( $max_val > 0 && $amount > $max_val ) {
				return sprintf(
					/* translators: %s: formatted maximum amount */
					__( 'Gift card amount must not exceed %s.', 'mp-commerce-promotions' ),
					self::format_money( $max_val )
				);
			}
		}

		return null;
	}

	/**
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
	 */
	public static function catalog_price_html( array $config ): string {
		$config = self::storefront_config( $config );
		$min    = GiftCard::money( (float) ( $config['min_amount'] ?? 0 ) );
		if ( $min > 0 ) {
			return sprintf(
				/* translators: %s: formatted minimum price */
				__( 'From %s', 'mp-commerce-promotions' ),
				self::format_money( $min )
			);
		}

		return __( 'Choose amount', 'mp-commerce-promotions' );
	}

	public static function format_money( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount ) );
		}

		return number_format( $amount, 2 );
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public static function read_amount_from_cart_item( array $cart_item ): ?float {
		if ( ! isset( $cart_item[ self::CART_ITEM_KEY ] ) ) {
			return null;
		}

		$raw = $cart_item[ self::CART_ITEM_KEY ];
		if ( ! is_numeric( $raw ) ) {
			return null;
		}

		$amount = GiftCard::money( (float) $raw );
		return $amount > 0 ? $amount : null;
	}

	/**
	 * @param object $item WC_Order_Item_Product
	 */
	public static function read_amount_from_order_item( $item ): ?float {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return null;
		}

		$raw = $item->get_meta( self::ORDER_META_KEY, true );
		if ( $raw === '' || $raw === null || ! is_numeric( $raw ) ) {
			return null;
		}

		$amount = GiftCard::money( (float) $raw );
		return $amount > 0 ? $amount : null;
	}

	/**
	 * @param object $item WC_Order_Item_Product
	 */
	public static function write_amount_to_order_item( $item, float $amount ): void {
		if ( ! is_object( $item ) || ! method_exists( $item, 'update_meta_data' ) ) {
			return;
		}

		$amount = GiftCard::money( $amount );
		$item->update_meta_data( self::ORDER_META_KEY, (string) $amount );
	}

	public static function order_item_display_value( float $amount ): string {
		return self::format_money( $amount );
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function suggested_amounts_storage_value( array $input ): string {
		$suggested = isset( $input['suggested_amounts'] ) && is_array( $input['suggested_amounts'] )
			? $input['suggested_amounts']
			: self::parse_suggested_amounts( (string) ( $input['suggested_amounts'] ?? '' ) );

		if ( $suggested === array() ) {
			return '';
		}

		return implode(
			',',
			array_map(
				static function ( float $v ): string {
					return (string) $v;
				},
				$suggested
			)
		);
	}
}
