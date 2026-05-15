<?php
/**
 * Append-only persistence for promotion redemptions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use MP\CommercePromotions\Infrastructure\Database\Schema;
use wpdb;

final class RedemptionRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function insert( Redemption $redemption ): int {
		$now = current_time( 'mysql' );

		$data = array(
			'promotion_id'    => $redemption->get_promotion_id(),
			'order_id'        => $redemption->get_order_id(),
			'customer_id'     => $redemption->get_customer_id(),
			'code'            => $redemption->get_code(),
			'discount_amount' => number_format( $redemption->get_discount_amount(), 6, '.', '' ),
			'currency'        => $redemption->get_currency(),
			'status'          => $redemption->get_status(),
			'redeemed_at'     => $redemption->get_redeemed_at() ?? $now,
			'created_at'      => $redemption->get_created_at() ?? $now,
		);

		$formats = array(
			'%d',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);

		$inserted = $this->wpdb->insert(
			Schema::redemptions_table( $this->wpdb ),
			$data,
			$formats
		);

		if ( false === $inserted ) {
			return 0;
		}

		$new_id = (int) $this->wpdb->insert_id;
		return $new_id > 0 ? $new_id : 0;
	}

	public function update( Redemption $redemption ): bool {
		$id = $redemption->get_id();
		if ( $id === null || $id <= 0 ) {
			return false;
		}

		$table = Schema::redemptions_table( $this->wpdb );

		$updated = $this->wpdb->update(
			$table,
			array( 'status' => $redemption->get_status() ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		return (int) $updated > 0;
	}

	public function find_recorded_for_order_and_promotion( int $order_id, int $promotion_id ): ?Redemption {
		if ( $order_id <= 0 || $promotion_id <= 0 ) {
			return null;
		}

		$table = Schema::redemptions_table( $this->wpdb );
		$sql   = "SELECT * FROM {$table} WHERE order_id = %d AND promotion_id = %d AND status = %s LIMIT 1";

		$prepared = $this->wpdb->prepare( $sql, $order_id, $promotion_id, Redemption::STATUS_RECORDED );
		if ( ! is_string( $prepared ) ) {
			return null;
		}

		$row = $this->wpdb->get_row( $prepared, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		try {
			return Redemption::from_array( $row );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	public function exists_for_order_and_promotion( int $order_id, int $promotion_id ): bool {
		if ( $order_id <= 0 || $promotion_id <= 0 ) {
			return false;
		}

		$table = Schema::redemptions_table( $this->wpdb );
		$sql   = "SELECT 1 FROM {$table} WHERE order_id = %d AND promotion_id = %d LIMIT 1";

		$prepared = $this->wpdb->prepare( $sql, $order_id, $promotion_id );
		if ( ! is_string( $prepared ) ) {
			return false;
		}

		$found = $this->wpdb->get_var( $prepared );
		return $found !== null && $found !== '' && (int) $found > 0;
	}

	public function count_for_order( int $order_id ): int {
		if ( $order_id <= 0 ) {
			return 0;
		}

		$table = Schema::redemptions_table( $this->wpdb );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE order_id = %d";

		$prepared = $this->wpdb->prepare( $sql, $order_id );
		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		$count = $this->wpdb->get_var( $prepared );
		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

	/**
	 * @return list<Redemption>
	 */
	public function find_for_order( int $order_id ): array {
		if ( $order_id <= 0 ) {
			return array();
		}

		$table = Schema::redemptions_table( $this->wpdb );
		$sql   = "SELECT * FROM {$table} WHERE order_id = %d ORDER BY redeemed_at DESC, id DESC";

		$prepared = $this->wpdb->prepare( $sql, $order_id );
		if ( ! is_string( $prepared ) ) {
			return array();
		}

		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return $this->rows_to_redemptions( $rows );
	}

	/**
	 * @return list<Redemption>
	 */
	public function find_for_promotion( int $promotion_id, int $limit = 50 ): array {
		if ( $promotion_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );

		$table = Schema::redemptions_table( $this->wpdb );
		$sql   = "SELECT * FROM {$table} WHERE promotion_id = %d ORDER BY created_at DESC, id DESC LIMIT %d";

		$prepared = $this->wpdb->prepare( $sql, $promotion_id, $limit );
		if ( ! is_string( $prepared ) ) {
			return array();
		}

		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return $this->rows_to_redemptions( $rows );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<Redemption>
	 */
	private function rows_to_redemptions( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$out[] = Redemption::from_array( $row );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return $out;
	}
}
