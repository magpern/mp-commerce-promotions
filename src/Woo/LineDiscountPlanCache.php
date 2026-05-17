<?php
/**
 * Request-scoped cache of planner output shared between totals hooks.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\LineDiscountAllocationResult;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;

final class LineDiscountPlanCache {

	private static ?PromotionEvaluationPlan $plan = null;

	private static ?EvaluationContext $context = null;

	private static ?LineDiscountAllocationResult $allocation_result = null;

	private static string $cart_signature = '';

	/** @var array<int, float> */
	private static array $line_applied_totals_by_promotion = array();

	/** @var array<int, true> */
	private static array $fee_fallback_promotion_ids = array();

	public static function reset(): void {
		self::$plan               = null;
		self::$context            = null;
		self::$allocation_result  = null;
		self::$cart_signature     = '';
		self::$line_applied_totals_by_promotion = array();
		self::$fee_fallback_promotion_ids     = array();
	}

	public static function store(
		PromotionEvaluationPlan $plan,
		EvaluationContext $context,
		?LineDiscountAllocationResult $allocation_result,
		string $cart_signature
	): void {
		self::$plan              = $plan;
		self::$context           = $context;
		self::$allocation_result = $allocation_result;
		self::$cart_signature    = $cart_signature;
	}

	public static function get_plan(): ?PromotionEvaluationPlan {
		return self::$plan;
	}

	public static function get_context(): ?EvaluationContext {
		return self::$context;
	}

	public static function get_allocation_result(): ?LineDiscountAllocationResult {
		return self::$allocation_result;
	}

	public static function get_cart_signature(): string {
		return self::$cart_signature;
	}

	public static function record_line_applied( int $promotion_id, float $amount ): void {
		if ( $promotion_id <= 0 ) {
			return;
		}
		self::$line_applied_totals_by_promotion[ $promotion_id ] = ( self::$line_applied_totals_by_promotion[ $promotion_id ] ?? 0.0 ) + $amount;
	}

	public static function get_line_applied_total( int $promotion_id ): float {
		return self::$line_applied_totals_by_promotion[ $promotion_id ] ?? 0.0;
	}

	public static function has_line_applied( int $promotion_id ): bool {
		return ( self::$line_applied_totals_by_promotion[ $promotion_id ] ?? 0.0 ) > 0;
	}

	public static function mark_fee_fallback( int $promotion_id ): void {
		if ( $promotion_id > 0 ) {
			self::$fee_fallback_promotion_ids[ $promotion_id ] = true;
		}
	}

	public static function should_fee_fallback( int $promotion_id ): bool {
		return isset( self::$fee_fallback_promotion_ids[ $promotion_id ] );
	}

	/**
	 * @param object $cart WooCommerce cart.
	 */
	public static function signature_for_cart( $cart ): string {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return '';
		}

		$items = $cart->get_cart();
		if ( ! is_array( $items ) ) {
			return '';
		}

		$parts = array();
		foreach ( $items as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$qty = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$parts[] = $key . ':' . $pid . 'x' . $qty;
		}

		sort( $parts );

		return hash( 'sha256', implode( '|', $parts ) );
	}
}
