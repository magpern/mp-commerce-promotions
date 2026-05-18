<?php
/**
 * WooCommerce feature compatibility declarations (HPOS, etc.).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class WooCompatibility {

	/**
	 * Register hooks that must run before WooCommerce initializes.
	 */
	public static function register(): void {
		add_action( 'before_woocommerce_init', array( self::class, 'declare_feature_compatibility' ) );
	}

	/**
	 * Declare compatibility with WooCommerce features (safe when WC is inactive or older).
	 */
	public static function declare_feature_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		if ( ! defined( 'MP_COMMERCE_PROMOTIONS_FILE' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			MP_COMMERCE_PROMOTIONS_FILE,
			true
		);
	}

	/**
	 * Whether WooCommerce HPOS (custom order tables) is enabled.
	 */
	public static function is_hpos_enabled(): bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Whether this plugin has declared cart_checkout_blocks compatibility with WooCommerce.
	 */
	public static function is_cart_checkout_blocks_declared(): bool {
		return false;
	}

	private function __construct() {
	}
}
