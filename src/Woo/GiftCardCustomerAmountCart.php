<?php
/**
 * Cart and order handling for customer-entered gift card amounts.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardProductCustomerAmount;
use MP\CommercePromotions\GiftCard\GiftCardStorefrontAmounts;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use WC_Product;

final class GiftCardCustomerAmountCart {

	private GiftCardProductService $products;

	public function __construct( ?GiftCardProductService $products = null ) {
		$this->products = $products ?? new GiftCardProductService();
	}

	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 5 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_cart_line_prices' ), 20, 1 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_order_line_item' ), 10, 4 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'append_order_item_display_meta' ), 10, 2 );
	}

	/**
	 * @param bool $passed
	 * @param int $product_id
	 * @param int $quantity
	 * @param int|string $variation_id
	 * @param array<string, mixed> $variations
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ): bool {
		unset( $quantity, $variations );
		if ( ! $passed ) {
			return false;
		}

		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;
		$config       = $this->products->get_line_config( $product_id, $variation_id );
		if ( $config === null || ! GiftCardProductCustomerAmount::is_customer_amount_mode( $config ) ) {
			return $passed;
		}

		$raw = isset( $_POST[ GiftCardProductCustomerAmount::POST_FIELD ] )
			? wp_unslash( (string) $_POST[ GiftCardProductCustomerAmount::POST_FIELD ] )
			: '';

		if ( $raw === '' || ! is_numeric( $raw ) ) {
			wc_add_notice(
				__( 'Please enter a gift card amount before adding to cart.', 'mp-commerce-promotions' ),
				'error'
			);
			return false;
		}

		$error = GiftCardProductCustomerAmount::validate_customer_amount( (float) $raw, $config );
		if ( $error !== null ) {
			wc_add_notice( $error, 'error' );
			return false;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $cart_item_data
	 * @param int $product_id
	 * @param int $variation_id
	 * @return array<string, mixed>
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ): array {
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;
		$config       = $this->products->get_line_config( $product_id, $variation_id );
		if ( $config === null || ! GiftCardProductCustomerAmount::is_customer_amount_mode( $config ) ) {
			return is_array( $cart_item_data ) ? $cart_item_data : array();
		}

		$raw = isset( $_POST[ GiftCardProductCustomerAmount::POST_FIELD ] )
			? wp_unslash( (string) $_POST[ GiftCardProductCustomerAmount::POST_FIELD ] )
			: '';
		if ( $raw === '' || ! is_numeric( $raw ) ) {
			return is_array( $cart_item_data ) ? $cart_item_data : array();
		}

		$display_amount = GiftCard::money( (float) $raw );
		$error          = GiftCardProductCustomerAmount::validate_customer_amount( $display_amount, $config, true );
		if ( $error !== null ) {
			return is_array( $cart_item_data ) ? $cart_item_data : array();
		}

		if ( ! is_array( $cart_item_data ) ) {
			$cart_item_data = array();
		}

		// Always store the gift card face value in shop base currency (EUR).
		$cart_item_data[ GiftCardProductCustomerAmount::CART_ITEM_KEY ] = GiftCardStorefrontAmounts::base_amount_from_display( $display_amount );

		return $cart_item_data;
	}

	/**
	 * @param \WC_Cart $cart
	 */
	public function apply_cart_line_prices( $cart ): void {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$amount = GiftCardProductCustomerAmount::read_amount_from_cart_item( $cart_item );
			if ( $amount === null ) {
				continue;
			}

			if ( ! isset( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
				continue;
			}

			$product = $cart_item['data'];
			if ( $product instanceof WC_Product && method_exists( $product, 'set_price' ) ) {
				$product->set_price( (string) $amount );
			}
		}
	}

	/**
	 * @param array<int, array<string, string>> $item_data
	 * @param array<string, mixed> $cart_item
	 * @return array<int, array<string, string>>
	 */
	public function display_cart_item_data( $item_data, $cart_item ): array {
		if ( ! is_array( $item_data ) ) {
			$item_data = array();
		}
		if ( ! is_array( $cart_item ) ) {
			return $item_data;
		}

		$amount = GiftCardProductCustomerAmount::read_amount_from_cart_item( $cart_item );
		if ( $amount === null ) {
			return $item_data;
		}

		$display_amount = GiftCardStorefrontAmounts::display_amount_from_base( $amount );

		$item_data[] = array(
			'key'   => __( 'Gift card amount', 'mp-commerce-promotions' ),
			'value' => GiftCardProductCustomerAmount::format_display_money( $display_amount ),
		);

		return $item_data;
	}

	/**
	 * @param \WC_Order_Item_Product $item
	 * @param string $cart_item_key
	 * @param array<string, mixed> $values
	 * @param \WC_Order $order
	 */
	public function persist_order_line_item( $item, $cart_item_key, $values, $order ): void {
		unset( $cart_item_key, $order );
		if ( ! is_array( $values ) ) {
			return;
		}

		$amount = GiftCardProductCustomerAmount::read_amount_from_cart_item( $values );
		if ( $amount === null ) {
			return;
		}

		GiftCardProductCustomerAmount::write_amount_to_order_item( $item, $amount );
	}

	/**
	 * @param array<int, object> $formatted_meta
	 * @param \WC_Order_Item_Product $item
	 * @return array<int, object>
	 */
	public function append_order_item_display_meta( $formatted_meta, $item ): array {
		if ( ! is_array( $formatted_meta ) ) {
			$formatted_meta = array();
		}

		$amount = GiftCardProductCustomerAmount::read_amount_from_order_item( $item );
		if ( $amount === null ) {
			return $formatted_meta;
		}

		$formatted_meta[] = (object) array(
			'key'           => GiftCardProductCustomerAmount::ORDER_META_KEY,
			'value'         => (string) $amount,
			'display_key'   => __( 'Gift card amount', 'mp-commerce-promotions' ),
			'display_value' => GiftCardProductCustomerAmount::order_item_display_value( $amount ),
		);

		return $formatted_meta;
	}
}
