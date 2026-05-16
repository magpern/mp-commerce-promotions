<?php
/**
 * Canonical condition and action type identifiers for the promotion rule engine.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class RuleTypes {

	public const CONDITION_MINIMUM_SUBTOTAL = 'minimum_subtotal';

	public const CONDITION_PRODUCT_QUANTITY = 'product_quantity';

	public const CONDITION_CATEGORY_QUANTITY = 'category_quantity';

	public const CONDITION_LOGGED_IN = 'logged_in';

	public const CONDITION_FIRST_ORDER = 'first_order';

	public const ACTION_PERCENTAGE_DISCOUNT = 'percentage_discount';

	public const ACTION_FIXED_AMOUNT_DISCOUNT = 'fixed_amount_discount';

	private function __construct() {
	}
}
