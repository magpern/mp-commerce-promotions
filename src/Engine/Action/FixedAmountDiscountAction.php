<?php
/**
 * Demo action: fixed cart amount discount preview (no pricing side effects).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class FixedAmountDiscountAction implements ActionInterface {

	private float $amount;

	public function __construct( float $amount ) {
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'fixed_amount_discount amount must be > 0.' );
		}
		$this->amount = $amount;
	}

	public function get_type(): string {
		return RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT;
	}

	public function preview( EvaluationContext $context ): ActionResult {
		return new ActionResult(
			$this->get_type(),
			array(
				'amount' => $this->amount,
			)
		);
	}
}
