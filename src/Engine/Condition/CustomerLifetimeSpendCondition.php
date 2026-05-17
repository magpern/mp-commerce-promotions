<?php
/**
 * Condition: customer lifetime spend from Woo order totals (metadata lifetime_spend).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use MP\CommercePromotions\Engine\RuleTypes;

final class CustomerLifetimeSpendCondition extends AbstractCustomerNumericCondition {

	public function __construct( string $operator, float $amount ) {
		parent::__construct(
			$operator,
			$amount,
			'lifetime_spend',
			ConditionTrace::REASON_LIFETIME_SPEND_TOO_LOW
		);
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_CUSTOMER_LIFETIME_SPEND;
	}
}
