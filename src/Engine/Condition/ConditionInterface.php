<?php
/**
 * Future rule condition contract (no evaluation logic yet).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

interface ConditionInterface {

	/**
	 * Machine-readable condition type (e.g. cart_category_quantity).
	 */
	public function get_type(): string;
}
