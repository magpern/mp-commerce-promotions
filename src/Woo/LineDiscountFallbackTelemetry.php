<?php
/**
 * Request-scoped counters for line discount → fee fallbacks.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class LineDiscountFallbackTelemetry {

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
