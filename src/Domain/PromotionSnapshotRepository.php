<?php
/**
 * Persistence for promotion rollback snapshots.
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

final class PromotionSnapshotRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function insert( PromotionSnapshot $snapshot ): int {
		$now = current_time( 'mysql' );

		$data = array(
			'promotion_id'    => $snapshot->get_promotion_id(),
			'snapshot_type'   => $snapshot->get_snapshot_type(),
			'snapshot_label'  => $snapshot->get_snapshot_label(),
			'snapshot_source' => $snapshot->get_snapshot_source(),
			'snapshot_json'   => wp_json_encode( $snapshot->get_snapshot_data() ),
			'notes'           => $snapshot->get_notes(),
			'created_by'      => $snapshot->get_created_by(),
			'created_at'      => $snapshot->get_created_at() ?? $now,
		);

		$notes_format       = $data['notes'] === null ? '%s' : '%s';
		$created_by_format  = $data['created_by'] === null ? '%s' : '%d';
		$label_format       = $data['snapshot_label'] === null ? '%s' : '%s';
		$source_format      = $data['snapshot_source'] === null ? '%s' : '%s';

		$formats = array( '%d', '%s', $label_format, $source_format, '%s', $notes_format, $created_by_format, '%s' );

		$inserted = $this->wpdb->insert( $this->table(), $data, $formats );
		if ( false === $inserted ) {
			return 0;
		}

		$new_id = (int) $this->wpdb->insert_id;

		return $new_id > 0 ? $new_id : 0;
	}

	public function find( int $id ): ?PromotionSnapshot {
		if ( $id <= 0 ) {
			return null;
		}

		$row = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1",
			array( $id )
		);

		return $this->row_to_snapshot( $row );
	}

	/**
	 * @return list<PromotionSnapshot>
	 */
	/**
	 * @return list<PromotionSnapshot>
	 */
	public function find_latest_for_promotion( int $promotion_id, int $limit = 10 ): array {
		$limit = max( 1, min( 50, $limit ) );
		if ( $promotion_id <= 0 ) {
			return array();
		}

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$this->table()} WHERE promotion_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
			array( $promotion_id, $limit )
		);

		return $this->rows_to_snapshots( $rows );
	}

	public function prune_older_than( string $cutoff_mysql, int $keep_per_promotion = 5 ): int {
		$keep_per_promotion = max( 1, min( 20, $keep_per_promotion ) );
		$table              = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$promotion_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT promotion_id FROM {$table} WHERE created_at < %s",
				$cutoff_mysql
			)
		);

		if ( ! is_array( $promotion_ids ) || $promotion_ids === array() ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $promotion_ids as $promotion_id ) {
			$pid = (int) $promotion_id;
			if ( $pid <= 0 ) {
				continue;
			}

			$keep_rows = DbQuery::get_results(
				$this->wpdb,
				"SELECT id FROM {$table} WHERE promotion_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
				array( $pid, $keep_per_promotion )
			);
			$keep_ids = array();
			foreach ( $keep_rows as $row ) {
				if ( isset( $row['id'] ) ) {
					$keep_ids[] = (int) $row['id'];
				}
			}
			if ( $keep_ids === array() ) {
				continue;
			}

			$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
			$params       = array_merge( array( $pid, $cutoff_mysql ), $keep_ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM {$table} WHERE promotion_id = %d AND created_at < %s AND id NOT IN ({$placeholders})",
					$params
				)
			);
			if ( false !== $result ) {
				$deleted += (int) $result;
			}
		}

		return $deleted;
	}

	public function count_for_promotion( int $promotion_id, ?string $snapshot_type = null ): int {
		if ( $promotion_id <= 0 ) {
			return 0;
		}

		$sql    = "SELECT COUNT(*) FROM {$this->table()} WHERE promotion_id = %d";
		$params = array( $promotion_id );

		if ( $snapshot_type !== null && trim( $snapshot_type ) !== '' ) {
			$sql     .= ' AND snapshot_type = %s';
			$params[] = $snapshot_type;
		}

		$count = DbQuery::get_var( $this->wpdb, $sql, $params );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	private function table(): string {
		return TableName::assert_valid( Schema::promotion_snapshots_table( $this->wpdb ) );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<PromotionSnapshot>
	 */
	private function rows_to_snapshots( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$snapshot = $this->row_to_snapshot( $row );
			if ( $snapshot instanceof PromotionSnapshot ) {
				$out[] = $snapshot;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_snapshot( ?array $row ): ?PromotionSnapshot {
		if ( $row === null ) {
			return null;
		}

		try {
			return PromotionSnapshot::from_row( $row );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}
}
