<?php
/**
 * Read-only promotion performance summaries and redemption CSV export.
 *
 * Date filters apply to redemption.redeemed_at (site-local day boundaries).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Domain\RedemptionRepository;

final class PromotionReports {

	public const EXPORT_ROW_LIMIT = 5000;

	public const DATE_PRESET_TODAY      = 'today';

	public const DATE_PRESET_7D         = '7d';

	public const DATE_PRESET_30D        = '30d';

	public const DATE_PRESET_THIS_MONTH = 'this_month';

	private PromotionRepository $promotions;

	private RedemptionRepository $redemptions;

	public function __construct(
		PromotionRepository $promotions,
		RedemptionRepository $redemptions
	) {
		$this->promotions  = $promotions;
		$this->redemptions = $redemptions;
	}

	/**
	 * @param array<string, mixed> $args Raw request/filter input.
	 * @return array{
	 *     total_promotions: int,
	 *     active_promotions: int,
	 *     recorded_redemptions: int,
	 *     reversed_redemptions: int,
	 *     recorded_discount_total: float,
	 *     total_budget_spent: float,
	 *     active_budgeted_promotions: int,
	 *     exhausted_promotions: int,
	 *     top_promotions: list<array{
	 *         promotion_id: int,
	 *         name: string,
	 *         campaign_label: string,
	 *         recorded_count: int,
	 *         reversed_count: int,
	 *         total_discount_amount: float,
	 *         budget_amount: float|null,
	 *         budget_spent: float,
	 *         budget_utilization_percent: float|null
	 *     }>
	 * }
	 */
	public function summary( array $args = array() ): array {
		$filters = self::sanitize_filters( $args );

		$recorded_filters = $filters;
		$recorded_filters['status'] = Redemption::STATUS_RECORDED;

		$reversed_filters = $filters;
		$reversed_filters['status'] = Redemption::STATUS_REVERSED;

		$sum_filters = $filters;
		unset( $sum_filters['status'] );

		$recorded_count = 0;
		$reversed_count = 0;

		if ( $filters['status'] === null || $filters['status'] === Redemption::STATUS_RECORDED ) {
			$recorded_count = $this->redemptions->count_recorded( $recorded_filters );
		}

		if ( $filters['status'] === null || $filters['status'] === Redemption::STATUS_REVERSED ) {
			$reversed_count = $this->redemptions->count_reversed( $reversed_filters );
		}

		$top = $this->redemptions->top_promotions_by_redemptions( $filters, 10 );
		$top = $this->enrich_top_promotions_budget( $top, $filters['budget_exhausted'] );

		return array(
			'total_promotions'           => $this->promotions->count_all(),
			'active_promotions'          => $this->promotions->count_filtered(
				array( 'status' => PromotionStatus::ACTIVE )
			),
			'recorded_redemptions'       => $recorded_count,
			'reversed_redemptions'       => $reversed_count,
			'recorded_discount_total'    => $this->redemptions->sum_recorded_discount_amount( $sum_filters ),
			'total_budget_spent'         => $this->promotions->sum_budget_spent_for_budgeted(),
			'active_budgeted_promotions' => $this->promotions->count_active_budgeted(),
			'exhausted_promotions'       => $this->promotions->count_budget_exhausted_active(),
			'top_promotions'             => $top,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return list<Promotion>
	 */
	public function promotions_by_lifecycle_phase( string $phase, array $args = array(), int $limit = 20 ): array {
		$query = array(
			'lifecycle_phase' => $phase,
			'limit'           => max( 1, min( 100, $limit ) ),
			'offset'          => 0,
		);

		$budget_exhausted = $args['budget_exhausted'] ?? null;
		if ( is_string( $budget_exhausted ) && $budget_exhausted !== '' ) {
			$query['budget_exhausted'] = $budget_exhausted;
		}

		try {
			$list = $this->promotions->find_filtered( $query );
		} catch ( \InvalidArgumentException $e ) {
			return array();
		}

		return $this->filter_promotions_by_budget_exhausted( $list, $budget_exhausted );
	}

	/**
	 * CSV export of redemption rows (no raw promotion codes; code column may be empty).
	 *
	 * @param array<string, mixed> $args Filter input (same as summary).
	 */
	public function redemptions_csv( array $args = array() ): string {
		$filters = self::sanitize_filters( $args );
		$rows    = $this->redemptions->find_redemptions_for_export( $filters, self::EXPORT_ROW_LIMIT );

		$lines   = array();
		$lines[] = implode(
			',',
			array(
				'redemption_id',
				'promotion_id',
				'order_id',
				'customer_id',
				'code',
				'discount_amount',
				'currency',
				'status',
				'redeemed_at',
				'created_at',
				'campaign_label',
				'budget_amount',
				'budget_spent',
			)
		);

		foreach ( $rows as $row ) {
			$lines[] = implode(
				',',
				array(
					self::escape_csv_cell( (string) ( $row['redemption_id'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['promotion_id'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['order_id'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['customer_id'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['code'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['discount_amount'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['currency'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['status'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['redeemed_at'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['created_at'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['campaign_label'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['budget_amount'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['budget_spent'] ?? '' ) ),
				)
			);
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{
	 *     date_from: string|null,
	 *     date_to: string|null,
	 *     date_preset: string|null,
	 *     promotion_id: int|null,
	 *     status: string|null,
	 *     campaign_label: string|null,
	 *     budget_exhausted: string|null
	 * }
	 */
	public static function sanitize_filters( array $args ): array {
		$date_preset = self::sanitize_date_preset( $args['date_preset'] ?? null );
		$date_from   = self::sanitize_date( $args['date_from'] ?? null );
		$date_to     = self::sanitize_date( $args['date_to'] ?? null );

		if ( $date_preset !== null ) {
			$resolved  = self::resolve_date_preset( $date_preset );
			$date_from = $resolved['date_from'];
			$date_to   = $resolved['date_to'];
		}

		if ( $date_from !== null && $date_to !== null && $date_from > $date_to ) {
			$swap      = $date_from;
			$date_from = $date_to;
			$date_to   = $swap;
		}

		$promotion_id = null;
		if ( isset( $args['promotion_id'] ) && $args['promotion_id'] !== '' ) {
			$pid = (int) $args['promotion_id'];
			if ( $pid > 0 ) {
				$promotion_id = $pid;
			}
		}

		$status = null;
		if ( isset( $args['status'] ) && is_string( $args['status'] ) ) {
			$status = sanitize_key( $args['status'] );
			if ( $status !== Redemption::STATUS_RECORDED && $status !== Redemption::STATUS_REVERSED ) {
				$status = null;
			}
		}

		$campaign_label = null;
		if ( isset( $args['campaign_label'] ) && is_string( $args['campaign_label'] ) ) {
			$raw = trim( $args['campaign_label'] );
			if ( $raw !== '' ) {
				try {
					$campaign_label = Promotion::normalize_campaign_label( $raw );
				} catch ( \InvalidArgumentException $e ) {
					$campaign_label = null;
				}
			}
		}

		$budget_exhausted = self::sanitize_budget_exhausted_filter( $args['budget_exhausted'] ?? null );

		return array(
			'date_from'        => $date_from,
			'date_to'          => $date_to,
			'date_preset'      => $date_preset,
			'promotion_id'     => $promotion_id,
			'status'           => $status,
			'campaign_label'   => $campaign_label,
			'budget_exhausted' => $budget_exhausted,
		);
	}

	/**
	 * @return array{date_from: string, date_to: string}
	 */
	public static function resolve_date_preset( string $preset ): array {
		$preset = self::sanitize_date_preset( $preset ) ?? self::DATE_PRESET_30D;

		$tz_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
		$tz        = new \DateTimeZone( $tz_string );
		$today     = new \DateTimeImmutable( 'today', $tz );

		switch ( $preset ) {
			case self::DATE_PRESET_TODAY:
				$from = $today;
				$to   = $today;
				break;
			case self::DATE_PRESET_7D:
				$from = $today->modify( '-6 days' );
				$to   = $today;
				break;
			case self::DATE_PRESET_THIS_MONTH:
				$from = $today->modify( 'first day of this month' );
				$to   = $today;
				break;
			case self::DATE_PRESET_30D:
			default:
				$from = $today->modify( '-29 days' );
				$to   = $today;
				break;
		}

		return array(
			'date_from' => $from->format( 'Y-m-d' ),
			'date_to'   => $to->format( 'Y-m-d' ),
		);
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_date_preset( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = sanitize_key( $value );
		$allowed = array(
			self::DATE_PRESET_TODAY,
			self::DATE_PRESET_7D,
			self::DATE_PRESET_30D,
			self::DATE_PRESET_THIS_MONTH,
		);

		if ( ! in_array( $value, $allowed, true ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_budget_exhausted_filter( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = sanitize_key( $value );
		if ( $value === 'yes' || $value === 'no' ) {
			return $value;
		}

		return null;
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_date( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}

		$parts = explode( '-', $value );
		if ( count( $parts ) !== 3 ) {
			return null;
		}

		$year  = (int) $parts[0];
		$month = (int) $parts[1];
		$day   = (int) $parts[2];

		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return $value;
	}

	public static function escape_csv_cell( string $value ): string {
		if ( strpbrk( $value, ",\"\n\r" ) !== false ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}

		return $value;
	}

	/**
	 * @param list<array<string, mixed>> $top
	 * @return list<array<string, mixed>>
	 */
	private function enrich_top_promotions_budget( array $top, ?string $budget_exhausted ): array {
		$out = array();
		foreach ( $top as $row ) {
			$promotion_id = isset( $row['promotion_id'] ) ? (int) $row['promotion_id'] : 0;
			if ( $promotion_id <= 0 ) {
				continue;
			}

			$promotion = $this->promotions->find( $promotion_id );
			if ( ! $promotion instanceof Promotion ) {
				continue;
			}

			if ( ! self::promotion_matches_budget_exhausted_filter( $promotion, $budget_exhausted ) ) {
				continue;
			}

			$row['budget_amount']               = $promotion->get_budget_amount();
			$row['budget_spent']                  = $promotion->get_budget_spent();
			$row['budget_utilization_percent']    = $promotion->get_budget_utilization_percent();

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<Promotion>
	 */
	private function filter_promotions_by_budget_exhausted( array $promotions, ?string $budget_exhausted ): array {
		if ( $budget_exhausted === null ) {
			return $promotions;
		}

		$out = array();
		foreach ( $promotions as $promotion ) {
			if ( ! $promotion instanceof Promotion ) {
				continue;
			}
			if ( self::promotion_matches_budget_exhausted_filter( $promotion, $budget_exhausted ) ) {
				$out[] = $promotion;
			}
		}

		return $out;
	}

	public static function promotion_matches_budget_exhausted_filter( Promotion $promotion, ?string $budget_exhausted ): bool {
		if ( $budget_exhausted === null ) {
			return true;
		}

		$is_exhausted = $promotion->is_budget_exhausted();

		if ( $budget_exhausted === 'yes' ) {
			return $is_exhausted;
		}

		if ( $budget_exhausted === 'no' ) {
			return ! $is_exhausted;
		}

		return true;
	}
}
