<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardDeliveryMailer;
use MP\CommercePromotions\GiftCard\GiftCardEmailPreview;
use MP\CommercePromotions\GiftCard\GiftCardEmailRenderer;
use MP\CommercePromotions\GiftCard\GiftCardEmailTemplate;
use MP\CommercePromotions\GiftCard\GiftCardManualIssueDelivery;
use MP\CommercePromotions\GiftCard\GiftCardWooEmailStyler;
use MP\CommercePromotions\Service\Settings;
use PHPUnit\Framework\TestCase;

final class GiftCardEmailConfigurationTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		$this->settings = new Settings();
		$this->settings->set_gift_card_email_template( Settings::GIFT_CARD_TEMPLATE_CLASSIC );
		$this->settings->set_gift_card_email_style( Settings::GIFT_CARD_EMAIL_STYLE_COMMERCE_GROWTH );
		$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_DEFAULT );
	}

	public function test_invalid_template_slug_falls_back_to_classic(): void {
		$html = GiftCardEmailTemplate::render_html(
			'not-a-real-template',
			array(
				'site_name' => 'Store',
				'store_url' => '',
				'accent'    => '#2271b1',
				'logo_url'  => '',
				'support_text' => '',
				'footer_text'  => '',
				'cards'     => array(
					array(
						'masked_code' => GiftCardEmailPreview::SAMPLE_MASKED_CODE,
						'amount'      => 10.0,
						'currency'    => 'EUR',
					),
				),
				'preview'   => true,
			)
		);

		$this->assertStringContainsString( 'Your gift card', $html );
		$this->assertStringNotContainsString( 'REALCODE12345', $html );
	}

	public function test_preview_uses_sample_code_only(): void {
		$html = GiftCardEmailPreview::render( $this->settings );

		$this->assertStringContainsString( GiftCardEmailPreview::SAMPLE_MASKED_CODE, $html );
		$this->assertStringNotContainsString( 'plain_code', $html );
	}

	public function test_woo_style_falls_back_when_unavailable(): void {
		$this->settings->set_gift_card_email_style( Settings::GIFT_CARD_EMAIL_STYLE_WOOCOMMERCE );

		if ( GiftCardWooEmailStyler::is_available() ) {
			$this->markTestSkipped( 'WooCommerce mailer present in this environment.' );
		}

		$this->assertSame( Settings::GIFT_CARD_EMAIL_STYLE_COMMERCE_GROWTH, $this->settings->effective_gift_card_email_style() );
		$html = GiftCardEmailPreview::render( $this->settings );
		$this->assertStringContainsString( '****SAMPLE', $html );
		$this->assertStringNotContainsString( 'SHOULD-NOT-APPEAR', $html );
	}

	public function test_test_email_uses_sample_code_only(): void {
		global $mp_cp_test_wp_mail_bodies;
		$mp_cp_test_wp_mail_bodies = array();

		if ( ! function_exists( 'wp_mail' ) ) {
			$this->markTestSkipped( 'wp_mail stub unavailable.' );
		}

		$this->settings->set_gift_card_delivery_email_enabled( true );
		$mailer = new GiftCardDeliveryMailer( $this->settings );
		$result = $mailer->send_test_delivery_email( 'qa@store.test', 50.0, 'EUR' );

		$this->assertSame( GiftCardManualIssueDelivery::TEST_SAMPLE_CODE, '****TEST' );
		$this->assertArrayHasKey( 'sender_mode_used', $result );
		$combined = implode( "\n", $mp_cp_test_wp_mail_bodies );
		$this->assertStringContainsString( '****TEST', $combined );
		$this->assertDoesNotMatchRegularExpression( '/[A-Z0-9]{12,}/', $combined );
	}

	public function test_per_template_settings_resolve(): void {
		$this->settings->set_gift_card_accent_color( '#111111' );
		$this->settings->set_gift_card_email_template_settings(
			Settings::GIFT_CARD_TEMPLATE_BIRTHDAY,
			array(
				'logo_url'     => '',
				'accent_color' => '#ff00ff',
				'footer_text'  => 'Birthday footer',
				'support_text' => '',
			)
		);

		$resolved = $this->settings->resolve_gift_card_email_appearance( Settings::GIFT_CARD_TEMPLATE_BIRTHDAY );
		$this->assertSame( '#ff00ff', $resolved['accent_color'] );
		$this->assertSame( 'Birthday footer', $resolved['footer_text'] );
	}
}
