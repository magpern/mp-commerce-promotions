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
	 *     top_promotions: list<array{
	 *         promotion_id: int,
	 *         name: string,
	 *         recorded_count: int,
	 *         reversed_count: int,
	 *         total_discount_amount: float
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

		return array(
			'total_promotions'          => $this->promotions->count_all(),
			'active_promotions'         => $this->promotions->count_filtered(
				array( 'status' => PromotionStatus::ACTIVE )
			),
			'recorded_redemptions'      => $recorded_count,
			'reversed_redemptions'      => $reversed_count,
			'recorded_discount_total'   => $this->redemptions->sum_recorded_discount_amount( $sum_filters ),
			'top_promotions'            => $this->redemptions->top_promotions_by_redemptions( $filters, 10 ),
		);
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
	 *     promotion_id: int|null,
	 *     status: string|null,
	 *     campaign_label: string|null
	 * }
	 */
	public static function sanitize_filters( array $args ): array {
		$date_from = self::sanitize_date( $args['date_from'] ?? null );
		$date_to   = self::sanitize_date( $args['date_to'] ?? null );

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

		return array(
			'date_from'      => $date_from,
			'date_to'        => $date_to,
			'promotion_id'   => $promotion_id,
			'status'         => $status,
			'campaign_label' => $campaign_label,
		);
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
}
