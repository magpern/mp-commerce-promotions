<?php
/**
 * Transient-based locks and concurrency warnings (no PII).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

final class PromotionConcurrencyGuard {

	public const TRANSIENT_PLANNER_LOCK = 'mp_cp_planner_lock';

	public const TRANSIENT_AUTOMATION_LOCK = 'mp_cp_automation_lock';

	public const TRANSIENT_SNAPSHOT_RESTORE_LOCK = 'mp_cp_snapshot_restore_lock';

	public const OPTION_WARNINGS = 'mp_cp_concurrency_warnings';

	private const LOCK_TTL = 30;

	public function acquire_planner_lock(): bool {
		if ( get_transient( self::TRANSIENT_PLANNER_LOCK ) ) {
			$this->record_warning( 'planner_lock_contention', __( 'Planner lock already held; skipped overlapping execution.', 'mp-commerce-promotions' ) );
			return false;
		}

		set_transient( self::TRANSIENT_PLANNER_LOCK, wp_generate_password( 12, false ), self::LOCK_TTL );

		return true;
	}

	public function release_planner_lock(): void {
		delete_transient( self::TRANSIENT_PLANNER_LOCK );
	}

	public function acquire_automation_lock(): bool {
		if ( get_transient( self::TRANSIENT_AUTOMATION_LOCK ) ) {
			$this->record_warning( 'automation_overlap', __( 'Automation runner already in progress.', 'mp-commerce-promotions' ) );
			return false;
		}

		set_transient( self::TRANSIENT_AUTOMATION_LOCK, '1', 120 );

		return true;
	}

	public function release_automation_lock(): void {
		delete_transient( self::TRANSIENT_AUTOMATION_LOCK );
	}

	public function acquire_snapshot_restore_lock(): bool {
		if ( get_transient( self::TRANSIENT_SNAPSHOT_RESTORE_LOCK ) ) {
			$this->record_warning( 'snapshot_restore_overlap', __( 'Snapshot restore already in progress.', 'mp-commerce-promotions' ) );
			return false;
		}

		set_transient( self::TRANSIENT_SNAPSHOT_RESTORE_LOCK, '1', 60 );

		return true;
	}

	public function release_snapshot_restore_lock(): void {
		delete_transient( self::TRANSIENT_SNAPSHOT_RESTORE_LOCK );
	}

	public function is_automation_running(): bool {
		return (bool) get_transient( self::TRANSIENT_AUTOMATION_LOCK );
	}

	public function record_warning( string $code, string $message ): void {
		$warnings = get_option( self::OPTION_WARNINGS, array() );
		if ( ! is_array( $warnings ) ) {
			$warnings = array();
		}

		array_unshift(
			$warnings,
			array(
				'code'        => sanitize_key( $code ),
				'message'     => $message,
				'recorded_at' => gmdate( 'c' ),
			)
		);

		update_option( self::OPTION_WARNINGS, array_slice( $warnings, 0, 50 ), false );
	}

	/**
	 * @return list<array{code: string, message: string, recorded_at: string}>
	 */
	public function get_warnings(): array {
		$warnings = get_option( self::OPTION_WARNINGS, array() );

		return is_array( $warnings ) ? $warnings : array();
	}

	public function clear_warnings(): void {
		delete_option( self::OPTION_WARNINGS );
	}
}
