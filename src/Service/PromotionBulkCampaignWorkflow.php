<?php
/**
 * Bulk campaign field updates for merchant workflow acceleration.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use RuntimeException;

final class PromotionBulkCampaignWorkflow {

	private PromotionRepository $promotions;

	private AuditLogger $audit;

	public function __construct( PromotionRepository $promotions, AuditLogger $audit ) {
		$this->promotions = $promotions;
		$this->audit       = $audit;
	}

	/**
	 * @param list<int> $promotion_ids
	 * @return array{changed: int, skipped: int, errors: list<string>}
	 */
	public function bulk_update_schedule( array $promotion_ids, ?string $starts_at, ?string $ends_at, ?int $actor_user_id = null ): array {
		return $this->bulk_apply(
			$promotion_ids,
			static function ( Promotion $p ) use ( $starts_at, $ends_at ): Promotion {
				return $p->with_date_window(
					$starts_at ?? $p->get_starts_at(),
					$ends_at ?? $p->get_ends_at()
				);
			},
			array( 'starts_at' => $starts_at, 'ends_at' => $ends_at ),
			$actor_user_id
		);
	}

	/**
	 * @param list<int> $promotion_ids
	 */
	public function bulk_assign_orchestration( array $promotion_ids, ?string $group, ?int $cooldown_hours, ?int $actor_user_id = null ): array {
		return $this->bulk_apply(
			$promotion_ids,
			static function ( Promotion $p ) use ( $group, $cooldown_hours ): Promotion {
				return $p->with_orchestration( $cooldown_hours ?? $p->get_cooldown_hours(), $group );
			},
			array( 'orchestration_group' => $group, 'cooldown_hours' => $cooldown_hours ),
			$actor_user_id
		);
	}

	/**
	 * @param list<int> $promotion_ids
	 */
	public function bulk_assign_campaign_label( array $promotion_ids, ?string $label, ?int $actor_user_id = null ): array {
		return $this->bulk_apply(
			$promotion_ids,
			static function ( Promotion $p ) use ( $label ): Promotion {
				return $p->with_campaign_metadata( $label, $p->get_internal_notes(), $p->get_admin_color() );
			},
			array( 'campaign_label' => $label ),
			$actor_user_id
		);
	}

	/**
	 * @param list<int> $promotion_ids
	 */
	public function bulk_adjust_budget( array $promotion_ids, ?float $amount, ?string $currency, ?int $actor_user_id = null ): array {
		return $this->bulk_apply(
			$promotion_ids,
			static function ( Promotion $p ) use ( $amount, $currency ): Promotion {
				return $p->with_budget( $amount, $p->get_budget_spent(), $currency ?? $p->get_budget_currency() );
			},
			array( 'budget_amount' => $amount, 'budget_currency' => $currency ),
			$actor_user_id
		);
	}

	/**
	 * @param list<int> $promotion_ids
	 */
	public function bulk_assign_cooldown( array $promotion_ids, ?int $cooldown_hours, ?int $actor_user_id = null ): array {
		return $this->bulk_apply(
			$promotion_ids,
			static function ( Promotion $p ) use ( $cooldown_hours ): Promotion {
				return $p->with_orchestration( $cooldown_hours, $p->get_orchestration_group() );
			},
			array( 'cooldown_hours' => $cooldown_hours ),
			$actor_user_id
		);
	}

	/**
	 * @param list<int>               $promotion_ids
	 * @param callable(Promotion): Promotion $mutator
	 * @param array<string, mixed>    $audit_payload
	 * @return array{changed: int, skipped: int, errors: list<string>}
	 */
	private function bulk_apply( array $promotion_ids, callable $mutator, array $audit_payload, ?int $actor_user_id ): array {
		$result = array( 'changed' => 0, 'skipped' => 0, 'errors' => array() );

		foreach ( $promotion_ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( $id <= 0 ) {
				++$result['skipped'];
				continue;
			}

			$promotion = $this->promotions->find( $id );
			if ( $promotion === null ) {
				++$result['skipped'];
				continue;
			}

			try {
				$updated = $mutator( $promotion );
				if ( ! $this->promotions->update( $updated ) ) {
					$result['errors'][] = 'update_failed:' . $id;
					continue;
				}
				++$result['changed'];
				$this->audit->log(
					'promotion.bulk_updated',
					$id,
					array_merge( $audit_payload, array( 'promotion_id' => $id ) ),
					$actor_user_id
				);
			} catch ( RuntimeException $e ) {
				$result['errors'][] = $e->getMessage();
			}
		}

		return $result;
	}
}
