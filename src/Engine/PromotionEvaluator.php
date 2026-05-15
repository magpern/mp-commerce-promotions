<?php
/**
 * Future promotion evaluation pipeline (skeletal).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

use MP\CommercePromotions\Domain\Promotion;

final class PromotionEvaluator {

	/**
	 * Placeholder: will evaluate conditions/actions against cart context.
	 */
	public function evaluate( Promotion $promotion ): bool {
		// Future: pipeline over conditions/actions; $promotion identifies context.
		return false;
	}
}
