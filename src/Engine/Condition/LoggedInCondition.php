<?php
/**
 * Condition: customer must be logged in (customer_id present on context).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class LoggedInCondition implements ConditionInterface {

	public function get_type(): string {
		return RuleTypes::CONDITION_LOGGED_IN;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$customer_id = $context->get_customer_id();
		if ( $customer_id !== null && $customer_id > 0 ) {
			return ConditionResult::pass();
		}

		return ConditionResult::fail( 'Customer must be logged in.' );
	}
}
