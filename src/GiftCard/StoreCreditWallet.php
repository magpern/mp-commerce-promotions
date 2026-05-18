<?php
/**
 * Customer store credit balance operations (ledger-backed).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;
use MP\CommercePromotions\Service\AuditLogger;
use RuntimeException;

final class StoreCreditWallet {

	private StoreCreditAccountService $accounts;

	private GiftCardLedger $ledger;

	private ?AuditLogger $audit_logger;

	public function __construct(
		StoreCreditAccountService $accounts,
		GiftCardLedger $ledger,
		?AuditLogger $audit_logger = null
	) {
		$this->accounts     = $accounts;
		$this->ledger       = $ledger;
		$this->audit_logger = $audit_logger;
	}

	public function get_balance( int $customer_id, string $currency ): float {
		$wallet = $this->accounts->find_wallet( $customer_id, $currency );
		if ( $wallet === null ) {
			return 0.0;
		}

		return GiftCard::money( $wallet->get_balance() );
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function grant_credit( int $customer_id, float $amount, string $currency, string $note ): GiftCard {
		$note = trim( $note );
		if ( $note === '' ) {
			throw new InvalidArgumentException( 'Grant note is required.' );
		}

		$amount = GiftCard::money( $amount );
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Grant amount must be greater than zero.' );
		}

		$wallet = $this->accounts->find_or_create_wallet( $customer_id, $currency );
		$id     = $wallet->get_id();
		if ( $id === null ) {
			throw new RuntimeException( 'Store credit wallet has no ID.' );
		}

		$type = $wallet->get_balance() <= 0 && $wallet->get_initial_amount() <= 0
			? GiftCardTransaction::TYPE_ISSUED
			: GiftCardTransaction::TYPE_ADJUSTED;

		$updated = $this->ledger->credit_balance( $id, $amount, $type, null, $customer_id, $note );
		$this->audit( 'store_credit_grant', $customer_id, $id, $amount, $note );

		return $updated;
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function deduct_credit( int $customer_id, float $amount, string $currency, string $note ): GiftCard {
		$note = trim( $note );
		if ( $note === '' ) {
			throw new InvalidArgumentException( 'Deduct note is required.' );
		}

		$amount = GiftCard::money( $amount );
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Deduct amount must be greater than zero.' );
		}

		$wallet = $this->accounts->find_or_create_wallet( $customer_id, $currency );
		$id     = $wallet->get_id();
		if ( $id === null ) {
			throw new RuntimeException( 'Store credit wallet has no ID.' );
		}

		$updated = $this->ledger->adjust( $id, -$amount, $note );
		$this->audit( 'store_credit_deduct', $customer_id, $id, -$amount, $note );

		return $updated;
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function debit_for_checkout(
		int $account_id,
		float $amount,
		int $order_id,
		int $customer_id,
		?string $note = null
	): GiftCard {
		$card = $this->ledger->find( $account_id );
		if ( $card === null || ! $card->is_store_credit_wallet() ) {
			throw new InvalidArgumentException( 'Store credit account not found.' );
		}

		if ( $card->get_owner_customer_id() !== $customer_id ) {
			throw new InvalidArgumentException( 'Store credit account does not belong to this customer.' );
		}

		return $this->ledger->redeem(
			$account_id,
			$amount,
			$order_id > 0 ? $order_id : null,
			$customer_id > 0 ? $customer_id : null,
			$note ?? 'Checkout store credit'
		);
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function restore_checkout_debit(
		int $account_id,
		float $amount,
		int $order_id,
		?string $note = null
	): GiftCard {
		return $this->ledger->refund_redemption(
			$account_id,
			$amount,
			$order_id > 0 ? $order_id : null,
			$note ?? 'Checkout reversal'
		);
	}

	/**
	 * Refund an order amount into the customer's store credit wallet (MVP admin tool).
	 *
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function refund_order_to_credit( int $order_id, float $amount, string $note ): GiftCard {
		$note = trim( $note );
		if ( $note === '' ) {
			throw new InvalidArgumentException( 'Refund-to-credit note is required.' );
		}

		$amount = GiftCard::money( $amount );
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Refund amount must be greater than zero.' );
		}

		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			throw new InvalidArgumentException( 'Valid order ID is required.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
			throw new InvalidArgumentException( 'Order not found.' );
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id <= 0 ) {
			throw new InvalidArgumentException( 'Order has no customer; cannot refund to store credit.' );
		}

		$currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '';
		if ( $currency === '' && function_exists( 'get_woocommerce_currency' ) ) {
			$currency = get_woocommerce_currency();
		}

		$wallet = $this->accounts->find_or_create_wallet( $customer_id, $currency );
		$id     = $wallet->get_id();
		if ( $id === null ) {
			throw new RuntimeException( 'Store credit wallet has no ID.' );
		}

		$updated = $this->ledger->credit_balance(
			$id,
			$amount,
			GiftCardTransaction::TYPE_REFUND_TO_CREDIT,
			$order_id,
			$customer_id,
			$note
		);

		$this->audit( 'store_credit_refund_to_credit', $customer_id, $id, $amount, $note, $order_id );

		return $updated;
	}

	/**
	 * @return list<GiftCardTransaction>
	 */
	public function transactions_for_customer( int $customer_id, string $currency ): array {
		$wallet = $this->accounts->find_wallet( $customer_id, $currency );
		if ( $wallet === null ) {
			return array();
		}

		$id = $wallet->get_id();
		if ( $id === null ) {
			return array();
		}

		return $this->ledger->transactions_for_card( $id );
	}

	private function audit(
		string $action,
		int $customer_id,
		int $wallet_id,
		float $amount,
		string $note,
		?int $order_id = null
	): void {
		if ( $this->audit_logger === null ) {
			return;
		}

		$this->audit_logger->log(
			$action,
			null,
			array(
				'entity'      => 'store_credit',
				'wallet_id'   => $wallet_id,
				'customer_id' => $customer_id,
				'amount'      => $amount,
				'note'        => $note,
				'order_id'    => $order_id,
			)
		);
	}
}
