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

final class MinimumSubtotalCondition implements ConditionInterface {

	private float $amount;

	public function __construct( float $amount ) {
		if ( $amount < 0 ) {
			throw new InvalidArgumentException( 'minimum_subtotal amount must be >= 0.' );
		}
		$this->amount = $amount;
	}

	public function get_type(): string {
		return 'minimum_subtotal';
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$subtotal = $context->get_cart_subtotal();
		if ( $subtotal === null ) {
			return ConditionResult::fail( 'Cart subtotal is not available.' );
		}

		if ( $subtotal < $this->amount ) {
			return ConditionResult::fail(
				sprintf(
					'Cart subtotal %.4f is below required minimum %.4f.',
					$subtotal,
					$this->amount
				)
			);
		}

		return ConditionResult::pass();
	}
}
