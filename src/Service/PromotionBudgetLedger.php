<?php
/**
 * Adjusts promotion budget_spent from redemption discount amounts.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;

final class PromotionBudgetLedger {

	private PromotionRepository $promotions;

	public function __construct( PromotionRepository $promotions ) {
		$this->promotions = $promotions;
	}

	public function record_redemption_discount( Promotion $promotion, float $discount_amount ): void {
		if ( ! $promotion->has_budget_cap() || $discount_amount <= 0 ) {
			return;
		}

		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return;
		}

		$this->promotions->adjust_budget_spent( $id, $discount_amount );
	}

	public function reverse_redemption_discount( Promotion $promotion, float $discount_amount ): void {
		if ( ! $promotion->has_budget_cap() || $discount_amount <= 0 ) {
			return;
		}

		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return;
		}

		$this->promotions->adjust_budget_spent( $id, -1 * $discount_amount );
	}
}
