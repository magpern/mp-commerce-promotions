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

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		if ( ! is_scalar( $str ) ) {
			return '';
		}
		$str = (string) $str;
		$str = wp_strip_all_tags( $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( $str );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $text
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}

require $plugin_root . 'src/autoload.php';
require $plugin_root . 'tests/Support/PromotionTestFixtures.php';
