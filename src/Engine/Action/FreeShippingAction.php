<?php
/**
 * Action: free shipping preview (storefront applies a negative fee offsetting shipping).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class FreeShippingAction implements ActionInterface {

	public function get_type(): string {
		return RuleTypes::ACTION_FREE_SHIPPING;
	}

	public function preview( EvaluationContext $context ): ActionResult {
		return new ActionResult(
			$this->get_type(),
			array(
				'free_shipping' => true,
			)
		);
	}
}
