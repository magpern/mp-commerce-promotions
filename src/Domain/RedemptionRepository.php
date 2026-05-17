<?php
/**
 * Append-only persistence for promotion redemptions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\Promotion;
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
	 * Latest redeemed_at for a recorded redemption (customer + promotion), or null.
	 */
	public function find_latest_recorded_redeemed_at_for_customer_and_promotion( int $customer_id, int $promotion_id ): ?string {
		if ( $customer_id <= 0 || $promotion_id <= 0 ) {
			return null;
		}

		$table = $this->redemptions_table();
		$value = DbQuery::get_var(
			$this->wpdb,
			"SELECT redeemed_at FROM {$table}
				WHERE customer_id = %d AND promotion_id = %d AND status = %s
				ORDER BY redeemed_at DESC, id DESC
				LIMIT 1",
			array( $customer_id, $promotion_id, Redemption::STATUS_RECORDED )
		);

		if ( ! is_string( $value ) || trim( $value ) === '' ) {
			return null;
		}

		return $value;
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

	/**
	 * Count recorded redemptions matching report filters (redeemed_at date window).
	 *
	 * @param array{
	 *     date_from?: string|null,
	 *     date_to?: string|null,
	 *     promotion_id?: int|null,
	 *     status?: string|null
	 * } $filters
	 */
	public function count_recorded( array $filters = array() ): int {
		$filters['status'] = Redemption::STATUS_RECORDED;

		return $this->count_by_report_filters( $filters );
	}

	/**
	 * Count reversed redemptions matching report filters.
	 *
	 * @param array{
	 *     date_from?: string|null,
	 *     date_to?: string|null,
	 *     promotion_id?: int|null,
	 *     status?: string|null
	 * } $filters
	 */
	public function count_reversed( array $filters = array() ): int {
		$filters['status'] = Redemption::STATUS_REVERSED;

		return $this->count_by_report_filters( $filters );
	}

	/**
	 * Sum discount_amount for recorded redemptions (ignores status filter; uses date/promotion only).
	 *
	 * @param array{
	 *     date_from?: string|null,
	 *     date_to?: string|null,
	 *     promotion_id?: int|null,
	 *     status?: string|null
	 * } $filters
	 */
	public function sum_recorded_discount_amount( array $filters = array() ): float {
		$built = $this->build_report_where( $filters, true );
		$table = $this->redemptions_table();

		$sql = "SELECT COALESCE(SUM(discount_amount), 0) FROM {$table} WHERE status = %s";
		if ( $built['where'] !== '' ) {
			$sql .= ' AND ' . $built['where'];
		}

		$params   = array( Redemption::STATUS_RECORDED );
		$params   = array_merge( $params, $built['params'] );
		$total    = DbQuery::get_var( $this->wpdb, $sql, $params );

		if ( ! is_numeric( $total ) ) {
			return 0.0;
		}

		return (float) $total;
	}

	/**
	 * Average discount_amount per recorded redemption row.
	 *
	 * @param array{
	 *     date_from?: string|null,
	 *     date_to?: string|null,
	 *     promotion_id?: int|null,
	 *     status?: string|null
	 * } $filters
	 */
	public function avg_recorded_discount_amount( array $filters = array() ): float {
		$built = $this->build_report_where( $filters, true );
		$table = $this->redemptions_table();

		$sql = "SELECT COALESCE(AVG(discount_amount), 0) FROM {$table} WHERE status = %s";
		if ( $built['where'] !== '' ) {
			$sql .= ' AND ' . $built['where'];
		}

		$params = array( Redemption::STATUS_RECORDED );
		$params = array_merge( $params, $built['params'] );
		$avg    = DbQuery::get_var( $this->wpdb, $sql, $params );

		if ( ! is_numeric( $avg ) ) {
			return 0.0;
		}

		return (float) $avg;
	}

	/**
	 * Top promotions by recorded redemption count.
	 *
	 * @param array{
	 *     date_from?: string|null,
	 *     date_to?: string|null,
	 *     promotion_id?: int|null,
	 *     status?: string|null
	 * } $filters
	 * @return list<array{
	 *     promotion_id: int,
	 *     name: string,
	 *     recorded_count: int,
	 *     reversed_count: int,
	 *     total_discount_amount: float
	 * }>
	 */
	public function top_promotions_by_redemptions( array $filters = array(), int $limit = 10 ): array {
		$limit = max( 1, min( 50, $limit ) );
		$built = $this->build_report_where( $filters, false, 'r' );

		$r_table = $this->redemptions_table();
		$p_table = TableName::assert_valid( Schema::promotions_table( $this->wpdb ) );

		$where_sql = $built['where'] !== '' ? 'WHERE ' . $built['where'] : '';

		$sql = "SELECT r.promotion_id,
			p.name AS promotion_name,
			p.campaign_label AS campaign_label,
			SUM(CASE WHEN r.status = %s THEN 1 ELSE 0 END) AS recorded_count,
			SUM(CASE WHEN r.status = %s THEN 1 ELSE 0 END) AS reversed_count,
			COALESCE(SUM(CASE WHEN r.status = %s THEN r.discount_amount ELSE 0 END), 0) AS total_discount_amount
			FROM {$r_table} r
			INNER JOIN {$p_table} p ON p.id = r.promotion_id
			{$where_sql}
			GROUP BY r.promotion_id, p.name, p.campaign_label
			ORDER BY recorded_count DESC, r.promotion_id ASC
			LIMIT %d";

		$params   = array(
			Redemption::STATUS_RECORDED,
			Redemption::STATUS_REVERSED,
			Redemption::STATUS_RECORDED,
		);
		$params   = array_merge( $params, $built['params'] );
		$params[] = $limit;

		$rows = DbQuery::get_results( $this->wpdb, $sql, $params );

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$promotion_id = isset( $row['promotion_id'] ) ? (int) $row['promotion_id'] : 0;
			if ( $promotion_id <= 0 ) {
				continue;
			}

			$out[] = array(
				'promotion_id'          => $promotion_id,
				'name'                  => isset( $row['promotion_name'] ) ? (string) $row['promotion_name'] : '',
				'campaign_label'        => isset( $row['campaign_label'] ) ? (string) $row['campaign_label'] : '',
				'recorded_count'        => isset( $row['recorded_count'] ) ? (int) $row['recorded_count'] : 0,
				'reversed_count'        => isset( $row['reversed_count'] ) ? (int) $row['reversed_count'] : 0,
				'total_discount_amount' => isset( $row['total_discount_amount'] ) ? (float) $row['total_discount_amount'] : 0.0,
			);
		}

		return $out;
	}

	/**
	 * Rows for CSV export (max 5000).
	 *
	 * @param array{
	 *     date_from?: string|null,
	 *     date_to?: string|null,
	 *     promotion_id?: int|null,
	 *     status?: string|null
	 * } $filters
	 * @return list<array<string, mixed>>
	 */
	public function find_redemptions_for_export( array $filters = array(), int $limit = 5000 ): array {
		$limit = max( 1, min( 5000, $limit ) );
		$built = $this->build_report_where( $filters, false, 'r' );

		$r_table = $this->redemptions_table();
		$p_table = TableName::assert_valid( Schema::promotions_table( $this->wpdb ) );

		$sql = "SELECT r.id AS redemption_id, r.promotion_id, r.order_id, r.customer_id, r.code,
			r.discount_amount, r.currency, r.status, r.redeemed_at, r.created_at,
			p.campaign_label AS campaign_label,
			p.budget_amount AS budget_amount,
			p.budget_spent AS budget_spent,
			p.orchestration_group AS orchestration_group,
			p.cooldown_hours AS cooldown_hours
			FROM {$r_table} r
			INNER JOIN {$p_table} p ON p.id = r.promotion_id";

		if ( $built['where'] !== '' ) {
			$sql .= ' WHERE ' . $built['where'];
		}

		$sql .= ' ORDER BY r.redeemed_at DESC, r.id DESC LIMIT %d';

		$params   = $built['params'];
		$params[] = $limit;

		$rows = DbQuery::get_results( $this->wpdb, $sql, $params );

		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * @param array{
	 *     date_from?: string|null,
	 *     date_to?: string|null,
	 *     promotion_id?: int|null,
	 *     status?: string|null
	 * } $filters
	 */
	private function count_by_report_filters( array $filters ): int {
		$built = $this->build_report_where( $filters, false );
		$table = $this->redemptions_table();

		$sql = "SELECT COUNT(*) FROM {$table}";
		if ( $built['where'] !== '' ) {
			$sql .= ' WHERE ' . $built['where'];
		}

		$count = DbQuery::get_var( $this->wpdb, $sql, $built['params'] );

		if ( ! is_numeric( $count ) ) {
			return 0;
		}

		return (int) $count;
	}

	/**
	 * Report filters use redemption.redeemed_at for date bounds (inclusive calendar days, site timezone).
	 *
	 * @param array{
	 *     date_from?: string|null,
	 *     date_to?: string|null,
	 *     promotion_id?: int|null,
	 *     status?: string|null
	 * } $filters
	 * @return array{where: string, params: list<mixed>}
	 */
	private function build_report_where( array $filters, bool $ignore_status_filter, string $alias = '' ): array {
		$prefix = $alias !== '' ? $alias . '.' : '';
		$parts  = array();
		$params = array();

		if ( ! $ignore_status_filter && isset( $filters['status'] ) && is_string( $filters['status'] ) ) {
			$status = $filters['status'];
			if ( $status === Redemption::STATUS_RECORDED || $status === Redemption::STATUS_REVERSED ) {
				$parts[]  = "{$prefix}status = %s";
				$params[] = $status;
			}
		}

		if ( isset( $filters['promotion_id'] ) && is_int( $filters['promotion_id'] ) && $filters['promotion_id'] > 0 ) {
			$parts[]  = "{$prefix}promotion_id = %d";
			$params[] = $filters['promotion_id'];
		}

		$date_from = isset( $filters['date_from'] ) ? $filters['date_from'] : null;
		if ( is_string( $date_from ) && $date_from !== '' ) {
			$parts[]  = "{$prefix}redeemed_at >= %s";
			$params[] = $date_from . ' 00:00:00';
		}

		$date_to = isset( $filters['date_to'] ) ? $filters['date_to'] : null;
		if ( is_string( $date_to ) && $date_to !== '' ) {
			$parts[]  = "{$prefix}redeemed_at <= %s";
			$params[] = $date_to . ' 23:59:59';
		}

		$campaign_label = isset( $filters['campaign_label'] ) ? trim( (string) $filters['campaign_label'] ) : '';
		if ( $campaign_label !== '' ) {
			$normalized = Promotion::normalize_campaign_label( $campaign_label );
			if ( $normalized !== null ) {
				$p_table  = TableName::assert_valid( Schema::promotions_table( $this->wpdb ) );
				$parts[]  = "{$prefix}promotion_id IN (SELECT id FROM {$p_table} WHERE campaign_label = %s)";
				$params[] = $normalized;
			}
		}

		return array(
			'where'  => implode( ' AND ', $parts ),
			'params' => $params,
		);
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
