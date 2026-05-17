<?php
/**
 * Diagnostics recovery for simulation/forecast intelligence data.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRecord;
use MP\CommercePromotions\Engine\PlannerContextCache;
use MP\CommercePromotions\Service\SimulationScenario as ScenarioValue;

final class PromotionIntelligenceRecovery {

	private PlannerTelemetryRepository $telemetry;

	private SimulationScenarioRepository $scenarios;

	public function __construct(
		PlannerTelemetryRepository $telemetry,
		SimulationScenarioRepository $scenarios
	) {
		$this->telemetry  = $telemetry;
		$this->scenarios  = $scenarios;
	}

	/**
	 * @return array{dry_run: bool, deleted_rows: int}
	 */
	public function reset_telemetry( bool $dry_run = true ): array {
		if ( $dry_run ) {
			return array( 'dry_run' => true, 'deleted_rows' => 0 );
		}

		return array(
			'dry_run'      => false,
			'deleted_rows' => $this->telemetry->delete_all(),
		);
	}

	public function reset_forecast_cache(): void {
		PromotionForecastEngine::reset_cache();
	}

	/**
	 * @return array{dry_run: bool, scenarios_checked: int, valid: int, invalid: list<array{id: int, reason: string}>}
	 */
	public function validate_scenario_payloads( bool $dry_run = true ): array {
		$invalid  = array();
		$valid    = 0;
		$records  = $this->scenarios->find_all_active( 500 );

		foreach ( $records as $record ) {
			$scenario = ScenarioValue::from_array( $record->get_scenario_json() );
			$check    = $scenario->validate();
			if ( $check === true ) {
				++$valid;
			} else {
				$invalid[] = array(
					'id'     => (int) ( $record->get_id() ?? 0 ),
					'reason' => is_string( $check ) ? $check : 'invalid',
				);
			}
		}

		return array(
			'dry_run'           => $dry_run,
			'scenarios_checked' => count( $records ),
			'valid'             => $valid,
			'invalid'           => $invalid,
		);
	}

	/**
	 * @return array{dry_run: bool, repaired: int, archived: int}
	 */
	public function repair_malformed_simulation_rows( bool $dry_run = true ): array {
		$repaired = 0;
		$archived = 0;

		foreach ( $this->scenarios->find_all_active( 500 ) as $record ) {
			$id = $record->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			$json = $record->get_scenario_json();
			if ( $json === array() || ! isset( $json['items'] ) ) {
				if ( ! $dry_run ) {
					$this->scenarios->soft_delete( $id );
				}
				++$archived;
				continue;
			}

			$scenario = ScenarioValue::from_array( $json );
			if ( $scenario->validate() !== true ) {
				if ( ! $dry_run ) {
					$this->scenarios->soft_delete( $id );
				}
				++$archived;
			} else {
				++$repaired;
			}
		}

		return array(
			'dry_run'  => $dry_run,
			'repaired' => $repaired,
			'archived' => $archived,
		);
	}

	public function recalculate_simulation_metrics(): array {
		PlannerContextCache::reset_persisted_counters();
		return array(
			'planner_counters_reset' => true,
		);
	}
}
