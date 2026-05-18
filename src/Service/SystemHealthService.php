<?php
/**
 * Aggregated system health score for GA stabilization diagnostics.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;

final class SystemHealthService {

	public const OPTION_LAST_SNAPSHOT = 'mp_cp_system_health_snapshot';

	private Settings $settings;

	private PromotionPerformanceProfiler $profiler;

	private PromotionConcurrencyGuard $concurrency;

	private EcosystemCompatibilityRegistry $ecosystem;

	private ?PromotionHealthMonitor $promotion_health;

	private ?PromotionRepository $promotions;

	public function __construct(
		Settings $settings,
		PromotionPerformanceProfiler $profiler,
		PromotionConcurrencyGuard $concurrency,
		?EcosystemCompatibilityRegistry $ecosystem = null,
		?PromotionHealthMonitor $promotion_health = null,
		?PromotionRepository $promotions = null
	) {
		$this->settings         = $settings;
		$this->profiler         = $profiler;
		$this->concurrency      = $concurrency;
		$this->ecosystem        = $ecosystem ?? new EcosystemCompatibilityRegistry();
		$this->promotion_health = $promotion_health;
		$this->promotions       = $promotions;
	}

	/**
	 * @return array{
	 *   score: int,
	 *   label: string,
	 *   ecosystem_score: int,
	 *   promotion_issues: int,
	 *   degraded: bool,
	 *   recommendations: list<string>,
	 *   last_failure: array<string, mixed>,
	 *   components: array<string, mixed>
	 * }
	 */
	public function collect( bool $persist_snapshot = true ): array {
		$eco       = $this->ecosystem->summarize( true );
		$pricing   = ( new PricingCompatibilityAnalyzer() )->audit_with_confidence( true );
		$perf      = $this->profiler->get_report_summary();
		$warnings  = $this->concurrency->get_warnings();
		$promo_cnt = 0;

		if ( $this->promotion_health !== null ) {
			$promo_cnt = count( $this->promotion_health->analyze( 200 ) );
		}

		$score = (int) round( ( $eco['score'] * 0.35 ) + ( (int) ( $pricing['score'] ?? 0 ) * 0.25 ) );
		$score = (int) round( $score + ( $this->component_operational_score() * 0.4 ) );

		if ( ! empty( $perf['degraded']['active'] ) ) {
			$score -= 25;
		}
		if ( $this->settings->automation_emergency_stop() ) {
			$score -= 10;
		}
		if ( $this->settings->safe_mode_enabled() ) {
			$score -= 5;
		}
		$score -= min( 30, $promo_cnt * 3 );

		$score = max( 0, min( 100, $score ) );

		$recommendations = $this->build_recommendations( $eco, $pricing, $perf, $warnings, $promo_cnt );

		$last_failure = array(
			'planner_degraded' => $perf['degraded'] ?? array(),
			'concurrency'      => array_slice( $warnings, 0, 5 ),
			'planner_failures' => (int) ( $perf['planner_failures'] ?? 0 ),
		);

		$result = array(
			'score'            => $score,
			'label'            => $this->score_label( $score ),
			'ecosystem_score'  => (int) ( $eco['score'] ?? 0 ),
			'promotion_issues' => $promo_cnt,
			'degraded'         => ! empty( $perf['degraded']['active'] ),
			'recommendations'  => $recommendations,
			'last_failure'     => $last_failure,
			'components'       => array(
				'ecosystem'      => $eco,
				'pricing_compat' => $pricing,
				'performance'    => $perf,
				'feature_flags'  => $this->settings->to_feature_flags(),
				'stale_locks'    => $this->concurrency->purge_stale_locks( false ),
			),
		);

		if ( $persist_snapshot ) {
			update_option(
				self::OPTION_LAST_SNAPSHOT,
				array(
					'recorded_at' => gmdate( 'c' ),
					'snapshot'    => $result,
				),
				false
			);
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_last_snapshot(): array {
		$stored = get_option( self::OPTION_LAST_SNAPSHOT, array() );

		return is_array( $stored ) ? $stored : array();
	}

	private function component_operational_score(): int {
		$score = 100;
		$perf  = $this->profiler->get_report_summary();
		$avg   = (float) ( $perf['average_planner_ms'] ?? 0 );
		$max   = (float) ( $perf['max_planner_ms'] ?? 0 );

		if ( $avg > 80 ) {
			$score -= 15;
		}
		if ( $max > 250 ) {
			$score -= 20;
		}
		if ( (int) ( $perf['planner_failures'] ?? 0 ) > 0 ) {
			$score -= 15;
		}

		return max( 0, $score );
	}

	/**
	 * @param array<string, mixed>       $eco
	 * @param array<string, mixed>       $pricing
	 * @param array<string, mixed>       $perf
	 * @param list<array<string, mixed>> $warnings
	 * @return list<string>
	 */
	private function build_recommendations( array $eco, array $pricing, array $perf, array $warnings, int $promo_issues ): array {
		$recs = array();

		if ( ! empty( $perf['degraded']['active'] ) ) {
			$recs[] = __( 'Clear storefront degraded mode after resolving the planner error (Diagnostics → Performance).', 'mp-commerce-promotions' );
		}
		if ( $promo_issues > 0 ) {
			$recs[] = __( 'Review promotion health findings before enabling new automatic campaigns.', 'mp-commerce-promotions' );
		}
		if ( count( $warnings ) > 0 ) {
			$recs[] = __( 'Investigate concurrency warnings; run stale lock cleanup if locks were left after a crash.', 'mp-commerce-promotions' );
		}
		if ( (int) ( $perf['max_planner_ms'] ?? 0 ) > 200 ) {
			$recs[] = __( 'Planner runs are slow; archive unused promotions and reduce active orchestration groups.', 'mp-commerce-promotions' );
		}

		$eco_recs = is_array( $pricing['recommendations'] ?? null ) ? $pricing['recommendations'] : array();
		foreach ( array_slice( $eco_recs, 0, 3 ) as $line ) {
			if ( is_string( $line ) && $line !== '' ) {
				$recs[] = $line;
			}
		}

		if ( $recs === array() ) {
			$recs[] = __( 'No urgent recovery actions; continue GA soak testing on staging.', 'mp-commerce-promotions' );
		}

		return array_values( array_unique( $recs ) );
	}

	private function score_label( int $score ): string {
		if ( $score >= 85 ) {
			return __( 'Healthy', 'mp-commerce-promotions' );
		}
		if ( $score >= 65 ) {
			return __( 'Watch', 'mp-commerce-promotions' );
		}
		if ( $score >= 45 ) {
			return __( 'At risk', 'mp-commerce-promotions' );
		}

		return __( 'Critical', 'mp-commerce-promotions' );
	}
}
