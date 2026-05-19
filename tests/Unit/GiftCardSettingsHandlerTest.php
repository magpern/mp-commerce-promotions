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

	public function test_save_gift_card_options_persists_email_copy_fields(): void {
		$_POST['mp_cp_gift_card_email_subject']             = 'Persisted subject {site_title}';
		$_POST['mp_cp_gift_card_email_heading']             = 'Persisted heading';
		$_POST['mp_cp_gift_card_email_intro']                = 'Persisted intro';
		$_POST['mp_cp_gift_card_email_redeem_instructions']  = 'Persisted redeem';
		$_POST['mp_cp_gift_card_email_footer_text']          = 'Persisted footer';
		$_POST['mp_cp_gift_card_support_email_text']         = 'Persisted support';
		$_POST['mp_cp_gift_card_logo_url']                   = 'https://example.com/logo.png';
		$_POST['mp_cp_gift_card_accent_color']               = '#112233';
		$_POST['mp_cp_gift_card_email_style']                = Settings::GIFT_CARD_EMAIL_STYLE_COMMERCE_GROWTH;

		$handler = new GiftCardSettingsHandler( $this->settings );
		$handler->save_gift_card_options_from_post();

		$this->assertSame( 'Persisted subject {site_title}', $this->settings->gift_card_email_subject() );
		$this->assertSame( 'Persisted heading', $this->settings->gift_card_email_heading() );
		$this->assertSame( 'Persisted intro', $this->settings->gift_card_email_intro() );
		$this->assertSame( 'Persisted redeem', $this->settings->gift_card_email_redeem_instructions() );
		$this->assertSame( 'Persisted footer', $this->settings->gift_card_email_footer_text() );
		$this->assertSame( 'Persisted support', $this->settings->gift_card_support_email_text() );
		$this->assertSame( 'https://example.com/logo.png', $this->settings->gift_card_logo_url() );
		$this->assertSame( '#112233', $this->settings->gift_card_accent_color() );

		foreach (
			array(
				'mp_cp_gift_card_email_subject',
				'mp_cp_gift_card_email_heading',
				'mp_cp_gift_card_email_intro',
				'mp_cp_gift_card_email_redeem_instructions',
				'mp_cp_gift_card_email_footer_text',
				'mp_cp_gift_card_support_email_text',
				'mp_cp_gift_card_logo_url',
				'mp_cp_gift_card_accent_color',
				'mp_cp_gift_card_email_style',
			) as $key
		) {
			unset( $_POST[ $key ] );
		}
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
