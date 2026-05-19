<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GiftCardSettingsPageRelocationTest extends TestCase {

	public function test_global_settings_page_no_longer_renders_gift_card_delivery_checkbox(): void {
		$path = dirname( __DIR__, 2 ) . '/src/Admin/SettingsPage.php';
		$src  = (string) file_get_contents( $path );
		$this->assertStringNotContainsString( 'mp_cp_gift_card_delivery_email_enabled', $src );
		$this->assertStringContainsString( 'render_gift_card_settings_moved_notice', $src );
	}

	public function test_gift_card_settings_handler_defines_module_save_fields(): void {
		$this->assertSame( 'mp_cp_save_gift_card_settings_submit', \MP\CommercePromotions\Admin\GiftCardSettingsHandler::SUBMIT_FIELD );
		$this->assertSame( 'mp_cp_gift_card_settings_nonce', \MP\CommercePromotions\Admin\GiftCardSettingsHandler::NONCE_FIELD );
	}
}
