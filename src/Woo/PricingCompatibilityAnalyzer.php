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

	public const CONFIDENCE_HIGH = 'high';

	public const CONFIDENCE_MEDIUM = 'medium';

	public const CONFIDENCE_LOW = 'low';

	public const CONFIDENCE_UNKNOWN = 'unknown';

	public static function reset_cache(): void {
		delete_option( self::OPTION_CACHE );
	}

	/**
	 * @return array{confidence: string, score: int, issues: list<array{severity: string, code: string, message: string}>, recommendations: list<string>}
	 */
	public function audit_with_confidence( bool $use_cache = true ): array {
		try {
			$issues = $this->analyze( $use_cache );
		} catch ( \Throwable $e ) {
			return array(
				'confidence'      => self::CONFIDENCE_UNKNOWN,
				'score'           => 0,
				'issues'          => array(),
				'recommendations' => array(
					__( 'Compatibility analyzer failed; see debug log.', 'mp-commerce-promotions' ),
				),
			);
		}

		$issues = array_merge( $issues, $this->detect_subscriptions_plugin() );
		$issues = array_merge( $issues, $this->detect_checkout_blocks() );
		$issues = array_merge( $issues, $this->detect_object_cache() );
		$issues = array_merge( $issues, $this->detect_aggressive_coupon_plugins() );
		$issues = array_merge( $issues, $this->detect_tax_plugins() );
		$issues = array_merge( $issues, $this->detect_ecosystem_matrix() );

		$score = 100;
		foreach ( $issues as $issue ) {
			$severity = (string) ( $issue['severity'] ?? '' );
			if ( $severity === self::SEVERITY_CRITICAL ) {
				$score -= 25;
			} elseif ( $severity === self::SEVERITY_WARNING ) {
				$score -= 10;
			} else {
				$score -= 3;
			}
		}
		$score = max( 0, min( 100, $score ) );

		$confidence = self::CONFIDENCE_HIGH;
		if ( $score < 70 ) {
			$confidence = self::CONFIDENCE_MEDIUM;
		}
		if ( $score < 45 ) {
			$confidence = self::CONFIDENCE_LOW;
		}

		return array(
			'confidence'      => $confidence,
			'score'           => $score,
			'issues'          => $issues,
			'recommendations' => $this->build_recommendations( $issues ),
		);
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

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_subscriptions_plugin(): array {
		if ( class_exists( 'WC_Subscriptions' ) ) {
			return array(
				array(
					'severity' => self::SEVERITY_WARNING,
					'code'     => 'woocommerce_subscriptions',
					'message'  => __( 'WooCommerce Subscriptions detected; renewal carts may need separate QA.', 'mp-commerce-promotions' ),
				),
			);
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_checkout_blocks(): array {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			return array();
		}
		if ( \MP\CommercePromotions\Woo\WooCompatibility::is_cart_checkout_blocks_declared() ) {
			return array(
				array(
					'severity' => self::SEVERITY_INFO,
					'code'     => 'checkout_blocks_active',
					'message'  => __( 'Cart/Checkout Blocks package present; compatibility declared.', 'mp-commerce-promotions' ),
				),
			);
		}

		return array(
			array(
				'severity' => self::SEVERITY_WARNING,
				'code'     => 'checkout_blocks_active',
				'message'  => __( 'Cart/Checkout Blocks may be active; cart_checkout_blocks not declared.', 'mp-commerce-promotions' ),
			),
		);
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_object_cache(): array {
		if ( wp_using_ext_object_cache() ) {
			return array(
				array(
					'severity' => self::SEVERITY_INFO,
					'code'     => 'external_object_cache',
					'message'  => __( 'External object cache detected; planner transients rely on cache flush policies.', 'mp-commerce-promotions' ),
				),
			);
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_aggressive_coupon_plugins(): array {
		if ( class_exists( 'WC_Smart_Coupons' ) || defined( 'WOO_DISCOUNT_RULES_VERSION' ) ) {
			return array(
				array(
					'severity' => self::SEVERITY_WARNING,
					'code'     => 'advanced_coupon_plugin',
					'message'  => __( 'Advanced coupon/discount plugin detected; verify stacking with promotion fees.', 'mp-commerce-promotions' ),
				),
			);
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_tax_plugins(): array {
		if ( class_exists( 'WC_Taxjar' ) || class_exists( 'WC_AvaTax' ) ) {
			return array(
				array(
					'severity' => self::SEVERITY_INFO,
					'code'     => 'external_tax_service',
					'message'  => __( 'External tax service plugin detected; allocation tax estimates remain heuristic.', 'mp-commerce-promotions' ),
				),
			);
		}

		return array();
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_ecosystem_matrix(): array {
		$registry = new \MP\CommercePromotions\Service\EcosystemCompatibilityRegistry();
		$issues   = array();

		foreach ( $registry->build_matrix( true ) as $row ) {
			if ( empty( $row['detected'] ) ) {
				continue;
			}
			$status = (string) ( $row['status'] ?? '' );
			if ( $status === \MP\CommercePromotions\Service\EcosystemCompatibilityRegistry::STATUS_CERTIFIED
				&& (string) ( $row['severity'] ?? '' ) === self::SEVERITY_INFO ) {
				continue;
			}
			$issues[] = array(
				'severity' => (string) ( $row['severity'] ?? self::SEVERITY_INFO ),
				'code'     => (string) ( $row['code'] ?? '' ),
				'message'  => (string) ( $row['message'] ?? '' ),
			);
		}

		return $issues;
	}

	/**
	 * @param list<array{severity: string, code: string, message: string}> $issues
	 * @return list<string>
	 */
	private function build_recommendations( array $issues ): array {
		$codes = array_column( $issues, 'code' );
		$recs  = array();
		if ( in_array( 'checkout_blocks_active', $codes, true ) ) {
			$recs[] = __( 'Test classic checkout or document block-checkout limitations for merchants.', 'mp-commerce-promotions' );
		}
		if ( in_array( 'multi_currency_plugin', $codes, true ) ) {
			$recs[] = __( 'Validate promotion amounts in each currency before high-traffic campaigns.', 'mp-commerce-promotions' );
		}
		if ( in_array( 'advanced_coupon_plugin', $codes, true ) ) {
			$recs[] = __( 'Review coupon coexistence settings on promotions when native coupons stack.', 'mp-commerce-promotions' );
		}
		if ( $recs === array() ) {
			$recs[] = __( 'No critical compatibility blockers detected by heuristics.', 'mp-commerce-promotions' );
		}

		return $recs;
	}

	/**
	 * Line-item discount mode confidence for a single promotion.
	 *
	 * @return array{confidence: string, score: int, issues: list<array{severity: string, code: string, message: string}>}
	 */
	public function audit_line_discount_mode( string $discount_application_mode, array $promotion_actions = array() ): array {
		$issues = array();
		$score  = 90;

		if ( ! \MP\CommercePromotions\Domain\PromotionDiscountApplicationMode::uses_line_mutation( $discount_application_mode ) ) {
			return array(
				'confidence' => self::CONFIDENCE_HIGH,
				'score'      => 100,
				'issues'     => array(),
			);
		}

		$score -= 15;
		$issues[] = array(
			'severity' => self::SEVERITY_INFO,
			'code'     => 'line_discount_mode_beta',
			'message'  => __( 'Line-item discount mode is experimental; fee-based mode remains the default.', 'mp-commerce-promotions' ),
		);

		if ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) {
			$score -= 20;
			$issues[] = array(
				'severity' => self::SEVERITY_WARNING,
				'code'     => 'line_mode_tax_inclusive',
				'message'  => __( 'Tax-inclusive pricing may mismatch displayed line savings.', 'mp-commerce-promotions' ),
			);
		}

		$issues = array_merge( $issues, $this->detect_sale_price_mode() );
		$issues = array_merge( $issues, $this->detect_bundle_plugins() );
		$issues = array_merge( $issues, $this->detect_coupon_stacking() );
		$issues = array_merge( $issues, $this->detect_multi_currency() );
		$issues = array_merge( $issues, $this->detect_subscriptions_plugin() );
		$issues = array_merge( $issues, $this->detect_checkout_blocks_for_line_mode() );
		$issues = array_merge( $issues, $this->detect_active_native_coupons_line_mode() );

		foreach ( $promotion_actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			if ( ! empty( $action['product_ids'] ) || ! empty( $action['category_ids'] ) ) {
				$score -= 5;
				$issues[] = array(
					'severity' => self::SEVERITY_INFO,
					'code'     => 'line_mode_scoped_action',
					'message'  => __( 'Scoped line actions rely on cart context product matching.', 'mp-commerce-promotions' ),
				);
				break;
			}
		}

		$confidence = self::CONFIDENCE_HIGH;
		if ( $score < 70 ) {
			$confidence = self::CONFIDENCE_LOW;
		} elseif ( $score < 85 ) {
			$confidence = self::CONFIDENCE_MEDIUM;
		}

		return array(
			'confidence' => $confidence,
			'score'      => max( 0, min( 100, $score ) ),
			'issues'     => $issues,
		);
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_checkout_blocks_for_line_mode(): array {
		$base = $this->detect_checkout_blocks();
		if ( $base === array() ) {
			return array();
		}

		foreach ( $base as &$issue ) {
			if ( ( $issue['code'] ?? '' ) === 'checkout_blocks_active' ) {
				$issue['severity'] = self::SEVERITY_WARNING;
				$issue['message']  = __( 'Cart/Checkout Blocks are not certified for line-item discounts; use classic cart/checkout for QA.', 'mp-commerce-promotions' );
			}
		}
		unset( $issue );

		return $base;
	}

	/**
	 * @return list<array{severity: string, code: string, message: string}>
	 */
	private function detect_active_native_coupons_line_mode(): array {
		$eval = ( new CouponCoexistenceEvaluator() )->evaluate_cart();
		if ( (int) ( $eval['native_coupon_count'] ?? 0 ) <= 0 ) {
			return array();
		}

		return array(
			array(
				'severity' => self::SEVERITY_WARNING,
				'code'     => 'line_mode_native_coupons',
				'message'  => __( 'Native WooCommerce coupons are applied; line discounts may be blocked per coupon_behavior.', 'mp-commerce-promotions' ),
			),
		);
	}
}
