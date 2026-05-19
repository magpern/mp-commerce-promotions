<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardDeliveryMailer;
use MP\CommercePromotions\GiftCard\GiftCardEmailCopy;
use MP\CommercePromotions\GiftCard\GiftCardEmailPlaceholders;
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
		$this->settings->set_gift_card_email_template( Settings::GIFT_CARD_TEMPLATE_BIRTHDAY );
		$this->settings->set_gift_card_email_style( Settings::GIFT_CARD_EMAIL_STYLE_COMMERCE_GROWTH );
		$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_DEFAULT );
	}

	public function test_old_template_slugs_normalize_to_classic(): void {
		$this->assertSame( Settings::GIFT_CARD_TEMPLATE_CLASSIC, Settings::normalize_gift_card_email_template_slug( 'birthday' ) );
		$this->assertSame( Settings::GIFT_CARD_TEMPLATE_CLASSIC, Settings::normalize_gift_card_email_template_slug( 'holiday' ) );
		$this->assertSame( Settings::GIFT_CARD_TEMPLATE_CLASSIC, $this->settings->gift_card_email_template() );
		$this->assertSame( Settings::GIFT_CARD_TEMPLATE_CLASSIC, GiftCardEmailTemplate::normalize_slug( 'invalid-template-slug' ) );
	}

	public function test_invalid_template_slug_falls_back_to_classic_layout(): void {
		$html = GiftCardEmailTemplate::render_html(
			'not-a-real-template',
			array(
				'store_url'           => '',
				'email_heading'       => 'Custom heading',
				'intro_text'          => 'Custom intro',
				'redeem_instructions' => 'Custom redeem',
				'accent'              => '#2271b1',
				'logo_url'            => '',
				'support_text'        => '',
				'footer_text'         => '',
				'cards'               => array(
					array(
						'masked_code' => GiftCardEmailPreview::SAMPLE_MASKED_CODE,
						'amount'      => 10.0,
						'currency'    => 'EUR',
					),
				),
				'preview'             => true,
			)
		);

		$this->assertStringContainsString( 'Custom heading', $html );
		$this->assertStringContainsString( 'Custom intro', $html );
		$this->assertStringNotContainsString( 'REALCODE12345', $html );
	}

	public function test_preview_uses_sample_code_only(): void {
		$html = GiftCardEmailPreview::render( $this->settings );

		$this->assertStringContainsString( GiftCardEmailPreview::SAMPLE_MASKED_CODE, $html );
		$this->assertStringNotContainsString( 'plain_code', $html );
	}

	public function test_placeholders_render_in_subject_and_body(): void {
		$this->settings->set_gift_card_email_subject( 'Gift from {site_title} — {amount}' );
		$this->settings->set_gift_card_email_heading( 'Hello {recipient_name}' );
		$this->settings->set_gift_card_email_intro( 'Message: {message}' );

		$vars = GiftCardEmailPlaceholders::preview_variables( $this->settings, 25.0, 'EUR' );
		$copy = GiftCardEmailCopy::resolve( $this->settings, $vars );

		$this->assertStringContainsString( GiftCardEmailPlaceholders::site_title(), $copy['subject'] );
		$this->assertStringContainsString( 'Hello', $copy['heading'] );
		$this->assertStringContainsString( 'Enjoy your gift!', $copy['intro'] );

		$html = GiftCardEmailPreview::render( $this->settings );
		$this->assertStringContainsString( 'Hello', $html );
		$this->assertStringContainsString( 'Enjoy your gift!', $html );
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

	public function test_legacy_per_template_appearance_merges_when_global_empty(): void {
		update_option( Settings::OPTION_GIFT_CARD_ACCENT_COLOR, '', false );
		update_option( Settings::OPTION_GIFT_CARD_EMAIL_FOOTER_TEXT, '', false );
		update_option(
			Settings::OPTION_GIFT_CARD_EMAIL_TEMPLATE_SETTINGS,
			array(
				Settings::GIFT_CARD_TEMPLATE_BIRTHDAY => array(
					'logo_url'     => '',
					'accent_color' => '#ff00ff',
					'footer_text'  => 'Birthday footer',
					'support_text' => '',
				),
			),
			false
		);

		$settings = new Settings();
		$resolved = $settings->resolve_gift_card_email_appearance();
		$this->assertSame( '#ff00ff', $resolved['accent_color'] );
	}

	public function test_format_amount_display_decodes_nbsp_entities(): void {
		$display = GiftCardEmailPlaceholders::format_amount_display( 25.0, 'EUR' );

		$this->assertNotSame( '', $display );
		$this->assertStringNotContainsString( '&nbsp;', $display );
		$this->assertStringNotContainsString( '&#160;', $display );
	}

	public function test_preview_html_does_not_show_escaped_entities(): void {
		$html = GiftCardEmailPreview::render( $this->settings, null, 25.0, 'EUR' );

		$this->assertStringNotContainsString( '&nbsp;', $html );
		$this->assertStringNotContainsString( '&#160;', $html );
		$amount_display = GiftCardEmailPlaceholders::format_amount_display( 25.0, 'EUR' );
		if ( $amount_display !== '' ) {
			$this->assertStringContainsString( $amount_display, $html );
		}
	}

	public function test_sanitize_overrides_from_array_for_ajax_preview(): void {
		$data = array(
			'mp_cp_gift_card_email_heading' => 'Ajax preview heading',
			'mp_cp_gift_card_accent_color'  => '#abcdef',
		);
		$overrides = GiftCardEmailCopy::sanitize_overrides_from_array( $data );

		$this->assertIsArray( $overrides );
		$this->assertSame( 'Ajax preview heading', $overrides['heading'] ?? '' );
		$this->assertSame( '#abcdef', $overrides['accent_color'] ?? '' );

		$html = GiftCardEmailPreview::render( $this->settings, null, 25.0, 'EUR', $overrides );
		$this->assertStringContainsString( 'Ajax preview heading', $html );
		$this->assertStringContainsString( '#abcdef', $html );
	}

	public function test_default_copy_strings_are_production_ready(): void {
		$this->assertStringContainsString( '{site_title}', GiftCardEmailPlaceholders::default_subject() );
		$this->assertStringContainsString( 'gift card', strtolower( GiftCardEmailPlaceholders::default_heading() ) );
		$this->assertStringContainsString( 'checkout', strtolower( GiftCardEmailPlaceholders::default_redeem_instructions() ) );
		$this->assertStringNotContainsString( 'Smoke persist', GiftCardEmailPlaceholders::default_subject() );
	}

	public function test_smoke_string_cleanup_on_read(): void {
		update_option( Settings::OPTION_GIFT_CARD_EMAIL_SUBJECT, 'Smoke persist subject', false );
		$settings = new Settings();
		$this->assertSame( GiftCardEmailPlaceholders::default_subject(), $settings->gift_card_email_subject() );
		$this->assertSame(
			GiftCardEmailPlaceholders::default_subject(),
			get_option( Settings::OPTION_GIFT_CARD_EMAIL_SUBJECT )
		);
	}

	public function test_custom_subject_via_renderer(): void {
		$this->settings->set_gift_card_email_subject( 'Card for {recipient_name}' );
		$subject = GiftCardEmailRenderer::resolve_subject(
			$this->settings,
			array(
				'amount'         => 10.0,
				'currency'       => 'EUR',
				'recipient_name' => 'Alex',
				'masked_code'    => GiftCardEmailPreview::SAMPLE_MASKED_CODE,
			),
			true,
			false
		);
		$this->assertStringContainsString( 'Alex', $subject );
	}
}
