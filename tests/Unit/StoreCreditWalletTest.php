<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardTransaction;
use MP\CommercePromotions\GiftCard\StoreCreditAccountService;
use MP\CommercePromotions\GiftCard\StoreCreditCheckoutService;
use MP\CommercePromotions\GiftCard\StoreCreditWallet;
use MP\CommercePromotions\Tests\Support\InMemoryGiftCardStore;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardRepository;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardTransactionRepository;
use PHPUnit\Framework\TestCase;

final class StoreCreditWalletTest extends TestCase {

	private InMemoryGiftCardStore $store;

	private GiftCardLedger $ledger;

	private StoreCreditAccountService $accounts;

	private StoreCreditWallet $wallet;

	private StoreCreditCheckoutService $checkout;

	protected function setUp(): void {
		$this->store    = new InMemoryGiftCardStore();
		$card_repo      = new MemoryGiftCardRepository( $this->store );
		$tx_repo        = new MemoryGiftCardTransactionRepository( $this->store );
		$this->ledger   = new GiftCardLedger( $card_repo, $tx_repo );
		$this->accounts = new StoreCreditAccountService( $card_repo );
		$this->wallet   = new StoreCreditWallet( $this->accounts, $this->ledger );
		$this->checkout = new StoreCreditCheckoutService( $this->accounts, $this->ledger );
	}

	public function test_create_customer_wallet(): void {
		$wallet = $this->accounts->find_or_create_wallet( 42, 'EUR' );
		$this->assertTrue( $wallet->is_store_credit_wallet() );
		$this->assertSame( 42, $wallet->get_owner_customer_id() );
		$this->assertSame( 0.0, $wallet->get_balance() );
		$this->assertSame( GiftCard::WALLET_CODE_LAST4, $wallet->get_code_last4() );
	}

	public function test_grant_and_get_balance(): void {
		$this->wallet->grant_credit( 10, 25.0, 'EUR', 'welcome credit' );
		$this->assertSame( 25.0, $this->wallet->get_balance( 10, 'EUR' ) );
	}

	public function test_deduct_credit(): void {
		$this->wallet->grant_credit( 11, 40.0, 'EUR', 'grant' );
		$this->wallet->deduct_credit( 11, 15.0, 'EUR', 'deduct' );
		$this->assertSame( 25.0, $this->wallet->get_balance( 11, 'EUR' ) );
	}

	public function test_no_negative_balance_on_deduct(): void {
		$this->wallet->grant_credit( 12, 5.0, 'EUR', 'grant' );
		$this->expectException( InvalidArgumentException::class );
		$this->wallet->deduct_credit( 12, 10.0, 'EUR', 'too much' );
	}

	public function test_debit_and_restore_checkout(): void {
		$wallet = $this->wallet->grant_credit( 13, 50.0, 'EUR', 'grant' );
		$id     = $wallet->get_id();
		$this->assertNotNull( $id );

		$this->wallet->debit_for_checkout( $id, 20.0, 5001, 13 );
		$this->assertSame( 30.0, $this->wallet->get_balance( 13, 'EUR' ) );

		$this->wallet->restore_checkout_debit( $id, 20.0, 5001 );
		$this->assertSame( 50.0, $this->wallet->get_balance( 13, 'EUR' ) );
	}

	public function test_guest_checkout_service_has_zero_customer(): void {
		$this->assertSame( 0, $this->checkout->get_current_customer_id() );
		$this->assertSame( 0.0, $this->checkout->preview_apply_amount( 99, 'EUR', 100.0 ) );
	}

	public function test_checkout_preview_for_wallet(): void {
		$this->wallet->grant_credit( 14, 30.0, 'EUR', 'grant' );
		$amount = $this->checkout->preview_apply_amount( 14, 'EUR', 100.0 );
		$this->assertSame( 30.0, $amount );

		$partial = $this->checkout->preview_apply_amount( 14, 'EUR', 12.5 );
		$this->assertSame( 12.5, $partial );
	}

	public function test_refund_to_credit_ledger_type(): void {
		$wallet = $this->accounts->find_or_create_wallet( 20, 'EUR' );
		$id     = $wallet->get_id();
		$this->assertNotNull( $id );

		$this->ledger->credit_balance(
			$id,
			12.0,
			GiftCardTransaction::TYPE_REFUND_TO_CREDIT,
			9001,
			20,
			'order refund to credit'
		);

		$this->assertSame( 12.0, $this->wallet->get_balance( 20, 'EUR' ) );
		$txs = $this->ledger->transactions_for_card( $id );
		$this->assertNotEmpty( $txs );
		$this->assertSame( GiftCardTransaction::TYPE_REFUND_TO_CREDIT, $txs[0]->get_transaction_type() );
	}
}
