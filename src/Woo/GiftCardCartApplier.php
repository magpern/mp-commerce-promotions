<?php
/**
 * Applies gift card store credit as a negative cart fee (preview only; ledger on order).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRedemptionService;
use Throwable;

final class GiftCardCartApplier {

	private GiftCardLedger $ledger;

	private GiftCardRedemptionService $redemption;

	public function __construct( GiftCardLedger $ledger, ?GiftCardRedemptionService $redemption = null ) {
		$this->ledger     = $ledger;
		$this->redemption = $redemption ?? new GiftCardRedemptionService( $ledger );
	}

	public function apply_cart_fee(): void {
		try {
			if ( is_admin() && ! wp_doing_ajax() ) {
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

			$session = GiftCardSession::get();
			if ( $session === null ) {
				return;
			}

			$card = $this->ledger->find( $session['gift_card_id'] );
			if ( $card === null || ! $this->redemption->is_redeemable( $card ) ) {
				GiftCardSession::clear();
				return;
			}

			$payable = $this->estimate_cart_payable( $cart );
			$amount  = $this->redemption->preview_apply_amount( $card, $payable );
			if ( $amount <= 0 ) {
				GiftCardSession::clear();
				return;
			}

			GiftCardSession::set(
				array(
					'gift_card_id'   => $session['gift_card_id'],
					'code_last4'     => $card->get_code_last4(),
					'applied_amount' => $amount,
				)
			);

			$label = sprintf(
				/* translators: %s: last four characters of the gift card code */
				__( 'Store credit ****%s', 'mp-commerce-promotions' ),
				$card->get_code_last4()
			);

			$cart->add_fee( $label, -$amount, false );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[mp-commerce-promotions] GiftCardCartApplier: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Payable cart total before gift card fee (includes promotion fees).
	 *
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
