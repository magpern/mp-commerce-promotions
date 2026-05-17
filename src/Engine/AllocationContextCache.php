<?php
/**
 * Request-scoped allocation and compatibility caches (no external object cache).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class AllocationContextCache {

	public const OPTION_METRICS = 'mp_cp_allocation_performance_metrics';

	/** @var array<string, AllocationResult> */
	private static array $allocation_cache = array();

	/** @var array<string, list<array<string, mixed>>> */
	private static array $scoped_line_cache = array();

	private static int $allocation_hits = 0;

	private static int $allocation_misses = 0;

	private static float $allocation_time_ms = 0.0;

	private static int $planner_timing_ms = 0;

	public static function reset_request_cache(): void {
		self::$allocation_cache   = array();
		self::$scoped_line_cache  = array();
		self::$allocation_hits    = 0;
		self::$allocation_misses  = 0;
		self::$allocation_time_ms = 0.0;
		self::$planner_timing_ms  = 0;
	}

	public static function cache_key( EvaluationContext $context, array $promotion_ids ): string {
		$data = $context->to_array();
		sort( $promotion_ids );
		$data['promotion_ids'] = $promotion_ids;

		return hash( 'sha256', wp_json_encode( $data ) ?? '' );
	}

	public static function get_allocation( string $key ): ?AllocationResult {
		if ( isset( self::$allocation_cache[ $key ] ) ) {
			++self::$allocation_hits;
			return self::$allocation_cache[ $key ];
		}

		++self::$allocation_misses;
		return null;
	}

	public static function store_allocation( string $key, AllocationResult $result ): void {
		self::$allocation_cache[ $key ] = $result;
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	public static function get_scoped_lines( string $scope_key ): ?array {
		if ( isset( self::$scoped_line_cache[ $scope_key ] ) ) {
			++self::$allocation_hits;
			return self::$scoped_line_cache[ $scope_key ];
		}

		++self::$allocation_misses;
		return null;
	}

	/**
	 * @param list<array<string, mixed>> $lines
	 */
	public static function store_scoped_lines( string $scope_key, array $lines ): void {
		self::$scoped_line_cache[ $scope_key ] = $lines;
	}

	public static function record_allocation_timing( float $milliseconds ): void {
		self::$allocation_time_ms += max( 0.0, $milliseconds );
	}

	public static function record_planner_timing( int $milliseconds ): void {
		self::$planner_timing_ms += max( 0, $milliseconds );
	}

	/**
	 * @return array{allocation_hits: int, allocation_misses: int, allocation_time_ms: float, planner_timing_ms: int}
	 */
	public static function request_metrics(): array {
		return array(
			'allocation_hits'     => self::$allocation_hits,
			'allocation_misses'   => self::$allocation_misses,
			'allocation_time_ms'  => round( self::$allocation_time_ms, 2 ),
			'planner_timing_ms'   => self::$planner_timing_ms,
		);
	}

	public static function persist_metrics(): void {
		$stored = get_option( self::OPTION_METRICS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stored['allocation_hits']     = (int) ( $stored['allocation_hits'] ?? 0 ) + self::$allocation_hits;
		$stored['allocation_misses']   = (int) ( $stored['allocation_misses'] ?? 0 ) + self::$allocation_misses;
		$stored['allocation_time_ms']  = (float) ( $stored['allocation_time_ms'] ?? 0 ) + self::$allocation_time_ms;
		$stored['planner_timing_ms']   = (int) ( $stored['planner_timing_ms'] ?? 0 ) + self::$planner_timing_ms;
		$stored['updated_at']          = current_time( 'mysql' );

		update_option( self::OPTION_METRICS, $stored, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_persisted_metrics(): array {
		$stored = get_option( self::OPTION_METRICS, array() );

		return is_array( $stored ) ? $stored : array();
	}

	public static function reset_persisted_metrics(): void {
		delete_option( self::OPTION_METRICS );
	}

	private function __construct() {
	}
}
