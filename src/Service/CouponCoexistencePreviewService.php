<?php
/**
 * Coupon coexistence preview for promotion edit (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Woo\CouponCoexistenceEvaluator;

final class CouponCoexistencePreviewService {

	private CouponCoexistenceEvaluator $evaluator;

	private CouponCompatibilityMatrix $matrix;

	public function __construct(
		?CouponCoexistenceEvaluator $evaluator = null,
		?CouponCompatibilityMatrix $matrix = null
	) {
		$this->evaluator = $evaluator ?? new CouponCoexistenceEvaluator();
		$this->matrix    = $matrix ?? new CouponCompatibilityMatrix();
	}

	/**
	 * @return array{
	 *     native: array<string, mixed>,
	 *     promotion_check: array<string, mixed>,
	 *     scenarios: list<array<string, string>>,
	 *     warnings: list<array<string, string>>
	 * }
	 */
	public function preview_for_promotion( Promotion $promotion, ?EvaluationContext $context = null ): array {
		$context = $context ?? new EvaluationContext( null, 100.0, 'USD', array(), array() );
		$native  = $this->evaluator->evaluate_cart();
		$check   = $this->evaluator->evaluate_promotion( $promotion, $context, null );

		return array(
			'native'          => $native,
			'promotion_check' => $check,
			'scenarios'       => $this->matrix->build_scenarios(),
			'warnings'        => $this->matrix->collect_diagnostics_warnings( $promotion ),
		);
	}
}
