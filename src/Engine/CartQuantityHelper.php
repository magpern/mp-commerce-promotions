<?php
/**
 * Cart quantity helpers for evaluation context line items.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class CartQuantityHelper {

	private function __construct() {
	}

	/**
	 * Sum quantities across cart line items in context.
	 *
	 * @param array<mixed> $items
	 */
	public static function total_quantity_from_items( array $items ): float {
		$total = 0.0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! isset( $item['quantity'] ) || ! is_numeric( $item['quantity'] ) ) {
				continue;
			}
			$qty = (float) $item['quantity'];
			if ( $qty > 0 ) {
				$total += $qty;
			}
		}

		return $total;
	}
}
