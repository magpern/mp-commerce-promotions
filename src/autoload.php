<?php
/**
 * PSR-4 style autoloader for MP\CommercePromotions\* → src/
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'MP_COMMERCE_PROMOTIONS_PATH' ) ) {
	return;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix     = 'MP\\CommercePromotions\\';
		$prefix_len = strlen( $prefix );

		if ( strncmp( $prefix, $class, $prefix_len ) !== 0 ) {
			return;
		}

		$relative = substr( $class, $prefix_len );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$file     = MP_COMMERCE_PROMOTIONS_PATH . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	},
	true,
	true
);
