<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Service\Settings;
use PHPUnit\Framework\TestCase;

final class GiftCardSenderModeSettingsTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		$this->settings = new Settings();
		delete_option( Settings::OPTION_GIFT_CARD_SENDER_MODE );
		delete_option( Settings::OPTION_GIFT_CARD_SENDER_EMAIL );
	}

	public function test_missing_option_returns_default(): void {
		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_DEFAULT, $this->settings->gift_card_sender_mode() );
	}

	public function test_empty_option_returns_default(): void {
		update_option( Settings::OPTION_GIFT_CARD_SENDER_MODE, '', false );
		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_DEFAULT, $this->settings->gift_card_sender_mode() );
	}

	public function test_invalid_option_returns_default(): void {
		update_option( Settings::OPTION_GIFT_CARD_SENDER_MODE, 'smtp_override', false );
		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_DEFAULT, $this->settings->gift_card_sender_mode() );
	}

	public function test_custom_without_valid_email_returns_default(): void {
		update_option( Settings::OPTION_GIFT_CARD_SENDER_MODE, Settings::GIFT_CARD_SENDER_MODE_CUSTOM, false );
		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_DEFAULT, $this->settings->gift_card_sender_mode() );
		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_CUSTOM, $this->settings->gift_card_sender_mode_stored() );
	}

	public function test_custom_with_valid_email_returns_custom(): void {
		update_option( Settings::OPTION_GIFT_CARD_SENDER_MODE, Settings::GIFT_CARD_SENDER_MODE_CUSTOM, false );
		$this->settings->set_gift_card_sender_email( 'gifts@store.test' );
		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_CUSTOM, $this->settings->gift_card_sender_mode() );
	}

	public function test_normalize_static_matches_getter(): void {
		$this->assertSame(
			Settings::GIFT_CARD_SENDER_MODE_DEFAULT,
			Settings::normalize_gift_card_sender_mode( false )
		);
		$this->assertSame(
			Settings::GIFT_CARD_SENDER_MODE_DEFAULT,
			Settings::normalize_gift_card_sender_mode( 'not-a-mode' )
		);
		$this->assertSame(
			Settings::GIFT_CARD_SENDER_MODE_CUSTOM,
			Settings::normalize_gift_card_sender_mode(
				Settings::GIFT_CARD_SENDER_MODE_CUSTOM,
				'gifts@store.test',
				true
			)
		);
	}
}
