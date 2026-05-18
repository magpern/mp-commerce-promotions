<?php
/**
 * Ecosystem plugin detection matrix for GA compatibility audit (no deep integration).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Woo\WooCompatibility;

final class EcosystemCompatibilityRegistry {

	public const STATUS_CERTIFIED = 'certified';

	public const STATUS_PARTIAL = 'partial';

	public const STATUS_UNKNOWN = 'unknown';

	public const STATUS_UNSUPPORTED = 'unsupported';

	public const CONFIDENCE_HIGH = 'high';

	public const CONFIDENCE_MEDIUM = 'medium';

	public const CONFIDENCE_LOW = 'low';

	public const OPTION_SNAPSHOT = 'mp_cp_ecosystem_compatibility_snapshot';

	/**
	 * @return list<array<string, mixed>>
	 */
	public function build_matrix( bool $use_cache = true ): array {
		if ( $use_cache ) {
			$cached = get_option( self::OPTION_SNAPSHOT, null );
			if ( is_array( $cached ) && isset( $cached['matrix'], $cached['generated_at'] ) ) {
				$age = time() - strtotime( (string) $cached['generated_at'] );
				if ( $age >= 0 && $age < 1800 ) {
					return $cached['matrix'];
				}
			}
		}

		$rows = array(
			$this->row_subscriptions(),
			$this->row_product_bundles(),
			$this->row_composite_products(),
			$this->row_multi_currency(),
			$this->row_tax_inclusive(),
			$this->row_germanized(),
			$this->row_dynamic_pricing(),
			$this->row_memberships(),
			$this->row_object_cache(),
			$this->row_hpos(),
			$this->row_cart_checkout_blocks(),
		);

		update_option(
			self::OPTION_SNAPSHOT,
			array(
				'generated_at' => current_time( 'mysql' ),
				'matrix'       => $rows,
			),
			false
		);

		return $rows;
	}

	/**
	 * @return array{score: int, confidence: string, detected_count: int, warnings: int, matrix: list<array<string, mixed>>}
	 */
	public function summarize( bool $use_cache = true ): array {
		$matrix   = $this->build_matrix( $use_cache );
		$score    = 100;
		$warnings = 0;

		foreach ( $matrix as $row ) {
			if ( empty( $row['detected'] ) ) {
				continue;
			}
			$severity = (string) ( $row['severity'] ?? 'info' );
			if ( $severity === 'critical' ) {
				$score -= 20;
				++$warnings;
			} elseif ( $severity === 'warning' ) {
				$score -= 8;
				++$warnings;
			} else {
				$score -= 2;
			}
		}

		$score = max( 0, min( 100, $score ) );
		$conf  = self::CONFIDENCE_HIGH;
		if ( $score < 75 ) {
			$conf = self::CONFIDENCE_MEDIUM;
		}
		if ( $score < 50 ) {
			$conf = self::CONFIDENCE_LOW;
		}

		return array(
			'score'          => $score,
			'confidence'     => $conf,
			'detected_count' => count(
				array_filter(
					$matrix,
					static function ( array $row ): bool {
						return ! empty( $row['detected'] );
					}
				)
			),
			'warnings'       => $warnings,
			'matrix'         => $matrix,
		);
	}

	public static function reset_cache(): void {
		delete_option( self::OPTION_SNAPSHOT );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_subscriptions(): array {
		$detected = class_exists( 'WC_Subscriptions' ) || defined( 'WCS_INIT_PLUGIN_FILE' );

		return $this->build_row(
			'woocommerce_subscriptions',
			__( 'WooCommerce Subscriptions', 'mp-commerce-promotions' ),
			$detected,
			$detected ? self::STATUS_PARTIAL : self::STATUS_UNKNOWN,
			$detected ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_HIGH,
			$detected ? 'warning' : 'info',
			'woocommerce_subscriptions',
			$detected
				? __( 'WooCommerce Subscriptions detected; renewal carts need separate QA.', 'mp-commerce-promotions' )
				: __( 'WooCommerce Subscriptions not detected.', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_product_bundles(): array {
		$detected = class_exists( 'WC_Bundles' ) || defined( 'WC_PB_VERSION' );

		return $this->build_row(
			'woocommerce_product_bundles',
			__( 'Product Bundles', 'mp-commerce-promotions' ),
			$detected,
			$detected ? self::STATUS_PARTIAL : self::STATUS_UNKNOWN,
			self::CONFIDENCE_MEDIUM,
			$detected ? 'warning' : 'info',
			'woocommerce_product_bundles',
			$detected
				? __( 'WooCommerce Product Bundles detected.', 'mp-commerce-promotions' )
				: __( 'Product Bundles not detected.', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_composite_products(): array {
		$detected = class_exists( 'WC_Composite_Products' ) || defined( 'WC_CP_VERSION' );

		return $this->build_row(
			'woocommerce_composite_products',
			__( 'Composite Products', 'mp-commerce-promotions' ),
			$detected,
			$detected ? self::STATUS_PARTIAL : self::STATUS_UNKNOWN,
			self::CONFIDENCE_MEDIUM,
			$detected ? 'warning' : 'info',
			'woocommerce_composite_products',
			$detected
				? __( 'WooCommerce Composite Products detected.', 'mp-commerce-promotions' )
				: __( 'Composite Products not detected.', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_multi_currency(): array {
		$detected = defined( 'WCML_VERSION' )
			|| class_exists( 'WOOCS' )
			|| class_exists( '\Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher' )
			|| class_exists( '\WCPBC_Pricing_Zones' );

		return $this->build_row(
			'multi_currency',
			__( 'Multi-currency', 'mp-commerce-promotions' ),
			$detected,
			$detected ? self::STATUS_PARTIAL : self::STATUS_CERTIFIED,
			$detected ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_HIGH,
			$detected ? 'warning' : 'info',
			'multi_currency_plugin',
			$detected
				? __( 'Multi-currency plugin detected.', 'mp-commerce-promotions' )
				: __( 'Single-currency context (no known multi-currency plugin).', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_tax_inclusive(): array {
		$detected = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();

		return $this->build_row(
			'tax_inclusive',
			__( 'Tax-inclusive pricing', 'mp-commerce-promotions' ),
			$detected,
			$detected ? self::STATUS_PARTIAL : self::STATUS_CERTIFIED,
			self::CONFIDENCE_HIGH,
			'info',
			'tax_inclusive_prices',
			$detected
				? __( 'Store uses tax-inclusive prices.', 'mp-commerce-promotions' )
				: __( 'Store uses tax-exclusive prices (or WooCommerce inactive).', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_germanized(): array {
		$detected = class_exists( 'WooCommerce_Germanized' )
			|| defined( 'WC_GERMANIZED_VERSION' )
			|| class_exists( 'WC_GZD_Legal_Checkbox_Manager' );

		return $this->build_row(
			'germanized',
			__( 'Germanized / EU VAT', 'mp-commerce-promotions' ),
			$detected,
			$detected ? self::STATUS_PARTIAL : self::STATUS_UNKNOWN,
			self::CONFIDENCE_MEDIUM,
			'info',
			'germanized_detected',
			$detected
				? __( 'Germanized or EU VAT helper plugin detected.', 'mp-commerce-promotions' )
				: __( 'Germanized not detected.', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_dynamic_pricing(): array {
		$detected = defined( 'WOO_DISCOUNT_RULES_VERSION' )
			|| class_exists( 'WC_Dynamic_Pricing' )
			|| class_exists( 'RP_WCDPD' );

		return $this->build_row(
			'dynamic_pricing',
			__( 'Dynamic pricing plugins', 'mp-commerce-promotions' ),
			$detected,
			$detected ? self::STATUS_PARTIAL : self::STATUS_UNKNOWN,
			self::CONFIDENCE_LOW,
			$detected ? 'warning' : 'info',
			'dynamic_pricing_plugin',
			$detected
				? __( 'Dynamic pricing / discount rules plugin detected.', 'mp-commerce-promotions' )
				: __( 'No known dynamic pricing plugin detected.', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_memberships(): array {
		$detected = class_exists( 'WC_Memberships' ) || class_exists( 'WC_Member_Order_Post_Types' );

		return $this->build_row(
			'memberships',
			__( 'WooCommerce Memberships', 'mp-commerce-promotions' ),
			$detected,
			$detected ? self::STATUS_PARTIAL : self::STATUS_UNKNOWN,
			self::CONFIDENCE_MEDIUM,
			'info',
			'membership_plugin',
			$detected
				? __( 'WooCommerce Memberships detected.', 'mp-commerce-promotions' )
				: __( 'Memberships plugin not detected.', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_object_cache(): array {
		$detected = function_exists( 'wp_using_ext_object_cache' ) && (bool) wp_using_ext_object_cache();

		return $this->build_row(
			'object_cache',
			__( 'Object cache / Redis', 'mp-commerce-promotions' ),
			$detected,
			$detected ? self::STATUS_PARTIAL : self::STATUS_CERTIFIED,
			self::CONFIDENCE_HIGH,
			'info',
			'external_object_cache',
			$detected
				? __( 'External object cache detected.', 'mp-commerce-promotions' )
				: __( 'WordPress object cache default (non-persistent).', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_hpos(): array {
		$hpos = WooCompatibility::is_hpos_enabled();

		return $this->build_row(
			'hpos',
			__( 'HPOS (custom order tables)', 'mp-commerce-promotions' ),
			$hpos,
			$hpos ? self::STATUS_CERTIFIED : self::STATUS_PARTIAL,
			self::CONFIDENCE_HIGH,
			$hpos ? 'info' : 'warning',
			$hpos ? 'hpos_enabled' : 'hpos_legacy_posts',
			$hpos
				? __( 'HPOS is enabled; plugin declares custom_order_tables compatibility.', 'mp-commerce-promotions' )
				: __( 'HPOS not enabled; orders use post-based storage.', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_cart_checkout_blocks(): array {
		$blocks_pkg = class_exists( '\Automattic\WooCommerce\Blocks\Package', false );
		$declared   = WooCompatibility::is_cart_checkout_blocks_declared();

		return $this->build_row(
			'cart_checkout_blocks',
			__( 'Cart/Checkout Blocks', 'mp-commerce-promotions' ),
			$blocks_pkg,
			$declared ? self::STATUS_CERTIFIED : ( $blocks_pkg ? self::STATUS_PARTIAL : self::STATUS_UNKNOWN ),
			$declared ? self::CONFIDENCE_HIGH : self::CONFIDENCE_MEDIUM,
			$blocks_pkg && ! $declared ? 'warning' : 'info',
			'checkout_blocks_active',
			$declared
				? __( 'Cart/Checkout Blocks package present; compatibility declared.', 'mp-commerce-promotions' )
				: ( $blocks_pkg
					? __( 'Blocks package present; cart_checkout_blocks not declared.', 'mp-commerce-promotions' )
					: __( 'WooCommerce Blocks package not detected.', 'mp-commerce-promotions' ) )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_row(
		string $slug,
		string $label,
		bool $detected,
		string $status,
		string $confidence,
		string $severity,
		string $code,
		string $message
	): array {
		return array(
			'slug'       => $slug,
			'label'      => $label,
			'detected'   => $detected,
			'status'     => $status,
			'confidence' => $confidence,
			'severity'   => $severity,
			'code'       => $code,
			'message'    => $message,
			'limitation' => KnownLimitationsRegistry::lookup( $code ),
		);
	}
}
