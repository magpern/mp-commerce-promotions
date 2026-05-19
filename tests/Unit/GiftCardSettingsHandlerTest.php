<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Admin\GiftCardSettingsHandler;
use MP\CommercePromotions\Service\Settings;
use PHPUnit\Framework\TestCase;

final class GiftCardSettingsHandlerTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = new Settings();
	}

	public function test_save_gift_card_options_from_post_persists_delivery_toggle(): void {
		$_POST['mp_cp_gift_card_delivery_email_enabled'] = 'yes';

		$handler = new GiftCardSettingsHandler( $this->settings );
		$handler->save_gift_card_options_from_post();

		$this->assertTrue( $this->settings->gift_card_delivery_email_enabled() );

		unset( $_POST['mp_cp_gift_card_delivery_email_enabled'] );
		$this->settings->set_gift_card_delivery_email_enabled( false );
	}

	public function test_post_includes_gift_card_fields_detects_sender_mode(): void {
		$_POST['mp_cp_gift_card_sender_mode'] = Settings::GIFT_CARD_SENDER_MODE_DEFAULT;
		$this->assertTrue( GiftCardSettingsHandler::post_includes_gift_card_fields() );
		unset( $_POST['mp_cp_gift_card_sender_mode'] );
	}

	public function test_invalid_custom_sender_falls_back_to_default(): void {
		$_POST['mp_cp_gift_card_sender_mode']  = Settings::GIFT_CARD_SENDER_MODE_CUSTOM;
		$_POST['mp_cp_gift_card_sender_email'] = 'not-an-email';

		$handler = new GiftCardSettingsHandler( $this->settings );
		$code    = $handler->save_gift_card_options_from_post();

		$this->assertSame( 'sender_invalid_fallback', $code );
		$this->assertSame( Settings::GIFT_CARD_SENDER_MODE_DEFAULT, $this->settings->gift_card_sender_mode() );

		unset( $_POST['mp_cp_gift_card_sender_mode'], $_POST['mp_cp_gift_card_sender_email'] );
	}
}
