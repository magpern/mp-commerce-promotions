<?php
/**
 * Diagnostics recovery tools: budget recalc, telemetry rebuild, snapshot validation, orchestration repair.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionSnapshot;
use MP\CommercePromotions\Domain\PromotionSnapshotRepository;
use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Domain\RedemptionRepository;
use RuntimeException;

final class PromotionOperationalRecovery {

	private PromotionRepository $promotions;

	private RedemptionRepository $redemptions;

	private PlannerTelemetryRepository $telemetry;

	private PromotionSnapshotRepository $snapshots;

	private ?AuditLogger $audit;

	public function __construct(
		PromotionRepository $promotions,
		RedemptionRepository $redemptions,
		PlannerTelemetryRepository $telemetry,
		PromotionSnapshotRepository $snapshots,
		?AuditLogger $audit = null
	) {
		$this->promotions  = $promotions;
		$this->redemptions = $redemptions;
		$this->telemetry   = $telemetry;
		$this->snapshots   = $snapshots;
		$this->audit       = $audit;
	}

	/**
	 * @return array{dry_run: bool, changed: list<array{id: int, old_spent: float, new_spent: float}>, skipped: list<array{id: int, reason: string}>, errors: list<array{id: int, message: string}>}
	 */
	public function recalculate_budget_spent_from_redemptions( bool $dry_run = true ): array {
		$result = array(
			'dry_run' => $dry_run,
			'changed' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		$promotions = $this->promotions->find_filtered(
			array(
				'limit' => 500,
			)
		);

		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			if ( ! $promotion->has_budget_cap() ) {
				$result['skipped'][] = array(
					'id'     => $id,
					'reason' => 'no_budget_cap',
				);
				continue;
			}

			try {
				$computed = $this->redemptions->sum_recorded_discount_amount(
					array(
						'promotion_id' => $id,
						'status'       => Redemption::STATUS_RECORDED,
					)
				);
				$computed = max( 0.0, $computed );
				$current  = $promotion->get_budget_spent();

				if ( abs( $current - $computed ) < 0.000001 ) {
					$result['skipped'][] = array(
						'id'     => $id,
						'reason' => 'already_aligned',
					);
					continue;
				}

				if ( ! $dry_run ) {
					$updated = $promotion->with_budget_spent( $computed );
					if ( ! $this->promotions->update( $updated ) ) {
						$result['errors'][] = array(
							'id'      => $id,
							'message' => 'update_failed',
						);
						continue;
					}

					if ( $this->audit !== null ) {
						$this->audit->log(
							'promotion.budget_spent_recalculated',
							$id,
							array(
								'old_spent' => $current,
								'new_spent' => $computed,
							),
							null
						);
					}
				}

				$result['changed'][] = array(
					'id'        => $id,
					'old_spent' => $current,
					'new_spent' => $computed,
				);
			} catch ( RuntimeException $e ) {
				$result['errors'][] = array(
					'id'      => $id,
					'message' => $e->getMessage(),
				);
			}
		}

		return $result;
	}

	/**
	 * @return array{dry_run: bool, promotions_processed: int, rows_written: int}
	 */
	public function rebuild_planner_telemetry_from_redemptions( bool $dry_run = true ): array {
		$aggregates = array();

		$promotions = $this->promotions->find_filtered( array( 'limit' => 500 ) );
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			$count = $this->redemptions->count_recorded_for_promotion( $id );

			if ( $count <= 0 ) {
				continue;
			}

			$aggregates[ $id ] = array(
				'selected' => $count,
				'skipped'  => 0,
			);
		}

		$rows_written = 0;
		if ( ! $dry_run ) {
			$this->telemetry->delete_all();
			foreach ( $aggregates as $promotion_id => $deltas ) {
				$this->telemetry->increment( (int) $promotion_id, $deltas );
				++$rows_written;
			}
		} else {
			$rows_written = count( $aggregates );
		}

		return array(
			'dry_run'              => $dry_run,
			'promotions_processed' => count( $promotions ),
			'rows_written'         => $rows_written,
		);
	}

	/**
	 * @return array{valid: list<int>, invalid: list<array{id: int, reason: string}>}
	 */
	public function validate_promotion_snapshots( int $limit = 500 ): array {
		$valid   = array();
		$invalid = array();

		$promotions = $this->promotions->find_filtered( array( 'limit' => min( 500, max( 1, $limit ) ) ) );
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			$snapshots = $this->snapshots->find_latest_for_promotion( $id, 20 );
			foreach ( $snapshots as $snapshot ) {
				$snapshot_id = $snapshot->get_id();
				if ( $snapshot_id === null || $snapshot_id <= 0 ) {
					continue;
				}

				$check = $this->validate_snapshot_payload( $snapshot );
				if ( $check === true ) {
					$valid[] = $snapshot_id;
				} else {
					$invalid[] = array(
						'id'     => $snapshot_id,
						'reason' => is_string( $check ) ? $check : 'invalid_payload',
					);
				}
			}
		}

		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/**
	 * @return array{dry_run: bool, changed: list<array{id: int, old_group: string|null, new_group: string|null}>, skipped: list<array{id: int, reason: string}>}
	 */
	public function repair_invalid_orchestration_groups( bool $dry_run = true ): array {
		$result = array(
			'dry_run' => $dry_run,
			'changed' => array(),
			'skipped' => array(),
		);

		$promotions = $this->promotions->find_filtered( array( 'limit' => 500 ) );
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			$group = $promotion->get_orchestration_group();
			if ( $group === null || $group === '' ) {
				continue;
			}

			$normalized = Promotion::normalize_orchestration_group( $group );
			if ( $normalized === $group ) {
				$result['skipped'][] = array(
					'id'     => $id,
					'reason' => 'already_normalized',
				);
				continue;
			}

			if ( ! $dry_run ) {
				$updated = $promotion->with_orchestration(
					$promotion->get_cooldown_hours(),
					$normalized
				);
				$this->promotions->update( $updated );
				if ( $this->audit !== null ) {
					$this->audit->log(
						'promotion.orchestration_group_repaired',
						$id,
						array(
							'old_group' => $group,
							'new_group' => $normalized,
						),
						null
					);
				}
			}

			$result['changed'][] = array(
				'id'        => $id,
				'old_group' => $group,
				'new_group' => $normalized,
			);
		}

		return $result;
	}

	/**
	 * @return true|string Error message when invalid.
	 */
	public function validate_snapshot_payload( PromotionSnapshot $snapshot ) {
		$data = $snapshot->get_snapshot_data();
		if ( $data === array() ) {
			return 'empty_payload';
		}

		if ( ! isset( $data['uuid'], $data['name'], $data['status'] ) ) {
			return 'missing_core_fields';
		}

		try {
			Promotion::from_array( $data );
		} catch ( \InvalidArgumentException $e ) {
			return 'promotion_from_array_failed';
		}

		foreach ( array( 'conditions', 'actions', 'restrictions' ) as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				continue;
			}
			if ( ! is_array( $data[ $key ] ) ) {
				return 'invalid_json_array_' . $key;
			}
		}

		return true;
	}
}
