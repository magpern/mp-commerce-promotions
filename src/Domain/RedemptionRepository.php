<?php
/**
 * Append-only persistence for promotion redemptions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;
use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Infrastructure\Database\TableName;
use MP\CommercePromotions\Woo\WooCompatibility;
use wpdb;

final class RedemptionRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Insert a redemption row; returns new id or 0 on failure.
	 */
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
			$this->redemptions_table(),
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
	 * Update redemption status (e.g. recorded → reversed).
	 */
	public function update( Redemption $redemption ): bool {
		$id = $redemption->get_id();
		if ( $id === null || $id <= 0 ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$this->redemptions_table(),
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

	/**
	 * Find a recorded redemption for order + promotion.
	 */
	public function find_recorded_for_order_and_promotion( int $order_id, int $promotion_id ): ?Redemption {
		if ( $order_id <= 0 || $promotion_id <= 0 ) {
			return null;
		}

		$table = $this->redemptions_table();
		$row   = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE order_id = %d AND promotion_id = %d AND status = %s LIMIT 1",
			array( $order_id, $promotion_id, Redemption::STATUS_RECORDED )
		);

		return $this->row_to_redemption( $row );
	}

	/**
	 * Find a reversed redemption for order + promotion.
	 */
	public function find_reversed_for_order_and_promotion( int $order_id, int $promotion_id ): ?Redemption {
		if ( $order_id <= 0 || $promotion_id <= 0 ) {
			return null;
		}

		$table = $this->redemptions_table();
		$row   = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE order_id = %d AND promotion_id = %d AND status = %s LIMIT 1",
			array( $order_id, $promotion_id, Redemption::STATUS_REVERSED )
		);

		return $this->row_to_redemption( $row );
	}

	/**
	 * Whether any redemption exists for order + promotion (any status).
	 */
	public function exists_for_order_and_promotion( int $order_id, int $promotion_id ): bool {
		if ( $order_id <= 0 || $promotion_id <= 0 ) {
			return false;
		}

		$table = $this->redemptions_table();
		$found = DbQuery::get_var(
			$this->wpdb,
			"SELECT 1 FROM {$table} WHERE order_id = %d AND promotion_id = %d LIMIT 1",
			array( $order_id, $promotion_id )
		);

		return $found !== null && $found !== '' && (int) $found > 0;
	}

	/**
	 * Count recorded redemptions for a promotion.
	 */
	public function count_recorded_for_promotion( int $promotion_id ): int {
		return $this->count_by_promotion_and_status( $promotion_id, Redemption::STATUS_RECORDED );
	}

	/**
	 * Count recorded redemptions for a customer (read-only).
	 */
	public function count_recorded_for_customer( int $customer_id ): int {
		if ( $customer_id <= 0 ) {
			return 0;
		}

		$table = $this->redemptions_table();
		$count = DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$table} WHERE customer_id = %d AND status = %s",
			array( $customer_id, Redemption::STATUS_RECORDED )
		);

		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

	/**
	 * Count recorded redemptions for a customer on a specific promotion.
	 */
	public function count_recorded_for_customer_and_promotion( int $customer_id, int $promotion_id ): int {
		if ( $customer_id <= 0 || $promotion_id <= 0 ) {
			return 0;
		}

		$table = $this->redemptions_table();
		$count = DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$table} WHERE customer_id = %d AND promotion_id = %d AND status = %s",
			array( $customer_id, $promotion_id, Redemption::STATUS_RECORDED )
		);

		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

	/**
	 * Count reversed redemptions for a promotion.
	 */
	public function count_reversed_for_promotion( int $promotion_id ): int {
		return $this->count_by_promotion_and_status( $promotion_id, Redemption::STATUS_REVERSED );
	}

	/**
	 * Recorded redemptions whose order meta links to this promotion code id.
	 */
	public function count_recorded_for_promotion_code( int $code_id ): int {
		if ( $code_id <= 0 ) {
			return 0;
		}

		$meta_key          = '_mp_cp_promotion_code_id';
		$meta_val          = (string) $code_id;
		$redemptions_table = $this->redemptions_table();
		$status            = Redemption::STATUS_RECORDED;

		if ( $this->uses_custom_orders_table() ) {
			$meta_table = TableName::assert_valid( $this->wpdb->prefix . 'wc_orders_meta' );
			$sql        = "SELECT COUNT(DISTINCT r.id) FROM {$redemptions_table} r
				INNER JOIN {$meta_table} om ON om.order_id = r.order_id
					AND om.meta_key = %s AND om.meta_value = %s
				WHERE r.status = %s AND r.order_id IS NOT NULL";
		} else {
			$meta_table = TableName::assert_valid( $this->wpdb->postmeta );
			$sql        = "SELECT COUNT(DISTINCT r.id) FROM {$redemptions_table} r
				INNER JOIN {$meta_table} pm ON pm.post_id = r.order_id
					AND pm.meta_key = %s AND pm.meta_value = %s
				WHERE r.status = %s AND r.order_id IS NOT NULL";
		}

		$count = DbQuery::get_var(
			$this->wpdb,
			$sql,
			array( $meta_key, $meta_val, $status )
		);

		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

	/**
	 * Count redemptions for an order.
	 */
	public function count_for_order( int $order_id ): int {
		if ( $order_id <= 0 ) {
			return 0;
		}

		$table = $this->redemptions_table();
		$count = DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$table} WHERE order_id = %d",
			array( $order_id )
		);

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

		$table = $this->redemptions_table();
		$rows  = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE order_id = %d ORDER BY redeemed_at DESC, id DESC",
			array( $order_id )
		);

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
		$table = $this->redemptions_table();

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE promotion_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
			array( $promotion_id, $limit )
		);

		return $this->rows_to_redemptions( $rows );
	}

	private function count_by_promotion_and_status( int $promotion_id, string $status ): int {
		if ( $promotion_id <= 0 ) {
			return 0;
		}

		$table = $this->redemptions_table();
		$count = DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$table} WHERE promotion_id = %d AND status = %s",
			array( $promotion_id, $status )
		);

		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

	private function redemptions_table(): string {
		return TableName::assert_valid( Schema::redemptions_table( $this->wpdb ) );
	}

	private function uses_custom_orders_table(): bool {
		return WooCompatibility::is_hpos_enabled();
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_redemption( ?array $row ): ?Redemption {
		if ( $row === null ) {
			return null;
		}

		try {
			return Redemption::from_array( $row );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<Redemption>
	 */
	private function rows_to_redemptions( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$redemption = $this->row_to_redemption( $row );
			if ( $redemption instanceof Redemption ) {
				$out[] = $redemption;
			}
		}

		return $out;
	}
}
