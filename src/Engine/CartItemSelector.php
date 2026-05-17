<?php
/**
 * Cart line filtering for the evaluation engine (extends WooCommerce-aware selector).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class CartItemSelector extends \MP\CommercePromotions\Woo\CartItemSelector {

	private function __construct() {
	}
}
