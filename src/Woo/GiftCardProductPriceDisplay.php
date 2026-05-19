<?php
/**
 * Storefront price and add-to-cart copy for customer-amount gift cards.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCardProductCustomerAmount;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use WC_Product;

final class GiftCardProductPriceDisplay {

	private GiftCardProductService $products;

	public function __construct( ?GiftCardProductService $products = null ) {
		$this->products = $products ?? new GiftCardProductService();
	}

	public function register(): void {
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 20, 2 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_is_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'filter_loop_button_text' ), 20, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'filter_single_button_text' ), 20, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'filter_loop_add_to_cart_link' ), 20, 2 );
	}

	/**
	 * @param string $html
	 * @param WC_Product $product
	 */
	public function filter_price_html( $html, $product ): string {
		$config = $this->config_for_product( $product );
		if ( $config === null || ! GiftCardProductCustomerAmount::is_customer_amount_mode( $config ) ) {
			return (string) $html;
		}

		return '<span class="price">' . esc_html( GiftCardProductCustomerAmount::catalog_price_html( $config ) ) . '</span>';
	}

	/**
	 * @param bool $purchasable
	 * @param WC_Product $product
	 */
	public function filter_is_purchasable( $purchasable, $product ): bool {
		$config = $this->config_for_product( $product );
		if ( $config === null || ! GiftCardProductCustomerAmount::is_customer_amount_mode( $config ) ) {
			return (bool) $purchasable;
		}

		return $product->is_in_stock() && $product->get_status() === 'publish';
	}

	/**
	 * @param string $text
	 * @param WC_Product $product
	 */
	public function filter_loop_button_text( $text, $product ): string {
		$config = $this->config_for_product( $product );
		if ( $config !== null && GiftCardProductCustomerAmount::is_customer_amount_mode( $config ) ) {
			return __( 'Select amount', 'mp-commerce-promotions' );
		}

		return (string) $text;
	}

	/**
	 * @param string $text
	 * @param WC_Product $product
	 */
	public function filter_single_button_text( $text, $product ): string {
		unset( $product );
		return (string) $text;
	}

	/**
	 * @param string $link
	 * @param WC_Product $product
	 */
	public function filter_loop_add_to_cart_link( $link, $product ): string {
		$config = $this->config_for_product( $product );
		if ( $config === null || ! GiftCardProductCustomerAmount::is_customer_amount_mode( $config ) ) {
			return (string) $link;
		}

		$url  = $product->get_permalink();
		$text = __( 'Select amount', 'mp-commerce-promotions' );
		$cls  = 'button product_type_simple';

		return sprintf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( $url ),
			esc_attr( $cls ),
			esc_html( $text )
		);
	}

	/**
	 * @param WC_Product $product
	 * @return array<string, mixed>|null
	 */
	private function config_for_product( $product ): ?array {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$variation_id = 0;
		if ( $product->is_type( 'variation' ) ) {
			$variation_id = (int) $product->get_id();
			$product_id   = (int) $product->get_parent_id();
		} else {
			$product_id = (int) $product->get_id();
		}

		return $this->products->get_line_config( $product_id, $variation_id );
	}
}
