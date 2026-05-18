<?php
/**
 * Reissue gift card delivery (new code; old code cannot be recovered).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\Settings;
use RuntimeException;

final class GiftCardOrderReissueService {

	private GiftCardLedger $ledger;

	private GiftCardRepository $cards;

	private GiftCardDeliveryMailer $mailer;

	private ?AuditLogger $audit_logger;

	public function __construct(
		GiftCardLedger $ledger,
		GiftCardRepository $cards,
		?Settings $settings = null,
		?AuditLogger $audit_logger = null
	) {
		$this->ledger       = $ledger;
		$this->cards        = $cards;
		$this->mailer       = new GiftCardDeliveryMailer( $settings ?? new Settings() );
		$this->audit_logger = $audit_logger;
	}

	/**
	 * @return array{success: bool, message: string, new_gift_card_id?: int}
	 */
	public function reissue_for_order_card( $order, int $gift_card_id ): array {
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
			return array(
				'success' => false,
				'message' => __( 'Order not found.', 'mp-commerce-promotions' ),
			);
		}

		$row = GiftCardGeneratedOrderState::find_row_by_gift_card_id( $order, $gift_card_id );
		if ( $row === null ) {
			return array(
				'success' => false,
				'message' => __( 'Gift card is not linked to this order.', 'mp-commerce-promotions' ),
			);
		}

		$card = $this->cards->find( $gift_card_id );
		if ( $card === null || $card->is_store_credit_wallet() ) {
			return array(
				'success' => false,
				'message' => __( 'Gift card not found.', 'mp-commerce-promotions' ),
			);
		}

		if ( $card->get_status() === GiftCard::STATUS_VOIDED ) {
			return array(
				'success' => false,
				'message' => __( 'This gift card was already voided.', 'mp-commerce-promotions' ),
			);
		}

		$unused = abs( $card->get_balance() - $card->get_initial_amount() ) < 0.009 && $card->get_balance() > 0;
		if ( ! $unused ) {
			return array(
				'success' => false,
				'message' => __( 'Gift card was partially used; manual review required before reissue.', 'mp-commerce-promotions' ),
			);
		}

		$order_id     = (int) $order->get_id();
		$billing_mail = method_exists( $order, 'get_billing_email' ) ? sanitize_email( (string) $order->get_billing_email() ) : '';
		$customer_id  = (int) $order->get_customer_id();

		try {
			$this->ledger->void_card(
				$gift_card_id,
				sprintf( 'Reissued delivery for order #%d', $order_id )
			);
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		try {
			$result = $this->ledger->issue_from_order(
				$card->get_initial_amount(),
				$card->get_currency(),
				$order_id,
				$customer_id > 0 ? $customer_id : $card->get_purchaser_customer_id(),
				$billing_mail !== '' ? $billing_mail : $card->get_recipient_email(),
				$card->get_expires_at(),
				sprintf( 'Reissue from gift card #%d, order #%d', $gift_card_id, $order_id )
			);
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		$new_card = $result->get_card();
		$new_id   = $new_card->get_id();
		if ( $new_id === null || $new_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to create replacement gift card.', 'mp-commerce-promotions' ),
			);
		}

		$new_row = GiftCardGeneratedOrderState::row_from_card(
			$new_card,
			(int) ( $row['order_item_id'] ?? 0 ),
			(int) ( $row['unit_index'] ?? 0 ),
			GiftCardDeliveryStatus::PENDING,
			array(
				'reissued_from_gift_card_id' => $gift_card_id,
			)
		);

		GiftCardGeneratedOrderState::replace_row( $order, $gift_card_id, $new_row );

		$email_payload = array(
			array(
				'plain_code'   => $result->get_plain_code(),
				'amount'       => $new_card->get_initial_amount(),
				'currency'     => $new_card->get_currency(),
				'expires_at'   => $new_card->get_expires_at(),
				'gift_card_id' => $new_id,
			),
		);

		$delivery = $this->mailer->deliver_batch(
			$billing_mail !== '' ? $billing_mail : (string) ( $card->get_recipient_email() ?? '' ),
			$order_id,
			$email_payload
		);

		$this->apply_delivery_results( $order, $delivery['results'] );

		if ( method_exists( $order, 'add_order_note' ) ) {
			$note = sprintf(
				/* translators: 1: old gift card id, 2: new gift card id */
				__( 'Gift card delivery reissued: #%1$d voided, #%2$d created. Full code was sent by email and is not stored.', 'mp-commerce-promotions' ),
				$gift_card_id,
				$new_id
			);
			$order->add_order_note( $note, false );
		}

		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		$this->audit_reissue( $order_id, $gift_card_id, $new_id );

		$message = __( 'Replacement gift card created and delivery attempted.', 'mp-commerce-promotions' );
		$first   = $delivery['results'][0] ?? array();
		if ( ( $first['delivery_status'] ?? '' ) === GiftCardDeliveryStatus::FAILED ) {
			$message = __( 'Replacement gift card created but email delivery failed. Use Reissue again or contact the customer manually.', 'mp-commerce-promotions' );
		} elseif ( ( $first['delivery_status'] ?? '' ) === GiftCardDeliveryStatus::DISABLED ) {
			$message = __( 'Replacement gift card created. Email delivery is disabled in settings.', 'mp-commerce-promotions' );
		}

		return array(
			'success'          => true,
			'message'          => $message,
			'new_gift_card_id' => $new_id,
		);
	}

	/**
	 * @param list<array<string, mixed>> $delivery_results
	 */
	private function apply_delivery_results( $order, array $delivery_results ): void {
		foreach ( $delivery_results as $result ) {
			$gift_card_id = (int) ( $result['gift_card_id'] ?? 0 );
			if ( $gift_card_id <= 0 ) {
				continue;
			}
			$patch = array(
				'delivery_status' => (string) ( $result['delivery_status'] ?? GiftCardDeliveryStatus::PENDING ),
			);
			if ( isset( $result['delivered_to'] ) ) {
				$patch['delivered_to'] = (string) $result['delivered_to'];
			}
			if ( isset( $result['delivered_at'] ) ) {
				$patch['delivered_at'] = (string) $result['delivered_at'];
			}
			if ( isset( $result['delivery_error'] ) ) {
				$patch['delivery_error'] = (string) $result['delivery_error'];
			}
			GiftCardGeneratedOrderState::update_row( $order, $gift_card_id, $patch );
		}
	}

	private function audit_reissue( int $order_id, int $old_id, int $new_id ): void {
		if ( $this->audit_logger === null ) {
			return;
		}

		$this->audit_logger->log(
			'gift_card.reissued_delivery',
			null,
			array(
				'order_id'              => $order_id,
				'voided_gift_card_id'   => $old_id,
				'new_gift_card_id'      => $new_id,
			)
		);
	}
}
