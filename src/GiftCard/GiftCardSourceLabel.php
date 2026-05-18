<?php
/**
 * Human-readable gift card / wallet source labels.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardSourceLabel {

	public static function for_card( GiftCard $card ): string {
		if ( $card->is_store_credit_wallet() ) {
			return __( 'Store credit wallet', 'mp-commerce-promotions' );
		}

		$order_id = $card->get_created_order_id();
		if ( $order_id !== null && $order_id > 0 ) {
			return sprintf(
				/* translators: %d: order ID */
				__( 'Product order #%d', 'mp-commerce-promotions' ),
				$order_id
			);
		}

		return __( 'Manual issue', 'mp-commerce-promotions' );
	}
}
