<?php
/**
 * Issue and email a single gift card unit (shared by generator and scheduler).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\Settings;

final class GiftCardUnitFulfillment {

	private GiftCardLedger $ledger;

	private GiftCardDeliveryMailer $mailer;

	private ?AuditLogger $audit_logger;

	public function __construct(
		GiftCardLedger $ledger,
		?Settings $settings = null,
		?AuditLogger $audit_logger = null
	) {
		$this->ledger       = $ledger;
		$this->mailer       = new GiftCardDeliveryMailer( $settings ?? new Settings() );
		$this->audit_logger = $audit_logger;
	}

	/**
	 * @param array{
	 *   recipient_email: string,
	 *   recipient_name?: string,
	 *   message?: string,
	 *   purchaser_name?: string
	 * } $context
	 * @return array{card: GiftCard, plain_code: string, generated_row: array<string, mixed>, delivery_results: list<array<string, mixed>>}
	 */
	public function issue_and_deliver(
		int $order_id,
		int $order_item_id,
		int $unit_index,
		float $amount,
		string $currency,
		?int $customer_id,
		?string $expires_at,
		string $to_email,
		array $context = array()
	): array {
		$result = $this->ledger->issue_from_order(
			$amount,
			$currency,
			$order_id,
			$customer_id !== null && $customer_id > 0 ? $customer_id : null,
			$to_email !== '' ? $to_email : null,
			$expires_at,
			sprintf( 'Product order line %d unit %d', $order_item_id, $unit_index )
		);

		$card    = $result->get_card();
		$card_id = (int) $card->get_id();

		$overrides = array(
			'recipient_email'  => $to_email,
			'recipient_name'   => (string) ( $context['recipient_name'] ?? '' ),
			'message'          => (string) ( $context['message'] ?? '' ),
			'delivery_timing'  => (string) ( $context['delivery_timing'] ?? GiftCardLineItemMeta::TIMING_SEND_NOW ),
			'scheduled_for'    => (string) ( $context['scheduled_for'] ?? '' ),
		);

		$generated_row = GiftCardGeneratedOrderState::row_from_card(
			$card,
			$order_item_id,
			$unit_index,
			GiftCardDeliveryStatus::PENDING,
			$overrides
		);

		$email_payload = array(
			array(
				'plain_code'      => $result->get_plain_code(),
				'amount'          => $amount,
				'currency'        => $currency,
				'expires_at'      => $expires_at,
				'gift_card_id'    => $card_id,
				'recipient_name'  => (string) ( $context['recipient_name'] ?? '' ),
				'purchaser_name'  => (string) ( $context['purchaser_name'] ?? '' ),
				'message'         => (string) ( $context['message'] ?? '' ),
			),
		);

		$delivery = $this->mailer->deliver_batch( $to_email, $order_id, $email_payload );
		if ( isset( $delivery['results'][0] ) ) {
			$patch = $delivery['results'][0];
			$generated_row = array_merge( $generated_row, array(
				'delivery_status' => (string) ( $patch['delivery_status'] ?? GiftCardDeliveryStatus::PENDING ),
				'delivered_to'    => $patch['delivered_to'] ?? null,
				'delivered_at'    => $patch['delivered_at'] ?? null,
				'delivery_error'  => $patch['delivery_error'] ?? null,
			) );
		}

		$this->audit_generated( $order_id, $card_id, $amount );

		return array(
			'card'              => $card,
			'plain_code'        => $result->get_plain_code(),
			'generated_row'     => $generated_row,
			'delivery_results'  => $delivery['results'] ?? array(),
		);
	}

	private function audit_generated( int $order_id, int $gift_card_id, float $amount ): void {
		if ( $this->audit_logger === null ) {
			return;
		}

		$this->audit_logger->log(
			'gift_card.generated_from_order',
			null,
			array(
				'order_id'     => $order_id,
				'gift_card_id' => $gift_card_id,
				'amount'       => $amount,
			)
		);
	}
}
