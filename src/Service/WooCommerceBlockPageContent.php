<?php
/**
 * Default WooCommerce Cart/Checkout block post_content (inner block markup required for SSR + hydration).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use ReflectionMethod;

final class WooCommerceBlockPageContent {

	/**
	 * Self-closing cart/checkout comments render empty HTML; WooCommerce expects inner block templates.
	 */
	public static function has_complete_cart_structure( string $content ): bool {
		return str_contains( $content, 'woocommerce/cart' )
			&& (
				str_contains( $content, 'filled-cart-block' )
				|| str_contains( $content, 'cart-line-items-block' )
			);
	}

	public static function has_complete_checkout_structure( string $content ): bool {
		return str_contains( $content, 'woocommerce/checkout' )
			&& (
				str_contains( $content, 'checkout-fields-block' )
				|| str_contains( $content, 'checkout-order-summary-block' )
			);
	}

	public static function default_cart_markup(): string {
		$content = self::invoke_wc_install_method( 'get_cart_block_content' );
		if ( $content !== '' ) {
			return $content;
		}

		return self::minimal_cart_markup();
	}

	public static function default_checkout_markup(): string {
		$content = self::invoke_wc_install_method( 'get_checkout_block_content' );
		if ( $content !== '' ) {
			return $content;
		}

		return self::minimal_checkout_markup();
	}

	/**
	 * @return array{has_wrapper: bool, length: int, excerpt: string}
	 */
	public static function render_cart_diagnostic( string $post_content ): array {
		$html = '';
		if ( function_exists( 'do_blocks' ) ) {
			$html = (string) do_blocks( $post_content );
		}

		return array(
			'has_wrapper' => str_contains( $html, 'wp-block-woocommerce-cart' )
				|| str_contains( $html, 'wc-block-cart' ),
			'length'      => strlen( $html ),
			'excerpt'     => substr( preg_replace( '/\s+/', ' ', strip_tags( $html ) ) ?? '', 0, 120 ),
		);
	}

	private static function invoke_wc_install_method( string $method ): string {
		if ( ! class_exists( \WC_Install::class ) ) {
			return '';
		}

		try {
			$ref = new ReflectionMethod( \WC_Install::class, $method );
			$ref->setAccessible( true );
			$result = $ref->invoke( null );

			return is_string( $result ) ? $result : '';
		} catch ( \ReflectionException $e ) {
			return '';
		}
	}

	private static function minimal_cart_markup(): string {
		return '<!-- wp:woocommerce/cart -->
<div class="wp-block-woocommerce-cart alignwide is-loading"><!-- wp:woocommerce/filled-cart-block -->
<div class="wp-block-woocommerce-filled-cart-block"><!-- wp:woocommerce/cart-items-block -->
<div class="wp-block-woocommerce-cart-items-block"><!-- wp:woocommerce/cart-line-items-block -->
<div class="wp-block-woocommerce-cart-line-items-block"></div>
<!-- /wp:woocommerce/cart-line-items-block -->

<!-- /wp:woocommerce/cart-items-block -->

<!-- wp:woocommerce/cart-totals-block -->
<div class="wp-block-woocommerce-cart-totals-block"><!-- wp:woocommerce/cart-order-summary-block -->
<div class="wp-block-woocommerce-cart-order-summary-block"><!-- wp:woocommerce/cart-order-summary-heading-block -->
<div class="wp-block-woocommerce-cart-order-summary-heading-block"></div>
<!-- /wp:woocommerce/cart-order-summary-heading-block -->
<!-- wp:woocommerce/cart-order-summary-coupon-form-block -->
<div class="wp-block-woocommerce-cart-order-summary-coupon-form-block"></div>
<!-- /wp:woocommerce/cart-order-summary-coupon-form-block -->
<!-- wp:woocommerce/cart-order-summary-subtotal-block -->
<div class="wp-block-woocommerce-cart-order-summary-subtotal-block"></div>
<!-- /wp:woocommerce/cart-order-summary-subtotal-block -->
<!-- wp:woocommerce/cart-order-summary-fee-block -->
<div class="wp-block-woocommerce-cart-order-summary-fee-block"></div>
<!-- /wp:woocommerce/cart-order-summary-fee-block -->
<!-- wp:woocommerce/cart-order-summary-discount-block -->
<div class="wp-block-woocommerce-cart-order-summary-discount-block"></div>
<!-- /wp:woocommerce/cart-order-summary-discount-block -->
<!-- wp:woocommerce/cart-order-summary-shipping-block -->
<div class="wp-block-woocommerce-cart-order-summary-shipping-block"></div>
<!-- /wp:woocommerce/cart-order-summary-shipping-block -->
<!-- wp:woocommerce/cart-order-summary-taxes-block -->
<div class="wp-block-woocommerce-cart-order-summary-taxes-block"></div>
<!-- /wp:woocommerce/cart-order-summary-taxes-block --></div>
<!-- /wp:woocommerce/cart-order-summary-block -->
<!-- wp:woocommerce/proceed-to-checkout-block -->
<div class="wp-block-woocommerce-proceed-to-checkout-block"></div>
<!-- /wp:woocommerce/proceed-to-checkout-block --></div>
<!-- /wp:woocommerce/cart-totals-block --></div>
<!-- /wp:woocommerce/filled-cart-block -->
<!-- wp:woocommerce/empty-cart-block -->
<div class="wp-block-woocommerce-empty-cart-block"></div>
<!-- /wp:woocommerce/empty-cart-block --></div>
<!-- /wp:woocommerce/cart -->';
	}

	private static function minimal_checkout_markup(): string {
		return '<!-- wp:woocommerce/checkout -->
<div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading"><!-- wp:woocommerce/checkout-fields-block -->
<div class="wp-block-woocommerce-checkout-fields-block"><!-- wp:woocommerce/checkout-contact-information-block -->
<div class="wp-block-woocommerce-checkout-contact-information-block"></div>
<!-- /wp:woocommerce/checkout-contact-information-block -->
<!-- wp:woocommerce/checkout-shipping-address-block -->
<div class="wp-block-woocommerce-checkout-shipping-address-block"></div>
<!-- /wp:woocommerce/checkout-shipping-address-block -->
<!-- wp:woocommerce/checkout-billing-address-block -->
<div class="wp-block-woocommerce-checkout-billing-address-block"></div>
<!-- /wp:woocommerce/checkout-billing-address-block -->
<!-- wp:woocommerce/checkout-payment-block -->
<div class="wp-block-woocommerce-checkout-payment-block"></div>
<!-- /wp:woocommerce/checkout-payment-block -->
<!-- wp:woocommerce/checkout-actions-block -->
<div class="wp-block-woocommerce-checkout-actions-block"></div>
<!-- /wp:woocommerce/checkout-actions-block --></div>
<!-- /wp:woocommerce/checkout-fields-block -->
<!-- wp:woocommerce/checkout-totals-block -->
<div class="wp-block-woocommerce-checkout-totals-block"><!-- wp:woocommerce/checkout-order-summary-block -->
<div class="wp-block-woocommerce-checkout-order-summary-block"><!-- wp:woocommerce/checkout-order-summary-coupon-form-block -->
<div class="wp-block-woocommerce-checkout-order-summary-coupon-form-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-coupon-form-block -->
<!-- wp:woocommerce/checkout-order-summary-subtotal-block -->
<div class="wp-block-woocommerce-checkout-order-summary-subtotal-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-subtotal-block -->
<!-- wp:woocommerce/checkout-order-summary-fee-block -->
<div class="wp-block-woocommerce-checkout-order-summary-fee-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-fee-block --></div>
<!-- /wp:woocommerce/checkout-order-summary-block --></div>
<!-- /wp:woocommerce/checkout-totals-block --></div>
<!-- /wp:woocommerce/checkout -->';
	}
}
