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

	public const TAB_KEY = 'mp_cp_gift_card';

	public const PANEL_ID = 'mp_cp_gift_card_product_data';

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'register_product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_simple' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_block' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
	}

	/**
	 * @param array<string, array<string, mixed>> $tabs
	 * @return array<string, array<string, mixed>>
	 */
	public function register_product_data_tab( array $tabs ): array {
		$tabs[ self::TAB_KEY ] = array(
			'label'    => __( 'Gift card', 'mp-commerce-promotions' ),
			'target'   => self::PANEL_ID,
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * @param string $hook
	 */
	public function enqueue_admin_assets( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen === null || $screen->post_type !== 'product' ) {
			return;
		}

		if ( ! defined( 'MP_COMMERCE_PROMOTIONS_URL' ) || ! defined( 'MP_COMMERCE_PROMOTIONS_VERSION' ) ) {
			return;
		}

		wp_enqueue_style(
			'mp-cp-gift-card-product-admin',
			MP_COMMERCE_PROMOTIONS_URL . 'assets/css/gift-card-product-admin.css',
			array(),
			MP_COMMERCE_PROMOTIONS_VERSION
		);

		wp_enqueue_script(
			'mp-cp-gift-card-product-admin',
			MP_COMMERCE_PROMOTIONS_URL . 'assets/js/gift-card-product-admin.js',
			array( 'jquery', 'woocommerce_admin' ),
			MP_COMMERCE_PROMOTIONS_VERSION,
			true
		);
	}

	public function render_product_data_panel(): void {
		global $post;

		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		if ( $product_id <= 0 ) {
			return;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$is_variable = $product instanceof WC_Product && $product->is_type( 'variable' );

		echo '<div id="' . esc_attr( self::PANEL_ID ) . '" class="panel woocommerce_options_panel hidden">';

		if ( $is_variable ) {
			$this->render_variable_parent_notice();
		} else {
			$this->render_simple_product_panel( $product_id, $product );
		}

		echo '</div>';
	}

	private function render_variable_parent_notice(): void {
		echo '<div class="options_group">';
		echo '<p class="form-field mp-cp-gc-admin-intro">';
		echo '<strong>' . esc_html__( 'Gift card product', 'mp-commerce-promotions' ) . '</strong><br />';
		echo esc_html(
			GiftCardProductAdminHelper::variable_parent_admin_notice()
		);
		echo '</p>';
		echo '</div>';
	}

	/**
	 * @param int $product_id
	 * @param WC_Product|null $product
	 */
	private function render_simple_product_panel( int $product_id, ?WC_Product $product ): void {
		$config  = GiftCardProductMeta::read( $product_id );
		$price   = $product instanceof WC_Product ? (float) $product->get_regular_price() : 0.0;
		$virtual = $product instanceof WC_Product && $product->is_virtual();

		echo '<div class="options_group mp-cp-gift-card-panel-intro">';
		echo '<p class="form-field mp-cp-gc-admin-intro">';
		echo '<strong>' . esc_html__( 'Gift card product', 'mp-commerce-promotions' ) . '</strong><br />';
		echo esc_html__(
			'Sell a digital gift card from this WooCommerce product. Codes are generated after payment — never stored in order meta.',
			'mp-commerce-promotions'
		);
		echo '</p>';
		echo '</div>';

		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'            => 'mp_cp_sells_gift_card',
				'label'         => __( 'This product sells a gift card', 'mp-commerce-promotions' ),
				'description'   => __( 'When the order is paid, one gift card is issued per quantity purchased.', 'mp-commerce-promotions' ),
				'value'         => $config['sells'] ? GiftCardProductMeta::VALUE_YES : GiftCardProductMeta::VALUE_NO,
				'wrapper_class' => '',
			)
		);
		echo '</div>';

		echo '<div class="options_group mp-cp-gift-card-product-options show_if_mp_cp_sells_gift_card">';
		$this->render_merchant_notices( $config, $price, $virtual );
		$this->render_fields_inner( null, $config, false );
		echo '</div>';
	}

	/**
	 * @param int $loop
	 * @param array<string, mixed> $variation_data
	 * @param \WP_Post $variation
	 */
	public function render_variation_block( $loop, $variation_data, $variation ): void {
		unset( $variation_data );

		$variation_id = $variation instanceof \WP_Post ? (int) $variation->ID : 0;
		if ( $variation_id <= 0 ) {
			return;
		}

		$config = GiftCardProductMeta::read( $variation_id );
		$loop   = (int) $loop;

		echo '<div class="options_group mp-cp-gift-card-variation-block" data-loop="' . esc_attr( (string) $loop ) . '">';
		echo '<p class="form-row form-row-full mp-cp-gc-variation-heading"><strong>';
		echo esc_html__( 'Gift card', 'mp-commerce-promotions' );
		echo '</strong></p>';

		woocommerce_wp_checkbox(
			array(
				'id'            => 'mp_cp_sells_gift_card_var[' . $loop . ']',
				'name'          => 'mp_cp_sells_gift_card_var[' . $loop . ']',
				'label'         => __( 'This variation sells a gift card', 'mp-commerce-promotions' ),
				'description'   => __( 'When the order is paid, one gift card is issued per quantity purchased.', 'mp-commerce-promotions' ),
				'value'         => $config['sells'] ? GiftCardProductMeta::VALUE_YES : GiftCardProductMeta::VALUE_NO,
				'wrapper_class' => 'form-row form-row-full',
			)
		);

		echo '<div class="mp-cp-gift-card-variation-fields' . ( $config['sells'] ? '' : ' mp-cp-gift-card-variation-fields--hidden' ) . '">';
		$this->render_fields_inner( $loop, $config, true );
		echo '</div>';
		echo '</div>';
	}

	public function save_simple( int $product_id ): void {
		if ( $product_id <= 0 || ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( $product instanceof WC_Product && $product->is_type( 'variable' ) ) {
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
		$loop         = (int) $loop;
		if ( $variation_id <= 0 || ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		$sells = isset( $_POST['mp_cp_sells_gift_card_var'][ $loop ] )
			&& GiftCardProductMeta::VALUE_YES === sanitize_text_field(
				wp_unslash( (string) $_POST['mp_cp_sells_gift_card_var'][ $loop ] )
			);

		$input          = $this->read_post_input_variation( $loop );
		$input['sells'] = $sells ? GiftCardProductMeta::VALUE_YES : GiftCardProductMeta::VALUE_NO;
		GiftCardProductMeta::save( $variation_id, $input );
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
			echo '<p class="form-field mp-cp-gc-admin-preview"><em>' . esc_html( $preview ) . '</em></p>';
		}

		$virtual_warn = GiftCardProductAdminHelper::virtual_product_warning( $is_virtual );
		if ( $virtual_warn !== '' ) {
			echo '<p class="form-field mp-cp-gc-admin-warning"><strong>' . esc_html__( 'Note:', 'mp-commerce-promotions' ) . '</strong> '
				. esc_html( $virtual_warn ) . '</p>';
		}
	}

	/**
	 * @param array{sells: bool, amount_mode: string, fixed_amount: float, expiry_days: ?int, recipient_mode: string} $config
	 */
	private function render_fields_inner( $loop, array $config, bool $is_variation ): void {
		$suffix         = $is_variation && $loop !== null ? '_' . (int) $loop : '';
		$id_mode        = 'mp_cp_gift_card_amount_mode' . $suffix;
		$name_mode      = $is_variation && $loop !== null ? 'mp_cp_gift_card_amount_mode_var[' . (int) $loop . ']' : 'mp_cp_gift_card_amount_mode';
		$id_fixed       = 'mp_cp_gift_card_fixed_amount' . $suffix;
		$name_fixed     = $is_variation && $loop !== null ? 'mp_cp_gift_card_fixed_amount_var[' . (int) $loop . ']' : 'mp_cp_gift_card_fixed_amount';
		$id_expiry      = 'mp_cp_gift_card_expiry_days' . $suffix;
		$name_expiry    = $is_variation && $loop !== null ? 'mp_cp_gift_card_expiry_days_var[' . (int) $loop . ']' : 'mp_cp_gift_card_expiry_days';
		$id_recipient   = 'mp_cp_gift_card_recipient_mode' . $suffix;
		$name_recipient = $is_variation && $loop !== null ? 'mp_cp_gift_card_recipient_mode_var[' . (int) $loop . ']' : 'mp_cp_gift_card_recipient_mode';
		$mode           = (string) ( $config['recipient_mode'] ?? GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY );

		$show_if_sells     = $is_variation ? '' : ' show_if_mp_cp_sells_gift_card';
		$fixed_show        = $config['amount_mode'] === GiftCardProductMeta::AMOUNT_MODE_FIXED ? '' : ' mp-cp-hidden';
		$customer_show     = $config['amount_mode'] === GiftCardProductMeta::AMOUNT_MODE_CUSTOMER_AMOUNT ? '' : ' mp-cp-hidden';
		$row_class         = $is_variation ? ' form-row form-row-full' : '';
		$id_min            = 'mp_cp_gift_card_min_amount' . $suffix;
		$name_min          = $is_variation && $loop !== null ? 'mp_cp_gift_card_min_amount_var[' . (int) $loop . ']' : 'mp_cp_gift_card_min_amount';
		$id_max            = 'mp_cp_gift_card_max_amount' . $suffix;
		$name_max          = $is_variation && $loop !== null ? 'mp_cp_gift_card_max_amount_var[' . (int) $loop . ']' : 'mp_cp_gift_card_max_amount';
		$id_suggested      = 'mp_cp_gift_card_suggested_amounts' . $suffix;
		$name_suggested    = $is_variation && $loop !== null ? 'mp_cp_gift_card_suggested_amounts_var[' . (int) $loop . ']' : 'mp_cp_gift_card_suggested_amounts';
		$id_default        = 'mp_cp_gift_card_default_amount' . $suffix;
		$name_default      = $is_variation && $loop !== null ? 'mp_cp_gift_card_default_amount_var[' . (int) $loop . ']' : 'mp_cp_gift_card_default_amount';

		woocommerce_wp_select(
			array(
				'id'            => $id_mode,
				'name'          => $name_mode,
				'value'         => $config['amount_mode'],
				'label'         => __( 'Gift card amount mode', 'mp-commerce-promotions' ),
				'description'   => __( 'Choose whether the card value follows the WooCommerce product price or a fixed amount.', 'mp-commerce-promotions' ),
				'options'       => GiftCardProductAdminHelper::amount_mode_options(),
				'wrapper_class' => 'mp_cp_gift_card_amount_mode_field' . $show_if_sells . $row_class,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => $id_fixed,
				'name'              => $name_fixed,
				'value'             => GiftCardProductAdminHelper::fixed_amount_input_value( $config ),
				'label'             => __( 'Fixed gift card amount', 'mp-commerce-promotions' ),
				'description'       => __( 'Used only when amount mode is Fixed amount.', 'mp-commerce-promotions' ),
				'type'              => 'number',
				'placeholder'       => '50.00',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'wrapper_class'     => 'mp_cp_gift_card_fixed_amount_field' . $show_if_sells . $fixed_show . $row_class,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => $id_min,
				'name'              => $name_min,
				'value'             => GiftCardProductAdminHelper::min_amount_input_value( $config ),
				'label'             => __( 'Minimum amount', 'mp-commerce-promotions' ),
				'description'       => __( 'Required when customers enter the amount. Must be greater than zero.', 'mp-commerce-promotions' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0.01',
					'step' => '0.01',
				),
				'wrapper_class'     => 'mp_cp_gift_card_customer_amount_field' . $show_if_sells . $customer_show . $row_class,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => $id_max,
				'name'              => $name_max,
				'value'             => GiftCardProductAdminHelper::max_amount_input_value( $config ),
				'label'             => __( 'Maximum amount', 'mp-commerce-promotions' ),
				'description'       => __( 'Optional upper limit for customer-entered amounts.', 'mp-commerce-promotions' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'wrapper_class'     => 'mp_cp_gift_card_customer_amount_field' . $show_if_sells . $customer_show . $row_class,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => $id_suggested,
				'name'        => $name_suggested,
				'value'       => GiftCardProductAdminHelper::suggested_amounts_input_value( $config ),
				'label'       => __( 'Suggested amounts', 'mp-commerce-promotions' ),
				'description' => __( 'Comma-separated values shown as quick picks on the product page (e.g. 25,50,100).', 'mp-commerce-promotions' ),
				'placeholder' => '25,50,100',
				'wrapper_class' => 'mp_cp_gift_card_customer_amount_field' . $show_if_sells . $customer_show . $row_class,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => $id_default,
				'name'              => $name_default,
				'value'             => GiftCardProductAdminHelper::default_amount_input_value( $config ),
				'label'             => __( 'Default amount (optional)', 'mp-commerce-promotions' ),
				'description'       => __( 'Prefills the amount field on the product page when within min/max.', 'mp-commerce-promotions' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'wrapper_class'     => 'mp_cp_gift_card_customer_amount_field' . $show_if_sells . $customer_show . $row_class,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => $id_expiry,
				'name'              => $name_expiry,
				'value'             => GiftCardProductAdminHelper::expiry_days_input_value( $config['expiry_days'] ),
				'label'             => __( 'Expiry days', 'mp-commerce-promotions' ),
				'description'       => __( '0 = no expiry', 'mp-commerce-promotions' ),
				'type'              => 'number',
				'placeholder'       => '365',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'wrapper_class'     => 'mp_cp_gift_card_expiry_days_field' . $show_if_sells . $row_class,
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => $id_recipient,
				'name'          => $name_recipient,
				'value'         => $mode,
				'label'         => __( 'Recipient mode', 'mp-commerce-promotions' ),
				'description'   => GiftCardProductAdminHelper::recipient_mode_help( $mode ),
				'options'       => GiftCardProductAdminHelper::recipient_mode_options(),
				'wrapper_class' => 'mp_cp_gift_card_recipient_mode_field' . $show_if_sells . $row_class,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_post_input(): array {
		$sells = isset( $_POST['mp_cp_sells_gift_card'] )
			&& GiftCardProductMeta::VALUE_YES === sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_sells_gift_card'] ) );

		return array(
			'sells'              => $sells ? GiftCardProductMeta::VALUE_YES : GiftCardProductMeta::VALUE_NO,
			'amount_mode'        => isset( $_POST['mp_cp_gift_card_amount_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_amount_mode'] ) ) : '',
			'fixed_amount'       => isset( $_POST['mp_cp_gift_card_fixed_amount'] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_fixed_amount'] ) : '',
			'min_amount'         => isset( $_POST['mp_cp_gift_card_min_amount'] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_min_amount'] ) : '',
			'max_amount'         => isset( $_POST['mp_cp_gift_card_max_amount'] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_max_amount'] ) : '',
			'suggested_amounts'  => isset( $_POST['mp_cp_gift_card_suggested_amounts'] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_suggested_amounts'] ) : '',
			'default_amount'     => isset( $_POST['mp_cp_gift_card_default_amount'] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_default_amount'] ) : '',
			'expiry_days'        => isset( $_POST['mp_cp_gift_card_expiry_days'] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_expiry_days'] ) : '',
			'recipient_mode'     => isset( $_POST['mp_cp_gift_card_recipient_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_recipient_mode'] ) ) : '',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_post_input_variation( int $loop ): array {
		return array(
			'amount_mode'       => isset( $_POST['mp_cp_gift_card_amount_mode_var'][ $loop ] ) ? sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_amount_mode_var'][ $loop ] ) ) : '',
			'fixed_amount'      => isset( $_POST['mp_cp_gift_card_fixed_amount_var'][ $loop ] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_fixed_amount_var'][ $loop ] ) : '',
			'min_amount'        => isset( $_POST['mp_cp_gift_card_min_amount_var'][ $loop ] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_min_amount_var'][ $loop ] ) : '',
			'max_amount'        => isset( $_POST['mp_cp_gift_card_max_amount_var'][ $loop ] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_max_amount_var'][ $loop ] ) : '',
			'suggested_amounts' => isset( $_POST['mp_cp_gift_card_suggested_amounts_var'][ $loop ] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_suggested_amounts_var'][ $loop ] ) : '',
			'default_amount'    => isset( $_POST['mp_cp_gift_card_default_amount_var'][ $loop ] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_default_amount_var'][ $loop ] ) : '',
			'expiry_days'       => isset( $_POST['mp_cp_gift_card_expiry_days_var'][ $loop ] ) ? wp_unslash( (string) $_POST['mp_cp_gift_card_expiry_days_var'][ $loop ] ) : '',
			'recipient_mode'    => isset( $_POST['mp_cp_gift_card_recipient_mode_var'][ $loop ] ) ? sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_recipient_mode_var'][ $loop ] ) ) : '',
		);
	}
}
