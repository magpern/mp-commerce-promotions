<?php
/**
 * Aggregate planner outcome counters per promotion (no PII).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Infrastructure\Database\TableName;
use wpdb;

final class PlannerTelemetryRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * @param array{
	 *     selected?: int,
	 *     skipped?: int,
	 *     blocked_by_group?: int,
	 *     blocked_by_cooldown?: int,
	 *     blocked_by_budget?: int,
	 *     blocked_by_exclusion?: int
	 * } $deltas
	 */
	public function increment( int $promotion_id, array $deltas ): void {
		if ( $promotion_id <= 0 ) {
			return;
		}

		$selected  = max( 0, (int) ( $deltas['selected'] ?? 0 ) );
		$skipped   = max( 0, (int) ( $deltas['skipped'] ?? 0 ) );
		$group     = max( 0, (int) ( $deltas['blocked_by_group'] ?? 0 ) );
		$cooldown  = max( 0, (int) ( $deltas['blocked_by_cooldown'] ?? 0 ) );
		$budget    = max( 0, (int) ( $deltas['blocked_by_budget'] ?? 0 ) );
		$exclusion = max( 0, (int) ( $deltas['blocked_by_exclusion'] ?? 0 ) );

		if ( $selected + $skipped + $group + $cooldown + $budget + $exclusion === 0 ) {
			return;
		}

		$table = $this->table();
		$now   = current_time( 'mysql' );

		$sql = "INSERT INTO {$table} (
			promotion_id,
			selected_count,
			skipped_count,
			blocked_by_group_count,
			blocked_by_cooldown_count,
			blocked_by_budget_count,
			blocked_by_exclusion_count,
			last_seen_at
		) VALUES ( %d, %d, %d, %d, %d, %d, %d, %s )
		ON DUPLICATE KEY UPDATE
			selected_count = selected_count + VALUES(selected_count),
			skipped_count = skipped_count + VALUES(skipped_count),
			blocked_by_group_count = blocked_by_group_count + VALUES(blocked_by_group_count),
			blocked_by_cooldown_count = blocked_by_cooldown_count + VALUES(blocked_by_cooldown_count),
			blocked_by_budget_count = blocked_by_budget_count + VALUES(blocked_by_budget_count),
			blocked_by_exclusion_count = blocked_by_exclusion_count + VALUES(blocked_by_exclusion_count),
			last_seen_at = VALUES(last_seen_at)";

		$this->wpdb->query(
			$this->wpdb->prepare(
				$sql,
				$promotion_id,
				$selected,
				$skipped,
				$group,
				$cooldown,
				$budget,
				$exclusion,
				$now
			)
		);
	}

	public function delete_all(): int {
		$deleted = $this->wpdb->query( "DELETE FROM {$this->table()}" );

		return false === $deleted ? 0 : (int) $deleted;
	}

	public function count_older_than( string $cutoff_mysql ): int {
		$table = $this->table();
		$value = DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$table} WHERE last_seen_at < %s",
			array( $cutoff_mysql )
		);

		return is_numeric( $value ) ? (int) $value : 0;
	}

	public function delete_older_than( string $cutoff_mysql ): int {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table} WHERE last_seen_at < %s",
				$cutoff_mysql
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * @return list<array{promotion_id: int, selected_count: int, skipped_count: int, blocked_by_group_count: int, blocked_by_cooldown_count: int, blocked_by_budget_count: int, blocked_by_exclusion_count: int, name: string}>
	 */
	public function top_by_column( string $column, int $limit = 10 ): array {
		$allowed = array(
			'selected_count',
			'skipped_count',
			'blocked_by_group_count',
			'blocked_by_cooldown_count',
			'blocked_by_budget_count',
			'blocked_by_exclusion_count',
		);
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}

		$limit = max( 1, min( 50, $limit ) );
		$t     = $this->table();
		$p     = Schema::promotions_table( $this->wpdb );

		$sql = "SELECT t.promotion_id, t.{$column} AS metric_value, t.skipped_count, t.blocked_by_group_count,
			t.blocked_by_cooldown_count, t.blocked_by_budget_count, t.blocked_by_exclusion_count,
			t.selected_count, p.name
			FROM {$t} t
			INNER JOIN {$p} p ON p.id = t.promotion_id
			WHERE t.{$column} > 0
			ORDER BY t.{$column} DESC, t.promotion_id ASC
			LIMIT %d";

		$rows = DbQuery::get_results( $this->wpdb, $sql, array( $limit ) );

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'promotion_id'              => (int) ( $row['promotion_id'] ?? 0 ),
				'name'                      => (string) ( $row['name'] ?? '' ),
				'selected_count'            => (int) ( $row['selected_count'] ?? 0 ),
				'skipped_count'             => (int) ( $row['skipped_count'] ?? 0 ),
				'blocked_by_group_count'    => (int) ( $row['blocked_by_group_count'] ?? 0 ),
				'blocked_by_cooldown_count' => (int) ( $row['blocked_by_cooldown_count'] ?? 0 ),
				'blocked_by_budget_count'   => (int) ( $row['blocked_by_budget_count'] ?? 0 ),
				'blocked_by_exclusion_count'=> (int) ( $row['blocked_by_exclusion_count'] ?? 0 ),
				'metric_value'              => (int) ( $row['metric_value'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * @return list<array{orchestration_group: string, promotion_count: int, total_skipped: int}>
	 */
	public function top_orchestration_groups_by_blocks( int $limit = 10 ): array {
		$limit = max( 1, min( 50, $limit ) );
		$p     = Schema::promotions_table( $this->wpdb );
		$t     = $this->table();

		$sql = "SELECT p.orchestration_group AS orchestration_group,
			COUNT(*) AS promotion_count,
			SUM(t.blocked_by_group_count) AS total_blocked
			FROM {$t} t
			INNER JOIN {$p} p ON p.id = t.promotion_id
			WHERE p.orchestration_group IS NOT NULL AND p.orchestration_group <> ''
			GROUP BY p.orchestration_group
			HAVING total_blocked > 0
			ORDER BY total_blocked DESC, promotion_count DESC
			LIMIT %d";

		$rows = DbQuery::get_results( $this->wpdb, $sql, array( $limit ) );

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$group = isset( $row['orchestration_group'] ) ? trim( (string) $row['orchestration_group'] ) : '';
			if ( $group === '' ) {
				continue;
			}
			$out[] = array(
				'orchestration_group' => $group,
				'promotion_count'     => (int) ( $row['promotion_count'] ?? 0 ),
				'total_blocked'       => (int) ( $row['total_blocked'] ?? 0 ),
			);
		}

		return $out;
	}

	private function table(): string {
		return TableName::assert_valid( Schema::planner_telemetry_table( $this->wpdb ) );
	}
}
