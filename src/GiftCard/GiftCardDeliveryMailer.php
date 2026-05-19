<?php
/**
 * Gift card delivery email (HTML + plain; codes never logged or audited).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;

final class GiftCardDeliveryMailer {

	private Settings $settings;

	private GiftCardEmailSender $email_sender;

	public function __construct( Settings $settings, ?GiftCardEmailSender $email_sender = null ) {
		$this->settings     = $settings;
		$this->email_sender = $email_sender ?? new GiftCardEmailSender( $settings );
	}

	/**
	 * @param list<array{
	 *   plain_code: string,
	 *   amount: float,
	 *   currency: string,
	 *   expires_at: ?string,
	 *   gift_card_id?: int,
	 *   recipient_name?: string,
	 *   purchaser_name?: string,
	 *   message?: string
	 * }> $cards
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
			$error = __( 'Invalid recipient email address.', 'mp-commerce-promotions' );
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

		$manual_issue = $order_id <= 0;
		$send_meta    = $this->send_email( $to_email, $order_id, $cards, false, $manual_issue );
		$sent         = ! empty( $send_meta['sent'] );
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
	 * Send a test gift card email (sample code only; does not create a card).
	 *
	 * @return array{
	 *   ok: bool,
	 *   delivery_status: string,
	 *   delivered_to?: string,
	 *   delivered_at?: string,
	 *   delivery_error?: string,
	 *   sender_mode_used?: string,
	 *   from_header_set?: bool
	 * }
	 */
	/**
	 * @param array<string, string>|null $copy_overrides Unsaved settings form values for test send.
	 */
	public function send_test_delivery_email(
		string $to_email,
		?float $amount = null,
		?string $currency = null,
		?array $copy_overrides = null
	): array {
		$to_email = sanitize_email( $to_email );
		if ( $to_email === '' || ! is_email( $to_email ) ) {
			return array(
				'ok'              => false,
				'delivery_status' => GiftCardDeliveryStatus::FAILED,
				'delivery_error'  => __( 'Invalid recipient email address.', 'mp-commerce-promotions' ),
			);
		}

		if ( ! $this->settings->gift_card_delivery_email_enabled() ) {
			return array(
				'ok'              => false,
				'delivery_status' => GiftCardDeliveryStatus::DISABLED,
				'delivery_error'  => __( 'Gift card delivery email is disabled in settings.', 'mp-commerce-promotions' ),
			);
		}

		$currency = $currency !== null && $currency !== ''
			? sanitize_text_field( $currency )
			: ( function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR' );

		$sample_amount = $amount !== null && $amount > 0 ? (float) $amount : 1.0;

		$cards = array(
			array(
				'plain_code' => GiftCardManualIssueDelivery::TEST_SAMPLE_CODE,
				'amount'     => $sample_amount,
				'currency'   => $currency,
				'expires_at' => null,
			),
		);

		$send_meta = $this->send_email( $to_email, 0, $cards, true, true, $copy_overrides );
		$now       = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$base      = array(
			'sender_mode_used' => (string) ( $send_meta['sender_mode_used'] ?? $this->email_sender->effective_mode() ),
			'from_header_set'  => ! empty( $send_meta['from_header_set'] ),
		);

		if ( ! empty( $send_meta['sent'] ) ) {
			return array_merge(
				$base,
				array(
					'ok'              => true,
					'delivery_status' => GiftCardDeliveryStatus::SENT,
					'delivered_to'    => $to_email,
					'delivered_at'    => $now,
				)
			);
		}

		return array_merge(
			$base,
			array(
				'ok'              => false,
				'delivery_status' => GiftCardDeliveryStatus::FAILED,
				'delivered_to'    => $to_email,
				'delivery_error'  => __( 'Email could not be sent (wp_mail failed).', 'mp-commerce-promotions' ),
			)
		);
	}

	/**
	 * @param list<array{plain_code: string, amount: float, currency: string, expires_at: ?string, recipient_name?: string, purchaser_name?: string, message?: string}> $cards
	 */
	/**
	 * @return array{sent: bool, sender_mode_used: string, from_header_set: bool}
	 */
	/**
	 * @param array<string, string>|null $copy_overrides
	 */
	private function send_email(
		string $to_email,
		int $order_id,
		array $cards,
		bool $is_test = false,
		bool $manual_issue = false,
		?array $copy_overrides = null
	): array {
		$site_name = GiftCardEmailPlaceholders::site_title();

		$first_card = $cards[0] ?? array();
		$subject    = GiftCardEmailRenderer::resolve_subject(
			$this->settings,
			$first_card,
			false,
			$is_test,
			$copy_overrides
		);

		$store_url  = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$template   = $this->settings->gift_card_email_template();
		$appearance = $this->settings->resolve_gift_card_email_appearance( $template );

		$html = GiftCardEmailRenderer::render(
			$this->settings,
			array(
				'template_slug'  => $template,
				'site_name'        => $site_name,
				'store_url'        => $store_url,
				'order_id'         => $order_id,
				'accent'           => $appearance['accent_color'],
				'logo_url'         => $appearance['logo_url'],
				'cards'            => $cards,
				'manual_issue'     => $manual_issue,
				'is_test'          => $is_test,
				'copy_overrides'   => $copy_overrides,
			)
		);

		$plain = $this->build_plain_body( $site_name, $order_id, $store_url, $cards, $manual_issue, $is_test );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'MIME-Version: 1.0',
		);

		$sender_meta = $this->email_sender->resolve_for_send( $site_name );
		$headers     = array_merge( $headers, $sender_meta['headers'] );

		$meta = array(
			'sent'              => false,
			'sender_mode_used'  => (string) $sender_meta['mode'],
			'from_header_set'   => ! empty( $sender_meta['from_header_set'] ),
		);

		if ( function_exists( 'wp_mail' ) ) {
			\add_action( 'wp_mail_content_type', array( $this, 'filter_html_content_type' ) );
			$sent = (bool) wp_mail( $to_email, $subject, $html, $headers );
			\remove_action( 'wp_mail_content_type', array( $this, 'filter_html_content_type' ) );
			if ( ! $sent ) {
				$headers_plain = array( 'Content-Type: text/plain; charset=UTF-8' );
				foreach ( $sender_meta['headers'] as $header ) {
					if ( str_starts_with( $header, 'From:' ) || str_starts_with( $header, 'Reply-To:' ) ) {
						$headers_plain[] = $header;
					}
				}
				$sent = (bool) wp_mail( $to_email, $subject, $plain, $headers_plain );
			}
			if ( ! $sent ) {
				GiftCardMailDiagnostics::record_mail_failure( ! empty( $sender_meta['from_header_set'] ) );
			}
			$meta['sent'] = $sent;

			return $meta;
		}

		return $meta;
	}

	public function filter_html_content_type(): string {
		return 'text/html';
	}

	/**
	 * @param list<array{plain_code: string, amount: float, currency: string, expires_at: ?string, recipient_name?: string, purchaser_name?: string, message?: string}> $cards
	 */
	private function build_plain_body(
		string $site_name,
		int $order_id,
		string $store_url,
		array $cards,
		bool $manual_issue = false,
		bool $is_test = false
	): string {
		if ( $is_test ) {
			$lines = array(
				__( 'This is a test gift card email. No real gift card was created.', 'mp-commerce-promotions' ),
				'',
			);
		} elseif ( $manual_issue ) {
			$lines = array(
				sprintf(
					/* translators: %s: store name */
					__( 'You have received a gift card from %s.', 'mp-commerce-promotions' ),
					$site_name
				),
				__( 'Gift card details:', 'mp-commerce-promotions' ),
				'',
			);
		} else {
			$lines = array(
				sprintf(
					/* translators: %s: store name */
					__( 'Thank you for your purchase at %s.', 'mp-commerce-promotions' ),
					$site_name
				),
			);
			if ( $order_id > 0 ) {
				$lines[] = sprintf(
					/* translators: %d: order ID */
					__( 'Gift card details from order #%d:', 'mp-commerce-promotions' ),
					$order_id
				);
			} else {
				$lines[] = __( 'Gift card details:', 'mp-commerce-promotions' );
			}
			$lines[] = '';
		}

		$lines[] = __( 'Redeem at checkout in the “Gift card or store credit” section.', 'mp-commerce-promotions' );
		$lines[] = '';

		foreach ( $cards as $card ) {
			$recipient_name = trim( (string) ( $card['recipient_name'] ?? '' ) );
			if ( $recipient_name !== '' ) {
				$lines[] = sprintf(
					/* translators: %s: recipient name */
					__( 'Hi %s,', 'mp-commerce-promotions' ),
					$recipient_name
				);
			}
			$message = trim( (string) ( $card['message'] ?? '' ) );
			if ( $message !== '' ) {
				$lines[] = __( 'Message:', 'mp-commerce-promotions' );
				$lines[] = $message;
			}
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

		if ( $store_url !== '' ) {
			$lines[] = __( 'Store:', 'mp-commerce-promotions' ) . ' ' . $store_url;
		}

		$support = $this->settings->gift_card_support_email_text();
		if ( $support !== '' ) {
			$lines[] = $support;
		}

		$lines[] = __( 'Keep this email safe. The full code is required at checkout and is not stored in our system after delivery.', 'mp-commerce-promotions' );

		return implode( "\n", $lines );
	}
}
