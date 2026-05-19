<?php
/**
 * WooCommerce product meta for gift card products.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardProductMeta {

	public const META_SELLS = '_mp_cp_sells_gift_card';

	public const META_AMOUNT_MODE = '_mp_cp_gift_card_amount_mode';

	public const META_FIXED_AMOUNT = '_mp_cp_gift_card_fixed_amount';

	public const META_EXPIRY_DAYS = '_mp_cp_gift_card_expiry_days';

	public const META_RECIPIENT_MODE = '_mp_cp_gift_card_recipient_mode';

	public const META_MIN_AMOUNT = '_mp_cp_gift_card_min_amount';

	public const META_MAX_AMOUNT = '_mp_cp_gift_card_max_amount';

	public const META_SUGGESTED_AMOUNTS = '_mp_cp_gift_card_suggested_amounts';

	public const META_DEFAULT_AMOUNT = '_mp_cp_gift_card_default_amount';

	public const AMOUNT_MODE_PRODUCT_PRICE = 'product_price';

	public const AMOUNT_MODE_FIXED = 'fixed_amount';

	public const AMOUNT_MODE_CUSTOMER_AMOUNT = 'customer_amount';

	public const RECIPIENT_PURCHASER_ONLY = 'purchaser_only';

	public const RECIPIENT_EMAIL = 'recipient_email';

	public const RECIPIENT_EMAIL_AND_MESSAGE = 'recipient_email_and_message';

	public const VALUE_YES = 'yes';

	public const VALUE_NO = 'no';

	/**
	 * @return list<string>
	 */
	public static function amount_modes(): array {
		return array(
			self::AMOUNT_MODE_PRODUCT_PRICE,
			self::AMOUNT_MODE_FIXED,
			self::AMOUNT_MODE_CUSTOMER_AMOUNT,
		);
	}

	/**
	 * @return array{
	 *   sells: bool,
	 *   amount_mode: string,
	 *   fixed_amount: float,
	 *   expiry_days: ?int,
	 *   recipient_mode: string,
	 *   min_amount: float,
	 *   max_amount: ?float,
	 *   suggested_amounts: list<float>,
	 *   default_amount: ?float
	 * }
	 */
	public static function read( int $product_id ): array {
		if ( $product_id <= 0 ) {
			return self::defaults();
		}

		$sells = get_post_meta( $product_id, self::META_SELLS, true );

		$amount_mode = (string) get_post_meta( $product_id, self::META_AMOUNT_MODE, true );
		if ( $amount_mode === '' ) {
			$amount_mode = self::AMOUNT_MODE_PRODUCT_PRICE;
		}

		$fixed = get_post_meta( $product_id, self::META_FIXED_AMOUNT, true );
		$fixed = is_numeric( $fixed ) ? (float) $fixed : 0.0;

		$expiry_raw = get_post_meta( $product_id, self::META_EXPIRY_DAYS, true );
		$expiry     = null;
		if ( $expiry_raw !== '' && $expiry_raw !== null && is_numeric( $expiry_raw ) ) {
			$expiry = max( 0, (int) $expiry_raw );
			if ( $expiry <= 0 ) {
				$expiry = null;
			}
		}

		$recipient = (string) get_post_meta( $product_id, self::META_RECIPIENT_MODE, true );
		if ( $recipient === '' ) {
			$recipient = self::RECIPIENT_PURCHASER_ONLY;
		}

		$min_raw = get_post_meta( $product_id, self::META_MIN_AMOUNT, true );
		$min     = is_numeric( $min_raw ) ? GiftCard::money( (float) $min_raw ) : 0.0;

		$max_raw = get_post_meta( $product_id, self::META_MAX_AMOUNT, true );
		$max     = null;
		if ( $max_raw !== '' && $max_raw !== null && is_numeric( $max_raw ) ) {
			$max = GiftCard::money( (float) $max_raw );
			if ( $max <= 0 ) {
				$max = null;
			}
		}

		$suggested_raw = (string) get_post_meta( $product_id, self::META_SUGGESTED_AMOUNTS, true );
		$suggested     = GiftCardProductCustomerAmount::parse_suggested_amounts( $suggested_raw );

		$default_raw = get_post_meta( $product_id, self::META_DEFAULT_AMOUNT, true );
		$default     = null;
		if ( $default_raw !== '' && $default_raw !== null && is_numeric( $default_raw ) ) {
			$val = GiftCard::money( (float) $default_raw );
			if ( $val > 0 ) {
				$default = $val;
			}
		}

		return array(
			'sells'              => $sells === self::VALUE_YES,
			'amount_mode'        => $amount_mode,
			'fixed_amount'       => GiftCard::money( $fixed ),
			'expiry_days'        => $expiry,
			'recipient_mode'     => $recipient,
			'min_amount'         => $min,
			'max_amount'         => $max,
			'suggested_amounts'  => $suggested,
			'default_amount'     => $default,
		);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function save( int $product_id, array $input ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		$sells = isset( $input['sells'] ) && (string) $input['sells'] === self::VALUE_YES;
		update_post_meta( $product_id, self::META_SELLS, $sells ? self::VALUE_YES : self::VALUE_NO );

		if ( ! $sells ) {
			return;
		}

		$mode = isset( $input['amount_mode'] ) ? sanitize_key( (string) $input['amount_mode'] ) : self::AMOUNT_MODE_PRODUCT_PRICE;
		if ( ! in_array( $mode, self::amount_modes(), true ) ) {
			$mode = self::AMOUNT_MODE_PRODUCT_PRICE;
		}
		update_post_meta( $product_id, self::META_AMOUNT_MODE, $mode );

		$fixed = isset( $input['fixed_amount'] ) ? (float) $input['fixed_amount'] : 0.0;
		update_post_meta( $product_id, self::META_FIXED_AMOUNT, GiftCard::money( max( 0.0, $fixed ) ) );

		$expiry = isset( $input['expiry_days'] ) && $input['expiry_days'] !== '' && $input['expiry_days'] !== null
			? max( 0, (int) $input['expiry_days'] )
			: '';
		update_post_meta( $product_id, self::META_EXPIRY_DAYS, $expiry === 0 ? '' : (string) $expiry );

		$recipient = isset( $input['recipient_mode'] ) ? sanitize_key( (string) $input['recipient_mode'] ) : self::RECIPIENT_PURCHASER_ONLY;
		if ( ! in_array( $recipient, self::recipient_modes(), true ) ) {
			$recipient = self::RECIPIENT_PURCHASER_ONLY;
		}
		update_post_meta( $product_id, self::META_RECIPIENT_MODE, $recipient );

		if ( $mode === self::AMOUNT_MODE_CUSTOMER_AMOUNT ) {
			$normalized = GiftCardProductCustomerAmount::normalize_admin_settings( $input );
			update_post_meta( $product_id, self::META_MIN_AMOUNT, (string) $normalized['min_amount'] );
			update_post_meta(
				$product_id,
				self::META_MAX_AMOUNT,
				$normalized['max_amount'] !== null ? (string) $normalized['max_amount'] : ''
			);
			update_post_meta(
				$product_id,
				self::META_SUGGESTED_AMOUNTS,
				GiftCardProductCustomerAmount::suggested_amounts_storage_value( $normalized )
			);
			update_post_meta(
				$product_id,
				self::META_DEFAULT_AMOUNT,
				$normalized['default_amount'] !== null ? (string) $normalized['default_amount'] : ''
			);
		}
	}

	/**
	 * @return list<string>
	 */
	public static function recipient_modes(): array {
		return array(
			self::RECIPIENT_PURCHASER_ONLY,
			self::RECIPIENT_EMAIL,
			self::RECIPIENT_EMAIL_AND_MESSAGE,
		);
	}

	public static function allows_recipient_fields( string $mode ): bool {
		return in_array( $mode, array( self::RECIPIENT_EMAIL, self::RECIPIENT_EMAIL_AND_MESSAGE ), true );
	}

	/**
	 * @return array{
	 *   sells: bool,
	 *   amount_mode: string,
	 *   fixed_amount: float,
	 *   expiry_days: ?int,
	 *   recipient_mode: string,
	 *   min_amount: float,
	 *   max_amount: ?float,
	 *   suggested_amounts: list<float>,
	 *   default_amount: ?float
	 * }
	 */
	private static function defaults(): array {
		return array(
			'sells'             => false,
			'amount_mode'       => self::AMOUNT_MODE_PRODUCT_PRICE,
			'fixed_amount'      => 0.0,
			'expiry_days'       => null,
			'recipient_mode'    => self::RECIPIENT_PURCHASER_ONLY,
			'min_amount'        => 0.0,
			'max_amount'        => null,
			'suggested_amounts' => array(),
			'default_amount'    => null,
		);
	}
}
