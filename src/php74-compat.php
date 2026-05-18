<?php
/**
 * PHP 7.4 compatibility shims (composer requires php >=7.4).
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
