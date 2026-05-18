<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransferService;
use MP\CommercePromotions\GiftCard\GiftCardTransferStore;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Tests\Support\InMemoryGiftCardStore;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardRepository;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardTransactionRepository;
use PHPUnit\Framework\TestCase;

final class GiftCardTransferTest extends TestCase {

	private InMemoryGiftCardStore $store;

	private GiftCardRepository $cards;

	private GiftCardLedger $ledger;

	private GiftCardTransferService $transfers;

	protected function setUp(): void {
		global $mp_cp_test_wp_mail_result, $mp_cp_test_options;
		$mp_cp_test_wp_mail_result = true;
		$mp_cp_test_options        = array();

		$this->store     = new InMemoryGiftCardStore();
		$this->cards     = new MemoryGiftCardRepository( $this->store );
		$this->ledger    = new GiftCardLedger(
			$this->cards,
			new MemoryGiftCardTransactionRepository( $this->store )
		);
		$settings = new Settings();
		$settings->set_gift_card_delivery_email_enabled( true );
		$this->transfers = new GiftCardTransferService( $this->ledger, $this->cards, $settings );
	}

	public function test_transfer_unused_card_to_new_recipient(): void {
		$issued = $this->ledger->issue( 40.0, 'EUR', null, 'first@store.test', null, null, 42 );
		$id     = (int) $issued->get_card()->get_id();
		$this->assertNotNull( $id );

		$result = $this->transfers->transfer_to_new_recipient(
			$id,
			'newrecipient@store.test',
			'Admin test transfer',
			GiftCardTransferService::INITIATED_BY_ADMIN
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'plain_code', $result );

		$old = $this->cards->find( $id );
		$this->assertNotNull( $old );
		$this->assertSame( GiftCard::STATUS_VOIDED, $old->get_status() );

		$new_id = (int) ( $result['new_gift_card_id'] ?? 0 );
		$new    = $this->cards->find( $new_id );
		$this->assertNotNull( $new );
		$this->assertSame( 'newrecipient@store.test', $new->get_recipient_email() );
		$this->assertSame( GiftCard::STATUS_ACTIVE, $new->get_status() );

		$store = new GiftCardTransferStore();
		$this->assertSame( $new_id, $store->get_replacement_id( $id ) );
		$this->assertSame( $id, $store->get_source_id( $new_id ) );
	}

	public function test_blocks_transfer_partially_used_card(): void {
		$issued = $this->ledger->issue( 50.0, 'EUR', null, 'holder@store.test' );
		$id     = (int) $issued->get_card()->get_id();
		$this->ledger->redeem( $id, 10.0, 9001 );

		$result = $this->transfers->transfer_to_new_recipient(
			$id,
			'other@store.test',
			'Should fail',
			GiftCardTransferService::INITIATED_BY_ADMIN
		);

		$this->assertFalse( $result['success'] );
		$card = $this->cards->find( $id );
		$this->assertNotNull( $card );
		$this->assertSame( GiftCard::STATUS_ACTIVE, $card->get_status() );
	}

	public function test_recipient_email_persisted_for_unregistered_recipient(): void {
		$issued = $this->ledger->issue( 20.0, 'EUR', null, 'unregistered@store.test' );
		$card   = $issued->get_card();
		$this->assertSame( 'unregistered@store.test', $card->get_recipient_email() );

		$stored = $this->cards->find( (int) $card->get_id() );
		$this->assertNotNull( $stored );
		$this->assertSame( 'unregistered@store.test', $stored->get_recipient_email() );
	}

	public function test_transfer_updates_recipient_for_account_visibility(): void {
		$issued = $this->ledger->issue( 12.0, 'EUR', null, 'before@store.test' );
		$id     = (int) $issued->get_card()->get_id();

		$result = $this->transfers->transfer_to_new_recipient(
			$id,
			'after@store.test',
			'Email change',
			GiftCardTransferService::INITIATED_BY_ADMIN
		);
		$this->assertTrue( $result['success'] );

		$new = $this->cards->find( (int) ( $result['new_gift_card_id'] ?? 0 ) );
		$this->assertNotNull( $new );
		$this->assertSame( 'after@store.test', $new->get_recipient_email() );
	}

	public function test_no_plain_code_in_transfer_option(): void {
		$issued = $this->ledger->issue( 15.0, 'EUR', null, 'from@store.test' );
		$plain  = $issued->get_plain_code();
		$id     = (int) $issued->get_card()->get_id();

		$this->transfers->transfer_to_new_recipient(
			$id,
			'to@store.test',
			'No code in storage',
			GiftCardTransferService::INITIATED_BY_ADMIN
		);

		$raw = get_option( GiftCardTransferStore::OPTION_KEY, array() );
		$encoded = wp_json_encode( $raw );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( $plain, $encoded );
	}

	public function test_customer_transfer_requires_purchaser(): void {
		$issued = $this->ledger->issue( 25.0, 'EUR', null, 'gift@store.test', null, null, 99 );
		$id     = (int) $issued->get_card()->get_id();

		$denied = $this->transfers->transfer_to_new_recipient(
			$id,
			'other@store.test',
			'Wrong owner',
			GiftCardTransferService::INITIATED_BY_CUSTOMER,
			42
		);
		$this->assertFalse( $denied['success'] );

		$allowed = $this->transfers->transfer_to_new_recipient(
			$id,
			'other@store.test',
			'Owner transfer',
			GiftCardTransferService::INITIATED_BY_CUSTOMER,
			99
		);
		$this->assertTrue( $allowed['success'] );
	}

	public function test_is_fully_unused_helper(): void {
		$issued = $this->ledger->issue( 30.0, 'EUR' );
		$this->assertTrue( $issued->get_card()->is_fully_unused() );

		$id = (int) $issued->get_card()->get_id();
		$this->ledger->redeem( $id, 5.0, 1 );
		$after = $this->cards->find( $id );
		$this->assertNotNull( $after );
		$this->assertFalse( $after->is_fully_unused() );
	}
}
