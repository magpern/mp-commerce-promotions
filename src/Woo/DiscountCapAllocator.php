<?php
/**
 * Pure discount cap logic for stacked promotion fees.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class DiscountCapAllocator {

	/**
	 * Clamp a discount to the remaining cart subtotal allowance.
	 */
	public static function clamp_to_remaining( float $discount, float $remaining_allowance ): float {
		if ( $discount <= 0 || $remaining_allowance <= 0 ) {
			return 0.0;
		}

		if ( $discount > $remaining_allowance ) {
			return $remaining_allowance;
		}

		return $discount;
	}

	/**
	 * @param list<float> $discounts
	 */
	public static function sum_capped_discounts( float $cart_subtotal, array $discounts ): float {
		$remaining = $cart_subtotal;
		$total     = 0.0;

		foreach ( $discounts as $discount ) {
			if ( $remaining <= 0 ) {
				break;
			}
			$applied = self::clamp_to_remaining( (float) $discount, $remaining );
			if ( $applied <= 0 ) {
				continue;
			}
			$total     += $applied;
			$remaining -= $applied;
		}

		return $total;
	}
}
