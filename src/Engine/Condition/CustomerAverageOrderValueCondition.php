<?php
/**
 * Condition: customer average order value (metadata average_order_value).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use MP\CommercePromotions\Engine\RuleTypes;

final class CustomerAverageOrderValueCondition extends AbstractCustomerNumericCondition {

	public function __construct( string $operator, float $amount ) {
		parent::__construct(
			$operator,
			$amount,
			'average_order_value',
			ConditionTrace::REASON_AVERAGE_ORDER_VALUE_NOT_MET
		);
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_CUSTOMER_AVERAGE_ORDER_VALUE;
	}
}
