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
			'promotion_id'  => $snapshot->get_promotion_id(),
			'snapshot_type' => $snapshot->get_snapshot_type(),
			'snapshot_json' => wp_json_encode( $snapshot->get_snapshot_data() ),
			'notes'         => $snapshot->get_notes(),
			'created_by'    => $snapshot->get_created_by(),
			'created_at'    => $snapshot->get_created_at() ?? $now,
		);

		$notes_format       = $data['notes'] === null ? '%s' : '%s';
		$created_by_format  = $data['created_by'] === null ? '%s' : '%d';

		$formats = array( '%d', '%s', '%s', $notes_format, $created_by_format, '%s' );

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
