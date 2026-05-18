<?php
/**
 * Money rounding helper for gift card Woo integration.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCard;

final class GiftCardAmount {

	public static function money( float $amount ): float {
		return GiftCard::money( $amount );
	}
}
