<?php
/**
 * Quote mp-commerce-promotions line discounts from snapshots (no set_price).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\BulkPricing\BulkPricingMoney;
use MP\CommercePromotions\BulkPricing\LinePriceSnapshot;
use MP\CommercePromotions\BulkPricing\PromotionLineQuote;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Engine\DiscountAllocationEngine;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\GiftCard\GiftCardPromotionExclusion;
use MP\CommercePromotions\Service\PromotionDryRunGuard;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\CouponCoexistenceEvaluator;
use MP\CommercePromotions\Woo\FreeGiftCartHandler;
use MP\CommercePromotions\Woo\LineItemDiscountApplier;
use MP\CommercePromotions\Woo\LinePriceMutationGuard;

final class PromotionLineQuoteService {

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
	 * @param object $cart
	 * @param array<string, LinePriceSnapshot> $snapshots
	 * @return array<string, PromotionLineQuote>
	 */
	public function quote_for_plan(
		$cart,
		PromotionEvaluationPlan $plan,
		EvaluationContext $context,
		array $snapshots
	): array {
		$quotes = array();

		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) || $snapshots === array() ) {
			return $quotes;
		}

		if ( $this->settings->safe_mode_enabled() || $this->settings->line_item_mode_disabled() ) {
			return $quotes;
		}

		$dry_run_guard = new PromotionDryRunGuard( $this->settings );
		if ( $dry_run_guard->is_global_dry_run() ) {
			return $quotes;
		}

		$selected = array();
		foreach ( $plan->get_selected_decisions() as $decision ) {
			if ( $decision->is_selected() ) {
				$selected[] = $decision;
			}
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
				continue;
			}
			$line_capable[] = $decision;
		}

		if ( $line_capable === array() ) {
			return $quotes;
		}

		$allocation = $this->allocator->allocate( $context, $line_capable, true );
		$by_promo   = $this->amounts_by_promotion_and_line( $allocation, $line_capable );
		$cart_items = $cart->get_cart();

		foreach ( $line_capable as $decision ) {
			$promotion = $decision->get_promotion();
			$pid       = (int) ( $promotion->get_id() ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}

			$coupon_gate = $this->coupon_evaluator->evaluate_promotion( $promotion, $context, $cart );
			if ( empty( $coupon_gate['allowed'] ) ) {
				continue;
			}

			$action_type = $this->first_line_capable_action_type( $promotion );
			$line_map    = $by_promo[ $pid ] ?? array();

			foreach ( $line_map as $cart_item_key => $discount_amount ) {
				if ( $discount_amount <= 0 || ! isset( $cart_items[ $cart_item_key ] ) || ! isset( $snapshots[ $cart_item_key ] ) ) {
					continue;
				}

				$cart_item = $cart_items[ $cart_item_key ];
				if ( ! is_array( $cart_item ) ) {
					continue;
				}

				if ( ! empty( $cart_item[ FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT ] ) ) {
					continue;
				}

				if ( GiftCardPromotionExclusion::wc_cart_item_is_gift_card( $cart_item ) ) {
					continue;
				}

				if ( ! LinePriceMutationGuard::is_supported_product_type( $cart_item ) ) {
					continue;
				}

				$snapshot = $snapshots[ $cart_item_key ];
				$qty      = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
				$standard = BulkPricingMoney::line_total_minor( $snapshot->get_display_unit_minor(), $qty );
				$discount_minor = BulkPricingMoney::to_minor( (float) $discount_amount, $snapshot->get_decimals() );
				$line_total     = max( 0, $standard - $discount_minor );

				if ( ! isset( $quotes[ $cart_item_key ] ) || $line_total < $quotes[ $cart_item_key ]->get_line_total_minor() ) {
					$quotes[ $cart_item_key ] = new PromotionLineQuote( $line_total, $pid, $action_type );
				}
			}
		}

		return $quotes;
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
}
