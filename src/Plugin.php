<?php
/**
 * Central plugin coordinator.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions;

use MP\CommercePromotions\Admin\AdminMenu;
use MP\CommercePromotions\Admin\AdminRouter;
use MP\CommercePromotions\Admin\DiagnosticsPage;
use MP\CommercePromotions\Admin\ReportsPage;
use MP\CommercePromotions\Admin\PromotionEditPage;
use MP\CommercePromotions\Admin\PromotionsPage;
use MP\CommercePromotions\Admin\GettingStartedPage;
use MP\CommercePromotions\Admin\SettingsPage;
use MP\CommercePromotions\Domain\AuditLogRepository;
use MP\CommercePromotions\Domain\AutomationRunRepository;
use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRepository;
use MP\CommercePromotions\Domain\PromotionCodeBatchRepository;
use MP\CommercePromotions\Domain\PromotionSnapshotRepository;
use MP\CommercePromotions\Domain\PromotionCodeFactory;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionFactory;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Admin\AdminProductionNotices;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\PlannerTelemetryRecorder;
use MP\CommercePromotions\Service\PromotionAutomationRunner;
use MP\CommercePromotions\Service\PromotionConcurrencyGuard;
use MP\CommercePromotions\Service\PromotionCronScheduler;
use MP\CommercePromotions\Service\PromotionDataRetentionService;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\PromotionSubsystemRecovery;
use MP\CommercePromotions\Service\PromotionCodeBatchGenerator;
use MP\CommercePromotions\Service\PromotionConflictAnalyzer;
use MP\CommercePromotions\Service\PromotionHealthMonitor;
use MP\CommercePromotions\Service\PromotionIntelligenceRecovery;
use MP\CommercePromotions\Service\PromotionPricingRecovery;
use MP\CommercePromotions\Service\PromotionOperationalRecovery;
use MP\CommercePromotions\Service\PromotionRecommendationEngine;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Service\SupportBundleExporter;
use MP\CommercePromotions\Service\UsageDiagnostics;
use MP\CommercePromotions\Woo\BlocksHookAudit;
use MP\CommercePromotions\Woo\CartContextBuilder;
use MP\CommercePromotions\Woo\CartPromotionApplier;
use MP\CommercePromotions\Woo\FreeGiftCartSynchronizer;
use MP\CommercePromotions\Woo\OrderPromotionRecorder;
use MP\CommercePromotions\Woo\PromotionCodeCouponBridge;
use MP\CommercePromotions\Woo\WooCommerceBridge;
use wpdb;

final class Plugin {

	private WooCommerceBridge $woo_bridge;

	private ?AdminMenu $admin_menu = null;

	private PromotionEvaluator $promotion_evaluator;

	private ?PromotionRepository $promotion_repository = null;

	private ?PromotionFactory $promotion_factory = null;

	private ?AuditLogRepository $audit_log_repository = null;

	private ?AuditLogger $audit_logger = null;

	private ?PromotionService $promotion_service = null;

	private ?RedemptionRepository $redemption_repository = null;

	private ?PromotionCodeRepository $promotion_code_repository = null;

	private Settings $settings;

	public function __construct() {
		$this->settings = new Settings();
		global $wpdb;
		if ( $wpdb instanceof wpdb ) {
			$this->promotion_repository = new PromotionRepository( $wpdb );
			$this->audit_log_repository   = new AuditLogRepository( $wpdb );
			$this->audit_logger           = new AuditLogger( $this->audit_log_repository );
			$this->promotion_factory      = new PromotionFactory();

			$this->promotion_service = new PromotionService(
				$this->promotion_repository,
				$this->promotion_factory,
				$this->audit_logger
			);

			$this->redemption_repository   = new RedemptionRepository( $wpdb );
			$this->promotion_code_repository = new PromotionCodeRepository( $wpdb );
		}

		$this->woo_bridge           = new WooCommerceBridge();
		$this->promotion_evaluator = new PromotionEvaluator( $this->redemption_repository );
	}

	public function init(): void {
		$this->woo_bridge->init();

		$cart_builder = null;
		if ( $this->woo_bridge->is_available() && $this->promotion_repository !== null && $this->promotion_code_repository !== null ) {
			$cart_builder = new CartContextBuilder( $this->redemption_repository );
			$this->woo_bridge->set_cart_context_builder( $cart_builder );

			$coupon_bridge = new PromotionCodeCouponBridge( $this->promotion_code_repository );
			$this->woo_bridge->set_promotion_code_coupon_bridge( $coupon_bridge );

			$gift_sync = new FreeGiftCartSynchronizer(
				$this->promotion_repository,
				$this->audit_logger
			);

			$profiler = new PromotionPerformanceProfiler();
			$planner  = new PromotionPlanner( $this->promotion_evaluator, null, $profiler );

			$telemetry_recorder = null;
			global $wpdb;
			if ( $wpdb instanceof wpdb && $this->settings->planner_telemetry_enabled() ) {
				$telemetry_recorder = new PlannerTelemetryRecorder(
					new PlannerTelemetryRepository( $wpdb ),
					$profiler
				);
			}

			$cart_applier = new CartPromotionApplier(
				$this->promotion_repository,
				$this->promotion_code_repository,
				$this->promotion_evaluator,
				$cart_builder,
				$this->settings,
				$planner,
				null,
				$gift_sync,
				$telemetry_recorder,
				$profiler
			);
			$this->woo_bridge->set_cart_promotion_applier( $cart_applier );

			add_action(
				'shutdown',
				static function (): void {
					AllocationContextCache::persist_metrics();
				}
			);

			AdminProductionNotices::register( $this->settings, $profiler );

			add_action(
				'woocommerce_before_cart',
				static function () use ( $profiler ): void {
					if ( ! $profiler->is_storefront_degraded() || ! function_exists( 'wc_add_notice' ) ) {
						return;
					}
					wc_add_notice(
						__( 'Some promotions may be temporarily unavailable. Your cart totals are still calculated normally.', 'mp-commerce-promotions' ),
						'notice'
					);
				}
			);

			if ( $this->redemption_repository !== null && $this->audit_logger !== null ) {
				$order_recorder = new OrderPromotionRecorder(
					$this->redemption_repository,
					$this->promotion_repository,
					$this->promotion_code_repository,
					$this->audit_logger
				);
				$this->woo_bridge->set_order_promotion_recorder( $order_recorder );
			}

			BlocksHookAudit::register( $this->settings );
		} elseif ( $this->woo_bridge->is_available() ) {
			$cart_builder = new CartContextBuilder( $this->redemption_repository );
			$this->woo_bridge->set_cart_context_builder( $cart_builder );
		}

		$promotions_page = null;
		if ( $this->promotion_repository !== null && $this->promotion_service !== null ) {
			global $wpdb;
			$rule_validator = new PromotionRuleValidator();
			$code_factory     = new PromotionCodeFactory();
			$batch_repository = ( $wpdb instanceof wpdb )
				? new PromotionCodeBatchRepository( $wpdb )
				: null;
			$batch_generator = null;
			if ( $this->audit_logger !== null && $batch_repository !== null && $this->promotion_code_repository !== null ) {
				$batch_generator = new PromotionCodeBatchGenerator(
					$this->promotion_code_repository,
					$code_factory,
					$batch_repository,
					$this->audit_logger
				);
			}
			$snapshot_service = null;
			if ( $wpdb instanceof wpdb && $this->audit_logger !== null ) {
				$snapshot_service = new \MP\CommercePromotions\Service\PromotionSnapshotService(
					$this->promotion_repository,
					new \MP\CommercePromotions\Domain\PromotionSnapshotRepository( $wpdb ),
					$this->audit_logger
				);
			}
			$edit_page        = new PromotionEditPage(
				$this->promotion_repository,
				$this->promotion_service,
				$cart_builder,
				$this->promotion_evaluator,
				$this->redemption_repository,
				$this->audit_log_repository,
				$rule_validator,
				$this->promotion_code_repository,
				$code_factory,
				$batch_repository,
				$batch_generator,
				$this->audit_logger,
				$snapshot_service
			);
			$health_monitor = new PromotionHealthMonitor(
				$this->promotion_repository,
				new PromotionConflictAnalyzer()
			);

			$campaign_bulk = null;
			$pricing_bulk  = null;
			if ( $wpdb instanceof wpdb && $this->audit_logger !== null ) {
				$campaign_bulk = new \MP\CommercePromotions\Service\PromotionBulkCampaignWorkflow(
					$this->promotion_repository,
					$this->audit_logger
				);
				$pricing_bulk = new \MP\CommercePromotions\Service\PromotionBulkPricingWorkflow(
					$this->promotion_repository,
					$this->audit_logger
				);
			}

			$promotions_page = new PromotionsPage(
				$this->promotion_repository,
				$this->promotion_service,
				$edit_page,
				$this->promotion_code_repository,
				$batch_repository,
				$this->redemption_repository,
				$rule_validator,
				$health_monitor,
				$campaign_bulk,
				$pricing_bulk
			);
		}

		$settings_page        = new SettingsPage( $this->settings );
		$getting_started_page = new GettingStartedPage( $this->settings );

		$profiler_global     = new PromotionPerformanceProfiler();
		$concurrency_global  = new PromotionConcurrencyGuard();
		$retention_global    = null;
		$cron_scheduler      = null;
		$subsystem_recovery  = null;
		global $wpdb;
		if ( $wpdb instanceof wpdb ) {
			$retention_global = new PromotionDataRetentionService(
				$wpdb,
				$this->settings,
				new AutomationRunRepository( $wpdb ),
				new PlannerTelemetryRepository( $wpdb ),
				new SimulationScenarioRepository( $wpdb )
			);
			$subsystem_recovery = new PromotionSubsystemRecovery(
				new PlannerTelemetryRepository( $wpdb ),
				new SimulationScenarioRepository( $wpdb )
			);
		}

		$diagnostics_page = null;
		if (
			$this->promotion_repository !== null
			&& $this->promotion_code_repository !== null
			&& $this->redemption_repository !== null
		) {
			$usage_diagnostics = new UsageDiagnostics(
				$this->promotion_repository,
				$this->promotion_code_repository,
				$this->redemption_repository,
				$this->audit_logger
			);
			$automation_runner = null;
			$health_monitor    = null;
			$operational_recovery = null;
			$automation_runs_repo = null;
			global $wpdb;
			if ( $wpdb instanceof wpdb && $this->promotion_service !== null ) {
				$automation_runs_repo = new AutomationRunRepository( $wpdb );
				$automation_runner    = new PromotionAutomationRunner(
					$this->promotion_service,
					$automation_runs_repo
				);
				$health_monitor = new PromotionHealthMonitor(
					$this->promotion_repository,
					new PromotionConflictAnalyzer()
				);
				$operational_recovery = new PromotionOperationalRecovery(
					$this->promotion_repository,
					$this->redemption_repository,
					new PlannerTelemetryRepository( $wpdb ),
					new PromotionSnapshotRepository( $wpdb ),
					$this->audit_logger
				);
			}

			$intelligence_recovery = null;
			$pricing_recovery      = null;
			$recommendation_engine = null;
			if ( $wpdb instanceof wpdb ) {
				$scenario_repo_intel = new SimulationScenarioRepository( $wpdb );
				$telemetry_intel     = new PlannerTelemetryRepository( $wpdb );
				$intelligence_recovery = new PromotionIntelligenceRecovery( $telemetry_intel, $scenario_repo_intel );
				$pricing_recovery      = new PromotionPricingRecovery(
					$this->promotion_repository,
					new PromotionSnapshotRepository( $wpdb )
				);
				$recommendation_engine = new PromotionRecommendationEngine(
					$this->promotion_repository,
					$this->redemption_repository,
					$telemetry_intel
				);
			}

			$support_exporter = null;
			if ( $wpdb instanceof wpdb && $this->promotion_code_repository !== null ) {
				$batch_repo_support = new PromotionCodeBatchRepository( $wpdb );
				$support_exporter     = new SupportBundleExporter(
					$this->settings,
					$this->promotion_repository,
					$this->redemption_repository,
					$this->promotion_code_repository,
					$batch_repo_support,
					$automation_runs_repo,
					$health_monitor
				);
			}

			if ( $wpdb instanceof wpdb && $automation_runner !== null && $retention_global !== null ) {
				$cron_scheduler = new PromotionCronScheduler(
					$this->settings,
					$automation_runner,
					$retention_global,
					$this->audit_logger
				);
				$cron_scheduler->register();
			}

			$diagnostics_page = new DiagnosticsPage(
				$usage_diagnostics,
				$this->settings,
				$this->promotion_service,
				$automation_runner,
				$health_monitor,
				$operational_recovery,
				$automation_runs_repo,
				$intelligence_recovery,
				$recommendation_engine,
				$pricing_recovery,
				$support_exporter,
				$profiler_global,
				$concurrency_global,
				$cron_scheduler,
				$retention_global,
				$subsystem_recovery,
				$this->audit_logger
			);
		}

		$reports_page = null;
		if ( $this->promotion_repository !== null && $this->redemption_repository !== null ) {
			global $wpdb;
			$telemetry_repo = ( $wpdb instanceof wpdb ) ? new PlannerTelemetryRepository( $wpdb ) : null;
			$automation_runs_repo = ( $wpdb instanceof wpdb ) ? new AutomationRunRepository( $wpdb ) : null;
			$health_monitor = new PromotionHealthMonitor(
				$this->promotion_repository,
				new PromotionConflictAnalyzer()
			);

			$scenario_repo = ( $wpdb instanceof wpdb ) ? new SimulationScenarioRepository( $wpdb ) : null;

			$promotion_reports = new PromotionReports(
				$this->promotion_repository,
				$this->redemption_repository,
				$telemetry_repo,
				$automation_runs_repo,
				$health_monitor,
				$scenario_repo
			);
			$reports_page = new ReportsPage(
				$promotion_reports,
				$this->promotion_repository,
				$this->settings,
				$scenario_repo
			);
		}

		$admin_router = new AdminRouter(
			$promotions_page,
			$settings_page,
			$getting_started_page,
			$diagnostics_page,
			$reports_page
		);

		if ( is_admin() ) {
			$admin_router->register_legacy_redirects();
		}

		$this->admin_menu = new AdminMenu( $this->woo_bridge, $admin_router );

		if ( is_admin() && $this->admin_menu !== null ) {
			$this->admin_menu->register();
		}
	}
}
