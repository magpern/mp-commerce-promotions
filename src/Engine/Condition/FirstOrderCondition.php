<?php
/**
 * Condition: customer has no previous orders (metadata has_previous_orders from cart context).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class FirstOrderCondition implements ConditionInterface {

	public function get_type(): string {
		return RuleTypes::CONDITION_FIRST_ORDER;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$metadata = $context->get_metadata();

		if ( ! array_key_exists( 'has_previous_orders', $metadata ) ) {
			return ConditionResult::fail(
				'Order history is not available (has_previous_orders metadata missing).'
			);
		}

		if ( $metadata['has_previous_orders'] === false ) {
			return ConditionResult::pass();
		}

		if ( $metadata['has_previous_orders'] === true ) {
			return ConditionResult::fail( 'Customer has previous orders.' );
		}

		return ConditionResult::fail( 'has_previous_orders metadata must be a boolean.' );
	}
}
