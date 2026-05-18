<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardDeliveryMailer;
use MP\CommercePromotions\GiftCard\GiftCardEmailSender;
use MP\CommercePromotions\GiftCard\GiftCardManualIssueDelivery;
use MP\CommercePromotions\Service\Settings;
use PHPUnit\Framework\TestCase;

final class GiftCardEmailSenderTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		$this->settings = new Settings();
		$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_DEFAULT );
		$this->settings->set_gift_card_sender_name( '' );
		$this->settings->set_gift_card_sender_email( '' );
		$this->settings->set_gift_card_reply_to_email( '' );
	}

	public function test_default_mode_does_not_force_from_header(): void {
		$this->settings->set_gift_card_sender_email( 'orphan@example.org' );
		$sender = new GiftCardEmailSender( $this->settings );
		$resolved = $sender->resolve_for_send( 'Test Store' );

		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_DEFAULT, $resolved['mode'] );
		$this->assertFalse( $resolved['from_header_set'] );
		$this->assertSame( array(), $resolved['headers'] );
	}

	public function test_custom_mode_sets_from_and_reply_to(): void {
		$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_CUSTOM );
		$this->settings->set_gift_card_sender_name( 'Gift Shop' );
		$this->settings->set_gift_card_sender_email( 'gifts@store.test' );
		$this->settings->set_gift_card_reply_to_email( 'support@store.test' );

		$resolved = ( new GiftCardEmailSender( $this->settings ) )->resolve_for_send( 'Test Store' );

		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_CUSTOM, $resolved['mode'] );
		$this->assertTrue( $resolved['from_header_set'] );
		$this->assertTrue( $resolved['reply_to_set'] );
		$this->assertCount( 2, $resolved['headers'] );
		$this->assertStringContainsString( 'From: Gift Shop <gifts@store.test>', $resolved['headers'][0] );
		$this->assertStringContainsString( 'Reply-To: support@store.test', $resolved['headers'][1] );
	}

	public function test_invalid_custom_sender_falls_back_to_default(): void {
		$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_CUSTOM );
		$this->settings->set_gift_card_sender_email( 'not-valid' );

		$sender = new GiftCardEmailSender( $this->settings );
		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_DEFAULT, $sender->effective_mode() );
		$this->assertFalse( $sender->resolve_for_send()['from_header_set'] );
	}

	public function test_diagnostics_analyze_includes_sender_mode(): void {
		$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_CUSTOM );
		$this->settings->set_gift_card_sender_email( 'sender@store.test' );

		$analysis = ( new GiftCardEmailSender( $this->settings ) )->analyze();

		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_CUSTOM, $analysis['sender_mode'] );
		$this->assertArrayHasKey( 'recommendation', $analysis );
		$this->assertArrayHasKey( 'warnings', $analysis );
	}

	public function test_test_email_uses_sample_code_only(): void {
		global $mp_cp_test_wp_mail_result;
		$mp_cp_test_wp_mail_result = true;

		$this->settings->set_gift_card_delivery_email_enabled( true );
		$mailer = new GiftCardDeliveryMailer( $this->settings );
		$result = $mailer->send_test_delivery_email( 'qa@store.test' );

		$this->assertSame( GiftCardManualIssueDelivery::TEST_SAMPLE_CODE, '****TEST' );
		$this->assertArrayHasKey( 'sender_mode_used', $result );
		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_DEFAULT, $result['sender_mode_used'] );
		$this->assertFalse( $result['from_header_set'] ?? true );
	}
}
