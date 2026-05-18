<?php
/**
 * Tax-inclusive store compatibility heuristics (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use wpdb;

final class TaxCompatibilityAnalyzer {

	/**
	 * @return array{
	 *     prices_include_tax: bool,
	 *     tax_display_shop: string,
	 *     tax_display_cart: string,
	 *     rounding_risk: string,
	 *     warnings: list<array{severity: string, code: string, message: string}>
	 * }
	 */
	public function analyze(): array {
		$include_tax = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();
		$shop        = function_exists( 'wc_tax_display_shop' ) ? (string) wc_tax_display_shop() : 'excl';
		$cart        = function_exists( 'wc_tax_display_cart' ) ? (string) wc_tax_display_cart() : 'excl';

		$rounding = 'low';
		if ( $include_tax && $shop !== $cart ) {
			$rounding = 'medium';
		}
		if ( $include_tax && $this->count_line_mode_active() > 0 ) {
			$rounding = 'high';
		}

		$warnings = array();
		if ( $include_tax ) {
			$warnings[] = array(
				'severity' => 'info',
				'code'     => 'prices_include_tax',
				'message'  => __( 'WooCommerce prices include tax. Fee-based discounts align with cart subtotal as displayed.', 'mp-commerce-promotions' ),
			);
		}

		if ( $include_tax && $this->count_line_mode_active() > 0 ) {
			$warnings[] = array(
				'severity' => 'warning',
				'code'     => 'tax_inclusive_line_item',
				'message'  => __( 'Tax-inclusive store with line_item/hybrid promotions — verify line prices after discount on checkout.', 'mp-commerce-promotions' ),
			);
		}

		if ( $include_tax && $this->count_free_shipping_active() > 0 ) {
			$warnings[] = array(
				'severity' => 'info',
				'code'     => 'mixed_shipping_taxes',
				'message'  => __( 'Free shipping fee offsets may interact with tax-inclusive shipping totals.', 'mp-commerce-promotions' ),
			);
		}

		if ( $include_tax && $this->count_scoped_fixed_active() > 0 ) {
			$warnings[] = array(
				'severity' => 'warning',
				'code'     => 'scoped_fixed_tax_inclusive',
				'message'  => __( 'Scoped fixed-amount discounts in tax-inclusive stores may not match displayed line tax breakdown.', 'mp-commerce-promotions' ),
			);
		}

		return array(
			'prices_include_tax' => $include_tax,
			'tax_display_shop'   => $shop,
			'tax_display_cart'   => $cart,
			'rounding_risk'      => $rounding,
			'warnings'           => $warnings,
		);
	}

	public function count_tax_sensitive_promotions( ?PromotionRepository $repo = null ): int {
		global $wpdb;
		if ( $repo !== null ) {
			return $repo->count_tax_sensitive_promotions();
		}
		if ( ! $wpdb instanceof wpdb ) {
			return 0;
		}

		$table = Schema::promotions_table( $wpdb );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE status IN ('active','paused','draft')
			AND (discount_application_mode IN ('line_item','hybrid')
			OR actions LIKE %s OR actions LIKE %s)";
		$count = DbQuery::get_var(
			$wpdb,
			$sql,
			array(
				'%' . RuleTypes::ACTION_FREE_SHIPPING . '%',
				'%' . RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT . '%',
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * @return list<array{label: string, subtotal: float, mode: string, estimated_discount: float}>
	 */
	public function simulate_tax_inclusive_scenarios(): array {
		$include = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();
		$base    = 120.0;

		return array(
			array(
				'label'              => __( 'Fee mode 10% (tax-inclusive subtotal)', 'mp-commerce-promotions' ),
				'subtotal'           => $base,
				'mode'               => PromotionDiscountApplicationMode::FEE_BASED,
				'estimated_discount' => round( $base * 0.10, 2 ),
			),
			array(
				'label'              => __( 'Line mode 10% (unit price mutation)', 'mp-commerce-promotions' ),
				'subtotal'           => $base,
				'mode'               => PromotionDiscountApplicationMode::LINE_ITEM,
				'estimated_discount' => round( $base * 0.10, 2 ),
			),
			array(
				'label'              => __( 'Tax-exclusive reference', 'mp-commerce-promotions' ),
				'subtotal'           => $base,
				'mode'               => $include ? 'inclusive_context' : 'exclusive_context',
				'estimated_discount' => 0.0,
			),
		);
	}

	private function count_line_mode_active(): int {
		return $this->count_by_sql(
			"SELECT COUNT(*) FROM %s WHERE status = 'active' AND discount_application_mode IN ('line_item','hybrid')"
		);
	}

	private function count_free_shipping_active(): int {
		return $this->count_by_sql(
			"SELECT COUNT(*) FROM %s WHERE status = 'active' AND actions LIKE '%free_shipping%'"
		);
	}

	private function count_scoped_fixed_active(): int {
		return $this->count_by_sql(
			"SELECT COUNT(*) FROM %s WHERE status = 'active' AND actions LIKE '%fixed_amount_discount%'"
		);
	}

	private function count_by_sql( string $pattern ): int {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return 0;
		}

		$table = Schema::promotions_table( $wpdb );
		$sql   = sprintf( $pattern, $table );
		$count = DbQuery::get_var( $wpdb, $sql, array() );

		return is_numeric( $count ) ? (int) $count : 0;
	}
}
