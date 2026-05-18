<?php
/**
 * Cart/checkout store credit apply form for logged-in customers (classic MVP).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\StoreCreditCheckoutService;

final class StoreCreditCheckoutForm {

	private const NONCE_ACTION = 'mp_cp_apply_store_credit';

	private const NONCE_FIELD = 'mp_cp_store_credit_nonce';

	private StoreCreditCheckoutService $checkout;

	private StoreCreditCartApplier $cart_applier;

	public function __construct(
		StoreCreditCheckoutService $checkout,
		StoreCreditCartApplier $cart_applier
	) {
		$this->checkout     = $checkout;
		$this->cart_applier = $cart_applier;
	}

	public function register(): void {
		add_action( 'woocommerce_before_cart', array( $this, 'maybe_handle_post' ), 6 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'maybe_handle_post' ), 6 );
		add_action( 'woocommerce_before_cart', array( $this, 'render_form' ), 16 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_form' ), 16 );
	}

	public function maybe_handle_post(): void {
		if ( ! isset( $_POST['mp_cp_store_credit_action'] ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! CartSessionHelper::has_wc_session() ) {
			return;
		}

		if ( ! $this->checkout->can_apply() ) {
			$this->add_notice( __( 'Sign in to use your store credit balance.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['mp_cp_store_credit_action'] ) );

		if (
			! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ),
				self::NONCE_ACTION
			)
		) {
			$this->add_notice( __( 'Security check failed. Please try again.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		if ( $action === 'remove' ) {
			$this->checkout->remove_from_session();
			$this->add_notice( __( 'Store credit removed.', 'mp-commerce-promotions' ), 'success' );
			$this->recalculate_cart();
			return;
		}

		if ( $action !== 'apply' ) {
			return;
		}

		$customer_id = $this->checkout->get_current_customer_id();
		$currency    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';

		$payable = 0.0;
		if ( isset( WC()->cart ) && is_object( WC()->cart ) ) {
			$payable = $this->cart_applier->estimate_cart_payable( WC()->cart );
		}

		if ( ! $this->checkout->apply_to_session( $customer_id, $currency, $payable ) ) {
			$this->add_notice( __( 'No store credit balance can be applied to this cart.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$applied = $this->checkout->get_applied_from_session();
		$amount  = $applied !== null ? (float) $applied['applied_amount'] : 0.0;

		$this->add_notice(
			sprintf(
				/* translators: %s: formatted amount */
				__( 'Store credit applied: %s', 'mp-commerce-promotions' ),
				function_exists( 'wc_price' ) ? wc_price( $amount ) : (string) $amount
			),
			'success'
		);
		$this->recalculate_cart();
	}

	public function render_form(): void {
		if ( ! function_exists( 'WC' ) || ! CartSessionHelper::has_wc_session() ) {
			return;
		}

		if ( ! $this->checkout->can_apply() ) {
			return;
		}

		$customer_id = $this->checkout->get_current_customer_id();
		$currency    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
		$balance     = $this->checkout->get_available_balance( $customer_id, $currency );
		$applied     = $this->checkout->get_applied_from_session();

		echo '<div class="mp-cp-store-credit-checkout" style="margin:1em 0;padding:1em;border:1px solid #dcdcde;background:#fff;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Store credit balance', 'mp-commerce-promotions' ) . '</h3>';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: formatted balance */
					__( 'Available: %s', 'mp-commerce-promotions' ),
					function_exists( 'wc_price' )
						? wp_strip_all_tags( wc_price( $balance ) )
						: number_format( $balance, 2 )
				)
			)
		);

		if ( $applied !== null && $applied['applied_amount'] > 0 ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: formatted amount */
						__( 'Applied to this order: %s', 'mp-commerce-promotions' ),
						function_exists( 'wc_price' )
							? wp_strip_all_tags( wc_price( $applied['applied_amount'] ) )
							: (string) $applied['applied_amount']
					)
				)
			);
			echo '<form method="post" style="margin-top:8px;">';
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			echo '<input type="hidden" name="mp_cp_store_credit_action" value="remove" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Remove store credit', 'mp-commerce-promotions' ) . '</button>';
			echo '</form>';
		} elseif ( $balance > 0 ) {
			echo '<form method="post" style="margin-top:8px;">';
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			echo '<input type="hidden" name="mp_cp_store_credit_action" value="apply" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Apply store credit', 'mp-commerce-promotions' ) . '</button>';
			echo '</form>';
		}

		echo '</div>';
	}

	private function add_notice( string $message, string $type ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}
	}

	private function recalculate_cart(): void {
		if ( function_exists( 'WC' ) && isset( WC()->cart ) && is_object( WC()->cart ) && method_exists( WC()->cart, 'calculate_totals' ) ) {
			WC()->cart->calculate_totals();
		}
	}
}
