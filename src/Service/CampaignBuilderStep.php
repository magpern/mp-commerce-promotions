<?php
/**
 * Campaign Builder wizard step identifiers and navigation.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

final class CampaignBuilderStep {

	public const GOAL = 'goal';

	public const TARGETING = 'targeting';

	public const OFFER = 'offer';

	public const SCHEDULE = 'schedule';

	public const REVIEW = 'review';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::GOAL,
			self::TARGETING,
			self::OFFER,
			self::SCHEDULE,
			self::REVIEW,
		);
	}

	public static function sanitize( ?string $step ): ?string {
		if ( $step === null || $step === '' ) {
			return null;
		}

		$step = sanitize_key( $step );

		return in_array( $step, self::all(), true ) ? $step : null;
	}

	/**
	 * Ordered steps shown in the progress bar for a goal (goal step omitted once chosen).
	 *
	 * @return list<string>
	 */
	public static function flow_for_goal( string $goal ): array {
		$flow = array( self::TARGETING, self::OFFER, self::SCHEDULE, self::REVIEW );
		if ( ! self::goal_needs_targeting_step( $goal ) ) {
			$flow = array_values( array_filter( $flow, static fn( string $s ): bool => $s !== self::TARGETING ) );
		}

		return $flow;
	}

	public static function goal_needs_targeting_step( string $goal ): bool {
		return in_array(
			$goal,
			array(
				CampaignBuilderGoal::CATEGORY_DISCOUNT,
				CampaignBuilderGoal::PRODUCT_DISCOUNT,
				CampaignBuilderGoal::BUY_X_GET_Y,
				CampaignBuilderGoal::VIP_ROLE,
				CampaignBuilderGoal::SCHEDULED,
			),
			true
		);
	}

	public static function label( string $step ): string {
		$labels = array(
			self::GOAL       => __( 'Goal', 'mp-commerce-promotions' ),
			self::TARGETING  => __( 'Targeting', 'mp-commerce-promotions' ),
			self::OFFER      => __( 'Offer', 'mp-commerce-promotions' ),
			self::SCHEDULE   => __( 'Schedule', 'mp-commerce-promotions' ),
			self::REVIEW     => __( 'Review', 'mp-commerce-promotions' ),
		);

		return $labels[ $step ] ?? $step;
	}

	/**
	 * First step after choosing a goal.
	 */
	public static function initial_after_goal( string $goal ): string {
		$flow = self::flow_for_goal( $goal );

		return $flow[0] ?? self::OFFER;
	}

	public static function navigate( string $goal, string $current, string $direction ): string {
		$flow = self::flow_for_goal( $goal );
		$idx  = array_search( $current, $flow, true );
		if ( $idx === false ) {
			return self::initial_after_goal( $goal );
		}

		if ( $direction === 'back' ) {
			return $flow[ max( 0, (int) $idx - 1 ) ] ?? $current;
		}

		return $flow[ min( count( $flow ) - 1, (int) $idx + 1 ) ] ?? $current;
	}

	private function __construct() {
	}
}
