<?php
/**
 * Optional WP-Cron hooks for automation and cleanup (disabled by default).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Engine\PlannerContextCache;

final class PromotionCronScheduler {

	public const HOOK_HOURLY = 'mp_cp_cron_hourly_maintenance';

	public const HOOK_DAILY = 'mp_cp_cron_daily_cleanup';

	private Settings $settings;

	private ?PromotionAutomationRunner $automation;

	private ?PromotionDataRetentionService $retention;

	private ?AuditLogger $audit;

	public function __construct(
		Settings $settings,
		?PromotionAutomationRunner $automation = null,
		?PromotionDataRetentionService $retention = null,
		?AuditLogger $audit = null
	) {
		$this->settings   = $settings;
		$this->automation = $automation;
		$this->retention  = $retention;
		$this->audit      = $audit;
	}

	public function register(): void {
		add_action( self::HOOK_HOURLY, array( $this, 'run_hourly' ) );
		add_action( self::HOOK_DAILY, array( $this, 'run_daily' ) );

		if ( $this->settings->cron_automation_enabled() ) {
			$this->schedule_events();
		}
	}

	public static function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( self::HOOK_HOURLY );
		wp_clear_scheduled_hook( self::HOOK_DAILY );
	}

	public function reschedule(): void {
		self::clear_scheduled_events();
		if ( $this->settings->cron_automation_enabled() ) {
			$this->schedule_events();
		}
	}

	public function run_hourly(): void {
		if ( ! $this->settings->cron_automation_enabled() ) {
			return;
		}

		if ( $this->settings->automation_emergency_stop() || $this->settings->automation_manual_only() ) {
			return;
		}

		if ( $this->automation === null ) {
			return;
		}

		$guard = new PromotionConcurrencyGuard();
		if ( ! $guard->acquire_automation_lock() ) {
			return;
		}

		try {
			$summary = $this->automation->run_all( 0 );
			if ( $this->audit !== null ) {
				$this->audit->log(
					'promotion.automation_cron_run',
					null,
					array(
						'run_type' => PromotionAutomationRunner::RUN_TYPE_ALL,
						'summary'  => $summary,
					)
				);
			}
		} finally {
			$guard->release_automation_lock();
		}
	}

	public function run_daily(): void {
		if ( ! $this->settings->cron_automation_enabled() && $this->retention === null ) {
			return;
		}

		if ( $this->retention !== null ) {
			$this->retention->run_daily_cleanup( true );
		}

		PromotionForecastEngine::reset_cache();
		PlannerContextCache::reset_persisted_counters();
	}

	private function schedule_events(): void {
		if ( ! wp_next_scheduled( self::HOOK_HOURLY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_HOURLY );
		}
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK_DAILY );
		}
	}
}
