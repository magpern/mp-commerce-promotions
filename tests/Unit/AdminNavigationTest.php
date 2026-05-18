<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Admin\AdminNavigation;
use MP\CommercePromotions\Admin\AdminRouter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AdminNavigationTest extends TestCase {

	public function test_normalize_tab_missing_empty_invalid_default_to_campaign_builder(): void {
		$this->assertSame( AdminNavigation::TAB_CAMPAIGN_BUILDER, AdminNavigation::normalize_tab( null ) );
		$this->assertSame( AdminNavigation::TAB_CAMPAIGN_BUILDER, AdminNavigation::normalize_tab( '' ) );
		$this->assertSame( AdminNavigation::TAB_CAMPAIGN_BUILDER, AdminNavigation::normalize_tab( 'not-a-real-tab' ) );
	}

	public function test_normalize_tab_explicit_campaign_builder(): void {
		$this->assertSame(
			AdminNavigation::TAB_CAMPAIGN_BUILDER,
			AdminNavigation::normalize_tab( AdminNavigation::TAB_CAMPAIGN_BUILDER )
		);
	}

	public function test_normalize_tab_all_and_other_allowed_tabs(): void {
		$this->assertSame( AdminNavigation::TAB_ALL, AdminNavigation::normalize_tab( AdminNavigation::TAB_ALL ) );
		$this->assertSame( AdminNavigation::TAB_REPORTS, AdminNavigation::normalize_tab( AdminNavigation::TAB_REPORTS ) );
		$this->assertSame( AdminNavigation::TAB_DIAGNOSTICS, AdminNavigation::normalize_tab( AdminNavigation::TAB_DIAGNOSTICS ) );
		$this->assertSame( AdminNavigation::TAB_SETTINGS, AdminNavigation::normalize_tab( AdminNavigation::TAB_SETTINGS ) );
		$this->assertSame(
			AdminNavigation::TAB_GETTING_STARTED,
			AdminNavigation::normalize_tab( AdminNavigation::TAB_GETTING_STARTED )
		);
		$this->assertSame(
			AdminNavigation::TAB_GIFT_CARDS,
			AdminNavigation::normalize_tab( AdminNavigation::TAB_GIFT_CARDS )
		);
	}

	public function test_allowed_tabs_includes_gift_cards(): void {
		$this->assertContains( AdminNavigation::TAB_GIFT_CARDS, AdminNavigation::allowed_tabs() );
	}

	public function test_sanitize_tab_delegates_to_normalize_tab(): void {
		$this->assertSame( AdminNavigation::TAB_CAMPAIGN_BUILDER, AdminNavigation::sanitize_tab( null ) );
		$this->assertSame( AdminNavigation::TAB_ALL, AdminNavigation::sanitize_tab( AdminNavigation::TAB_ALL ) );
	}

	public function test_default_tab_is_campaign_builder(): void {
		$this->assertSame( AdminNavigation::TAB_CAMPAIGN_BUILDER, AdminNavigation::DEFAULT_TAB );
	}

	public function test_router_switch_includes_campaign_builder_case(): void {
		$source = (string) file_get_contents(
			( new ReflectionClass( AdminRouter::class ) )->getFileName()
		);
		$this->assertStringContainsString(
			'case AdminNavigation::TAB_CAMPAIGN_BUILDER:',
			$source
		);
		$this->assertStringContainsString(
			'case AdminNavigation::TAB_GIFT_CARDS:',
			$source
		);
		$this->assertStringContainsString( 'normalize_tab', $source );
	}
}
