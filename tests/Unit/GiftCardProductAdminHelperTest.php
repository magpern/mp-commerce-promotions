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
}
