<?php
/**
 * Storefront gift card product purchase panel.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCardEmailTemplate;
use MP\CommercePromotions\GiftCard\GiftCardProductCustomerAmount;
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
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_customer_amount_field' ), 8 );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_product_panel' ), 12 );
	}

	public function render_customer_amount_field(): void {
		global $product;
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$config = $this->products->get_line_config( (int) $product->get_id(), 0 );
		if ( $config === null || ! GiftCardProductCustomerAmount::is_customer_amount_mode( $config ) ) {
			return;
		}

		$config = GiftCardProductCustomerAmount::storefront_config( $config );

		GiftCardCustomerAssets::enqueue();
		if ( defined( 'MP_COMMERCE_PROMOTIONS_URL' ) && defined( 'MP_COMMERCE_PROMOTIONS_VERSION' ) ) {
			wp_enqueue_script(
				'mp-cp-gift-card-customer-amount',
				MP_COMMERCE_PROMOTIONS_URL . 'assets/js/gift-card-customer-amount.js',
				array(),
				MP_COMMERCE_PROMOTIONS_VERSION,
				true
			);
		}

		$default = $config['default_amount'] ?? null;
		$value   = $default !== null && $default > 0 ? (string) $default : '';
		$min     = GiftCardProductCustomerAmount::format_money( max( 0.01, (float) ( $config['min_amount'] ?? 0 ) ) );
		$max     = $config['max_amount'] ?? null;

		echo '<div class="mp-cp-gc-customer-amount">';
		echo '<p class="form-row form-row-wide">';
		echo '<label for="mp_cp_gift_card_customer_amount">' . esc_html__( 'Gift card amount', 'mp-commerce-promotions' ) . ' <span class="required">*</span></label>';
		echo '<input type="number" class="input-text" id="mp_cp_gift_card_customer_amount" name="' . esc_attr( GiftCardProductCustomerAmount::POST_FIELD ) . '" value="' . esc_attr( $value ) . '" min="' . esc_attr( (string) max( 0.01, (float) ( $config['min_amount'] ?? 0.01 ) ) ) . '"';
		if ( $max !== null && $max > 0 ) {
			echo ' max="' . esc_attr( (string) $max ) . '"';
		}
		echo ' step="0.01" required />';
		echo '<span class="description">';
		if ( $max !== null && $max > 0 ) {
			echo esc_html(
				sprintf(
					/* translators: 1: minimum amount, 2: maximum amount */
					__( 'Enter an amount between %1$s and %2$s.', 'mp-commerce-promotions' ),
					$min,
					GiftCardProductCustomerAmount::format_money( (float) $max )
				)
			);
		} else {
			echo esc_html(
				sprintf(
					/* translators: %s: minimum amount */
					__( 'Enter an amount of at least %s.', 'mp-commerce-promotions' ),
					$min
				)
			);
		}
		echo '</span></p>';

		if ( ! empty( $config['suggested_amounts'] ) ) {
			echo '<p class="mp-cp-gc-suggested-label">' . esc_html__( 'Suggested amounts', 'mp-commerce-promotions' ) . '</p>';
			echo '<p class="mp-cp-gc-suggested-amounts">';
			foreach ( $config['suggested_amounts'] as $suggested ) {
				$label = GiftCardProductCustomerAmount::format_money( (float) $suggested );
				echo '<button type="button" class="button mp-cp-gc-suggested-amount" data-amount="' . esc_attr( (string) $suggested ) . '">' . esc_html( $label ) . '</button> ';
			}
			echo '</p>';
		}

		echo '</div>';
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
		$preview_amount = $this->preview_amount( $config, $product );
		$currency       = \MP\CommercePromotions\GiftCard\GiftCardStorefrontAmounts::display_currency();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- storefront preview HTML from plugin templates.
		echo \MP\CommercePromotions\GiftCard\GiftCardEmailPreview::render( $this->settings, null, $preview_amount, $currency );
		echo '</div></details></div>';
	}

	/**
	 * @param array<string, mixed> $config
	 * @param object $product
	 */
	private function preview_amount( array $config, $product ): float {
		if ( GiftCardProductCustomerAmount::is_customer_amount_mode( $config ) ) {
			$config = GiftCardProductCustomerAmount::storefront_config( $config );
			$default = $config['default_amount'] ?? null;
			if ( $default !== null && $default > 0 ) {
				return (float) $default;
			}
			$min = (float) ( $config['min_amount'] ?? 0 );
			if ( $min > 0 ) {
				return $min;
			}
			if ( ! empty( $config['suggested_amounts'] ) ) {
				return (float) $config['suggested_amounts'][0];
			}

			return 25.0;
		}

		if ( method_exists( $product, 'get_price' ) ) {
			return $this->products->resolve_unit_amount( $config, (float) $product->get_price(), 1 );
		}

		return 25.0;
	}
}
