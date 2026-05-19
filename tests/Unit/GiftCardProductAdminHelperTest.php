<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardProductAdminHelper;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use PHPUnit\Framework\TestCase;

final class GiftCardProductAdminHelperTest extends TestCase {

	public function test_amount_preview_product_price(): void {
		$text = GiftCardProductAdminHelper::amount_preview_text(
			array(
				'sells'          => true,
				'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE,
				'fixed_amount'   => 0.0,
				'expiry_days'    => 365,
				'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL,
			),
			30.0,
			'EUR'
		);
		$this->assertStringContainsString( '30', $text );
		$this->assertStringContainsString( 'after payment', strtolower( $text ) );
	}

	public function test_virtual_warning_when_not_virtual(): void {
		$warn = GiftCardProductAdminHelper::virtual_product_warning( false );
		$this->assertStringContainsString( 'virtual', strtolower( $warn ) );
	}

	public function test_amount_mode_options_labels(): void {
		$options = GiftCardProductAdminHelper::amount_mode_options();
		$this->assertArrayHasKey( GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE, $options );
		$this->assertArrayHasKey( GiftCardProductMeta::AMOUNT_MODE_FIXED, $options );
		$this->assertArrayHasKey( GiftCardProductMeta::AMOUNT_MODE_CUSTOMER_AMOUNT, $options );
	}

	public function test_recipient_mode_options_labels(): void {
		$options = GiftCardProductAdminHelper::recipient_mode_options();
		$this->assertArrayHasKey( GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY, $options );
		$this->assertArrayHasKey( GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE, $options );
	}

	public function test_fixed_amount_input_blank_for_product_price_mode(): void {
		$value = GiftCardProductAdminHelper::fixed_amount_input_value(
			array(
				'sells'          => true,
				'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE,
				'fixed_amount'   => 25.0,
				'expiry_days'    => null,
				'recipient_mode' => GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY,
			)
		);
		$this->assertSame( '', $value );
	}

	public function test_expiry_days_preserves_zero(): void {
		$this->assertSame( '0', GiftCardProductAdminHelper::expiry_days_input_value( 0 ) );
		$this->assertSame( '', GiftCardProductAdminHelper::expiry_days_input_value( null ) );
	}

	public function test_variable_parent_admin_notice(): void {
		$notice = GiftCardProductAdminHelper::variable_parent_admin_notice();
		$this->assertStringContainsString( 'variation', strtolower( $notice ) );
	}

	public function test_admin_uses_dedicated_product_data_tab(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Woo/GiftCardProductAdmin.php'
		);
		$this->assertStringNotContainsString( 'woocommerce_product_options_general_product_data', $src );
		$this->assertStringContainsString( 'woocommerce_product_data_tabs', $src );
		$this->assertStringContainsString( 'mp_cp_gift_card_product_data', $src );
		$this->assertStringContainsString( 'woocommerce_wp_select', $src );
		$this->assertStringContainsString( 'woocommerce_wp_text_input', $src );
		$this->assertStringContainsString( 'Gift card amount mode', $src );
		$this->assertStringContainsString( 'Fixed gift card amount', $src );
		$this->assertStringContainsString( 'Expiry days', $src );
		$this->assertStringContainsString( '0 = no expiry', $src );
		$this->assertStringContainsString( 'Recipient mode', $src );
		$this->assertStringContainsString( 'show_if_mp_cp_sells_gift_card', $src );
		$this->assertStringContainsString( 'gift-card-product-admin.js', $src );
	}
}
