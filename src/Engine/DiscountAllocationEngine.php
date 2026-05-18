<?php
/**
 * Proportional discount allocation across cart lines and shipping (no line mutation).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionAllocationMode;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Woo\CartItemSelector;
use MP\CommercePromotions\Woo\TaxAwareDiscountCalculator;

final class DiscountAllocationEngine {

	private TaxAwareDiscountCalculator $tax_calculator;

	public function __construct( ?TaxAwareDiscountCalculator $tax_calculator = null ) {
		$this->tax_calculator = $tax_calculator ?? new TaxAwareDiscountCalculator();
	}

	/**
	 * @param list<PromotionEvaluationDecision> $selected_decisions
	 */
	public function allocate(
		EvaluationContext $context,
		array $selected_decisions,
		bool $use_cache = true
	): AllocationResult {
		$promotion_ids = array();
		foreach ( $selected_decisions as $decision ) {
			if ( ! $decision->is_selected() ) {
				continue;
			}
			$pid = $decision->get_promotion_id();
			if ( $pid > 0 ) {
				$promotion_ids[] = $pid;
			}
		}

		$cache_key = AllocationContextCache::cache_key( $context, $promotion_ids );
		if ( $use_cache ) {
			$cached = AllocationContextCache::get_allocation( $cache_key );
			if ( $cached instanceof AllocationResult ) {
				return $cached;
			}
		}

		$started = microtime( true );

		$lines           = $this->resolve_lines( $context );
		$line_subtotal   = $this->sum_line_subtotals( $lines );
		$shipping_total  = $this->shipping_total( $context );
		$cart_basis      = max( 0.01, $line_subtotal + $shipping_total );

		$line_allocations     = array();
		$shipping_allocations = array();
		$promotion_totals     = array();

		foreach ( $selected_decisions as $decision ) {
			if ( ! $decision->is_selected() ) {
				continue;
			}

			$promotion = $decision->get_promotion();
			$pid       = (int) ( $promotion->get_id() ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}

			$breakdown = $this->estimate_promotion_discount_breakdown( $promotion, $context, $lines, $shipping_total );
			$promotion_totals[ $pid ] = $breakdown;

			foreach ( $breakdown['line_amounts'] as $line_key => $amount ) {
				if ( $amount <= 0 ) {
					continue;
				}
				$line_allocations[] = new AllocatedDiscount(
					$pid,
					AllocatedDiscount::TARGET_LINE,
					(string) $line_key,
					$this->product_id_for_line( $lines, (string) $line_key ),
					$amount,
					$line_subtotal > 0 ? ( $amount / $line_subtotal ) * 100 : 0.0,
					array( 'allocation_mode' => $promotion->get_allocation_mode() )
				);
			}

			if ( $breakdown['shipping_amount'] > 0 ) {
				$shipping_allocations[] = new AllocatedDiscount(
					$pid,
					AllocatedDiscount::TARGET_SHIPPING,
					null,
					null,
					$breakdown['shipping_amount'],
					$shipping_total > 0 ? ( $breakdown['shipping_amount'] / $shipping_total ) * 100 : 100.0,
					array( 'type' => RuleTypes::ACTION_FREE_SHIPPING )
				);
			}
		}

		$total_allocated = 0.0;
		foreach ( $line_allocations as $slice ) {
			$total_allocated += $slice->get_amount();
		}
		foreach ( $shipping_allocations as $slice ) {
			$total_allocated += $slice->get_amount();
		}

		$effective_rate = $cart_basis > 0 ? ( $total_allocated / $cart_basis ) * 100 : 0.0;
		$tax_meta         = $this->tax_calculator->estimate_for_allocation( $context, $total_allocated, $shipping_total );

		$result = new AllocationResult(
			$line_allocations,
			$shipping_allocations,
			$total_allocated,
			$effective_rate,
			$tax_meta,
			array(
				'line_subtotal'     => round( $line_subtotal, 4 ),
				'shipping_total'    => round( $shipping_total, 4 ),
				'promotion_totals'  => $promotion_totals,
				'allocation_mode'   => PromotionAllocationMode::PROPORTIONAL,
			)
		);

		AllocationContextCache::store_allocation( $cache_key, $result );
		AllocationContextCache::record_allocation_timing( ( microtime( true ) - $started ) * 1000 );

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function resolve_lines( EvaluationContext $context ): array {
		$scope_key = 'lines_' . hash( 'sha256', wp_json_encode( $context->to_array() ) ?? '' );
		$cached    = AllocationContextCache::get_scoped_lines( $scope_key );
		if ( $cached !== null ) {
			return $cached;
		}

		$items = $context->to_array()['items'] ?? array();
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$lines = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$key = isset( $item['item_key'] ) ? (string) $item['item_key'] : 'line_' . $index;
			$lines[ $key ] = $item;
		}

		AllocationContextCache::store_scoped_lines( $scope_key, $lines );

		return $lines;
	}

	/**
	 * @param list<array<string, mixed>>|array<string, array<string, mixed>> $lines
	 */
	private function sum_line_subtotals( array $lines ): float {
		$sum = 0.0;
		foreach ( $lines as $line ) {
			if ( isset( $line['line_subtotal'] ) && is_numeric( $line['line_subtotal'] ) ) {
				$sum += (float) $line['line_subtotal'];
			}
		}

		return max( 0.0, $sum );
	}

	private function shipping_total( EvaluationContext $context ): float {
		$meta = $context->to_array()['metadata'] ?? array();
		if ( ! is_array( $meta ) ) {
			return 0.0;
		}

		if ( isset( $meta['shipping_total'] ) && is_numeric( $meta['shipping_total'] ) ) {
			return max( 0.0, (float) $meta['shipping_total'] );
		}

		if ( isset( $meta['shipping_methods'] ) && is_array( $meta['shipping_methods'] ) ) {
			$sum = 0.0;
			foreach ( $meta['shipping_methods'] as $method ) {
				if ( is_array( $method ) && isset( $method['cost'] ) && is_numeric( $method['cost'] ) ) {
					$sum += (float) $method['cost'];
				}
			}
			return max( 0.0, $sum );
		}

		return 0.0;
	}

	/**
	 * @param list<array<string, mixed>>|array<string, array<string, mixed>> $lines
	 * @return array{line_amounts: array<string, float>, shipping_amount: float, total: float}
	 */
	private function estimate_promotion_discount_breakdown(
		Promotion $promotion,
		EvaluationContext $context,
		array $lines,
		float $shipping_total
	): array {
		$line_amounts     = array();
		$shipping_amount  = 0.0;
		$line_subtotal    = $this->sum_line_subtotals( $lines );
		$mode             = $promotion->get_allocation_mode();

		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}

			$type = isset( $action['type'] ) ? (string) $action['type'] : '';

			if ( $type === RuleTypes::ACTION_FREE_SHIPPING ) {
				$shipping_amount += $shipping_total;
				continue;
			}

			if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
				$pct         = isset( $action['percentage'] ) ? (float) $action['percentage'] : 0.0;
				$pct         = max( 0.0, min( 100.0, $pct ) );
				$scope_lines = $this->scoped_lines_for_action( $lines, $action );
				$scope_sub   = $this->sum_line_subtotals( $scope_lines );
				$this->distribute_amount( $line_amounts, $scope_lines, $scope_sub * ( $pct / 100 ), $mode );
				continue;
			}

			if ( $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				$amount      = isset( $action['amount'] ) ? (float) $action['amount'] : 0.0;
				$scope_lines = $this->scoped_lines_for_action( $lines, $action );
				$scope_sub   = $this->sum_line_subtotals( $scope_lines );
				$this->distribute_amount( $line_amounts, $scope_lines, min( $amount, $scope_sub ), $mode );
				continue;
			}

			if ( $type === RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
				$scope_lines = $this->scoped_lines_for_action( $lines, $action );
				$scope_sub   = $this->sum_line_subtotals( $scope_lines );
				$pct         = isset( $action['discount_percentage'] ) ? (float) $action['discount_percentage'] : 100.0;
				$this->distribute_amount(
					$line_amounts,
					$scope_lines,
					$scope_sub * ( max( 0.0, min( 100.0, $pct ) ) / 100 ),
					$mode
				);
			}
		}

		$total = $shipping_amount;
		foreach ( $line_amounts as $amt ) {
			$total += $amt;
		}

		return array(
			'line_amounts'    => $line_amounts,
			'shipping_amount' => $shipping_amount,
			'total'           => $total,
		);
	}

	/**
	 * @param array<string, float>              $line_amounts
	 * @param array<string, array<string, mixed>> $lines
	 */
	private function distribute_amount( array &$line_amounts, array $lines, float $amount, string $mode ): void {
		if ( $amount <= 0 || $lines === array() ) {
			return;
		}

		$subtotal = $this->sum_line_subtotals( $lines );
		if ( $subtotal <= 0 ) {
			return;
		}

		if ( $mode === PromotionAllocationMode::LINE_EQUAL ) {
			$share = $amount / count( $lines );
			foreach ( array_keys( $lines ) as $key ) {
				$line_amounts[ $key ] = ( $line_amounts[ $key ] ?? 0.0 ) + $share;
			}
			return;
		}

		foreach ( $lines as $key => $line ) {
			$line_sub = isset( $line['line_subtotal'] ) ? (float) $line['line_subtotal'] : 0.0;
			if ( $line_sub <= 0 ) {
				continue;
			}
			$line_amounts[ $key ] = ( $line_amounts[ $key ] ?? 0.0 ) + ( $amount * ( $line_sub / $subtotal ) );
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $lines
	 * @param array<string, mixed>                $action
	 * @return list<array<string, mixed>>
	 */
	private function scoped_lines_for_action( array $lines, array $action ): array {
		$product_ids   = isset( $action['product_ids'] ) && is_array( $action['product_ids'] ) ? $action['product_ids'] : array();
		$variation_ids = isset( $action['variation_ids'] ) && is_array( $action['variation_ids'] ) ? $action['variation_ids'] : array();
		$category_ids  = isset( $action['category_ids'] ) && is_array( $action['category_ids'] ) ? $action['category_ids'] : array();

		if ( $product_ids === array() && $variation_ids === array() && $category_ids === array() ) {
			return $lines;
		}

		$scoped = array();
		foreach ( $lines as $key => $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			if ( $product_ids !== array() || $variation_ids !== array() ) {
				if ( ! CartItemSelector::item_matches_product_or_variation(
					$line,
					array_map( 'intval', $product_ids ),
					array_map( 'intval', $variation_ids )
				) ) {
					continue;
				}
			}
			if ( $category_ids !== array() ) {
				$cats = isset( $line['category_ids'] ) && is_array( $line['category_ids'] ) ? $line['category_ids'] : array();
				if ( array_intersect( array_map( 'intval', $category_ids ), array_map( 'intval', $cats ) ) === array() ) {
					continue;
				}
			}
			$scoped[ (string) $key ] = $line;
		}

		return $scoped;
	}

	/**
	 * @param array<string, array<string, mixed>> $lines
	 */
	private function product_id_for_line( array $lines, string $line_key ): ?int {
		if ( ! isset( $lines[ $line_key ]['product_id'] ) ) {
			return null;
		}

		$pid = (int) $lines[ $line_key ]['product_id'];

		return $pid > 0 ? $pid : null;
	}
}
