<?php
/**
 * Promotion-level eligibility checks (usage limits, schedule, per-customer caps).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;

final class PromotionRestrictionEvaluator {

	public const TRACE_TYPE = 'promotion_restrictions';

	private ?RedemptionRepository $redemptions;

	public function __construct( ?RedemptionRepository $redemptions = null ) {
		$this->redemptions = $redemptions;
	}

	/**
	 * @return ConditionTrace|null Failure trace when ineligible; null when restrictions pass.
	 */
	public function evaluate_restrictions( Promotion $promotion, EvaluationContext $context ): ?ConditionTrace {
		$date_trace = $this->evaluate_date_window( $promotion );
		if ( $date_trace !== null ) {
			return $date_trace;
		}

		$usage_trace = $this->evaluate_global_usage_limit( $promotion );
		if ( $usage_trace !== null ) {
			return $usage_trace;
		}

		$budget_trace = $this->evaluate_budget_cap( $promotion );
		if ( $budget_trace !== null ) {
			return $budget_trace;
		}

		$cooldown_trace = $this->evaluate_cooldown( $promotion, $context );
		if ( $cooldown_trace !== null ) {
			return $cooldown_trace;
		}

		return $this->evaluate_customer_usage_limit( $promotion, $context );
	}

	private function evaluate_date_window( Promotion $promotion ): ?ConditionTrace {
		$now = PromotionDateHelper::now_timestamp();

		$starts_at = $promotion->get_starts_at();
		$starts_ts = PromotionDateHelper::parse_mysql_datetime( $starts_at );
		if ( $starts_ts !== null && $now < $starts_ts ) {
			return new ConditionTrace(
				self::TRACE_TYPE,
				false,
				'Promotion has not started yet.',
				ConditionTrace::REASON_PROMOTION_NOT_STARTED,
				array(
					'starts_at' => $starts_at,
				),
				array(
					'now_timestamp'    => $now,
					'starts_timestamp' => $starts_ts,
				)
			);
		}

		$ends_at = $promotion->get_ends_at();
		$ends_ts = PromotionDateHelper::parse_mysql_datetime( $ends_at );
		if ( $ends_ts !== null && $now > $ends_ts ) {
			return new ConditionTrace(
				self::TRACE_TYPE,
				false,
				'Promotion has expired.',
				ConditionTrace::REASON_PROMOTION_EXPIRED,
				array(
					'ends_at' => $ends_at,
				),
				array(
					'now_timestamp'  => $now,
					'ends_timestamp' => $ends_ts,
				)
			);
		}

		return null;
	}

	private function evaluate_global_usage_limit( Promotion $promotion ): ?ConditionTrace {
		$limit = $promotion->get_usage_limit();
		if ( $limit === null || $limit < 1 ) {
			return null;
		}

		$usage_count = $promotion->get_usage_count();
		if ( $usage_count < $limit ) {
			return null;
		}

		return new ConditionTrace(
			self::TRACE_TYPE,
			false,
			'Promotion global usage limit has been reached.',
			ConditionTrace::REASON_USAGE_LIMIT_REACHED,
			array(
				'usage_limit' => $limit,
			),
			array(
				'usage_count' => $usage_count,
				'usage_limit' => $limit,
			)
		);
	}

	private function evaluate_budget_cap( Promotion $promotion ): ?ConditionTrace {
		if ( ! $promotion->is_budget_exhausted() ) {
			return null;
		}

		return new ConditionTrace(
			self::TRACE_TYPE,
			false,
			'Promotion budget has been exhausted.',
			ConditionTrace::REASON_PROMOTION_BUDGET_EXHAUSTED,
			array(
				'budget_amount' => $promotion->get_budget_amount(),
			),
			array(
				'budget_spent'    => $promotion->get_budget_spent(),
				'budget_amount'   => $promotion->get_budget_amount(),
				'budget_currency' => $promotion->get_budget_currency(),
			)
		);
	}

	private function evaluate_cooldown( Promotion $promotion, EvaluationContext $context ): ?ConditionTrace {
		$hours = $promotion->get_cooldown_hours();
		if ( $hours === null || $hours < 1 ) {
			return null;
		}

		$customer_id  = $context->get_customer_id();
		$promotion_id = $promotion->get_id();

		if ( $customer_id === null || $customer_id <= 0 ) {
			return new ConditionTrace(
				self::TRACE_TYPE,
				false,
				'Customer account is required when a promotion cooldown is configured.',
				ConditionTrace::REASON_CUSTOMER_REQUIRED,
				array(
					'cooldown_hours' => $hours,
				),
				array(
					'customer_id' => $customer_id,
				)
			);
		}

		if ( $this->redemptions === null || $promotion_id === null || $promotion_id <= 0 ) {
			return null;
		}

		$last_redeemed = $this->redemptions->find_latest_recorded_redeemed_at_for_customer_and_promotion(
			$customer_id,
			$promotion_id
		);
		if ( $last_redeemed === null ) {
			return null;
		}

		$last_ts = PromotionDateHelper::parse_mysql_datetime( $last_redeemed );
		if ( $last_ts === null ) {
			return null;
		}

		$cooldown_until_ts = $last_ts + ( $hours * HOUR_IN_SECONDS );
		$now               = PromotionDateHelper::now_timestamp();
		if ( $now >= $cooldown_until_ts ) {
			return null;
		}

		$cooldown_until = gmdate( 'Y-m-d H:i:s', $cooldown_until_ts );

		return new ConditionTrace(
			self::TRACE_TYPE,
			false,
			'Promotion is in cooldown for this customer.',
			ConditionTrace::REASON_PROMOTION_COOLDOWN_ACTIVE,
			array(
				'cooldown_hours' => $hours,
			),
			array(
				'last_redeemed_at' => $last_redeemed,
				'cooldown_until'   => $cooldown_until,
			)
		);
	}

	private function evaluate_customer_usage_limit( Promotion $promotion, EvaluationContext $context ): ?ConditionTrace {
		$limit = $promotion->get_customer_usage_limit();
		if ( $limit === null || $limit < 1 ) {
			return null;
		}

		$customer_id = $context->get_customer_id();
		$promotion_id = $promotion->get_id();

		if ( $customer_id === null || $customer_id <= 0 ) {
			return new ConditionTrace(
				self::TRACE_TYPE,
				false,
				'Customer account is required to enforce per-customer usage limits.',
				ConditionTrace::REASON_CUSTOMER_REQUIRED_FOR_USAGE_TRACKING,
				array(
					'customer_usage_limit' => $limit,
				),
				array(
					'customer_id' => $customer_id,
				)
			);
		}

		$count = $this->resolve_customer_promotion_redemption_count( $customer_id, $promotion_id, $context );

		if ( $count < $limit ) {
			return null;
		}

		return new ConditionTrace(
			self::TRACE_TYPE,
			false,
			'Customer has reached the per-customer usage limit for this promotion.',
			ConditionTrace::REASON_CUSTOMER_USAGE_LIMIT_REACHED,
			array(
				'customer_usage_limit' => $limit,
			),
			array(
				'customer_id'                         => $customer_id,
				'customer_promotion_redemption_count' => $count,
				'customer_usage_limit'                => $limit,
			)
		);
	}

	private function resolve_customer_promotion_redemption_count(
		int $customer_id,
		?int $promotion_id,
		EvaluationContext $context
	): int {
		$metadata = $context->get_metadata();
		if (
			$promotion_id !== null
			&& $promotion_id > 0
			&& isset( $metadata['customer_promotion_redemption_count'] )
			&& is_numeric( $metadata['customer_promotion_redemption_count'] )
		) {
			return max( 0, (int) $metadata['customer_promotion_redemption_count'] );
		}

		if ( $this->redemptions === null || $promotion_id === null || $promotion_id <= 0 ) {
			return 0;
		}

		return $this->redemptions->count_recorded_for_customer_and_promotion( $customer_id, $promotion_id );
	}
}
