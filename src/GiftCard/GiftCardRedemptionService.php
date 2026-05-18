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
		return $card->can_redeem( 0.01, current_time( 'mysql' ) );
	}
}
