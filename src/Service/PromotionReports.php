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

use MP\CommercePromotions\Domain\AutomationRunRepository;
use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRepository;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Engine\PlannerContextCache;
use MP\CommercePromotions\Woo\CartSessionHelper;
use MP\CommercePromotions\Woo\CouponCoexistenceEvaluator;
use MP\CommercePromotions\Woo\LineDiscountFallbackTelemetry;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;

final class PromotionReports {

	public const EXPORT_ROW_LIMIT = 5000;

	public const DATE_PRESET_TODAY = 'today';

	public const DATE_PRESET_7D = '7d';

	public const DATE_PRESET_30D = '30d';

	public const DATE_PRESET_THIS_MONTH = 'this_month';

	private PromotionRepository $promotions;

	private RedemptionRepository $redemptions;

	private ?PlannerTelemetryRepository $telemetry;

	private ?AutomationRunRepository $automation_runs;

	private ?PromotionHealthMonitor $health_monitor;

	private ?SimulationScenarioRepository $scenarios;

	public function __construct(
		PromotionRepository $promotions,
		RedemptionRepository $redemptions,
		?PlannerTelemetryRepository $telemetry = null,
		?AutomationRunRepository $automation_runs = null,
		?PromotionHealthMonitor $health_monitor = null,
		?SimulationScenarioRepository $scenarios = null
	) {
		$this->promotions      = $promotions;
		$this->redemptions     = $redemptions;
		$this->telemetry       = $telemetry;
		$this->automation_runs = $automation_runs;
		$this->health_monitor  = $health_monitor;
		$this->scenarios       = $scenarios;
	}

	public function forecast_summary(): array {
		if ( $this->telemetry === null ) {
			return array();
		}

		return ( new PromotionForecastEngine( $this->promotions, $this->redemptions, $this->telemetry ) )->forecast_catalog();
	}

