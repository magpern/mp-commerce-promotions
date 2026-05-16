<?php
/**
 * Condition: maximum total cart quantity across all line items.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\CartQuantityHelper;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class MaximumCartQuantityCondition implements ConditionInterface {

	private int $quantity;

	public function __construct( int $quantity ) {
		if ( $quantity < 1 ) {
			throw new InvalidArgumentException( 'maximum_cart_quantity quantity must be >= 1.' );
		}
		$this->quantity = $quantity;
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$actual = CartQuantityHelper::total_quantity_from_items( $context->get_items() );
		$observed = array(
			'cart_total_quantity' => $actual,
			'required_maximum'    => $this->quantity,
		);

		if ( $actual > (float) $this->quantity ) {
			return ConditionResult::fail(
				sprintf(
					'Cart quantity %.4f exceeds maximum %d.',
					$actual,
					$this->quantity
				),
				ConditionTrace::REASON_QUANTITY_NOT_MET,
				$observed
			);
		}

		return ConditionResult::pass( null, ConditionTrace::REASON_PASSED, $observed );
	}
}
