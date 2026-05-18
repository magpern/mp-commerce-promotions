<?php
/**
 * Promotion complexity scoring for scaling diagnostics (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;

final class PromotionComplexityScorer {

	private PromotionRepository $promotions;

	public function __construct( PromotionRepository $promotions ) {
		$this->promotions = $promotions;
	}

	/**
	 * @return list<array{promotion_id: int, name: string, score: int, tier: string, factors: list<string>}>
	 */
	public function score_active_promotions( int $limit = 100 ): array {
		$rows   = array();
		$promos = $this->promotions->find_active_for_planner( $limit );

		foreach ( $promos as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}
			$scored = $this->score_promotion( $promotion );
			$rows[] = array(
				'promotion_id' => $id,
				'name'         => $promotion->get_name(),
				'score'        => $scored['score'],
				'tier'         => $scored['tier'],
				'factors'      => $scored['factors'],
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
			}
		);

		return $rows;
	}

	/**
	 * @return array{score: int, tier: string, factors: list<string>}
	 */
	public function score_promotion( Promotion $promotion ): array {
		$score   = 0;
		$factors = array();

		$conditions = $promotion->get_conditions();
		$actions    = $promotion->get_actions();
		$score     += min( 40, count( $conditions ) * 5 );
		$score     += min( 25, count( $actions ) * 8 );

		if ( count( $conditions ) > 6 ) {
			$factors[] = 'many_conditions';
		}
		if ( count( $actions ) > 2 ) {
			$factors[] = 'many_actions';
		}

		if ( $promotion->get_excluded_promotion_ids() !== array() ) {
			$score    += 10;
			$factors[] = 'exclusions';
		}
		if ( $promotion->get_orchestration_group() !== null && $promotion->get_orchestration_group() !== '' ) {
			$score    += 8;
			$factors[] = 'orchestration_group';
		}
		if ( $promotion->has_budget_cap() ) {
			$score += 5;
		}
		if ( PromotionDiscountApplicationMode::uses_line_mutation( $promotion->get_discount_application_mode() ) ) {
			$score    += 15;
			$factors[] = 'line_item_mode';
		}
		if ( $promotion->get_application_mode() === PromotionApplicationMode::STACKABLE ) {
			$score    += 6;
			$factors[] = 'stackable';
		}

		$score = max( 0, min( 100, $score ) );
		$tier  = 'low';
		if ( $score >= 55 ) {
			$tier = 'high';
		} elseif ( $score >= 30 ) {
			$tier = 'medium';
		}

		return array(
			'score'   => $score,
			'tier'    => $tier,
			'factors' => $factors,
		);
	}

	/**
	 * @return list<array{promotion_id: int, name: string, score: int, tier: string}>
	 */
	public function slow_promotion_candidates( int $threshold = 45, int $limit = 15 ): array {
		$all = $this->score_active_promotions( 200 );

		return array_values(
			array_slice(
				array_filter(
					$all,
					static function ( array $row ) use ( $threshold ): bool {
						return (int) ( $row['score'] ?? 0 ) >= $threshold;
					}
				),
				0,
				$limit
			)
		);
	}
}
