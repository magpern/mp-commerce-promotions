<?php
/**
 * Promotion schedule/budget lifecycle labels for admin visibility (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\PromotionDateHelper;

final class PromotionLifecycle {

	public const PHASE_UPCOMING         = 'upcoming';
	public const PHASE_LIVE             = 'live';
	public const PHASE_ENDING_SOON      = 'ending_soon';
	public const PHASE_EXPIRED_ACTIVE   = 'expired_active';
	public const PHASE_BUDGET_EXHAUSTED = 'budget_exhausted';
	public const PHASE_ARCHIVED         = 'archived';

	public const PHASE_SCHEDULED_DRAFT  = 'scheduled_draft';

	public const PHASE_EXPIRED_PAUSED   = 'expired_paused';

	public const ENDING_SOON_DAYS = 7;

	/**
	 * Primary lifecycle phase for list badges and filters.
	 */
	public static function primary_phase( Promotion $promotion ): string {
		if ( $promotion->get_status() === PromotionStatus::ARCHIVED ) {
			return self::PHASE_ARCHIVED;
		}

		if ( $promotion->get_status() === PromotionStatus::ACTIVE && $promotion->is_budget_exhausted() ) {
			return self::PHASE_BUDGET_EXHAUSTED;
		}

		if ( $promotion->get_status() === PromotionStatus::ACTIVE && self::is_expired_but_active( $promotion ) ) {
			return self::PHASE_EXPIRED_ACTIVE;
		}

		if ( self::is_upcoming( $promotion ) ) {
			return self::PHASE_UPCOMING;
		}

		if ( self::is_ending_soon( $promotion ) ) {
			return self::PHASE_ENDING_SOON;
		}

		if ( $promotion->get_status() === PromotionStatus::ACTIVE ) {
			return self::PHASE_LIVE;
		}

		if ( $promotion->get_status() === PromotionStatus::DRAFT && self::is_scheduled_draft_ready( $promotion ) ) {
			return self::PHASE_SCHEDULED_DRAFT;
		}

		if ( $promotion->get_status() === PromotionStatus::PAUSED && self::is_expired_paused( $promotion ) ) {
			return self::PHASE_EXPIRED_PAUSED;
		}

		return $promotion->get_status();
	}

	public static function is_scheduled_draft_ready( Promotion $promotion ): bool {
		if ( $promotion->get_status() !== PromotionStatus::DRAFT ) {
			return false;
		}

		$starts = PromotionDateHelper::parse_mysql_datetime( $promotion->get_starts_at() );
		if ( $starts === null ) {
			return false;
		}

		return PromotionDateHelper::now_timestamp() >= $starts;
	}

	public static function is_expired_paused( Promotion $promotion ): bool {
		if ( $promotion->get_status() !== PromotionStatus::PAUSED ) {
			return false;
		}

		$ends = PromotionDateHelper::parse_mysql_datetime( $promotion->get_ends_at() );
		if ( $ends === null ) {
			return false;
		}

		return PromotionDateHelper::now_timestamp() > $ends;
	}

	public static function badge_label( string $phase ): string {
		$labels = array(
			self::PHASE_UPCOMING         => __( 'Scheduled', 'mp-commerce-promotions' ),
			self::PHASE_LIVE             => __( 'Live', 'mp-commerce-promotions' ),
			self::PHASE_ENDING_SOON      => __( 'Ending soon', 'mp-commerce-promotions' ),
			self::PHASE_EXPIRED_ACTIVE   => __( 'Expired (active)', 'mp-commerce-promotions' ),
			self::PHASE_BUDGET_EXHAUSTED => __( 'Exhausted', 'mp-commerce-promotions' ),
			self::PHASE_ARCHIVED         => __( 'Archived', 'mp-commerce-promotions' ),
			self::PHASE_SCHEDULED_DRAFT  => __( 'Ready to activate', 'mp-commerce-promotions' ),
			self::PHASE_EXPIRED_PAUSED   => __( 'Expired (paused)', 'mp-commerce-promotions' ),
		);

		return $labels[ $phase ] ?? $phase;
	}

	public static function is_upcoming( Promotion $promotion ): bool {
		if ( ! in_array( $promotion->get_status(), array( PromotionStatus::ACTIVE, PromotionStatus::PAUSED, PromotionStatus::DRAFT ), true ) ) {
			return false;
		}

		$starts = PromotionDateHelper::parse_mysql_datetime( $promotion->get_starts_at() );
		if ( $starts === null ) {
			return false;
		}

		return PromotionDateHelper::now_timestamp() < $starts;
	}

	public static function is_ending_soon( Promotion $promotion, int $within_days = self::ENDING_SOON_DAYS ): bool {
		if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
			return false;
		}

		$ends = PromotionDateHelper::parse_mysql_datetime( $promotion->get_ends_at() );
		if ( $ends === null ) {
			return false;
		}

		$now    = PromotionDateHelper::now_timestamp();
		$window = $within_days * DAY_IN_SECONDS;

		return $now <= $ends && ( $ends - $now ) <= $window;
	}

	public static function is_expired_but_active( Promotion $promotion ): bool {
		if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
			return false;
		}

		$ends = PromotionDateHelper::parse_mysql_datetime( $promotion->get_ends_at() );
		if ( $ends === null ) {
			return false;
		}

		return PromotionDateHelper::now_timestamp() > $ends;
	}

	/**
	 * @param list<Promotion> $promotions
	 * @return list<Promotion>
	 */
	public static function filter_by_phase( array $promotions, string $phase ): array {
		$out = array();
		foreach ( $promotions as $promotion ) {
			if ( ! $promotion instanceof Promotion ) {
				continue;
			}
			if ( self::primary_phase( $promotion ) === $phase ) {
				$out[] = $promotion;
			}
		}

		return $out;
	}
}
