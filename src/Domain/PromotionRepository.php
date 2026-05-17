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
use MP\CommercePromotions\Service\PromotionLifecycle;
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
			'campaign_label'         => $promotion->get_campaign_label(),
			'internal_notes'         => $promotion->get_internal_notes(),
			'admin_color'            => $promotion->get_admin_color(),
			'budget_amount'          => $promotion->get_budget_amount(),
			'budget_spent'           => $promotion->get_budget_spent(),
			'budget_currency'        => $promotion->get_budget_currency(),
			'cooldown_hours'         => $promotion->get_cooldown_hours(),
			'orchestration_group'    => $promotion->get_orchestration_group(),
			'created_by'             => $promotion->get_created_by(),
			'created_at'             => $promotion->get_created_at() ?? $now,
			'updated_at'             => $promotion->get_updated_at() ?? $now,
		);

		$cooldown_format = $data['cooldown_hours'] === null ? '%s' : '%d';

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
			'%s',
			'%s',
			'%s',
			'%f',
			'%f',
			'%s',
			$cooldown_format,
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
			'campaign_label'         => $promotion->get_campaign_label(),
			'internal_notes'         => $promotion->get_internal_notes(),
			'admin_color'            => $promotion->get_admin_color(),
			'budget_amount'          => $promotion->get_budget_amount(),
			'budget_spent'           => $promotion->get_budget_spent(),
			'budget_currency'        => $promotion->get_budget_currency(),
			'cooldown_hours'         => $promotion->get_cooldown_hours(),
			'orchestration_group'    => $promotion->get_orchestration_group(),
			'created_by'             => $promotion->get_created_by(),
			'updated_at'             => $now,
		);

		$cooldown_format = $data['cooldown_hours'] === null ? '%s' : '%d';

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
			'%s',
			'%s',
			'%s',
			'%f',
			'%f',
			'%s',
			$cooldown_format,
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
	 *     campaign_label?: string|null,
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
	 *     campaign_label?: string|null,
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
	 * @return list<string>
	 */
	public function find_distinct_campaign_labels( int $limit = 50 ): array {
		$limit = max( 1, min( 100, $limit ) );
		$table = $this->promotions_table();

		$sql = "SELECT DISTINCT campaign_label FROM {$table}
			WHERE campaign_label IS NOT NULL AND campaign_label <> ''
			ORDER BY campaign_label ASC
			LIMIT %d";

		$rows = DbQuery::get_results( $this->wpdb, $sql, array( $limit ) );

		$labels = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['campaign_label'] ) ? trim( (string) $row['campaign_label'] ) : '';
			if ( $label !== '' ) {
				$labels[] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Active promotions with ends_at in the past (site timezone).
	 *
	 * @return list<Promotion>
	 */
	public function find_expired_active( int $limit = 500 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$table = $this->promotions_table();
		$now   = current_time( 'mysql' );

		$sql = "SELECT * FROM {$table}
			WHERE status = %s
			AND ends_at IS NOT NULL
			AND ends_at < %s
			ORDER BY ends_at ASC, id ASC
			LIMIT %d";

		$rows = DbQuery::get_results(
			$this->wpdb,
			$sql,
			array(
				PromotionStatus::ACTIVE,
				$now,
				$limit,
			)
		);

		return $this->rows_to_promotions( $rows );
	}

	/**
	 * Draft promotions older than N days (by created_at).
	 *
	 * @return list<Promotion>
	 */
	public function find_old_drafts( int $days, int $limit = 500 ): array {
		$days  = max( 1, min( 3650, $days ) );
		$limit = max( 1, min( 500, $limit ) );
		$table = $this->promotions_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', strtotime( current_time( 'mysql' ) ) ) );

		$sql = "SELECT * FROM {$table}
			WHERE status = %s
			AND created_at < %s
			ORDER BY created_at ASC, id ASC
			LIMIT %d";

		$rows = DbQuery::get_results(
			$this->wpdb,
			$sql,
			array(
				PromotionStatus::DRAFT,
				$cutoff,
				$limit,
			)
		);

		return $this->rows_to_promotions( $rows );
	}

	/**
	 * Atomically adjust budget_spent (positive to add, negative to subtract).
	 */
	public function adjust_budget_spent( int $id, float $delta ): bool {
		if ( $id <= 0 || $delta === 0.0 ) {
			return false;
		}

		$table = $this->promotions_table();
		$sql   = "UPDATE {$table}
			SET budget_spent = GREATEST(0, budget_spent + %f), updated_at = %s
			WHERE id = %d AND budget_amount IS NOT NULL";

		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				$sql,
				$delta,
				current_time( 'mysql' ),
				$id
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Active promotions with budget cap exhausted (budget_spent >= budget_amount).
	 *
	 * @return list<Promotion>
	 */
	/**
	 * Sum budget_spent across promotions that have a budget cap.
	 */
	public function sum_budget_spent_for_budgeted(): float {
		$table = $this->promotions_table();
		$total = DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(budget_spent), 0) FROM {$table}
				WHERE budget_amount IS NOT NULL AND budget_amount > 0"
		);

		if ( ! is_numeric( $total ) ) {
			return 0.0;
		}

		return (float) $total;
	}

	/**
	 * Count active promotions with a budget cap configured.
	 */
	public function count_active_budgeted(): int {
		$table = $this->promotions_table();
		$count = DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$table}
				WHERE status = %s
				AND budget_amount IS NOT NULL
				AND budget_amount > 0",
			array( PromotionStatus::ACTIVE )
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Count active promotions whose budget cap is exhausted.
	 */
	public function count_budget_exhausted_active(): int {
		$table = $this->promotions_table();
		$count = DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$table}
				WHERE status = %s
				AND budget_amount IS NOT NULL
				AND budget_amount > 0
				AND budget_spent >= budget_amount",
			array( PromotionStatus::ACTIVE )
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Draft promotions whose starts_at is in the past (ready to activate).
	 *
	 * @return list<Promotion>
	 */
	public function find_scheduled_drafts_ready( int $limit = 500 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$table = $this->promotions_table();
		$now   = current_time( 'mysql' );

		$sql = "SELECT * FROM {$table}
			WHERE status = %s
			AND starts_at IS NOT NULL
			AND starts_at <= %s
			ORDER BY starts_at ASC, id ASC
			LIMIT %d";

		$rows = DbQuery::get_results(
			$this->wpdb,
			$sql,
			array(
				PromotionStatus::DRAFT,
				$now,
				$limit,
			)
		);

		return $this->rows_to_promotions( $rows );
	}

	/**
	 * Paused promotions with ends_at in the past.
	 *
	 * @return list<Promotion>
	 */
	public function find_expired_paused( int $limit = 500 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$table = $this->promotions_table();
		$now   = current_time( 'mysql' );

		$sql = "SELECT * FROM {$table}
			WHERE status = %s
			AND ends_at IS NOT NULL
			AND ends_at < %s
			ORDER BY ends_at ASC, id ASC
			LIMIT %d";

		$rows = DbQuery::get_results(
			$this->wpdb,
			$sql,
			array(
				PromotionStatus::PAUSED,
				$now,
				$limit,
			)
		);

		return $this->rows_to_promotions( $rows );
	}

	/**
	 * Count active promotions with cooldown_hours configured.
	 */
	public function count_cooldown_active_promotions(): int {
		$table = $this->promotions_table();
		$count = DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$table} WHERE status = %s AND cooldown_hours IS NOT NULL AND cooldown_hours > 0",
			array( PromotionStatus::ACTIVE )
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * @return list<array{orchestration_group: string, promotion_count: int}>
	 */
	public function find_top_orchestration_groups( int $limit = 10 ): array {
		$limit = max( 1, min( 50, $limit ) );
		$table = $this->promotions_table();

		$sql = "SELECT orchestration_group, COUNT(*) AS promotion_count
			FROM {$table}
			WHERE status = %s
			AND orchestration_group IS NOT NULL
			AND orchestration_group <> ''
			GROUP BY orchestration_group
			ORDER BY promotion_count DESC, orchestration_group ASC
			LIMIT %d";

		$rows = DbQuery::get_results(
			$this->wpdb,
			$sql,
			array(
				PromotionStatus::ACTIVE,
				$limit,
			)
		);

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$group = isset( $row['orchestration_group'] ) ? trim( (string) $row['orchestration_group'] ) : '';
			if ( $group === '' ) {
				continue;
			}
			$out[] = array(
				'orchestration_group' => $group,
				'promotion_count'     => isset( $row['promotion_count'] ) ? (int) $row['promotion_count'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Active budgeted promotions with highest budget_spent (budget burn).
	 *
	 * @return list<Promotion>
	 */
	public function find_highest_budget_burn( int $limit = 10 ): array {
		$limit = max( 1, min( 50, $limit ) );
		$table = $this->promotions_table();

		$sql = "SELECT * FROM {$table}
			WHERE status = %s
			AND budget_amount IS NOT NULL
			AND budget_amount > 0
			ORDER BY budget_spent DESC, id ASC
			LIMIT %d";

		$rows = DbQuery::get_results(
			$this->wpdb,
			$sql,
			array(
				PromotionStatus::ACTIVE,
				$limit,
			)
		);

		return $this->rows_to_promotions( $rows );
	}

	public function find_budget_exhausted_active( int $limit = 500 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$table = $this->promotions_table();

		$sql = "SELECT * FROM {$table}
			WHERE status = %s
			AND budget_amount IS NOT NULL
			AND budget_amount > 0
			AND budget_spent >= budget_amount
			ORDER BY id ASC
			LIMIT %d";

		$rows = DbQuery::get_results(
			$this->wpdb,
			$sql,
			array(
				PromotionStatus::ACTIVE,
				$limit,
			)
		);

		return $this->rows_to_promotions( $rows );
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
	 *     campaign_label?: string|null,
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

		$campaign_label = isset( $args['campaign_label'] ) ? trim( (string) $args['campaign_label'] ) : '';
		if ( $campaign_label !== '' ) {
			$clauses[] = 'campaign_label = %s';
			$params[]  = Promotion::normalize_campaign_label( $campaign_label );
		}

		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( $search !== '' ) {
			$like      = '%' . $this->wpdb->esc_like( $search ) . '%';
			$clauses[] = '( name LIKE %s OR uuid LIKE %s OR campaign_label LIKE %s )';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		$lifecycle_phase = isset( $args['lifecycle_phase'] ) ? trim( (string) $args['lifecycle_phase'] ) : '';
		if ( $lifecycle_phase !== '' ) {
			$this->append_lifecycle_phase_where( $lifecycle_phase, $clauses, $params );
		}

		return array(
			'where'  => implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}

	/**
	 * @param list<string>       $clauses
	 * @param list<mixed>        $params
	 */
	private function append_lifecycle_phase_where( string $phase, array &$clauses, array &$params ): void {
		$valid = array(
			PromotionLifecycle::PHASE_UPCOMING,
			PromotionLifecycle::PHASE_LIVE,
			PromotionLifecycle::PHASE_ENDING_SOON,
			PromotionLifecycle::PHASE_EXPIRED_ACTIVE,
			PromotionLifecycle::PHASE_BUDGET_EXHAUSTED,
			PromotionLifecycle::PHASE_ARCHIVED,
			PromotionLifecycle::PHASE_SCHEDULED_DRAFT,
			PromotionLifecycle::PHASE_EXPIRED_PAUSED,
		);

		if ( ! in_array( $phase, $valid, true ) ) {
			throw new InvalidArgumentException( 'Invalid lifecycle_phase filter.' );
		}

		$now = current_time( 'mysql' );
		$ending_cutoff = gmdate(
			'Y-m-d H:i:s',
			strtotime( '+' . PromotionLifecycle::ENDING_SOON_DAYS . ' days', strtotime( $now ) )
		);

		if ( $phase === PromotionLifecycle::PHASE_ARCHIVED ) {
			$clauses[] = 'status = %s';
			$params[]  = PromotionStatus::ARCHIVED;
			return;
		}

		if ( $phase === PromotionLifecycle::PHASE_BUDGET_EXHAUSTED ) {
			$clauses[] = 'status = %s';
			$params[]  = PromotionStatus::ACTIVE;
			$clauses[] = 'budget_amount IS NOT NULL AND budget_amount > 0 AND budget_spent >= budget_amount';
			return;
		}

		if ( $phase === PromotionLifecycle::PHASE_EXPIRED_ACTIVE ) {
			$clauses[] = 'status = %s';
			$params[]  = PromotionStatus::ACTIVE;
			$clauses[] = 'ends_at IS NOT NULL AND ends_at < %s';
			$params[]  = $now;
			return;
		}

		if ( $phase === PromotionLifecycle::PHASE_UPCOMING ) {
			$clauses[] = 'status IN (%s, %s, %s)';
			$params[]  = PromotionStatus::ACTIVE;
			$params[]  = PromotionStatus::PAUSED;
			$params[]  = PromotionStatus::DRAFT;
			$clauses[] = 'starts_at IS NOT NULL AND starts_at > %s';
			$params[]  = $now;
			return;
		}

		if ( $phase === PromotionLifecycle::PHASE_ENDING_SOON ) {
			$clauses[] = 'status = %s';
			$params[]  = PromotionStatus::ACTIVE;
			$clauses[] = 'ends_at IS NOT NULL AND ends_at >= %s AND ends_at <= %s';
			$params[]  = $now;
			$params[]  = $ending_cutoff;
			$clauses[] = 'NOT ( budget_amount IS NOT NULL AND budget_amount > 0 AND budget_spent >= budget_amount )';
			return;
		}

		if ( $phase === PromotionLifecycle::PHASE_LIVE ) {
			$clauses[] = 'status = %s';
			$params[]  = PromotionStatus::ACTIVE;
			$clauses[] = '( starts_at IS NULL OR starts_at <= %s )';
			$params[]  = $now;
			$clauses[] = '( ends_at IS NULL OR ends_at >= %s )';
			$params[]  = $now;
			$clauses[] = 'NOT ( budget_amount IS NOT NULL AND budget_amount > 0 AND budget_spent >= budget_amount )';
			$clauses[] = '( ends_at IS NULL OR ends_at > %s )';
			$params[]  = $ending_cutoff;
			return;
		}

		if ( $phase === PromotionLifecycle::PHASE_SCHEDULED_DRAFT ) {
			$clauses[] = 'status = %s';
			$params[]  = PromotionStatus::DRAFT;
			$clauses[] = 'starts_at IS NOT NULL AND starts_at <= %s';
			$params[]  = $now;
			return;
		}

		if ( $phase === PromotionLifecycle::PHASE_EXPIRED_PAUSED ) {
			$clauses[] = 'status = %s';
			$params[]  = PromotionStatus::PAUSED;
			$clauses[] = 'ends_at IS NOT NULL AND ends_at < %s';
			$params[]  = $now;
		}
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
