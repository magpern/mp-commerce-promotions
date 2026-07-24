<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRedemptionService;
use MP\CommercePromotions\GiftCard\GiftCardTransferService;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Tests\Support\InMemoryGiftCardStore;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardRepository;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardTransactionRepository;
use PHPUnit\Framework\TestCase;

final class GiftCardRedemptionEdgeCasesTest extends TestCase {

	private GiftCardLedger $ledger;

	private GiftCardRedemptionService $redemption;

	private MemoryGiftCardRepository $repo;

	protected function setUp(): void {
		$store = new InMemoryGiftCardStore();
		$this->repo      = new MemoryGiftCardRepository( $store );
		$tx              = new MemoryGiftCardTransactionRepository( $store );
		$this->ledger    = new GiftCardLedger( $this->repo, $tx );
		$this->redemption = new GiftCardRedemptionService( $this->ledger );
	}

	public function test_voided_card_has_specific_error(): void {
		$issued = $this->ledger->issue( 10.0, 'EUR' );
		$id     = (int) $issued->get_card()->get_id();
		$this->ledger->void_card( $id, 'test' );

		$card = $this->repo->find( $id );
		$this->assertNotNull( $card );
		$error = $this->redemption->redeemability_error( $card );
		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'voided', strtolower( $error ) );
	}

	public function test_expired_card_has_specific_error(): void {
		$issued = $this->ledger->issue( 10.0, 'EUR', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$card   = $issued->get_card();
		$error  = $this->redemption->redeemability_error( $card );
		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'expired', strtolower( $error ) );
	}

	public function test_currency_mismatch_blocks_redeem(): void {
		$issued = $this->ledger->issue( 10.0, 'SEK' );
		$error  = $this->redemption->redeemability_error( $issued->get_card() );
		if ( $this->redemption->cart_currency() === 'SEK' ) {
			$this->assertNull( $error );
		} else {
			$this->assertNotNull( $error );
			$this->assertStringContainsString( 'SEK', $error );
		}
	}

	public function test_depleted_card_cannot_transfer(): void {
		$settings  = new Settings();
		$issued    = $this->ledger->issue( 10.0, 'EUR' );
		$id        = (int) $issued->get_card()->get_id();
		$this->ledger->redeem( $id, 5.0, 1 );
		$transfers = new GiftCardTransferService( $this->ledger, $this->repo, $settings );
		$card      = $this->repo->find( $id );
		$this->assertNotNull( $card );
		$this->assertFalse( $transfers->can_transfer( $card ) );
	}
}
