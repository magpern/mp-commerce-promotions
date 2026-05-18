<?php
/**
 * Multi-currency plugin detection and confidence (no deep integration).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use wpdb;

final class MultiCurrencyCompatibility {

	public const CONFIDENCE_HIGH = 'high';

	public const CONFIDENCE_MEDIUM = 'medium';

	public const CONFIDENCE_LOW = 'low';

	public const CONFIDENCE_UNSUPPORTED = 'unsupported';

	/**
	 * @return array{
	 *     detected: bool,
	 *     plugin_slug: string|null,
	 *     confidence: string,
	 *     line_mode_warning: bool,
	 *     recommendation: string
	 * }
	 */
	public function snapshot(): array {
		$plugin = $this->detect_plugin_slug();
		$detected = $plugin !== null;

		$confidence = self::CONFIDENCE_HIGH;
		if ( $detected ) {
			$confidence = $this->confidence_for_plugin( $plugin );
		}

		$line_active = $this->active_line_mode_count() > 0;
		$line_warn   = $detected && $line_active && $confidence !== self::CONFIDENCE_HIGH;

		$recommendation = $line_warn
			? __( 'Use fee_based mode with this currency plugin.', 'mp-commerce-promotions' )
			: __( 'Fee-based discount application is recommended for multi-currency stores.', 'mp-commerce-promotions' );

		return array(
			'detected'          => $detected,
			'plugin_slug'       => $plugin,
			'confidence'        => $confidence,
			'line_mode_warning' => $line_warn,
			'recommendation'    => $recommendation,
		);
	}

	private function detect_plugin_slug(): ?string {
		if ( class_exists( 'WOOMC_API' ) || defined( 'WOOMC_VERSION' ) ) {
			return 'woocommerce-multicurrency';
		}
		if ( class_exists( 'WOOCS' ) || defined( 'WOOCS_VERSION' ) ) {
			return 'woocommerce-currency-switcher';
		}
		if ( class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
			return 'aelia-currency-switcher';
		}
		if ( defined( 'WCML_VERSION' ) || class_exists( 'woocommerce_wpml' ) ) {
			return 'wpml-multicurrency';
		}

		return null;
	}

	private function confidence_for_plugin( ?string $slug ): string {
		if ( $slug === 'woocommerce-multicurrency' || $slug === 'wpml-multicurrency' ) {
			return self::CONFIDENCE_MEDIUM;
		}
		if ( $slug === 'woocommerce-currency-switcher' ) {
			return self::CONFIDENCE_LOW;
		}
		if ( $slug === 'aelia-currency-switcher' ) {
			return self::CONFIDENCE_UNSUPPORTED;
		}

		return self::CONFIDENCE_LOW;
	}

	private function active_line_mode_count(): int {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return 0;
		}

		$table = Schema::promotions_table( $wpdb );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND discount_application_mode IN ('line_item','hybrid')";
		$count = DbQuery::get_var( $wpdb, $sql, array() );

		return is_numeric( $count ) ? (int) $count : 0;
	}
}
