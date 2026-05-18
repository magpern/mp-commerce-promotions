<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardDeliveryMailer;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardIssueResult;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardManualDeliveryStore;
use MP\CommercePromotions\GiftCard\GiftCardManualIssueDelivery;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Tests\Support\InMemoryGiftCardStore;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardRepository;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardTransactionRepository;
use PHPUnit\Framework\TestCase;

final class GiftCardManualIssueDeliveryTest extends TestCase {

	private GiftCardLedger $ledger;

	private GiftCardManualIssueDelivery $delivery;

	protected function setUp(): void {
		global $mp_cp_test_wp_mail_result;
		$mp_cp_test_wp_mail_result = true;

		$store  = new InMemoryGiftCardStore();
		$this->ledger = new GiftCardLedger(
			new MemoryGiftCardRepository( $store ),
			new MemoryGiftCardTransactionRepository( $store )
		);

		$settings = new Settings();
		$settings->set_gift_card_delivery_email_enabled( true );
		$this->delivery = new GiftCardManualIssueDelivery(
			new GiftCardDeliveryMailer( $settings ),
			new GiftCardManualDeliveryStore()
		);
	}

	public function test_manual_issue_with_recipient_calls_mailer(): void {
		$issued = $this->ledger->issue( 25.0, 'EUR', null, 'recipient@example.com' );
		$result = $this->delivery->deliver_after_issue( $issued, 'recipient@example.com' );

		$this->assertSame( GiftCardDeliveryStatus::SENT, $result['delivery_status'] );
		$this->assertSame( 'recipient@example.com', $result['recipient_email'] );
		$this->assertSame( 'recipient@example.com', $result['delivered_to'] ?? '' );

		$id = $issued->get_card()->get_id();
		$this->assertNotNull( $id );
		$stored = ( new GiftCardManualDeliveryStore() )->get( $id );
		$this->assertNotNull( $stored );
		$this->assertSame( GiftCardDeliveryStatus::SENT, $stored['delivery_status'] );
		$this->assertArrayNotHasKey( 'plain_code', $stored );
	}

	public function test_manual_issue_without_recipient_does_not_send(): void {
		$issued = $this->ledger->issue( 10.0, 'EUR' );
		$result = $this->delivery->deliver_after_issue( $issued, null );

		$this->assertSame( GiftCardDeliveryStatus::NOT_REQUESTED, $result['delivery_status'] );
		$this->assertSame( '', $result['recipient_email'] );
	}

	public function test_failed_mail_shows_failed_status(): void {
		global $mp_cp_test_wp_mail_result;
		$mp_cp_test_wp_mail_result = false;

		$issued = $this->ledger->issue( 15.0, 'EUR', null, 'fail@example.com' );
		$result = $this->delivery->deliver_after_issue( $issued, 'fail@example.com' );

		$this->assertSame( GiftCardDeliveryStatus::FAILED, $result['delivery_status'] );
		$this->assertNotEmpty( $result['delivery_error'] ?? '' );
	}

	public function test_no_plain_code_persisted_in_delivery_store(): void {
		$issued = $this->ledger->issue( 5.0, 'EUR', null, 'store@example.com' );
		$this->delivery->deliver_after_issue( $issued, 'store@example.com' );

		$raw = get_option( GiftCardManualDeliveryStore::OPTION_KEY, array() );
		$this->assertIsArray( $raw );
		$encoded = wp_json_encode( $raw );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( $issued->get_plain_code(), $encoded );
	}

	public function test_test_email_uses_sample_code_only(): void {
		$result = $this->delivery->send_test_email( 'postmaster@biopentra.eu' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( GiftCardDeliveryStatus::SENT, $result['delivery_status'] );

		$last = $this->delivery->get_last_test_result();
		$this->assertIsArray( $last );
		$this->assertTrue( $last['ok'] ?? false );
	}
}
