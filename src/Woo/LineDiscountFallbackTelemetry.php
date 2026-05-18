<?php
/**
 * Request-scoped counters for line discount → fee fallbacks.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Service\PromotionPerformanceProfiler;

final class LineDiscountFallbackTelemetry {

	public const OPTION_STATS = 'mp_cp_line_discount_fallback_stats';

	public const REASON_LINE_MUTATION_FAILED = 'line_mutation_failed';

	public const REASON_UNSUPPORTED_PRODUCT_TYPE = 'unsupported_product_type';

	public const REASON_TAX_MODE_CONFLICT = 'tax_mode_conflict';

	public const REASON_COUPON_CONFLICT = 'coupon_conflict';

	public const REASON_MUTATION_GUARD_TRIGGERED = 'mutation_guard_triggered';

	public const REASON_UNSUPPORTED_ACTION = 'unsupported_action';

	/** @var array<string, int> */
	private static array $counts = array();

	/** @var list<array<string, mixed>> */
	private static array $events = array();

	public static function record( string $reason_code, int $promotion_id = 0, ?string $detail = null ): void {
		$reason = sanitize_key( $reason_code );
		if ( $reason === '' ) {
			$reason = self::REASON_LINE_MUTATION_FAILED;
		}

		if ( ! isset( self::$counts[ $reason ] ) ) {
			self::$counts[ $reason ] = 0;
		}
		++self::$counts[ $reason ];

		self::$events[] = array(
			'reason_code'   => $reason,
			'promotion_id'  => $promotion_id,
			'detail'        => $detail,
			'recorded_at'   => gmdate( 'c' ),
		);

		self::persist_stats( $reason, $promotion_id, $detail );
		self::bump_profiler_counters( $reason );
	}

	private static function bump_profiler_counters( string $reason ): void {
		$profiler = new PromotionPerformanceProfiler();
		if ( $reason === self::REASON_COUPON_CONFLICT ) {
			$profiler->increment_coupon_conflict();
			return;
		}

		$profiler->increment_coexistence_fallback();
	}

	private static function persist_stats( string $reason, int $promotion_id, ?string $detail ): void {
		$stats = get_option( self::OPTION_STATS, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		$stats['total'] = (int) ( $stats['total'] ?? 0 ) + 1;
		if ( ! isset( $stats['counts'] ) || ! is_array( $stats['counts'] ) ) {
			$stats['counts'] = array();
		}
		$stats['counts'][ $reason ] = (int) ( $stats['counts'][ $reason ] ?? 0 ) + 1;
		$stats['last_reason']      = $reason;
		$stats['last_promotion_id'] = $promotion_id;
		$stats['last_detail']      = $detail;
		$stats['last_recorded_at'] = gmdate( 'c' );

		update_option( self::OPTION_STATS, $stats, false );
	}

	public static function get_persisted_stats(): array {
		$stats = get_option( self::OPTION_STATS, array() );

		return is_array( $stats ) ? $stats : array();
	}

	public static function reset_persisted_stats(): void {
		delete_option( self::OPTION_STATS );
	}

	public static function reset(): void {
		self::$counts = array();
		self::$events = array();
	}

	/** @return array<string, int> */
	public static function get_counts(): array {
		return self::$counts;
	}

	public static function get_total(): int {
		return array_sum( self::$counts );
	}

	/** @return list<array<string, mixed>> */
	public static function get_events(): array {
		return self::$events;
	}

	/** @return array<string, mixed> */
	public static function to_summary(): array {
		return array(
			'total'  => self::get_total(),
			'counts' => self::$counts,
			'events' => self::$events,
		);
	}
}
