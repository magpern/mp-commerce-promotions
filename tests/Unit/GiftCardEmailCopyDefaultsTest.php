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
		$this->assertFalse( GiftCardEmailCopyDefaults::is_known_smoke_string( 'Real support line' ) );
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