	/**
	 * @return array{upcoming: list<array<string, mixed>>, active: list<array<string, mixed>>, ending_soon: list<array<string, mixed>>, exhausted: list<array<string, mixed>>, archived: list<array<string, mixed>>}
	 */
	public function promotion_calendar(): array {
		return array(
			'upcoming'    => $this->calendar_phase( PromotionLifecycle::PHASE_UPCOMING ),
			'active'      => $this->calendar_phase( PromotionLifecycle::PHASE_LIVE ),
			'ending_soon' => $this->calendar_phase( PromotionLifecycle::PHASE_ENDING_SOON ),
			'exhausted'   => $this->calendar_phase( PromotionLifecycle::PHASE_BUDGET_EXHAUSTED ),
			'archived'    => $this->calendar_phase( PromotionLifecycle::PHASE_ARCHIVED ),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function calendar_phase( string $phase ): array {
		try {
			$list = $this->promotions->find_filtered(
				array(
					'lifecycle_phase' => $phase,
					'limit'           => 50,
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			return array();
		}

		$rows = array();
		foreach ( $list as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null ) {
				continue;
			}
			$rows[] = array(
				'promotion_id'               => $id,
				'name'                       => $promotion->get_name(),
				'campaign_label'             => $promotion->get_campaign_label(),
				'orchestration_group'        => $promotion->get_orchestration_group(),
				'priority_tier'              => $promotion->get_priority_tier(),
				'tier_color'                 => $this->tier_color( $promotion->get_priority_tier() ),
				'coupon_conflict_indicator'  => $promotion->get_coupon_behavior() !== PromotionCouponBehavior::COEXIST ? '!' : '',
				'budget_risk_indicator'      => $promotion->is_budget_exhausted()
					? 'exhausted'
					: ( ( ( $promotion->get_budget_utilization_percent() ?? 0 ) >= 80 ) ? 'high' : '' ),
				'budget_utilization_percent' => $promotion->get_budget_utilization_percent(),
				'lifecycle_phase'            => PromotionLifecycle::primary_phase( $promotion ),
				'starts_at'                  => $promotion->get_starts_at(),
				'ends_at'                    => $promotion->get_ends_at(),
			);
		}

		return $rows;
	}

	/**
	 * @return list<array{severity: string, code: string, promotion_ids: list<int>, message: string}>
	 */
	public function recommendations(): array {
		if ( $this->telemetry === null ) {
			return array();
		}

		return ( new PromotionRecommendationEngine(
			$this->promotions,
			$this->redemptions,
			$this->telemetry
		) )->recommend();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function intelligence_analytics(): array {
		$telemetry    = $this->telemetry;
		$top_selected = $telemetry !== null ? $telemetry->top_by_column( 'selected_count', 10 ) : array();
		$top_group    = $telemetry !== null ? $telemetry->top_by_column( 'blocked_by_group_count', 10 ) : array();
		$top_cooldown = $telemetry !== null ? $telemetry->top_by_column( 'blocked_by_cooldown_count', 10 ) : array();

		$low_usage  = array();
		$promotions = $this->promotions->find_filtered( array( 'limit' => 100 ) );
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null ) {
				continue;
			}
			$count = $this->redemptions->count_recorded_for_promotion( $id );
			if ( $count === 0 ) {
				$low_usage[] = array(
					'promotion_id' => $id,
					'name'         => $promotion->get_name(),
					'usage'        => 0,
				);
			}
		}

		$highest_roi = array();
		foreach ( $promotions as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null ) {
				continue;
			}
			$count = $this->redemptions->count_recorded_for_promotion( $id );
			if ( $count <= 0 ) {
				continue;
			}
			$total_discount = $this->redemptions->sum_recorded_discount_amount( array( 'promotion_id' => $id ) );
			$highest_roi[]  = array(
				'promotion_id'   => $id,
				'name'           => $promotion->get_name(),
				'redemptions'    => $count,
				'total_discount' => $total_discount,
				'roi_score'      => $count > 0 ? round( $total_discount / $count, 2 ) : 0.0,
			);
		}
		usort(
			$highest_roi,
			static fn ( array $a, array $b ): int => ( $b['roi_score'] ?? 0 ) <=> ( $a['roi_score'] ?? 0 )
		);
		$highest_roi = array_slice( $highest_roi, 0, 10 );

		$scenario_runs = 0;
		if ( $this->scenarios !== null ) {
			foreach ( $this->scenarios->find_latest( 20 ) as $scenario ) {
				$scenario_runs += $scenario->get_run_count();
			}
		}

		$burn_velocity = array();
		foreach ( $this->promotions->find_highest_budget_burn( 10 ) as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null ) {
				continue;
			}
			$spent           = $promotion->get_budget_spent();
			$burn_velocity[] = array(
				'promotion_id' => $id,
				'name'         => $promotion->get_name(),
				'budget_spent' => $spent,
				'velocity'     => $spent > 0 ? 'high' : 'low',
			);
		}

		return array(
			'highest_roi_campaigns'         => $highest_roi,
			'lowest_usage_promotions'       => array_slice( $low_usage, 0, 10 ),
			'most_simulated_scenarios_runs' => $scenario_runs,
			'highest_blocked_by_group'      => $top_group,
			'highest_blocked_by_cooldown'   => $top_cooldown,
			'most_selected'                 => $top_selected,
			'budget_burn_velocity'          => $burn_velocity,
		);
	}

	/**
	 * @return array{request: array<string, int>, persisted: array<string, int>}
	 */
	public function planner_performance(): array {
		$allocation = AllocationContextCache::request_metrics();
		$persisted  = AllocationContextCache::get_persisted_metrics();
		$profiler   = new PromotionPerformanceProfiler();
		$compat     = ( new PricingCompatibilityAnalyzer() )->audit_with_confidence();

		return array(
			'request'                  => array_merge( PlannerContextCache::request_counters(), $allocation ),
			'persisted'                => array_merge( PlannerContextCache::get_persisted_counters(), $persisted ),
			'profiler'                 => $profiler->get_report_summary(),
			'compatibility_confidence' => (string) ( $compat['confidence'] ?? PricingCompatibilityAnalyzer::CONFIDENCE_UNKNOWN ),
			'slow_runs'                => (array) ( $profiler->get_aggregates()['slow_runs'] ?? array() ),
		);
	}

