<?php
/**
 * Read-only validation of promotion conditions/actions against supported engine types.
 *
 * Storefront note: WooCommerce products that sell gift cards are excluded from promotion
 * discounts by default (see GiftCardPromotionExclusion).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Domain\PromotionPriorityTier;
use MP\CommercePromotions\Service\LineDiscountModeHelper;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\PromotionDateHelper;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\Action\FreeGiftProductAction;
use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\Action\FreeShippingAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\CategoryQuantityCondition;
use MP\CommercePromotions\Engine\Condition\CustomerAverageOrderValueCondition;
use MP\CommercePromotions\Engine\Condition\CustomerLifetimeSpendCondition;
use MP\CommercePromotions\Engine\Condition\CustomerOrderCountCondition;
use MP\CommercePromotions\Engine\Condition\BillingCountryCondition;
use MP\CommercePromotions\Engine\Condition\CustomerEmailDomainCondition;
use MP\CommercePromotions\Engine\Condition\CustomerRedemptionCountCondition;
use MP\CommercePromotions\Engine\Condition\CustomerRoleCondition;
use MP\CommercePromotions\Engine\Condition\MaximumCartQuantityCondition;
use MP\CommercePromotions\Engine\Condition\MinimumCartQuantityCondition;
use MP\CommercePromotions\Engine\Condition\MaximumEligibleSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\MinimumEligibleSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\MinimumSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\ProductQuantityCondition;
use MP\CommercePromotions\Engine\Condition\QuantityComparator;
use MP\CommercePromotions\Engine\RuleRegistry;
use MP\CommercePromotions\Engine\RuleTypes;

final class PromotionRuleValidator {

	private ?PromotionScheduleAnalyzer $schedule_analyzer = null;

	private ?PromotionConflictAnalyzer $conflict_analyzer = null;

	private ?Settings $settings = null;

	/**
	 * @return list<array{level: string, message: string}>
	 */
	public function validate( Promotion $promotion ): array {
		$issues = array();

		$this->append_status_issues( $promotion, $issues );
		$this->append_usage_limit_issues( $promotion, $issues );
		$this->append_application_rules_issues( $promotion, $issues );
		$this->append_orchestration_issues( $promotion, $issues );
		$this->append_condition_issues( $promotion->get_conditions(), $issues );
		$this->append_segmentation_condition_warnings( $promotion->get_conditions(), $issues );
		$this->append_action_issues( $promotion->get_actions(), $issues );
		$this->append_conflict_heuristic_issues( $promotion, $issues );
		$this->append_operational_maturity_issues( $promotion, $issues );
		$this->append_intelligence_issues( $promotion, $issues );
		$this->append_pricing_issues( $promotion, $issues );
		$this->append_settings_gated_action_issues( $promotion->get_actions(), $issues );

		return $issues;
	}

	/**
	 * @param list<Promotion> $catalog Peer promotions for schedule/economics checks.
	 * @return list<array{level: string, message: string}>
	 */
	public function validate_with_catalog( Promotion $promotion, array $catalog ): array {
		$issues = $this->validate( $promotion );
		$this->append_economics_issues( $promotion, $catalog, $issues );

		return $issues;
	}

	/**
	 * @param list<Promotion>                             $catalog
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_economics_issues( Promotion $promotion, array $catalog, array &$issues ): void {
		if ( $promotion->has_budget_cap() && ( $promotion->get_budget_currency() === null || $promotion->get_budget_currency() === '' ) ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Budget amount is set without a budget currency.', 'mp-commerce-promotions' ),
			);
		}

		if ( $promotion->get_status() === PromotionStatus::ACTIVE ) {
			$ends = PromotionDateHelper::parse_mysql_datetime( $promotion->get_ends_at() );
			if ( $ends !== null && PromotionDateHelper::now_timestamp() > $ends ) {
				$issues[] = array(
					'level'   => 'warning',
					'message' => __( 'Promotion is active but the end date is in the past.', 'mp-commerce-promotions' ),
				);
			}
		}

		if ( $promotion->get_ends_at() === null || trim( (string) $promotion->get_ends_at() ) === '' ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __( 'No end date is configured for this promotion.', 'mp-commerce-promotions' ),
			);
		}

		$schedule_rows = $this->schedule_analyzer()->analyze( $catalog, $promotion );
		foreach ( $schedule_rows as $row ) {
			$severity = isset( $row['severity'] ) ? (string) $row['severity'] : 'info';
			$level    = $severity === 'warning' ? 'warning' : 'info';
			$issues[] = array(
				'level'   => $level,
				'message' => isset( $row['message'] ) ? (string) $row['message'] : '',
			);
		}

		$subject_id = $promotion->get_id();
		if ( $subject_id !== null && $subject_id > 0 ) {
			foreach ( $this->conflict_analyzer()->analyze( $catalog ) as $conflict ) {
				$type = isset( $conflict['type'] ) ? (string) $conflict['type'] : '';
				if ( $type !== PromotionConflictAnalyzer::TYPE_FREE_SHIPPING_OVERLAP ) {
					continue;
				}
				$ids = isset( $conflict['promotion_ids'] ) && is_array( $conflict['promotion_ids'] )
					? $conflict['promotion_ids']
					: array();
				if ( ! in_array( $subject_id, $ids, true ) ) {
					continue;
				}
				$issues[] = array(
					'level'   => 'warning',
					'message' => isset( $conflict['message'] ) ? (string) $conflict['message'] : '',
				);
				break;
			}
		}

		$stackable_overlaps = 0;
		foreach ( $catalog as $peer ) {
			if ( ! $peer instanceof Promotion ) {
				continue;
			}
			if ( $peer->get_status() !== PromotionStatus::ACTIVE ) {
				continue;
			}
			if ( $peer->get_application_mode() !== PromotionApplicationMode::STACKABLE ) {
				continue;
			}
			$peer_id = $peer->get_id();
			if ( $peer_id === null || $peer_id <= 0 ) {
				continue;
			}
			if ( $subject_id !== null && $peer_id === $subject_id ) {
				continue;
			}
			if ( $this->promotions_overlap_in_time( $promotion, $peer ) ) {
				++$stackable_overlaps;
			}
		}

		if ( $promotion->get_application_mode() === PromotionApplicationMode::STACKABLE
			&& $promotion->get_status() === PromotionStatus::ACTIVE
			&& $stackable_overlaps > 0 ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => sprintf(
					/* translators: %d: number of overlapping stackable promotions */
					__( 'This stackable promotion overlaps %d other active stackable promotion(s) in time.', 'mp-commerce-promotions' ),
					$stackable_overlaps
				),
			);
		}
	}

	private function promotions_overlap_in_time( Promotion $a, Promotion $b ): bool {
		$start_a = PromotionDateHelper::parse_mysql_datetime( $a->get_starts_at() );
		$end_a   = PromotionDateHelper::parse_mysql_datetime( $a->get_ends_at() );
		$start_b = PromotionDateHelper::parse_mysql_datetime( $b->get_starts_at() );
		$end_b   = PromotionDateHelper::parse_mysql_datetime( $b->get_ends_at() );

		$range_start_a = $start_a ?? PHP_INT_MIN;
		$range_end_a   = $end_a ?? PHP_INT_MAX;
		$range_start_b = $start_b ?? PHP_INT_MIN;
		$range_end_b   = $end_b ?? PHP_INT_MAX;

		return $range_start_a <= $range_end_b && $range_start_b <= $range_end_a;
	}

	private function schedule_analyzer(): PromotionScheduleAnalyzer {
		if ( $this->schedule_analyzer === null ) {
			$this->schedule_analyzer = new PromotionScheduleAnalyzer();
		}

		return $this->schedule_analyzer;
	}

	private function conflict_analyzer(): PromotionConflictAnalyzer {
		if ( $this->conflict_analyzer === null ) {
			$this->conflict_analyzer = new PromotionConflictAnalyzer();
		}

		return $this->conflict_analyzer;
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_usage_limit_issues( Promotion $promotion, array &$issues ): void {
		$usage_limit = $promotion->get_usage_limit();
		if ( $usage_limit !== null && $usage_limit < 1 ) {
			$issues[] = $this->error(
				__( 'usage_limit must be null or at least 1.', 'mp-commerce-promotions' )
			);
		}

		$customer_limit = $promotion->get_customer_usage_limit();
		if ( $customer_limit !== null && $customer_limit < 1 ) {
			$issues[] = $this->error(
				__( 'customer_usage_limit must be null or at least 1.', 'mp-commerce-promotions' )
			);
		}
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_orchestration_issues( Promotion $promotion, array &$issues ): void {
		$cooldown = $promotion->get_cooldown_hours();
		if ( $cooldown !== null && $cooldown < 1 ) {
			$issues[] = $this->error(
				__( 'cooldown_hours must be null or at least 1.', 'mp-commerce-promotions' )
			);
		}

		if ( $cooldown !== null ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __(
					'Cooldown blocks repeat redemptions for the same logged-in customer until the configured hours pass after the last recorded redemption.',
					'mp-commerce-promotions'
				),
			);
		}

		$group = $promotion->get_orchestration_group();
		if ( $group !== null && $group !== '' ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __(
					'Orchestration group allows only one selected promotion per group in a cart evaluation plan (first eligible by priority wins).',
					'mp-commerce-promotions'
				),
			);
		}
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_operational_maturity_issues( Promotion $promotion, array &$issues ): void {
		$cooldown = $promotion->get_cooldown_hours();
		if ( $cooldown !== null && $cooldown > 0 && ! $this->conditions_include_logged_in( $promotion->get_conditions() ) ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Cooldown is configured but no logged-in customer condition is present.', 'mp-commerce-promotions' ),
			);
		}

		$group = $promotion->get_orchestration_group();
		if ( $group !== null && $group !== '' ) {
			$normalized = Promotion::normalize_orchestration_group( $group );
			if ( $normalized !== $group ) {
				$issues[] = $this->error(
					__( 'Orchestration group contains invalid characters and must be normalized before activation.', 'mp-commerce-promotions' )
				);
			}
		}

		if (
			$promotion->get_status() === PromotionStatus::ACTIVE
			&& $promotion->get_ends_at() === null
			&& $promotion->get_usage_limit() === null
		) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Promotion has no end date and no usage limit (unlimited campaign).', 'mp-commerce-promotions' ),
			);
		}

		if ( $promotion->get_status() === PromotionStatus::ACTIVE && count( $promotion->get_actions() ) === 0 ) {
			$issues[] = $this->error(
				__( 'Active promotion has no actions configured.', 'mp-commerce-promotions' )
			);
		}

		if ( $promotion->get_status() === PromotionStatus::ACTIVE ) {
			$ends = PromotionDateHelper::parse_mysql_datetime( $promotion->get_ends_at() );
			if ( $ends !== null && PromotionDateHelper::now_timestamp() > $ends ) {
				$issues[] = array(
					'level'   => 'warning',
					'message' => __( 'Lifecycle conflict: promotion is active but past its end date.', 'mp-commerce-promotions' ),
				);
			}
			$starts = PromotionDateHelper::parse_mysql_datetime( $promotion->get_starts_at() );
			if ( $starts !== null && PromotionDateHelper::now_timestamp() < $starts ) {
				$issues[] = array(
					'level'   => 'warning',
					'message' => __( 'Lifecycle conflict: promotion is active but before its start date.', 'mp-commerce-promotions' ),
				);
			}
		}
	}

	/**
	 * Simulation, forecasting, and campaign intelligence warnings (heuristic).
	 *
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_intelligence_issues( Promotion $promotion, array &$issues ): void {
		$cooldown = $promotion->get_cooldown_hours();
		$starts   = PromotionDateHelper::parse_mysql_datetime( $promotion->get_starts_at() );
		$ends     = PromotionDateHelper::parse_mysql_datetime( $promotion->get_ends_at() );
		if (
			$cooldown !== null
			&& $cooldown > 0
			&& $starts !== null
			&& $ends !== null
			&& $ends > $starts
		) {
			$duration_hours = ( $ends - $starts ) / 3600;
			if ( $cooldown > $duration_hours ) {
				$issues[] = array(
					'level'   => 'warning',
					'message' => __( 'Cooldown duration exceeds campaign window (customers may never redeem twice).', 'mp-commerce-promotions' ),
				);
			}
		}

		$budget = $promotion->get_budget_amount();
		if ( $budget !== null && $budget > 0 ) {
			$max_discount = $this->estimate_max_action_discount( $promotion->get_actions() );
			if ( $max_discount > $budget ) {
				$issues[] = array(
					'level'   => 'warning',
					'message' => __( 'Configured discount may exceed budget before usage limits are reached.', 'mp-commerce-promotions' ),
				);
			}
		}

		$free_shipping_count = 0;
		foreach ( $promotion->get_actions() as $action ) {
			if ( is_array( $action ) && ( $action['type'] ?? '' ) === RuleTypes::ACTION_FREE_SHIPPING ) {
				++$free_shipping_count;
			}
		}
		if ( $free_shipping_count > 0 && $promotion->get_application_mode() === PromotionApplicationMode::STACKABLE ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Stackable promotion includes free shipping; overlapping stackable shipping may overload checkout.', 'mp-commerce-promotions' ),
			);
		}

		$scoped_discounts = 0;
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = (string) ( $action['type'] ?? '' );
			if ( in_array( $type, array( RuleTypes::ACTION_PERCENTAGE_DISCOUNT, RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ), true )
				&& isset( $action['product_ids'], $action['category_ids'] )
				&& ( $action['product_ids'] !== array() || $action['category_ids'] !== array() ) ) {
				++$scoped_discounts;
			}
		}
		if ( $scoped_discounts > 2 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Many scoped discount actions on one promotion may overlap and confuse planner selection.', 'mp-commerce-promotions' ),
			);
		}

		$group = $promotion->get_orchestration_group();
		if ( $group !== null && $group !== '' && $promotion->get_priority() > 1000 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Very high priority with orchestration group may block peer promotions unexpectedly.', 'mp-commerce-promotions' ),
			);
		}

		if ( $promotion->get_status() === PromotionStatus::ACTIVE && count( $promotion->get_conditions() ) === 0 && count( $promotion->get_actions() ) === 0 ) {
			$issues[] = $this->error(
				__( 'Active promotion has no eligible products or actions (empty rules).', 'mp-commerce-promotions' )
			);
		}

		$forecast_cache = get_option( PromotionForecastEngine::OPTION_CACHE, null );
		if ( is_array( $forecast_cache ) && isset( $forecast_cache['promotions'] ) && ! is_array( $forecast_cache['promotions'] ) ) {
			$issues[] = $this->error(
				__( 'Forecast cache is corrupted; reset via Diagnostics → Intelligence recovery.', 'mp-commerce-promotions' )
			);
		}
	}

	/**
	 * @param list<array<string, mixed>> $actions
	 */
	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_pricing_issues( Promotion $promotion, array &$issues ): void {
		if ( ! PromotionCouponBehavior::is_valid( $promotion->get_coupon_behavior() ) ) {
			$issues[] = $this->error(
				__( 'Corrupted coupon_behavior configuration; repair via Diagnostics.', 'mp-commerce-promotions' )
			);
		}

		if ( ! PromotionPriorityTier::is_valid( $promotion->get_priority_tier() ) ) {
			$issues[] = $this->error(
				__( 'Invalid priority_tier value.', 'mp-commerce-promotions' )
			);
		}

		$free_shipping = 0;
		$percent_count = 0;
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = (string) ( $action['type'] ?? '' );
			if ( $type === RuleTypes::ACTION_FREE_SHIPPING ) {
				++$free_shipping;
			}
			if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
				$pct = (float) ( $action['percentage'] ?? 0 );
				if ( $pct >= 50 ) {
					++$percent_count;
				}
			}
		}

		if ( $free_shipping > 0 && $percent_count > 0 && $promotion->get_application_mode() === PromotionApplicationMode::STACKABLE ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Shipping plus high percentage discounts may exceed profitable margins (heuristic).', 'mp-commerce-promotions' ),
			);
		}

		if ( $promotion->get_coupon_behavior() === PromotionCouponBehavior::COEXIST && $free_shipping > 0 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Coupon coexistence with free shipping may overload checkout discounts.', 'mp-commerce-promotions' ),
			);
		}

		$app_mode = $promotion->get_discount_application_mode();
		if ( ! PromotionDiscountApplicationMode::is_valid( $app_mode ) ) {
			$issues[] = $this->error(
				__( 'Invalid discount_application_mode; repair via Diagnostics.', 'mp-commerce-promotions' )
			);
		}

		if ( PromotionDiscountApplicationMode::uses_line_mutation( $app_mode ) ) {
			$has_line_action = false;
			foreach ( $promotion->get_actions() as $action ) {
				if ( ! is_array( $action ) ) {
					continue;
				}
				$type = (string) ( $action['type'] ?? '' );
				if ( PromotionDiscountApplicationMode::is_line_capable_action( $type ) ) {
					$has_line_action = true;
				}
			}
			if ( ! $has_line_action ) {
				$issues[] = array(
					'level'   => 'warning',
					'message' => __( 'Line-item discount mode is enabled but no percentage or fixed amount actions are configured; storefront may apply no discount.', 'mp-commerce-promotions' ),
				);
			}
			foreach ( $promotion->get_actions() as $action ) {
				if ( ! is_array( $action ) ) {
					continue;
				}
				$type = (string) ( $action['type'] ?? '' );
				$msg  = LineDiscountModeHelper::per_action_fee_fallback_message( $type );
				if ( $msg !== null ) {
					$issues[] = array(
						'level'   => 'warning',
						'message' => $msg,
					);
				}
			}
			if ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) {
				$issues[] = array(
					'level'   => 'warning',
					'message' => __( 'Tax-inclusive catalog: line price mutation does not adjust tax tables; verify totals on staging.', 'mp-commerce-promotions' ),
				);
			}
		}
	}

	/**
	 * @param list<array<string, mixed>>                  $actions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_settings_gated_action_issues( array $actions, array &$issues ): void {
		$settings = $this->settings ?? new Settings();
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = (string) ( $action['type'] ?? '' );
			if ( $type === RuleTypes::ACTION_FREE_GIFT_PRODUCT && ! $settings->free_gift_enabled() ) {
				$issues[] = array(
					'level'   => 'warning',
					'message' => __( 'Free gift actions are disabled in Settings; this promotion will not add gift lines on the storefront.', 'mp-commerce-promotions' ),
				);
			}
			if ( $type === RuleTypes::ACTION_FREE_SHIPPING && ! $settings->free_shipping_enabled() ) {
				$issues[] = array(
					'level'   => 'warning',
					'message' => __( 'Free shipping actions are disabled in Settings; this promotion will not apply shipping offsets.', 'mp-commerce-promotions' ),
				);
			}
		}
	}

	private function estimate_max_action_discount( array $actions ): float {
		$max = 0.0;
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = (string) ( $action['type'] ?? '' );
			if ( $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				$max = max( $max, (float) ( $action['amount'] ?? 0 ) );
			}
			if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
				$max = max( $max, 9999.0 );
			}
		}

		return $max;
	}

	/**
	 * @param array<mixed> $conditions
	 */
	private function conditions_include_logged_in( array $conditions ): bool {
		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}
			if ( ( $condition['type'] ?? '' ) === RuleTypes::CONDITION_LOGGED_IN ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<mixed>                                $conditions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_segmentation_condition_warnings( array $conditions, array &$issues ): void {
		$segmentation_types = array(
			RuleTypes::CONDITION_CUSTOMER_LIFETIME_SPEND,
			RuleTypes::CONDITION_CUSTOMER_ORDER_COUNT,
			RuleTypes::CONDITION_CUSTOMER_AVERAGE_ORDER_VALUE,
		);

		$has_segmentation = false;
		$has_logged_in    = false;

		foreach ( $conditions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$type = isset( $raw['type'] ) ? (string) $raw['type'] : '';
			if ( in_array( $type, $segmentation_types, true ) ) {
				$has_segmentation = true;
			}
			if ( $type === RuleTypes::CONDITION_LOGGED_IN ) {
				$has_logged_in = true;
			}
		}

		if ( $has_segmentation && ! $has_logged_in ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __(
					'Customer segmentation conditions require a logged-in customer; add a logged_in condition or expect guests to fail eligibility.',
					'mp-commerce-promotions'
				),
			);
		}
	}

	private function append_application_rules_issues( Promotion $promotion, array &$issues ): void {
		$mode = $promotion->get_application_mode();
		if ( ! PromotionApplicationMode::is_valid( $mode ) ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => __( 'Invalid application_mode. Allowed values: exclusive, stackable.', 'mp-commerce-promotions' ),
			);
			return;
		}

		$max = $promotion->get_max_applications();
		if ( $max !== null && $max < 1 ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => __( 'max_applications must be null or at least 1.', 'mp-commerce-promotions' ),
			);
		}

		if ( $max !== null ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __(
					'Max applications limits how many promotions may be selected in one cart evaluation plan (not per-customer usage). The plan cap is the minimum max_applications among selected promotions.',
					'mp-commerce-promotions'
				),
			);
		}

		if ( $mode === PromotionApplicationMode::EXCLUSIVE && $max !== null && $max > 1 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __(
					'Exclusive promotions stop further selections when stop processing is enabled; max_applications above 1 may have no effect unless stop processing is off.',
					'mp-commerce-promotions'
				),
			);
		}

		$excluded = $promotion->get_excluded_promotion_ids();
		$own_id   = $promotion->get_id();
		if ( $own_id !== null && $own_id > 0 && in_array( $own_id, $excluded, true ) ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => __( 'A promotion cannot exclude itself.', 'mp-commerce-promotions' ),
			);
		}

		if ( count( $excluded ) > 0 ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __(
					'When this promotion is selected, listed promotion IDs are skipped in the plan even if eligible. Exclusions apply only to promotions evaluated later (priority/order).',
					'mp-commerce-promotions'
				),
			);
		}
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_status_issues( Promotion $promotion, array &$issues ): void {
		$status = $promotion->get_status();

		if ( $status === PromotionStatus::ARCHIVED ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __( 'Archived promotions do not run.', 'mp-commerce-promotions' ),
			);
			return;
		}

		if ( $status === PromotionStatus::DRAFT || $status === PromotionStatus::PAUSED ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __( 'Promotion is not active and will not run.', 'mp-commerce-promotions' ),
			);
		}
	}

	/**
	 * @param array<mixed>                                $conditions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_condition_issues( array $conditions, array &$issues ): void {
		if ( count( $conditions ) === 0 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Promotion has no conditions and may apply broadly.', 'mp-commerce-promotions' ),
			);
			return;
		}

		foreach ( $conditions as $index => $raw ) {
			$this->validate_condition_entry( (int) $index, $raw, $issues );
		}
	}

	/**
	 * @param mixed                                       $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_condition_entry( int $index, $raw, array &$issues ): void {
		if ( ! is_array( $raw ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'Condition at index %s must be an object.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$type = isset( $raw['type'] ) ? trim( (string) $raw['type'] ) : '';
		if ( $type === '' ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'Condition at index %s has no type.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! RuleRegistry::is_supported_condition( $type ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: condition type string */
					__( 'Unknown condition type: %s', 'mp-commerce-promotions' ),
					$type
				)
			);
			return;
		}

		if ( $type === RuleTypes::CONDITION_MINIMUM_SUBTOTAL ) {
			$this->validate_minimum_subtotal( $index, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_PRODUCT_QUANTITY ) {
			$this->validate_quantity_condition( $index, $raw, RuleTypes::CONDITION_PRODUCT_QUANTITY, 'product_id', $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_CATEGORY_QUANTITY ) {
			$this->validate_quantity_condition( $index, $raw, RuleTypes::CONDITION_CATEGORY_QUANTITY, 'category_id', $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_LOGGED_IN || $type === RuleTypes::CONDITION_FIRST_ORDER ) {
			return;
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_ROLE ) {
			$this->validate_customer_role( $index, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_BILLING_COUNTRY ) {
			$this->validate_billing_country( $index, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN ) {
			$this->validate_customer_email_domain( $index, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT ) {
			$this->validate_customer_redemption_count( $index, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_MINIMUM_CART_QUANTITY || $type === RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY ) {
			$this->validate_cart_quantity_condition( $index, $type, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_PRODUCT_IN_CART ) {
			$this->validate_id_list_condition( $index, $type, $raw, 'product_ids', $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_CATEGORY_IN_CART ) {
			$this->validate_id_list_condition( $index, $type, $raw, 'category_ids', $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_EXCLUDE_SALE_ITEMS ) {
			return;
		}

		if ( $type === RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL
			|| $type === RuleTypes::CONDITION_MAXIMUM_ELIGIBLE_SUBTOTAL ) {
			$this->validate_eligible_subtotal_condition( $index, $type, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_LIFETIME_SPEND
			|| $type === RuleTypes::CONDITION_CUSTOMER_ORDER_COUNT
			|| $type === RuleTypes::CONDITION_CUSTOMER_AVERAGE_ORDER_VALUE ) {
			$this->validate_customer_numeric_segmentation( $index, $type, $raw, $issues );
			return;
		}

		$issues[] = $this->error(
			sprintf(
				/* translators: %s: condition type string */
				__( 'Unknown condition type: %s', 'mp-commerce-promotions' ),
				$type
			)
		);
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_id_list_condition( int $index, string $type, array $raw, string $key, array &$issues ): void {
		if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) || $raw[ $key ] === array() ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: field name, 3: index */
					__( '%1$s at index %3$s is missing %2$s.', 'mp-commerce-promotions' ),
					$type,
					$key,
					(string) $index
				)
			);
			return;
		}

		foreach ( $raw[ $key ] as $raw_id ) {
			if ( ! is_numeric( $raw_id ) || (int) $raw_id <= 0 ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: 1: condition type, 2: field name, 3: index */
						__( '%1$s at index %3$s has invalid %2$s (positive integers only).', 'mp-commerce-promotions' ),
						$type,
						$key,
						(string) $index
					)
				);
				return;
			}
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_cart_quantity_condition( int $index, string $type, array $raw, array &$issues ): void {
		if ( ! isset( $raw['quantity'] ) || ! is_numeric( $raw['quantity'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: zero-based index */
					__( '%1$s at index %2$s is missing or has an invalid quantity.', 'mp-commerce-promotions' ),
					$type,
					(string) $index
				)
			);
			return;
		}

		$quantity = (int) $raw['quantity'];
		if ( $quantity < 1 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: zero-based index */
					__( '%1$s at index %2$s quantity must be >= 1.', 'mp-commerce-promotions' ),
					$type,
					(string) $index
				)
			);
			return;
		}

		try {
			if ( $type === RuleTypes::CONDITION_MINIMUM_CART_QUANTITY ) {
				new MinimumCartQuantityCondition( $quantity );
			} else {
				new MaximumCartQuantityCondition( $quantity );
			}
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: zero-based index */
					__( '%1$s at index %2$s has invalid quantity.', 'mp-commerce-promotions' ),
					$type,
					(string) $index
				)
			);
		}
	}

	private function validate_billing_country( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['countries'] ) || ! is_array( $raw['countries'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'billing_country at index %s is missing a countries array.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new BillingCountryCondition( $raw['countries'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'billing_country at index %s has invalid countries.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	private function validate_customer_email_domain( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['domains'] ) || ! is_array( $raw['domains'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_email_domain at index %s is missing a domains array.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new CustomerEmailDomainCondition( $raw['domains'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_email_domain at index %s has invalid domains.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	private function validate_customer_role( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['roles'] ) || ! is_array( $raw['roles'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_role at index %s is missing a roles array.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new CustomerRoleCondition( $raw['roles'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_role at index %s has invalid roles.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_minimum_subtotal( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'minimum_subtotal at index %s is missing or has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new MinimumSubtotalCondition( (float) $raw['amount'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'minimum_subtotal at index %s has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_quantity_condition(
		int $index,
		array $raw,
		string $type_label,
		string $id_key,
		array &$issues
	): void {
		if ( ! isset( $raw[ $id_key ] ) || ! is_numeric( $raw[ $id_key ] ) || (int) $raw[ $id_key ] <= 0 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s is missing or has an invalid %3$s.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index,
					$id_key
				)
			);
			return;
		}

		if ( ! isset( $raw['operator'] ) || ! is_string( $raw['operator'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s is missing or has an invalid operator.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
			return;
		}

		$operator = trim( $raw['operator'] );
		if ( ! QuantityComparator::supports( $operator ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s has an unsupported operator.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['quantity'] ) || ! is_numeric( $raw['quantity'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s is missing or has an invalid quantity.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
			return;
		}

		try {
			if ( $type_label === RuleTypes::CONDITION_PRODUCT_QUANTITY ) {
				new ProductQuantityCondition( (int) $raw[ $id_key ], $operator, (float) $raw['quantity'] );
			} else {
				new CategoryQuantityCondition( (int) $raw[ $id_key ], $operator, (float) $raw['quantity'] );
			}
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s has invalid field values.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<mixed>                                $actions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_action_issues( array $actions, array &$issues ): void {
		if ( count( $actions ) === 0 ) {
			$issues[] = $this->error(
				__( 'Promotion has no actions.', 'mp-commerce-promotions' )
			);
			return;
		}

		$supported_count = 0;

		foreach ( $actions as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'Action at index %s must be an object.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				continue;
			}

			$type = isset( $raw['type'] ) ? trim( (string) $raw['type'] ) : '';
			if ( $type === '' ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'Action at index %s has no type.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				continue;
			}

			if ( ! RuleRegistry::is_supported_action( $type ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: action type string */
						__( 'Unknown action type: %s', 'mp-commerce-promotions' ),
						$type
					)
				);
				continue;
			}

			++$supported_count;
			$this->validate_supported_action( (int) $index, $type, $raw, $issues );
		}

		if ( $supported_count > 1 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Only the first supported action per promotion is applied on the storefront.', 'mp-commerce-promotions' ),
			);
		}
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_conflict_heuristic_issues( Promotion $promotion, array &$issues ): void {
		$mode     = $promotion->get_application_mode();
		$excluded = $promotion->get_excluded_promotion_ids();

		if ( $mode === PromotionApplicationMode::EXCLUSIVE && $excluded !== array() ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __(
					'Exclusive promotion with excluded_promotion_ids: exclusions apply only after this promotion is selected; redundant if stop processing is enabled.',
					'mp-commerce-promotions'
				),
			);
		}

		$max = $promotion->get_max_applications();
		if ( $max !== null && $max === 1 && $mode === PromotionApplicationMode::STACKABLE && ! $promotion->should_stop_processing() ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __(
					'max_applications is 1 on a stackable promotion without stop processing; additional stackable promotions may still be selected unless planner cap applies.',
					'mp-commerce-promotions'
				),
			);
		}

		if ( $max !== null && $mode === PromotionApplicationMode::EXCLUSIVE && $promotion->should_stop_processing() ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __(
					'max_applications may be unreachable when exclusive stop processing prevents further selections.',
					'mp-commerce-promotions'
				),
			);
		}

		$this->append_action_scope_overlap_issues( $promotion->get_actions(), $issues );
		$this->append_duplicate_gift_action_issues( $promotion->get_actions(), $issues );
		$this->append_multiple_free_shipping_action_issues( $promotion->get_actions(), $issues );
	}

	/**
	 * @param array<mixed>                                $actions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_action_scope_overlap_issues( array $actions, array &$issues ): void {
		$category_sets = array();
		$product_sets  = array();

		foreach ( $actions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$type = isset( $raw['type'] ) ? (string) $raw['type'] : '';
			if ( $type !== RuleTypes::ACTION_PERCENTAGE_DISCOUNT && $type !== RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				continue;
			}

			$categories = isset( $raw['category_ids'] ) && is_array( $raw['category_ids'] )
				? $raw['category_ids']
				: array();
			$products   = isset( $raw['product_ids'] ) && is_array( $raw['product_ids'] )
				? $raw['product_ids']
				: array();

			if ( $categories !== array() ) {
				$category_sets[] = $categories;
			}
			if ( $products !== array() ) {
				$product_sets[] = $products;
			}
		}

		if ( count( $category_sets ) > 1 || count( $product_sets ) > 1 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Multiple scoped discount actions on this promotion may overlap; only the first action applies on the storefront.', 'mp-commerce-promotions' ),
			);
		}

		if ( $category_sets !== array() || $product_sets !== array() ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __( 'Scoped percentage/fixed discounts on this promotion only affect matching cart lines (fee-based).', 'mp-commerce-promotions' ),
			);
		}
	}

	/**
	 * @param array<mixed>                                $actions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_duplicate_gift_action_issues( array $actions, array &$issues ): void {
		$gift_products = array();
		foreach ( $actions as $raw ) {
			if ( ! is_array( $raw ) || ( $raw['type'] ?? '' ) !== RuleTypes::ACTION_FREE_GIFT_PRODUCT ) {
				continue;
			}
			$pid = isset( $raw['product_id'] ) ? (int) $raw['product_id'] : 0;
			if ( $pid > 0 ) {
				$gift_products[] = $pid;
			}
		}

		if ( count( $gift_products ) !== count( array_unique( $gift_products ) ) ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Duplicate free_gift_product actions for the same product_id; only the first action applies.', 'mp-commerce-promotions' ),
			);
		}
	}

	/**
	 * @param array<mixed>                                $actions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_multiple_free_shipping_action_issues( array $actions, array &$issues ): void {
		$count = 0;
		foreach ( $actions as $raw ) {
			if ( is_array( $raw ) && ( $raw['type'] ?? '' ) === RuleTypes::ACTION_FREE_SHIPPING ) {
				++$count;
			}
		}

		if ( $count > 1 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Multiple free_shipping actions defined; only the first applies on the storefront.', 'mp-commerce-promotions' ),
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_supported_action( int $index, string $type, array $raw, array &$issues ): void {
		if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
			if ( ! isset( $raw['percentage'] ) || ! is_numeric( $raw['percentage'] ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'percentage_discount at index %s is missing or has an invalid percentage.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				return;
			}

			try {
				PercentageDiscountAction::from_config( $raw );
			} catch ( InvalidArgumentException $e ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'percentage_discount at index %s has an invalid percentage or scope.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
			}
			return;
		}

		if ( $type === RuleTypes::ACTION_FREE_SHIPPING ) {
			try {
				new FreeShippingAction();
			} catch ( InvalidArgumentException $e ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'free_shipping at index %s is invalid.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
			}
			return;
		}

		if ( $type === RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
			$this->validate_cheapest_item_discount( $index, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::ACTION_FREE_GIFT_PRODUCT ) {
			$this->validate_free_gift_product( $index, $raw, $issues );
			return;
		}

		if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'fixed_amount_discount at index %s is missing or has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			FixedAmountDiscountAction::from_config( $raw );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'fixed_amount_discount at index %s has an invalid amount or scope.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_eligible_subtotal_condition( int $index, string $type, array $raw, array &$issues ): void {
		if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: zero-based index */
					__( '%1$s at index %2$s is missing or has an invalid amount.', 'mp-commerce-promotions' ),
					$type,
					(string) $index
				)
			);
			return;
		}

		if ( (float) $raw['amount'] < 0 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: zero-based index */
					__( '%1$s at index %2$s amount must be >= 0.', 'mp-commerce-promotions' ),
					$type,
					(string) $index
				)
			);
			return;
		}

		foreach ( array( 'product_ids', 'variation_ids', 'category_ids' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			if ( ! is_array( $raw[ $key ] ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: 1: condition type, 2: field name, 3: index */
						__( '%1$s at index %3$s has invalid %2$s.', 'mp-commerce-promotions' ),
						$type,
						$key,
						(string) $index
					)
				);
				return;
			}
			foreach ( $raw[ $key ] as $raw_id ) {
				if ( ! is_numeric( $raw_id ) || (int) $raw_id <= 0 ) {
					$issues[] = $this->error(
						sprintf(
							/* translators: 1: condition type, 2: field name, 3: index */
							__( '%1$s at index %3$s has invalid %2$s (positive integers only).', 'mp-commerce-promotions' ),
							$type,
							$key,
							(string) $index
						)
					);
					return;
				}
			}
		}

		try {
			if ( $type === RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL ) {
				MinimumEligibleSubtotalCondition::from_config( $raw );
			} else {
				MaximumEligibleSubtotalCondition::from_config( $raw );
			}
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: zero-based index */
					__( '%1$s at index %2$s has invalid field values.', 'mp-commerce-promotions' ),
					$type,
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_free_gift_product( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['product_id'] ) || ! is_numeric( $raw['product_id'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'free_gift_product at index %s is missing or has an invalid product_id.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$product_id = (int) $raw['product_id'];
		if ( $product_id <= 0 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'free_gift_product at index %s product_id must be a positive integer.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( isset( $raw['variation_id'] ) && $raw['variation_id'] !== null && $raw['variation_id'] !== '' ) {
			if ( ! is_numeric( $raw['variation_id'] ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'free_gift_product at index %s has an invalid variation_id.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				return;
			}
			$variation_id = (int) $raw['variation_id'];
			if ( $variation_id <= 0 ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'free_gift_product at index %s variation_id must be a positive integer when set.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				return;
			}
		}

		if ( ! isset( $raw['quantity'] ) || ! is_numeric( $raw['quantity'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'free_gift_product at index %s is missing or has an invalid quantity.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$quantity = (int) $raw['quantity'];
		if ( $quantity < 1 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'free_gift_product at index %s quantity must be >= 1.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			FreeGiftProductAction::from_config( $raw );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: zero-based action index, 2: detail */
					__( 'free_gift_product at index %1$s has invalid field values (%2$s).', 'mp-commerce-promotions' ),
					(string) $index,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_cheapest_item_discount( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['scope'] ) || ! is_string( $raw['scope'] ) || trim( $raw['scope'] ) === '' ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s is missing scope (use category or products).', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$scope = trim( $raw['scope'] );
		if ( $scope !== CheapestItemDiscountAction::SCOPE_CATEGORY && $scope !== CheapestItemDiscountAction::SCOPE_PRODUCTS ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s has invalid scope (must be category or products).', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( $scope === CheapestItemDiscountAction::SCOPE_CATEGORY ) {
			if ( ! isset( $raw['category_ids'] ) || ! is_array( $raw['category_ids'] ) || count( $raw['category_ids'] ) === 0 ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'cheapest_item_discount at index %s is missing category_ids.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				return;
			}
		} elseif ( ! isset( $raw['product_ids'] ) || ! is_array( $raw['product_ids'] ) || count( $raw['product_ids'] ) === 0 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s is missing product_ids.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['discount_percentage'] ) || ! is_numeric( $raw['discount_percentage'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s is missing or has an invalid discount_percentage.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$pct = (float) $raw['discount_percentage'];
		if ( $pct <= 0 || $pct > 100 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s discount_percentage must be > 0 and <= 100.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['required_quantity'] ) || ! is_numeric( $raw['required_quantity'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s is missing or has an invalid required_quantity.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$required = (int) $raw['required_quantity'];
		if ( $required < 1 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s required_quantity must be >= 1.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['discounted_quantity'] ) || ! is_numeric( $raw['discounted_quantity'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s is missing or has an invalid discounted_quantity.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$discounted = (int) $raw['discounted_quantity'];
		if ( $discounted < 1 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s discounted_quantity must be >= 1.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( $discounted > $required ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s discounted_quantity must be <= required_quantity.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			CheapestItemDiscountAction::from_config( $raw );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: zero-based action index, 2: detail */
					__( 'cheapest_item_discount at index %1$s has invalid IDs or field values (%2$s).', 'mp-commerce-promotions' ),
					(string) $index,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_customer_numeric_segmentation( int $index, string $type, array $raw, array &$issues ): void {
		if ( ! isset( $raw['operator'] ) || ! is_string( $raw['operator'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s is missing or has an invalid operator.', 'mp-commerce-promotions' ),
					$type,
					(string) $index
				)
			);
			return;
		}

		$operator = trim( $raw['operator'] );
		if ( ! QuantityComparator::supports( $operator ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s has an unsupported operator.', 'mp-commerce-promotions' ),
					$type,
					(string) $index
				)
			);
			return;
		}

		$amount_key = $type === RuleTypes::CONDITION_CUSTOMER_ORDER_COUNT ? 'count' : 'amount';
		if ( ! isset( $raw[ $amount_key ] ) || ! is_numeric( $raw[ $amount_key ] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: field name, 3: index */
					__( '%1$s at index %3$s is missing or has an invalid %2$s.', 'mp-commerce-promotions' ),
					$type,
					$amount_key,
					(string) $index
				)
			);
			return;
		}

		try {
			$value = (float) $raw[ $amount_key ];
			if ( $type === RuleTypes::CONDITION_CUSTOMER_LIFETIME_SPEND ) {
				new CustomerLifetimeSpendCondition( $operator, $value );
			} elseif ( $type === RuleTypes::CONDITION_CUSTOMER_ORDER_COUNT ) {
				new CustomerOrderCountCondition( $operator, $value );
			} else {
				new CustomerAverageOrderValueCondition( $operator, $value );
			}
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s has invalid field values.', 'mp-commerce-promotions' ),
					$type,
					(string) $index
				)
			);
		}
	}

	private function validate_customer_redemption_count( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['operator'] ) || ! is_string( $raw['operator'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_redemption_count at index %s is missing or has an invalid operator.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$operator = trim( $raw['operator'] );
		if ( ! QuantityComparator::supports( $operator ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_redemption_count at index %s has an unsupported operator.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['count'] ) || ! is_numeric( $raw['count'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_redemption_count at index %s is missing or has an invalid count.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new CustomerRedemptionCountCondition( $operator, (float) $raw['count'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_redemption_count at index %s has invalid field values.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	/**
	 * @return array{level: string, message: string}
	 */
	private function error( string $message ): array {
		return array(
			'level'   => 'error',
			'message' => $message,
		);
	}
}
