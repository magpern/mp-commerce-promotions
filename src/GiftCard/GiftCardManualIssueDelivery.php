<?php
/**
 * Email delivery for manually issued admin gift cards.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardManualIssueDelivery {

	public const TEST_SAMPLE_CODE = '****TEST';

	private GiftCardDeliveryMailer $mailer;

	private GiftCardManualDeliveryStore $delivery_store;

	public function __construct( GiftCardDeliveryMailer $mailer, GiftCardManualDeliveryStore $delivery_store ) {
		$this->mailer          = $mailer;
		$this->delivery_store  = $delivery_store;
	}

	/**
	 * Deliver gift card email after manual issue (plain code only in memory).
	 *
	 * @return array{
	 *   delivery_status: string,
	 *   delivered_to?: string,
	 *   delivered_at?: string,
	 *   delivery_error?: string,
	 *   recipient_email: string
	 * }
	 */
	public function deliver_after_issue( GiftCardIssueResult $result, ?string $recipient_email ): array {
		$card = $result->get_card();
		$id   = $card->get_id();
		$email = $recipient_email !== null && $recipient_email !== '' ? sanitize_email( $recipient_email ) : '';

		if ( $email === '' || ! is_email( $email ) ) {
			$outcome = array(
				'delivery_status' => GiftCardDeliveryStatus::NOT_REQUESTED,
				'recipient_email' => '',
			);
			if ( $id !== null && $id > 0 ) {
				$this->delivery_store->record( $id, $outcome );
			}
			return $outcome;
		}

		$batch = $this->mailer->deliver_batch(
			$email,
			0,
			array(
				array(
					'plain_code'   => $result->get_plain_code(),
					'amount'       => $card->get_initial_amount(),
					'currency'     => $card->get_currency(),
					'expires_at'   => $card->get_expires_at(),
					'gift_card_id' => $id ?? 0,
				),
			)
		);

		$row    = $batch['results'][0] ?? array( 'delivery_status' => GiftCardDeliveryStatus::FAILED );
		$status = (string) ( $row['delivery_status'] ?? GiftCardDeliveryStatus::FAILED );

		$outcome = array(
			'delivery_status' => $status,
			'recipient_email' => $email,
		);
		if ( isset( $row['delivered_to'] ) ) {
			$outcome['delivered_to'] = (string) $row['delivered_to'];
		}
		if ( isset( $row['delivered_at'] ) ) {
			$outcome['delivered_at'] = (string) $row['delivered_at'];
		}
		if ( isset( $row['delivery_error'] ) ) {
			$outcome['delivery_error'] = (string) $row['delivery_error'];
		}

		if ( $id !== null && $id > 0 ) {
			$stored = array( 'delivery_status' => $status );
			foreach ( array( 'delivered_to', 'delivered_at', 'delivery_error' ) as $key ) {
				if ( isset( $outcome[ $key ] ) && $outcome[ $key ] !== '' ) {
					$stored[ $key ] = (string) $outcome[ $key ];
				}
			}
			$this->delivery_store->record( $id, $stored );
		}

		return $outcome;
	}

	/**
	 * Send a test gift card email (no real card created).
	 *
	 * @return array{
	 *   ok: bool,
	 *   delivery_status: string,
	 *   delivered_to?: string,
	 *   delivered_at?: string,
	 *   delivery_error?: string
	 * }
	 */
	public function send_test_email( string $to_email ): array {
		$result = $this->mailer->send_test_delivery_email( $to_email );
		$this->record_test_result( $result );

		return $result;
	}

	/**
	 * @param array{
	 *   ok: bool,
	 *   delivery_status: string,
	 *   delivered_to?: string,
	 *   delivered_at?: string,
	 *   delivery_error?: string
	 * } $result
	 */
	private function record_test_result( array $result ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		$payload = array(
			'delivery_status' => (string) ( $result['delivery_status'] ?? '' ),
			'ok'              => ! empty( $result['ok'] ),
			'recorded_at'     => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		);
		if ( isset( $result['delivered_to'] ) ) {
			$payload['delivered_to'] = (string) $result['delivered_to'];
		}
		if ( isset( $result['delivery_error'] ) ) {
			$payload['delivery_error'] = (string) $result['delivery_error'];
		}
		if ( isset( $result['sender_mode_used'] ) ) {
			$payload['sender_mode_used'] = (string) $result['sender_mode_used'];
		}
		if ( isset( $result['from_header_set'] ) ) {
			$payload['from_header_set'] = ! empty( $result['from_header_set'] );
		}

		update_option( 'mp_cp_gift_card_test_email_last', $payload, false );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_last_test_result(): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$raw = get_option( 'mp_cp_gift_card_test_email_last', null );
		return is_array( $raw ) ? $raw : null;
	}
}
