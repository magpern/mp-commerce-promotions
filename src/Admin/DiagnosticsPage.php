<?php
/**
 * WooCommerce admin: promotion usage diagnostics and manual repair.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

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

	private UsageDiagnostics $diagnostics;

	private ?PromotionService $promotion_service;

	public function __construct( UsageDiagnostics $diagnostics, ?PromotionService $promotion_service = null ) {
		$this->diagnostics        = $diagnostics;
		$this->promotion_service  = $promotion_service;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->handle_post_repair();
		$this->handle_post_archive_hygiene();

		$report = $this->diagnostics->analyze();

		echo '<div class="wrap">';
		$this->render_notices();
		echo '<h1>' . esc_html__( 'Promotion Diagnostics', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_DIAGNOSTICS );
		echo '<p>' . esc_html__( 'Compare stored usage_count values against redemption and order-meta records. Use the repair action to recalculate mismatched counters from recorded redemptions.', 'mp-commerce-promotions' ) . '</p>';

		$this->render_repair_form();
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
}
