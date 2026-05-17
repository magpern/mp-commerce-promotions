<?php
/**
 * Persistence for promotions (custom table only).
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

final class PromotionRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Find a promotion by primary key.
	 */
	public function find( int $id ): ?Promotion {
		if ( $id <= 0 ) {
			return null;
		}

		$table = $this->promotions_table();
		$row   = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE id = %d",
			array( $id )
		);

		return $this->row_to_promotion( $row );
	}

	/**
	 * Find a promotion by UUID.
	 */
	public function find_by_uuid( string $uuid ): ?Promotion {
		$uuid = trim( $uuid );
		if ( $uuid === '' ) {
			return null;
		}

		$table = $this->promotions_table();
		$row   = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE uuid = %s",
			array( $uuid )
		);

		return $this->row_to_promotion( $row );
	}

	/**
	 * Resolve numeric id or UUID string to a promotion.
	 */
	public function find_by_id_or_uuid( string $identifier ): ?Promotion {
		$identifier = trim( $identifier );
		if ( $identifier === '' ) {
			return null;
		}

		if ( ctype_digit( $identifier ) ) {
			return $this->find( (int) $identifier );
		}

		return $this->find_by_uuid( $identifier );
	}

	/**
	 * Active promotions whose date window includes "now" (site timezone MySQL string).
	 *
	 * @return list<Promotion>
	 */
	public function find_active( int $limit = 50 ): array {
		$limit = max( 1, min( 100, $limit ) );
		$table = $this->promotions_table();
		$now   = current_time( 'mysql' );

		$sql = "SELECT * FROM {$table}
			WHERE status = %s
			AND ( starts_at IS NULL OR starts_at <= %s )
			AND ( ends_at IS NULL OR ends_at >= %s )
			ORDER BY priority ASC, id ASC
			LIMIT %d";

		$rows = DbQuery::get_results(
			$this->wpdb,
			$sql,
			array(
				PromotionStatus::ACTIVE,
				$now,
				$now,
				$limit,
			)
		);

		return $this->rows_to_promotions( $rows );
	}

	/**
	 * Insert a new promotion row; returns new id or 0 on failure.
	 */
	public function insert( Promotion $promotion ): int {
		$now = current_time( 'mysql' );

		$data = array(
			'uuid'         => $promotion->get_uuid(),
			'name'         => $promotion->get_name(),
			'description'  => $promotion->get_description(),
			'status'       => $promotion->get_status(),
			'priority'     => $promotion->get_priority(),
			'starts_at'    => $promotion->get_starts_at(),
			'ends_at'      => $promotion->get_ends_at(),
			'conditions'   => $this->encode_json( $promotion->get_conditions() ),
			'actions'      => $this->encode_json( $promotion->get_actions() ),
			'restrictions' => $this->encode_json( $promotion->get_restrictions() ),
			'usage_limit'          => $promotion->get_usage_limit(),
			'customer_usage_limit' => $promotion->get_customer_usage_limit(),
			'usage_count'          => $promotion->get_usage_count(),
			'application_mode'   => $promotion->get_application_mode(),
			'stop_processing'    => $promotion->should_stop_processing() ? 1 : 0,
			'max_applications'       => $promotion->get_max_applications(),
			'excluded_promotion_ids' => $this->encode_json( $promotion->get_excluded_promotion_ids() ),
			'excluded_product_ids'   => $this->encode_json( $promotion->get_excluded_product_ids() ),
			'excluded_category_ids'  => $this->encode_json( $promotion->get_excluded_category_ids() ),
			'created_by'             => $promotion->get_created_by(),
			'created_at'             => $promotion->get_created_at() ?? $now,
			'updated_at'             => $promotion->get_updated_at() ?? $now,
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
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
		);

		$inserted = $this->wpdb->insert(
			$this->promotions_table(),
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
	 * Update an existing promotion row.
	 */
	public function update( Promotion $promotion ): bool {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return false;
		}

		$now = current_time( 'mysql' );

		$data = array(
			'uuid'         => $promotion->get_uuid(),
			'name'         => $promotion->get_name(),
			'description'  => $promotion->get_description(),
			'status'       => $promotion->get_status(),
			'priority'     => $promotion->get_priority(),
			'starts_at'    => $promotion->get_starts_at(),
			'ends_at'      => $promotion->get_ends_at(),
			'conditions'   => $this->encode_json( $promotion->get_conditions() ),
			'actions'      => $this->encode_json( $promotion->get_actions() ),
			'restrictions' => $this->encode_json( $promotion->get_restrictions() ),
			'usage_limit'          => $promotion->get_usage_limit(),
			'customer_usage_limit' => $promotion->get_customer_usage_limit(),
			'usage_count'          => $promotion->get_usage_count(),
			'application_mode'   => $promotion->get_application_mode(),
			'stop_processing'    => $promotion->should_stop_processing() ? 1 : 0,
			'max_applications'       => $promotion->get_max_applications(),
			'excluded_promotion_ids' => $this->encode_json( $promotion->get_excluded_promotion_ids() ),
			'excluded_product_ids'   => $this->encode_json( $promotion->get_excluded_product_ids() ),
			'excluded_category_ids'  => $this->encode_json( $promotion->get_excluded_category_ids() ),
			'created_by'             => $promotion->get_created_by(),
			'updated_at'             => $now,
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
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
		);

		$updated = $this->wpdb->update(
			$this->promotions_table(),
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Hard-delete a promotion by id.
	 */
	public function delete( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$deleted = $this->wpdb->delete(
			$this->promotions_table(),
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
		$table  = $this->promotions_table();

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
			array( $limit, $offset )
		);

		return $this->rows_to_promotions( $rows );
	}

	/**
	 * Count all promotions (no filters).
	 */
	public function count_all(): int {
		return $this->count_filtered( array() );
	}

	/**
	 * @param array{
	 *     status?: string|null,
	 *     search?: string|null,
	 *     limit?: int,
	 *     offset?: int
	 * } $args
	 * @return list<Promotion>
	 */
	public function find_filtered( array $args ): array {
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 20;
		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		$filter = $this->build_filtered_where( $args );
		$table  = $this->promotions_table();

		$sql = "SELECT * FROM {$table} WHERE {$filter['where']} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";

		$params   = $filter['params'];
		$params[] = $limit;
		$params[] = $offset;

		$rows = DbQuery::get_results( $this->wpdb, $sql, $params );

		return $this->rows_to_promotions( $rows );
	}

	/**
	 * @param array{
	 *     status?: string|null,
	 *     search?: string|null,
	 *     limit?: int,
	 *     offset?: int
	 * } $args
	 */
	public function count_filtered( array $args ): int {
		$filter = $this->build_filtered_where( $args );
		$table  = $this->promotions_table();
		$sql    = "SELECT COUNT(*) FROM {$table} WHERE {$filter['where']}";

		$count = DbQuery::get_var( $this->wpdb, $sql, $filter['params'] );

		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

	/**
	 * Validated promotions table name from Schema.
	 */
	private function promotions_table(): string {
		return TableName::assert_valid( Schema::promotions_table( $this->wpdb ) );
	}

	/**
	 * @param array{
	 *     status?: string|null,
	 *     search?: string|null,
	 *     limit?: int,
	 *     offset?: int
	 * } $args
	 * @return array{where: string, params: list<mixed>}
	 */
	private function build_filtered_where( array $args ): array {
		$clauses = array( '1=1' );
		$params  = array();

		$status = isset( $args['status'] ) ? trim( (string) $args['status'] ) : '';
		if ( $status !== '' ) {
			if ( ! PromotionStatus::is_valid( $status ) ) {
				throw new InvalidArgumentException( 'Invalid promotion status filter.' );
			}
			$clauses[] = 'status = %s';
			$params[]  = $status;
		}

		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( $search !== '' ) {
			$like      = '%' . $this->wpdb->esc_like( $search ) . '%';
			$clauses[] = '( name LIKE %s OR uuid LIKE %s )';
			$params[]  = $like;
			$params[]  = $like;
		}

		return array(
			'where'  => implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<Promotion>
	 */
	private function rows_to_promotions( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$p = $this->row_to_promotion( $row );
			if ( $p instanceof Promotion ) {
				$out[] = $p;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_promotion( ?array $row ): ?Promotion {
		if ( $row === null || $row === array() ) {
			return null;
		}

		try {
			return Promotion::from_array( $row );
		} catch ( InvalidArgumentException $e ) {
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
