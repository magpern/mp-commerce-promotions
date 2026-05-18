<?php
/**
 * Diagnostics recovery for pricing/allocation metadata.
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
use MP\CommercePromotions\Domain\PromotionSnapshotRepository;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Woo\CartSessionHelper;
use MP\CommercePromotions\Woo\LineDiscountFallbackTelemetry;
use MP\CommercePromotions\Woo\LineDiscountPlanCache;
use MP\CommercePromotions\Woo\LinePriceMutationGuard;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;

final class PromotionPricingRecovery {

	private PromotionRepository $promotions;

	private PromotionSnapshotRepository $snapshots;

	public function __construct( PromotionRepository $promotions, PromotionSnapshotRepository $snapshots ) {
		$this->promotions = $promotions;
		$this->snapshots  = $snapshots;
	}

	/**
	 * @return array{dry_run: bool, promotions_processed: int}
	 */
	public function rebuild_allocation_summaries( bool $dry_run = true ): array {
		if ( ! $dry_run ) {
			AllocationContextCache::reset_persisted_metrics();
		}

		return array(
			'dry_run'              => $dry_run,
			'promotions_processed' => count( $this->promotions->find_active( 500 ) ),
		);
	}

	/**
	 * @return array{dry_run: bool, changed: int, skipped: int}
	 */
	public function repair_malformed_coexistence_configs( bool $dry_run = true ): array {
		$changed = 0;
		$skipped = 0;

		foreach ( $this->promotions->find_filtered( array( 'limit' => 500 ) ) as $promotion ) {
			$behavior = $promotion->get_coupon_behavior();
			if ( PromotionCouponBehavior::is_valid( $behavior ) ) {
				++$skipped;
				continue;
			}

			if ( ! $dry_run ) {
				$id = $promotion->get_id();
				if ( $id !== null && $id > 0 ) {
					$this->promotions->update(
						$promotion->with_pricing_fields( null, PromotionCouponBehavior::DEFAULT_BEHAVIOR, null )
					);
				}
			}
			++$changed;
		}

		return array(
			'dry_run' => $dry_run,
			'changed' => $changed,
			'skipped' => $skipped,
		);
	}

	/**
	 * @return array{dry_run: bool, changed: int}
	 */
	public function normalize_invalid_priority_tiers( bool $dry_run = true ): array {
		$changed = 0;

		foreach ( $this->promotions->find_filtered( array( 'limit' => 500 ) ) as $promotion ) {
			$tier = $promotion->get_priority_tier();
			if ( PromotionPriorityTier::is_valid( $tier ) ) {
				continue;
			}

			if ( ! $dry_run ) {
				$id = $promotion->get_id();
				if ( $id !== null && $id > 0 ) {
					$this->promotions->update(
						$promotion->with_pricing_fields( PromotionPriorityTier::DEFAULT_TIER, null, null )
					);
				}
			}
			++$changed;
		}

		return array(
			'dry_run' => $dry_run,
			'changed' => $changed,
		);
	}

	public function recalculate_profitability_metrics(): array {
		delete_option( 'mp_cp_profitability_cache' );
		PricingCompatibilityAnalyzer::reset_cache();

		return array( 'profitability_cache_cleared' => true );
	}

	/**
	 * @return array{valid: int, invalid: list<int>}
	 */
	public function validate_allocation_snapshots(): array {
		$valid   = 0;
		$invalid = array();

		foreach ( $this->promotions->find_filtered( array( 'limit' => 100 ) ) as $promotion ) {
			$pid = $promotion->get_id();
			if ( $pid === null || $pid <= 0 ) {
				continue;
			}
			foreach ( $this->snapshots->find_latest_for_promotion( $pid, 5 ) as $snapshot ) {
				$notes = $snapshot->get_notes();
				if ( $notes === null || trim( $notes ) === '' ) {
					++$valid;
					continue;
				}

				$decoded = json_decode( $notes, true );
				if ( ! is_array( $decoded ) || ! isset( $decoded['mp_cp_intel']['allocation_summary'] ) ) {
					++$valid;
					continue;
				}

				$summary = $decoded['mp_cp_intel']['allocation_summary'];
				if ( ! is_array( $summary ) || ! isset( $summary['total_allocated'] ) ) {
					$id = $snapshot->get_id();
					if ( $id !== null ) {
						$invalid[] = $id;
					}
					continue;
				}

				++$valid;
			}
		}

		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/**
	 * Clear stuck line allocation session/cache (storefront recovery).
	 *
	 * @return array<string, mixed>
	 */
	public function repair_stuck_line_discount_sessions( bool $dry_run = true ): array {
		$would_clear = array(
			'session_keys'    => array(
				CartSessionHelper::LINE_ALLOCATIONS_SESSION_KEY,
				'mp_cp_applied_promotion',
			),
			'cart_item_meta'  => array(
				\MP\CommercePromotions\Engine\AppliedLineDiscount::META_ORIGINAL_PRICE,
				\MP\CommercePromotions\Engine\AppliedLineDiscount::META_MUTATED_BY,
			),
			'in_request_caches' => array(
				'LineDiscountPlanCache',
				'LinePriceMutationGuard',
				'AllocationContextCache (request)',
			),
			'optional_stats'  => array(
				'mp_cp_line_discount_usage_stats (not cleared by default)',
				LineDiscountFallbackTelemetry::OPTION_STATS . ' (not cleared by default)',
			),
		);

		if ( $dry_run ) {
			return array(
				'dry_run'     => true,
				'would_clear' => $would_clear,
			);
		}

		AllocationContextCache::reset_request_cache();
		LineDiscountPlanCache::reset();
		LinePriceMutationGuard::reset_cycle();
		LinePriceMutationGuard::begin_cycle();
		CartSessionHelper::clear_line_allocations();

		if ( function_exists( 'WC' ) && is_object( WC()->cart ?? null ) ) {
			LinePriceMutationGuard::restore_all_line_prices( WC()->cart );
		}

		return array(
			'dry_run'     => false,
			'cleared'     => true,
			'would_clear' => $would_clear,
		);
	}
}
