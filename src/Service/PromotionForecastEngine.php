<?php
/**
 * Heuristic promotion forecasting from telemetry and redemption history (no ML).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\RedemptionRepository;

final class PromotionForecastEngine {

	public const OPTION_CACHE = 'mp_cp_forecast_cache';

	private PromotionRepository $promotions;

	private RedemptionRepository $redemptions;

	private PlannerTelemetryRepository $telemetry;

	public function __construct(
		PromotionRepository $promotions,
		RedemptionRepository $redemptions,
		PlannerTelemetryRepository $telemetry
	) {
		$this->promotions  = $promotions;
		$this->redemptions = $redemptions;
		$this->telemetry   = $telemetry;
	}

	/**
	 * @param list<int>|null $promotion_ids
	 * @return array<string, mixed>
	 */
	public function forecast_catalog( ?array $promotion_ids = null, bool $use_cache = true ): array {
		if ( $use_cache ) {
			$cached = get_option( self::OPTION_CACHE, null );
			if ( is_array( $cached ) && isset( $cached['generated_at'] ) ) {
				$age = time() - strtotime( (string) $cached['generated_at'] );
				if ( $age >= 0 && $age < 3600 ) {
					$cached['from_cache'] = true;
					return $cached;
				}
			}
		}

		$promotions = $this->load_promotions( $promotion_ids );
		$rows       = array();

		$total_exposure         = 0.0;
		$total_projected_volume = 0;
		$total_cooldown_blocks  = 0;
		$total_orchestration    = 0;
		$discount_samples       = array();

		foreach ( $promotions as $promotion ) {
			$row                     = $this->forecast_promotion( $promotion );
			$rows[]                  = $row;
			$total_exposure         += (float) ( $row['estimated_discount_exposure'] ?? 0 );
			$total_projected_volume += (int) ( $row['projected_redemption_volume'] ?? 0 );
			$total_cooldown_blocks  += (int) ( $row['projected_cooldown_blocks'] ?? 0 );
			$total_orchestration    += (int) ( $row['projected_orchestration_conflicts'] ?? 0 );
			if ( isset( $row['estimated_avg_discount'] ) && $row['estimated_avg_discount'] > 0 ) {
				$discount_samples[] = (float) $row['estimated_avg_discount'];
			}
		}

		$summary = array(
			'generated_at'                      => current_time( 'mysql' ),
			'promotion_count'                   => count( $rows ),
			'estimated_discount_exposure'       => round( $total_exposure, 2 ),
			'projected_redemption_volume'       => $total_projected_volume,
			'projected_cooldown_blocks'         => $total_cooldown_blocks,
			'projected_orchestration_conflicts' => $total_orchestration,
			'estimated_average_discount'        => $discount_samples !== array()
				? round( array_sum( $discount_samples ) / count( $discount_samples ), 2 )
				: 0.0,
			'promotions'                        => $rows,
			'from_cache'                        => false,
		);

		update_option( self::OPTION_CACHE, $summary, false );

		return $summary;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function forecast_promotion( Promotion $promotion ): array {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return array();
		}

		$recorded = $this->redemptions->count_recorded_for_promotion( $id );
		$avg      = $recorded > 0
			? $this->redemptions->avg_recorded_discount_amount( array( 'promotion_id' => $id ) )
			: 0.0;

		$telemetry_rows = $this->telemetry->top_by_column( 'selected_count', 500 );
		$telemetry      = array();
		foreach ( $telemetry_rows as $row ) {
			if ( (int) ( $row['promotion_id'] ?? 0 ) === $id ) {
				$telemetry = $row;
				break;
			}
		}

		$selected_telemetry = (int) ( $telemetry['selected_count'] ?? 0 );
		$blocked_group      = (int) ( $telemetry['blocked_by_group_count'] ?? 0 );
		$blocked_cooldown   = (int) ( $telemetry['blocked_by_cooldown_count'] ?? 0 );

		$weekly_rate      = max( 1, (int) ceil( $recorded / 4 ) );
		$projected_volume = $weekly_rate * 4;

		$exposure = $avg * $projected_volume;

		$budget_exhaustion_at = null;
		if ( $promotion->has_budget_cap() && $promotion->get_budget_amount() !== null ) {
			$remaining = (float) $promotion->get_budget_amount() - $promotion->get_budget_spent();
			if ( $avg > 0 && $remaining > 0 ) {
				$days                 = (int) ceil( $remaining / max( 0.01, $avg * max( 1, $weekly_rate / 7 ) ) );
				$budget_exhaustion_at = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $days . ' days' ) );
			} elseif ( $remaining <= 0 ) {
				$budget_exhaustion_at = current_time( 'mysql' );
			}
		}

		return array(
			'promotion_id'                      => $id,
			'name'                              => $promotion->get_name(),
			'estimated_discount_exposure'       => round( $exposure, 2 ),
			'projected_budget_exhaustion_at'    => $budget_exhaustion_at,
			'projected_redemption_volume'       => $projected_volume,
			'projected_cooldown_blocks'         => $blocked_cooldown,
			'projected_orchestration_conflicts' => $blocked_group > 0 ? 1 : 0,
			'estimated_avg_discount'            => round( $avg, 2 ),
			'telemetry_selected'                => $selected_telemetry,
		);
	}

	public static function reset_cache(): void {
		delete_option( self::OPTION_CACHE );
	}

	/**
	 * @param list<int>|null $ids
	 * @return list<Promotion>
	 */
	private function load_promotions( ?array $ids ): array {
		if ( $ids !== null && $ids !== array() ) {
			$out = array();
			foreach ( $ids as $id ) {
				$p = $this->promotions->find( (int) $id );
				if ( $p instanceof Promotion ) {
					$out[] = $p;
				}
			}
			return $out;
		}

		return $this->promotions->find_filtered( array( 'limit' => 100 ) );
	}
}
