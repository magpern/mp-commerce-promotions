<?php
/**
 * Condition: cart must not contain on-sale line items.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class ExcludeSaleItemsCondition implements ConditionInterface {

	public function get_type(): string {
		return RuleTypes::CONDITION_EXCLUDE_SALE_ITEMS;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$sale_count = CartItemSelector::count_sale_items( $context->get_items() );

		$observed = array(
			'sale_item_count' => $sale_count,
		);

		if ( $sale_count === 0 ) {
			return ConditionResult::pass( null, ConditionTrace::REASON_PASSED, $observed );
		}

		return ConditionResult::fail(
			sprintf( 'Cart contains %d on-sale line item(s); promotion requires no sale items.', $sale_count ),
			ConditionTrace::REASON_SALE_ITEMS_PRESENT,
			$observed
		);
	}
}
