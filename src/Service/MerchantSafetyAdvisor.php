<?php
/**
 * Merchant safety heuristics: exposure, runaway discount, schedule conflicts (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;

final class MerchantSafetyAdvisor {

	public const SEVERITY_INFO = 'info';

	public const SEVERITY_WARNING = 'warning';

	public const SEVERITY_CRITICAL = 'critical';

	private PromotionRepository $promotions;

	public function __construct( PromotionRepository $promotions ) {
		$this->promotions = $promotions;
	}

	/**
	 * @return list<array{severity: string, code: string, promotion_id: int, message: string}>
	 */
	public function analyze_promotion( Promotion $promotion ): array {
		$issues = array();
		$id     = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return $issues;
		}

		$issues = array_merge( $issues, $this->detect_high_percentage( $promotion, $id ) );
		$issues = array_merge( $issues, $this->detect_uncapped_fixed_on_large_catalog( $promotion, $id ) );
		$issues = array_merge( $issues, $this->detect_stackable_high_risk( $promotion, $id ) );
		$issues = array_merge( $issues, $this->detect_budget_near_exhaustion( $promotion, $id ) );
		$issues = array_merge( $issues, $this->detect_missing_end_date( $promotion, $id ) );

		return $issues;
	}

	/**
	 * @return list<array{severity: string, code: string, promotion_id: int, message: string}>
	 */
	public function analyze_catalog( int $limit = 200 ): array {
		$all = array();
		foreach ( $this->promotions->find_filtered( array( 'limit' => $limit ) ) as $promotion ) {
			$status = $promotion->get_status();
			if ( $status !== PromotionStatus::ACTIVE && $status !== PromotionStatus::DRAFT ) {
				continue;
			}
			$all = array_merge( $all, $this->analyze_promotion( $promotion ) );
		}

		return $all;
	}

	/**
	 * Estimated max discount exposure for a single cart (heuristic).
	 */
	public function estimate_max_cart_exposure( Promotion $promotion, float $reference_subtotal = 100.0 ): float {
		$reference_subtotal = max( 1.0, $reference_subtotal );
		$total              = 0.0;

		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = (string) ( $action['type'] ?? '' );
			if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
				$pct    = (float) ( $action['percentage'] ?? 0 );
				$total += $reference_subtotal * ( $pct / 100 );
			} elseif ( $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				$total += (float) ( $action['amount'] ?? 0 );
			}
		}

		if ( $promotion->has_budget_cap() ) {
			$remaining = max( 0.0, (float) $promotion->get_budget_cap() - (float) $promotion->get_budget_spent() );
			$total     = min( $total, $remaining );
		}

		return round( max( 0.0, $total ), 2 );
	}

	/**
	 * @return list<array{severity: string, code: string, promotion_id: int, message: string}>
	 */
	private function detect_high_percentage( Promotion $promotion, int $id ): array {
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			if ( (string) ( $action['type'] ?? '' ) !== RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
				continue;
			}
			$pct = (float) ( $action['percentage'] ?? 0 );
			if ( $pct >= 50 ) {
				return array(
					$this->issue(
						self::SEVERITY_CRITICAL,
						'high_percentage_discount',
						$id,
						sprintf(
							/* translators: %1$d: promotion id, %2$.0f: percent */
							__( 'Promotion %1$d applies %2$.0f%% off — high runaway risk.', 'mp-commerce-promotions' ),
							$id,
							$pct
						)
					),
				);
			}
			if ( $pct >= 30 ) {
				return array(
					$this->issue(
						self::SEVERITY_WARNING,
						'elevated_percentage_discount',
						$id,
						sprintf(
							/* translators: %1$d: promotion id, %2$.0f: percent */
							__( 'Promotion %1$d applies %2$.0f%% off — review max exposure.', 'mp-commerce-promotions' ),
							$id,
							$pct
						)
					),
				);
			}
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, promotion_id: int, message: string}>
	 */
	private function detect_uncapped_fixed_on_large_catalog( Promotion $promotion, int $id ): array {
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			if ( (string) ( $action['type'] ?? '' ) !== RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				continue;
			}
			$amount = (float) ( $action['amount'] ?? 0 );
			if ( $amount >= 100 && ! $promotion->has_budget_cap() ) {
				return array(
					$this->issue(
						self::SEVERITY_WARNING,
						'large_fixed_no_budget',
						$id,
						sprintf(
							/* translators: %1$d: promotion id */
							__( 'Promotion %1$d has a large fixed discount without a budget cap.', 'mp-commerce-promotions' ),
							$id
						)
					),
				);
			}
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, promotion_id: int, message: string}>
	 */
	private function detect_stackable_high_risk( Promotion $promotion, int $id ): array {
		if ( $promotion->get_application_mode() !== PromotionApplicationMode::STACKABLE ) {
			return array();
		}
		if ( $promotion->should_stop_processing() ) {
			return array();
		}

		return array(
			$this->issue(
				self::SEVERITY_WARNING,
				'stackable_unbounded',
				$id,
				sprintf(
					/* translators: %d: promotion id */
					__( 'Promotion %d is stackable without stop_processing — may combine with other fees.', 'mp-commerce-promotions' ),
					$id
				)
			),
		);
	}

	/**
	 * @return list<array{severity: string, code: string, promotion_id: int, message: string}>
	 */
	private function detect_budget_near_exhaustion( Promotion $promotion, int $id ): array {
		if ( ! $promotion->has_budget_cap() ) {
			return array();
		}
		$cap   = (float) $promotion->get_budget_cap();
		$spent = (float) $promotion->get_budget_spent();
		if ( $cap <= 0 ) {
			return array();
		}
		$pct = ( $spent / $cap ) * 100;
		if ( $pct >= 90 ) {
			return array(
				$this->issue(
					self::SEVERITY_WARNING,
					'budget_near_exhausted',
					$id,
					sprintf(
						/* translators: %1$d: promotion id, %2$.0f: percent used */
						__( 'Promotion %1$d budget is %2$.0f%% consumed.', 'mp-commerce-promotions' ),
						$id,
						$pct
					)
				),
			);
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, promotion_id: int, message: string}>
	 */
	private function detect_missing_end_date( Promotion $promotion, int $id ): array {
		if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
			return array();
		}
		if ( $promotion->get_ends_at() !== null && $promotion->get_ends_at() !== '' ) {
			return array();
		}

		return array(
			$this->issue(
				self::SEVERITY_INFO,
				'no_end_date',
				$id,
				sprintf(
					/* translators: %d: promotion id */
					__( 'Promotion %d has no end date — consider scheduling an end to limit exposure.', 'mp-commerce-promotions' ),
					$id
				)
			),
		);
	}

	/**
	 * @return array{severity: string, code: string, promotion_id: int, message: string}
	 */
	private function issue( string $severity, string $code, int $promotion_id, string $message ): array {
		return array(
			'severity'     => $severity,
			'code'         => $code,
			'promotion_id' => $promotion_id,
			'message'      => $message,
		);
	}
}
