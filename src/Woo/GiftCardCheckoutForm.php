<?php
/**
 * Cart/checkout gift card apply form (classic MVP).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRedemptionService;

final class GiftCardCheckoutForm {

	private const NONCE_ACTION = 'mp_cp_apply_gift_card';

	private const NONCE_FIELD = 'mp_cp_gift_card_nonce';

	private GiftCardLedger $ledger;

	private GiftCardRedemptionService $redemption;

	private GiftCardCartApplier $cart_applier;

	public function __construct(
		GiftCardLedger $ledger,
		GiftCardCartApplier $cart_applier,
		?GiftCardRedemptionService $redemption = null
	) {
		$this->ledger       = $ledger;
		$this->cart_applier  = $cart_applier;
		$this->redemption    = $redemption ?? new GiftCardRedemptionService( $ledger );
	}

	public function register(): void {
		add_action( 'woocommerce_before_cart', array( $this, 'maybe_handle_post' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'maybe_handle_post' ), 5 );
		add_action( 'woocommerce_before_cart', array( $this, 'render_form' ), 15 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_form' ), 15 );
	}

	public function maybe_handle_post(): void {
		if ( ! isset( $_POST['mp_cp_gift_card_action'] ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! CartSessionHelper::has_wc_session() ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_action'] ) );

		if ( $action === 'remove' ) {
			if (
				! isset( $_POST[ self::NONCE_FIELD ] )
				|| ! wp_verify_nonce(
					sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ),
					self::NONCE_ACTION
				)
			) {
				return;
			}
			GiftCardSession::clear();
			$this->add_notice( __( 'Gift card removed.', 'mp-commerce-promotions' ), 'success' );
			$this->recalculate_cart();
			return;
		}

		if ( $action !== 'apply' ) {
			return;
		}

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

		$plain = isset( $_POST['mp_cp_gift_card_code'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_gift_card_code'] ) )
			: '';
		if ( $plain === '' ) {
			$this->add_notice( __( 'Enter a gift card code.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$card = $this->ledger->find_by_plain_code( $plain );
		if ( $card === null || ! $this->redemption->is_redeemable( $card ) ) {
			$this->add_notice( __( 'Gift card is not valid or cannot be used.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$id = $card->get_id();
		if ( $id === null || $id <= 0 ) {
			$this->add_notice( __( 'Gift card is not valid or cannot be used.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$payable = 0.0;
		if ( function_exists( 'WC' ) && isset( WC()->cart ) && is_object( WC()->cart ) ) {
			$payable = $this->cart_applier->estimate_cart_payable( WC()->cart );
		}

		$amount = $this->redemption->preview_apply_amount( $card, $payable );
		if ( $amount <= 0 ) {
			$this->add_notice( __( 'No balance can be applied to this cart.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		GiftCardSession::set(
			array(
				'gift_card_id'   => $id,
				'code_last4'     => $card->get_code_last4(),
				'applied_amount' => $amount,
			)
		);

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

		$applied = GiftCardSession::get();

		echo '<div class="mp-cp-gift-card-checkout" style="margin:1em 0;padding:1em;border:1px solid #dcdcde;background:#fff;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Gift card or store credit', 'mp-commerce-promotions' ) . '</h3>';

		if ( $applied !== null ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: last four digits, 2: amount */
						__( 'Applied: ****%1$s (%2$s)', 'mp-commerce-promotions' ),
						$applied['code_last4'],
						function_exists( 'wc_price' )
							? wp_strip_all_tags( wc_price( $applied['applied_amount'] ) )
							: (string) $applied['applied_amount']
					)
				)
			);
			echo '<form method="post" style="margin-top:8px;">';
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			echo '<input type="hidden" name="mp_cp_gift_card_action" value="remove" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Remove gift card', 'mp-commerce-promotions' ) . '</button>';
			echo '</form>';
		} else {
			echo '<form method="post" class="mp-cp-gift-card-apply-form">';
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			echo '<input type="hidden" name="mp_cp_gift_card_action" value="apply" />';
			echo '<p><label for="mp_cp_gift_card_code">' . esc_html__( 'Gift card code', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<input type="text" class="input-text" name="mp_cp_gift_card_code" id="mp_cp_gift_card_code" autocomplete="off" style="max-width:320px;" /></p>';
			echo '<button type="submit" class="button">' . esc_html__( 'Apply gift card', 'mp-commerce-promotions' ) . '</button>';
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
