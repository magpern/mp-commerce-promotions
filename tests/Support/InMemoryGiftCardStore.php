<?php
/**
 * In-memory gift card store for unit tests.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Support;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardTransaction;

/**
 * @internal
 */
final class InMemoryGiftCardStore {

	/** @var array<int, GiftCard> */
	private array $cards = array();

	/** @var array<int, list<GiftCardTransaction>> */
	private array $transactions = array();

	private int $next_card_id = 1;

	private int $next_tx_id = 1;

	public function insert_card( GiftCard $card ): int {
		$id = $this->next_card_id++;
		$stored = new GiftCard(
			$id,
			$card->get_gift_card_uuid(),
			$card->get_code_hash(),
			$card->get_code_last4(),
			$card->get_initial_amount(),
			$card->get_balance(),
			$card->get_currency(),
			$card->get_status(),
			$card->get_expires_at(),
			$card->get_created_order_id(),
			$card->get_purchaser_customer_id(),
			$card->get_recipient_email()
		);
		$this->cards[ $id ] = $stored;

		return $id;
	}

	public function update_card( GiftCard $card ): bool {
		$id = $card->get_id();
		if ( $id === null || ! isset( $this->cards[ $id ] ) ) {
			return false;
		}
		$this->cards[ $id ] = $card;

		return true;
	}

	public function find_card( int $id ): ?GiftCard {
		return $this->cards[ $id ] ?? null;
	}

	public function find_by_hash( string $hash ): ?GiftCard {
		foreach ( $this->cards as $card ) {
			if ( $card->get_code_hash() === $hash ) {
				return $card;
			}
		}

		return null;
	}

	public function insert_tx( GiftCardTransaction $transaction ): int {
		$id  = $this->next_tx_id++;
		$gid = $transaction->get_gift_card_id();
		if ( ! isset( $this->transactions[ $gid ] ) ) {
			$this->transactions[ $gid ] = array();
		}
		$this->transactions[ $gid ][] = new GiftCardTransaction(
			$id,
			$gid,
			$transaction->get_transaction_type(),
			$transaction->get_amount(),
			$transaction->get_balance_after(),
			$transaction->get_order_id(),
			$transaction->get_customer_id(),
			$transaction->get_note()
		);

		return $id;
	}

	/**
	 * @return list<GiftCardTransaction>
	 */
	public function list_tx( int $gift_card_id ): array {
		return $this->transactions[ $gift_card_id ] ?? array();
	}

	public function has_redeemed_for_order( int $gift_card_id, int $order_id ): bool {
		foreach ( $this->list_tx( $gift_card_id ) as $tx ) {
			if (
				$tx->get_transaction_type() === GiftCardTransaction::TYPE_REDEEMED
				&& $tx->get_order_id() === $order_id
			) {
				return true;
			}
		}

		return false;
	}
}
