<?php
/**
 * Checkout fields for gift card recipient and scheduled delivery.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use InvalidArgumentException;
use MP\CommercePromotions\GiftCard\GiftCardLineItemMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\GiftCard\GiftCardRecipientValidator;

final class GiftCardRecipientCheckout {

	private GiftCardProductService $products;

	public function __construct( ?GiftCardProductService $products = null ) {
		$this->products = $products ?? new GiftCardProductService();
	}

	public function register(): void {
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_checkout_fields' ), 15 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_line_item_meta' ), 10, 4 );
	}

	public function render_checkout_fields(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$lines = $this->gift_card_cart_lines();
		if ( $lines === array() ) {
			return;
		}

		echo '<div id="mp_cp_gift_card_delivery_fields"><h3>' . esc_html__( 'Gift card delivery', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Recipient details apply to each gift card line (same recipient for all units in a line).',
			'mp-commerce-promotions'
		) . '</p>';

		$post_data = isset( $_POST['mp_cp_gc'] ) && is_array( $_POST['mp_cp_gc'] )
			? array_map( 'wp_unslash', $_POST['mp_cp_gc'] )
			: array();

		foreach ( $lines as $cart_key => $line ) {
			$config = $line['config'];
			$mode   = (string) $config['recipient_mode'];
			$label  = esc_html( $line['product_name'] );

			$values = isset( $post_data[ $cart_key ] ) && is_array( $post_data[ $cart_key ] )
				? GiftCardLineItemMeta::normalize_array( $post_data[ $cart_key ] )
				: GiftCardLineItemMeta::empty();

			echo '<fieldset class="mp-cp-gc-line" style="margin:1em 0;padding:12px;border:1px solid #ddd;">';
			echo '<legend><strong>' . $label . '</strong></legend>';

			if ( ! GiftCardProductMeta::allows_recipient_fields( $mode ) ) {
				echo '<p class="description">' . esc_html__( 'This gift card will be sent to your billing email when the order is paid.', 'mp-commerce-promotions' ) . '</p>';
				echo '</fieldset>';
				continue;
			}

			$max_days = GiftCardRecipientValidator::max_schedule_days();

			echo '<p class="form-row form-row-wide">';
			echo '<label for="mp_cp_gc_' . esc_attr( $cart_key ) . '_email">' . esc_html__( 'Recipient email', 'mp-commerce-promotions' ) . ' <abbr class="required" title="required">*</abbr></label>';
			echo '<input type="email" class="input-text" id="mp_cp_gc_' . esc_attr( $cart_key ) . '_email" name="mp_cp_gc[' . esc_attr( $cart_key ) . '][recipient_email]" value="' . esc_attr( $values['recipient_email'] ) . '" required />';
			echo '</p>';

			echo '<p class="form-row form-row-wide">';
			echo '<label for="mp_cp_gc_' . esc_attr( $cart_key ) . '_name">' . esc_html__( 'Recipient name (optional)', 'mp-commerce-promotions' ) . '</label>';
			echo '<input type="text" class="input-text" id="mp_cp_gc_' . esc_attr( $cart_key ) . '_name" name="mp_cp_gc[' . esc_attr( $cart_key ) . '][recipient_name]" value="' . esc_attr( $values['recipient_name'] ) . '" />';
			echo '</p>';

			if ( $mode === GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE ) {
				echo '<p class="form-row form-row-wide">';
				echo '<label for="mp_cp_gc_' . esc_attr( $cart_key ) . '_message">' . esc_html__( 'Personal message (optional)', 'mp-commerce-promotions' ) . '</label>';
				echo '<textarea class="input-text" id="mp_cp_gc_' . esc_attr( $cart_key ) . '_message" name="mp_cp_gc[' . esc_attr( $cart_key ) . '][message]" rows="3" maxlength="' . esc_attr( (string) GiftCardRecipientValidator::max_message_length() ) . '">'
					. esc_textarea( $values['message'] ) . '</textarea>';
				echo '</p>';
			}

			echo '<p class="form-row form-row-wide">';
			echo '<label>' . esc_html__( 'When to send', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<label><input type="radio" name="mp_cp_gc[' . esc_attr( $cart_key ) . '][delivery_timing]" value="' . esc_attr( GiftCardLineItemMeta::TIMING_SEND_NOW ) . '" '
				. checked( $values['delivery_timing'], GiftCardLineItemMeta::TIMING_SEND_NOW, false ) . ' /> '
				. esc_html__( 'Send now (when order is paid)', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<label><input type="radio" name="mp_cp_gc[' . esc_attr( $cart_key ) . '][delivery_timing]" value="' . esc_attr( GiftCardLineItemMeta::TIMING_SEND_ON_DATE ) . '" '
				. checked( $values['delivery_timing'], GiftCardLineItemMeta::TIMING_SEND_ON_DATE, false ) . ' /> '
				. esc_html__( 'Send on a specific date', 'mp-commerce-promotions' ) . '</label>';
			echo '</p>';

			echo '<p class="form-row form-row-wide">';
			echo '<label for="mp_cp_gc_' . esc_attr( $cart_key ) . '_date">' . esc_html__( 'Delivery date', 'mp-commerce-promotions' ) . '</label>';
			echo '<input type="date" class="input-text" id="mp_cp_gc_' . esc_attr( $cart_key ) . '_date" name="mp_cp_gc[' . esc_attr( $cart_key ) . '][scheduled_for]" value="' . esc_attr( $values['scheduled_for'] ) . '" min="' . esc_attr( gmdate( 'Y-m-d' ) ) . '" />';
			echo '<span class="description">' . esc_html(
				sprintf(
					/* translators: %d: max days */
					__( 'Required when sending on a date. Up to %d days ahead.', 'mp-commerce-promotions' ),
					$max_days
				)
			) . '</span>';
			echo '</p>';

			echo '</fieldset>';
		}

		echo '</div>';
	}

	public function validate_checkout(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$billing = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['billing_email'] ) ) : '';
		$post    = isset( $_POST['mp_cp_gc'] ) && is_array( $_POST['mp_cp_gc'] ) ? $_POST['mp_cp_gc'] : array();

		foreach ( $this->gift_card_cart_lines() as $cart_key => $line ) {
			$raw = isset( $post[ $cart_key ] ) && is_array( $post[ $cart_key ] ) ? $post[ $cart_key ] : array();
			try {
				GiftCardRecipientValidator::validate_for_product( $line['config'], $raw, $billing );
			} catch ( InvalidArgumentException $e ) {
				wc_add_notice( $e->getMessage(), 'error' );
			}
		}
	}

	/**
	 * @param \WC_Order_Item_Product $item
	 * @param string                 $cart_item_key
	 * @param array<string, mixed>   $values
	 * @param \WC_Order              $order
	 */
	public function persist_line_item_meta( $item, $cart_item_key, $values, $order ): void {
		unset( $values, $order );

		if ( ! is_object( $item ) || ! is_a( $item, 'WC_Order_Item_Product', false ) ) {
			return;
		}

		$product_id   = (int) $item->get_product_id();
		$variation_id = (int) $item->get_variation_id();
		$config       = $this->products->get_line_config( $product_id, $variation_id );
		if ( $config === null ) {
			return;
		}

		$post = isset( $_POST['mp_cp_gc'] ) && is_array( $_POST['mp_cp_gc'] ) ? $_POST['mp_cp_gc'] : array();
		$raw  = isset( $post[ $cart_item_key ] ) && is_array( $post[ $cart_item_key ] ) ? $post[ $cart_item_key ] : array();

		$billing = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';

		try {
			$delivery = GiftCardRecipientValidator::validate_for_product( $config, $raw, $billing );
		} catch ( InvalidArgumentException $e ) {
			$delivery = GiftCardLineItemMeta::empty();
			if ( $billing !== '' ) {
				$delivery['recipient_email'] = sanitize_email( $billing );
			}
		}

		GiftCardLineItemMeta::write_to_order_item( $item, $delivery );
	}

	/**
	 * @return array<string, array{config: array{sells: bool, amount_mode: string, fixed_amount: float, expiry_days: ?int, recipient_mode: string}, product_name: string}>
	 */
	private function gift_card_cart_lines(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$out = array();
		foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
			$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
			$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
			$config       = $this->products->get_line_config( $product_id, $variation_id );
			if ( $config === null ) {
				continue;
			}

			$name = '';
			if ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_name' ) ) {
				$name = (string) $cart_item['data']->get_name();
			}

			$out[ (string) $cart_key ] = array(
				'config'        => $config,
				'product_name'  => $name !== '' ? $name : __( 'Gift card', 'mp-commerce-promotions' ),
			);
		}

		return $out;
	}
}
