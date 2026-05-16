<?php
/**
 * PHPUnit bootstrap: plugin autoloader without WordPress.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

$plugin_root = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $plugin_root );
}

if ( ! defined( 'MP_COMMERCE_PROMOTIONS_PATH' ) ) {
	define( 'MP_COMMERCE_PROMOTIONS_PATH', $plugin_root );
}

require $plugin_root . 'src/autoload.php';
