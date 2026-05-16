<?php
/**
 * Persistence for promotion code generation batches.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use MP\CommercePromotions\Infrastructure\Database\Schema;
use wpdb;

final class PromotionCodeBatchRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

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

		$prefix_format      = $data['code_prefix'] === null ? '%s' : '%s';
		$usage_limit_format = $data['usage_limit'] === null ? '%s' : '%d';
		$created_by_format  = $data['created_by'] === null ? '%s' : '%d';
		$expires_format     = $data['expires_at'] === null ? '%s' : '%s';

		$formats = array(
			'%d',
			'%s',
			'%s',
			'%d',
			$prefix_format,
			$usage_limit_format,
			$expires_format,
			$created_by_format,
			'%s',
		);

		$inserted = $this->wpdb->insert(
			Schema::code_batches_table( $this->wpdb ),
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
	 * @return list<PromotionCodeBatch>
	 */
	public function find_for_promotion( int $promotion_id, int $limit = 50 ): array {
		if ( $promotion_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );

		$table = Schema::code_batches_table( $this->wpdb );
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
				$out[] = PromotionCodeBatch::from_array( $row );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return $out;
	}
}
