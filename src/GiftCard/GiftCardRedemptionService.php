<?php
/**
 * Gift card checkout preview amounts (no ledger writes).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardRedemptionService {

	private GiftCardLedger $ledger;

	public function __construct( GiftCardLedger $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * Amount that can be applied to the current cart (min of balance and payable).
	 */
	public function preview_apply_amount( GiftCard $card, float $cart_payable ): float {
		$payable = GiftCard::money( $cart_payable );
		if ( $payable <= 0 ) {
			return 0.0;
		}

		$now = current_time( 'mysql' );
		if ( ! $card->can_redeem( 0.01, $now ) ) {
			return 0.0;
		}

		return GiftCard::money( min( $card->get_balance(), $payable ) );
	}

	public function is_redeemable( GiftCard $card ): bool {
		return $this->redeemability_error( $card ) === null;
	}

	/**
	 * User-facing reason the card cannot be applied, or null when redeemable for this cart.
	 */
	public function redeemability_error( GiftCard $card ): ?string {
		if ( $card->is_store_credit_wallet() ) {
			return __( 'This code is a store credit wallet, not a gift card. Use store credit below or in My Account.', 'mp-commerce-promotions' );
		}

		if ( $card->get_status() === GiftCard::STATUS_VOIDED ) {
			return __( 'This gift card has been voided and cannot be used.', 'mp-commerce-promotions' );
		}

		if ( $card->get_status() === GiftCard::STATUS_EXPIRED || $card->is_expired_at( current_time( 'mysql' ) ) ) {
			return __( 'This gift card has expired.', 'mp-commerce-promotions' );
		}

		if ( $card->get_status() === GiftCard::STATUS_DEPLETED || $card->get_balance() <= 0 ) {
			return __( 'This gift card has no remaining balance.', 'mp-commerce-promotions' );
		}

		if ( $card->get_status() !== GiftCard::STATUS_ACTIVE ) {
			return __( 'This gift card is not active and cannot be used.', 'mp-commerce-promotions' );
		}

		if ( ! $this->matches_cart_currency( $card ) ) {
			return sprintf(
				/* translators: 1: gift card currency, 2: cart currency */
				__( 'This gift card is in %1$s but your cart uses %2$s. Use a card in the same currency.', 'mp-commerce-promotions' ),
				strtoupper( $card->get_currency() ),
				strtoupper( $this->cart_currency() )
			);
		}

		if ( ! $card->can_redeem( 0.01, current_time( 'mysql' ) ) ) {
			return __( 'This gift card cannot be applied to this order.', 'mp-commerce-promotions' );
		}

		return null;
	}

	public function cart_currency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = get_woocommerce_currency();
			if ( is_string( $currency ) && $currency !== '' ) {
				return strtoupper( $currency );
			}
		}

		return 'EUR';
	}

	public function matches_cart_currency( GiftCard $card ): bool {
		return strtoupper( $card->get_currency() ) === $this->cart_currency();
	}
}
