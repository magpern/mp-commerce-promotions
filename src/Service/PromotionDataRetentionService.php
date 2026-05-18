<?php
/**
 * Telemetry and history cleanup (retention policies).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\AutomationRunRepository;
use MP\CommercePromotions\Domain\CertificationRunRepository;
use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\PromotionSnapshotRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRepository;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Engine\PlannerContextCache;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;
use wpdb;

final class PromotionDataRetentionService {

	private wpdb $wpdb;

	private Settings $settings;

	private ?AutomationRunRepository $automation_runs;

	private ?PlannerTelemetryRepository $telemetry;

	private ?SimulationScenarioRepository $scenarios;

	private ?CertificationRunRepository $certification_runs;

	private ?PromotionSnapshotRepository $snapshots;

	public function __construct(
		wpdb $wpdb,
		Settings $settings,
		?AutomationRunRepository $automation_runs = null,
		?PlannerTelemetryRepository $telemetry = null,
		?SimulationScenarioRepository $scenarios = null,
		?CertificationRunRepository $certification_runs = null,
		?PromotionSnapshotRepository $snapshots = null
	) {
		$this->wpdb                = $wpdb;
		$this->settings            = $settings;
		$this->automation_runs     = $automation_runs;
		$this->telemetry           = $telemetry;
		$this->scenarios           = $scenarios;
		$this->certification_runs  = $certification_runs;
		$this->snapshots           = $snapshots;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function storage_estimates(): array {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$days   = $this->settings->telemetry_retention_days();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return array(
			'retention_days'              => $days,
			'cutoff'                      => $cutoff,
			'automation_runs'             => $this->table_row_count( $prefix . 'mp_cp_automation_runs' ),
			'automation_runs_stale'       => $this->count_rows_before( $prefix . 'mp_cp_automation_runs', 'created_at', $cutoff ),
			'planner_telemetry'           => $this->table_row_count( $prefix . 'mp_cp_planner_telemetry' ),
			'planner_telemetry_stale'     => $this->count_telemetry_stale( $cutoff ),
			'simulation_scenarios'        => $this->table_row_count( $prefix . 'mp_cp_simulation_scenarios' ),
			'scenarios_stale_active'      => $this->count_old_scenarios( $cutoff ),
			'certification_runs'          => $this->table_row_count( $prefix . 'mp_cp_certification_runs' ),
			'certification_runs_stale'    => $this->count_rows_before( $prefix . 'mp_cp_certification_runs', 'certified_at', $cutoff ),
			'promotion_snapshots'         => $this->table_row_count( $prefix . 'mp_cp_promotion_snapshots' ),
			'snapshots_stale'             => $this->count_rows_before( $prefix . 'mp_cp_promotion_snapshots', 'created_at', $cutoff ),
			'redemptions'                 => $this->table_row_count( $prefix . 'mp_cp_redemptions' ),
			'promotions'                  => $this->table_row_count( $prefix . 'mp_cp_promotions' ),
			'profiler_option_bytes'       => $this->option_size_estimate( PromotionPerformanceProfiler::OPTION_AGGREGATES ),
			'anomaly_option_bytes'        => $this->option_size_estimate( RuntimeAnomalyDetector::OPTION_COUNTERS ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function run_daily_cleanup( bool $dry_run = true ): array {
		$days   = $this->settings->telemetry_retention_days();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$result = array(
			'dry_run'                   => $dry_run,
			'cutoff'                    => $cutoff,
			'automation_runs'           => 0,
			'planner_telemetry_rows'    => 0,
			'scenarios_archived'        => 0,
			'certification_runs'        => 0,
			'snapshots_pruned'          => 0,
			'profiler_aggregates_reset' => false,
			'anomaly_counters_reset'    => false,
			'forecast_cache_reset'      => false,
			'planner_counters_reset'    => false,
		);

		if ( $dry_run ) {
			$result['automation_runs']        = $this->count_old_automation_runs( $cutoff );
			$result['planner_telemetry_rows'] = $this->count_telemetry_stale( $cutoff );
			$result['scenarios_archived']     = $this->count_old_scenarios( $cutoff );
			$result['certification_runs']     = $this->count_rows_before( $this->wpdb->prefix . 'mp_cp_certification_runs', 'certified_at', $cutoff );
			$result['snapshots_pruned']       = $this->count_rows_before( $this->wpdb->prefix . 'mp_cp_promotion_snapshots', 'created_at', $cutoff );

			return $result;
		}

		$result['automation_runs']        = $this->purge_old_automation_runs( $cutoff );
		$result['planner_telemetry_rows'] = $this->purge_old_planner_telemetry( $cutoff );
		$result['scenarios_archived']     = $this->archive_old_scenarios( $cutoff );
		$result['certification_runs']     = $this->purge_old_certification_runs( $cutoff );
		$result['snapshots_pruned']       = $this->purge_old_snapshots( $cutoff );

		PromotionForecastEngine::reset_cache();
		PricingCompatibilityAnalyzer::reset_cache();
		AllocationContextCache::reset_persisted_metrics();
		PlannerContextCache::reset_persisted_counters();
		( new PromotionPerformanceProfiler() )->reset_aggregates();
		( new RuntimeAnomalyDetector() )->reset_counters();

		$result['forecast_cache_reset']      = true;
		$result['planner_counters_reset']    = true;
		$result['profiler_aggregates_reset'] = true;
		$result['anomaly_counters_reset']    = true;

		return $result;
	}

	private function table_row_count( string $table ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		$value = $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function count_rows_before( string $table, string $column, string $cutoff ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` < %s",
				$cutoff
			)
		);

		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function count_telemetry_stale( string $cutoff ): int {
		if ( $this->telemetry === null ) {
			return $this->count_rows_before( $this->wpdb->prefix . 'mp_cp_planner_telemetry', 'last_seen_at', $cutoff );
		}

		return $this->telemetry->count_older_than( $cutoff );
	}

	private function purge_old_planner_telemetry( string $cutoff ): int {
		if ( $this->telemetry !== null ) {
			return $this->telemetry->delete_older_than( $cutoff );
		}

		$table = $this->wpdb->prefix . 'mp_cp_planner_telemetry';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM `{$table}` WHERE last_seen_at < %s",
				$cutoff
			)
		);
	}

	private function purge_old_certification_runs( string $cutoff ): int {
		if ( $this->certification_runs !== null ) {
			return $this->certification_runs->delete_older_than( $cutoff );
		}

		$table = Schema::certification_runs_table( $this->wpdb );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM `{$table}` WHERE certified_at < %s",
				$cutoff
			)
		);
	}

	private function purge_old_snapshots( string $cutoff ): int {
		if ( $this->snapshots !== null ) {
			return $this->snapshots->prune_older_than( $cutoff, 5 );
		}

		return 0;
	}

	private function option_size_estimate( string $option ): int {
		$value = get_option( $option, array() );
		$json  = wp_json_encode( $value );

		return is_string( $json ) ? strlen( $json ) : 0;
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
