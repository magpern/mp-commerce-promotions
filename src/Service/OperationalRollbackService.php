<?php
/**
 * Operational rollback tooling (snapshots, recent edits, dry-run, emergency).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\AuditLogRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionSnapshotRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use RuntimeException;

final class OperationalRollbackService {

	private PromotionSnapshotService $snapshots;

	private PromotionRepository $promotions;

	private PromotionSnapshotRepository $snapshot_repo;

	private AuditLogRepository $audit_logs;

	private Settings $settings;

	private ?AuditLogger $audit;

	/** @var list<string> */
	private const ROLLBACK_AUDIT_ACTIONS = array(
		'promotion.updated',
		'promotion.status_changed',
		'promotion.bulk_updated',
	);

	public function __construct(
		PromotionSnapshotService $snapshots,
		PromotionRepository $promotions,
		PromotionSnapshotRepository $snapshot_repo,
		AuditLogRepository $audit_logs,
		Settings $settings,
		?AuditLogger $audit = null
	) {
		$this->snapshots      = $snapshots;
		$this->promotions     = $promotions;
		$this->snapshot_repo  = $snapshot_repo;
		$this->audit_logs     = $audit_logs;
		$this->settings       = $settings;
		$this->audit          = $audit;
	}

	/**
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	public function rollback_promotion_to_snapshot( int $snapshot_id, bool $dry_run = true ): array {
		$snapshot = $this->snapshot_repo->find( $snapshot_id );
		$summary  = array(
			'snapshot_id'   => $snapshot_id,
			'promotion_id'  => $snapshot?->get_promotion_id(),
			'restored'      => false,
		);

		if ( $snapshot === null ) {
			$summary['error'] = 'snapshot_not_found';

			return $this->result( 'rollback_promotion_to_snapshot', $dry_run, $summary );
		}

		if ( ! $dry_run ) {
			try {
				$this->snapshots->restore( $snapshot_id, get_current_user_id() );
				$summary['restored'] = true;
			} catch ( RuntimeException $e ) {
				$summary['error'] = $e->getMessage();
			}
		}

		return $this->result( 'rollback_promotion_to_snapshot', $dry_run, $summary );
	}

	/**
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	public function rollback_modified_in_hours( int $hours, bool $dry_run = true ): array {
		$hours   = max( 1, min( 168, $hours ) );
		$since   = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );
		$entries = $this->audit_logs->find_actions_since( self::ROLLBACK_AUDIT_ACTIONS, $since, 500 );
		$ids     = array();
		foreach ( $entries as $entry ) {
			$pid = $entry->get_promotion_id();
			if ( $pid !== null && $pid > 0 ) {
				$ids[ $pid ] = true;
			}
		}

		$restored = array();
		$skipped  = array();
		foreach ( array_keys( $ids ) as $promotion_id ) {
			$latest = $this->snapshot_repo->find_latest_for_promotion( (int) $promotion_id, 1 );
			$snap   = $latest[0] ?? null;
			if ( $snap === null || $snap->get_id() === null ) {
				$skipped[] = $promotion_id;
				continue;
			}
			if ( ! $dry_run ) {
				try {
					$this->snapshots->restore( (int) $snap->get_id(), get_current_user_id() );
					$restored[] = $promotion_id;
				} catch ( RuntimeException $e ) {
					$skipped[] = $promotion_id;
				}
			} else {
				$restored[] = $promotion_id;
			}
		}

		$summary = array(
			'hours'              => $hours,
			'audit_entries'      => count( $entries ),
			'promotion_candidates' => count( $ids ),
			'would_restore'      => $restored,
			'skipped_no_snapshot' => $skipped,
		);

		return $this->result( 'rollback_modified_in_hours', $dry_run, $summary );
	}

	/**
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	public function rollback_dry_run_activations( bool $dry_run = true ): array {
		$global_before = $this->settings->promotion_dry_run_enabled();
		$promotions    = $this->promotions->find_with_dry_run_enabled( 200 );
		$cleared_ids   = array();

		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}
			$cleared_ids[] = $id;
			if ( ! $dry_run ) {
				$this->promotions->update( $promotion->with_dry_run( false ) );
			}
		}

		if ( ! $dry_run && $global_before ) {
			$this->settings->set_promotion_dry_run_enabled( false );
		}

		$summary = array(
			'global_dry_run_before' => $global_before,
			'per_promotion_cleared' => count( $cleared_ids ),
			'promotion_ids'         => $cleared_ids,
		);

		if ( ! $dry_run ) {
			$this->log( 'rollback.dry_run_activations', $summary );
		}

		return $this->result( 'rollback_dry_run_activations', $dry_run, $summary );
	}

	/**
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	public function rollback_emergency_disable_actions( bool $dry_run = true ): array {
		$actions = array(
			'emergency.disable_automatic_promotions',
			'emergency.disable_line_item_mode',
			'emergency.pause_stackable',
		);
		$entries = $this->audit_logs->find_actions_since( $actions, gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) ), 50 );
		$summary = array(
			'entries_found' => count( $entries ),
			'reversals'     => array(),
		);

		foreach ( $entries as $entry ) {
			$action  = $entry->get_action();
			$context = $entry->get_context();
			$row     = array( 'audit_action' => $action );

			if ( $action === 'emergency.disable_automatic_promotions' ) {
				$row['reversal'] = 'clear_safe_mode';
				if ( ! $dry_run ) {
					$this->settings->set_safe_mode_enabled( false );
				}
			} elseif ( $action === 'emergency.disable_line_item_mode' ) {
				$row['reversal'] = 'allow_line_mode';
				if ( ! $dry_run ) {
					$this->settings->set_line_item_mode_disabled( false );
				}
			} elseif ( $action === 'emergency.pause_stackable' ) {
				$ids = is_array( $context['promotion_ids'] ?? null ) ? $context['promotion_ids'] : array();
				$row['reversal'] = 'reactivate_paused_stackable';
				$row['promotion_ids'] = $ids;
				if ( ! $dry_run ) {
					foreach ( $ids as $pid ) {
						$p = $this->promotions->find( (int) $pid );
						if ( $p !== null && $p->get_status() === PromotionStatus::PAUSED ) {
							$this->promotions->update( $p->with_status( PromotionStatus::ACTIVE ) );
						}
					}
				}
			}

			$summary['reversals'][] = $row;
		}

		if ( ! $dry_run && $summary['reversals'] !== array() ) {
			$this->log( 'rollback.emergency_disable', $summary );
		}

		return $this->result( 'rollback_emergency_disable', $dry_run, $summary );
	}

	/**
	 * @param array<string, mixed> $summary
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	private function result( string $action, bool $dry_run, array $summary ): array {
		return array(
			'dry_run' => $dry_run,
			'action'  => $action,
			'summary' => $summary,
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function log( string $event, array $context ): void {
		if ( $this->audit === null ) {
			return;
		}

		$this->audit->log( $event, null, $context, get_current_user_id() );
	}
}
