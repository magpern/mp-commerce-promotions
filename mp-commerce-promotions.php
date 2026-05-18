<?php
/**
 * Plugin Name:       Commerce Promotions for WooCommerce
 * Plugin URI:        https://github.com/magpern/mp-commerce-promotions
 * Description:       Generic WooCommerce promotion engine for discounts, promotion codes, and voucher workflows.
 * Version:           0.2.0-beta.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Magpern
 * Author URI:        https://github.com/magpern
 * Text Domain:       mp-commerce-promotions
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 8.0
 * WC tested up to:   10.7
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MP_COMMERCE_PROMOTIONS_VERSION', '0.2.0-beta.1' );
define( 'MP_COMMERCE_PROMOTIONS_FILE', __FILE__ );
define( 'MP_COMMERCE_PROMOTIONS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MP_COMMERCE_PROMOTIONS_URL', plugin_dir_url( __FILE__ ) );

require_once MP_COMMERCE_PROMOTIONS_PATH . 'src/php74-compat.php';
require_once MP_COMMERCE_PROMOTIONS_PATH . 'src/autoload.php';

\MP\CommercePromotions\Woo\WooCompatibility::register();

register_activation_hook( __FILE__, array( \MP\CommercePromotions\Infrastructure\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \MP\CommercePromotions\Infrastructure\Deactivator::class, 'deactivate' ) );

/**
 * Loads plugin translations (no-op if the languages directory is empty or missing).
 */
function mp_commerce_promotions_load_textdomain(): void {
	load_plugin_textdomain(
		'mp-commerce-promotions',
		false,
		dirname( plugin_basename( MP_COMMERCE_PROMOTIONS_FILE ) ) . '/languages'
	);
}

/**
 * Bootstraps the plugin after all plugins are loaded.
 */
function mp_commerce_promotions_bootstrap(): void {
	if ( ! class_exists( \MP\CommercePromotions\Plugin::class, true ) ) {
		return;
	}

	try {
		$plugin = new \MP\CommercePromotions\Plugin();
		$plugin->init();
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[mp-commerce-promotions] Bootstrap failed: %s in %s:%d',
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);
		}
	}
}

add_action( 'plugins_loaded', 'mp_commerce_promotions_load_textdomain', 0 );
add_action( 'plugins_loaded', 'mp_commerce_promotions_bootstrap', 10 );
