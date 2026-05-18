<?php
/**
 * Applies customer store credit as a negative cart fee (preview only; ledger on order).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRedemptionService;
use MP\CommercePromotions\GiftCard\StoreCreditCheckoutService;
use Throwable;

final class StoreCreditCartApplier {

	private GiftCardLedger $ledger;

	private StoreCreditCheckoutService $checkout;

	private GiftCardRedemptionService $redemption;

	public function __construct(
		GiftCardLedger $ledger,
		StoreCreditCheckoutService $checkout,
		?GiftCardRedemptionService $redemption = null
	) {
		$this->ledger     = $ledger;
		$this->checkout   = $checkout;
		$this->redemption = $redemption ?? new GiftCardRedemptionService( $ledger );
	}

	public function apply_cart_fee(): void {
		try {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return;
			}

			if ( ! $this->checkout->can_apply() ) {
				StoreCreditSession::clear();
				return;
			}

			if ( ! function_exists( 'WC' ) ) {
				return;
			}

			$wc = WC();
			if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) ) {
				return;
			}

			$cart = $wc->cart;
			if ( ! method_exists( $cart, 'add_fee' ) ) {
				return;
			}

			$session = StoreCreditSession::get();
			if ( $session === null ) {
				return;
			}

			$card = $this->ledger->find( $session['account_id'] );
			if ( $card === null || ! $card->is_store_credit_wallet() || ! $this->redemption->is_redeemable( $card ) ) {
				StoreCreditSession::clear();
				return;
			}

			$customer_id = $this->checkout->get_current_customer_id();
			if ( $customer_id <= 0 || $card->get_owner_customer_id() !== $customer_id ) {
				StoreCreditSession::clear();
				return;
			}

			$payable = $this->estimate_cart_payable( $cart );
			$amount  = $this->redemption->preview_apply_amount( $card, $payable );
			if ( $amount <= 0 ) {
				StoreCreditSession::clear();
				return;
			}

			StoreCreditSession::set(
				array(
					'account_id'     => $session['account_id'],
					'applied_amount' => $amount,
					'currency'       => $card->get_currency(),
				)
			);

			$cart->add_fee(
				__( 'Store credit balance', 'mp-commerce-promotions' ),
				-$amount,
				false
			);
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[mp-commerce-promotions] StoreCreditCartApplier: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * @param object $cart WC_Cart.
	 */
	public function estimate_cart_payable( $cart ): float {
		$payable = 0.0;
		if ( method_exists( $cart, 'get_cart_contents_total' ) ) {
			$payable += (float) $cart->get_cart_contents_total();
		}
		if ( method_exists( $cart, 'get_shipping_total' ) ) {
			$payable += (float) $cart->get_shipping_total();
		}
		if ( method_exists( $cart, 'get_cart_contents_tax' ) ) {
			$payable += (float) $cart->get_cart_contents_tax();
		}
		if ( method_exists( $cart, 'get_shipping_tax' ) ) {
			$payable += (float) $cart->get_shipping_tax();
		}
		if ( method_exists( $cart, 'get_fee_total' ) ) {
			$payable += (float) $cart->get_fee_total();
		}

		return GiftCard::money( max( 0.0, $payable ) );
	}
}
