<?php
/**
 * Bulk pricing field updates (tier, coupon behavior, allocation mode).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionAllocationMode;
use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Domain\PromotionPriorityTier;
use MP\CommercePromotions\Domain\PromotionRepository;

final class PromotionBulkPricingWorkflow {

	private PromotionRepository $promotions;

	private AuditLogger $audit;

	public function __construct( PromotionRepository $promotions, AuditLogger $audit ) {
		$this->promotions = $promotions;
		$this->audit      = $audit;
	}

	/**
	 * @param list<int> $promotion_ids
	 * @return array{changed: int, skipped: int, errors: list<string>}
	 */
	public function bulk_assign_tier( array $promotion_ids, ?string $tier, ?int $actor_user_id = null ): array {
		return $this->bulk_apply(
			$promotion_ids,
			static fn ( Promotion $p ) => $p->with_pricing_fields( $tier, null, null ),
			array( 'priority_tier' => $tier ),
			$actor_user_id
		);
	}

	/**
	 * @param list<int> $promotion_ids
	 */
	public function bulk_assign_coupon_behavior( array $promotion_ids, ?string $behavior, ?int $actor_user_id = null ): array {
		return $this->bulk_apply(
			$promotion_ids,
			static fn ( Promotion $p ) => $p->with_pricing_fields( null, $behavior, null ),
			array( 'coupon_behavior' => $behavior ),
			$actor_user_id
		);
	}

	/**
	 * @param list<int> $promotion_ids
	 */
	public function bulk_assign_allocation_mode( array $promotion_ids, ?string $mode, ?int $actor_user_id = null ): array {
		return $this->bulk_apply(
			$promotion_ids,
			static fn ( Promotion $p ) => $p->with_pricing_fields( null, null, $mode ),
			array( 'allocation_mode' => $mode ),
			$actor_user_id
		);
	}

	/**
	 * @param list<int>                      $promotion_ids
	 * @param callable(Promotion): Promotion $mutator
	 * @param array<string, mixed>           $audit_meta
	 * @return array{changed: int, skipped: int, errors: list<string>}
	 */
	private function bulk_apply( array $promotion_ids, callable $mutator, array $audit_meta, ?int $actor_user_id ): array {
		$changed = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( $promotion_ids as $promotion_id ) {
			$promotion = $this->promotions->find( $promotion_id );
			if ( $promotion === null ) {
				++$skipped;
				continue;
			}

			try {
				$updated = $mutator( $promotion );
				if ( ! $this->promotions->update( $updated ) ) {
					++$skipped;
					continue;
				}
				$this->audit->log( 'promotion.bulk_updated', $promotion_id, $audit_meta, $actor_user_id );
				++$changed;
			} catch ( \Throwable $e ) {
				$errors[] = $e->getMessage();
				++$skipped;
			}
		}

		return array(
			'changed' => $changed,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}
}
