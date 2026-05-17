<?php
/**
 * Request-scoped planner cache and performance counters (no external object cache).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class PlannerContextCache {

	public const OPTION_COUNTERS = 'mp_cp_planner_performance_counters';

	/** @var array<string, EvaluationResult> */
	private static array $evaluation_cache = array();

	/** @var array<string, list<array<string, mixed>>> */
	private static array $scope_cache = array();

	private static int $simulated_runs = 0;

	private static int $cache_hits = 0;

	private static int $cache_misses = 0;

	public static function reset_request_cache(): void {
		self::$evaluation_cache = array();
		self::$scope_cache      = array();
	}

	public static function record_simulated_run(): void {
		++self::$simulated_runs;
	}

	public static function cache_key_for_context( EvaluationContext $context, int $promotion_id ): string {
		$data = $context->to_array();
		$data['promotion_id'] = $promotion_id;

		return hash( 'sha256', wp_json_encode( $data ) ?? '' );
	}

	public static function get_cached_evaluation( string $key ): ?EvaluationResult {
		if ( isset( self::$evaluation_cache[ $key ] ) ) {
			++self::$cache_hits;
			return self::$evaluation_cache[ $key ];
		}

		++self::$cache_misses;
		return null;
	}

	public static function store_evaluation( string $key, EvaluationResult $result ): void {
		self::$evaluation_cache[ $key ] = $result;
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	public static function get_scope_items( string $scope_key ): ?array {
		if ( isset( self::$scope_cache[ $scope_key ] ) ) {
			++self::$cache_hits;
			return self::$scope_cache[ $scope_key ];
		}

		++self::$cache_misses;
		return null;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	public static function store_scope_items( string $scope_key, array $items ): void {
		self::$scope_cache[ $scope_key ] = $items;
	}

	/**
	 * @return array{simulated_runs: int, cache_hits: int, cache_misses: int}
	 */
	public static function request_counters(): array {
		return array(
			'simulated_runs' => self::$simulated_runs,
			'cache_hits'     => self::$cache_hits,
			'cache_misses'   => self::$cache_misses,
		);
	}

	public static function persist_counters(): void {
		$stored = get_option( self::OPTION_COUNTERS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stored['simulated_runs'] = (int) ( $stored['simulated_runs'] ?? 0 ) + self::$simulated_runs;
		$stored['cache_hits']     = (int) ( $stored['cache_hits'] ?? 0 ) + self::$cache_hits;
		$stored['cache_misses']   = (int) ( $stored['cache_misses'] ?? 0 ) + self::$cache_misses;
		$stored['updated_at']     = current_time( 'mysql' );

		update_option( self::OPTION_COUNTERS, $stored, false );
	}

	/**
	 * @return array{simulated_runs: int, cache_hits: int, cache_misses: int, updated_at: string|null}
	 */
	public static function get_persisted_counters(): array {
		$stored = get_option( self::OPTION_COUNTERS, array() );
		if ( ! is_array( $stored ) ) {
			return array(
				'simulated_runs' => 0,
				'cache_hits'     => 0,
				'cache_misses'   => 0,
				'updated_at'     => null,
			);
		}

		return array(
			'simulated_runs' => (int) ( $stored['simulated_runs'] ?? 0 ),
			'cache_hits'     => (int) ( $stored['cache_hits'] ?? 0 ),
			'cache_misses'   => (int) ( $stored['cache_misses'] ?? 0 ),
			'updated_at'     => isset( $stored['updated_at'] ) ? (string) $stored['updated_at'] : null,
		);
	}

	public static function reset_persisted_counters(): void {
		delete_option( self::OPTION_COUNTERS );
		self::$simulated_runs = 0;
		self::$cache_hits     = 0;
		self::$cache_misses   = 0;
	}

	private function __construct() {
	}
}
