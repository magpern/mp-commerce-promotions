<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Woo\GiftCardRedemptionCheckout;
use PHPUnit\Framework\TestCase;

final class GiftCardRedemptionAccordionTest extends TestCase {

	public function test_expands_when_gift_card_applied(): void {
		$this->assertTrue(
			GiftCardRedemptionCheckout::should_expand_accordion(
				array( 'gift_card_id' => 1, 'code_last4' => '1234', 'applied_amount' => 10.0 ),
				null,
				0.0,
				false,
				false
			)
		);
	}

	public function test_expands_when_store_credit_applied(): void {
		$this->assertTrue(
			GiftCardRedemptionCheckout::should_expand_accordion(
				null,
				array( 'applied_amount' => 5.0 ),
				0.0,
				true,
				false
			)
		);
	}

	public function test_expands_when_logged_in_wallet_has_balance(): void {
		$this->assertTrue(
			GiftCardRedemptionCheckout::should_expand_accordion(
				null,
				null,
				25.0,
				true,
				false
			)
		);
	}

	public function test_collapsed_when_no_state(): void {
		$this->assertFalse(
			GiftCardRedemptionCheckout::should_expand_accordion(
				null,
				null,
				0.0,
				false,
				false
			)
		);
	}

	public function test_renderer_uses_compact_accordion_markup(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Woo/GiftCardRedemptionCheckout.php'
		);
		$this->assertStringContainsString( 'mp-cp-credit-accordion', $src );
		$this->assertStringContainsString( 'mp-cp-credit-accordion__toggle', $src );
		$this->assertStringContainsString( 'mp-cp-credit-chip', $src );
		$this->assertStringContainsString( 'mp_cp_gift_card_nonce', $src );
		$this->assertStringNotContainsString( 'mp-cp-gc-title', $src );
	}

	public function test_cart_uses_collaterals_hook_not_coupon_row(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Woo/GiftCardRedemptionCheckout.php'
		);
		$this->assertStringContainsString( 'woocommerce_before_cart_collaterals', $src );
		$this->assertStringContainsString( 'render_cart_form', $src );
		$this->assertStringNotContainsString( 'woocommerce_cart_coupon', $src );
	}
}
