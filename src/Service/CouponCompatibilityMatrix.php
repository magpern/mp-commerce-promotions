<?php
/**
 * Explicit native Woo coupon vs MP CP coexistence scenarios (read-only matrix).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Woo\CouponCoexistenceEvaluator;

final class CouponCompatibilityMatrix {

	/**
	 * @return list<array{id: string, label: string, risk: string, certified: string, notes: string}>
	 */
	public function build_scenarios(): array {
		$native = ( new CouponCoexistenceEvaluator() )->evaluate_cart();

		return array(
			array(
				'id'         => 'native_only',
				'label'      => __( 'Native Woo coupons only', 'mp-commerce-promotions' ),
				'risk'       => 'low',
				'certified'  => 'operational',
				'notes'      => __( 'MP CP automatic promotions may still run per settings; native coupons apply via WooCommerce.', 'mp-commerce-promotions' ),
			),
			array(
				'id'         => 'mp_cp_only',
				'label'      => __( 'MP Commerce Promotions only', 'mp-commerce-promotions' ),
				'risk'       => 'low',
				'certified'  => 'operational',
				'notes'      => __( 'No native coupons on cart; planner and fee/line application as configured.', 'mp-commerce-promotions' ),
			),
			array(
				'id'         => 'mixed_fee_native',
				'label'      => __( 'Mixed fee-based + native coupons', 'mp-commerce-promotions' ),
				'risk'       => 'medium',
				'certified'  => 'partial',
				'notes'      => __( 'Use coupon_behavior coexist/block on promotions; fee mode is default certified path.', 'mp-commerce-promotions' ),
			),
			array(
				'id'         => 'mixed_line_native',
				'label'      => __( 'Mixed line discounts + native coupons', 'mp-commerce-promotions' ),
				'risk'       => 'high',
				'certified'  => 'experimental',
				'notes'      => __( 'Line mutation may conflict with coupon recalculation; hybrid falls back to fees when configured.', 'mp-commerce-promotions' ),
			),
			array(
				'id'         => 'shipping_coupons',
				'label'      => __( 'Shipping coupons + free shipping promotions', 'mp-commerce-promotions' ),
				'risk'       => 'medium',
				'certified'  => 'partial',
				'notes'      => __( 'Free shipping promotions block when native shipping coupon detected.', 'mp-commerce-promotions' ),
			),
			array(
				'id'         => 'auto_coupons',
				'label'      => __( 'Auto-applied native coupons', 'mp-commerce-promotions' ),
				'risk'       => 'medium',
				'certified'  => 'partial',
				'notes'      => sprintf(
					/* translators: %d: native coupon count on current cart */
					__( 'Current cart native coupon count: %d. Re-test after plugin or theme changes.', 'mp-commerce-promotions' ),
					(int) ( $native['native_coupon_count'] ?? 0 )
				),
			),
		);
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	public function collect_diagnostics_warnings( ?Promotion $subject = null ): array {
		$warnings = array();
		$native   = ( new CouponCoexistenceEvaluator() )->evaluate_cart();
		$count    = (int) ( $native['native_coupon_count'] ?? 0 );

		if ( $count > 0 && $this->store_has_line_mode_promotions() ) {
			$warnings[] = array(
				'severity' => 'warning',
				'code'     => 'stacked_native_line_mode',
				'message'  => __( 'Native coupons are on the cart while active promotions use line-item discount mode — double-discount and recalculation-loop risk.', 'mp-commerce-promotions' ),
			);
		}

		if ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() && $this->store_has_line_mode_promotions() ) {
			$warnings[] = array(
				'severity' => 'warning',
				'code'     => 'tax_inclusive_line_mode',
				'message'  => __( 'Tax-inclusive catalog with line-item mode may produce rounding differences vs fee mode.', 'mp-commerce-promotions' ),
			);
		}

		if ( $count > 2 ) {
			$warnings[] = array(
				'severity' => 'warning',
				'code'     => 'coupon_recalculation_loop',
				'message'  => __( 'Multiple native coupons increase totals recalculation churn with MP promotions.', 'mp-commerce-promotions' ),
			);
		}

		if ( $subject !== null && $count > 0 ) {
			$behavior = $subject->get_coupon_behavior();
			if ( $behavior === PromotionCouponBehavior::BLOCK_NATIVE ) {
				$warnings[] = array(
					'severity' => 'info',
					'code'     => 'promotion_blocks_native',
					'message'  => __( 'This promotion blocks when native coupons are present (expected skip at planner).', 'mp-commerce-promotions' ),
				);
			}
		}

		return $warnings;
	}

	private function store_has_line_mode_promotions(): bool {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$table = Schema::promotions_table( $wpdb );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND discount_application_mode IN ('line_item','hybrid')";
		$count = DbQuery::get_var( $wpdb, $sql, array() );

		return is_numeric( $count ) && (int) $count > 0;
	}
}
