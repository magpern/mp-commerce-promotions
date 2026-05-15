<?php
/**
 * Plugin Name:       MP Commerce Promotions
 * Description:       Lightweight commerce promotion engine foundation (conditions, actions, evaluation pipeline).
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            MP
 * Text Domain:       mp-commerce-promotions
 * Domain Path:       /languages
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MP_COMMERCE_PROMOTIONS_VERSION', '0.1.0' );
define( 'MP_COMMERCE_PROMOTIONS_FILE', __FILE__ );
define( 'MP_COMMERCE_PROMOTIONS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MP_COMMERCE_PROMOTIONS_URL', plugin_dir_url( __FILE__ ) );

require_once MP_COMMERCE_PROMOTIONS_PATH . 'src/autoload.php';

register_activation_hook( __FILE__, array( \MP\CommercePromotions\Infrastructure\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \MP\CommercePromotions\Infrastructure\Deactivator::class, 'deactivate' ) );

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

add_action( 'plugins_loaded', 'mp_commerce_promotions_bootstrap', 10 );
