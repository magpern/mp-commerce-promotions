<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Admin\GiftCardModuleSections;
use PHPUnit\Framework\TestCase;

final class GiftCardModuleSectionsTest extends TestCase {

	protected function tearDown(): void {
		unset( $_GET[ GiftCardModuleSections::QUERY_ARG ] );
		unset( $_GET[ GiftCardModuleSections::LEGACY_PANEL_ARG ] );
		unset( $_GET['gift_card_id'] );
		parent::tearDown();
	}

	public function test_default_section_is_dashboard(): void {
		$this->assertSame( GiftCardModuleSections::SECTION_DASHBOARD, GiftCardModuleSections::default_section() );
		$this->assertSame( GiftCardModuleSections::SECTION_DASHBOARD, GiftCardModuleSections::current_section() );
	}

	public function test_normalize_unknown_section_to_dashboard(): void {
		$this->assertSame(
			GiftCardModuleSections::SECTION_DASHBOARD,
			GiftCardModuleSections::normalize_section( 'not-a-section' )
		);
	}

	public function test_settings_section_url_includes_query_arg(): void {
		$url = GiftCardModuleSections::section_url( GiftCardModuleSections::SECTION_SETTINGS );
		$this->assertStringContainsString( 'tab=gift-cards', $url );
		$this->assertStringContainsString(
			GiftCardModuleSections::QUERY_ARG . '=' . GiftCardModuleSections::SECTION_SETTINGS,
			$url
		);
	}

	public function test_legacy_panel_maps_to_gift_cards_section(): void {
		$_GET[ GiftCardModuleSections::LEGACY_PANEL_ARG ] = GiftCardModuleSections::LEGACY_PANEL_GIFT_CARDS;
		$this->assertSame( GiftCardModuleSections::SECTION_GIFT_CARDS, GiftCardModuleSections::current_section() );
	}

}
