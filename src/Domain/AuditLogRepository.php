<?php
/**
 * Append-only persistence for audit log rows.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use MP\CommercePromotions\Infrastructure\Database\Schema;
use wpdb;

final class AuditLogRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

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
			Schema::audit_log_table( $this->wpdb ),
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

		$table = Schema::audit_log_table( $this->wpdb );
		$sql   = "SELECT * FROM {$table} WHERE promotion_id = %d ORDER BY created_at DESC, id DESC LIMIT %d";

		$prepared = $this->wpdb->prepare( $sql, $promotion_id, $limit );
		if ( ! is_string( $prepared ) ) {
			return array();
		}

		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$out[] = AuditLogEntry::from_array( $row );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return $out;
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
