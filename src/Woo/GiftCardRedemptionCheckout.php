<?php
/**
 * Unified cart/checkout gift card + store credit redemption UI.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRedemptionService;
use MP\CommercePromotions\GiftCard\StoreCreditCheckoutService;

final class GiftCardRedemptionCheckout {

	private const NONCE_GIFT = 'mp_cp_apply_gift_card';

	private const NONCE_GIFT_FIELD = 'mp_cp_gift_card_nonce';

	private const NONCE_SC = 'mp_cp_apply_store_credit';

	private const NONCE_SC_FIELD = 'mp_cp_store_credit_nonce';

	private GiftCardLedger $ledger;

	private GiftCardRedemptionService $redemption;

	private GiftCardCartApplier $gift_applier;

	private StoreCreditCheckoutService $store_credit;

	private StoreCreditCartApplier $sc_applier;

	public function __construct(
		GiftCardLedger $ledger,
		GiftCardCartApplier $gift_applier,
		StoreCreditCheckoutService $store_credit,
		StoreCreditCartApplier $sc_applier,
		?GiftCardRedemptionService $redemption = null
	) {
		$this->ledger       = $ledger;
		$this->gift_applier = $gift_applier;
		$this->store_credit = $store_credit;
		$this->sc_applier   = $sc_applier;
		$this->redemption   = $redemption ?? new GiftCardRedemptionService( $ledger );
	}

	public function register(): void {
		$hooks = array(
			'woocommerce_before_cart',
			'woocommerce_before_checkout_form',
			'woocommerce_cart_totals_before_order_total',
		);
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( $this, 'maybe_handle_post' ), 5 );
			add_action( $hook, array( $this, 'render_form' ), 15 );
		}
	}

	public function maybe_handle_post(): void {
		try {
			$this->handle_gift_card_post();
			$this->handle_store_credit_post();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[mp-commerce-promotions] GiftCardRedemptionCheckout: ' . $e->getMessage() );
			}
		}
	}

	private function handle_gift_card_post(): void {
		if ( ! isset( $_POST['mp_cp_gift_card_action'] ) || ! function_exists( 'WC' ) || ! CartSessionHelper::has_wc_session() ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_action'] ) );
		if (
			! isset( $_POST[ self::NONCE_GIFT_FIELD ] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_GIFT_FIELD ] ) ),
				self::NONCE_GIFT
			)
		) {
			$this->notice( __( 'Security check failed. Please try again.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		if ( $action === 'remove' ) {
			GiftCardSession::clear();
			$this->notice( __( 'Gift card removed.', 'mp-commerce-promotions' ), 'success' );
			$this->recalculate();
			return;
		}

		if ( $action !== 'apply' ) {
			return;
		}

		$plain = isset( $_POST['mp_cp_gift_card_code'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_gift_card_code'] ) )
			: '';
		if ( $plain === '' ) {
			$this->notice( __( 'Enter a gift card code.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$card = $this->ledger->find_by_plain_code( $plain );
		if ( $card === null || $card->is_store_credit_wallet() || ! $this->redemption->is_redeemable( $card ) ) {
			$this->notice( __( 'This gift card is not valid or cannot be used on this order.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$id = $card->get_id();
		if ( $id === null || $id <= 0 ) {
			$this->notice( __( 'This gift card is not valid or cannot be used on this order.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$payable = $this->payable_total();
		$amount  = $this->redemption->preview_apply_amount( $card, $payable );
		if ( $amount <= 0 ) {
			$this->notice( __( 'No balance can be applied to this cart.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		GiftCardSession::set(
			array(
				'gift_card_id'   => $id,
				'code_last4'     => $card->get_code_last4(),
				'applied_amount' => $amount,
			)
		);
		GiftCardMyAccount::stash_reveal_code( $plain );

		$remaining = GiftCard::money( $card->get_balance() - $amount );
		$this->notice(
			sprintf(
				/* translators: 1: applied amount, 2: estimated remaining balance */
				__( 'Gift card applied: %1$s. Estimated remaining balance on this card: %2$s.', 'mp-commerce-promotions' ),
				function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $amount ) ) : (string) $amount,
				function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $remaining ) ) : (string) $remaining
			),
			'success'
		);
		$this->recalculate();
	}

	private function handle_store_credit_post(): void {
		if ( ! isset( $_POST['mp_cp_store_credit_action'] ) || ! function_exists( 'WC' ) || ! CartSessionHelper::has_wc_session() ) {
			return;
		}

		if ( ! $this->store_credit->can_apply() ) {
			return;
		}

		if (
			! isset( $_POST[ self::NONCE_SC_FIELD ] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_SC_FIELD ] ) ),
				self::NONCE_SC
			)
		) {
			$this->notice( __( 'Security check failed. Please try again.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['mp_cp_store_credit_action'] ) );
		if ( $action === 'remove' ) {
			$this->store_credit->remove_from_session();
			$this->notice( __( 'Store credit removed.', 'mp-commerce-promotions' ), 'success' );
			$this->recalculate();
			return;
		}

		if ( $action !== 'apply' ) {
			return;
		}

		$customer_id = $this->store_credit->get_current_customer_id();
		$currency    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
		if ( ! $this->store_credit->apply_to_session( $customer_id, $currency, $this->payable_total() ) ) {
			$this->notice( __( 'No store credit balance can be applied to this cart.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$applied = $this->store_credit->get_applied_from_session();
		$amount  = $applied !== null ? (float) $applied['applied_amount'] : 0.0;
		$this->notice(
			sprintf(
				/* translators: %s: formatted amount */
				__( 'Store credit applied: %s. Any unused balance stays in your wallet.', 'mp-commerce-promotions' ),
				function_exists( 'wc_price' ) ? wp_price( $amount ) : (string) $amount
			),
			'success'
		);
		$this->recalculate();
	}

	public function render_form(): void {
		if ( ! function_exists( 'WC' ) || ! CartSessionHelper::has_wc_session() ) {
			return;
		}

		GiftCardCustomerAssets::enqueue();

		$gift_applied = GiftCardSession::get();
		$sc_applied   = $this->store_credit->can_apply() ? $this->store_credit->get_applied_from_session() : null;
		$sc_balance   = 0.0;
		if ( $this->store_credit->can_apply() ) {
			$sc_balance = $this->store_credit->get_available_balance(
				$this->store_credit->get_current_customer_id(),
				function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR'
			);
		}

		echo '<div class="mp-cp-gift-card-checkout">';
		echo '<h3 class="mp-cp-gc-title">' . esc_html__( 'Gift card or store credit', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="mp-cp-gc-help">' . esc_html__( 'Apply a gift card code and/or your store credit wallet. Partial payment is supported — pay the remainder with another method.', 'mp-commerce-promotions' ) . '</p>';

		if ( $gift_applied !== null ) {
			echo '<p><strong>' . esc_html__( 'Gift card', 'mp-commerce-promotions' ) . ':</strong> ****' . esc_html( $gift_applied['code_last4'] ) . ' — ';
			echo esc_html(
				function_exists( 'wc_price' )
					? wp_strip_all_tags( wc_price( $gift_applied['applied_amount'] ) )
					: (string) $gift_applied['applied_amount']
			);
			echo '</p>';
			echo '<form method="post" style="margin:0 0 12px;">';
			wp_nonce_field( self::NONCE_GIFT, self::NONCE_GIFT_FIELD );
			echo '<input type="hidden" name="mp_cp_gift_card_action" value="remove" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Remove gift card', 'mp-commerce-promotions' ) . '</button></form>';
		} else {
			echo '<form method="post" style="margin:0 0 16px;">';
			wp_nonce_field( self::NONCE_GIFT, self::NONCE_GIFT_FIELD );
			echo '<input type="hidden" name="mp_cp_gift_card_action" value="apply" />';
			echo '<p><label for="mp_cp_gift_card_code">' . esc_html__( 'Gift card code', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<input type="text" class="input-text" name="mp_cp_gift_card_code" id="mp_cp_gift_card_code" autocomplete="off" style="max-width:320px;" /></p>';
			echo '<button type="submit" class="button">' . esc_html__( 'Apply gift card', 'mp-commerce-promotions' ) . '</button></form>';
		}

		if ( $this->store_credit->can_apply() ) {
			echo '<hr style="margin:16px 0;border:0;border-top:1px solid #dcdcde;" />';
			echo '<p><strong>' . esc_html__( 'Store credit wallet', 'mp-commerce-promotions' ) . '</strong> — ';
			echo esc_html(
				sprintf(
					/* translators: %s: balance */
					__( 'Available: %s', 'mp-commerce-promotions' ),
					function_exists( 'wc_price' )
						? wp_strip_all_tags( wc_price( $sc_balance ) )
						: number_format( $sc_balance, 2 )
				)
			);
			echo '</p>';

			if ( $sc_applied !== null && $sc_applied['applied_amount'] > 0 ) {
				echo '<form method="post"><input type="hidden" name="mp_cp_store_credit_action" value="remove" />';
				wp_nonce_field( self::NONCE_SC, self::NONCE_SC_FIELD );
				echo '<button type="submit" class="button">' . esc_html__( 'Remove store credit', 'mp-commerce-promotions' ) . '</button></form>';
			} elseif ( $sc_balance > 0 ) {
				echo '<form method="post"><input type="hidden" name="mp_cp_store_credit_action" value="apply" />';
				wp_nonce_field( self::NONCE_SC, self::NONCE_SC_FIELD );
				echo '<button type="submit" class="button">' . esc_html__( 'Apply store credit', 'mp-commerce-promotions' ) . '</button></form>';
			}
		}

		echo '</div>';
	}

	private function payable_total(): float {
		if ( function_exists( 'WC' ) && isset( WC()->cart ) && is_object( WC()->cart ) ) {
			return $this->gift_applier->estimate_cart_payable( WC()->cart );
		}

		return 0.0;
	}

	private function notice( string $message, string $type ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}
	}

	private function recalculate(): void {
		if ( function_exists( 'WC' ) && isset( WC()->cart ) && is_object( WC()->cart ) && method_exists( WC()->cart, 'calculate_totals' ) ) {
			WC()->cart->calculate_totals();
		}
	}
}
