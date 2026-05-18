<?php
/**
 * Lightweight lifecycle timeline and relative schedule copy for Campaign Builder.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Engine\PromotionDateHelper;

final class CampaignBuilderLifecyclePresenter {

	/**
	 * @return array{phase: string, label: string, chip: string, relative: string, percent: int|null}
	 */
	public static function snapshot( Promotion $promotion ): array {
		$phase = PromotionLifecycle::primary_phase( $promotion );
		$label = PromotionLifecycle::badge_label( $phase );

		return array(
			'phase'    => $phase,
			'label'    => $label,
			'chip'     => self::chip_text( $promotion, $phase ),
			'relative' => self::relative_schedule( $promotion ),
			'percent'  => self::budget_percent( $promotion ),
		);
	}

	public static function relative_schedule( Promotion $promotion ): string {
		$now    = PromotionDateHelper::now_timestamp();
		$starts = PromotionDateHelper::parse_mysql_datetime( $promotion->get_starts_at() );
		$ends   = PromotionDateHelper::parse_mysql_datetime( $promotion->get_ends_at() );

		if ( $starts !== null && $now < $starts ) {
			$days = (int) ceil( ( $starts - $now ) / DAY_IN_SECONDS );

			return sprintf(
				/* translators: %d: number of days */
				_n( 'Starts in %d day', 'Starts in %d days', $days, 'mp-commerce-promotions' ),
				$days
			);
		}

		if ( $ends !== null && $now < $ends ) {
			$days = (int) ceil( ( $ends - $now ) / DAY_IN_SECONDS );

			return sprintf(
				/* translators: %d: number of days */
				_n( 'Ends in %d day', 'Ends in %d days', $days, 'mp-commerce-promotions' ),
				$days
			);
		}

		if ( $ends !== null && $now >= $ends ) {
			return __( 'Ended', 'mp-commerce-promotions' );
		}

		if ( $promotion->is_budget_exhausted() ) {
			return __( 'Budget exhausted', 'mp-commerce-promotions' );
		}

		return __( 'No end date set', 'mp-commerce-promotions' );
	}

	public static function budget_percent( Promotion $promotion ): ?int {
		if ( ! $promotion->has_budget_cap() ) {
			return null;
		}

		$cap = (float) $promotion->get_budget_cap();
		if ( $cap <= 0 ) {
			return null;
		}

		$spent = (float) $promotion->get_budget_spent();
		$pct   = (int) round( min( 100, max( 0, ( $spent / $cap ) * 100 ) ) );

		return $pct;
	}

	private static function chip_text( Promotion $promotion, string $phase ): string {
		if ( $phase === PromotionLifecycle::PHASE_BUDGET_EXHAUSTED ) {
			return __( 'Budget exhausted', 'mp-commerce-promotions' );
		}
		if ( $phase === PromotionLifecycle::PHASE_UPCOMING ) {
			return __( 'Upcoming', 'mp-commerce-promotions' );
		}
		if ( $phase === PromotionLifecycle::PHASE_ENDING_SOON ) {
			return __( 'Ending soon', 'mp-commerce-promotions' );
		}
		if ( $phase === PromotionLifecycle::PHASE_LIVE ) {
			return __( 'Active', 'mp-commerce-promotions' );
		}
		if ( $phase === PromotionLifecycle::PHASE_EXPIRED_ACTIVE || $phase === PromotionLifecycle::PHASE_EXPIRED_PAUSED ) {
			return __( 'Expired', 'mp-commerce-promotions' );
		}

		return PromotionLifecycle::badge_label( $phase );
	}

	private function __construct() {
	}
}
