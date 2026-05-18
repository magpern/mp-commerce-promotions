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
		echo '<h3 class="mp-cp-gc-title">' . esc_html__( 'About this gift card', 'mp-commerce-promotions' ) . '</h3>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'Delivered by email after payment (or on your chosen date).', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Use the full code at checkout in the “Gift card or store credit” section.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Remaining balance stays on the card for future orders (partial payment supported).', 'mp-commerce-promotions' ) . '</li>';
		echo '</ul>';

		if ( GiftCardProductMeta::allows_recipient_fields( $mode ) ) {
			echo '<p class="mp-cp-gc-help"><strong>' . esc_html__( 'Recipient details', 'mp-commerce-promotions' ) . '</strong> — '
				. esc_html__( 'You can enter the recipient email, optional message, and send now or schedule a date at checkout.', 'mp-commerce-promotions' )
				. '</p>';
		}

		echo '<details class="mp-cp-gc-email-preview" style="margin-top:12px;">';
		echo '<summary>' . esc_html__( 'Email preview', 'mp-commerce-promotions' ) . '</summary>';
		echo '<div style="margin-top:8px;border:1px solid #dcdcde;padding:8px;max-height:240px;overflow:auto;">';
		echo GiftCardEmailTemplate::render_html(
			$this->settings->gift_card_email_template(),
			array(
				'site_name' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'Store',
				'store_url' => function_exists( 'home_url' ) ? home_url( '/' ) : '',
				'accent'    => $this->settings->gift_card_accent_color(),
				'logo_url'  => $this->settings->gift_card_logo_url(),
				'support_text' => $this->settings->gift_card_support_email_text(),
				'preview'   => true,
				'cards'     => array(
					array(
						'masked_code' => '****SAMPLE',
						'amount'      => $this->products->resolve_unit_amount( $config, (float) $product->get_price(), 1 ),
						'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR',
						'recipient_name' => __( 'Recipient', 'mp-commerce-promotions' ),
						'message'     => __( 'Enjoy your gift!', 'mp-commerce-promotions' ),
					),
				),
			)
		);
		echo '</div></details></div>';
	}
}
