<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRedemptionService;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransaction;
use MP\CommercePromotions\Tests\Support\InMemoryGiftCardStore;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardRepository;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardTransactionRepository;
use PHPUnit\Framework\TestCase;

final class GiftCardLedgerTest extends TestCase {

	private InMemoryGiftCardStore $store;

	private GiftCardLedger $ledger;

	protected function setUp(): void {
		$this->store  = new InMemoryGiftCardStore();
		$this->ledger = new GiftCardLedger(
			new MemoryGiftCardRepository( $this->store ),
			new MemoryGiftCardTransactionRepository( $this->store )
		);
	}

	public function test_hash_lookup(): void {
		$issued = $this->ledger->issue( 50.0, 'EUR' );
		$found  = $this->ledger->find_by_plain_code( $issued->get_plain_code() );
		$this->assertNotNull( $found );
		$this->assertSame( 50.0, $found->get_balance() );
	}

	public function test_partial_redemption(): void {
		$issued = $this->ledger->issue( 100.0, 'EUR' );
		$id     = $issued->get_card()->get_id();
		$this->assertNotNull( $id );

		$this->ledger->redeem( $id, 30.0, 1001 );
		$card = $this->ledger->find( $id );
		$this->assertNotNull( $card );
		$this->assertSame( 70.0, $card->get_balance() );
		$this->assertSame( GiftCard::STATUS_ACTIVE, $card->get_status() );
	}

	public function test_full_redemption_depletes(): void {
		$issued = $this->ledger->issue( 80.0, 'EUR' );
		$id     = $issued->get_card()->get_id();
		$this->assertNotNull( $id );

		$this->ledger->redeem( $id, 80.0, 1002 );
		$card = $this->ledger->find( $id );
		$this->assertNotNull( $card );
		$this->assertSame( 0.0, $card->get_balance() );
		$this->assertSame( GiftCard::STATUS_DEPLETED, $card->get_status() );
	}

	public function test_insufficient_balance_rejected(): void {
		$issued = $this->ledger->issue( 10.0, 'EUR' );
		$id     = $issued->get_card()->get_id();
		$this->assertNotNull( $id );

		$this->expectException( InvalidArgumentException::class );
		$this->ledger->redeem( $id, 20.0, 1003 );
	}

	public function test_voided_card_cannot_redeem(): void {
		$issued = $this->ledger->issue( 25.0, 'EUR' );
		$id     = $issued->get_card()->get_id();
		$this->assertNotNull( $id );

		$this->ledger->void_card( $id, 'test void' );

		$this->expectException( InvalidArgumentException::class );
		$this->ledger->redeem( $id, 5.0, 1004 );
	}

	public function test_negative_adjustment_rejected(): void {
		$issued = $this->ledger->issue( 15.0, 'EUR' );
		$id     = $issued->get_card()->get_id();
		$this->assertNotNull( $id );

		$this->expectException( InvalidArgumentException::class );
		$this->ledger->adjust( $id, -20.0, 'too much' );
	}

	public function test_reversal_restores_balance(): void {
		$issued = $this->ledger->issue( 60.0, 'EUR' );
		$id     = $issued->get_card()->get_id();
		$this->assertNotNull( $id );

		$this->ledger->redeem( $id, 25.0, 2001 );
		$this->ledger->refund_redemption( $id, 25.0, 2001 );
		$card = $this->ledger->find( $id );
		$this->assertNotNull( $card );
		$this->assertSame( 60.0, $card->get_balance() );
		$this->assertSame( GiftCard::STATUS_ACTIVE, $card->get_status() );
	}

	public function test_preview_caps_to_payable(): void {
		$card    = new GiftCard(
			1,
			'00000000-0000-4000-8000-000000000099',
			str_repeat( 'a', 64 ),
			'9999',
			100.0,
			100.0,
			'EUR',
			GiftCard::STATUS_ACTIVE
		);
		$service = new GiftCardRedemptionService( $this->ledger );
		$this->assertSame( 80.0, $service->preview_apply_amount( $card, 80.0 ) );
		$this->assertSame( 100.0, $service->preview_apply_amount( $card, 120.0 ) );
	}

	public function test_hash_plain_code_normalizes(): void {
		$h1 = GiftCardRepository::hash_plain_code( 'gc-abcd-1234' );
		$h2 = GiftCardRepository::hash_plain_code( '  gc-abcd-1234  ' );
		$this->assertSame( $h1, $h2 );
	}
}
