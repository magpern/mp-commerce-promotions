<?php
/**
 * Demo action: percentage discount preview (no pricing side effects).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class PercentageDiscountAction implements ActionInterface {

	private float $percentage;

	public function __construct( float $percentage ) {
		if ( $percentage <= 0 || $percentage > 100 ) {
			throw new InvalidArgumentException( 'percentage_discount percentage must be > 0 and <= 100.' );
		}
		$this->percentage = $percentage;
	}

	public function get_type(): string {
		return RuleTypes::ACTION_PERCENTAGE_DISCOUNT;
	}

	public function preview( EvaluationContext $context ): ActionResult {
		return new ActionResult(
			$this->get_type(),
			array(
				'percentage' => $this->percentage,
			)
		);
	}
}
