<?php
/**
 * Gift card balance ledger (append-only transactions).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;
use RuntimeException;

final class GiftCardLedger {

	private GiftCardRepository $cards;

	private GiftCardTransactionRepository $transactions;

	private GiftCardCodeGenerator $code_generator;

	public function __construct(
		GiftCardRepository $cards,
		GiftCardTransactionRepository $transactions,
		?GiftCardCodeGenerator $code_generator = null
	) {
		$this->cards           = $cards;
		$this->transactions    = $transactions;
		$this->code_generator    = $code_generator ?? new GiftCardCodeGenerator();
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function issue(
		float $amount,
		string $currency,
		?string $expires_at = null,
		?string $recipient_email = null,
		?string $note = null,
		?int $created_order_id = null,
		?int $purchaser_customer_id = null
	): GiftCardIssueResult {
		$amount = GiftCard::money( $amount );
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Gift card amount must be greater than zero.' );
		}

		$currency = GiftCardCurrency::validate( $currency );

		$plain_code = $this->code_generator->generate_plain_code();
		$hash       = GiftCardRepository::hash_plain_code( $plain_code );
		$last4      = $this->code_generator->last4_from_plain_code( $plain_code );
		$uuid       = $this->generate_uuid();

		$card = new GiftCard(
			null,
			$uuid,
			$hash,
			$last4,
			$amount,
			$amount,
			$currency,
			GiftCard::STATUS_ACTIVE,
			$expires_at !== null && $expires_at !== '' ? $expires_at : null,
			$created_order_id !== null && $created_order_id > 0 ? $created_order_id : null,
			$purchaser_customer_id !== null && $purchaser_customer_id > 0 ? $purchaser_customer_id : null,
			$recipient_email !== null && $recipient_email !== '' ? sanitize_email( $recipient_email ) : null
		);

		$card_id = $this->cards->insert( $card );
		if ( $card_id <= 0 ) {
			throw new RuntimeException( 'Failed to insert gift card.' );
		}

		$stored = $this->cards->find( $card_id );
		if ( $stored === null ) {
			throw new RuntimeException( 'Failed to load issued gift card.' );
		}

		$this->append_transaction(
			$card_id,
			GiftCardTransaction::TYPE_ISSUED,
			$amount,
			$amount,
			$created_order_id !== null && $created_order_id > 0 ? $created_order_id : null,
			$purchaser_customer_id !== null && $purchaser_customer_id > 0 ? $purchaser_customer_id : null,
			$note
		);

		return new GiftCardIssueResult( $plain_code, $stored );
	}

	/**
	 * Issue a gift card tied to a paid product order line.
	 *
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function issue_from_order(
		float $amount,
		string $currency,
		int $order_id,
		?int $purchaser_customer_id,
		?string $recipient_email,
		?string $expires_at,
		?string $note = null
	): GiftCardIssueResult {
		if ( $order_id <= 0 ) {
			throw new InvalidArgumentException( 'Order ID is required for product gift cards.' );
		}

		return $this->issue(
			$amount,
			$currency,
			$expires_at,
			$recipient_email,
			$note ?? 'Generated from product order',
			$order_id,
			$purchaser_customer_id
		);
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function adjust( int $gift_card_id, float $delta, string $note ): GiftCard {
		$note = trim( $note );
		if ( $note === '' ) {
			throw new InvalidArgumentException( 'Adjustment note is required.' );
		}

		$card = $this->require_card( $gift_card_id );
		if ( $card->get_status() === GiftCard::STATUS_VOIDED ) {
			throw new InvalidArgumentException( 'Voided gift cards cannot be adjusted.' );
		}

		$delta       = round( $delta, 2 );
		$raw_balance = round( $card->get_balance() + $delta, 2 );
		if ( $raw_balance < 0 ) {
			throw new InvalidArgumentException( 'Adjustment would result in a negative balance.' );
		}
		$new_balance = GiftCard::money( $raw_balance );

		$status = $card->get_status();
		if ( $new_balance <= 0 && $status === GiftCard::STATUS_ACTIVE ) {
			$status = GiftCard::STATUS_DEPLETED;
		} elseif ( $new_balance > 0 && $status === GiftCard::STATUS_DEPLETED ) {
			$status = GiftCard::STATUS_ACTIVE;
		}

		$updated = $card->with_balance_and_status( $new_balance, $status );
		if ( ! $this->cards->update( $updated ) ) {
			throw new RuntimeException( 'Failed to update gift card balance.' );
		}

		$this->append_transaction(
			$gift_card_id,
			GiftCardTransaction::TYPE_ADJUSTED,
			$delta,
			$new_balance,
			null,
			null,
			$note
		);

		return $this->require_card( $gift_card_id );
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function void_card( int $gift_card_id, string $note ): GiftCard {
		$note = trim( $note );
		if ( $note === '' ) {
			throw new InvalidArgumentException( 'Void note is required.' );
		}

		$card = $this->require_card( $gift_card_id );
		if ( $card->get_status() === GiftCard::STATUS_VOIDED ) {
			return $card;
		}

		$balance_before = GiftCard::money( $card->get_balance() );
		$updated        = $card->with_balance_and_status( 0.0, GiftCard::STATUS_VOIDED );
		if ( ! $this->cards->update( $updated ) ) {
			throw new RuntimeException( 'Failed to void gift card.' );
		}

		$this->append_transaction(
			$gift_card_id,
			GiftCardTransaction::TYPE_VOIDED,
			-$balance_before,
			0.0,
			null,
			null,
			$note
		);

		return $this->require_card( $gift_card_id );
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function redeem(
		int $gift_card_id,
		float $amount,
		?int $order_id = null,
		?int $customer_id = null,
		?string $note = null
	): GiftCard {
		$amount = GiftCard::money( $amount );
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Redemption amount must be greater than zero.' );
		}

		$card = $this->require_card( $gift_card_id );
		$now  = current_time( 'mysql' );

		if ( $card->get_status() === GiftCard::STATUS_VOIDED ) {
			throw new InvalidArgumentException( 'Voided gift cards cannot be redeemed.' );
		}

		if ( $card->get_status() === GiftCard::STATUS_DEPLETED ) {
			throw new InvalidArgumentException( 'Depleted gift cards cannot be redeemed.' );
		}

		if ( $card->get_status() === GiftCard::STATUS_EXPIRED || $card->is_expired_at( $now ) ) {
			throw new InvalidArgumentException( 'Expired gift cards cannot be redeemed.' );
		}

		if ( ! $card->can_redeem( $amount, $now ) ) {
			throw new InvalidArgumentException( 'Insufficient gift card balance.' );
		}

		if ( $order_id !== null && $order_id > 0 && $this->transactions->has_redeemed_for_order( $gift_card_id, $order_id ) ) {
			return $card;
		}

		$new_balance = GiftCard::money( $card->get_balance() - $amount );
		$status      = $new_balance <= 0 ? GiftCard::STATUS_DEPLETED : GiftCard::STATUS_ACTIVE;

		$updated = $card->with_balance_and_status( $new_balance, $status );
		if ( ! $this->cards->update( $updated ) ) {
			throw new RuntimeException( 'Failed to update gift card after redemption.' );
		}

		$this->append_transaction(
			$gift_card_id,
			GiftCardTransaction::TYPE_REDEEMED,
			-$amount,
			$new_balance,
			$order_id,
			$customer_id,
			$note
		);

		return $this->require_card( $gift_card_id );
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	/**
	 * Credit balance with a specific positive transaction type (store credit grants, refund-to-credit).
	 *
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function credit_balance(
		int $gift_card_id,
		float $amount,
		string $transaction_type,
		?int $order_id = null,
		?int $customer_id = null,
		?string $note = null
	): GiftCard {
		$amount = GiftCard::money( $amount );
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Credit amount must be greater than zero.' );
		}

		$allowed = array(
			GiftCardTransaction::TYPE_ISSUED,
			GiftCardTransaction::TYPE_ADJUSTED,
			GiftCardTransaction::TYPE_REFUND_TO_CREDIT,
		);
		if ( ! in_array( $transaction_type, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Invalid credit transaction type.' );
		}

		$card = $this->require_card( $gift_card_id );
		if ( $card->get_status() === GiftCard::STATUS_VOIDED ) {
			throw new InvalidArgumentException( 'Voided accounts cannot be credited.' );
		}

		$new_balance = GiftCard::money( $card->get_balance() + $amount );
		$status      = GiftCard::STATUS_ACTIVE;
		if ( $new_balance <= 0 ) {
			$status = GiftCard::STATUS_DEPLETED;
		} elseif ( $card->get_status() === GiftCard::STATUS_DEPLETED ) {
			$status = GiftCard::STATUS_ACTIVE;
		}

		$updated = $card->with_balance_and_status( $new_balance, $status );

		if ( ! $this->cards->update( $updated ) ) {
			throw new RuntimeException( 'Failed to update account after credit.' );
		}

		$this->append_transaction(
			$gift_card_id,
			$transaction_type,
			$amount,
			$new_balance,
			$order_id,
			$customer_id,
			$note
		);

		return $this->require_card( $gift_card_id );
	}

	public function refund_redemption(
		int $gift_card_id,
		float $amount,
		?int $order_id = null,
		?string $note = null
	): GiftCard {
		$amount = GiftCard::money( $amount );
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Refund amount must be greater than zero.' );
		}

		$card = $this->require_card( $gift_card_id );
		if ( $card->get_status() === GiftCard::STATUS_VOIDED ) {
			throw new InvalidArgumentException( 'Voided gift cards cannot be refunded.' );
		}

		$new_balance = GiftCard::money( $card->get_balance() + $amount );
		$status      = GiftCard::STATUS_ACTIVE;
		if ( $new_balance <= 0 ) {
			$status = GiftCard::STATUS_DEPLETED;
		}

		$updated = $card->with_balance_and_status( $new_balance, $status );
		if ( ! $this->cards->update( $updated ) ) {
			throw new RuntimeException( 'Failed to update gift card after refund.' );
		}

		$this->append_transaction(
			$gift_card_id,
			GiftCardTransaction::TYPE_REFUNDED,
			$amount,
			$new_balance,
			$order_id,
			null,
			$note
		);

		return $this->require_card( $gift_card_id );
	}

	public function find_by_plain_code( string $plain_code ): ?GiftCard {
		return $this->cards->find_by_plain_code( $plain_code );
	}

	public function find( int $gift_card_id ): ?GiftCard {
		return $this->cards->find( $gift_card_id );
	}

	/**
	 * @return list<GiftCardTransaction>
	 */
	public function transactions_for_card( int $gift_card_id ): array {
		return $this->transactions->list_for_card( $gift_card_id );
	}

	private function require_card( int $gift_card_id ): GiftCard {
		$card = $this->cards->find( $gift_card_id );
		if ( $card === null ) {
			throw new InvalidArgumentException( 'Gift card not found.' );
		}

		return $card;
	}

	private function append_transaction(
		int $gift_card_id,
		string $type,
		float $amount,
		float $balance_after,
		?int $order_id,
		?int $customer_id,
		?string $note
	): void {
		$tx = new GiftCardTransaction(
			null,
			$gift_card_id,
			$type,
			$amount,
			$balance_after,
			$order_id,
			$customer_id,
			$note
		);

		if ( $this->transactions->insert( $tx ) <= 0 ) {
			throw new RuntimeException( 'Failed to write gift card ledger transaction.' );
		}
	}

	private function generate_uuid(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ) | 0x4000,
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff )
		);
	}
}
