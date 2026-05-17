<?php
/**
 * Safe construction of new Promotion instances (no persistence).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use MP\CommercePromotions\Domain\PromotionAllocationMode;
use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Domain\PromotionPriorityTier;

final class PromotionFactory {

	public function create_draft( string $name, ?int $created_by = null ): Promotion {
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '';
		if ( ! is_string( $uuid ) || $uuid === '' ) {
			throw new \RuntimeException( 'Unable to generate promotion UUID.' );
		}

		return new Promotion(
			null,
			$uuid,
			$name,
			null,
			PromotionStatus::DRAFT,
			100,
			null,
			null,
			array(),
			array(),
			array(),
			null,
			null,
			0,
			PromotionApplicationMode::EXCLUSIVE,
			true,
			null,
			array(),
			array(),
			array(),
			null,
			null,
			null,
			null,
			0.0,
			null,
			null,
			null,
			PromotionPriorityTier::DEFAULT_TIER,
			PromotionCouponBehavior::DEFAULT_BEHAVIOR,
			PromotionAllocationMode::DEFAULT_MODE,
			$created_by,
			null,
			null
		);
	}

	public function create_draft_from_source( Promotion $source, string $name, ?int $created_by = null ): Promotion {
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '';
		if ( ! is_string( $uuid ) || $uuid === '' ) {
			throw new \RuntimeException( 'Unable to generate promotion UUID.' );
		}

		return new Promotion(
			null,
			$uuid,
			$name,
			$source->get_description(),
			PromotionStatus::DRAFT,
			$source->get_priority(),
			$source->get_starts_at(),
			$source->get_ends_at(),
			$source->get_conditions(),
			$source->get_actions(),
			$source->get_restrictions(),
			$source->get_usage_limit(),
			$source->get_customer_usage_limit(),
			0,
			$source->get_application_mode(),
			$source->should_stop_processing(),
			$source->get_max_applications(),
			$source->get_excluded_promotion_ids(),
			$source->get_excluded_product_ids(),
			$source->get_excluded_category_ids(),
			$source->get_campaign_label(),
			$source->get_internal_notes(),
			$source->get_admin_color(),
			$source->get_budget_amount(),
			0.0,
			$source->get_budget_currency(),
			$source->get_cooldown_hours(),
			$source->get_orchestration_group(),
			$source->get_priority_tier(),
			$source->get_coupon_behavior(),
			$source->get_allocation_mode(),
			$created_by,
			null,
			null
		);
	}
}
