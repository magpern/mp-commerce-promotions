<?php
/**
 * Merchant-facing copy for gift card product admin fields.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardProductAdminHelper {

	/**
	 * @return array<string, string>
	 */
	public static function amount_mode_options(): array {
		return array(
			GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE => __( 'Product price', 'mp-commerce-promotions' ),
			GiftCardProductMeta::AMOUNT_MODE_FIXED         => __( 'Fixed amount', 'mp-commerce-promotions' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function recipient_mode_options(): array {
		return array(
			GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY       => __( 'Purchaser only', 'mp-commerce-promotions' ),
			GiftCardProductMeta::RECIPIENT_EMAIL                => __( 'Recipient email', 'mp-commerce-promotions' ),
			GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE    => __( 'Recipient email + personal message', 'mp-commerce-promotions' ),
		);
	}

	/**
	 * @param array{sells: bool, amount_mode: string, fixed_amount: float, expiry_days: ?int, recipient_mode: string} $config
	 */
	public static function amount_preview_text( array $config, float $product_price, string $currency = '' ): string {
		if ( ! $config['sells'] ) {
			return '';
		}

		$amount = $config['amount_mode'] === GiftCardProductMeta::AMOUNT_MODE_FIXED
			? GiftCard::money( $config['fixed_amount'] )
			: GiftCard::money( max( 0.0, $product_price ) );

		$formatted = function_exists( 'wc_price' )
			? wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency !== '' ? $currency : null ) ) )
			: number_format( $amount, 2 ) . ( $currency !== '' ? ' ' . $currency : '' );

		if ( $config['amount_mode'] === GiftCardProductMeta::AMOUNT_MODE_FIXED ) {
			return sprintf(
				/* translators: %s: formatted amount */
				__( 'This product will generate a gift card worth %s (fixed amount) after payment.', 'mp-commerce-promotions' ),
				$formatted
			);
		}

		return sprintf(
			/* translators: %s: formatted amount */
			__( 'This product will generate a gift card worth %s (from the product price per unit) after payment.', 'mp-commerce-promotions' ),
			$formatted
		);
	}

	public static function recipient_mode_help( string $mode ): string {
		switch ( $mode ) {
			case GiftCardProductMeta::RECIPIENT_EMAIL:
				return __( 'Customers enter recipient email and delivery timing at checkout. The code is emailed to the recipient.', 'mp-commerce-promotions' );
			case GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE:
				return __( 'Same as recipient email, plus an optional personal message included in the delivery email.', 'mp-commerce-promotions' );
			default:
				return __( 'The gift card is emailed to the purchaser’s billing address when the order is paid (no extra checkout fields).', 'mp-commerce-promotions' );
		}
	}

	public static function virtual_product_warning( bool $is_virtual ): string {
		if ( $is_virtual ) {
			return '';
		}

		return __( 'Gift cards are usually sold as virtual products (no shipping). Consider enabling “Virtual” in the Product data box.', 'mp-commerce-promotions' );
	}

	/**
	 * Expiry input value: empty string when unset; preserve 0 for “no expiry”.
	 */
	public static function expiry_days_input_value( ?int $expiry_days ): string {
		if ( $expiry_days === null ) {
			return '';
		}

		return (string) $expiry_days;
	}

	/**
	 * Fixed amount input value: blank when zero and product-price mode avoids a stray “0”.
	 *
	 * @param array{sells: bool, amount_mode: string, fixed_amount: float, expiry_days: ?int, recipient_mode: string} $config
	 */
	public static function fixed_amount_input_value( array $config ): string {
		if ( $config['amount_mode'] !== GiftCardProductMeta::AMOUNT_MODE_FIXED ) {
			return '';
		}

		$amount = GiftCard::money( $config['fixed_amount'] );
		if ( $amount <= 0 ) {
			return '';
		}

		return (string) $amount;
	}
}
