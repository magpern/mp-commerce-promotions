<?php
/**
 * WooCommerce product edit fields for gift card products.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCardProductAdminHelper;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use WC_Product;

final class GiftCardProductAdmin {

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_simple_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_simple' ) );

		add_action( 'woocommerce_variation_options', array( $this, 'render_variation_checkbox' ), 10, 3 );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
	}

	public function render_simple_fields(): void {
		global $post;
		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		if ( $product_id <= 0 ) {
			return;
		}

		echo '<div class="options_group mp-cp-gift-card-product-fields">';
		$this->render_fields( $product_id );
		echo '</div>';
	}

	/**
	 * @param int $loop
	 * @param array<string, mixed> $variation_data
	 * @param \WP_Post $variation
	 */
	public function render_variation_checkbox( $loop, $variation_data, $variation ): void {
		unset( $variation_data );
		$variation_id = $variation instanceof \WP_Post ? (int) $variation->ID : 0;
		if ( $variation_id <= 0 ) {
			return;
		}

		$config = GiftCardProductMeta::read( $variation_id );
		woocommerce_wp_checkbox(
			array(
				'id'            => 'mp_cp_sells_gift_card_var[' . $loop . ']',
				'name'          => 'mp_cp_sells_gift_card_var[' . $loop . ']',
				'label'         => __( 'Sells gift card', 'mp-commerce-promotions' ),
				'value'         => $config['sells'] ? GiftCardProductMeta::VALUE_YES : GiftCardProductMeta::VALUE_NO,
				'wrapper_class' => 'form-row form-row-full',
			)
		);
	}

	/**
	 * @param int $loop
	 * @param array<string, mixed> $variation_data
	 * @param \WP_Post $variation
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ): void {
		unset( $variation_data );
		$variation_id = $variation instanceof \WP_Post ? (int) $variation->ID : 0;
		if ( $variation_id <= 0 ) {
			return;
		}

		$config = GiftCardProductMeta::read( $variation_id );
		if ( ! $config['sells'] ) {
			return;
		}

		echo '<div class="mp-cp-gift-card-variation-fields form-row form-row-full">';
		$this->render_fields_inner( $loop, $config, true, $variation_id );
		echo '</div>';
	}

	public function save_simple( int $product_id ): void {
		if ( $product_id <= 0 || ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		GiftCardProductMeta::save( $product_id, $this->read_post_input() );
	}

	/**
	 * @param int $variation_id
	 * @param int $loop
	 */
	public function save_variation( $variation_id, $loop ): void {
		$variation_id = (int) $variation_id;
		if ( $variation_id <= 0 || ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		$sells = isset( $_POST['mp_cp_sells_gift_card_var'][ $loop ] )
			&& GiftCardProductMeta::VALUE_YES === sanitize_text_field(
				wp_unslash( (string) $_POST['mp_cp_sells_gift_card_var'][ $loop ] )
			);

		$input = $this->read_post_input_variation( $loop );
		$input['sells'] = $sells ? GiftCardProductMeta::VALUE_YES : GiftCardProductMeta::VALUE_NO;
		GiftCardProductMeta::save( $variation_id, $input );
	}

	private function render_fields( int $product_id ): void {
		$config  = GiftCardProductMeta::read( $product_id );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$price   = $product instanceof WC_Product ? (float) $product->get_regular_price() : 0.0;
		$virtual = $product instanceof WC_Product && $product->is_virtual();

		echo '<p class="form-field mp-cp-gc-admin-intro"><strong>' . esc_html__( 'Gift card product', 'mp-commerce-promotions' ) . '</strong> — '
			. esc_html__( 'Sell a digital gift card from this WooCommerce product. Codes are generated after payment — never stored in order meta.', 'mp-commerce-promotions' )
			. '</p>';

		woocommerce_wp_checkbox(
			array(
				'id'            => 'mp_cp_sells_gift_card',
				'label'         => __( 'This product sells a gift card', 'mp-commerce-promotions' ),
				'description'   => __( 'When the order is paid, one gift card is issued per quantity purchased.', 'mp-commerce-promotions' ),
				'value'         => $config['sells'] ? GiftCardProductMeta::VALUE_YES : GiftCardProductMeta::VALUE_NO,
				'wrapper_class' => 'show_if_simple show_if_variable',
			)
		);

		echo '<div class="mp-cp-gift-card-product-options">';
		$this->render_merchant_notices( $config, $price, $virtual );
		$this->render_fields_inner( null, $config, false, $product_id );
		echo '</div>';
	}

	/**
	 * @param array{sells: bool, amount_mode: string, fixed_amount: float, expiry_days: ?int, recipient_mode: string} $config
	 */
	private function render_merchant_notices( array $config, float $product_price, bool $is_virtual ): void {
		if ( ! $config['sells'] ) {
			return;
		}

		$preview = GiftCardProductAdminHelper::amount_preview_text(
			$config,
			$product_price,
			function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : ''
		);
		if ( $preview !== '' ) {
			echo '<p class="mp-cp-gc-admin-preview"><em>' . esc_html( $preview ) . '</em></p>';
		}

		$virtual_warn = GiftCardProductAdminHelper::virtual_product_warning( $is_virtual );
		if ( $virtual_warn !== '' ) {
			echo '<p class="mp-cp-gc-admin-warning" style="color:#b32d2e;"><strong>' . esc_html__( 'Note:', 'mp-commerce-promotions' ) . '</strong> '
				. esc_html( $virtual_warn ) . '</p>';
		}
	}

	/**
	 * @param array{sells: bool, amount_mode: string, fixed_amount: float, expiry_days: ?int, recipient_mode: string} $config
	 */
	private function render_fields_inner( $loop, array $config, bool $is_variation, int $product_id ): void {
		$suffix = $is_variation && $loop !== null ? '_' . (int) $loop : '';
		$name_mode = $is_variation && $loop !== null ? 'mp_cp_gift_card_amount_mode_var[' . (int) $loop . ']' : 'mp_cp_gift_card_amount_mode';
		$name_fixed = $is_variation && $loop !== null ? 'mp_cp_gift_card_fixed_amount_var[' . (int) $loop . ']' : 'mp_cp_gift_card_fixed_amount';
		$name_expiry = $is_variation && $loop !== null ? 'mp_cp_gift_card_expiry_days_var[' . (int) $loop . ']' : 'mp_cp_gift_card_expiry_days';

		echo '<p><label><strong>' . esc_html__( 'Gift card value', 'mp-commerce-promotions' ) . '</strong></label><br />';
		echo '<label><input type="radio" name="' . esc_attr( $name_mode ) . '" value="' . esc_attr( GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE ) . '" '
			. checked( $config['amount_mode'], GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE, false ) . ' /> '
			. esc_html__( 'Match product price (per unit)', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<label><input type="radio" name="' . esc_attr( $name_mode ) . '" value="' . esc_attr( GiftCardProductMeta::AMOUNT_MODE_FIXED ) . '" '
			. checked( $config['amount_mode'], GiftCardProductMeta::AMOUNT_MODE_FIXED, false ) . ' /> '
			. esc_html__( 'Fixed amount', 'mp-commerce-promotions' ) . '</label></p>';

		echo '<p><label for="mp_cp_gift_card_fixed_amount' . esc_attr( $suffix ) . '">' . esc_html__( 'Fixed amount', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="number" step="0.01" min="0" class="short" id="mp_cp_gift_card_fixed_amount' . esc_attr( $suffix ) . '" name="' . esc_attr( $name_fixed ) . '" value="' . esc_attr( (string) $config['fixed_amount'] ) . '" /></p>';

		echo '<p><label for="mp_cp_gift_card_expiry_days' . esc_attr( $suffix ) . '">' . esc_html__( 'Expires (days after purchase, optional)', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="number" step="1" min="0" class="short" id="mp_cp_gift_card_expiry_days' . esc_attr( $suffix ) . '" name="' . esc_attr( $name_expiry ) . '" value="'
			. esc_attr( $config['expiry_days'] !== null ? (string) $config['expiry_days'] : '' ) . '" /></p>';

		$name_recipient = $is_variation && $loop !== null ? 'mp_cp_gift_card_recipient_mode_var[' . (int) $loop . ']' : 'mp_cp_gift_card_recipient_mode';
		$mode           = (string) ( $config['recipient_mode'] ?? GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY );

		echo '<p><label for="mp_cp_gift_card_recipient_mode' . esc_attr( $suffix ) . '"><strong>' . esc_html__( 'Recipient at checkout', 'mp-commerce-promotions' ) . '</strong></label><br />';
		echo '<select id="mp_cp_gift_card_recipient_mode' . esc_attr( $suffix ) . '" name="' . esc_attr( $name_recipient ) . '">';
		echo '<option value="' . esc_attr( GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY ) . '" ' . selected( $mode, GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY, false ) . '>'
			. esc_html__( 'Purchaser only (billing email)', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="' . esc_attr( GiftCardProductMeta::RECIPIENT_EMAIL ) . '" ' . selected( $mode, GiftCardProductMeta::RECIPIENT_EMAIL, false ) . '>'
			. esc_html__( 'Recipient email (+ optional name & schedule)', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="' . esc_attr( GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE ) . '" ' . selected( $mode, GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE, false ) . '>'
			. esc_html__( 'Recipient email + personal message', 'mp-commerce-promotions' ) . '</option>';
		echo '</select></p>';
		echo '<p class="description">' . esc_html( GiftCardProductAdminHelper::recipient_mode_help( $mode ) ) . '</p>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_post_input(): array {
		$sells = isset( $_POST['mp_cp_sells_gift_card'] )
			&& GiftCardProductMeta::VALUE_YES === sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_sells_gift_card'] ) );

		return array(
			'sells'          => $sells ? GiftCardProductMeta::VALUE_YES : GiftCardProductMeta::VALUE_NO,
			'amount_mode'    => isset( $_POST['mp_cp_gift_card_amount_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_amount_mode'] ) ) : '',
			'fixed_amount'   => isset( $_POST['mp_cp_gift_card_fixed_amount'] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_fixed_amount'] ) : '',
			'expiry_days'    => isset( $_POST['mp_cp_gift_card_expiry_days'] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_expiry_days'] ) : '',
			'recipient_mode' => isset( $_POST['mp_cp_gift_card_recipient_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_recipient_mode'] ) ) : '',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_post_input_variation( int $loop ): array {
		return array(
			'amount_mode'    => isset( $_POST['mp_cp_gift_card_amount_mode_var'][ $loop ] ) ? sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_amount_mode_var'][ $loop ] ) ) : '',
			'fixed_amount'   => isset( $_POST['mp_cp_gift_card_fixed_amount_var'][ $loop ] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_fixed_amount_var'][ $loop ] ) : '',
			'expiry_days'    => isset( $_POST['mp_cp_gift_card_expiry_days_var'][ $loop ] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_expiry_days_var'][ $loop ] ) : '',
			'recipient_mode' => isset( $_POST['mp_cp_gift_card_recipient_mode_var'][ $loop ] ) ? sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_recipient_mode_var'][ $loop ] ) ) : '',
		);
	}
}
