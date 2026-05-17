<?php
/**
 * Usage counter diagnostics and manual repair (stored vs redemption-derived counts).
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

	private const ACTION_PROMOTION_USAGE_REPAIRED = 'promotion.usage_repaired';

	private const ACTION_CODE_USAGE_REPAIRED = 'promotion_code.usage_repaired';

	private PromotionRepository $promotions;

	private PromotionCodeRepository $codes;

	private RedemptionRepository $redemptions;

	private ?AuditLogger $audit_logger;

	public function __construct(
		PromotionRepository $promotions,
		PromotionCodeRepository $codes,
		RedemptionRepository $redemptions,
		?AuditLogger $audit_logger = null
	) {
		$this->promotions   = $promotions;
		$this->codes        = $codes;
		$this->redemptions  = $redemptions;
		$this->audit_logger = $audit_logger;
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

	/**
	 * @return array{
	 *   promotions_repaired: int,
	 *   codes_repaired: int,
	 *   errors: list<string>
	 * }
	 */
	public function repair(): array {
		$report              = $this->analyze();
		$promotions_repaired = 0;
		$codes_repaired      = 0;
		$errors              = array();

		foreach ( $report['promotions'] as $row ) {
			if ( ! empty( $row['matches'] ) ) {
				continue;
			}

			$id       = (int) $row['promotion_id'];
			$expected = (int) $row['expected_usage_count'];
			$old      = (int) $row['stored_usage_count'];

			$promotion = $this->promotions->find( $id );
			if ( $promotion === null ) {
				$errors[] = sprintf( 'Promotion %d not found.', $id );
				continue;
			}

			if ( ! $this->promotions->update( $promotion->with_usage_count( $expected ) ) ) {
				$errors[] = sprintf( 'Failed to update promotion %d usage_count.', $id );
				continue;
			}

			++$promotions_repaired;

			if ( $this->audit_logger !== null ) {
				$this->audit_logger->log(
					self::ACTION_PROMOTION_USAGE_REPAIRED,
					$id,
					array(
						'old_usage_count' => $old,
						'new_usage_count' => $expected,
					)
				);
			}
		}

		foreach ( $report['codes'] as $row ) {
			if ( ! empty( $row['matches'] ) ) {
				continue;
			}

			$code_id      = (int) $row['code_id'];
			$promotion_id = (int) $row['promotion_id'];
			$expected     = (int) $row['expected_usage_count'];
			$old          = (int) $row['stored_usage_count'];

			$code = $this->codes->find( $code_id );
			if ( $code === null ) {
				$errors[] = sprintf( 'Promotion code %d not found.', $code_id );
				continue;
			}

			if ( ! $this->codes->update( $code->with_usage_count( $expected ) ) ) {
				$errors[] = sprintf( 'Failed to update promotion code %d usage_count.', $code_id );
				continue;
			}

			++$codes_repaired;

			if ( $this->audit_logger !== null ) {
				$this->audit_logger->log(
					self::ACTION_CODE_USAGE_REPAIRED,
					$promotion_id,
					array(
						'promotion_code_id' => $code_id,
						'promotion_id'      => $promotion_id,
						'old_usage_count'   => $old,
						'new_usage_count'   => $expected,
					)
				);
			}
		}

		return array(
			'promotions_repaired' => $promotions_repaired,
			'codes_repaired'      => $codes_repaired,
			'errors'              => $errors,
		);
	}
}
