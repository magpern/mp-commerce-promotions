<?php
/**
 * Persistence for manual promotion codes (hashed; no plain code storage).
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

final class PromotionCodeRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * SHA-256 hash of normalized plain code (uppercase trim).
	 */
	public static function hash_plain_code( string $plain_code ): string {
		return hash( 'sha256', strtoupper( trim( $plain_code ) ) );
	}

	/**
	 * Insert a promotion code row; returns new id or 0 on failure.
	 */
	public function insert( PromotionCode $code ): int {
		$now = current_time( 'mysql' );

		$data = array(
			'promotion_id' => $code->get_promotion_id(),
			'batch_id'     => $code->get_batch_id(),
			'code_hash'    => $code->get_code_hash(),
			'code_last4'   => $code->get_code_last4(),
			'status'       => $code->get_status(),
			'usage_limit'  => $code->get_usage_limit(),
			'usage_count'  => $code->get_usage_count(),
			'expires_at'   => $code->get_expires_at(),
			'created_at'   => $code->get_created_at() ?? $now,
			'updated_at'   => $code->get_updated_at() ?? $now,
		);

		$batch_id_format    = $data['batch_id'] === null ? '%s' : '%d';
		$usage_limit_format = $data['usage_limit'] === null ? '%s' : '%d';

		$formats = array(
			'%d',
			$batch_id_format,
			'%s',
			'%s',
			'%s',
			$usage_limit_format,
			'%d',
			'%s',
			'%s',
			'%s',
		);

		$inserted = $this->wpdb->insert(
			$this->promotion_codes_table(),
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
	 * Find a promotion code by primary key.
	 */
	public function find( int $id ): ?PromotionCode {
		if ( $id <= 0 ) {
			return null;
		}

		$table = $this->promotion_codes_table();
		$row   = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
			array( $id )
		);

		return $this->row_to_code( $row );
	}

	/**
	 * Update mutable promotion code fields.
	 */
	public function update( PromotionCode $code ): bool {
		$id = $code->get_id();
		if ( $id === null || $id <= 0 ) {
			return false;
		}

		$now = current_time( 'mysql' );

		$data = array(
			'status'      => $code->get_status(),
			'usage_limit' => $code->get_usage_limit(),
			'usage_count' => $code->get_usage_count(),
			'expires_at'  => $code->get_expires_at(),
			'updated_at'  => $now,
		);

		$usage_limit_format = $data['usage_limit'] === null ? '%s' : '%d';

		$formats = array(
			'%s',
			$usage_limit_format,
			'%d',
			'%s',
			'%s',
		);

		$updated = $this->wpdb->update(
			$this->promotion_codes_table(),
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Find a code by plain text (hashed lookup).
	 */
	public function find_by_plain_code( string $plain_code ): ?PromotionCode {
		$hash = self::hash_plain_code( $plain_code );
		if ( strlen( $hash ) !== 64 ) {
			return null;
		}

		$table = $this->promotion_codes_table();
		$row   = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE code_hash = %s LIMIT 1",
			array( $hash )
		);

		if ( $row === null ) {
			return null;
		}

		try {
			return PromotionCode::from_array( $row );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * @return list<PromotionCode>
	 */
	public function find_all( int $limit = 100, int $offset = 0 ): array {
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$table  = $this->promotion_codes_table();

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
			array( $limit, $offset )
		);

		return $this->rows_to_codes( $rows );
	}

	/**
	 * @return list<PromotionCode>
	 */
	public function find_for_promotion( int $promotion_id, int $limit = 50 ): array {
		if ( $promotion_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );
		$table = $this->promotion_codes_table();

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE promotion_id = %d ORDER BY id DESC LIMIT %d",
			array( $promotion_id, $limit )
		);

		return $this->rows_to_codes( $rows );
	}

	/**
	 * @return list<PromotionCode>
	 */
	public function find_for_batch( int $batch_id, int $limit = 100 ): array {
		if ( $batch_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );
		$table = $this->promotion_codes_table();

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE batch_id = %d ORDER BY id DESC LIMIT %d",
			array( $batch_id, $limit )
		);

		return $this->rows_to_codes( $rows );
	}

	/**
	 * Count codes belonging to a batch.
	 */
	public function count_for_batch( int $batch_id ): int {
		if ( $batch_id <= 0 ) {
			return 0;
		}

		return $this->count_scalar(
			"SELECT COUNT(*) FROM {$this->promotion_codes_table()} WHERE batch_id = %d",
			array( $batch_id )
		);
	}

	/**
	 * Count codes for a promotion.
	 */
	public function count_for_promotion( int $promotion_id ): int {
		if ( $promotion_id <= 0 ) {
			return 0;
		}

		return $this->count_scalar(
			"SELECT COUNT(*) FROM {$this->promotion_codes_table()} WHERE promotion_id = %d",
			array( $promotion_id )
		);
	}

	/**
	 * Count active codes for a promotion.
	 */
	public function count_active_for_promotion( int $promotion_id ): int {
		if ( $promotion_id <= 0 ) {
			return 0;
		}

		return $this->count_scalar(
			"SELECT COUNT(*) FROM {$this->promotion_codes_table()} WHERE promotion_id = %d AND status = %s",
			array( $promotion_id, PromotionCode::STATUS_ACTIVE )
		);
	}

	/**
	 * Count codes in a batch with a given status.
	 */
	public function count_for_batch_with_status( int $batch_id, string $status ): int {
		if ( $batch_id <= 0 || ! PromotionCode::is_valid_status( $status ) ) {
			return 0;
		}

		return $this->count_scalar(
			"SELECT COUNT(*) FROM {$this->promotion_codes_table()} WHERE batch_id = %d AND status = %s",
			array( $batch_id, $status )
		);
	}

	/**
	 * Bulk status transition for all codes in a batch matching $from_status.
	 */
	public function bulk_update_status_for_batch( int $batch_id, string $from_status, string $to_status ): int {
		if ( $batch_id <= 0 ) {
			return 0;
		}

		if ( ! PromotionCode::is_valid_status( $from_status ) || ! PromotionCode::is_valid_status( $to_status ) ) {
			return 0;
		}

		$table = $this->promotion_codes_table();
		$now   = current_time( 'mysql' );

		$updated = DbQuery::query(
			$this->wpdb,
			"UPDATE {$table} SET status = %s, updated_at = %s WHERE batch_id = %d AND status = %s",
			array( $to_status, $now, $batch_id, $from_status )
		);

		if ( false === $updated ) {
			return 0;
		}

		return (int) $updated;
	}

	/**
	 * Whether the code is active, within usage limit, and not expired.
	 */
	public function is_code_usable( PromotionCode $code ): bool {
		if ( $code->get_status() !== PromotionCode::STATUS_ACTIVE ) {
			return false;
		}

		$usage_limit = $code->get_usage_limit();
		if ( $usage_limit !== null && $code->get_usage_count() >= $usage_limit ) {
			return false;
		}

		$expires_at = $code->get_expires_at();
		if ( $expires_at !== null && $expires_at !== '' ) {
			$now = current_time( 'mysql' );
			if ( $expires_at < $now ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Atomically increment usage_count for a code id.
	 */
	public function increment_usage( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$table   = $this->promotion_codes_table();
		$now     = current_time( 'mysql' );
		$updated = DbQuery::query(
			$this->wpdb,
			"UPDATE {$table} SET usage_count = usage_count + 1, updated_at = %s WHERE id = %d",
			array( $now, $id )
		);

		if ( false === $updated ) {
			return false;
		}

		return (int) $updated > 0;
	}

	private function promotion_codes_table(): string {
		return TableName::assert_valid( Schema::promotion_codes_table( $this->wpdb ) );
	}

	/**
	 * @param array<int, mixed> $args
	 */
	private function count_scalar( string $sql, array $args ): int {
		$count = DbQuery::get_var( $this->wpdb, $sql, $args );
		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<PromotionCode>
	 */
	private function rows_to_codes( array $rows ): array {
		$codes = array();
		foreach ( $rows as $row ) {
			$code = $this->row_to_code( $row );
			if ( $code instanceof PromotionCode ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_code( ?array $row ): ?PromotionCode {
		if ( $row === null ) {
			return null;
		}

		try {
			return PromotionCode::from_array( $row );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}
}
