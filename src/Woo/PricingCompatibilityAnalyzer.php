<?php
/**
 * Heuristic compatibility checks for pricing/tax/shipping/coupon plugins.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class PricingCompatibilityAnalyzer {

	public const SEVERITY_INFO = 'info';

	public const SEVERITY_WARNING = 'warning';

	public const SEVERITY_CRITICAL = 'critical';

	public const OPTION_CACHE = 'mp_cp_pricing_compatibility_cache';

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	public function analyze( bool $use_cache = true ): array {
		if ( $use_cache ) {
			$cached = get_option( self::OPTION_CACHE, null );
			if ( is_array( $cached ) && isset( $cached['issues'], $cached['generated_at'] ) ) {
				$age = time() - strtotime( (string) $cached['generated_at'] );
				if ( $age >= 0 && $age < 1800 ) {
					return $cached['issues'];
				}
			}
		}

		$issues = array();
		$issues = array_merge( $issues, $this->detect_tax_inclusive_pricing() );
		$issues = array_merge( $issues, $this->detect_sale_price_mode() );
		$issues = array_merge( $issues, $this->detect_multi_currency() );
		$issues = array_merge( $issues, $this->detect_bundle_plugins() );
		$issues = array_merge( $issues, $this->detect_coupon_stacking() );

		update_option(
			self::OPTION_CACHE,
			array(
				'generated_at' => current_time( 'mysql' ),
				'issues'       => $issues,
			),
			false
		);

		return $issues;
	}

	public static function reset_cache(): void {
		delete_option( self::OPTION_CACHE );
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_tax_inclusive_pricing(): array {
		if ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) {
			return array(
				array(
					'severity' => self::SEVERITY_INFO,
					'code'     => 'tax_inclusive_prices',
					'message'  => __( 'Store uses tax-inclusive prices; allocation tax estimates are heuristic.', 'mp-commerce-promotions' ),
				),
			);
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_sale_price_mode(): array {
		if ( get_option( 'woocommerce_calc_discounts_sequentially', 'no' ) === 'yes' ) {
			return array(
				array(
					'severity' => self::SEVERITY_WARNING,
					'code'     => 'sequential_discounts',
					'message'  => __( 'WooCommerce sequential discount calculation may interact with promotion fees.', 'mp-commerce-promotions' ),
				),
			);
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_multi_currency(): array {
		if ( defined( 'WCML_VERSION' ) || class_exists( 'WOOCS' ) || class_exists( '\Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher' ) ) {
			return array(
				array(
					'severity' => self::SEVERITY_WARNING,
					'code'     => 'multi_currency_plugin',
					'message'  => __( 'Multi-currency plugin detected; verify promotion amounts per currency.', 'mp-commerce-promotions' ),
				),
			);
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_bundle_plugins(): array {
		if ( class_exists( 'WC_Bundles' ) || class_exists( 'WC_Composite_Products' ) ) {
			return array(
				array(
					'severity' => self::SEVERITY_INFO,
					'code'     => 'bundle_composite_plugin',
					'message'  => __( 'Bundle/composite product plugin detected; line allocation may not match component pricing.', 'mp-commerce-promotions' ),
				),
			);
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_coupon_stacking(): array {
		$evaluator = new CouponCoexistenceEvaluator();
		$native    = $evaluator->evaluate_cart();
		if ( (int) ( $native['native_coupon_count'] ?? 0 ) > 0 ) {
			return array(
				array(
					'severity' => self::SEVERITY_WARNING,
					'code'     => 'native_coupons_active',
					'message'  => (string) ( $native['message'] ?? '' ),
				),
			);
		}

		return array();
	}
}
