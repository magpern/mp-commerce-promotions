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

	public const AMOUNT_MODE_PRODUCT_PRICE = 'product_price';

	public const AMOUNT_MODE_FIXED = 'fixed_amount';

	public const RECIPIENT_PURCHASER_ONLY = 'purchaser_only';

	public const RECIPIENT_EMAIL = 'recipient_email';

	public const RECIPIENT_EMAIL_AND_MESSAGE = 'recipient_email_and_message';

	public const VALUE_YES = 'yes';

	public const VALUE_NO = 'no';

	/**
	 * @return array{
	 *   sells: bool,
	 *   amount_mode: string,
	 *   fixed_amount: float,
	 *   expiry_days: ?int,
	 *   recipient_mode: string
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

		return array(
			'sells'           => $sells === self::VALUE_YES,
			'amount_mode'     => $amount_mode,
			'fixed_amount'    => GiftCard::money( $fixed ),
			'expiry_days'     => $expiry,
			'recipient_mode'  => $recipient,
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
		if ( ! in_array( $mode, array( self::AMOUNT_MODE_PRODUCT_PRICE, self::AMOUNT_MODE_FIXED ), true ) ) {
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
	}

	/**
	 * @return array{sells: bool, amount_mode: string, fixed_amount: float, expiry_days: ?int, recipient_mode: string}
	 */
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

	private static function defaults(): array {
		return array(
			'sells'          => false,
			'amount_mode'    => self::AMOUNT_MODE_PRODUCT_PRICE,
			'fixed_amount'   => 0.0,
			'expiry_days'    => null,
			'recipient_mode' => self::RECIPIENT_PURCHASER_ONLY,
		);
	}
}
