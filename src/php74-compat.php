<?php
/**
 * Optional polyfills for older PHP (minimum supported version is 8.1).
 *
 * @package MP\CommercePromotions
 */

if ( ! function_exists( 'str_contains' ) ) {
	/**
	 * @param string $haystack
	 * @param string $needle
	 */
	function str_contains( $haystack, $needle ): bool {
		if ( $needle === '' ) {
			return true;
		}

		return strpos( $haystack, $needle ) !== false;
	}
}
