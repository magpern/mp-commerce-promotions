<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardEmailCopyDefaults;
use MP\CommercePromotions\GiftCard\GiftCardEmailPlaceholders;
use MP\CommercePromotions\GiftCard\GiftCardEmailTemplateReset;
use MP\CommercePromotions\Service\Settings;
use PHPUnit\Framework\TestCase;

final class GiftCardEmailTemplateResetTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = new Settings();
	}

	public function test_reset_restores_production_defaults(): void {
		$this->settings->set_gift_card_email_subject( 'Merchant QA subject line' );
		$this->settings->set_gift_card_email_heading( 'Custom preview heading' );
		$this->settings->set_gift_card_logo_url( 'https://example.com/logo.png' );
		$this->settings->set_gift_card_accent_color( GiftCardEmailCopyDefaults::QA_ACCENT_COLOR );
		$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_CUSTOM );
		$this->settings->set_gift_card_sender_email( 'merchant@example.com' );
		$this->settings->set_gift_card_sender_name( 'Merchant Shop' );

		( new GiftCardEmailTemplateReset() )->apply( $this->settings );

		$this->assertSame( GiftCardEmailPlaceholders::default_subject(), $this->settings->gift_card_email_subject() );
		$this->assertSame( GiftCardEmailPlaceholders::default_heading(), $this->settings->gift_card_email_heading() );
		$this->assertSame( '', $this->settings->gift_card_logo_url() );
		$this->assertNotSame( GiftCardEmailCopyDefaults::QA_ACCENT_COLOR, $this->settings->gift_card_accent_color_saved() );
		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_CUSTOM, $this->settings->gift_card_sender_mode() );
		$this->assertSame( 'merchant@example.com', $this->settings->gift_card_sender_email() );
		$this->assertSame( 'Merchant Shop', $this->settings->gift_card_sender_name() );
	}

	public function test_qa_accent_migrates_on_read(): void {
		$this->settings->set_gift_card_accent_color( GiftCardEmailCopyDefaults::QA_ACCENT_COLOR );
		$this->assertSame( '', $this->settings->gift_card_accent_color_saved() );
		$this->assertSame( Settings::resolve_default_gift_card_accent_color(), $this->settings->gift_card_accent_color() );
	}
}
