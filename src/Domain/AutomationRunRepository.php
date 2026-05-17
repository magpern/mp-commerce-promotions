<?php
/**
 * Persistence for automation run history.
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

final class AutomationRunRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function insert( AutomationRun $run ): int {
		$summary_json = wp_json_encode( $run->get_summary() );
		if ( ! is_string( $summary_json ) ) {
			$summary_json = '{}';
		}

		$data = array(
			'run_type'        => $run->get_run_type(),
			'status'          => $run->get_status(),
			'summary_json'    => $summary_json,
			'warnings_count'  => $run->get_warnings_count(),
			'errors_count'    => $run->get_errors_count(),
			'created_at'      => $run->get_created_at(),
			'finished_at'     => $run->get_finished_at(),
		);

		$finished_format = $data['finished_at'] === null ? '%s' : '%s';

		$inserted = $this->wpdb->insert(
			$this->table(),
			$data,
			array( '%s', '%s', '%s', '%d', '%d', '%s', $finished_format )
		);

		if ( false === $inserted ) {
			return 0;
		}

		$new_id = (int) $this->wpdb->insert_id;

		return $new_id > 0 ? $new_id : 0;
	}

	public function find( int $id ): ?AutomationRun {
		if ( $id <= 0 ) {
			return null;
		}

		$row = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1",
			array( $id )
		);

		return $this->row_to_run( $row );
	}

	/**
	 * @return list<AutomationRun>
	 */
	public function find_latest( int $limit = 20 ): array {
		$limit = max( 1, min( 100, $limit ) );

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$this->table()} ORDER BY created_at DESC, id DESC LIMIT %d",
			array( $limit )
		);

		return $this->rows_to_runs( $rows );
	}

	private function table(): string {
		return TableName::assert_valid( Schema::automation_runs_table( $this->wpdb ) );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<AutomationRun>
	 */
	private function rows_to_runs( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$run = $this->row_to_run( $row );
			if ( $run instanceof AutomationRun ) {
				$out[] = $run;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_run( ?array $row ): ?AutomationRun {
		if ( $row === null ) {
			return null;
		}

		try {
			return AutomationRun::from_row( $row );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}
}
