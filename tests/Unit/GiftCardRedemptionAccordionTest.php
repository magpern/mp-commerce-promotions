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

	public function test_checkout_uses_compact_accordion_markup(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Woo/GiftCardRedemptionCheckout.php'
		);
		$this->assertStringContainsString( 'mp-cp-credit-accordion', $src );
		$this->assertStringContainsString( 'mp-cp-credit-accordion__toggle', $src );
		$this->assertStringContainsString( 'mp-cp-credit-accordion__header-main', $src );
		$this->assertStringContainsString( 'mp-cp-credit-accordion__summary-text', $src );
		$this->assertStringContainsString( 'mp-cp-credit-accordion__form-row', $src );
		$this->assertStringContainsString( 'mp-cp-credit-chip', $src );
		$this->assertStringContainsString( 'mp_cp_gift_card_nonce', $src );
		$this->assertStringNotContainsString( 'mp-cp-gc-title', $src );
	}

	public function test_cart_uses_link_disclosure_after_cart_table(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Woo/GiftCardRedemptionCheckout.php'
		);
		$this->assertStringContainsString( 'woocommerce_after_cart_table', $src );
		$this->assertStringContainsString( 'render_cart_disclosure', $src );
		$this->assertStringContainsString( 'mp-cp-credit-cart-disclosure', $src );
		$this->assertStringContainsString( 'mp-cp-credit-cart-disclosure__trigger', $src );
		$this->assertStringContainsString( 'build_cart_disclosure_trigger_text', $src );
		$this->assertStringNotContainsString( 'woocommerce_cart_coupon', $src );
		$this->assertStringNotContainsString( 'render_cart_inline', $src );
		$this->assertStringNotContainsString( 'mp-cp-credit-accordion--cart', $src );
		$this->assertStringNotContainsString( 'woocommerce_before_cart_collaterals', $src );
		$this->assertStringNotContainsString( 'mp-cp-credit-accordion--cart-collateral', $src );
	}
}
