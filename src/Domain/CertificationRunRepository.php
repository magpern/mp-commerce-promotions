<?php
/**
 * Persistence for certification runs.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use wpdb;

final class CertificationRunRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function insert( CertificationRun $run ): int {
		$meta = wp_json_encode( $run->get_metadata() );
		if ( ! is_string( $meta ) ) {
			$meta = '{}';
		}

		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'certification_type' => $run->get_certification_type(),
				'status'             => $run->get_status(),
				'environment'        => $run->get_environment(),
				'payment_gateway'    => $run->get_payment_gateway(),
				'operator_notes'     => $run->get_operator_notes(),
				'metadata_json'      => $meta,
				'certified_at'       => $run->get_certified_at(),
				'created_by'         => $run->get_created_by(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		$new_id = (int) $this->wpdb->insert_id;

		return $new_id > 0 ? $new_id : 0;
	}

	public function find_latest_by_type( string $type ): ?CertificationRun {
		if ( ! in_array( $type, CertificationRun::allowed_types(), true ) ) {
			return null;
		}

		$row = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$this->table()} WHERE certification_type = %s ORDER BY certified_at DESC, id DESC LIMIT 1",
			array( $type )
		);

		return $this->row_to_run( $row );
	}

	/**
	 * @return list<CertificationRun>
	 */
	public function find_latest_per_type(): array {
		$out = array();
		foreach ( CertificationRun::allowed_types() as $type ) {
			$run = $this->find_latest_by_type( $type );
			if ( $run instanceof CertificationRun ) {
				$out[] = $run;
			}
		}

		return $out;
	}

	/**
	 * @return list<CertificationRun>
	 */
	public function find_stale( int $older_than_days = 30, int $limit = 20 ): array {
		$days  = max( 1, min( 365, $older_than_days ) );
		$limit = max( 1, min( 100, $limit ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT t1.* FROM {$this->table()} t1
			INNER JOIN (
				SELECT certification_type, MAX(certified_at) AS max_at
				FROM {$this->table()}
				GROUP BY certification_type
			) t2 ON t1.certification_type = t2.certification_type AND t1.certified_at = t2.max_at
			WHERE t1.certified_at < %s
			ORDER BY t1.certified_at ASC
			LIMIT %d",
			array( $cutoff, $limit )
		);

		$out = array();
		foreach ( $rows as $row ) {
			$run = $this->row_to_run( is_array( $row ) ? $row : null );
			if ( $run instanceof CertificationRun ) {
				$out[] = $run;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_run( ?array $row ): ?CertificationRun {
		if ( $row === null || $row === array() ) {
			return null;
		}

		$meta = array();
		if ( ! empty( $row['metadata_json'] ) && is_string( $row['metadata_json'] ) ) {
			$decoded = json_decode( $row['metadata_json'], true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}

		return new CertificationRun(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(string) ( $row['certification_type'] ?? '' ),
			(string) ( $row['status'] ?? CertificationRun::STATUS_FAILED ),
			isset( $row['environment'] ) ? (string) $row['environment'] : null,
			isset( $row['payment_gateway'] ) ? (string) $row['payment_gateway'] : null,
			isset( $row['operator_notes'] ) ? (string) $row['operator_notes'] : null,
			$meta,
			(string) ( $row['certified_at'] ?? current_time( 'mysql' ) ),
			isset( $row['created_by'] ) ? (int) $row['created_by'] : null
		);
	}

	public function delete_older_than( string $cutoff_mysql ): int {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table} WHERE certified_at < %s",
				$cutoff_mysql
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	private function table(): string {
		return Schema::certification_runs_table( $this->wpdb );
	}
}
