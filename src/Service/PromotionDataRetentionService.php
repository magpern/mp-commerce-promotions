<?php
/**
 * Telemetry and history cleanup (retention policies).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\AutomationRunRepository;
use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRepository;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Engine\PlannerContextCache;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;
use wpdb;

final class PromotionDataRetentionService {

	private wpdb $wpdb;

	private Settings $settings;

	private ?AutomationRunRepository $automation_runs;

	private ?PlannerTelemetryRepository $telemetry;

	private ?SimulationScenarioRepository $scenarios;

	public function __construct(
		wpdb $wpdb,
		Settings $settings,
		?AutomationRunRepository $automation_runs = null,
		?PlannerTelemetryRepository $telemetry = null,
		?SimulationScenarioRepository $scenarios = null
	) {
		$this->wpdb            = $wpdb;
		$this->settings        = $settings;
		$this->automation_runs = $automation_runs;
		$this->telemetry       = $telemetry;
		$this->scenarios       = $scenarios;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function storage_estimates(): array {
		global $wpdb;
		$prefix = $wpdb->prefix;

		return array(
			'automation_runs'      => $this->table_row_count( $prefix . 'mp_cp_automation_runs' ),
			'planner_telemetry'    => $this->table_row_count( $prefix . 'mp_cp_planner_telemetry' ),
			'simulation_scenarios' => $this->table_row_count( $prefix . 'mp_cp_simulation_scenarios' ),
			'redemptions'          => $this->table_row_count( $prefix . 'mp_cp_redemptions' ),
			'promotions'           => $this->table_row_count( $prefix . 'mp_cp_promotions' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function run_daily_cleanup( bool $dry_run = true ): array {
		$days   = $this->settings->telemetry_retention_days();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$result = array(
			'dry_run'                => $dry_run,
			'cutoff'                 => $cutoff,
			'automation_runs'        => 0,
			'scenarios_archived'     => 0,
			'forecast_cache_reset'   => false,
			'planner_counters_reset' => false,
		);

		if ( ! $dry_run ) {
			$result['automation_runs']    = $this->purge_old_automation_runs( $cutoff );
			$result['scenarios_archived'] = $this->archive_old_scenarios( $cutoff );
			PromotionForecastEngine::reset_cache();
			PricingCompatibilityAnalyzer::reset_cache();
			AllocationContextCache::reset_persisted_metrics();
			PlannerContextCache::reset_persisted_counters();
			$result['forecast_cache_reset']   = true;
			$result['planner_counters_reset'] = true;
		} else {
			$result['automation_runs']    = $this->count_old_automation_runs( $cutoff );
			$result['scenarios_archived'] = $this->count_old_scenarios( $cutoff );
		}

		return $result;
	}

	private function table_row_count( string $table ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		$value = $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function purge_old_automation_runs( string $cutoff ): int {
		if ( $this->automation_runs === null ) {
			return 0;
		}

		$table = $this->wpdb->prefix . 'mp_cp_automation_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM `{$table}` WHERE created_at < %s",
				$cutoff
			)
		);
	}

	private function count_old_automation_runs( string $cutoff ): int {
		$table = $this->wpdb->prefix . 'mp_cp_automation_runs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE created_at < %s",
				$cutoff
			)
		);

		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function archive_old_scenarios( string $cutoff ): int {
		$table = $this->wpdb->prefix . 'mp_cp_simulation_scenarios';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE `{$table}` SET status = %s WHERE created_at < %s AND status = %s",
				'archived',
				$cutoff,
				'active'
			)
		);
	}

	private function count_old_scenarios( string $cutoff ): int {
		$table = $this->wpdb->prefix . 'mp_cp_simulation_scenarios';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE created_at < %s AND status = %s",
				$cutoff,
				'active'
			)
		);

		return is_numeric( $value ) ? (int) $value : 0;
	}
}
