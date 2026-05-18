<?php
/**
 * Lightweight rolling anomaly heuristics (options only, no external telemetry).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Woo\LineDiscountFallbackTelemetry;

final class RuntimeAnomalyDetector {

	public const OPTION_COUNTERS = 'mp_cp_runtime_anomaly_counters';

	private const WINDOW_PLANNER_MS = 500;

	private const WINDOW_SKIPPED_RATIO = 0.85;

	private const WINDOW_LINE_FALLBACKS = 10;

	private const WINDOW_DEGRADED = 3;

	private const WINDOW_COUPON_CONFLICTS = 5;

	private const WINDOW_BUDGET_SPIKES = 5;

	/**
	 * @param array<string, mixed> $planner_metrics
	 */
	public function record_planner_sample( array $planner_metrics ): void {
		$counters = $this->load_counters();
		++$counters['planner_samples'];

		$duration = (float) ( $planner_metrics['duration_ms'] ?? 0 );
		$counters['planner_ms_total'] += $duration;
		if ( $duration >= self::WINDOW_PLANNER_MS ) {
			++$counters['slow_planner_runs'];
		}

		$considered = max( 1, (int) ( $planner_metrics['promotions_considered'] ?? 0 ) );
		$selected   = (int) ( $planner_metrics['selected_count'] ?? 0 );
		$skipped    = max( 0, $considered - $selected );
		$ratio      = $skipped / $considered;
		if ( $ratio >= self::WINDOW_SKIPPED_RATIO && $considered >= 5 ) {
			++$counters['high_skipped_ratio_runs'];
		}

		$counters['blocked_by_budget_total'] += (int) ( $planner_metrics['blocked_by_budget_count'] ?? 0 );
		if ( (int) ( $planner_metrics['blocked_by_budget_count'] ?? 0 ) > 0 ) {
			++$counters['budget_block_runs'];
		}

		$counters['coupon_conflict_total'] += (int) ( $planner_metrics['coupon_conflict_count'] ?? 0 );
		if ( (int) ( $planner_metrics['coupon_conflict_count'] ?? 0 ) > 0 ) {
			++$counters['coupon_conflict_runs'];
		}

		$counters['updated_at'] = gmdate( 'c' );
		$this->save_counters( $counters );
	}

	public function record_degraded_activation(): void {
		$counters = $this->load_counters();
		++$counters['degraded_activations'];
		$counters['updated_at'] = gmdate( 'c' );
		$this->save_counters( $counters );
	}

	public function record_line_fallback(): void {
		$counters = $this->load_counters();
		++$counters['line_fallback_events'];
		$counters['updated_at'] = gmdate( 'c' );
		$this->save_counters( $counters );
	}

	/**
	 * @return list<array{code: string, severity: string, message: string, metric: int|string}>
	 */
	public function active_anomalies( ?PromotionPerformanceProfiler $profiler = null ): array {
		$counters = $this->load_counters();
		$out      = array();

		$samples = max( 1, (int) ( $counters['planner_samples'] ?? 0 ) );
		$slow    = (int) ( $counters['slow_planner_runs'] ?? 0 );
		if ( $slow >= 3 ) {
			$out[] = array(
				'code'     => 'excessive_planner_runtime',
				'severity' => 'high',
				'message'  => __( 'Multiple planner runs exceeded 500ms recently.', 'mp-commerce-promotions' ),
				'metric'   => $slow,
			);
		}

		if ( (int) ( $counters['high_skipped_ratio_runs'] ?? 0 ) >= 3 ) {
			$out[] = array(
				'code'     => 'high_skipped_ratio',
				'severity' => 'medium',
				'message'  => __( 'Planner frequently skips most promotions (orchestration or eligibility pressure).', 'mp-commerce-promotions' ),
				'metric'   => (int) $counters['high_skipped_ratio_runs'],
			);
		}

		if ( (int) ( $counters['degraded_activations'] ?? 0 ) >= self::WINDOW_DEGRADED ) {
			$out[] = array(
				'code'     => 'repeated_degraded_mode',
				'severity' => 'high',
				'message'  => __( 'Storefront degraded mode activated repeatedly.', 'mp-commerce-promotions' ),
				'metric'   => (int) $counters['degraded_activations'],
			);
		}

		$line_fb = (int) ( $counters['line_fallback_events'] ?? 0 );
		$line_stats = LineDiscountFallbackTelemetry::get_persisted_stats();
		$line_total = (int) ( $line_stats['total'] ?? 0 );
		if ( $line_fb >= self::WINDOW_LINE_FALLBACKS || $line_total >= self::WINDOW_LINE_FALLBACKS ) {
			$out[] = array(
				'code'     => 'repeated_line_fallback',
				'severity' => 'medium',
				'message'  => __( 'Line discount fallbacks to fee mode are elevated.', 'mp-commerce-promotions' ),
				'metric'   => max( $line_fb, $line_total ),
			);
		}

		if ( (int) ( $counters['budget_block_runs'] ?? 0 ) >= self::WINDOW_BUDGET_SPIKES ) {
			$out[] = array(
				'code'     => 'budget_exhaustion_spike',
				'severity' => 'medium',
				'message'  => __( 'Budget blocks spiked in recent planner runs.', 'mp-commerce-promotions' ),
				'metric'   => (int) $counters['budget_block_runs'],
			);
		}

		if ( (int) ( $counters['coupon_conflict_runs'] ?? 0 ) >= self::WINDOW_COUPON_CONFLICTS ) {
			$out[] = array(
				'code'     => 'coupon_conflict_spike',
				'severity' => 'medium',
				'message'  => __( 'Coupon conflict signals spiked in recent planner runs.', 'mp-commerce-promotions' ),
				'metric'   => (int) $counters['coupon_conflict_runs'],
			);
		}

		if ( $profiler !== null && $profiler->is_storefront_degraded() ) {
			$out[] = array(
				'code'     => 'degraded_mode_active',
				'severity' => 'high',
				'message'  => __( 'Storefront degraded mode is currently active.', 'mp-commerce-promotions' ),
				'metric'   => 'active',
			);
		}

		$avg_ms = $samples > 0 ? (float) $counters['planner_ms_total'] / $samples : 0.0;
		if ( $avg_ms >= 250 && $samples >= 10 ) {
			$out[] = array(
				'code'     => 'elevated_avg_planner_runtime',
				'severity' => 'low',
				'message'  => __( 'Average planner runtime is elevated across recent samples.', 'mp-commerce-promotions' ),
				'metric'   => (int) round( $avg_ms ),
			);
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function counter_summary(): array {
		return $this->load_counters();
	}

	public function reset_counters(): void {
		delete_option( self::OPTION_COUNTERS );
	}

	/**
	 * @return array<string, int|float|string>
	 */
	private function load_counters(): array {
		$stored = get_option( self::OPTION_COUNTERS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge(
			array(
				'planner_samples'         => 0,
				'planner_ms_total'        => 0.0,
				'slow_planner_runs'       => 0,
				'high_skipped_ratio_runs' => 0,
				'degraded_activations'    => 0,
				'line_fallback_events'    => 0,
				'budget_block_runs'       => 0,
				'blocked_by_budget_total' => 0,
				'coupon_conflict_runs'    => 0,
				'coupon_conflict_total'   => 0,
				'updated_at'              => '',
			),
			$stored
		);
	}

	/**
	 * @param array<string, int|float|string> $counters
	 */
	private function save_counters( array $counters ): void {
		update_option( self::OPTION_COUNTERS, $counters, false );
	}
}
