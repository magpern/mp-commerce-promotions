<?php
/**
 * Persistence for promotion code generation batches.
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

final class PromotionCodeBatchRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Insert a code batch row; returns new id or 0 on failure.
	 */
	public function insert( PromotionCodeBatch $batch ): int {
		$now = current_time( 'mysql' );

		$data = array(
			'promotion_id' => $batch->get_promotion_id(),
			'batch_uuid'   => $batch->get_batch_uuid(),
			'name'         => $batch->get_name(),
			'quantity'     => $batch->get_quantity(),
			'code_prefix'  => $batch->get_code_prefix(),
			'usage_limit'  => $batch->get_usage_limit(),
			'expires_at'   => $batch->get_expires_at(),
			'created_by'   => $batch->get_created_by(),
			'created_at'   => $batch->get_created_at() ?? $now,
		);

		$usage_limit_format = $data['usage_limit'] === null ? '%s' : '%d';
		$created_by_format  = $data['created_by'] === null ? '%s' : '%d';
		$expires_format     = $data['expires_at'] === null ? '%s' : '%s';

		$formats = array(
			'%d',
			'%s',
			'%s',
			'%d',
			'%s',
			$usage_limit_format,
			$expires_format,
			$created_by_format,
			'%s',
		);

		$inserted = $this->wpdb->insert(
			$this->code_batches_table(),
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
	 * Find a batch by primary key.
	 */
	public function find( int $id ): ?PromotionCodeBatch {
		if ( $id <= 0 ) {
			return null;
		}

		$table = $this->code_batches_table();
		$row   = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
			array( $id )
		);

		return $this->row_to_batch( $row );
	}

	/**
	 * @return list<PromotionCodeBatch>
	 */
	public function find_for_promotion( int $promotion_id, int $limit = 50 ): array {
		if ( $promotion_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );
		$table = $this->code_batches_table();

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE promotion_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
			array( $promotion_id, $limit )
		);

		return $this->rows_to_batches( $rows );
	}

	/**
	 * Count batches for a promotion.
	 */
	public function count_for_promotion( int $promotion_id ): int {
		if ( $promotion_id <= 0 ) {
			return 0;
		}

		$table = $this->code_batches_table();
		$count = DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$table} WHERE promotion_id = %d",
			array( $promotion_id )
		);

		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

	private function code_batches_table(): string {
		return TableName::assert_valid( Schema::code_batches_table( $this->wpdb ) );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<PromotionCodeBatch>
	 */
	private function rows_to_batches( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$batch = $this->row_to_batch( $row );
			if ( $batch instanceof PromotionCodeBatch ) {
				$out[] = $batch;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_batch( ?array $row ): ?PromotionCodeBatch {
		if ( $row === null ) {
			return null;
		}

		try {
			return PromotionCodeBatch::from_array( $row );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}
}
