<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Admin\AdminNavigation;
use PHPUnit\Framework\TestCase;

final class AdminNavigationTest extends TestCase {

	public function test_default_tab_is_campaign_builder(): void {
		$this->assertSame( AdminNavigation::TAB_CAMPAIGN_BUILDER, AdminNavigation::DEFAULT_TAB );
		$this->assertSame( AdminNavigation::TAB_CAMPAIGN_BUILDER, AdminNavigation::sanitize_tab( null ) );
		$this->assertSame( AdminNavigation::TAB_CAMPAIGN_BUILDER, AdminNavigation::sanitize_tab( '' ) );
		$this->assertSame( AdminNavigation::TAB_CAMPAIGN_BUILDER, AdminNavigation::sanitize_tab( 'invalid-tab' ) );
	}

	public function test_explicit_advanced_tab_preserved(): void {
		$this->assertSame( AdminNavigation::TAB_ALL, AdminNavigation::sanitize_tab( AdminNavigation::TAB_ALL ) );
	}
}
