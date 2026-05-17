<?php
/**
 * Rolling performance aggregates for planner, allocation, telemetry (no PII).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Engine\PlannerContextCache;

final class PromotionPerformanceProfiler {

	public const OPTION_AGGREGATES = 'mp_cp_performance_profiler_aggregates';

	public const OPTION_DEGRADED_STATE = 'mp_cp_storefront_degraded_state';

	private const MAX_SLOW_RUNS = 15;

	/**
	 * @param array<string, mixed> $metrics
	 */
	public function record_planner_run( array $metrics ): void {
		$aggregates = $this->load();
		++$aggregates['planner_runs'];
		$ms                                    = (float) ( $metrics['duration_ms'] ?? 0 );
		$aggregates['total_planner_ms']       += $ms;
		$aggregates['max_planner_ms']          = max( (float) $aggregates['max_planner_ms'], $ms );
		$aggregates['evaluator_calls']        += (int) ( $metrics['evaluator_calls'] ?? 0 );
		$aggregates['condition_checks']       += (int) ( $metrics['condition_checks'] ?? 0 );
		$aggregates['action_count']           += (int) ( $metrics['action_count'] ?? 0 );
		$aggregates['allocation_count']       += (int) ( $metrics['allocation_count'] ?? 0 );
		$aggregates['promotions_considered']  += (int) ( $metrics['promotions_considered'] ?? 0 );
		$aggregates['promotions_prefiltered'] += (int) ( $metrics['promotions_prefiltered'] ?? 0 );

		$cache                                  = AllocationContextCache::request_metrics();
		$aggregates['allocation_cache_hits']   += (int) ( $cache['allocation_hits'] ?? 0 );
		$aggregates['allocation_cache_misses'] += (int) ( $cache['allocation_misses'] ?? 0 );

		$slow                     = array(
			'duration_ms'           => $ms,
			'promotions_considered' => (int) ( $metrics['promotions_considered'] ?? 0 ),
			'selected_count'        => (int) ( $metrics['selected_count'] ?? 0 ),
			'recorded_at'           => gmdate( 'c' ),
		);
		$aggregates['slow_runs']  = $this->push_slow_run( (array) ( $aggregates['slow_runs'] ?? array() ), $slow );
		$aggregates['updated_at'] = gmdate( 'c' );
		$this->save( $aggregates );
	}

	public function record_telemetry_write( float $duration_ms ): void {
		$aggregates = $this->load();
		++$aggregates['telemetry_writes'];
		$aggregates['total_telemetry_ms'] += max( 0.0, $duration_ms );
		$aggregates['updated_at']          = gmdate( 'c' );
		$this->save( $aggregates );
	}

	public function record_simulation_run( float $duration_ms ): void {
		$aggregates = $this->load();
		++$aggregates['simulation_runs'];
		$aggregates['total_simulation_ms'] += max( 0.0, $duration_ms );
		$aggregates['updated_at']           = gmdate( 'c' );
		$this->save( $aggregates );
	}

	public function record_planner_failure( string $message ): void {
		$state = array(
			'active'      => true,
			'message'     => substr( sanitize_text_field( $message ), 0, 500 ),
			'occurred_at' => gmdate( 'c' ),
		);
		update_option( self::OPTION_DEGRADED_STATE, $state, false );

		$aggregates = $this->load();
		++$aggregates['planner_failures'];
		$aggregates['updated_at'] = gmdate( 'c' );
		$this->save( $aggregates );
	}

	public function clear_degraded_state(): void {
		delete_option( self::OPTION_DEGRADED_STATE );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_degraded_state(): array {
		$state = get_option( self::OPTION_DEGRADED_STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	public function is_storefront_degraded(): bool {
		$state = $this->get_degraded_state();

		return ! empty( $state['active'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_aggregates(): array {
		return $this->load();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_report_summary(): array {
		$data    = $this->load();
		$runs    = max( 1, (int) ( $data['planner_runs'] ?? 0 ) );
		$hits    = (int) ( $data['allocation_cache_hits'] ?? 0 );
		$misses  = (int) ( $data['allocation_cache_misses'] ?? 0 );
		$total   = $hits + $misses;
		$planner = PlannerContextCache::get_persisted_counters();
		$alloc   = AllocationContextCache::get_persisted_metrics();

		return array(
			'planner_runs'              => (int) ( $data['planner_runs'] ?? 0 ),
			'average_planner_ms'        => round( (float) ( $data['total_planner_ms'] ?? 0 ) / $runs, 2 ),
			'max_planner_ms'            => (float) ( $data['max_planner_ms'] ?? 0 ),
			'evaluator_calls'           => (int) ( $data['evaluator_calls'] ?? 0 ),
			'allocation_cache_hit_rate' => $total > 0 ? round( ( $hits / $total ) * 100, 1 ) : 0.0,
			'telemetry_writes'          => (int) ( $data['telemetry_writes'] ?? 0 ),
			'planner_failures'          => (int) ( $data['planner_failures'] ?? 0 ),
			'slow_runs'                 => (array) ( $data['slow_runs'] ?? array() ),
			'persisted_planner'         => $planner,
			'persisted_allocation'      => $alloc,
			'degraded'                  => $this->get_degraded_state(),
		);
	}

	public function reset_aggregates(): void {
		delete_option( self::OPTION_AGGREGATES );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function load(): array {
		$stored = get_option( self::OPTION_AGGREGATES, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$defaults = array(
			'planner_runs'            => 0,
			'total_planner_ms'        => 0.0,
			'max_planner_ms'          => 0.0,
			'evaluator_calls'         => 0,
			'condition_checks'        => 0,
			'action_count'            => 0,
			'allocation_count'        => 0,
			'promotions_considered'   => 0,
			'promotions_prefiltered'  => 0,
			'allocation_cache_hits'   => 0,
			'allocation_cache_misses' => 0,
			'telemetry_writes'        => 0,
			'total_telemetry_ms'      => 0.0,
			'simulation_runs'         => 0,
			'total_simulation_ms'     => 0.0,
			'planner_failures'        => 0,
			'slow_runs'               => array(),
			'updated_at'              => null,
		);

		return array_merge( $defaults, $stored );
	}

	/**
	 * @param array<string, mixed> $aggregates
	 */
	private function save( array $aggregates ): void {
		update_option( self::OPTION_AGGREGATES, $aggregates, false );
	}

	/**
	 * @param list<array<string, mixed>> $runs
	 * @param array<string, mixed>       $entry
	 * @return list<array<string, mixed>>
	 */
	private function push_slow_run( array $runs, array $entry ): array {
		array_unshift( $runs, $entry );

		return array_slice( $runs, 0, self::MAX_SLOW_RUNS );
	}
}
