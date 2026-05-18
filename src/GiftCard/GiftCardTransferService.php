<?php
/**
 * Transfer unused gift cards to a new recipient (new code; old code voided).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\Settings;
use RuntimeException;

final class GiftCardTransferService {

	public const INITIATED_BY_ADMIN = 'admin';

	public const INITIATED_BY_CUSTOMER = 'customer';

	private GiftCardLedger $ledger;

	private GiftCardRepository $cards;

	private GiftCardDeliveryMailer $mailer;

	private GiftCardTransferStore $transfer_store;

	private ?AuditLogger $audit_logger;

	public function __construct(
		GiftCardLedger $ledger,
		GiftCardRepository $cards,
		?Settings $settings = null,
		?GiftCardTransferStore $transfer_store = null,
		?AuditLogger $audit_logger = null
	) {
		$this->ledger          = $ledger;
		$this->cards           = $cards;
		$this->mailer          = new GiftCardDeliveryMailer( $settings ?? new Settings() );
		$this->transfer_store  = $transfer_store ?? new GiftCardTransferStore();
		$this->audit_logger    = $audit_logger;
	}

	public function customer_can_transfer_card( int $gift_card_id, int $customer_id ): bool {
		if ( $gift_card_id <= 0 || $customer_id <= 0 ) {
			return false;
		}

		$card = $this->cards->find( $gift_card_id );

		return $card !== null && $this->can_transfer( $card, $customer_id );
	}

	public function can_transfer( GiftCard $card, ?int $purchaser_customer_id = null ): bool {
		if ( $card->is_store_credit_wallet() || ! $card->is_fully_unused() ) {
			return false;
		}

		if ( $purchaser_customer_id !== null && $purchaser_customer_id > 0 ) {
			$owner = $card->get_purchaser_customer_id();
			if ( $owner === null || $owner !== $purchaser_customer_id ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array{
	 *   success: bool,
	 *   message: string,
	 *   new_gift_card_id?: int,
	 *   delivery_status?: string
	 * }
	 */
	public function transfer_to_new_recipient(
		int $gift_card_id,
		string $new_recipient_email,
		string $note,
		string $initiated_by,
		?int $acting_customer_id = null,
		?string $recipient_name = null,
		?string $message = null
	): array {
		$note = trim( $note );
		if ( $note === '' ) {
			return array(
				'success' => false,
				'message' => __( 'A note is required for this transfer.', 'mp-commerce-promotions' ),
			);
		}

		$new_recipient_email = sanitize_email( $new_recipient_email );
		if ( $new_recipient_email === '' || ! is_email( $new_recipient_email ) ) {
			return array(
				'success' => false,
				'message' => __( 'Please enter a valid recipient email address.', 'mp-commerce-promotions' ),
			);
		}

		$card = $this->cards->find( $gift_card_id );
		if ( $card === null || $card->is_store_credit_wallet() ) {
			return array(
				'success' => false,
				'message' => __( 'Gift card not found.', 'mp-commerce-promotions' ),
			);
		}

		if ( $initiated_by === self::INITIATED_BY_CUSTOMER ) {
			if ( $acting_customer_id === null || $acting_customer_id <= 0 ) {
				return array(
					'success' => false,
					'message' => __( 'You must be signed in to transfer a gift card.', 'mp-commerce-promotions' ),
				);
			}
			if ( ! $this->can_transfer( $card, $acting_customer_id ) ) {
				return array(
					'success' => false,
					'message' => __( 'Only unused gift cards you purchased can be sent to another recipient.', 'mp-commerce-promotions' ),
				);
			}
		} elseif ( ! $this->can_transfer( $card ) ) {
			return array(
				'success' => false,
				'message' => __( 'Only active, fully unused gift cards can be reissued to a new recipient.', 'mp-commerce-promotions' ),
			);
		}

		$current_recipient = $card->get_recipient_email();
		if ( $current_recipient !== null && strcasecmp( $current_recipient, $new_recipient_email ) === 0 ) {
			return array(
				'success' => false,
				'message' => __( 'The new recipient email must be different from the current recipient.', 'mp-commerce-promotions' ),
			);
		}

		$void_note = sprintf(
			/* translators: 1: new recipient email, 2: admin/customer note */
			__( 'Transferred to new recipient (%1$s). %2$s', 'mp-commerce-promotions' ),
			$new_recipient_email,
			$note
		);

		try {
			$this->ledger->void_card( $gift_card_id, $void_note );
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		$order_id    = $card->get_created_order_id();
		$purchaser   = $card->get_purchaser_customer_id();
		$issue_note  = sprintf(
			/* translators: %d: voided gift card ID */
			__( 'Transfer replacement for gift card #%d.', 'mp-commerce-promotions' ),
			$gift_card_id
		);

		try {
			if ( $order_id !== null && $order_id > 0 ) {
				$result = $this->ledger->issue_from_order(
					$card->get_initial_amount(),
					$card->get_currency(),
					$order_id,
					$purchaser,
					$new_recipient_email,
					$card->get_expires_at(),
					$issue_note
				);
			} else {
				$result = $this->ledger->issue(
					$card->get_initial_amount(),
					$card->get_currency(),
					$card->get_expires_at(),
					$new_recipient_email,
					$issue_note,
					null,
					$purchaser
				);
			}
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

		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$this->transfer_store->record_transfer(
			$gift_card_id,
			array(
				'new_gift_card_id' => $new_id,
				'recipient_email'  => $new_recipient_email,
				'initiated_by'     => $initiated_by,
				'transferred_at'   => $now,
			)
		);

		$this->sync_order_meta_after_transfer( $card, $gift_card_id, $new_card, $new_id );

		$email_card = array(
			'plain_code'      => $result->get_plain_code(),
			'amount'          => $new_card->get_initial_amount(),
			'currency'        => $new_card->get_currency(),
			'expires_at'      => $new_card->get_expires_at(),
			'gift_card_id'    => $new_id,
			'recipient_name'  => $recipient_name !== null ? trim( $recipient_name ) : '',
			'message'         => $message !== null ? trim( $message ) : '',
		);

		$delivery = $this->mailer->deliver_batch(
			$new_recipient_email,
			$order_id ?? 0,
			array( $email_card )
		);

		if ( $order_id !== null && $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$this->apply_delivery_results( wc_get_order( $order_id ), $delivery['results'] );
		}

		$delivery_row = array(
			'delivery_status' => (string) ( $delivery['results'][0]['delivery_status'] ?? GiftCardDeliveryStatus::PENDING ),
		);
		if ( ! empty( $delivery['results'][0]['delivered_to'] ) ) {
			$delivery_row['delivered_to'] = (string) $delivery['results'][0]['delivered_to'];
		}
		if ( ! empty( $delivery['results'][0]['delivered_at'] ) ) {
			$delivery_row['delivered_at'] = (string) $delivery['results'][0]['delivered_at'];
		}
		if ( ! empty( $delivery['results'][0]['delivery_error'] ) ) {
			$delivery_row['delivery_error'] = (string) $delivery['results'][0]['delivery_error'];
		}
		( new GiftCardManualDeliveryStore() )->record( $new_id, $delivery_row );

		$this->audit_transfer( $gift_card_id, $new_id, $new_recipient_email, $initiated_by, $order_id );

		$delivery_status = (string) ( $delivery['results'][0]['delivery_status'] ?? '' );
		$message_out     = sprintf(
			/* translators: %s: recipient email */
			__( 'A new gift card was emailed to %s. The previous code is no longer valid and was not shown here.', 'mp-commerce-promotions' ),
			$new_recipient_email
		);

		if ( $delivery_status === GiftCardDeliveryStatus::FAILED ) {
			$message_out = sprintf(
				/* translators: %s: recipient email */
				__( 'Replacement gift card created but email to %s failed. Contact support or try again from Diagnostics.', 'mp-commerce-promotions' ),
				$new_recipient_email
			);
		} elseif ( $delivery_status === GiftCardDeliveryStatus::DISABLED ) {
			$message_out = sprintf(
				/* translators: %s: recipient email */
				__( 'Replacement gift card created. Email delivery is disabled in settings; share the new code manually if needed.', 'mp-commerce-promotions' ),
				$new_recipient_email
			);
		}

		return array(
			'success'          => true,
			'message'          => $message_out,
			'new_gift_card_id' => $new_id,
			'delivery_status'  => $delivery_status,
		);
	}

	private function sync_order_meta_after_transfer( GiftCard $old_card, int $old_id, GiftCard $new_card, int $new_id ): void {
		$order_id = $old_card->get_created_order_id();
		if ( $order_id === null || $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) ) {
			return;
		}

		$row = GiftCardGeneratedOrderState::find_row_by_gift_card_id( $order, $old_id );
		if ( $row === null ) {
			return;
		}

		$new_row = GiftCardGeneratedOrderState::row_from_card(
			$new_card,
			(int) ( $row['order_item_id'] ?? 0 ),
			(int) ( $row['unit_index'] ?? 0 ),
			GiftCardDeliveryStatus::PENDING,
			array(
				'reissued_from_gift_card_id' => $old_id,
			)
		);

		GiftCardGeneratedOrderState::replace_row( $order, $old_id, $new_row );

		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: old id, 2: new id, 3: recipient email */
					__( 'Gift card transferred: #%1$d voided, #%2$d emailed to %3$s. Full code is not stored.', 'mp-commerce-promotions' ),
					$old_id,
					$new_id,
					(string) ( $new_card->get_recipient_email() ?? '' )
				),
				false
			);
		}

		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * @param list<array<string, mixed>> $delivery_results
	 */
	private function apply_delivery_results( $order, array $delivery_results ): void {
		if ( ! is_object( $order ) ) {
			return;
		}

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

	private function audit_transfer( int $old_id, int $new_id, string $recipient_email, string $initiated_by, ?int $order_id ): void {
		if ( $this->audit_logger === null ) {
			return;
		}

		$this->audit_logger->log(
			'gift_card.transferred_recipient',
			null,
			array(
				'voided_gift_card_id' => $old_id,
				'new_gift_card_id'    => $new_id,
				'recipient_email'     => $recipient_email,
				'initiated_by'        => $initiated_by,
				'order_id'            => $order_id,
			)
		);
	}
}
