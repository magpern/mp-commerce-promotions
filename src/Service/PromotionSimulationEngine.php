<?php
/**
 * Simulates promotion planner outcomes against synthetic or saved cart scenarios.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionPriorityTier;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Engine\DiscountAllocationEngine;
use MP\CommercePromotions\Engine\EligibleCartScope;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PlannerContextCache;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\PromotionPlanExplainer;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;

final class PromotionSimulationEngine {

	private PromotionRepository $promotions;

	private PromotionPlanner $planner;

	private PromotionEvaluator $evaluator;

	private DiscountAllocationEngine $allocator;

	private ?Settings $settings;

	private ?PromotionPerformanceProfiler $profiler;

	public function __construct(
		PromotionRepository $promotions,
		?PromotionPlanner $planner = null,
		?PromotionEvaluator $evaluator = null,
		?DiscountAllocationEngine $allocator = null,
		?Settings $settings = null,
		?PromotionPerformanceProfiler $profiler = null
	) {
		$this->promotions = $promotions;
		$this->evaluator  = $evaluator ?? new PromotionEvaluator();
		$this->planner    = $planner ?? new PromotionPlanner( $this->evaluator, null, $profiler );
		$this->allocator  = $allocator ?? new DiscountAllocationEngine();
		$this->settings   = $settings;
		$this->profiler   = $profiler;
	}

	public function simulate( SimulationScenario $scenario, ?array $promotion_filter_ids = null ): SimulationResult {
		if ( $this->settings !== null && ! $this->settings->simulations_enabled() ) {
			return new SimulationResult(
				array(),
				array(),
				array(),
				0.0,
				array(),
				array(),
				array(),
				array( __( 'Simulations are paused.', 'mp-commerce-promotions' ) )
			);
		}

		$started = microtime( true );
		PlannerContextCache::reset_request_cache();
		PlannerContextCache::record_simulated_run();

		$validation = $scenario->validate();
		$warnings   = array();
		if ( $validation !== true ) {
			$warnings[] = is_string( $validation ) ? $validation : 'invalid_scenario';
		}

		$context     = $this->build_context( $scenario );
		$promotions  = $this->resolve_promotions( $scenario, $promotion_filter_ids );
		$promotions  = $this->sort_promotions( $promotions );

		$plan        = $this->planner->plan( $promotions, $context );
		$allocation  = $this->allocator->allocate( $context, $plan->get_selected_decisions() );
		$explained   = PromotionPlanExplainer::explain( $plan );
		$explained   = PromotionPlanExplainer::enrich_explanation( $explained, $plan, $context, $allocation );

		$eligible    = array();
		$selected    = array();
		$skipped     = array();
		$actions     = array();
		$total       = 0.0;

		foreach ( $plan->get_decisions() as $decision ) {
			$pid = $decision->get_promotion_id();
			$row = array(
				'promotion_id'   => $pid,
				'promotion_name' => $decision->get_promotion_name(),
				'selected'       => $decision->is_selected(),
				'skipped_reason' => $decision->get_skipped_reason(),
			);

			if ( $decision->get_result()->is_eligible() ) {
				$eligible[] = $row;
			}

			if ( $decision->is_selected() ) {
				$selected[] = $row;
				$discount   = $this->estimate_discount_for_promotion( $decision->get_promotion(), $context );
				$total     += $discount;
				foreach ( $decision->get_promotion()->get_actions() as $action ) {
					if ( is_array( $action ) ) {
						$actions[] = array_merge(
							array( 'promotion_id' => $pid ),
							$action,
							array( 'estimated_discount' => $discount )
						);
					}
				}
			} else {
				$skipped[] = $row;
			}
		}

		PlannerContextCache::persist_counters();

		if ( $this->profiler !== null ) {
			$this->profiler->record_simulation_run( ( microtime( true ) - $started ) * 1000 );
		}

		return new SimulationResult(
			$eligible,
			$selected,
			$skipped,
			min( $total, $this->cart_subtotal( $context ) ),
			$plan->get_metrics(),
			$actions,
			$explained['summary_lines'] ?? array(),
			$warnings,
			$explained
		);
	}

	private function build_context( SimulationScenario $scenario ): EvaluationContext {
		$items = $scenario->get_items();
		$subtotal = 0.0;
		foreach ( $items as $item ) {
			if ( isset( $item['line_subtotal'] ) && is_numeric( $item['line_subtotal'] ) ) {
				$subtotal += (float) $item['line_subtotal'];
			}
		}

		$meta = $scenario->get_metadata();
		if ( ! empty( $meta['simulate_cooldown_active'] ) ) {
			$meta['promotion_cooldown_active'] = true;
		}

		return EvaluationContext::from_array(
			array(
				'customer_id'   => $scenario->get_customer_id(),
				'cart_subtotal' => $subtotal,
				'currency'      => 'USD',
				'items'         => $items,
				'metadata'      => $meta,
			)
		);
	}

	/**
	 * @return list<Promotion>
	 */
	private function resolve_promotions( SimulationScenario $scenario, ?array $filter_ids ): array {
		$ids = $scenario->get_promotion_ids();
		if ( $filter_ids !== null && $filter_ids !== array() ) {
			$ids = array_values( array_intersect( $ids, $filter_ids ) );
		}

		if ( $ids !== array() ) {
			$out = array();
			foreach ( $ids as $id ) {
				$p = $this->promotions->find( $id );
				if ( $p instanceof Promotion ) {
					$out[] = $p;
				}
			}
			return $out;
		}

		return $this->promotions->find_active( 50 );
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<Promotion>
	 */
	private function sort_promotions( array $promotions ): array {
		return PromotionPriorityTier::sort_promotions( $promotions );
	}

	private function estimate_discount_for_promotion( Promotion $promotion, EvaluationContext $context ): float {
		$subtotal = $this->cart_subtotal( $context );
		$discount = 0.0;

		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = isset( $action['type'] ) ? (string) $action['type'] : '';
			if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
				$pct = isset( $action['percentage'] ) ? (float) $action['percentage'] : 0.0;
				$discount += $subtotal * ( max( 0.0, min( 100.0, $pct ) ) / 100 );
			} elseif ( $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				$discount += isset( $action['amount'] ) ? (float) $action['amount'] : 0.0;
			} elseif ( $type === RuleTypes::ACTION_FREE_SHIPPING ) {
				$meta = $context->to_array()['metadata'] ?? array();
				$discount += is_array( $meta ) && isset( $meta['shipping_total'] )
					? (float) $meta['shipping_total']
					: 10.0;
			}
		}

		return max( 0.0, $discount );
	}

	private function cart_subtotal( EvaluationContext $context ): float {
		$subtotal = $context->get_cart_subtotal();
		return $subtotal !== null ? max( 0.0, $subtotal ) : 0.0;
	}
}