	/**
	 * Production hardening dashboard data for Reports (no extra DB queries).
	 *
	 * @return array<string, mixed>
	 */
	public function production_hardening_dashboard( Settings $settings ): array {
		$perf     = $this->planner_performance();
		$profiler = new PromotionPerformanceProfiler();

		return array(
			'planner_performance'       => $perf,
			'profiler'                  => $perf['profiler'] ?? $profiler->get_report_summary(),
			'compatibility_confidence'  => (string) ( $perf['compatibility_confidence'] ?? PricingCompatibilityAnalyzer::CONFIDENCE_UNKNOWN ),
			'slow_runs'                 => (array) ( $perf['slow_runs'] ?? array() ),
			'safe_mode'                 => $settings->safe_mode_enabled(),
			'automatic_promotions'      => $settings->automatic_promotions_enabled(),
			'telemetry_paused'          => $settings->telemetry_paused(),
			'simulation_paused'         => $settings->simulation_paused(),
			'automation_emergency_stop' => $settings->automation_emergency_stop(),
			'cron_automation_enabled'   => $settings->cron_automation_enabled(),
			'cron_hourly_scheduled'     => function_exists( 'wp_next_scheduled' )
				? (bool) wp_next_scheduled( PromotionCronScheduler::HOOK_HOURLY )
				: false,
			'cron_daily_scheduled'      => function_exists( 'wp_next_scheduled' )
				? (bool) wp_next_scheduled( PromotionCronScheduler::HOOK_DAILY )
				: false,
			'telemetry_retention_days'  => $settings->telemetry_retention_days(),
			'degraded_state'            => $profiler->get_degraded_state(),
			'storefront_degraded'       => $profiler->is_storefront_degraded(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function profitability_analytics(): array {
		$redemptions    = $this->redemptions->find_redemptions_for_export( array(), 500 );
		$total_discount = 0.0;
		$count          = 0;
		foreach ( $redemptions as $row ) {
			$total_discount += (float) ( $row['discount_amount'] ?? 0 );
			++$count;
		}

		$avg_rate = 0.0;
		$active   = $this->promotions->find_active( 100 );
		foreach ( $active as $promotion ) {
			if ( $promotion->has_budget_cap() && $promotion->get_budget_amount() > 0 ) {
				$avg_rate = max(
					$avg_rate,
					( $promotion->get_budget_spent() / (float) $promotion->get_budget_amount() ) * 100
				);
			}
		}

		$highest_cost = array();
		foreach ( $active as $promotion ) {
			$highest_cost[] = array(
				'promotion_id' => $promotion->get_id(),
				'name'         => $promotion->get_name(),
				'budget_spent' => $promotion->get_budget_spent(),
				'tier'         => $promotion->get_priority_tier(),
			);
		}
		usort(
			$highest_cost,
			static fn ( array $a, array $b ): int => ( (float) ( $b['budget_spent'] ?? 0 ) ) <=> ( (float) ( $a['budget_spent'] ?? 0 ) )
		);

		return array(
			'estimated_margin_impact'     => round( $total_discount * 0.35, 2 ),
			'average_discount_rate'       => $count > 0 ? round( ( $total_discount / max( 1, $count ) ), 2 ) : 0.0,
			'shipping_discount_exposure'  => $this->shipping_discount_exposure(),
			'highest_cost_campaigns'      => array_slice( $highest_cost, 0, 10 ),
			'highest_effective_savings'   => $this->intelligence_analytics()['highest_roi_campaigns'] ?? array(),
			'estimated_revenue_influence' => round( $total_discount * 2.5, 2 ),
			'budget_burn_percent_peak'    => round( $avg_rate, 2 ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function pricing_analytics(): array {
		$tiers = array();
		foreach ( $this->promotions->find_filtered( array( 'limit' => 500 ) ) as $promotion ) {
			$tier = $promotion->get_priority_tier();
			if ( ! isset( $tiers[ $tier ] ) ) {
				$tiers[ $tier ] = 0;
			}
			++$tiers[ $tier ];
		}

		$coupon_eval = ( new CouponCoexistenceEvaluator() )->evaluate_cart();

		return array(
			'allocation_metrics'   => AllocationContextCache::get_persisted_metrics(),
			'shipping_analytics'   => array(
				'exposure' => $this->shipping_discount_exposure(),
			),
			'coupon_coexistence'   => $coupon_eval,
			'priority_tier_counts' => $tiers,
			'compatibility_issues' => ( new PricingCompatibilityAnalyzer() )->analyze(),
			'line_discount_mode'   => $this->line_discount_mode_summary(),
		);
	}

	/**
	 * Lightweight line / hybrid mode counters (options + promotion scan).
	 *
	 * @return array<string, mixed>
	 */
	public function line_discount_mode_summary(): array {
		$line_item_count = 0;
		$hybrid_count    = 0;
		$fee_based_count = 0;

		try {
			$list = $this->promotions->find_filtered( array( 'limit' => 500 ) );
		} catch ( \InvalidArgumentException $e ) {
			$list = array();
		}

		foreach ( $list as $promotion ) {
			$mode = $promotion->get_discount_application_mode();
			if ( $mode === PromotionDiscountApplicationMode::LINE_ITEM ) {
				++$line_item_count;
			} elseif ( $mode === PromotionDiscountApplicationMode::HYBRID ) {
				++$hybrid_count;
			} else {
				++$fee_based_count;
			}
		}

		$fallback = LineDiscountFallbackTelemetry::get_persisted_stats();
		$usage    = CartSessionHelper::get_line_usage_stats();
		$apps     = (int) ( $usage['applications'] ?? 0 );
		$savings  = (float) ( $usage['total_savings'] ?? 0.0 );

		return array(
			'line_item_promotions'           => $line_item_count,
			'hybrid_promotions'              => $hybrid_count,
			'fee_based_promotions'           => $fee_based_count,
			'fallback_total'                 => (int) ( $fallback['total'] ?? 0 ),
			'last_fallback_reason'           => (string) ( $fallback['last_reason'] ?? '' ),
			'last_fallback_at'               => (string) ( $fallback['last_recorded_at'] ?? '' ),
			'line_allocation_applications'   => $apps,
			'average_effective_line_savings' => $apps > 0 ? round( $savings / $apps, 4 ) : 0.0,
			'total_line_savings_recorded'    => round( $savings, 4 ),
		);
	}

	private function shipping_discount_exposure(): float {
		$total = 0.0;
		foreach ( $this->promotions->find_active( 100 ) as $promotion ) {
			foreach ( $promotion->get_actions() as $action ) {
				if ( is_array( $action ) && ( $action['type'] ?? '' ) === 'free_shipping' ) {
					$total += 10.0;
				}
			}
		}

		return round( $total, 2 );
	}

	/**
	 * @return array{
	 *     most_selected: list<array<string, mixed>>,
	 *     most_blocked: list<array<string, mixed>>,
	 *     top_orchestration_conflicts: list<array<string, mixed>>
	 * }
	 */
	public function telemetry_summary( int $limit = 10 ): array {
		if ( $this->telemetry === null ) {
			return array(
				'most_selected'               => array(),
				'most_blocked'                => array(),
				'top_orchestration_conflicts' => array(),
			);
		}

		$limit = max( 1, min( 20, $limit ) );

		return array(
			'most_selected'               => $this->telemetry->top_by_column( 'selected_count', $limit ),
			'most_blocked'                => $this->telemetry->top_by_column( 'blocked_by_group_count', $limit ),
			'top_orchestration_conflicts' => $this->telemetry->top_orchestration_groups_by_blocks( $limit ),
		);
	}

	/**
	 * @return list<\MP\CommercePromotions\Domain\AutomationRun>
	 */
	public function latest_automation_runs( int $limit = 20 ): array {
		if ( $this->automation_runs === null ) {
			return array();
		}

		return $this->automation_runs->find_latest( $limit );
	}

	/**
	 * @return array{total: int, critical: int, warning: int, info: int, issues: list<array<string, mixed>>}
	 */
	public function health_summary( int $limit = 500 ): array {
		if ( $this->health_monitor === null ) {
			return array(
				'total'    => 0,
				'critical' => 0,
				'warning'  => 0,
				'info'     => 0,
				'issues'   => array(),
			);
		}

		$issues   = $this->health_monitor->analyze( $limit );
		$critical = 0;
		$warning  = 0;
		$info     = 0;
		foreach ( $issues as $issue ) {
			$severity = isset( $issue['severity'] ) ? (string) $issue['severity'] : '';
			if ( $severity === PromotionHealthMonitor::SEVERITY_CRITICAL ) {
				++$critical;
			} elseif ( $severity === PromotionHealthMonitor::SEVERITY_WARNING ) {
				++$warning;
			} else {
				++$info;
			}
		}

		return array(
			'total'    => count( $issues ),
			'critical' => $critical,
			'warning'  => $warning,
			'info'     => $info,
			'issues'   => array_slice( $issues, 0, 25 ),
		);
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
	 *     cooldown_active_promotions: int,
	 *     avg_recorded_discount_per_redemption: float,
	 *     top_orchestration_groups: list<array{orchestration_group: string, promotion_count: int}>,
	 *     highest_budget_burn: list<array{
	 *         promotion_id: int,
	 *         name: string,
	 *         budget_amount: float|null,
	 *         budget_spent: float,
	 *         budget_utilization_percent: float|null
	 *     }>,
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

		$recorded_filters           = $filters;
		$recorded_filters['status'] = Redemption::STATUS_RECORDED;

		$reversed_filters           = $filters;
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
			'total_promotions'                     => $this->promotions->count_all(),
			'active_promotions'                    => $this->promotions->count_filtered(
				array( 'status' => PromotionStatus::ACTIVE )
			),
			'recorded_redemptions'                 => $recorded_count,
			'reversed_redemptions'                 => $reversed_count,
			'recorded_discount_total'              => $this->redemptions->sum_recorded_discount_amount( $sum_filters ),
			'total_budget_spent'                   => $this->promotions->sum_budget_spent_for_budgeted(),
			'active_budgeted_promotions'           => $this->promotions->count_active_budgeted(),
			'exhausted_promotions'                 => $this->promotions->count_budget_exhausted_active(),
			'cooldown_active_promotions'           => $this->promotions->count_cooldown_active_promotions(),
			'dry_run_promotions'                   => $this->promotions->count_dry_run_promotions(),
			'avg_recorded_discount_per_redemption' => $recorded_count > 0
				? $this->redemptions->avg_recorded_discount_amount( $sum_filters )
				: 0.0,
			'top_orchestration_groups'             => $this->promotions->find_top_orchestration_groups( 10 ),
			'highest_budget_burn'                  => $this->format_highest_budget_burn( $this->promotions->find_highest_budget_burn( 10 ) ),
			'top_promotions'                       => $top,
		);
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<array{
	 *     promotion_id: int,
	 *     name: string,
	 *     budget_amount: float|null,
	 *     budget_spent: float,
	 *     budget_utilization_percent: float|null
	 * }>
	 */
	private function format_highest_budget_burn( array $promotions ): array {
		$out = array();
		foreach ( $promotions as $promotion ) {
			if ( ! $promotion instanceof Promotion ) {
				continue;
			}
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}
			$out[] = array(
				'promotion_id'               => $id,
				'name'                       => $promotion->get_name(),
				'budget_amount'              => $promotion->get_budget_amount(),
				'budget_spent'               => $promotion->get_budget_spent(),
				'budget_utilization_percent' => $promotion->get_budget_utilization_percent(),
			);
		}

		return $out;
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
				'orchestration_group',
				'cooldown_hours',
				'budget_utilization_percent',
				'forecast_estimated_exposure',
				'planner_simulated_runs',
				'planner_cache_hits',
				'planner_cache_misses',
				'effective_discount_rate',
				'estimated_tax_impact',
				'allocation_total',
				'priority_tier',
				'coupon_behavior',
			)
		);

		$forecast_exposure = '';
		$forecast          = $this->forecast_summary();
		if ( $forecast !== array() ) {
			$forecast_exposure = (string) ( $forecast['estimated_discount_exposure'] ?? '' );
		}
		$planner  = $this->planner_performance();
		$sim_runs = (string) (int) ( $planner['persisted']['simulated_runs'] ?? 0 );
		$hits     = (string) (int) ( $planner['persisted']['cache_hits'] ?? 0 );
		$misses   = (string) (int) ( $planner['persisted']['cache_misses'] ?? 0 );

		foreach ( $rows as $row ) {
			$promotion_row = isset( $row['promotion_id'] ) ? $this->promotions->find( (int) $row['promotion_id'] ) : null;
			$tier          = $promotion_row instanceof Promotion ? $promotion_row->get_priority_tier() : '';
			$coupon_beh    = $promotion_row instanceof Promotion ? $promotion_row->get_coupon_behavior() : '';
			$alloc_total   = (string) ( $row['discount_amount'] ?? '' );
			$lines[]       = implode(
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
					self::escape_csv_cell( (string) ( $row['orchestration_group'] ?? '' ) ),
					self::escape_csv_cell( (string) ( $row['cooldown_hours'] ?? '' ) ),
					self::escape_csv_cell( self::format_budget_utilization_percent_for_csv( $row ) ),
					self::escape_csv_cell( $forecast_exposure ),
					self::escape_csv_cell( $sim_runs ),
					self::escape_csv_cell( $hits ),
					self::escape_csv_cell( $misses ),
					self::escape_csv_cell( '' ),
					self::escape_csv_cell( '' ),
					self::escape_csv_cell( $alloc_total ),
					self::escape_csv_cell( $tier ),
					self::escape_csv_cell( $coupon_beh ),
				)
			);
		}

		return implode( "\n", $lines ) . "\n";
	}

	private function tier_color( string $tier ): string {
		return match ( $tier ) {
			'override' => '#b32d2e',
			'recovery' => '#d63638',
			'loyalty'  => '#2271b1',
			'campaign' => '#00a32a',
			default    => '#787c82',
		};
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

		$value   = sanitize_key( $value );
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

	/**
	 * @param array<string, mixed> $row
	 */
	public static function format_budget_utilization_percent_for_csv( array $row ): string {
		$amount = isset( $row['budget_amount'] ) && is_numeric( $row['budget_amount'] )
			? (float) $row['budget_amount']
			: 0.0;
		$spent  = isset( $row['budget_spent'] ) && is_numeric( $row['budget_spent'] )
			? (float) $row['budget_spent']
			: 0.0;

		if ( $amount <= 0 ) {
			return '';
		}

		$pct = min( 100.0, max( 0.0, ( $spent / $amount ) * 100.0 ) );

		return number_format( $pct, 1, '.', '' );
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

			$row['budget_amount']              = $promotion->get_budget_amount();
			$row['budget_spent']               = $promotion->get_budget_spent();
			$row['budget_utilization_percent'] = $promotion->get_budget_utilization_percent();

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
