<?php
/**
 * Plain-text gift card delivery email (codes never logged or audited).
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
	 * @param list<array{plain_code: string, amount: float, currency: string, expires_at: ?string, gift_card_id?: int}> $cards
	 * @return array{
	 *   enabled: bool,
	 *   recipient_valid: bool,
	 *   results: list<array{gift_card_id: int, delivery_status: string, delivered_to?: string, delivered_at?: string, delivery_error?: string}>
	 * }
	 */
	public function deliver_batch( string $to_email, int $order_id, array $cards ): array {
		$results = array();
		foreach ( $cards as $index => $card ) {
			$gift_card_id = isset( $card['gift_card_id'] ) ? (int) $card['gift_card_id'] : $index;
			$results[]    = array(
				'gift_card_id'    => $gift_card_id,
				'delivery_status' => GiftCardDeliveryStatus::PENDING,
			);
		}

		if ( ! $this->settings->gift_card_delivery_email_enabled() ) {
			foreach ( $results as $i => $row ) {
				$results[ $i ]['delivery_status'] = GiftCardDeliveryStatus::DISABLED;
				$results[ $i ]['delivery_error']  = __( 'Gift card delivery email is disabled in settings.', 'mp-commerce-promotions' );
			}

			return array(
				'enabled'          => false,
				'recipient_valid'  => true,
				'results'          => $results,
			);
		}

		$to_email = sanitize_email( $to_email );
		if ( $to_email === '' || ! is_email( $to_email ) ) {
			$error = __( 'Invalid billing email address.', 'mp-commerce-promotions' );
			foreach ( $results as $i => $row ) {
				$results[ $i ]['delivery_status'] = GiftCardDeliveryStatus::FAILED;
				$results[ $i ]['delivery_error']  = $error;
			}

			return array(
				'enabled'          => true,
				'recipient_valid'  => false,
				'results'          => $results,
			);
		}

		if ( $cards === array() ) {
			return array(
				'enabled'          => true,
				'recipient_valid'  => true,
				'results'          => $results,
			);
		}

		$sent = $this->send_email( $to_email, $order_id, $cards );
		$now  = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		foreach ( $results as $i => $row ) {
			if ( $sent ) {
				$results[ $i ]['delivery_status'] = GiftCardDeliveryStatus::SENT;
				$results[ $i ]['delivered_to']    = $to_email;
				$results[ $i ]['delivered_at']    = $now;
			} else {
				$results[ $i ]['delivery_status'] = GiftCardDeliveryStatus::FAILED;
				$results[ $i ]['delivery_error']  = __( 'Email could not be sent (wp_mail failed).', 'mp-commerce-promotions' );
			}
		}

		return array(
			'enabled'          => true,
			'recipient_valid'  => true,
			'results'          => $results,
		);
	}

	/**
	 * @param list<array{plain_code: string, amount: float, currency: string, expires_at: ?string}> $cards
	 */
	private function send_email( string $to_email, int $order_id, array $cards ): bool {
		$site_name = function_exists( 'wp_specialchars_decode' )
			? wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
			: 'Store';

		if ( $site_name === '' ) {
			$site_name = 'Store';
		}

		$subject = sprintf(
			/* translators: %s: store name */
			__( 'Your gift card from %s', 'mp-commerce-promotions' ),
			$site_name
		);

		$store_url = function_exists( 'home_url' ) ? home_url( '/' ) : '';

		$lines = array(
			sprintf(
				/* translators: %s: store name */
				__( 'Thank you for your purchase at %s.', 'mp-commerce-promotions' ),
				$site_name
			),
			sprintf(
				/* translators: %d: order ID */
				__( 'Gift card details from order #%d:', 'mp-commerce-promotions' ),
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
			$lines[] = __( 'Currency:', 'mp-commerce-promotions' ) . ' ' . (string) $card['currency'];
			if ( ! empty( $card['expires_at'] ) ) {
				$lines[] = __( 'Expires:', 'mp-commerce-promotions' ) . ' ' . (string) $card['expires_at'];
			}
			$lines[] = '';
		}

		if ( $store_url !== '' ) {
			$lines[] = __( 'Store:', 'mp-commerce-promotions' ) . ' ' . $store_url;
			$lines[] = '';
		}

		$lines[] = __( 'Keep this email safe. The full code is required at checkout and is not stored in our system after delivery.', 'mp-commerce-promotions' );

		$body = implode( "\n", $lines );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$from_email = function_exists( 'get_option' ) ? (string) get_option( 'admin_email' ) : '';
		$from_email = sanitize_email( $from_email );
		if ( $from_email !== '' && is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $site_name, $from_email );
		}

		if ( function_exists( 'wp_mail' ) ) {
			return (bool) wp_mail( $to_email, $subject, $body, $headers );
		}

		return false;
	}
}
