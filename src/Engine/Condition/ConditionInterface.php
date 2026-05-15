<?php
/**
 * Rule condition contract.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use MP\CommercePromotions\Engine\EvaluationContext;

interface ConditionInterface {

	public function get_type(): string;

	public function evaluate( EvaluationContext $context ): ConditionResult;
}
