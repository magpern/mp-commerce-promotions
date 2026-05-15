<?php
/**
 * Rule action contract (preview only; no mutation).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

use MP\CommercePromotions\Engine\EvaluationContext;

interface ActionInterface {

	public function get_type(): string;

	public function preview( EvaluationContext $context ): ActionResult;
}
