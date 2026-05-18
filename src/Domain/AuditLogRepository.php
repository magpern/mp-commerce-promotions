<?php
/**
 * Append-only persistence for audit log rows.
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

final class AuditLogRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Insert an audit log entry; returns new id or 0 on failure.
	 */
	public function insert( AuditLogEntry $entry ): int {
		$now = current_time( 'mysql' );

		$data = array(
			'promotion_id'  => $entry->get_promotion_id(),
			'actor_user_id' => $entry->get_actor_user_id(),
			'action'        => $entry->get_action(),
			'context'       => $this->encode_json( $entry->get_context() ),
			'ip_hash'       => $entry->get_ip_hash(),
			'created_at'    => $entry->get_created_at() ?? $now,
		);

		$formats = array(
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
		);

		$inserted = $this->wpdb->insert(
			$this->audit_log_table(),
			$data,
			$formats
		);

		if ( false === $inserted ) {
			return 0;
		}

		$new_id = (int) $this->wpdb->insert_id;

		return $new_id > 0 ? $new_id : 0;
	}

	/**
	 * @return list<AuditLogEntry>
	 */
	public function find_for_promotion( int $promotion_id, int $limit = 50 ): array {
		if ( $promotion_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );
		$table = $this->audit_log_table();

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE promotion_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
			array( $promotion_id, $limit )
		);

		$out = array();
		foreach ( $rows as $row ) {
			try {
				$out[] = AuditLogEntry::from_array( $row );
			} catch ( InvalidArgumentException $e ) {
				continue;
			}
		}

		return $out;
	}

	/**
	 * @param list<string> $actions
	 * @return list<AuditLogEntry>
	 */
	public function find_actions_since( array $actions, string $since_mysql, int $limit = 200 ): array {
		if ( $actions === array() ) {
			return array();
		}

		$limit = max( 1, min( 1000, $limit ) );
		$table = $this->audit_log_table();
		$placeholders = implode( ',', array_fill( 0, count( $actions ), '%s' ) );
		$params       = array_merge( $actions, array( $since_mysql, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from action list.
		$sql = "SELECT * FROM {$table} WHERE action IN ({$placeholders}) AND created_at >= %s ORDER BY created_at DESC, id DESC LIMIT %d";

		$rows = DbQuery::get_results( $this->wpdb, $sql, $params );

		$out = array();
		foreach ( $rows as $row ) {
			try {
				$out[] = AuditLogEntry::from_array( $row );
			} catch ( InvalidArgumentException $e ) {
				continue;
			}
		}

		return $out;
	}

	private function audit_log_table(): string {
		return TableName::assert_valid( Schema::audit_log_table( $this->wpdb ) );
	}

	/**
	 * @param array<mixed> $value
	 */
	private function encode_json( array $value ): string {
		$json = wp_json_encode(
			$value,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return is_string( $json ) ? $json : '[]';
	}
}
