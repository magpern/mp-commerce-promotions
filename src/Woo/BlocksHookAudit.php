<?php
/**
 * Optional debug logging for WooCommerce hooks used by MP CP (Blocks investigation).
 *
 * Active only when WP_DEBUG is true and mp_cp_blocks_hook_debug is enabled.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Service\Settings;

final class BlocksHookAudit {

	private const LOG_PREFIX = '[mp-cp blocks-hook]';

	private static bool $registered = false;

	/**
	 * @return array<string, string> hook => short label
	 */
	public static function audited_hooks(): array {
		return array(
			'woocommerce_before_calculate_totals' => 'cart_before_calculate_totals',
			'woocommerce_cart_calculate_fees'     => 'cart_calculate_fees',
			'woocommerce_get_shop_coupon_data'      => 'shop_coupon_data',
			'woocommerce_coupon_is_valid'           => 'coupon_is_valid',
			'woocommerce_checkout_create_order'              => 'checkout_create_order',
			'woocommerce_checkout_order_processed'           => 'checkout_order_processed',
			'woocommerce_store_api_checkout_order_processed' => 'store_api_checkout_order_processed',
		);
	}

	public static function register( Settings $settings ): void {
		if ( self::$registered || ! self::should_log( $settings ) ) {
			return;
		}

		foreach ( self::audited_hooks() as $hook => $label ) {
			add_action(
				$hook,
				static function () use ( $label, $hook ): void {
					BlocksHookAudit::log_event( $label, $hook, func_get_args() );
				},
				1,
				99
			);
		}

		self::$registered = true;
	}

	public static function should_log( Settings $settings ): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && $settings->blocks_hook_debug_enabled();
	}

	/**
	 * @param array<int, mixed> $args
	 */
	private static function log_event( string $label, string $hook, array $args ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$context = array( 'source' => 'mp-commerce-promotions-blocks' );
		$summary = array(
			'hook'       => $hook,
			'label'      => $label,
			'arg_count'  => count( $args ),
			'is_rest'    => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'is_ajax'    => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
			'store_api'  => self::is_store_api_request(),
		);

		if ( isset( $args[0] ) && is_object( $args[0] ) ) {
			$summary['arg0_class'] = get_class( $args[0] );
		}

		wc_get_logger()->debug(
			self::LOG_PREFIX . ' ' . wp_json_encode( $summary ),
			$context
		);
	}

	private static function is_store_api_request(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

		return str_contains( $uri, '/wc/store/' );
	}
}
