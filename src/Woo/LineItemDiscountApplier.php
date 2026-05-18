<?php
/**
 * Mutates WooCommerce cart line prices for line_item / hybrid promotion modes (MVP).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Engine\AppliedLineDiscount;
use MP\CommercePromotions\Engine\DiscountAllocationEngine;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\LineDiscountAllocationResult;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionDryRunGuard;
use MP\CommercePromotions\Service\Settings;

final class LineItemDiscountApplier {

	private DiscountAllocationEngine $allocator;

	private Settings $settings;

	private CouponCoexistenceEvaluator $coupon_evaluator;

	public function __construct(
		?DiscountAllocationEngine $allocator = null,
		?Settings $settings = null,
		?CouponCoexistenceEvaluator $coupon_evaluator = null
	) {
		$this->allocator        = $allocator ?? new DiscountAllocationEngine();
		$this->settings         = $settings ?? new Settings();
		$this->coupon_evaluator = $coupon_evaluator ?? new CouponCoexistenceEvaluator();
	}

	/**
	 * @param object $cart WooCommerce cart.
	 * @param list<PromotionEvaluationDecision> $decisions
	 * @return LineDiscountAllocationResult
	 */
	public function apply_for_plan( $cart, PromotionEvaluationPlan $plan, EvaluationContext $context ): LineDiscountAllocationResult {
		$selected = array();
		foreach ( $plan->get_selected_decisions() as $decision ) {
			if ( $decision->is_selected() ) {
				$selected[] = $decision;
			}
		}

		if ( $selected === array() || ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return new LineDiscountAllocationResult( array(), 0.0 );
		}

		if ( $this->settings->safe_mode_enabled() ) {
			return new LineDiscountAllocationResult( array(), 0.0 );
		}

		$dry_run_guard = new PromotionDryRunGuard( $this->settings );
		if ( $dry_run_guard->is_global_dry_run() ) {
			return new LineDiscountAllocationResult( array(), 0.0 );
		}

		$line_capable = array();
		foreach ( $selected as $decision ) {
			$promotion = $decision->get_promotion();
			if ( $dry_run_guard->is_promotion_dry_run( $promotion ) ) {
				continue;
			}
			if ( ! PromotionDiscountApplicationMode::uses_line_mutation( $promotion->get_discount_application_mode() ) ) {
				continue;
			}
			if ( ! $this->promotion_has_line_capable_action( $promotion ) ) {
				LineDiscountFallbackTelemetry::record(
					LineDiscountFallbackTelemetry::REASON_UNSUPPORTED_ACTION,
					(int) ( $promotion->get_id() ?? 0 ),
					$promotion->get_discount_application_mode()
				);
				continue;
			}
			$line_capable[] = $decision;
		}

		if ( $line_capable === array() ) {
			return new LineDiscountAllocationResult( array(), 0.0 );
		}

		$allocation = $this->allocator->allocate( $context, $line_capable, true );
		$by_promo   = $this->amounts_by_promotion_and_line( $allocation, $line_capable );

		$applied_lines   = array();
		$fallback_events = array();
		$total           = 0.0;
		$cart_items      = $cart->get_cart();
		$tax_mode        = $this->estimate_tax_mode();

		foreach ( $line_capable as $decision ) {
			$promotion = $decision->get_promotion();
			$pid       = (int) ( $promotion->get_id() ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}

			$coupon_gate = $this->coupon_evaluator->evaluate_promotion( $promotion, $context, $cart );
			if ( empty( $coupon_gate['allowed'] ) ) {
				$fallback_events[] = $this->fallback_event(
					$pid,
					LineDiscountFallbackTelemetry::REASON_COUPON_CONFLICT
				);
				LineDiscountFallbackTelemetry::record(
					LineDiscountFallbackTelemetry::REASON_COUPON_CONFLICT,
					$pid
				);
				continue;
			}

			$action_type = $this->first_line_capable_action_type( $promotion );
			$line_map    = $by_promo[ $pid ] ?? array();

			foreach ( $line_map as $cart_item_key => $discount_amount ) {
				if ( $discount_amount <= 0 || ! isset( $cart_items[ $cart_item_key ] ) ) {
					continue;
				}

				$cart_item = $cart_items[ $cart_item_key ];
				if ( ! is_array( $cart_item ) ) {
					continue;
				}

				if ( ! empty( $cart_item[ FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT ] ) ) {
					continue;
				}

				if ( ! LinePriceMutationGuard::is_supported_product_type( $cart_item ) ) {
					$fallback_events[] = $this->fallback_event(
						$pid,
						LineDiscountFallbackTelemetry::REASON_UNSUPPORTED_PRODUCT_TYPE,
						$cart_item_key
					);
					LineDiscountFallbackTelemetry::record(
						LineDiscountFallbackTelemetry::REASON_UNSUPPORTED_PRODUCT_TYPE,
						$pid,
						$cart_item_key
					);
					continue;
				}

				if ( LinePriceMutationGuard::was_mutated_this_cycle( $cart_item_key ) ) {
					$fallback_events[] = $this->fallback_event(
						$pid,
						LineDiscountFallbackTelemetry::REASON_MUTATION_GUARD_TRIGGERED,
						$cart_item_key
					);
					LineDiscountFallbackTelemetry::record(
						LineDiscountFallbackTelemetry::REASON_MUTATION_GUARD_TRIGGERED,
						$pid,
						$cart_item_key
					);
					continue;
				}

				$mutated = $this->mutate_line_price(
					$cart,
					$cart_item_key,
					$discount_amount,
					$pid,
					$tax_mode
				);

				if ( ! $mutated ) {
					$fallback_events[] = $this->fallback_event(
						$pid,
						LineDiscountFallbackTelemetry::REASON_LINE_MUTATION_FAILED,
						$cart_item_key
					);
					LineDiscountFallbackTelemetry::record(
						LineDiscountFallbackTelemetry::REASON_LINE_MUTATION_FAILED,
						$pid,
						$cart_item_key
					);
					continue;
				}

				$product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
				$variation_id = isset( $cart_item['variation_id'] ) && (int) $cart_item['variation_id'] > 0
					? (int) $cart_item['variation_id']
					: null;
				$quantity     = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;

				$applied_lines[] = new AppliedLineDiscount(
					$cart_item_key,
					$product_id,
					$variation_id,
					$quantity,
					$discount_amount,
					$pid,
					$action_type,
					$tax_mode,
					array(
						'application_mode' => $promotion->get_discount_application_mode(),
					)
				);
				$total += $discount_amount;
				LineDiscountPlanCache::record_line_applied( $pid, $discount_amount );
				LinePriceMutationGuard::mark_mutated( $cart_item_key );
			}
		}

		return new LineDiscountAllocationResult(
			$applied_lines,
			$total,
			$fallback_events,
			array(
				'cycle' => LinePriceMutationGuard::get_cycle(),
			)
		);
	}

	/**
	 * @param list<PromotionEvaluationDecision> $decisions
	 * @return array<int, array<string, float>>
	 */
	private function amounts_by_promotion_and_line(
		\MP\CommercePromotions\Engine\AllocationResult $allocation,
		array $decisions
	): array {
		$map = array();
		foreach ( $allocation->get_line_allocations() as $slice ) {
			$row = $slice->to_array();
			$pid = isset( $row['promotion_id'] ) ? (int) $row['promotion_id'] : 0;
			$key = isset( $row['line_key'] ) ? (string) $row['line_key'] : '';
			if ( $pid <= 0 || $key === '' ) {
				continue;
			}
			if ( ! isset( $map[ $pid ] ) ) {
				$map[ $pid ] = array();
			}
			$map[ $pid ][ $key ] = ( $map[ $pid ][ $key ] ?? 0.0 ) + (float) ( $row['amount'] ?? 0.0 );
		}

		return $map;
	}

	private function promotion_has_line_capable_action( Promotion $promotion ): bool {
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = isset( $action['type'] ) ? (string) $action['type'] : '';
			if ( PromotionDiscountApplicationMode::is_line_capable_action( $type ) ) {
				return true;
			}
		}

		return false;
	}

	private function first_line_capable_action_type( Promotion $promotion ): string {
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = isset( $action['type'] ) ? (string) $action['type'] : '';
			if ( PromotionDiscountApplicationMode::is_line_capable_action( $type ) ) {
				return $type;
			}
		}

		return RuleTypes::ACTION_PERCENTAGE_DISCOUNT;
	}

	/**
	 * @param object $cart WooCommerce cart.
	 */
	private function mutate_line_price( $cart, string $cart_item_key, float $discount_amount, int $promotion_id, string $tax_mode ): bool {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return false;
		}

		$items = $cart->get_cart();
		if ( ! isset( $items[ $cart_item_key ] ) || ! is_array( $items[ $cart_item_key ] ) ) {
			return false;
		}

		$cart_item = $items[ $cart_item_key ];
		if ( ! isset( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
			return false;
		}

		$product = $cart_item['data'];
		if ( ! method_exists( $product, 'get_price' ) || ! method_exists( $product, 'set_price' ) ) {
			return false;
		}

		$quantity = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
		$unit     = (float) $product->get_price( 'edit' );
		if ( $unit <= 0 ) {
			$unit = (float) $product->get_price();
		}
		if ( $unit <= 0 ) {
			return false;
		}

		$line_total     = $unit * $quantity;
		$discount_total = min( $line_total, max( 0.0, $discount_amount ) );
		$new_line_total = max( 0.0, $line_total - $discount_total );
		$new_unit       = $new_line_total / $quantity;

		if ( $tax_mode === 'inclusive' && function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) {
			// Storefront prices include tax; mutation is unit-based only (no tax table edits).
		}

		if ( method_exists( $cart, 'cart_contents' ) && is_array( $cart->cart_contents ) ) {
			if ( ! isset( $cart->cart_contents[ $cart_item_key ][ AppliedLineDiscount::META_ORIGINAL_PRICE ] ) ) {
				$cart->cart_contents[ $cart_item_key ][ AppliedLineDiscount::META_ORIGINAL_PRICE ] = $unit;
			}
			$cart->cart_contents[ $cart_item_key ][ AppliedLineDiscount::META_MUTATED_BY ] = $promotion_id;
		}

		$product->set_price( $new_unit );

		return true;
	}

	private function estimate_tax_mode(): string {
		if ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) {
			return 'inclusive';
		}

		return 'exclusive';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fallback_event( int $promotion_id, string $reason, ?string $cart_item_key = null ): array {
		return array(
			'promotion_id'   => $promotion_id,
			'reason_code'    => $reason,
			'cart_item_key'  => $cart_item_key,
		);
	}
}
