<?php
/**
 * Condition: customer order count (metadata order_count).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use MP\CommercePromotions\Engine\RuleTypes;

final class CustomerOrderCountCondition extends AbstractCustomerNumericCondition {

	public function __construct( string $operator, float $count ) {
		parent::__construct(
			$operator,
			$count,
			'order_count',
			ConditionTrace::REASON_ORDER_COUNT_NOT_MET
		);
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_CUSTOMER_ORDER_COUNT;
	}
}
