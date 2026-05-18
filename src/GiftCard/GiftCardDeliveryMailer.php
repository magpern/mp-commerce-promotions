<?php
/**
 * Plain-text gift card delivery email (MVP).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;

final class GiftCardDeliveryMailer {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param list<array{plain_code: string, amount: float, currency: string, expires_at: ?string}> $cards
	 */
	public function send_to_billing_email( string $to_email, int $order_id, array $cards ): bool {
		if ( ! $this->settings->gift_card_delivery_email_enabled() ) {
			return false;
		}

		$to_email = sanitize_email( $to_email );
		if ( $to_email === '' || $cards === array() ) {
			return false;
		}

		$site_name = function_exists( 'wp_specialchars_decode' )
			? wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
			: 'Store';

		$subject = sprintf(
			/* translators: 1: site name, 2: order number */
			__( '[%1$s] Your gift card from order #%2$d', 'mp-commerce-promotions' ),
			$site_name,
			$order_id
		);

		$lines = array(
			sprintf(
				/* translators: %d: order ID */
				__( 'Thank you for your purchase. Gift card codes from order #%d:', 'mp-commerce-promotions' ),
				$order_id
			),
			'',
		);

		foreach ( $cards as $card ) {
			$amount_str = function_exists( 'wc_price' )
				? wp_strip_all_tags( wc_price( (float) $card['amount'], array( 'currency' => $card['currency'] ) ) )
				: number_format( (float) $card['amount'], 2 ) . ' ' . $card['currency'];
			$lines[] = __( 'Gift card code:', 'mp-commerce-promotions' ) . ' ' . (string) $card['plain_code'];
			$lines[] = __( 'Amount:', 'mp-commerce-promotions' ) . ' ' . $amount_str;
			if ( ! empty( $card['expires_at'] ) ) {
				$lines[] = __( 'Expires:', 'mp-commerce-promotions' ) . ' ' . (string) $card['expires_at'];
			}
			$lines[] = '';
		}

		$lines[] = __( 'Keep this email safe. The full code is required at checkout.', 'mp-commerce-promotions' );

		$body = implode( "\n", $lines );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		if ( function_exists( 'wp_mail' ) ) {
			return (bool) wp_mail( $to_email, $subject, $body, $headers );
		}

		return false;
	}
}
