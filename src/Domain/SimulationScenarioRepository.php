<?php
/**
 * Persistence for saved simulation scenarios.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;
use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Infrastructure\Database\TableName;
use wpdb;

final class SimulationScenarioRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function insert( SimulationScenarioRecord $record ): int {
		$json = wp_json_encode( $record->get_scenario_json() );
		if ( ! is_string( $json ) ) {
			$json = '{}';
		}

		$data = array(
			'name'          => $record->get_name(),
			'scenario_json' => $json,
			'status'        => $record->get_status(),
			'created_by'    => $record->get_created_by(),
			'created_at'    => current_time( 'mysql' ),
			'run_count'     => $record->get_run_count(),
		);

		$inserted = $this->wpdb->insert(
			$this->table(),
			$data,
			array( '%s', '%s', '%s', '%d', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $this->wpdb->insert_id;
	}

	public function find( int $id ): ?SimulationScenarioRecord {
		if ( $id <= 0 ) {
			return null;
		}

		$row = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$this->table()} WHERE id = %d AND status = %s LIMIT 1",
			array( $id, SimulationScenarioRecord::STATUS_ACTIVE )
		);

		return $this->row_to_record( $row );
	}

	/**
	 * @return list<SimulationScenarioRecord>
	 */
	public function find_latest( int $limit = 20 ): array {
		$limit = max( 1, min( 100, $limit ) );

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$this->table()} WHERE status = %s ORDER BY COALESCE(last_run_at, created_at) DESC, id DESC LIMIT %d",
			array( SimulationScenarioRecord::STATUS_ACTIVE, $limit )
		);

		return $this->rows_to_records( $rows );
	}

	public function record_run( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$now = current_time( 'mysql' );

		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table()} SET run_count = run_count + 1, last_run_at = %s WHERE id = %d AND status = %s",
				$now,
				$id,
				SimulationScenarioRecord::STATUS_ACTIVE
			)
		);

		return false !== $updated;
	}

	public function soft_delete( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$this->table(),
			array( 'status' => SimulationScenarioRecord::STATUS_ARCHIVED ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated && $updated > 0;
	}

	public function duplicate( int $id, ?int $actor_user_id = null ): int {
		$source = $this->find( $id );
		if ( $source === null ) {
			return 0;
		}

		$name = sprintf(
			/* translators: %s: scenario name */
			__( 'Copy of %s', 'mp-commerce-promotions' ),
			$source->get_name()
		);

		$record = new SimulationScenarioRecord(
			null,
			$name,
			$source->get_scenario_json(),
			SimulationScenarioRecord::STATUS_ACTIVE,
			$actor_user_id,
			current_time( 'mysql' ),
			null,
			0
		);

		return $this->insert( $record );
	}

	/**
	 * @return list<SimulationScenarioRecord>
	 */
	public function find_all_active( int $limit = 500 ): array {
		$limit = max( 1, min( 500, $limit ) );

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$this->table()} WHERE status = %s ORDER BY id ASC LIMIT %d",
			array( SimulationScenarioRecord::STATUS_ACTIVE, $limit )
		);

		return $this->rows_to_records( $rows );
	}

	private function table(): string {
		return TableName::assert_valid( Schema::simulation_scenarios_table( $this->wpdb ) );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<SimulationScenarioRecord>
	 */
	private function rows_to_records( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$record = $this->row_to_record( $row );
			if ( $record instanceof SimulationScenarioRecord ) {
				$out[] = $record;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_record( ?array $row ): ?SimulationScenarioRecord {
		if ( $row === null ) {
			return null;
		}

		try {
			return SimulationScenarioRecord::from_row( $row );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}
}
