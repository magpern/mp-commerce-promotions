<?php
/**
 * Storefront gift card product purchase panel.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCardEmailTemplate;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\Service\Settings;

final class GiftCardProductDisplay {

	private GiftCardProductService $products;

	private Settings $settings;

	public function __construct( GiftCardProductService $products, Settings $settings ) {
		$this->products = $products;
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_product_panel' ), 12 );
	}

	public function render_product_panel(): void {
		global $product;
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$config = $this->products->get_line_config( (int) $product->get_id(), 0 );
		if ( $config === null ) {
			return;
		}

		GiftCardCustomerAssets::enqueue();

		$mode = (string) ( $config['recipient_mode'] ?? GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY );

		echo '<div class="mp-cp-gift-card-product-panel">';
		echo '<h3 class="mp-cp-gc-title">' . esc_html__( 'Digital gift card', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="mp-cp-gc-help">' . esc_html__(
			'Delivered by email. Recipient details and send date are collected at checkout.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<ul class="mp-cp-gc-product-benefits">';
		echo '<li>' . esc_html__( 'Redeem at checkout under “Gift card or store credit”.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Partial use is OK — remaining balance stays on the card.', 'mp-commerce-promotions' ) . '</li>';
		echo '</ul>';

		if ( GiftCardProductMeta::allows_recipient_fields( $mode ) ) {
			echo '<p class="mp-cp-gc-help mp-cp-gc-recipient-hint"><strong>' . esc_html__( 'At checkout', 'mp-commerce-promotions' ) . '</strong> — '
				. esc_html__( 'Recipient email, optional message, and send now or schedule a future date.', 'mp-commerce-promotions' )
				. '</p>';
		}

		echo '<details class="mp-cp-gc-email-preview">';
		echo '<summary class="mp-cp-gc-email-preview__summary">' . esc_html__( 'Preview sample email (masked code)', 'mp-commerce-promotions' ) . '</summary>';
		echo '<div class="mp-cp-gc-email-preview__frame">';
		$amount = $this->products->resolve_unit_amount( $config, (float) $product->get_price(), 1 );
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- storefront preview HTML from plugin templates.
		echo \MP\CommercePromotions\GiftCard\GiftCardEmailPreview::render( $this->settings, null, $amount, $currency );
		echo '</div></details></div>';
	}
}
