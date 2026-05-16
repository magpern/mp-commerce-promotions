<?php
/**
 * Persistence for manual promotion codes (hashed; no plain code storage).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use MP\CommercePromotions\Infrastructure\Database\Schema;
use wpdb;

final class PromotionCodeRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public static function hash_plain_code( string $plain_code ): string {
		return hash( 'sha256', strtoupper( trim( $plain_code ) ) );
	}

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

		$batch_id_format      = $data['batch_id'] === null ? '%s' : '%d';
		$usage_limit_format   = $data['usage_limit'] === null ? '%s' : '%d';

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
			Schema::promotion_codes_table( $this->wpdb ),
			$data,
			$formats
		);

		if ( false === $inserted ) {
			return 0;
		}

		$new_id = (int) $this->wpdb->insert_id;
		return $new_id > 0 ? $new_id : 0;
	}

	public function find( int $id ): ?PromotionCode {
		if ( $id <= 0 ) {
			return null;
		}

		$table = Schema::promotion_codes_table( $this->wpdb );
		$sql   = "SELECT * FROM {$table} WHERE id = %d LIMIT 1";

		$prepared = $this->wpdb->prepare( $sql, $id );
		if ( ! is_string( $prepared ) ) {
			return null;
		}

		$row = $this->wpdb->get_row( $prepared, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		try {
			return PromotionCode::from_array( $row );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	public function update( PromotionCode $code ): bool {
		$id = $code->get_id();
		if ( $id === null || $id <= 0 ) {
			return false;
		}

		$now = current_time( 'mysql' );

		$data = array(
			'status'       => $code->get_status(),
			'usage_limit'  => $code->get_usage_limit(),
			'usage_count'  => $code->get_usage_count(),
			'expires_at'   => $code->get_expires_at(),
			'updated_at'   => $now,
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
			Schema::promotion_codes_table( $this->wpdb ),
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	public function find_by_plain_code( string $plain_code ): ?PromotionCode {
		$hash = self::hash_plain_code( $plain_code );
		if ( strlen( $hash ) !== 64 ) {
			return null;
		}

		$table = Schema::promotion_codes_table( $this->wpdb );
		$sql   = "SELECT * FROM {$table} WHERE code_hash = %s LIMIT 1";

		$prepared = $this->wpdb->prepare( $sql, $hash );
		if ( ! is_string( $prepared ) ) {
			return null;
		}

		$row = $this->wpdb->get_row( $prepared, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		return PromotionCode::from_array( $row );
	}

	/**
	 * @return list<PromotionCode>
	 */
	public function find_all( int $limit = 100, int $offset = 0 ): array {
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		$table = Schema::promotion_codes_table( $this->wpdb );
		$sql   = "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d";

		$prepared = $this->wpdb->prepare( $sql, $limit, $offset );
		if ( ! is_string( $prepared ) ) {
			return array();
		}

		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$codes = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$codes[] = PromotionCode::from_array( $row );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return $codes;
	}

	/**
	 * @return list<PromotionCode>
	 */
	public function find_for_promotion( int $promotion_id, int $limit = 50 ): array {
		if ( $promotion_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );

		$table = Schema::promotion_codes_table( $this->wpdb );
		$sql   = "SELECT * FROM {$table} WHERE promotion_id = %d ORDER BY id DESC LIMIT %d";

		$prepared = $this->wpdb->prepare( $sql, $promotion_id, $limit );
		if ( ! is_string( $prepared ) ) {
			return array();
		}

		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$codes = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$codes[] = PromotionCode::from_array( $row );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return $codes;
	}

	/**
	 * @return list<PromotionCode>
	 */
	public function find_for_batch( int $batch_id, int $limit = 100 ): array {
		if ( $batch_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );

		$table = Schema::promotion_codes_table( $this->wpdb );
		$sql   = "SELECT * FROM {$table} WHERE batch_id = %d ORDER BY id ASC LIMIT %d";

		$prepared = $this->wpdb->prepare( $sql, $batch_id, $limit );
		if ( ! is_string( $prepared ) ) {
			return array();
		}

		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$codes = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$codes[] = PromotionCode::from_array( $row );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return $codes;
	}

	public function count_for_batch( int $batch_id ): int {
		if ( $batch_id <= 0 ) {
			return 0;
		}

		$table = Schema::promotion_codes_table( $this->wpdb );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE batch_id = %d";

		$prepared = $this->wpdb->prepare( $sql, $batch_id );
		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		$count = $this->wpdb->get_var( $prepared );
		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

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

	public function increment_usage( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$table = Schema::promotion_codes_table( $this->wpdb );
		$now   = current_time( 'mysql' );

		$sql = "UPDATE {$table} SET usage_count = usage_count + 1, updated_at = %s WHERE id = %d";

		$prepared = $this->wpdb->prepare( $sql, $now, $id );
		if ( ! is_string( $prepared ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared is from $wpdb->prepare().
		$updated = $this->wpdb->query( $prepared );
		if ( false === $updated ) {
			return false;
		}

		return (int) $updated > 0;
	}
}
