<?php
/**
 * Read-only environment and compatibility snapshot (no external calls).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Woo\WooCompatibility;

final class CompatibilityStatus {

	/**
	 * @return array<string, mixed>
	 */
	public function collect(): array {
		global $wp_version;

		$wc_version = defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';

		return array(
			'wordpress_version'             => is_string( $wp_version ) ? $wp_version : '',
			'woocommerce_version'           => $wc_version,
			'php_version'                   => PHP_VERSION,
			'hpos_enabled'                  => WooCompatibility::is_hpos_enabled(),
			'hpos_declared_compatible'      => class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ),
			'cart_checkout_blocks_declared' => false,
			'cart_checkout_blocks_note'     => __( 'Not declared — block checkout compatibility pending QA.', 'mp-commerce-promotions' ),
			'discount_strategy'             => array(
				'cart_fees'            => true,
				'free_gift_cart_line'  => true,
				'allocation_reporting' => true,
				'line_price_mutation'  => false,
			),
			'tax_mode'                      => $this->tax_mode(),
			'currency'                      => $this->currency_code(),
			'payment_gateways'              => $this->payment_gateways(),
			'shipping_methods'              => $this->shipping_methods(),
		);
	}

	/**
	 * @return array{prices_include_tax: bool|null, label: string}
	 */
	private function tax_mode(): array {
		if ( function_exists( 'wc_prices_include_tax' ) ) {
			$include = (bool) wc_prices_include_tax();

			return array(
				'prices_include_tax' => $include,
				'label'              => $include
					? __( 'Prices include tax', 'mp-commerce-promotions' )
					: __( 'Prices exclude tax', 'mp-commerce-promotions' ),
			);
		}

		return array(
			'prices_include_tax' => null,
			'label'              => __( 'Unknown (WooCommerce inactive)', 'mp-commerce-promotions' ),
		);
	}

	private function currency_code(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			return (string) get_woocommerce_currency();
		}

		return '';
	}

	/**
	 * @return list<string>
	 */
	private function payment_gateways(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}

		$out      = array();
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! is_array( $gateways ) ) {
			return array();
		}

		foreach ( $gateways as $gateway ) {
			if ( is_object( $gateway ) && method_exists( $gateway, 'get_title' ) && method_exists( $gateway, 'is_enabled' ) ) {
				if ( $gateway->is_enabled() ) {
					$out[] = (string) $gateway->get_title();
				}
			}
		}

		return $out;
	}

	/**
	 * @return list<string>
	 */
	private function shipping_methods(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return array();
		}

		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return array();
		}

		$zones = \WC_Shipping_Zones::get_zones();
		if ( ! is_array( $zones ) ) {
			return array();
		}

		$methods = array();
		foreach ( $zones as $zone ) {
			if ( ! is_array( $zone ) || ! isset( $zone['shipping_methods'] ) || ! is_array( $zone['shipping_methods'] ) ) {
				continue;
			}
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( is_object( $method ) && method_exists( $method, 'get_title' ) ) {
					$methods[] = (string) $method->get_title();
				}
			}
		}

		return array_values( array_unique( $methods ) );
	}
}
