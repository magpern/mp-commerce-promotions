<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Admin\AdminNavigation;
use MP\CommercePromotions\Admin\PluginActionLinks;
use PHPUnit\Framework\TestCase;

final class PluginActionLinksTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['mp_cp_test_current_user_can'] );
		parent::tearDown();
	}

	public function test_links_for_capable_user(): void {
		$GLOBALS['mp_cp_test_current_user_can'] = true;
		$original                               = array( 'deactivate' => '<a>Deactivate</a>' );
		$links                                  = ( new PluginActionLinks() )->links( $original );

		$this->assertArrayHasKey( 'promotions', $links );
		$this->assertArrayHasKey( 'settings', $links );
		$this->assertArrayHasKey( 'deactivate', $links );
		$this->assertStringContainsString( 'Promotions', $links['promotions'] );
		$this->assertStringContainsString( 'Settings', $links['settings'] );
		$this->assertStringContainsString( 'page=' . AdminNavigation::PAGE_SLUG, $links['promotions'] );
		$this->assertStringContainsString( 'tab=' . AdminNavigation::TAB_ALL, $links['promotions'] );
		$this->assertStringContainsString( 'tab=' . AdminNavigation::TAB_SETTINGS, $links['settings'] );
	}

	public function test_links_hidden_without_capability(): void {
		$GLOBALS['mp_cp_test_current_user_can'] = false;
		$original                               = array( 'deactivate' => '<a>Deactivate</a>' );
		$links                                  = ( new PluginActionLinks() )->links( $original );

		$this->assertSame( $original, $links );
	}
}
