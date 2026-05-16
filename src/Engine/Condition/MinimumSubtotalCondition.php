<?php
/**
 * Demo condition: cart subtotal must meet a minimum (generic context only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class MinimumSubtotalCondition implements ConditionInterface {

	private float $amount;

	public function __construct( float $amount ) {
		if ( $amount < 0 ) {
			throw new InvalidArgumentException( 'minimum_subtotal amount must be >= 0.' );
		}
		$this->amount = $amount;
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_MINIMUM_SUBTOTAL;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$subtotal = $context->get_cart_subtotal();
		if ( $subtotal === null ) {
			return ConditionResult::fail(
				'Cart subtotal is not available.',
				ConditionTrace::REASON_METADATA_MISSING,
				array( 'cart_subtotal' => null )
			);
		}

		if ( $subtotal < $this->amount ) {
			return ConditionResult::fail(
				sprintf(
					'Cart subtotal %.4f is below required minimum %.4f.',
					$subtotal,
					$this->amount
				),
				ConditionTrace::REASON_CART_VALUE_TOO_LOW,
				array(
					'cart_subtotal'    => $subtotal,
					'required_minimum' => $this->amount,
				)
			);
		}

		return ConditionResult::pass(
			null,
			ConditionTrace::REASON_PASSED,
			array(
				'cart_subtotal'    => $subtotal,
				'required_minimum' => $this->amount,
			)
		);
	}
}
