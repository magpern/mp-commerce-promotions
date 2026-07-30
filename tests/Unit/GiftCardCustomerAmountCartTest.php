<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardProductCustomerAmount;
use MP\CommercePromotions\Woo\GiftCardCustomerAmountCart;
use PHPUnit\Framework\TestCase;
use WC_Product;

final class GiftCardCustomerAmountCartTest extends TestCase {

	public function test_apply_price_from_session_sets_price_from_stored_base_amount(): void {
		$product   = new WC_Product();
		$cart_item = array(
			'data'                                       => $product,
			GiftCardProductCustomerAmount::CART_ITEM_KEY => 42.5,
		);

		$cart   = new GiftCardCustomerAmountCart();
		$result = $cart->apply_price_from_session( $cart_item, array() );

		$this->assertSame( '42.5', $product->get_price() );
		$this->assertSame( $product, $result['data'] );
	}

	public function test_apply_price_from_session_ignores_items_without_a_stored_amount(): void {
		$product = new WC_Product();
		$product->set_price( '9.99' );
		$cart_item = array( 'data' => $product );

		$cart   = new GiftCardCustomerAmountCart();
		$result = $cart->apply_price_from_session( $cart_item, array() );

		$this->assertSame( '9.99', $product->get_price() );
		$this->assertSame( $cart_item, $result );
	}

	public function test_apply_price_from_session_rejects_non_array_input(): void {
		$cart = new GiftCardCustomerAmountCart();

		$this->assertSame( array(), $cart->apply_price_from_session( 'not-an-array', array() ) );
	}

	public function test_apply_cart_line_prices_sets_price_on_every_cart_item(): void {
		$product   = new WC_Product();
		$cart_item = array(
			'data'                                       => $product,
			GiftCardProductCustomerAmount::CART_ITEM_KEY => 100.0,
		);

		$fake_cart = new class( array( 'key' => $cart_item ) ) {
			/** @var array<string, array<string, mixed>> */
			private array $items;

			/**
			 * @param array<string, array<string, mixed>> $items Cart contents keyed by cart item hash.
			 */
			public function __construct( array $items ) {
				$this->items = $items;
			}

			/**
			 * @return array<string, array<string, mixed>>
			 */
			public function get_cart(): array {
				return $this->items;
			}
		};

		$cart = new GiftCardCustomerAmountCart();
		$cart->apply_cart_line_prices( $fake_cart );

		$this->assertSame( '100', $product->get_price() );
	}
}
