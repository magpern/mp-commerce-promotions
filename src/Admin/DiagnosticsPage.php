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
use MP\CommercePromotions\Service\PromotionIntelligenceRecovery;
use MP\CommercePromotions\Service\PromotionPricingRecovery;
use MP\CommercePromotions\Service\PromotionOperationalRecovery;
use MP\CommercePromotions\Service\PromotionRecommendationEngine;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Admin\AdminPilotHardeningPanel;
use MP\CommercePromotions\Admin\EcosystemCertificationPanel;
use MP\CommercePromotions\Domain\AuditLogRepository;
use MP\CommercePromotions\Domain\PromotionSnapshotRepository;
use MP\CommercePromotions\Service\PromotionSnapshotService;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\SupportBundleExporter;
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

	private const INTEL_RESET_TELEMETRY_SUBMIT = 'mp_cp_reset_planner_telemetry_submit';

	private const INTEL_RESET_TELEMETRY_NONCE_ACTION = 'mp_cp_reset_planner_telemetry';

	private const INTEL_RESET_TELEMETRY_NONCE_FIELD = 'mp_cp_reset_planner_telemetry_nonce';

	private const INTEL_RESET_FORECAST_SUBMIT = 'mp_cp_reset_forecast_cache_submit';

	private const INTEL_RESET_FORECAST_NONCE_ACTION = 'mp_cp_reset_forecast_cache';

	private const INTEL_RESET_FORECAST_NONCE_FIELD = 'mp_cp_reset_forecast_cache_nonce';

	private const INTEL_RECALC_METRICS_SUBMIT = 'mp_cp_recalc_simulation_metrics_submit';

	private const INTEL_RECALC_METRICS_NONCE_ACTION = 'mp_cp_recalc_simulation_metrics';

	private const INTEL_RECALC_METRICS_NONCE_FIELD = 'mp_cp_recalc_simulation_metrics_nonce';

	private const INTEL_VALIDATE_SCENARIOS_SUBMIT = 'mp_cp_validate_simulation_scenarios_submit';

	private const INTEL_VALIDATE_SCENARIOS_NONCE_ACTION = 'mp_cp_validate_simulation_scenarios';

	private const INTEL_VALIDATE_SCENARIOS_NONCE_FIELD = 'mp_cp_validate_simulation_scenarios_nonce';

	private const INTEL_REPAIR_SCENARIOS_SUBMIT = 'mp_cp_repair_simulation_scenarios_submit';

	private const INTEL_REPAIR_SCENARIOS_NONCE_ACTION = 'mp_cp_repair_simulation_scenarios';

	private const INTEL_REPAIR_SCENARIOS_NONCE_FIELD = 'mp_cp_repair_simulation_scenarios_nonce';

	private UsageDiagnostics $diagnostics;

	private ?PromotionService $promotion_service;

	private ?PromotionAutomationRunner $automation_runner;

	private ?PromotionHealthMonitor $health_monitor;

	private ?PromotionOperationalRecovery $operational_recovery;

	private ?AutomationRunRepository $automation_runs;

	private ?PromotionIntelligenceRecovery $intelligence_recovery;

	private ?PromotionRecommendationEngine $recommendations;

	private ?PromotionPricingRecovery $pricing_recovery;

	private Settings $settings;

	private ?SupportBundleExporter $support_exporter;

	private ?\MP\CommercePromotions\Service\PromotionPerformanceProfiler $profiler;

	private ?\MP\CommercePromotions\Service\PromotionConcurrencyGuard $concurrency;

	private ?\MP\CommercePromotions\Service\PromotionCronScheduler $cron_scheduler;

	private ?\MP\CommercePromotions\Service\PromotionDataRetentionService $retention;

	private ?\MP\CommercePromotions\Service\PromotionSubsystemRecovery $subsystem_recovery;

	private ?AuditLogger $audit_logger;

	public function __construct(
		UsageDiagnostics $diagnostics,
		Settings $settings,
		?PromotionService $promotion_service = null,
		?PromotionAutomationRunner $automation_runner = null,
		?PromotionHealthMonitor $health_monitor = null,
		?PromotionOperationalRecovery $operational_recovery = null,
		?AutomationRunRepository $automation_runs = null,
		?PromotionIntelligenceRecovery $intelligence_recovery = null,
		?PromotionRecommendationEngine $recommendations = null,
		?PromotionPricingRecovery $pricing_recovery = null,
		?SupportBundleExporter $support_exporter = null,
		?\MP\CommercePromotions\Service\PromotionPerformanceProfiler $profiler = null,
		?\MP\CommercePromotions\Service\PromotionConcurrencyGuard $concurrency = null,
		?\MP\CommercePromotions\Service\PromotionCronScheduler $cron_scheduler = null,
		?\MP\CommercePromotions\Service\PromotionDataRetentionService $retention = null,
		?\MP\CommercePromotions\Service\PromotionSubsystemRecovery $subsystem_recovery = null,
		?AuditLogger $audit_logger = null
	) {
		$this->diagnostics           = $diagnostics;
		$this->settings              = $settings;
		$this->support_exporter      = $support_exporter;
		$this->promotion_service     = $promotion_service;
		$this->automation_runner     = $automation_runner;
		$this->health_monitor        = $health_monitor;
		$this->operational_recovery  = $operational_recovery;
		$this->automation_runs       = $automation_runs;
		$this->intelligence_recovery = $intelligence_recovery;
		$this->recommendations       = $recommendations;
		$this->pricing_recovery      = $pricing_recovery;
		$this->profiler              = $profiler;
		$this->concurrency           = $concurrency;
		$this->cron_scheduler        = $cron_scheduler;
		$this->retention             = $retention;
		$this->subsystem_recovery    = $subsystem_recovery;
		$this->audit_logger          = $audit_logger;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->handle_post_gift_card_integrity_repair();
		$this->handle_post_gift_card_product_repair();
		$this->handle_post_gift_card_delivery_repair();
		$this->handle_post_gift_card_scheduled_repair();
		$this->handle_post_gift_card_customer_repair();
		$this->handle_post_repair();
		$this->handle_post_archive_hygiene();
		$this->handle_post_automation();
		$this->handle_post_operational_recovery();
		$this->handle_post_intelligence_recovery();
		$this->handle_post_pricing_recovery();
		$this->handle_post_support_export();
		AdminPerformanceHardeningPanel::handle_post(
			$this->settings,
			$this->retention,
			$this->profiler,
			$this->cron_scheduler
		);

		global $wpdb;
		if ( $wpdb instanceof \wpdb ) {
			$promo_repo         = new \MP\CommercePromotions\Domain\PromotionRepository( $wpdb );
			$snapshot_repo      = new PromotionSnapshotRepository( $wpdb );
			$audit_log_repo     = new AuditLogRepository( $wpdb );
			$snapshot_service   = $this->audit_logger !== null
				? new PromotionSnapshotService( $promo_repo, $snapshot_repo, $this->audit_logger )
				: null;
			AdminPilotHardeningPanel::handle_post(
				$this->settings,
				$snapshot_service,
				$promo_repo,
				$snapshot_repo,
				$audit_log_repo,
				$this->audit_logger
			);
			EcosystemCertificationPanel::handle_emergency_post(
				$this->settings,
				$promo_repo,
				$this->profiler,
				$this->intelligence_recovery,
				$this->audit_logger
			);
		}

		$report = $this->diagnostics->analyze();

		echo '<div class="wrap">';
		$this->render_notices();
		echo '<h1>' . esc_html__( 'Diagnostics', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_DIAGNOSTICS );
		echo '<p>' . esc_html__( 'Compare stored usage_count values against redemption and order-meta records. Use the repair action to recalculate mismatched counters from recorded redemptions.', 'mp-commerce-promotions' ) . '</p>';

		global $wpdb;
		if ( $wpdb instanceof \wpdb ) {
			$promo_count = ( new \MP\CommercePromotions\Domain\PromotionRepository( $wpdb ) )->count_filtered( array() );
			if ( $promo_count === 0 ) {
				echo '<div class="notice notice-info"><p>';
				echo esc_html__( 'No promotions yet.', 'mp-commerce-promotions' ) . ' ';
				AdminNavigation::render_create_campaign_button( array( 'class' => 'button button-primary' ) );
				echo '</p></div>';
			}
		}

		$this->render_repair_form();
		$this->render_gift_card_integrity_section();
		$this->render_gift_card_product_section();
		$this->render_gift_card_delivery_section();
		$this->render_gift_card_mail_section();
		$this->render_gift_card_scheduled_section();
		$this->render_gift_card_customer_section();
		CompatibilityStatusPanel::render();
		if ( $this->profiler !== null && $this->concurrency !== null ) {
			EcosystemCompatibilityPanel::render_system_health(
				$this->settings,
				$this->profiler,
				$this->concurrency,
				null,
				$this->health_monitor
			);
			EcosystemCompatibilityPanel::render_ecosystem_matrix();
		}
		global $wpdb;
		if ( $wpdb instanceof \wpdb ) {
			$promo_repo = new \MP\CommercePromotions\Domain\PromotionRepository( $wpdb );
			EcosystemCompatibilityPanel::render_merchant_safety( $promo_repo );
			EcosystemCompatibilityPanel::render_complexity( $promo_repo );
			EcosystemCompatibilityPanel::render_schedule_conflict_preview( $promo_repo );
			$cert_repo = new \MP\CommercePromotions\Domain\CertificationRunRepository( $wpdb );
			$tracking  = new \MP\CommercePromotions\Service\CertificationTrackingService( $cert_repo );
			EcosystemCertificationPanel::render_certification_table( $tracking );
			EcosystemCertificationPanel::render_coupon_matrix();
			EcosystemCertificationPanel::render_tax_compatibility();
			EcosystemCertificationPanel::render_currency_snapshot();
			EcosystemCertificationPanel::render_coupon_telemetry( $this->profiler );
			$emergency = new \MP\CommercePromotions\Service\EmergencyOperationsService(
				$this->settings,
				$promo_repo,
				$this->profiler,
				$this->intelligence_recovery,
				null
			);
			EcosystemCertificationPanel::render_emergency_operations( $emergency );
			AdminPilotHardeningPanel::render(
				$this->settings,
				$this->profiler,
				$snapshot_service,
				$promo_repo,
				$snapshot_repo,
				$audit_log_repo
			);
		}
		$this->render_support_export_section();
		$this->render_automation_runner_section();
		$this->render_promotion_health_section();
		$this->render_operational_recovery_section();
		$this->render_intelligence_recovery_section();
		$this->render_pricing_recovery_section();
		$this->render_recommendations_section();
		$this->render_automation_history_section();
		$this->render_archive_hygiene_section();
		$this->render_scheduler_automation_section();
		AdminPerformanceHardeningPanel::render(
			$this->settings,
			$this->profiler,
			$this->concurrency,
			$this->cron_scheduler,
			$this->retention,
			$this->subsystem_recovery
		);
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
			case 'intel_reset_telemetry_done':
				return sprintf(
					__( 'Planner telemetry reset: %1$d row(s) affected (dry-run when apply unchecked).', 'mp-commerce-promotions' ),
					$promotions
				);
			case 'intel_reset_forecast_done':
				return __( 'Forecast cache reset completed (or previewed in dry-run).', 'mp-commerce-promotions' );
			case 'intel_recalc_metrics_done':
				return __( 'Simulation / planner performance counters recalculated.', 'mp-commerce-promotions' );
			case 'intel_validate_scenarios_done':
				return sprintf(
					__( 'Scenario validation: %1$d valid, %2$d invalid.', 'mp-commerce-promotions' ),
					$promotions,
					$codes
				);
			case 'intel_repair_scenarios_done':
				return sprintf(
					__( 'Scenario repair: %1$d valid kept, %2$d archived.', 'mp-commerce-promotions' ),
					$promotions,
					$codes
				);
			case 'pricing_rebuild_done':
				return sprintf( __( 'Allocation summary rebuild processed %d promotion(s).', 'mp-commerce-promotions' ), $promotions );
			case 'pricing_tiers_done':
				return sprintf( __( 'Normalized %d invalid priority tier(s).', 'mp-commerce-promotions' ), $promotions );
			case 'pricing_coexistence_done':
				return sprintf( __( 'Repaired %d malformed coupon coexistence config(s).', 'mp-commerce-promotions' ), $promotions );
			case 'pricing_profitability_done':
				return __( 'Profitability metric cache recalculated.', 'mp-commerce-promotions' );
			case 'pricing_line_sessions_dry_run':
				return __( 'Line discount session repair dry-run complete. No session or cart prices were changed. Review Diagnostics for cleared keys.', 'mp-commerce-promotions' );
			case 'pricing_line_sessions_done':
				return __( 'Stuck line discount sessions repaired: line allocation session cleared and cart line prices restored.', 'mp-commerce-promotions' );
			case 'pricing_snapshots_done':
				return sprintf(
					__( 'Allocation snapshot validation: %1$d valid, %2$d invalid.', 'mp-commerce-promotions' ),
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

		if ( ! $this->settings->automation_manual_only() ) {
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

	private function handle_post_intelligence_recovery(): void {
		if ( $this->intelligence_recovery === null || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		$dry_run = ! isset( $_POST['mp_cp_intel_recovery_apply'] ) || sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_intel_recovery_apply'] ) ) !== '1';

		if ( isset( $_POST[ self::INTEL_RESET_TELEMETRY_SUBMIT ] ) ) {
			$this->verify_recovery_nonce( self::INTEL_RESET_TELEMETRY_NONCE_FIELD, self::INTEL_RESET_TELEMETRY_NONCE_ACTION );
			$result = $this->intelligence_recovery->reset_telemetry( $dry_run );
			$this->redirect_with_notice(
				'success',
				'intel_reset_telemetry_done',
				array(
					'promotions' => (int) ( $result['deleted_rows'] ?? 0 ),
					'codes'      => $dry_run ? 1 : 0,
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST[ self::INTEL_RESET_FORECAST_SUBMIT ] ) ) {
			$this->verify_recovery_nonce( self::INTEL_RESET_FORECAST_NONCE_FIELD, self::INTEL_RESET_FORECAST_NONCE_ACTION );
			if ( ! $dry_run ) {
				$this->intelligence_recovery->reset_forecast_cache();
			}
			$this->redirect_with_notice(
				'success',
				'intel_reset_forecast_done',
				array(
					'promotions' => 1,
					'codes'      => 0,
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST[ self::INTEL_RECALC_METRICS_SUBMIT ] ) ) {
			$this->verify_recovery_nonce( self::INTEL_RECALC_METRICS_NONCE_FIELD, self::INTEL_RECALC_METRICS_NONCE_ACTION );
			if ( ! $dry_run ) {
				$this->intelligence_recovery->recalculate_simulation_metrics();
			}
			$this->redirect_with_notice(
				'success',
				'intel_recalc_metrics_done',
				array(
					'promotions' => 1,
					'codes'      => 0,
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST[ self::INTEL_VALIDATE_SCENARIOS_SUBMIT ] ) ) {
			$this->verify_recovery_nonce( self::INTEL_VALIDATE_SCENARIOS_NONCE_FIELD, self::INTEL_VALIDATE_SCENARIOS_NONCE_ACTION );
			$result = $this->intelligence_recovery->validate_scenario_payloads( $dry_run );
			$this->redirect_with_notice(
				'success',
				'intel_validate_scenarios_done',
				array(
					'promotions' => (int) ( $result['valid'] ?? 0 ),
					'codes'      => count( $result['invalid'] ?? array() ),
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST[ self::INTEL_REPAIR_SCENARIOS_SUBMIT ] ) ) {
			$this->verify_recovery_nonce( self::INTEL_REPAIR_SCENARIOS_NONCE_FIELD, self::INTEL_REPAIR_SCENARIOS_NONCE_ACTION );
			$result = $this->intelligence_recovery->repair_malformed_simulation_rows( $dry_run );
			$this->redirect_with_notice(
				'success',
				'intel_repair_scenarios_done',
				array(
					'promotions' => (int) ( $result['repaired'] ?? 0 ),
					'codes'      => (int) ( $result['archived'] ?? 0 ),
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
		if ( $this->settings->automation_manual_only() ) {
			echo '<p class="description">' . esc_html__(
				'Runs activate-scheduled, archive-expired, pause-budget-exhausted, and normalize-states in one pass. Manual trigger only (no WP-Cron in this release). Each run is stored in automation history.',
				'mp-commerce-promotions'
			) . '</p>';

			$confirm = esc_js( __( 'Run full promotion automation now?', 'mp-commerce-promotions' ) );
			echo '<form method="post" action="" style="margin:0 0 1em;">';
			wp_nonce_field( self::RUN_ALL_AUTOMATION_NONCE_ACTION, self::RUN_ALL_AUTOMATION_NONCE_FIELD );
			echo '<p><button type="submit" name="' . esc_attr( self::RUN_ALL_AUTOMATION_SUBMIT ) . '" value="1" class="button button-primary" onclick="return confirm(\'' . $confirm . '\');">';
			echo esc_html__( 'Run all automation', 'mp-commerce-promotions' );
			echo '</button></p>';
			echo '</form>';
		} else {
			echo '<p class="description">' . esc_html__(
				'Automatic WP-Cron scheduling is not available in this release. Re-enable “Automation runner: manual only” in Settings to use Diagnostics triggers.',
				'mp-commerce-promotions'
			) . '</p>';
		}
	}

	private function handle_post_support_export(): void {
		if ( $this->support_exporter === null || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_support_bundle_submit'] ) ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_support_bundle_nonce'] ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mp-commerce-promotions' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_support_bundle_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'mp_cp_support_bundle' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mp-commerce-promotions' ) );
		}

		$json     = $this->support_exporter->to_json();
		$filename = 'mp-cp-support-bundle-' . gmdate( 'Y-m-d' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON attachment.
		echo $json;
		exit;
	}

	private function render_support_export_section(): void {
		if ( $this->support_exporter === null ) {
			return;
		}

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Export support bundle', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Download a JSON snapshot for support (versions, settings, counts, health issues). No customer PII or raw promotion codes.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<form method="post" action="">';
		wp_nonce_field( 'mp_cp_support_bundle', 'mp_cp_support_bundle_nonce' );
		echo '<p><button type="submit" name="mp_cp_support_bundle_submit" value="1" class="button">';
		echo esc_html__( 'Download support bundle', 'mp-commerce-promotions' );
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

	private function render_intelligence_recovery_section(): void {
		if ( $this->intelligence_recovery === null ) {
			return;
		}

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Simulation & forecasting recovery', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Dry-run by default. Check “Apply intelligence recovery” on any form to persist. Heuristic forecasts only (no ML).',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<p><label><input type="checkbox" name="mp_cp_intel_recovery_apply" value="1" form="mp-cp-intel-telemetry" /> ';
		echo esc_html__( 'Apply intelligence recovery (telemetry reset form)', 'mp-commerce-promotions' ) . '</label></p>';

		$this->render_recovery_form(
			self::INTEL_RESET_TELEMETRY_SUBMIT,
			self::INTEL_RESET_TELEMETRY_NONCE_ACTION,
			self::INTEL_RESET_TELEMETRY_NONCE_FIELD,
			__( 'Reset planner telemetry', 'mp-commerce-promotions' ),
			'mp-cp-intel-telemetry'
		);
		$this->render_recovery_form(
			self::INTEL_RESET_FORECAST_SUBMIT,
			self::INTEL_RESET_FORECAST_NONCE_ACTION,
			self::INTEL_RESET_FORECAST_NONCE_FIELD,
			__( 'Reset forecast cache', 'mp-commerce-promotions' ),
			'mp-cp-intel-forecast'
		);
		$this->render_recovery_form(
			self::INTEL_RECALC_METRICS_SUBMIT,
			self::INTEL_RECALC_METRICS_NONCE_ACTION,
			self::INTEL_RECALC_METRICS_NONCE_FIELD,
			__( 'Recalculate simulation / planner metrics', 'mp-commerce-promotions' ),
			'mp-cp-intel-metrics'
		);
		$this->render_recovery_form(
			self::INTEL_VALIDATE_SCENARIOS_SUBMIT,
			self::INTEL_VALIDATE_SCENARIOS_NONCE_ACTION,
			self::INTEL_VALIDATE_SCENARIOS_NONCE_FIELD,
			__( 'Validate saved simulation scenario payloads', 'mp-commerce-promotions' ),
			'mp-cp-intel-validate'
		);
		$this->render_recovery_form(
			self::INTEL_REPAIR_SCENARIOS_SUBMIT,
			self::INTEL_REPAIR_SCENARIOS_NONCE_ACTION,
			self::INTEL_REPAIR_SCENARIOS_NONCE_FIELD,
			__( 'Repair malformed simulation rows (soft-archive)', 'mp-commerce-promotions' ),
			'mp-cp-intel-repair'
		);
	}

	private function handle_post_pricing_recovery(): void {
		if ( $this->pricing_recovery === null || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		$dry_run = ! isset( $_POST['mp_cp_pricing_recovery_apply'] ) || sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_pricing_recovery_apply'] ) ) !== '1';

		if ( isset( $_POST['mp_cp_rebuild_allocation_submit'] ) ) {
			$this->verify_recovery_nonce( 'mp_cp_rebuild_allocation_nonce', 'mp_cp_rebuild_allocation' );
			$result = $this->pricing_recovery->rebuild_allocation_summaries( $dry_run );
			$this->redirect_with_notice(
				'success',
				'pricing_rebuild_done',
				array(
					'promotions' => (int) ( $result['promotions_processed'] ?? 0 ),
					'codes'      => 0,
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST['mp_cp_normalize_tiers_submit'] ) ) {
			$this->verify_recovery_nonce( 'mp_cp_normalize_tiers_nonce', 'mp_cp_normalize_tiers' );
			$result = $this->pricing_recovery->normalize_invalid_priority_tiers( $dry_run );
			$this->redirect_with_notice(
				'success',
				'pricing_tiers_done',
				array(
					'promotions' => (int) ( $result['changed'] ?? 0 ),
					'codes'      => 0,
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST['mp_cp_repair_coexistence_submit'] ) ) {
			$this->verify_recovery_nonce( 'mp_cp_repair_coexistence_nonce', 'mp_cp_repair_coexistence' );
			$result = $this->pricing_recovery->repair_malformed_coexistence_configs( $dry_run );
			$this->redirect_with_notice(
				'success',
				'pricing_coexistence_done',
				array(
					'promotions' => (int) ( $result['changed'] ?? 0 ),
					'codes'      => 0,
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST['mp_cp_recalc_profitability_submit'] ) ) {
			$this->verify_recovery_nonce( 'mp_cp_recalc_profitability_nonce', 'mp_cp_recalc_profitability' );
			$this->pricing_recovery->recalculate_profitability_metrics();
			$this->redirect_with_notice(
				'success',
				'pricing_profitability_done',
				array(
					'promotions' => 0,
					'codes'      => 0,
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST['mp_cp_validate_alloc_snapshots_submit'] ) ) {
			$this->verify_recovery_nonce( 'mp_cp_validate_alloc_snapshots_nonce', 'mp_cp_validate_alloc_snapshots' );
			$result = $this->pricing_recovery->validate_allocation_snapshots();
			$this->redirect_with_notice(
				'success',
				'pricing_snapshots_done',
				array(
					'promotions' => (int) ( $result['valid'] ?? 0 ),
					'codes'      => (int) ( $result['invalid'] ?? 0 ),
					'errors'     => 0,
				)
			);
		}

		if ( isset( $_POST['mp_cp_repair_line_discount_sessions_submit'] ) ) {
			$this->verify_recovery_nonce( 'mp_cp_repair_line_discount_sessions_nonce', 'mp_cp_repair_line_discount_sessions' );
			$dry_run = ! isset( $_POST['mp_cp_pricing_recovery_apply'] ) || sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_pricing_recovery_apply'] ) ) !== '1';
			$result  = $this->pricing_recovery->repair_stuck_line_discount_sessions( $dry_run );
			$this->redirect_with_notice(
				'success',
				$dry_run ? 'pricing_line_sessions_dry_run' : 'pricing_line_sessions_done',
				array(
					'promotions' => 0,
					'codes'      => 0,
					'errors'     => 0,
				)
			);
		}
	}

	private function render_pricing_recovery_section(): void {
		if ( $this->pricing_recovery === null ) {
			return;
		}

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Pricing engine recovery', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Rebuild allocation summaries and normalize priority tiers. Dry-run unless apply is checked.', 'mp-commerce-promotions' ) . '</p>';
		echo '<p><label><input type="checkbox" name="mp_cp_pricing_recovery_apply" value="1" form="mp-cp-pricing-line-sessions" /> ';
		echo esc_html__( 'Apply pricing recovery (including line discount session repair)', 'mp-commerce-promotions' ) . '</label></p>';
		$this->render_recovery_form( 'mp_cp_rebuild_allocation_submit', 'mp_cp_rebuild_allocation', 'mp_cp_rebuild_allocation_nonce', __( 'Rebuild allocation summaries', 'mp-commerce-promotions' ), 'mp-cp-pricing-rebuild' );
		$this->render_recovery_form( 'mp_cp_repair_coexistence_submit', 'mp_cp_repair_coexistence', 'mp_cp_repair_coexistence_nonce', __( 'Repair malformed coexistence configs', 'mp-commerce-promotions' ), 'mp-cp-pricing-coexistence' );
		$this->render_recovery_form( 'mp_cp_normalize_tiers_submit', 'mp_cp_normalize_tiers', 'mp_cp_normalize_tiers_nonce', __( 'Normalize invalid priority tiers', 'mp-commerce-promotions' ), 'mp-cp-pricing-tiers' );
		$this->render_recovery_form( 'mp_cp_recalc_profitability_submit', 'mp_cp_recalc_profitability', 'mp_cp_recalc_profitability_nonce', __( 'Recalculate profitability metrics', 'mp-commerce-promotions' ), 'mp-cp-pricing-profitability' );
		$this->render_recovery_form( 'mp_cp_validate_alloc_snapshots_submit', 'mp_cp_validate_alloc_snapshots', 'mp_cp_validate_alloc_snapshots_nonce', __( 'Validate allocation snapshots', 'mp-commerce-promotions' ), 'mp-cp-pricing-snapshots' );

		echo '<p class="description" style="max-width:52em;">' . esc_html__(
			'Repair stuck line discount sessions clears mp_cp_line_allocations, restores cart lines with mp_cp_original_line_unit_price, and resets in-request line discount caches. Does not delete fallback telemetry options unless you reset them separately.',
			'mp-commerce-promotions'
		) . '</p>';
		$this->render_recovery_form(
			'mp_cp_repair_line_discount_sessions_submit',
			'mp_cp_repair_line_discount_sessions',
			'mp_cp_repair_line_discount_sessions_nonce',
			__( 'Repair stuck line discount sessions', 'mp-commerce-promotions' ),
			'mp-cp-pricing-line-sessions'
		);
	}

	private function render_recommendations_section(): void {
		if ( $this->recommendations === null ) {
			return;
		}

		$recs = $this->recommendations->recommend( 100 );
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Campaign recommendations', 'mp-commerce-promotions' ) . '</h2>';
		if ( $recs === array() ) {
			echo '<p>' . esc_html__( 'No recommendations.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:100%;"><thead><tr>';
		echo '<th>' . esc_html__( 'Severity', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Code', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $recs, 0, 30 ) as $rec ) {
			echo '<tr><td>' . esc_html( (string) ( $rec['severity'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $rec['code'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $rec['message'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
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

	private function handle_post_gift_card_integrity_repair(): void {
		if ( ! isset( $_POST['mp_cp_gift_card_integrity_repair'] ) ) {
			return;
		}

		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ),
				'mp_cp_gift_card_integrity_repair'
			)
		) {
			return;
		}

		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$apply = isset( $_POST['mp_cp_gift_card_repair_apply'] ) && (string) $_POST['mp_cp_gift_card_repair_apply'] === '1';
		$repo  = new \MP\CommercePromotions\GiftCard\GiftCardRepository( $wpdb );
		$tx    = new \MP\CommercePromotions\GiftCard\GiftCardTransactionRepository( $wpdb );
		$diag  = new \MP\CommercePromotions\GiftCard\GiftCardIntegrityDiagnostics(
			$wpdb,
			$repo,
			new \MP\CommercePromotions\GiftCard\GiftCardLedger( $repo, $tx )
		);
		$result = $diag->repair( $apply );
		if ( $apply ) {
			AdminNotice::success(
				sprintf(
					/* translators: 1: depleted count, 2: expired count */
					__( 'Gift card repair applied: %1$d depleted, %2$d expired.', 'mp-commerce-promotions' ),
					(int) $result['depleted_marked'],
					(int) $result['expired_marked']
				)
			);
		} else {
			AdminNotice::info(
				sprintf(
					/* translators: 1: depleted count, 2: expired count */
					__( 'Gift card repair preview: would mark %1$d depleted, %2$d expired.', 'mp-commerce-promotions' ),
					(int) $result['depleted_marked'],
					(int) $result['expired_marked']
				)
			);
		}
	}

	private function handle_post_gift_card_product_repair(): void {
		if ( ! isset( $_POST['mp_cp_gift_card_product_repair'] ) ) {
			return;
		}

		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ),
				'mp_cp_gift_card_product_repair'
			)
		) {
			return;
		}

		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$apply = isset( $_POST['mp_cp_gift_card_product_repair_apply'] ) && (string) $_POST['mp_cp_gift_card_product_repair_apply'] === '1';
		$repo  = new \MP\CommercePromotions\GiftCard\GiftCardRepository( $wpdb );
		$tx    = new \MP\CommercePromotions\GiftCard\GiftCardTransactionRepository( $wpdb );
		$ledger = new \MP\CommercePromotions\GiftCard\GiftCardLedger( $repo, $tx );
		$generator = new \MP\CommercePromotions\GiftCard\GiftCardOrderGenerator(
			$ledger,
			null,
			$this->settings,
			$this->audit_logger
		);
		$reversal = new \MP\CommercePromotions\GiftCard\GiftCardOrderReversal( $ledger, $repo );
		$diag     = new \MP\CommercePromotions\GiftCard\GiftCardProductDiagnostics(
			$wpdb,
			$repo,
			$ledger,
			$generator,
			$reversal
		);
		$result = $diag->repair( $apply );
		if ( $apply ) {
			AdminNotice::success(
				sprintf(
					/* translators: 1: generated count, 2: voided count */
					__( 'Gift card product repair applied: %1$d generated, %2$d voided.', 'mp-commerce-promotions' ),
					(int) $result['generated'],
					(int) $result['voided']
				)
			);
		} else {
			AdminNotice::info(
				sprintf(
					/* translators: 1: generated count, 2: voided count */
					__( 'Gift card product repair preview: would generate %1$d, void %2$d.', 'mp-commerce-promotions' ),
					(int) $result['generated'],
					(int) $result['voided']
				)
			);
		}
	}

	private function render_gift_card_product_section(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$repo   = new \MP\CommercePromotions\GiftCard\GiftCardRepository( $wpdb );
		$tx     = new \MP\CommercePromotions\GiftCard\GiftCardTransactionRepository( $wpdb );
		$ledger = new \MP\CommercePromotions\GiftCard\GiftCardLedger( $repo, $tx );
		$generator = new \MP\CommercePromotions\GiftCard\GiftCardOrderGenerator(
			$ledger,
			null,
			$this->settings,
			$this->audit_logger
		);
		$reversal = new \MP\CommercePromotions\GiftCard\GiftCardOrderReversal( $ledger, $repo );
		$diag     = new \MP\CommercePromotions\GiftCard\GiftCardProductDiagnostics(
			$wpdb,
			$repo,
			$ledger,
			$generator,
			$reversal
		);
		$issues = $diag->analyze();

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Gift card products', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>' . esc_html__( 'Checks paid orders with gift-card products, order linkage, and cancellation hygiene.', 'mp-commerce-promotions' ) . '</p>';

		$catalog_count = \MP\CommercePromotions\GiftCard\GiftCardQaProductSetup::count_published_gift_card_products();
		if ( $catalog_count === 0 ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'No gift card products found.', 'mp-commerce-promotions' ) . '</strong> ';
			echo esc_html__(
				'Mark a simple or variation product with “This product sells a gift card”, or run the QA setup script on staging.',
				'mp-commerce-promotions'
			);
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=mp-commerce-promotions&tab=settings' ) ) . '">'
				. esc_html__( 'Settings', 'mp-commerce-promotions' ) . '</a>';
			$docs = defined( 'MP_COMMERCE_PROMOTIONS_URL' )
				? MP_COMMERCE_PROMOTIONS_URL . 'docs/GIFT_CARD_PRODUCTS.md'
				: '';
			if ( $docs !== '' ) {
				echo ' · <a href="' . esc_url( $docs ) . '" target="_blank" rel="noopener noreferrer">'
					. esc_html__( 'Gift card products docs', 'mp-commerce-promotions' ) . '</a>';
			}
			echo '</p>';
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				echo '<p class="description">' . esc_html__(
					'WP-CLI (staging): wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php',
					'mp-commerce-promotions'
				) . '</p>';
			}
			echo '</div>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: product count */
					_n( '%d published gift card product found.', '%d published gift card products found.', $catalog_count, 'mp-commerce-promotions' ),
					$catalog_count
				)
			) . '</p>';
		}

		$counts = array(
			__( 'Paid orders missing generation', 'mp-commerce-promotions' )       => count( $issues['paid_orders_missing_generation'] ),
			__( 'Gift cards missing order ID', 'mp-commerce-promotions' )          => count( $issues['product_cards_missing_order_id'] ),
			__( 'Cancelled orders with unused cards', 'mp-commerce-promotions' ) => count( $issues['cancelled_orders_active_unused_cards'] ),
		);
		echo '<ul>';
		foreach ( $counts as $label => $count ) {
			echo '<li>' . esc_html( $label ) . ': ' . esc_html( (string) $count ) . '</li>';
		}
		echo '</ul>';

		echo '<form method="post" style="margin-top:12px;">';
		wp_nonce_field( 'mp_cp_gift_card_product_repair' );
		echo '<input type="hidden" name="mp_cp_gift_card_product_repair" value="1" />';
		echo '<p><button type="submit" class="button" name="mp_cp_gift_card_product_repair_apply" value="0">'
			. esc_html__( 'Preview repair', 'mp-commerce-promotions' ) . '</button> ';
		echo '<button type="submit" class="button button-primary" name="mp_cp_gift_card_product_repair_apply" value="1">'
			. esc_html__( 'Apply repair', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';
	}

	private function handle_post_gift_card_delivery_repair(): void {
		if ( ! isset( $_POST['mp_cp_gift_card_delivery_repair'] ) ) {
			return;
		}

		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ),
				'mp_cp_gift_card_delivery_repair'
			)
		) {
			return;
		}

		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$apply = isset( $_POST['mp_cp_gift_card_delivery_repair_apply'] ) && (string) $_POST['mp_cp_gift_card_delivery_repair_apply'] === '1';
		$diag  = new \MP\CommercePromotions\GiftCard\GiftCardDeliveryDiagnostics( $wpdb );
		$result = $diag->repair( $apply );
		if ( $apply ) {
			AdminNotice::success(
				sprintf(
					/* translators: 1: plain_code removals, 2: legacy status marks */
					__( 'Gift card delivery repair applied: %1$d plain_code fields removed, %2$d legacy statuses marked.', 'mp-commerce-promotions' ),
					(int) $result['plain_code_removed'],
					(int) $result['legacy_status_marked']
				)
			);
		} else {
			AdminNotice::info(
				sprintf(
					/* translators: 1: plain_code removals, 2: legacy status marks */
					__( 'Gift card delivery repair preview: would remove %1$d plain_code fields, mark %2$d legacy statuses.', 'mp-commerce-promotions' ),
					(int) $result['plain_code_removed'],
					(int) $result['legacy_status_marked']
				)
			);
		}
	}

	private function render_gift_card_delivery_section(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$diag   = new \MP\CommercePromotions\GiftCard\GiftCardDeliveryDiagnostics( $wpdb );
		$issues = $diag->analyze();

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Gift card delivery security', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>' . esc_html__( 'Full gift card codes must not persist in order meta. Delivery status is tracked per generated card.', 'mp-commerce-promotions' ) . '</p>';

		$counts = array(
			__( 'Orders with legacy plain_code in meta', 'mp-commerce-promotions' ) => count( $issues['orders_with_plain_code'] ),
			__( 'Delivery failed', 'mp-commerce-promotions' )                     => count( $issues['delivery_failed'] ),
			__( 'Delivery disabled', 'mp-commerce-promotions' )                   => count( $issues['delivery_disabled'] ),
			__( 'Missing/unknown delivery status', 'mp-commerce-promotions' )   => count( $issues['missing_delivery_status'] ),
		);
		echo '<ul>';
		foreach ( $counts as $label => $count ) {
			echo '<li>' . esc_html( $label ) . ': ' . esc_html( (string) $count ) . '</li>';
		}
		echo '</ul>';

		if ( count( $issues['delivery_failed'] ) > 0 ) {
			echo '<p class="notice notice-error inline"><strong>' . esc_html__( 'Delivery failed', 'mp-commerce-promotions' ) . ':</strong> ';
			echo esc_html(
				sprintf(
					/* translators: %d: count */
					_n(
						'%d gift card email could not be delivered. Check SMTP and order delivery status.',
						'%d gift card emails could not be delivered. Check SMTP and order delivery status.',
						count( $issues['delivery_failed'] ),
						'mp-commerce-promotions'
					),
					count( $issues['delivery_failed'] )
				)
			);
			echo '</p>';
		}

		echo '<form method="post" style="margin-top:12px;">';
		wp_nonce_field( 'mp_cp_gift_card_delivery_repair' );
		echo '<input type="hidden" name="mp_cp_gift_card_delivery_repair" value="1" />';
		echo '<p><button type="submit" class="button" name="mp_cp_gift_card_delivery_repair_apply" value="0">'
			. esc_html__( 'Preview repair', 'mp-commerce-promotions' ) . '</button> ';
		echo '<button type="submit" class="button button-primary" name="mp_cp_gift_card_delivery_repair_apply" value="1">'
			. esc_html__( 'Apply repair', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';
	}

	private function render_gift_card_mail_section(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$mail = new \MP\CommercePromotions\GiftCard\GiftCardMailDiagnostics( $wpdb );
		$info = $mail->analyze();

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Gift card email deliverability', 'mp-commerce-promotions' ) . '</h2>';

		if ( ! empty( $info['wp_mail_likely_failing'] ) ) {
			echo '<motion class="notice notice-warning"><p><strong>' . esc_html__( 'Warning:', 'mp-commerce-promotions' ) . '</strong> ';
			echo esc_html__(
				'Recent gift card emails may not be sending. Configure SMTP before selling gift cards.',
				'mp-commerce-promotions'
			) . '</p></motion.div>';
		}

		echo '<ul>';
		echo '<li>' . esc_html__( 'Delivery emails enabled', 'mp-commerce-promotions' ) . ': '
			. ( ! empty( $info['delivery_email_enabled'] ) ? esc_html__( 'Yes', 'mp-commerce-promotions' ) : esc_html__( 'No', 'mp-commerce-promotions' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Recent delivery failures', 'mp-commerce-promotions' ) . ': '
			. esc_html( (string) (int) ( $info['recent_delivery_failed'] ?? 0 ) ) . '</li>';
		if ( ! empty( $info['last_mail_failure_at'] ) ) {
			echo '<li>' . esc_html__( 'Last wp_mail failure (gift cards)', 'mp-commerce-promotions' ) . ': '
				. esc_html( (string) $info['last_mail_failure_at'] ) . '</li>';
		}
		$smtp = (string) ( $info['smtp_plugin_hint'] ?? '' );
		if ( $smtp !== '' ) {
			echo '<li>' . esc_html__( 'SMTP plugin detected', 'mp-commerce-promotions' ) . ': ' . esc_html( $smtp ) . '</li>';
		}
		echo '</ul>';

		$summary = $info['settings_summary'] ?? array();
		if ( is_array( $summary ) && $summary !== array() ) {
			echo '<p><strong>' . esc_html__( 'Mail settings summary (no secrets)', 'mp-commerce-promotions' ) . '</strong></p><ul>';
			foreach ( $summary as $key => $value ) {
				$display = is_bool( $value ) ? ( $value ? __( 'Yes', 'mp-commerce-promotions' ) : __( 'No', 'mp-commerce-promotions' ) ) : (string) $value;
				echo '<li>' . esc_html( str_replace( '_', ' ', (string) $key ) ) . ': ' . esc_html( $display ) . '</li>';
			}
			echo '</ul>';
		}
	}

	private function handle_post_gift_card_scheduled_repair(): void {
		if ( ! isset( $_POST['mp_cp_gift_card_scheduled_repair'] ) ) {
			return;
		}

		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ),
				'mp_cp_gift_card_scheduled_repair'
			)
		) {
			return;
		}

		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$apply = isset( $_POST['mp_cp_gift_card_scheduled_repair_apply'] ) && (string) $_POST['mp_cp_gift_card_scheduled_repair_apply'] === '1';
		$repo  = new \MP\CommercePromotions\GiftCard\GiftCardRepository( $wpdb );
		$tx    = new \MP\CommercePromotions\GiftCard\GiftCardTransactionRepository( $wpdb );
		$ledger = new \MP\CommercePromotions\GiftCard\GiftCardLedger( $repo, $tx );
		$scheduler = new \MP\CommercePromotions\GiftCard\GiftCardScheduledDeliveryService( $ledger );
		$diag      = new \MP\CommercePromotions\GiftCard\GiftCardScheduledDiagnostics( $wpdb, $scheduler );
		$result    = $diag->repair( $apply );

		if ( $apply ) {
			AdminNotice::success(
				sprintf(
					/* translators: 1: fulfilled, 2: cancelled */
					__( 'Scheduled gift card repair: %1$d fulfilled, %2$d cancelled on unpaid orders.', 'mp-commerce-promotions' ),
					(int) $result['fulfilled'],
					(int) $result['cancelled']
				)
			);
		} else {
			AdminNotice::info(
				sprintf(
					/* translators: 1: would fulfill, 2: would cancel */
					__( 'Scheduled repair preview: would fulfill due deliveries site-wide; would cancel %2$d unpaid pending rows.', 'mp-commerce-promotions' ),
					(int) $result['fulfilled'],
					(int) $result['cancelled']
				)
			);
		}
	}

	private function render_gift_card_scheduled_section(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$repo      = new \MP\CommercePromotions\GiftCard\GiftCardRepository( $wpdb );
		$tx        = new \MP\CommercePromotions\GiftCard\GiftCardTransactionRepository( $wpdb );
		$ledger    = new \MP\CommercePromotions\GiftCard\GiftCardLedger( $repo, $tx );
		$scheduler = new \MP\CommercePromotions\GiftCard\GiftCardScheduledDeliveryService( $ledger );
		$diag      = new \MP\CommercePromotions\GiftCard\GiftCardScheduledDiagnostics( $wpdb, $scheduler );
		$issues    = $diag->analyze();

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Scheduled gift card delivery', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>' . esc_html__( 'Scheduled sends generate the gift card at delivery time (codes are not stored before email).', 'mp-commerce-promotions' ) . '</p>';

		$counts = array(
			__( 'Overdue scheduled deliveries', 'mp-commerce-promotions' )       => count( $issues['overdue'] ),
			__( 'Pending on unpaid/cancelled orders', 'mp-commerce-promotions' ) => count( $issues['unpaid_pending'] ),
			__( 'Invalid recipient email', 'mp-commerce-promotions' )          => count( $issues['invalid_recipient'] ),
			__( 'Failed scheduled rows', 'mp-commerce-promotions' )            => count( $issues['failed_scheduled'] ),
		);
		echo '<ul>';
		foreach ( $counts as $label => $count ) {
			echo '<li>' . esc_html( $label ) . ': ' . esc_html( (string) $count ) . '</li>';
		}
		echo '</ul>';

		echo '<form method="post" style="margin-top:12px;">';
		wp_nonce_field( 'mp_cp_gift_card_scheduled_repair' );
		echo '<input type="hidden" name="mp_cp_gift_card_scheduled_repair" value="1" />';
		echo '<p><button type="submit" class="button" name="mp_cp_gift_card_scheduled_repair_apply" value="0">'
			. esc_html__( 'Preview repair', 'mp-commerce-promotions' ) . '</button> ';
		echo '<button type="submit" class="button button-primary" name="mp_cp_gift_card_scheduled_repair_apply" value="1">'
			. esc_html__( 'Run due deliveries + cleanup', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';
	}

	private function render_gift_card_customer_section(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$diag   = new \MP\CommercePromotions\GiftCard\GiftCardCustomerDiagnostics( $wpdb );
		$issues = $diag->analyze();

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Gift card customer experience', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'Balance checker page missing', 'mp-commerce-promotions' ) . ': '
			. ( $issues['missing_balance_page'] ? esc_html__( 'Yes', 'mp-commerce-promotions' ) : esc_html__( 'No', 'mp-commerce-promotions' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Balance checker disabled', 'mp-commerce-promotions' ) . ': '
			. ( $issues['balance_checker_disabled'] ? esc_html__( 'Yes', 'mp-commerce-promotions' ) : esc_html__( 'No', 'mp-commerce-promotions' ) ) . '</li>';
		echo '<li>' . esc_html__( 'My Account endpoint disabled', 'mp-commerce-promotions' ) . ': '
			. ( $issues['my_account_disabled'] ? esc_html__( 'Yes', 'mp-commerce-promotions' ) : esc_html__( 'No', 'mp-commerce-promotions' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Cron off with pending scheduled sends', 'mp-commerce-promotions' ) . ': '
			. ( $issues['cron_disabled_with_pending'] ? esc_html__( 'Yes', 'mp-commerce-promotions' ) : esc_html__( 'No', 'mp-commerce-promotions' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Invalid email template slug', 'mp-commerce-promotions' ) . ': '
			. ( $issues['invalid_template'] ? esc_html__( 'Yes', 'mp-commerce-promotions' ) : esc_html__( 'No', 'mp-commerce-promotions' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Invalid accent color', 'mp-commerce-promotions' ) . ': '
			. ( $issues['invalid_accent'] ? esc_html__( 'Yes', 'mp-commerce-promotions' ) : esc_html__( 'No', 'mp-commerce-promotions' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Invalid sender email', 'mp-commerce-promotions' ) . ': '
			. ( $issues['invalid_sender_email'] ? esc_html__( 'Yes', 'mp-commerce-promotions' ) : esc_html__( 'No', 'mp-commerce-promotions' ) ) . '</li>';
		echo '</ul>';

		echo '<form method="post" style="margin-top:12px;">';
		wp_nonce_field( 'mp_cp_gift_card_customer_repair' );
		echo '<input type="hidden" name="mp_cp_gift_card_customer_repair" value="1" />';
		echo '<p><button type="submit" class="button button-primary" name="mp_cp_gift_card_customer_repair_apply" value="1">'
			. esc_html__( 'Create balance page & flush endpoints', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';
	}

	private function handle_post_gift_card_customer_repair(): void {
		if ( ! isset( $_POST['mp_cp_gift_card_customer_repair'] ) ) {
			return;
		}
		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ),
				'mp_cp_gift_card_customer_repair'
			)
		) {
			return;
		}
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}
		$diag = new \MP\CommercePromotions\GiftCard\GiftCardCustomerDiagnostics( $wpdb );
		$diag->repair( true );
		AdminNotice::success( __( 'Customer gift card pages/endpoints refreshed.', 'mp-commerce-promotions' ) );
	}

	private function render_gift_card_integrity_section(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$repo = new \MP\CommercePromotions\GiftCard\GiftCardRepository( $wpdb );
		$tx   = new \MP\CommercePromotions\GiftCard\GiftCardTransactionRepository( $wpdb );
		$diag = new \MP\CommercePromotions\GiftCard\GiftCardIntegrityDiagnostics(
			$wpdb,
			$repo,
			new \MP\CommercePromotions\GiftCard\GiftCardLedger( $repo, $tx )
		);
		$issues = $diag->analyze();

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Gift card integrity', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>' . esc_html__( 'Checks ledger consistency and status hygiene for stored-value gift cards.', 'mp-commerce-promotions' ) . '</p>';

		$counts = array(
			__( 'Negative balance', 'mp-commerce-promotions' )              => count( $issues['negative_balance'] ),
			__( 'Active with zero balance', 'mp-commerce-promotions' )      => count( $issues['active_zero_balance'] ),
			__( 'Balance mismatch', 'mp-commerce-promotions' )            => count( $issues['balance_mismatch'] ),
			__( 'Expired but active', 'mp-commerce-promotions' )            => count( $issues['expired_still_active'] ),
			__( 'Store credit without owner', 'mp-commerce-promotions' )  => count( $issues['store_credit_missing_owner'] ),
			__( 'Store credit unexpected code hash', 'mp-commerce-promotions' ) => count( $issues['store_credit_unexpected_code_hash'] ),
		);
		echo '<ul>';
		foreach ( $counts as $label => $count ) {
			echo '<li>' . esc_html( $label ) . ': ' . esc_html( (string) $count ) . '</li>';
		}
		echo '</ul>';

		echo '<form method="post" style="margin-top:12px;">';
		wp_nonce_field( 'mp_cp_gift_card_integrity_repair' );
		echo '<input type="hidden" name="mp_cp_gift_card_integrity_repair" value="1" />';
		echo '<p><button type="submit" class="button" name="mp_cp_gift_card_repair_apply" value="0">'
			. esc_html__( 'Preview repair', 'mp-commerce-promotions' ) . '</button> ';
		echo '<button type="submit" class="button button-primary" name="mp_cp_gift_card_repair_apply" value="1">'
			. esc_html__( 'Apply repair', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';
	}
}
