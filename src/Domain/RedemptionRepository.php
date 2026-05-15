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
		$sql   = "SELECT * FROM {$table} WHERE promotion_id = %d ORDER BY redeemed_at DESC, id DESC LIMIT %d";

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
