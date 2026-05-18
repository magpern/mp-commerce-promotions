<?php
/**
 * Applies promotion discounts as negative cart fees (automatic or via coupon code).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionCode;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Domain\PromotionPriorityTier;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;
use MP\CommercePromotions\Service\PlannerTelemetryRecorder;
use MP\CommercePromotions\Service\PromotionConcurrencyGuard;
use MP\CommercePromotions\Service\PromotionDryRunGuard;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\Settings;
use Throwable;

final class CartPromotionApplier {

	public const SESSION_KEY = 'mp_cp_applied_promotion';

	public const ACTION_PERCENTAGE_DISCOUNT = 'percentage_discount';

	public const ACTION_FIXED_AMOUNT_DISCOUNT = 'fixed_amount_discount';

	public const ACTION_FREE_SHIPPING = 'free_shipping';

	public const ACTION_CHEAPEST_ITEM_DISCOUNT = 'cheapest_item_discount';

	public const ACTION_FREE_GIFT_PRODUCT = 'free_gift_product';

	private PromotionRepository $promotions;

	private PromotionCodeRepository $promotion_codes;

	private PromotionEvaluator $evaluator;

	private PromotionPlanner $planner;

	private CartContextBuilder $context_builder;

	private Settings $settings;

	private FreeGiftCartHandler $free_gift_handler;

	private FreeGiftCartSynchronizer $gift_synchronizer;

	private ?PlannerTelemetryRecorder $telemetry_recorder;

	private ?PromotionPerformanceProfiler $profiler;

	private LineItemDiscountApplier $line_discount_applier;

	private PromotionDryRunGuard $dry_run_guard;

	public function __construct(
		PromotionRepository $promotions,
		PromotionCodeRepository $promotion_codes,
		PromotionEvaluator $evaluator,
		CartContextBuilder $context_builder,
		Settings $settings,
		?PromotionPlanner $planner = null,
		?FreeGiftCartHandler $free_gift_handler = null,
		?FreeGiftCartSynchronizer $gift_synchronizer = null,
		?PlannerTelemetryRecorder $telemetry_recorder = null,
		?PromotionPerformanceProfiler $profiler = null,
		?LineItemDiscountApplier $line_discount_applier = null
	) {
		$this->promotions         = $promotions;
		$this->promotion_codes    = $promotion_codes;
		$this->evaluator          = $evaluator;
		$this->planner            = $planner ?? new PromotionPlanner( $evaluator );
		$this->context_builder    = $context_builder;
		$this->settings           = $settings;
		$this->free_gift_handler  = $free_gift_handler ?? new FreeGiftCartHandler();
		$this->gift_synchronizer   = $gift_synchronizer ?? new FreeGiftCartSynchronizer( $promotions );
		$this->telemetry_recorder  = $telemetry_recorder;
		$this->profiler              = $profiler;
		$this->line_discount_applier = $line_discount_applier ?? new LineItemDiscountApplier();
		$this->dry_run_guard         = new PromotionDryRunGuard( $settings );
	}

	/**
	 * Prepare line-level discounts before Woo totals (priority 15).
	 */
	public function prepare_line_discount_cycle(): void {
		if ( ! apply_filters( 'mp_cp_enable_cart_discounts', $this->settings->cart_discounts_enabled() ) ) {
			return;
		}

		if ( $this->settings->safe_mode_enabled() ) {
			LinePriceMutationGuard::restore_all_line_prices( function_exists( 'WC' ) ? WC()->cart : null );
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! is_object( WC()->cart ?? null ) ) {
			return;
		}

		$cart = WC()->cart;
		LinePriceMutationGuard::restore_all_line_prices( $cart );
		LineDiscountPlanCache::reset();
		LinePriceMutationGuard::begin_cycle();

		if ( ! $this->settings->automatic_promotions_enabled() ) {
			CartSessionHelper::clear_line_allocations();
			return;
		}

		if ( $this->dry_run_guard->is_global_dry_run() ) {
			CartSessionHelper::clear_line_allocations();
			return;
		}

		$context    = $this->context_builder->build_from_cart();
		$paid_subtotal = FreeGiftCartHandler::paid_cart_subtotal( $cart );
		$subtotal   = $paid_subtotal > 0 ? $paid_subtotal : ( $context->get_cart_subtotal() ?? 0.0 );
		if ( $subtotal <= 0 ) {
			// WooCommerce may run an early totals pass before line subtotals exist; do not wipe a later pass.
			return;
		}

		$active = PromotionPriorityTier::sort_promotions( $this->promotions->find_active_for_planner( 200 ) );
		$plan   = $this->plan_with_resilience( $active, $context );
		if ( $plan === null ) {
			CartSessionHelper::clear_line_allocations();
			return;
		}

		$signature = LineDiscountPlanCache::signature_for_cart( $cart );
		LineDiscountPlanCache::store( $plan, $context, null, $signature );

		$allocation = $this->line_discount_applier->apply_for_plan( $cart, $plan, $context );
		LineDiscountPlanCache::store( $plan, $context, $allocation, $signature );
		CartSessionHelper::set_line_allocations( $allocation->to_array() );
	}

	/**
	 * Zero gift line prices before WooCommerce totals (woocommerce_before_calculate_totals).
	 */
	public function zero_free_gift_line_prices(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) ) {
			return;
		}

		FreeGiftCartHandler::zero_gift_line_prices( $wc->cart );
	}

	/**
	 * WooCommerce cart fee hook: apply selected promotion(s) as negative fee(s).
	 */
	public function apply(): void {
		/**
		 * Disable cart discount fees (admin setting or custom code).
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Whether to run cart promotion fee logic.
		 */
		if ( ! apply_filters( 'mp_cp_enable_cart_discounts', $this->settings->cart_discounts_enabled() ) ) {
			$this->clear_applied_promotion_session();
			CartSessionHelper::clear_line_allocations();
			if ( function_exists( 'WC' ) ) {
				$wc_disabled = WC();
				if ( is_object( $wc_disabled ) && isset( $wc_disabled->cart ) && is_object( $wc_disabled->cart ) ) {
					$this->gift_synchronizer->sync( $wc_disabled->cart, array() );
				}
			}
			return;
		}

		if ( $this->settings->safe_mode_enabled() && ! $this->settings->allow_codes_in_safe_mode() ) {
			$this->clear_applied_promotion_session();
			if ( function_exists( 'WC' ) && is_object( WC()->cart ?? null ) ) {
				$this->gift_synchronizer->sync( WC()->cart, array() );
			}
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) ) {
			return;
		}

		$cart = $wc->cart;
		if ( ! method_exists( $cart, 'add_fee' ) ) {
			return;
		}

		$context       = $this->context_builder->build_from_cart();
		$paid_subtotal = FreeGiftCartHandler::paid_cart_subtotal( $cart );
		$subtotal      = $paid_subtotal > 0 ? $paid_subtotal : ( $context->get_cart_subtotal() ?? 0.0 );
		if ( $subtotal <= 0 ) {
			// Early totals pass (subtotal not computed yet); avoid clearing line/session state for a later pass.
			return;
		}

		if ( $this->try_apply_via_applied_coupon_codes( $cart, $context, $subtotal ) ) {
			return;
		}

		if ( ! $this->settings->automatic_promotions_enabled() ) {
			$this->clear_applied_promotion_session();
			$this->gift_synchronizer->sync( $cart, array() );
			return;
		}

		$this->apply_automatic_promotions( $cart, $context, $subtotal );
	}

	/**
	 * When a WooCommerce coupon matches a promotion code, apply only that linked promotion (no automatic stack).
	 *
	 * @param object $cart WooCommerce cart.
	 */
	private function try_apply_via_applied_coupon_codes( $cart, EvaluationContext $context, float $subtotal ): bool {
		if ( ! method_exists( $cart, 'get_applied_coupons' ) ) {
			return false;
		}

		$coupons = $cart->get_applied_coupons();
		if ( ! is_array( $coupons ) || count( $coupons ) === 0 ) {
			return false;
		}

		foreach ( $coupons as $coupon_code ) {
			if ( ! is_string( $coupon_code ) || $coupon_code === '' ) {
				continue;
			}

			$promotion_code = $this->promotion_codes->find_by_plain_code( $coupon_code );
			if ( $promotion_code === null ) {
				continue;
			}

			if ( ! $this->promotion_codes->is_code_usable( $promotion_code ) ) {
				$this->clear_applied_promotion_session();
				return true;
			}

			$promotion_id = $promotion_code->get_promotion_id();
			$promotion    = $this->promotions->find( $promotion_id );
			if ( $promotion === null ) {
				$this->clear_applied_promotion_session();
				return true;
			}

			$plan = $this->plan_with_resilience( array( $promotion ), $context );
			if ( $plan === null ) {
				$this->clear_applied_promotion_session();
				return true;
			}
			$this->record_plan_telemetry( $plan );
			$decisions = $plan->get_selected_decisions();
			$entries  = $this->apply_selected_decisions(
				$decisions,
				$context,
				$subtotal,
				$cart,
				$promotion_code
			);

			$this->gift_synchronizer->sync(
				$cart,
				$this->desired_gifts_for_decisions( $decisions )
			);

			if ( $entries !== array() ) {
				$this->store_applied_promotions_session( $entries );
				return true;
			}

			$this->clear_applied_promotion_session();
			return true;
		}

		return false;
	}

	/**
	 * @param object $cart WooCommerce cart.
	 */
	private function apply_automatic_promotions( $cart, EvaluationContext $context, float $subtotal ): void {
		$active = PromotionPriorityTier::sort_promotions( $this->promotions->find_active_for_planner( 200 ) );
		$plan   = $this->plan_with_resilience( $active, $context );
		if ( $plan === null ) {
			$this->clear_applied_promotion_session();
			$this->gift_synchronizer->sync( $cart, array() );
			return;
		}
		$this->record_plan_telemetry( $plan );
		$decisions = $plan->get_selected_decisions();
		$entries   = $this->apply_selected_decisions(
			$decisions,
			$context,
			$subtotal,
			$cart,
			null
		);

		$this->gift_synchronizer->sync(
			$cart,
			$this->desired_gifts_for_decisions( $decisions )
		);

		if ( $entries !== array() ) {
			$this->store_applied_promotions_session( $entries );
			return;
		}

		$this->clear_applied_promotion_session();
	}

	/**
	 * @param list<PromotionEvaluationDecision> $decisions
	 * @return list<array{
	 *     promotion_id: int,
	 *     product_id: int,
	 *     variation_id: int|null,
	 *     quantity: int,
	 *     promotion: Promotion
	 * }>
	 */
	private function desired_gifts_for_decisions( array $decisions ): array {
		if ( $this->dry_run_guard->is_global_dry_run() ) {
			return array();
		}

		return $this->collect_desired_gifts_from_decisions( $decisions );
	}

	/**
	 * @param list<PromotionEvaluationDecision> $decisions
	 * @return list<array{
	 *     promotion_id: int,
	 *     product_id: int,
	 *     variation_id: int|null,
	 *     quantity: int,
	 *     promotion: Promotion
	 * }>
	 */
	private function collect_desired_gifts_from_decisions( array $decisions ): array {
		$desired = array();

		foreach ( $decisions as $decision ) {
			if ( ! $decision->is_selected() ) {
				continue;
			}

			$result = $decision->get_result();
			if ( ! $result->is_eligible() ) {
				continue;
			}

			$promotion = $decision->get_promotion();
			$pid       = $promotion->get_id();
			if ( $pid === null || $pid <= 0 ) {
				continue;
			}

			if ( $this->dry_run_guard->is_promotion_dry_run( $promotion ) ) {
				continue;
			}

			foreach ( $result->get_action_results() as $action_row ) {
				if ( ! is_array( $action_row ) ) {
					continue;
				}
				$type = isset( $action_row['type'] ) ? (string) $action_row['type'] : '';
				if ( $type !== self::ACTION_FREE_GIFT_PRODUCT ) {
					continue;
				}

				$payload = isset( $action_row['payload'] ) && is_array( $action_row['payload'] )
					? $action_row['payload']
					: array();

				$product_id = isset( $payload['product_id'] ) ? (int) $payload['product_id'] : 0;
				$quantity   = isset( $payload['quantity'] ) && is_numeric( $payload['quantity'] )
					? (int) $payload['quantity']
					: 0;
				if ( $product_id <= 0 || $quantity < 1 ) {
					continue;
				}

				$variation_id = null;
				if ( isset( $payload['variation_id'] ) && is_numeric( $payload['variation_id'] ) && (int) $payload['variation_id'] > 0 ) {
					$variation_id = (int) $payload['variation_id'];
				}

				$desired[] = array(
					'promotion_id'  => $pid,
					'product_id'    => $product_id,
					'variation_id'  => $variation_id,
					'quantity'      => $quantity,
					'promotion'     => $promotion,
				);
			}
		}

		return $desired;
	}

	/**
	 * @param list<PromotionEvaluationDecision> $decisions
	 * @param object                          $cart
	 * @return list<array<string, mixed>>
	 */
	private function apply_selected_decisions(
		array $decisions,
		EvaluationContext $context,
		float $subtotal,
		$cart,
		?PromotionCode $promotion_code
	): array {
		$remaining_allowance = $subtotal;
		$session_entries     = array();

		foreach ( $decisions as $decision ) {
			$promotion = $decision->get_promotion();
			$pid       = (int) ( $promotion->get_id() ?? 0 );
			$mode      = $promotion->get_discount_application_mode();

			if ( $this->dry_run_guard->is_promotion_dry_run( $promotion ) ) {
				$preview = $this->build_dry_run_session_entry(
					$decision,
					$context,
					$subtotal,
					$remaining_allowance,
					$cart,
					$promotion_code
				);
				if ( $preview !== null ) {
					$session_entries[] = $preview;
				}
				continue;
			}

			if ( PromotionDiscountApplicationMode::uses_line_mutation( $mode ) && $pid > 0 ) {
				$line_total = LineDiscountPlanCache::get_line_applied_total( $pid );
				if ( $line_total > 0 ) {
					$entry = $this->build_line_applied_session_entry( $decision, $line_total, $promotion_code );
					if ( $entry !== null ) {
						$session_entries[] = $entry;
						$remaining_allowance -= $line_total;
						continue;
					}
				}

				if ( $mode === PromotionDiscountApplicationMode::LINE_ITEM ) {
					continue;
				}

				LineDiscountPlanCache::mark_fee_fallback( $pid );
			}

			$applied = $this->apply_first_discount_fee_for_decision(
				$decision,
				$context,
				$subtotal,
				$remaining_allowance,
				$cart,
				$promotion_code
			);

			if ( ! is_array( $applied ) ) {
				continue;
			}

			if ( $pid > 0 && LineDiscountPlanCache::should_fee_fallback( $pid ) ) {
				$applied['fee_fallback'] = true;
			}

			$entry = $this->build_session_entry_from_applied( $applied, $promotion_code );
			if ( $entry === null ) {
				continue;
			}

			$session_entries[] = $entry;
			$applied_type        = (string) $applied['action_type'];
			if ( $applied_type !== self::ACTION_FREE_SHIPPING && $applied_type !== self::ACTION_FREE_GIFT_PRODUCT ) {
				$remaining_allowance -= (float) $applied['discount'];
			}
		}

		return $session_entries;
	}

	/**
	 * @param array<string, mixed> $applied
	 * @return array<string, mixed>|null
	 */
	private function build_session_entry_from_applied( array $applied, ?PromotionCode $promotion_code ): ?array {
		if ( ! isset( $applied['promotion'] ) || ! $applied['promotion'] instanceof Promotion ) {
			return null;
		}

		$promotion = $applied['promotion'];
		$pid       = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return null;
		}

		$entry = array(
			'promotion_id'    => $pid,
			'promotion_uuid'  => $promotion->get_uuid(),
			'promotion_name'  => $promotion->get_name(),
			'discount_amount' => (float) $applied['discount'],
			'action_type'     => (string) $applied['action_type'],
		);

		if ( isset( $applied['percentage'] ) ) {
			$entry['percentage'] = (float) $applied['percentage'];
		}
		if ( isset( $applied['fixed_amount'] ) ) {
			$entry['fixed_amount'] = (float) $applied['fixed_amount'];
		}
		if ( isset( $applied['product_id'] ) ) {
			$entry['product_id'] = (int) $applied['product_id'];
		}
		if ( array_key_exists( 'variation_id', $applied ) ) {
			$entry['variation_id'] = $applied['variation_id'] !== null ? (int) $applied['variation_id'] : null;
		}
		if ( isset( $applied['quantity'] ) ) {
			$entry['quantity'] = (int) $applied['quantity'];
		}

		if ( $promotion_code !== null ) {
			$code_id = $promotion_code->get_id();
			if ( $code_id !== null && $code_id > 0 ) {
				$entry['promotion_code_id'] = $code_id;
			}
			$entry['promotion_code_last4'] = $promotion_code->get_code_last4();
			$entry['entered_code_hash']    = $promotion_code->get_code_hash();
		}

		if ( $this->dry_run_guard->is_promotion_dry_run( $promotion ) ) {
			$entry['dry_run_mode'] = true;
			$entry['skip_reason']  = PromotionDryRunGuard::REASON_DRY_RUN_MODE;
		}

		return $entry;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function build_line_applied_session_entry(
		PromotionEvaluationDecision $decision,
		float $line_total,
		?PromotionCode $promotion_code
	): ?array {
		$promotion = $decision->get_promotion();
		$pid       = $promotion->get_id();
		if ( $pid === null || $pid <= 0 || $line_total <= 0 ) {
			return null;
		}

		$action_type = self::ACTION_PERCENTAGE_DISCOUNT;
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = isset( $action['type'] ) ? (string) $action['type'] : '';
			if ( PromotionDiscountApplicationMode::is_line_capable_action( $type ) ) {
				$action_type = $type;
				break;
			}
		}

		$applied = array(
			'promotion'   => $promotion,
			'discount'    => $line_total,
			'action_type' => $action_type,
		);

		$entry = $this->build_session_entry_from_applied( $applied, $promotion_code );
		if ( $entry === null ) {
			return null;
		}

		$entry['application_strategy'] = $promotion->get_discount_application_mode();
		$entry['line_discount_applied'] = true;
		$line_payload = CartSessionHelper::get_line_allocations();
		if ( is_array( $line_payload ) ) {
			$entry['line_allocation_summary'] = $line_payload;
		}

		return $entry;
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 */
	private function store_applied_promotions_session( array $entries ): void {
		if ( ! CartSessionHelper::has_wc_session() || $entries === array() ) {
			return;
		}

		$payload = AppliedPromotionSession::build_session_payload( $entries );
		if ( $payload === array() ) {
			return;
		}

		CartSessionHelper::set_applied_promotion( $payload );
	}

	private function clear_applied_promotion_session(): void {
		CartSessionHelper::clear_applied_promotion();
		CartSessionHelper::clear_line_allocations();
	}

	/**
	 * @param object             $cart           WooCommerce cart (WC_Cart).
	 * @param PromotionCode|null $promotion_code When set, fee label uses masked code last4.
	 * @return array<string, mixed>|false
	 */
	private function apply_first_discount_fee_for_decision(
		PromotionEvaluationDecision $decision,
		EvaluationContext $context,
		float $cart_subtotal,
		float $remaining_allowance,
		$cart,
		?PromotionCode $promotion_code
	) {
		$promotion = $decision->get_promotion();
		$result    = $decision->get_result();
		if ( ! $decision->is_selected() || ! $result->is_eligible() ) {
			return false;
		}

		foreach ( $result->get_action_results() as $action_row ) {
			if ( ! is_array( $action_row ) ) {
				continue;
			}

			$type    = isset( $action_row['type'] ) ? (string) $action_row['type'] : '';
			$payload = isset( $action_row['payload'] ) && is_array( $action_row['payload'] )
				? $action_row['payload']
				: array();

			if ( $type === self::ACTION_PERCENTAGE_DISCOUNT ) {
				$applied = $this->apply_percentage_discount_fee(
					$promotion,
					$payload,
					$cart_subtotal,
					$remaining_allowance,
					$cart,
					$promotion_code
				);
				if ( is_array( $applied ) ) {
					return $applied;
				}
				continue;
			}

			if ( $type === self::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				$applied = $this->apply_fixed_amount_discount_fee(
					$promotion,
					$payload,
					$remaining_allowance,
					$cart,
					$promotion_code
				);
				if ( is_array( $applied ) ) {
					return $applied;
				}
				continue;
			}

			if ( $type === self::ACTION_FREE_SHIPPING ) {
				if ( ! $this->settings->free_shipping_enabled() ) {
					continue;
				}
				$applied = $this->apply_free_shipping_fee(
					$promotion,
					$cart,
					$promotion_code
				);
				if ( is_array( $applied ) ) {
					return $applied;
				}
				continue;
			}

			if ( $type === self::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
				$applied = $this->apply_cheapest_item_discount_fee(
					$promotion,
					$payload,
					$remaining_allowance,
					$cart,
					$promotion_code
				);
				if ( is_array( $applied ) ) {
					return $applied;
				}
				continue;
			}

			if ( $type === self::ACTION_FREE_GIFT_PRODUCT ) {
				if ( ! $this->settings->free_gift_enabled() ) {
					continue;
				}
				$applied = $this->apply_free_gift_product( $promotion, $payload, $cart );
				if ( is_array( $applied ) ) {
					return $applied;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param object               $cart
	 * @return array<string, mixed>|false
	 */
	private function apply_free_gift_product( Promotion $promotion, array $payload, $cart ) {
		if ( ! isset( $payload['product_id'] ) || ! is_numeric( $payload['product_id'] ) ) {
			return false;
		}

		$product_id = (int) $payload['product_id'];
		$quantity   = isset( $payload['quantity'] ) && is_numeric( $payload['quantity'] ) ? (int) $payload['quantity'] : 0;
		if ( $product_id <= 0 || $quantity < 1 ) {
			return false;
		}

		$variation_id = null;
		if ( isset( $payload['variation_id'] ) && is_numeric( $payload['variation_id'] ) && (int) $payload['variation_id'] > 0 ) {
			$variation_id = (int) $payload['variation_id'];
		}

		$gift_payload = array(
			'product_id' => $product_id,
			'quantity'   => $quantity,
		);
		if ( $variation_id !== null ) {
			$gift_payload['variation_id'] = $variation_id;
		}

		if ( ! $this->dry_run_guard->should_apply_storefront( $promotion ) ) {
			return array(
				'promotion'    => $promotion,
				'discount'     => 0.0,
				'action_type'  => self::ACTION_FREE_GIFT_PRODUCT,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => $quantity,
			);
		}

		if ( ! $this->free_gift_handler->apply_gift( $promotion, $gift_payload, $cart ) ) {
			return false;
		}

		return array(
			'promotion'    => $promotion,
			'discount'     => 0.0,
			'action_type'  => self::ACTION_FREE_GIFT_PRODUCT,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => $quantity,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param object               $cart
	 * @return array<string, mixed>|false
	 */
	private function apply_percentage_discount_fee(
		Promotion $promotion,
		array $payload,
		float $cart_subtotal,
		float $remaining_allowance,
		$cart,
		?PromotionCode $promotion_code
	) {
		if ( ! isset( $payload['percentage'] ) || ! is_numeric( $payload['percentage'] ) ) {
			return false;
		}

		$pct = (float) $payload['percentage'];
		if ( $pct <= 0 || $pct > 100 ) {
			return false;
		}

		if ( isset( $payload['calculated_discount'] ) && is_numeric( $payload['calculated_discount'] ) ) {
			$discount = (float) $payload['calculated_discount'];
		} else {
			$discount = $cart_subtotal * $pct / 100.0;
		}

		$discount = DiscountCapAllocator::clamp_to_remaining( $discount, $remaining_allowance );
		if ( $discount <= 0 ) {
			return false;
		}

		$this->add_promotion_fee( $cart, $promotion, $discount, $promotion_code, 'default' );

		return array(
			'promotion'   => $promotion,
			'discount'    => $discount,
			'action_type' => self::ACTION_PERCENTAGE_DISCOUNT,
			'percentage'  => $pct,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param object               $cart
	 * @return array<string, mixed>|false
	 */
	private function apply_fixed_amount_discount_fee(
		Promotion $promotion,
		array $payload,
		float $remaining_allowance,
		$cart,
		?PromotionCode $promotion_code
	) {
		if ( ! empty( $payload['not_applicable'] ) ) {
			return false;
		}

		if ( isset( $payload['applied_discount'] ) && is_numeric( $payload['applied_discount'] ) ) {
			$configured = (float) $payload['applied_discount'];
		} elseif ( isset( $payload['amount'] ) && is_numeric( $payload['amount'] ) ) {
			$configured = (float) $payload['amount'];
		} else {
			return false;
		}

		if ( $configured <= 0 ) {
			return false;
		}

		$discount = DiscountCapAllocator::clamp_to_remaining( $configured, $remaining_allowance );
		if ( $discount <= 0 ) {
			return false;
		}

		$this->add_promotion_fee( $cart, $promotion, $discount, $promotion_code, 'default' );

		return array(
			'promotion'    => $promotion,
			'discount'     => $discount,
			'action_type'  => self::ACTION_FIXED_AMOUNT_DISCOUNT,
			'fixed_amount' => $configured,
		);
	}

	/**
	 * @param object $cart WooCommerce cart (WC_Cart).
	 * @return array<string, mixed>|false
	 */
	private function apply_free_shipping_fee(
		Promotion $promotion,
		$cart,
		?PromotionCode $promotion_code
	) {
		$shipping_total = $this->resolve_cart_shipping_total( $cart );
		if ( $shipping_total === null || $shipping_total <= 0 ) {
			return false;
		}

		$this->add_promotion_fee(
			$cart,
			$promotion,
			$shipping_total,
			$promotion_code,
			'free_shipping'
		);

		return array(
			'promotion'   => $promotion,
			'discount'    => $shipping_total,
			'action_type' => self::ACTION_FREE_SHIPPING,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param object               $cart
	 * @return array<string, mixed>|false
	 */
	private function apply_cheapest_item_discount_fee(
		Promotion $promotion,
		array $payload,
		float $remaining_allowance,
		$cart,
		?PromotionCode $promotion_code
	) {
		if ( ! isset( $payload['discount_amount'] ) || ! is_numeric( $payload['discount_amount'] ) ) {
			return false;
		}

		if ( ! empty( $payload['not_applicable'] ) ) {
			return false;
		}

		$configured = (float) $payload['discount_amount'];
		if ( $configured <= 0 ) {
			return false;
		}

		$discount = DiscountCapAllocator::clamp_to_remaining( $configured, $remaining_allowance );
		if ( $discount <= 0 ) {
			return false;
		}

		$this->add_promotion_fee(
			$cart,
			$promotion,
			$discount,
			$promotion_code,
			'cheapest_item_discount'
		);

		return array(
			'promotion'   => $promotion,
			'discount'    => $discount,
			'action_type' => self::ACTION_CHEAPEST_ITEM_DISCOUNT,
		);
	}

	/**
	 * @param object $cart WooCommerce cart (WC_Cart).
	 */
	private function resolve_cart_shipping_total( $cart ): ?float {
		if ( ! method_exists( $cart, 'get_shipping_total' ) ) {
			return null;
		}

		if ( method_exists( $cart, 'calculate_totals' ) ) {
			$cart->calculate_totals();
		}

		$total = (float) $cart->get_shipping_total();
		if ( $total < 0 ) {
			return null;
		}

		return $total;
	}

	/**
	 * @param object             $cart WooCommerce cart (WC_Cart).
	 * @param PromotionCode|null $promotion_code
	 */
	private function add_promotion_fee(
		$cart,
		Promotion $promotion,
		float $discount,
		?PromotionCode $promotion_code,
		string $label_kind = 'default'
	): void {
		if ( $promotion_code !== null ) {
			$last4 = sanitize_text_field( $promotion_code->get_code_last4() );
			if ( $label_kind === 'free_shipping' ) {
				$label = sprintf(
					/* translators: %s: last four characters of the promotion code */
					__( 'Commerce promotion code: Free shipping ****%s', 'mp-commerce-promotions' ),
					$last4
				);
			} elseif ( $label_kind === 'cheapest_item_discount' ) {
				$label = sprintf(
					/* translators: %s: last four characters of the promotion code */
					__( 'Commerce promotion code: Cheapest item discount ****%s', 'mp-commerce-promotions' ),
					$last4
				);
			} else {
				$label = sprintf(
					/* translators: %s: last four characters of the promotion code */
					__( 'Commerce promotion code: ****%s', 'mp-commerce-promotions' ),
					$last4
				);
			}
		} else {
			$name = sanitize_text_field( $promotion->get_name() );
			if ( $name === '' ) {
				$name = __( 'Promotion', 'mp-commerce-promotions' );
			}

			if ( $label_kind === 'free_shipping' ) {
				$label = sprintf(
					/* translators: %s: sanitized promotion name */
					__( 'Commerce promotion: Free shipping - %s', 'mp-commerce-promotions' ),
					$name
				);
			} elseif ( $label_kind === 'cheapest_item_discount' ) {
				$label = sprintf(
					/* translators: %s: sanitized promotion name */
					__( 'Commerce promotion: Cheapest item discount - %s', 'mp-commerce-promotions' ),
					$name
				);
			} else {
				$label = sprintf(
					/* translators: %s: sanitized promotion name */
					__( 'Commerce promotion: %s', 'mp-commerce-promotions' ),
					$name
				);
			}
		}

		if ( ! $this->dry_run_guard->should_apply_storefront( $promotion ) ) {
			return;
		}

		$cart->add_fee( $label, -$discount, false );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function build_dry_run_session_entry(
		PromotionEvaluationDecision $decision,
		EvaluationContext $context,
		float $subtotal,
		float $remaining_allowance,
		$cart,
		?PromotionCode $promotion_code
	): ?array {
		$applied = $this->apply_first_discount_fee_for_decision(
			$decision,
			$context,
			$subtotal,
			$remaining_allowance,
			$cart,
			$promotion_code
		);

		if ( ! is_array( $applied ) ) {
			return null;
		}

		$entry = $this->build_session_entry_from_applied( $applied, $promotion_code );

		return $entry;
	}

	/**
	 * @param list<Promotion> $promotions
	 */
	private function plan_with_resilience( array $promotions, EvaluationContext $context ): PromotionEvaluationPlan {
		$guard = new PromotionConcurrencyGuard();
		if ( ! $guard->acquire_planner_lock() ) {
			return new PromotionEvaluationPlan( array(), array( 'planner_lock_contention' => true ) );
		}

		try {
			return $this->planner->plan( $promotions, $context );
		} catch ( Throwable $e ) {
			if ( $this->profiler !== null ) {
				$this->profiler->record_planner_failure( $e->getMessage() );
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[mp-commerce-promotions] Planner failed safely: ' . $e->getMessage() );
			}

			return new PromotionEvaluationPlan( array(), array( 'planner_failed' => true ) );
		} finally {
			$guard->release_planner_lock();
		}
	}

	private function record_plan_telemetry( PromotionEvaluationPlan $plan ): void {
		if ( $this->telemetry_recorder === null ) {
			return;
		}

		$this->telemetry_recorder->record_plan( $plan );
	}
}
