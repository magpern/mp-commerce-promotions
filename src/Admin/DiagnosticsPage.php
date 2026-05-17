<?php
/**
 * WooCommerce admin: promotion usage diagnostics and manual repair.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\AutomationRun;
use MP\CommercePromotions\Domain\AutomationRunRepository;
use MP\CommercePromotions\Service\PromotionAutomationRunner;
use MP\CommercePromotions\Service\PromotionHealthMonitor;
use MP\CommercePromotions\Service\PromotionOperationalRecovery;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Service\UsageDiagnostics;

final class DiagnosticsPage {

	private const NONCE_ACTION = 'mp_cp_repair_usage_counters';

	private const NONCE_FIELD = 'mp_cp_repair_usage_nonce';

	private const REPAIR_SUBMIT = 'mp_cp_repair_usage_submit';

	private const ARCHIVE_EXPIRED_NONCE_ACTION = 'mp_cp_archive_expired_active';

	private const ARCHIVE_EXPIRED_NONCE_FIELD = 'mp_cp_archive_expired_nonce';

	private const ARCHIVE_EXPIRED_SUBMIT = 'mp_cp_archive_expired_submit';

	private const ARCHIVE_DRAFTS_NONCE_ACTION = 'mp_cp_archive_old_drafts';

	private const ARCHIVE_DRAFTS_NONCE_FIELD = 'mp_cp_archive_old_drafts_nonce';

	private const ARCHIVE_DRAFTS_SUBMIT = 'mp_cp_archive_old_drafts_submit';

	private const PAUSE_EXHAUSTED_NONCE_ACTION = 'mp_cp_pause_budget_exhausted';

	private const PAUSE_EXHAUSTED_NONCE_FIELD = 'mp_cp_pause_budget_exhausted_nonce';

	private const PAUSE_EXHAUSTED_SUBMIT = 'mp_cp_pause_budget_exhausted_submit';

	private const ACTIVATE_SCHEDULED_NONCE_ACTION = 'mp_cp_activate_scheduled';

	private const ACTIVATE_SCHEDULED_NONCE_FIELD = 'mp_cp_activate_scheduled_nonce';

	private const ACTIVATE_SCHEDULED_SUBMIT = 'mp_cp_activate_scheduled_submit';

	private const ARCHIVE_EXPIRED_PAUSED_NONCE_ACTION = 'mp_cp_archive_expired_paused';

	private const ARCHIVE_EXPIRED_PAUSED_NONCE_FIELD = 'mp_cp_archive_expired_paused_nonce';

	private const ARCHIVE_EXPIRED_PAUSED_SUBMIT = 'mp_cp_archive_expired_paused_submit';

	private const NORMALIZE_STATES_NONCE_ACTION = 'mp_cp_normalize_promotion_states';

	private const NORMALIZE_STATES_NONCE_FIELD = 'mp_cp_normalize_promotion_states_nonce';

	private const NORMALIZE_STATES_SUBMIT = 'mp_cp_normalize_promotion_states_submit';

	private const RUN_ALL_AUTOMATION_NONCE_ACTION = 'mp_cp_run_all_automation';

	private const RUN_ALL_AUTOMATION_NONCE_FIELD = 'mp_cp_run_all_automation_nonce';

	private const RUN_ALL_AUTOMATION_SUBMIT = 'mp_cp_run_all_automation_submit';

	private const RECOVERY_BUDGET_NONCE_ACTION = 'mp_cp_recalc_budget_spent';

	private const RECOVERY_BUDGET_NONCE_FIELD = 'mp_cp_recalc_budget_spent_nonce';

	private const RECOVERY_BUDGET_SUBMIT = 'mp_cp_recalc_budget_spent_submit';

	private const RECOVERY_TELEMETRY_NONCE_ACTION = 'mp_cp_rebuild_planner_telemetry';

	private const RECOVERY_TELEMETRY_NONCE_FIELD = 'mp_cp_rebuild_planner_telemetry_nonce';

	private const RECOVERY_TELEMETRY_SUBMIT = 'mp_cp_rebuild_planner_telemetry_submit';

	private const RECOVERY_SNAPSHOTS_NONCE_ACTION = 'mp_cp_validate_snapshots';

	private const RECOVERY_SNAPSHOTS_NONCE_FIELD = 'mp_cp_validate_snapshots_nonce';

	private const RECOVERY_SNAPSHOTS_SUBMIT = 'mp_cp_validate_snapshots_submit';

	private const RECOVERY_ORCH_NONCE_ACTION = 'mp_cp_repair_orchestration_groups';

	private const RECOVERY_ORCH_NONCE_FIELD = 'mp_cp_repair_orchestration_groups_nonce';

	private const RECOVERY_ORCH_SUBMIT = 'mp_cp_repair_orchestration_groups_submit';

	private UsageDiagnostics $diagnostics;

	private ?PromotionService $promotion_service;

	private ?PromotionAutomationRunner $automation_runner;

	private ?PromotionHealthMonitor $health_monitor;

	private ?PromotionOperationalRecovery $operational_recovery;

	private ?AutomationRunRepository $automation_runs;

	public function __construct(
		UsageDiagnostics $diagnostics,
		?PromotionService $promotion_service = null,
		?PromotionAutomationRunner $automation_runner = null,
		?PromotionHealthMonitor $health_monitor = null,
		?PromotionOperationalRecovery $operational_recovery = null,
		?AutomationRunRepository $automation_runs = null
	) {
		$this->diagnostics           = $diagnostics;
		$this->promotion_service     = $promotion_service;
		$this->automation_runner     = $automation_runner;
		$this->health_monitor        = $health_monitor;
		$this->operational_recovery  = $operational_recovery;
		$this->automation_runs       = $automation_runs;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->handle_post_repair();
		$this->handle_post_archive_hygiene();
		$this->handle_post_automation();
		$this->handle_post_operational_recovery();

		$report = $this->diagnostics->analyze();

		echo '<div class="wrap">';
		$this->render_notices();
		echo '<h1>' . esc_html__( 'Promotion Diagnostics', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_DIAGNOSTICS );
		echo '<p>' . esc_html__( 'Compare stored usage_count values against redemption and order-meta records. Use the repair action to recalculate mismatched counters from recorded redemptions.', 'mp-commerce-promotions' ) . '</p>';

		$this->render_repair_form();
		$this->render_automation_runner_section();
		$this->render_promotion_health_section();
		$this->render_operational_recovery_section();
		$this->render_automation_history_section();
		$this->render_archive_hygiene_section();
		$this->render_scheduler_automation_section();
		$this->render_integrity_notes();
		$this->render_promotions_table( $report['promotions'] );
		$this->render_codes_table( $report['codes'] );

		echo '</div>';
	}

	private function render_integrity_notes(): void {
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Promotion integrity notes', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:1.5em;max-width:720px;">';
		echo '<li>' . esc_html__( 'Checkout recording is idempotent per order and promotion (unique redemption rows; duplicate checkout hooks do not double usage).', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Order cancellation, failure, refund, and trash/delete reverse recorded redemptions once per promotion; repeated reversal hooks are ignored.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Orders that return to processing or completed after reversal restore reversed redemption rows when applicable.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Free gift cart lines marked mp_cp_free_gift=yes are synchronized on each totals pass (stale gifts removed, quantities normalized).', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Stacked promotions record separate redemption rows and applied-promotion meta entries.', 'mp-commerce-promotions' ) . '</li>';
		echo '</ul>';
	}

	private function render_repair_form(): void {
		$confirm = esc_js(
			__( 'Repair usage counters for all mismatches shown below?', 'mp-commerce-promotions' )
		);

		echo '<form method="post" action="" style="margin:1em 0;">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<p class="description">';
		echo esc_html__(
			'This recalculates stored usage counters from recorded redemptions. No rows are deleted.',
			'mp-commerce-promotions'
		);
		echo '</p>';
		echo '<p class="submit">';
		printf(
			'<button type="submit" name="%1$s" value="1" class="button button-secondary" onclick="return confirm(\'%2$s\');">%3$s</button>',
			esc_attr( self::REPAIR_SUBMIT ),
			$confirm,
			esc_html__( 'Repair Usage Counters', 'mp-commerce-promotions' )
		);
		echo '</p>';
		echo '</form>';
	}

	private function render_archive_hygiene_section(): void {
		if ( $this->promotion_service === null ) {
			return;
		}

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Archive hygiene', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Safe maintenance: moves promotions to archived status. Nothing is deleted.',
			'mp-commerce-promotions'
		) . '</p>';

		$confirm_expired = esc_js( __( 'Archive all active promotions whose end date is in the past?', 'mp-commerce-promotions' ) );
		echo '<form method="post" action="" style="margin:0 0 1em;">';
		wp_nonce_field( self::ARCHIVE_EXPIRED_NONCE_ACTION, self::ARCHIVE_EXPIRED_NONCE_FIELD );
		echo '<p><button type="submit" name="' . esc_attr( self::ARCHIVE_EXPIRED_SUBMIT ) . '" value="1" class="button button-secondary" onclick="return confirm(\'' . $confirm_expired . '\');">';
		echo esc_html__( 'Archive expired active promotions', 'mp-commerce-promotions' );
		echo '</button></p>';
		echo '<p class="description">' . esc_html__( 'Active promotions with ends_at before now are archived (audited).', 'mp-commerce-promotions' ) . '</p>';
		echo '</form>';

		$confirm_drafts = esc_js( __( 'Archive old draft promotions?', 'mp-commerce-promotions' ) );
		echo '<form method="post" action="" style="margin:0 0 1em;">';
		wp_nonce_field( self::ARCHIVE_DRAFTS_NONCE_ACTION, self::ARCHIVE_DRAFTS_NONCE_FIELD );
		echo '<p><label for="mp_cp_archive_draft_days">' . esc_html__( 'Draft age (days)', 'mp-commerce-promotions' ) . '</label> ';
		echo '<input type="number" class="small-text" id="mp_cp_archive_draft_days" name="mp_cp_archive_draft_days" min="1" max="3650" value="90" /> ';
		echo '<button type="submit" name="' . esc_attr( self::ARCHIVE_DRAFTS_SUBMIT ) . '" value="1" class="button button-secondary" onclick="return confirm(\'' . $confirm_drafts . '\');">';
		echo esc_html__( 'Archive old drafts', 'mp-commerce-promotions' );
		echo '</button></p>';
		echo '<p class="description">' . esc_html__( 'Draft promotions created before the cutoff are archived (audited).', 'mp-commerce-promotions' ) . '</p>';
		echo '</form>';

		$confirm_exhausted = esc_js( __( 'Pause all active promotions whose budget cap is exhausted?', 'mp-commerce-promotions' ) );
		echo '<form method="post" action="" style="margin:0 0 1em;">';
		wp_nonce_field( self::PAUSE_EXHAUSTED_NONCE_ACTION, self::PAUSE_EXHAUSTED_NONCE_FIELD );
		echo '<p><button type="submit" name="' . esc_attr( self::PAUSE_EXHAUSTED_SUBMIT ) . '" value="1" class="button button-secondary" onclick="return confirm(\'' . $confirm_exhausted . '\');">';
		echo esc_html__( 'Deactivate exhausted promotions', 'mp-commerce-promotions' );
		echo '</button></p>';
		echo '<p class="description">' . esc_html__( 'Sets active promotions with budget_spent >= budget_amount to paused (audited).', 'mp-commerce-promotions' ) . '</p>';
		echo '</form>';
	}

	private function render_scheduler_automation_section(): void {
		if ( $this->promotion_service === null ) {
			return;
		}

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Scheduler automation', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Manual triggers for promotion lifecycle automation (same operations intended for cron). All actions are audited.',
			'mp-commerce-promotions'
		) . '</p>';

		$confirm_activate = esc_js( __( 'Activate all scheduled draft promotions whose start date has passed?', 'mp-commerce-promotions' ) );
		echo '<form method="post" action="" style="margin:0 0 1em;">';
		wp_nonce_field( self::ACTIVATE_SCHEDULED_NONCE_ACTION, self::ACTIVATE_SCHEDULED_NONCE_FIELD );
		echo '<p><button type="submit" name="' . esc_attr( self::ACTIVATE_SCHEDULED_SUBMIT ) . '" value="1" class="button button-secondary" onclick="return confirm(\'' . $confirm_activate . '\');">';
		echo esc_html__( 'Activate scheduled promotions', 'mp-commerce-promotions' );
		echo '</button></p>';
		echo '<p class="description">' . esc_html__( 'Draft promotions with starts_at in the past move to active.', 'mp-commerce-promotions' ) . '</p>';
		echo '</form>';

		$confirm_archive_paused = esc_js( __( 'Archive all paused promotions past their end date?', 'mp-commerce-promotions' ) );
		echo '<form method="post" action="" style="margin:0 0 1em;">';
		wp_nonce_field( self::ARCHIVE_EXPIRED_PAUSED_NONCE_ACTION, self::ARCHIVE_EXPIRED_PAUSED_NONCE_FIELD );
		echo '<p><button type="submit" name="' . esc_attr( self::ARCHIVE_EXPIRED_PAUSED_SUBMIT ) . '" value="1" class="button button-secondary" onclick="return confirm(\'' . $confirm_archive_paused . '\');">';
		echo esc_html__( 'Archive expired paused promotions', 'mp-commerce-promotions' );
		echo '</button></p>';
		echo '<p class="description">' . esc_html__( 'Paused promotions with ends_at before now are archived.', 'mp-commerce-promotions' ) . '</p>';
		echo '</form>';

		$confirm_normalize = esc_js( __( 'Normalize invalid promotion states (pause expired active, flag archived with future start)?', 'mp-commerce-promotions' ) );
		echo '<form method="post" action="" style="margin:0 0 1em;">';
		wp_nonce_field( self::NORMALIZE_STATES_NONCE_ACTION, self::NORMALIZE_STATES_NONCE_FIELD );
		echo '<p><button type="submit" name="' . esc_attr( self::NORMALIZE_STATES_SUBMIT ) . '" value="1" class="button button-secondary" onclick="return confirm(\'' . $confirm_normalize . '\');">';
		echo esc_html__( 'Normalize promotion states', 'mp-commerce-promotions' );
		echo '</button></p>';
		echo '<p class="description">' . esc_html__( 'Pauses active promotions past end date; records warnings for archived promotions with future starts_at.', 'mp-commerce-promotions' ) . '</p>';
		echo '</form>';
	}

	private function handle_post_archive_hygiene(): void {
		if ( $this->promotion_service === null || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		$actor = (int) get_current_user_id();

		if ( isset( $_POST[ self::ARCHIVE_EXPIRED_SUBMIT ] ) ) {
			if ( ! isset( $_POST[ self::ARCHIVE_EXPIRED_NONCE_FIELD ] )
				|| ! wp_verify_nonce(
					sanitize_text_field( wp_unslash( (string) $_POST[ self::ARCHIVE_EXPIRED_NONCE_FIELD ] ) ),
					self::ARCHIVE_EXPIRED_NONCE_ACTION
				) ) {
				$this->redirect_with_notice( 'error', 'invalid_nonce' );
			}

			$result = $this->promotion_service->archive_expired_active_promotions( $actor );
			$this->redirect_with_notice(
				'success',
				'archive_hygiene_done',
				array(
					'promotions' => count( $result['changed'] ),
					'codes'      => count( $result['skipped'] ),
					'errors'     => count( $result['errors'] ),
				)
			);
		}

		if ( isset( $_POST[ self::ARCHIVE_DRAFTS_SUBMIT ] ) ) {
			if ( ! isset( $_POST[ self::ARCHIVE_DRAFTS_NONCE_FIELD ] )
				|| ! wp_verify_nonce(
					sanitize_text_field( wp_unslash( (string) $_POST[ self::ARCHIVE_DRAFTS_NONCE_FIELD ] ) ),
					self::ARCHIVE_DRAFTS_NONCE_ACTION
				) ) {
				$this->redirect_with_notice( 'error', 'invalid_nonce' );
			}

			$days = isset( $_POST['mp_cp_archive_draft_days'] ) ? (int) $_POST['mp_cp_archive_draft_days'] : 90;
			if ( $days < 1 ) {
				$days = 90;
			}

			$result = $this->promotion_service->archive_old_drafts( $days, $actor );
			$this->redirect_with_notice(
				'success',
				'archive_hygiene_done',
				array(
					'promotions' => count( $result['changed'] ),
					'codes'      => count( $result['skipped'] ),
					'errors'     => count( $result['errors'] ),
				)
			);
		}

		if ( isset( $_POST[ self::PAUSE_EXHAUSTED_SUBMIT ] ) ) {
			if ( ! isset( $_POST[ self::PAUSE_EXHAUSTED_NONCE_FIELD ] )
				|| ! wp_verify_nonce(
					sanitize_text_field( wp_unslash( (string) $_POST[ self::PAUSE_EXHAUSTED_NONCE_FIELD ] ) ),
					self::PAUSE_EXHAUSTED_NONCE_ACTION
				) ) {
				$this->redirect_with_notice( 'error', 'invalid_nonce' );
			}

			$result = $this->promotion_service->pause_budget_exhausted_promotions( $actor );
			$this->redirect_with_notice(
				'success',
				'pause_exhausted_done',
				array(
					'promotions' => count( $result['changed'] ),
					'codes'      => count( $result['skipped'] ),
					'errors'     => count( $result['errors'] ),
				)
			);
		}

		if ( isset( $_POST[ self::ACTIVATE_SCHEDULED_SUBMIT ] ) ) {
			if ( ! isset( $_POST[ self::ACTIVATE_SCHEDULED_NONCE_FIELD ] )
				|| ! wp_verify_nonce(
					sanitize_text_field( wp_unslash( (string) $_POST[ self::ACTIVATE_SCHEDULED_NONCE_FIELD ] ) ),
					self::ACTIVATE_SCHEDULED_NONCE_ACTION
				) ) {
				$this->redirect_with_notice( 'error', 'invalid_nonce' );
			}

			$result = $this->promotion_service->activate_scheduled_promotions( $actor );
			$this->redirect_with_notice(
				'success',
				'scheduler_automation_done',
				array(
					'promotions' => count( $result['changed'] ),
					'codes'      => count( $result['skipped'] ),
					'errors'     => count( $result['errors'] ),
				)
			);
		}

		if ( isset( $_POST[ self::ARCHIVE_EXPIRED_PAUSED_SUBMIT ] ) ) {
			if ( ! isset( $_POST[ self::ARCHIVE_EXPIRED_PAUSED_NONCE_FIELD ] )
				|| ! wp_verify_nonce(
					sanitize_text_field( wp_unslash( (string) $_POST[ self::ARCHIVE_EXPIRED_PAUSED_NONCE_FIELD ] ) ),
					self::ARCHIVE_EXPIRED_PAUSED_NONCE_ACTION
				) ) {
				$this->redirect_with_notice( 'error', 'invalid_nonce' );
			}

			$result = $this->promotion_service->archive_expired_paused_promotions( $actor );
			$this->redirect_with_notice(
				'success',
				'scheduler_automation_done',
				array(
					'promotions' => count( $result['changed'] ),
					'codes'      => count( $result['skipped'] ),
					'errors'     => count( $result['errors'] ),
				)
			);
		}

		if ( isset( $_POST[ self::NORMALIZE_STATES_SUBMIT ] ) ) {
			if ( ! isset( $_POST[ self::NORMALIZE_STATES_NONCE_FIELD ] )
				|| ! wp_verify_nonce(
					sanitize_text_field( wp_unslash( (string) $_POST[ self::NORMALIZE_STATES_NONCE_FIELD ] ) ),
					self::NORMALIZE_STATES_NONCE_ACTION
				) ) {
				$this->redirect_with_notice( 'error', 'invalid_nonce' );
			}

			$result = $this->promotion_service->normalize_invalid_promotion_states( $actor );
			$this->redirect_with_notice(
				'success',
				'scheduler_normalize_done',
				array(
					'promotions' => count( $result['changed'] ),
					'codes'      => count( $result['warnings'] ),
					'errors'     => count( $result['errors'] ),
				)
			);
		}
	}

	private function handle_post_repair(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST[ self::REPAIR_SUBMIT ] ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			$this->redirect_with_notice( 'error', 'missing_nonce' );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->redirect_with_notice( 'error', 'invalid_nonce' );
		}

		$result = $this->diagnostics->repair();

		if ( count( $result['errors'] ) > 0 ) {
			$this->redirect_with_notice(
				'error',
				'repair_partial',
				array(
					'promotions' => (int) $result['promotions_repaired'],
					'codes'      => (int) $result['codes_repaired'],
				)
			);
		}

		if ( $result['promotions_repaired'] === 0 && $result['codes_repaired'] === 0 ) {
			$this->redirect_with_notice( 'success', 'repair_none' );
		}

		$this->redirect_with_notice(
			'success',
			'repair_done',
			array(
				'promotions' => (int) $result['promotions_repaired'],
				'codes'      => (int) $result['codes_repaired'],
			)
		);
	}

	private function render_notices(): void {
		if ( ! isset( $_GET['mp_cp_diag_notice'] ) || ! isset( $_GET['mp_cp_diag_code'] ) ) {
			return;
		}

		$type = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_diag_notice'] ) );
		$code = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_diag_code'] ) );

		$promotions = isset( $_GET['mp_cp_diag_promotions'] )
			? (int) $_GET['mp_cp_diag_promotions']
			: 0;
		$codes      = isset( $_GET['mp_cp_diag_codes'] )
			? (int) $_GET['mp_cp_diag_codes']
			: 0;
		$errors     = isset( $_GET['mp_cp_diag_errors'] )
			? (int) $_GET['mp_cp_diag_errors']
			: 0;

		$message = $this->notice_message_for_code( $code, $promotions, $codes, $errors );
		if ( $message === '' ) {
			return;
		}

		if ( $type === 'success' ) {
			AdminNotice::success( $message );
			return;
		}

		AdminNotice::error( $message );
	}

	private function notice_message_for_code( string $code, int $promotions, int $codes, int $errors = 0 ): string {
		switch ( $code ) {
			case 'archive_hygiene_done':
				return sprintf(
					/* translators: 1: archived count, 2: skipped count, 3: error count */
					__( 'Archive hygiene: %1$d archived, %2$d skipped, %3$d errors.', 'mp-commerce-promotions' ),
					$promotions,
					$codes,
					$errors
				);
			case 'pause_exhausted_done':
				return sprintf(
					/* translators: 1: paused count, 2: skipped count, 3: error count */
					__( 'Budget exhausted maintenance: %1$d paused, %2$d skipped, %3$d errors.', 'mp-commerce-promotions' ),
					$promotions,
					$codes,
					$errors
				);
			case 'scheduler_automation_done':
				return sprintf(
					/* translators: 1: changed count, 2: skipped count, 3: error count */
					__( 'Scheduler automation: %1$d changed, %2$d skipped, %3$d errors.', 'mp-commerce-promotions' ),
					$promotions,
					$codes,
					$errors
				);
			case 'scheduler_normalize_done':
				return sprintf(
					/* translators: 1: changed count, 2: warnings count, 3: error count */
					__( 'State normalization: %1$d changed, %2$d warnings, %3$d errors.', 'mp-commerce-promotions' ),
					$promotions,
					$codes,
					$errors
				);
			case 'automation_run_all_done':
				return sprintf(
					/* translators: 1: changed count, 2: warnings count, 3: error count */
					__( 'Automation run_all finished: ~%1$d lifecycle changes, %2$d warnings, %3$d errors. See automation history for the full summary.', 'mp-commerce-promotions' ),
					$promotions,
					$codes,
					$errors
				);
			case 'recovery_budget_done':
				return sprintf(
					/* translators: 1: changed, 2: skipped, 3: errors */
					__( 'Budget recalculation: %1$d would change / changed, %2$d skipped, %3$d errors.', 'mp-commerce-promotions' ),
					$promotions,
					$codes,
					$errors
				);
			case 'recovery_telemetry_done':
				return sprintf(
					/* translators: 1: rows, 2: promotions scanned */
					__( 'Planner telemetry rebuild: %1$d row(s), %2$d promotion(s) scanned.', 'mp-commerce-promotions' ),
					$promotions,
					$codes
				);
			case 'recovery_snapshots_done':
				return sprintf(
					/* translators: 1: valid, 2: invalid */
					__( 'Snapshot validation: %1$d valid, %2$d invalid.', 'mp-commerce-promotions' ),
					$promotions,
					$codes
				);
			case 'recovery_orch_done':
				return sprintf(
					/* translators: 1: changed, 2: skipped */
					__( 'Orchestration group repair: %1$d changed, %2$d skipped.', 'mp-commerce-promotions' ),
					$promotions,
					$codes
				);
			case 'repair_done':
				return sprintf(
					/* translators: 1: promotions repaired count, 2: codes repaired count */
					__( 'Usage counters repaired: %1$d promotion(s), %2$d code(s).', 'mp-commerce-promotions' ),
					$promotions,
					$codes
				);
			case 'repair_none':
				return __( 'No usage counter mismatches were found to repair.', 'mp-commerce-promotions' );
			case 'repair_partial':
				return sprintf(
					/* translators: 1: promotions repaired count, 2: codes repaired count */
					__( 'Repair completed with errors. Repaired: %1$d promotion(s), %2$d code(s). Check logs for details.', 'mp-commerce-promotions' ),
					$promotions,
					$codes
				);
			case 'missing_nonce':
			case 'invalid_nonce':
				return __( 'Security check failed. Please try again.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	/**
	 * @param array{promotions?: int, codes?: int, errors?: int} $counts
	 */
	private function redirect_with_notice( string $type, string $code, array $counts = array() ): void {
		$args = array(
			'mp_cp_diag_notice' => $type,
			'mp_cp_diag_code'   => $code,
		);

		if ( isset( $counts['promotions'] ) ) {
			$args['mp_cp_diag_promotions'] = (int) $counts['promotions'];
		}
		if ( isset( $counts['codes'] ) ) {
			$args['mp_cp_diag_codes'] = (int) $counts['codes'];
		}
		if ( isset( $counts['errors'] ) ) {
			$args['mp_cp_diag_errors'] = (int) $counts['errors'];
		}

		wp_safe_redirect( AdminUrl::diagnostics( $args ) );
		exit;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function render_promotions_table( array $rows ): void {
		echo '<h2>' . esc_html__( 'Promotion usage', 'mp-commerce-promotions' ) . '</h2>';

		if ( count( $rows ) === 0 ) {
			echo '<p>' . esc_html__( 'No diagnostics available.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		$headers = array(
			__( 'ID', 'mp-commerce-promotions' ),
			__( 'Name', 'mp-commerce-promotions' ),
			__( 'Stored usage', 'mp-commerce-promotions' ),
			__( 'Recorded redemptions', 'mp-commerce-promotions' ),
			__( 'Reversed redemptions', 'mp-commerce-promotions' ),
			__( 'Expected usage', 'mp-commerce-promotions' ),
			__( 'Status', 'mp-commerce-promotions' ),
		);
		foreach ( $headers as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$matches = ! empty( $row['matches'] );
			echo '<tr>';
			echo '<td>' . esc_html( (string) (int) $row['promotion_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['name'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['stored_usage_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['computed_recorded_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['computed_reversed_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['expected_usage_count'] ) . '</td>';
			echo '<td>';
			if ( $matches ) {
				echo esc_html__( 'OK', 'mp-commerce-promotions' );
			} else {
				echo '<strong>' . esc_html__( 'Mismatch', 'mp-commerce-promotions' ) . '</strong>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function render_codes_table( array $rows ): void {
		echo '<h2>' . esc_html__( 'Promotion code usage', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Expected usage counts recorded redemptions whose order meta _mp_cp_promotion_code_id matches the code id.', 'mp-commerce-promotions' ) . '</p>';

		if ( count( $rows ) === 0 ) {
			echo '<p>' . esc_html__( 'No diagnostics available.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		$headers = array(
			__( 'Code ID', 'mp-commerce-promotions' ),
			__( 'Promotion ID', 'mp-commerce-promotions' ),
			__( 'Last 4', 'mp-commerce-promotions' ),
			__( 'Stored usage', 'mp-commerce-promotions' ),
			__( 'Expected usage', 'mp-commerce-promotions' ),
			__( 'Status', 'mp-commerce-promotions' ),
		);
		foreach ( $headers as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$matches = ! empty( $row['matches'] );
			echo '<tr>';
			echo '<td>' . esc_html( (string) (int) $row['code_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['promotion_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['last4'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['stored_usage_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['expected_usage_count'] ) . '</td>';
			echo '<td>';
			if ( $matches ) {
				echo esc_html__( 'OK', 'mp-commerce-promotions' );
			} else {
				echo '<strong>' . esc_html__( 'Mismatch', 'mp-commerce-promotions' ) . '</strong>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function handle_post_automation(): void {
		if ( $this->automation_runner === null || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST[ self::RUN_ALL_AUTOMATION_SUBMIT ] ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::RUN_ALL_AUTOMATION_NONCE_FIELD ] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST[ self::RUN_ALL_AUTOMATION_NONCE_FIELD ] ) ),
				self::RUN_ALL_AUTOMATION_NONCE_ACTION
			) ) {
			$this->redirect_with_notice( 'error', 'invalid_nonce' );
		}

		$summary = $this->automation_runner->run_all( (int) get_current_user_id() );
		$changed = 0;
		foreach ( $summary['actions'] ?? array() as $batch ) {
			if ( is_array( $batch ) && isset( $batch['changed'] ) && is_array( $batch['changed'] ) ) {
				$changed += count( $batch['changed'] );
			}
		}

		$this->redirect_with_notice(
			'success',
			'automation_run_all_done',
			array(
				'promotions' => $changed,
				'codes'      => count( $summary['warnings'] ?? array() ),
				'errors'     => count( $summary['errors'] ?? array() ),
			)
		);
	}

	private function handle_post_operational_recovery(): void {
		if ( $this->operational_recovery === null || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		$dry_run = ! isset( $_POST['mp_cp_recovery_apply'] ) || sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_recovery_apply'] ) ) !== '1';

		if ( isset( $_POST[ self::RECOVERY_BUDGET_SUBMIT ] ) ) {
			$this->verify_recovery_nonce( self::RECOVERY_BUDGET_NONCE_FIELD, self::RECOVERY_BUDGET_NONCE_ACTION );
			$result = $this->operational_recovery->recalculate_budget_spent_from_redemptions( $dry_run );
			$this->redirect_with_notice(
				'success',
				'recovery_budget_done',
				array(
					'promotions' => count( $result['changed'] ),
					'codes'      => count( $result['skipped'] ),
					'errors'     => count( $result['errors'] ),
				)
			);
		}

		if ( isset( $_POST[ self::RECOVERY_TELEMETRY_SUBMIT ] ) ) {
			$this->verify_recovery_nonce( self::RECOVERY_TELEMETRY_NONCE_FIELD, self::RECOVERY_TELEMETRY_NONCE_ACTION );
			$result = $this->operational_recovery->rebuild_planner_telemetry_from_redemptions( $dry_run );
			$this->redirect_with_notice(
				'success',
				'recovery_telemetry_done',
				array(
					'promotions' => (int) ( $result['rows_written'] ?? 0 ),
					'codes'      => (int) ( $result['promotions_processed'] ?? 0 ),
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST[ self::RECOVERY_SNAPSHOTS_SUBMIT ] ) ) {
			$this->verify_recovery_nonce( self::RECOVERY_SNAPSHOTS_NONCE_FIELD, self::RECOVERY_SNAPSHOTS_NONCE_ACTION );
			$result = $this->operational_recovery->validate_promotion_snapshots();
			$this->redirect_with_notice(
				'success',
				'recovery_snapshots_done',
				array(
					'promotions' => count( $result['valid'] ),
					'codes'      => count( $result['invalid'] ),
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST[ self::RECOVERY_ORCH_SUBMIT ] ) ) {
			$this->verify_recovery_nonce( self::RECOVERY_ORCH_NONCE_FIELD, self::RECOVERY_ORCH_NONCE_ACTION );
			$result = $this->operational_recovery->repair_invalid_orchestration_groups( $dry_run );
			$this->redirect_with_notice(
				'success',
				'recovery_orch_done',
				array(
					'promotions' => count( $result['changed'] ),
					'codes'      => count( $result['skipped'] ),
					'errors'     => 0,
				)
			);
		}
	}

	private function verify_recovery_nonce( string $field, string $action ): void {
		if ( ! isset( $_POST[ $field ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ), $action ) ) {
			$this->redirect_with_notice( 'error', 'invalid_nonce' );
		}
	}

	private function render_automation_runner_section(): void {
		if ( $this->automation_runner === null ) {
			return;
		}

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Promotion automation', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Runs activate-scheduled, archive-expired, pause-budget-exhausted, and normalize-states in one pass. Manual trigger only (no WP-Cron yet). Each run is stored in automation history.',
			'mp-commerce-promotions'
		) . '</p>';

		$confirm = esc_js( __( 'Run full promotion automation now?', 'mp-commerce-promotions' ) );
		echo '<form method="post" action="" style="margin:0 0 1em;">';
		wp_nonce_field( self::RUN_ALL_AUTOMATION_NONCE_ACTION, self::RUN_ALL_AUTOMATION_NONCE_FIELD );
		echo '<p><button type="submit" name="' . esc_attr( self::RUN_ALL_AUTOMATION_SUBMIT ) . '" value="1" class="button button-primary" onclick="return confirm(\'' . $confirm . '\');">';
		echo esc_html__( 'Run all automation', 'mp-commerce-promotions' );
		echo '</button></p>';
		echo '</form>';
	}

	private function render_promotion_health_section(): void {
		if ( $this->health_monitor === null ) {
			return;
		}

		$issues = $this->health_monitor->analyze( 500 );
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Promotion health', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Read-only configuration checks across promotions (dates, budgets, exclusions, orchestration, actions).', 'mp-commerce-promotions' ) . '</p>';

		if ( $issues === array() ) {
			echo '<p>' . esc_html__( 'No health issues detected.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:100%;"><thead><tr>';
		echo '<th>' . esc_html__( 'Severity', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Code', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Promotion IDs', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $issues, 0, 50 ) as $issue ) {
			$severity = isset( $issue['severity'] ) ? (string) $issue['severity'] : 'info';
			echo '<tr><td>' . esc_html( $this->health_badge_label( $severity ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $issue['code'] ?? '' ) ) . '</code></td>';
			$ids = isset( $issue['promotion_ids'] ) && is_array( $issue['promotion_ids'] )
				? implode( ', ', array_map( 'strval', $issue['promotion_ids'] ) )
				: '';
			echo '<td>' . esc_html( $ids ) . '</td>';
			echo '<td>' . esc_html( (string) ( $issue['message'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function health_badge_label( string $severity ): string {
		if ( $severity === PromotionHealthMonitor::SEVERITY_CRITICAL ) {
			return __( 'Critical', 'mp-commerce-promotions' );
		}
		if ( $severity === PromotionHealthMonitor::SEVERITY_WARNING ) {
			return __( 'Warning', 'mp-commerce-promotions' );
		}

		return __( 'Info', 'mp-commerce-promotions' );
	}

	private function render_operational_recovery_section(): void {
		if ( $this->operational_recovery === null ) {
			return;
		}

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Rollback and recovery', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Dry-run is the default (preview only). Check “Apply changes” to persist. All actions require confirmation.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<p><label><input type="checkbox" name="mp_cp_recovery_apply" value="1" form="mp-cp-recovery-budget" /> ';
		echo esc_html__( 'Apply changes (budget recalc form)', 'mp-commerce-promotions' ) . '</label></p>';

		$this->render_recovery_form(
			self::RECOVERY_BUDGET_SUBMIT,
			self::RECOVERY_BUDGET_NONCE_ACTION,
			self::RECOVERY_BUDGET_NONCE_FIELD,
			__( 'Recalculate budget_spent from redemptions', 'mp-commerce-promotions' ),
			'mp-cp-recovery-budget'
		);
		$this->render_recovery_form(
			self::RECOVERY_TELEMETRY_SUBMIT,
			self::RECOVERY_TELEMETRY_NONCE_ACTION,
			self::RECOVERY_TELEMETRY_NONCE_FIELD,
			__( 'Rebuild planner telemetry from redemption history', 'mp-commerce-promotions' ),
			'mp-cp-recovery-telemetry'
		);
		$this->render_recovery_form(
			self::RECOVERY_SNAPSHOTS_SUBMIT,
			self::RECOVERY_SNAPSHOTS_NONCE_ACTION,
			self::RECOVERY_SNAPSHOTS_NONCE_FIELD,
			__( 'Validate promotion snapshots', 'mp-commerce-promotions' ),
			'mp-cp-recovery-snapshots'
		);
		$this->render_recovery_form(
			self::RECOVERY_ORCH_SUBMIT,
			self::RECOVERY_ORCH_NONCE_ACTION,
			self::RECOVERY_ORCH_NONCE_FIELD,
			__( 'Repair invalid orchestration groups', 'mp-commerce-promotions' ),
			'mp-cp-recovery-orch'
		);
	}

	private function render_recovery_form(
		string $submit_name,
		string $nonce_action,
		string $nonce_field,
		string $label,
		string $form_id
	): void {
		$confirm = esc_js( $label . '?' );
		echo '<form method="post" action="" id="' . esc_attr( $form_id ) . '" style="margin:0 0 0.75em;">';
		wp_nonce_field( $nonce_action, $nonce_field );
		echo '<button type="submit" name="' . esc_attr( $submit_name ) . '" value="1" class="button button-secondary" onclick="return confirm(\'' . $confirm . '\');">';
		echo esc_html( $label );
		echo '</button></form>';
	}

	private function render_automation_history_section(): void {
		if ( $this->automation_runs === null ) {
			return;
		}

		$runs = $this->automation_runs->find_latest( 20 );
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Automation run history (latest 20)', 'mp-commerce-promotions' ) . '</h2>';

		if ( $runs === array() ) {
			echo '<p>' . esc_html__( 'No automation runs recorded yet.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		$detail_id = isset( $_GET['mp_cp_automation_run'] ) ? (int) $_GET['mp_cp_automation_run'] : 0;

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>ID</th><th>' . esc_html__( 'Type', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Warnings', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Errors', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Started', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $runs as $run ) {
			$id = $run->get_id();
			if ( $id === null ) {
				continue;
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) $id ) . '</td>';
			echo '<td><code>' . esc_html( $run->get_run_type() ) . '</code></td>';
			echo '<td>' . esc_html( $this->automation_status_badge( $run->get_status() ) ) . '</td>';
			echo '<td>' . esc_html( (string) $run->get_warnings_count() ) . '</td>';
			echo '<td>' . esc_html( (string) $run->get_errors_count() ) . '</td>';
			echo '<td>' . esc_html( $run->get_created_at() ) . '</td>';
			echo '<td><a href="' . esc_url( AdminUrl::diagnostics( array( 'mp_cp_automation_run' => $id ) ) ) . '">';
			echo esc_html__( 'View summary', 'mp-commerce-promotions' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $detail_id > 0 ) {
			$detail = $this->automation_runs->find( $detail_id );
			if ( $detail !== null ) {
				echo '<h3>' . esc_html__( 'Run summary', 'mp-commerce-promotions' ) . ' #' . esc_html( (string) $detail_id ) . '</h3>';
				echo '<pre style="max-width:100%;overflow:auto;background:#f6f7f7;padding:1em;">';
				echo esc_html( wp_json_encode( $detail->get_summary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?? '{}' );
				echo '</pre>';
			}
		}
	}

	private function automation_status_badge( string $status ): string {
		if ( $status === AutomationRun::STATUS_FAILED ) {
			return __( 'Failed', 'mp-commerce-promotions' );
		}

		return __( 'Completed', 'mp-commerce-promotions' );
	}
}
