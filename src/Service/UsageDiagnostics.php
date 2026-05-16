<?php
/**
 * Read-only usage counter diagnostics (stored vs computed from redemptions).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\RedemptionRepository;

final class UsageDiagnostics {

	private const LIST_LIMIT = 100;

	private PromotionRepository $promotions;

	private PromotionCodeRepository $codes;

	private RedemptionRepository $redemptions;

	public function __construct(
		PromotionRepository $promotions,
		PromotionCodeRepository $codes,
		RedemptionRepository $redemptions
	) {
		$this->promotions  = $promotions;
		$this->codes       = $codes;
		$this->redemptions = $redemptions;
	}

	/**
	 * @return array{
	 *   promotions: list<array{
	 *     promotion_id: int,
	 *     name: string,
	 *     stored_usage_count: int,
	 *     computed_recorded_count: int,
	 *     computed_reversed_count: int,
	 *     expected_usage_count: int,
	 *     matches: bool
	 *   }>,
	 *   codes: list<array{
	 *     code_id: int,
	 *     promotion_id: int,
	 *     last4: string,
	 *     stored_usage_count: int,
	 *     expected_usage_count: int,
	 *     matches: bool
	 *   }>
	 * }
	 */
	public function analyze(): array {
		$promotion_rows = array();
		foreach ( $this->promotions->find_all( self::LIST_LIMIT, 0 ) as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			$recorded = $this->redemptions->count_recorded_for_promotion( $id );
			$reversed = $this->redemptions->count_reversed_for_promotion( $id );
			$stored   = $promotion->get_usage_count();

			$promotion_rows[] = array(
				'promotion_id'            => $id,
				'name'                    => $promotion->get_name(),
				'stored_usage_count'      => $stored,
				'computed_recorded_count' => $recorded,
				'computed_reversed_count' => $reversed,
				'expected_usage_count'    => $recorded,
				'matches'                 => $stored === $recorded,
			);
		}

		$code_rows = array();
		foreach ( $this->codes->find_all( self::LIST_LIMIT, 0 ) as $code ) {
			$code_id = $code->get_id();
			if ( $code_id === null || $code_id <= 0 ) {
				continue;
			}

			$stored   = $code->get_usage_count();
			$expected = $this->redemptions->count_recorded_for_promotion_code( $code_id );
			$matches  = $stored === $expected;

			$code_rows[] = array(
				'code_id'              => $code_id,
				'promotion_id'         => $code->get_promotion_id(),
				'last4'                => $code->get_code_last4(),
				'stored_usage_count'   => $stored,
				'expected_usage_count' => $expected,
				'matches'              => $matches,
			);
		}

		return array(
			'promotions' => $promotion_rows,
			'codes'      => $code_rows,
		);
	}
}
