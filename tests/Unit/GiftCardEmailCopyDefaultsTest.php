<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardEmailCopyDefaults;
use MP\CommercePromotions\GiftCard\GiftCardEmailPlaceholders;
use MP\CommercePromotions\Service\Settings;
use PHPUnit\Framework\TestCase;

final class GiftCardEmailCopyDefaultsTest extends TestCase {

	public function test_replaces_exact_smoke_strings_only(): void {
		$this->assertSame(
			GiftCardEmailPlaceholders::default_subject(),
			GiftCardEmailCopyDefaults::replace_known_smoke_string( 'Smoke persist subject' )
		);
		$this->assertSame(
			'Custom merchant subject',
			GiftCardEmailCopyDefaults::replace_known_smoke_string( 'Custom merchant subject' )
		);
	}

	public function test_is_known_smoke_string(): void {
		$this->assertTrue( GiftCardEmailCopyDefaults::is_known_smoke_string( 'Smoke persist support' ) );
		$this->assertTrue( GiftCardEmailCopyDefaults::is_known_smoke_string( 'Merchant QA subject line' ) );
		$this->assertFalse( GiftCardEmailCopyDefaults::is_known_smoke_string( 'Real support line' ) );
	}

	public function test_merchant_qa_strings_map_to_production_defaults(): void {
		$this->assertSame(
			GiftCardEmailPlaceholders::default_subject(),
			GiftCardEmailCopyDefaults::replace_known_smoke_string( 'Merchant QA subject line' )
		);
		$this->assertSame(
			GiftCardEmailPlaceholders::default_intro(),
			GiftCardEmailCopyDefaults::replace_known_smoke_string( 'Custom preview intro text.' )
		);
		$this->assertSame(
			GiftCardEmailPlaceholders::default_heading(),
			GiftCardEmailCopyDefaults::replace_known_smoke_string( 'Real custom preview heading' )
		);
		$this->assertSame(
			GiftCardEmailPlaceholders::default_intro(),
			GiftCardEmailCopyDefaults::replace_known_smoke_string( 'Real custom preview intro text.' )
		);
	}

	public function test_real_custom_preview_strings_cleaned_on_save_and_read(): void {
		$settings = new Settings();
		$settings->set_gift_card_email_heading( 'Real custom preview heading' );
		$settings->set_gift_card_email_intro( 'Real custom preview intro text.' );
		$this->assertSame( GiftCardEmailPlaceholders::default_heading(), $settings->gift_card_email_heading() );
		$this->assertSame( GiftCardEmailPlaceholders::default_intro(), $settings->gift_card_email_intro() );
	}

	public function test_reset_save_reload_keeps_production_defaults(): void {
		$settings = new Settings();
		$settings->set_gift_card_email_heading( 'Real custom preview heading' );
		$settings->set_gift_card_email_intro( 'Real custom preview intro text.' );
		( new \MP\CommercePromotions\GiftCard\GiftCardEmailTemplateReset() )->apply( $settings );
		$settings->set_gift_card_email_heading( $settings->gift_card_email_heading() );
		$settings->set_gift_card_email_intro( $settings->gift_card_email_intro() );
		$reloaded = new Settings();
		$this->assertSame( GiftCardEmailPlaceholders::default_heading(), $reloaded->gift_card_email_heading() );
		$this->assertSame( GiftCardEmailPlaceholders::default_intro(), $reloaded->gift_card_email_intro() );
	}

	public function test_custom_merchant_copy_not_in_qa_list_is_preserved(): void {
		$settings = new Settings();
		$settings->set_gift_card_email_heading( 'Real merchant persist heading' );
		$this->assertSame( 'Real merchant persist heading', $settings->gift_card_email_heading() );
	}

	public function test_qa_accent_color_constant(): void {
		$this->assertTrue( GiftCardEmailCopyDefaults::is_known_qa_accent_color( '#aa5500' ) );
		$this->assertFalse( GiftCardEmailCopyDefaults::is_known_qa_accent_color( '#112233' ) );
	}

	public function test_sanitize_hex_color_normalizes_shorthand(): void {
		$this->assertSame( '#aabbcc', Settings::sanitize_hex_color( '#abc' ) );
		$this->assertSame( '', Settings::sanitize_hex_color( 'not-a-color' ) );
	}

	public function test_invalid_saved_accent_falls_back_to_resolved_default(): void {
		$settings = new Settings();
		$settings->set_gift_card_accent_color( 'invalid' );
		$resolved = $settings->gift_card_accent_color();
		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $resolved );
		$this->assertSame( Settings::resolve_default_gift_card_accent_color(), $resolved );
	}

	public function test_production_default_intro_copy(): void {
		$this->assertStringContainsString(
			'eligible purchases',
			GiftCardEmailPlaceholders::default_intro()
		);
		$this->assertStringContainsString(
			'Need help?',
			GiftCardEmailPlaceholders::default_support_text()
		);
	}
}
