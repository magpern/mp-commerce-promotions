<?php
/**
 * Persistence for promotions (custom table only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use MP\CommercePromotions\Infrastructure\Database\Schema;
use wpdb;

final class PromotionRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function find( int $id ): ?Promotion {
		if ( $id <= 0 ) {
			return null;
		}

		$table = Schema::promotions_table( $this->wpdb );
		$sql = "SELECT * FROM {$table} WHERE id = %d";

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( $sql, $id ),
			ARRAY_A
		);

		return $this->row_to_promotion( $row );
	}

	public function find_by_uuid( string $uuid ): ?Promotion {
		$uuid = trim( $uuid );
		if ( $uuid === '' ) {
			return null;
		}

		$table = Schema::promotions_table( $this->wpdb );
		$sql = "SELECT * FROM {$table} WHERE uuid = %s";

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( $sql, $uuid ),
			ARRAY_A
		);

		return $this->row_to_promotion( $row );
	}

	public function insert( Promotion $promotion ): int {
		$now = current_time( 'mysql' );

		$data = array(
			'uuid'          => $promotion->get_uuid(),
			'name'          => $promotion->get_name(),
			'description'   => $promotion->get_description(),
			'status'        => $promotion->get_status(),
			'priority'      => $promotion->get_priority(),
			'starts_at'     => $promotion->get_starts_at(),
			'ends_at'       => $promotion->get_ends_at(),
			'conditions'    => $this->encode_json( $promotion->get_conditions() ),
			'actions'       => $this->encode_json( $promotion->get_actions() ),
			'restrictions'  => $this->encode_json( $promotion->get_restrictions() ),
			'usage_limit'   => $promotion->get_usage_limit(),
			'usage_count'   => $promotion->get_usage_count(),
			'created_by'    => $promotion->get_created_by(),
			'created_at'    => $promotion->get_created_at() ?? $now,
			'updated_at'    => $promotion->get_updated_at() ?? $now,
		);

		$formats = array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%d',
			'%d',
			'%s',
			'%s',
		);

		$inserted = $this->wpdb->insert(
			Schema::promotions_table( $this->wpdb ),
			$data,
			$formats
		);

		if ( false === $inserted ) {
			return 0;
		}

		$new_id = (int) $this->wpdb->insert_id;
		return $new_id > 0 ? $new_id : 0;
	}

	public function update( Promotion $promotion ): bool {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return false;
		}

		$now = current_time( 'mysql' );

		$data = array(
			'uuid'          => $promotion->get_uuid(),
			'name'          => $promotion->get_name(),
			'description'   => $promotion->get_description(),
			'status'        => $promotion->get_status(),
			'priority'      => $promotion->get_priority(),
			'starts_at'     => $promotion->get_starts_at(),
			'ends_at'       => $promotion->get_ends_at(),
			'conditions'    => $this->encode_json( $promotion->get_conditions() ),
			'actions'       => $this->encode_json( $promotion->get_actions() ),
			'restrictions'  => $this->encode_json( $promotion->get_restrictions() ),
			'usage_limit'   => $promotion->get_usage_limit(),
			'usage_count'   => $promotion->get_usage_count(),
			'created_by'    => $promotion->get_created_by(),
			'updated_at'    => $now,
		);

		$formats = array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%d',
			'%d',
			'%s',
		);

		$updated = $this->wpdb->update(
			Schema::promotions_table( $this->wpdb ),
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	public function delete( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$deleted = $this->wpdb->delete(
			Schema::promotions_table( $this->wpdb ),
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * @return list<Promotion>
	 */
	public function find_all( int $limit = 50, int $offset = 0 ): array {
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		$table = Schema::promotions_table( $this->wpdb );
		$sql   = "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";

		$prepared = $this->wpdb->prepare( $sql, $limit, $offset );
		if ( ! is_string( $prepared ) ) {
			return array();
		}

		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$p = $this->row_to_promotion( $row );
			if ( $p instanceof Promotion ) {
				$out[] = $p;
			}
		}

		return $out;
	}

	public function count_all(): int {
		$table = Schema::promotions_table( $this->wpdb );
		$sql   = "SELECT COUNT(*) FROM {$table}";

		$count = $this->wpdb->get_var( $sql );
		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

	/**
	 * @param array<string, mixed>|object|null $row
	 */
	private function row_to_promotion( $row ): ?Promotion {
		if ( ! is_array( $row ) || empty( $row ) ) {
			return null;
		}

		try {
			return Promotion::from_array( $row );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
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
