<?php
/**
 * Read-only environment and compatibility snapshot (no external calls).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Woo\BlocksHookAudit;
use MP\CommercePromotions\Woo\WooCompatibility;

final class CompatibilityStatus {

	private BlockTestPages $block_pages;

	public function __construct( ?BlockTestPages $block_pages = null ) {
		$this->block_pages = $block_pages ?? new BlockTestPages();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function collect(): array {
		global $wp_version;

		$wc_version   = defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
		$block_state  = $this->block_pages->resolve_page_state();
		$block_status = $this->block_pages->compatibility_status();
		$block_notes  = $this->block_pages->compatibility_notes();
		$urls         = $this->block_pages->preview_urls(
			(int) $block_state['cart_page_id'],
			(int) $block_state['checkout_page_id']
		);

		return array(
			'wordpress_version'             => is_string( $wp_version ) ? $wp_version : '',
			'woocommerce_version'           => $wc_version,
			'php_version'                   => PHP_VERSION,
			'hpos_enabled'                  => WooCompatibility::is_hpos_enabled(),
			'hpos_declared_compatible'      => class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ),
			'cart_checkout_blocks_declared' => WooCompatibility::is_cart_checkout_blocks_declared(),
			'cart_checkout_blocks_note'     => WooCompatibility::is_cart_checkout_blocks_declared()
				? __( 'Declared compatible with Cart/Checkout Blocks (browser QA 2026-05-18).', 'mp-commerce-promotions' )
				: __( 'Not declared — block checkout compatibility pending QA.', 'mp-commerce-promotions' ),
			'block_cart_page_id'            => (int) $block_state['cart_page_id'],
			'block_checkout_page_id'        => (int) $block_state['checkout_page_id'],
			'block_pages_present'           => (bool) $block_state['block_pages_present'],
			'block_compatibility_status'    => $block_status,
			'block_compatibility_notes'     => $block_notes,
			'block_preview_urls'            => $urls,
			'blocks_hook_audit_hooks'       => array_keys( BlocksHookAudit::audited_hooks() ),
			'blocks_package_present'        => class_exists( \Automattic\WooCommerce\Blocks\Package::class, false ),
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
