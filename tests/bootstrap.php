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

if ( ! function_exists( 'selected' ) ) {
	/**
	 * @param mixed $selected
	 * @param mixed $current
	 * @param bool  $echo
	 * @return string
	 */
	function selected( $selected, $current, $echo = true ) {
		$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'checked' ) ) {
	/**
	 * @param mixed $checked
	 * @param mixed $current
	 * @param bool  $echo
	 * @return string
	 */
	function checked( $checked, $current, $echo = true ) {
		$result = ( (bool) $checked === (bool) $current ) ? ' checked="checked"' : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * @param string $str
	 * @return string
	 */
	function sanitize_textarea_field( $str ) {
		if ( ! is_scalar( $str ) ) {
			return '';
		}
		$str = (string) $str;
		$str = wp_strip_all_tags( $str );
		return trim( $str );
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

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! isset( $GLOBALS['mp_cp_test_options'] ) || ! is_array( $GLOBALS['mp_cp_test_options'] ) ) {
	$GLOBALS['mp_cp_test_options'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param mixed $default
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return $GLOBALS['mp_cp_test_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param mixed $value
	 */
	function update_option( $option, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['mp_cp_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		unset( $GLOBALS['mp_cp_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type
	 */
	function current_time( $type ) {
		if ( $type === 'timestamp' ) {
			return time();
		}

		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	class WC_Order {
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $order_id
	 * @return object|null
	 */
	function wc_get_order( $order_id ) {
		global $mp_cp_test_orders;
		if ( ! is_array( $mp_cp_test_orders ) ) {
			return null;
		}

		return $mp_cp_test_orders[ (int) $order_id ] ?? null;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/** @var array<string, mixed> $mp_cp_test_transients */
	global $mp_cp_test_transients;
	$mp_cp_test_transients = array();

	/**
	 * @param string $transient
	 * @return mixed
	 */
	function get_transient( $transient ) {
		global $mp_cp_test_transients;
		return $mp_cp_test_transients[ (string) $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $transient
	 * @param mixed  $value
	 * @param int    $expiration
	 * @return bool
	 */
	function set_transient( $transient, $value, $expiration = 0 ) {
		unset( $expiration );
		global $mp_cp_test_transients;
		$mp_cp_test_transients[ (string) $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * @param string $transient
	 * @return bool
	 */
	function delete_transient( $transient ) {
		global $mp_cp_test_transients;
		unset( $mp_cp_test_transients[ (string) $transient ] );
		return true;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * @param int  $length
	 * @param bool $special_chars
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special_chars = true ) {
		unset( $special_chars );
		return str_repeat( 'x', max( 1, (int) $length ) );
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache(): bool {
		return false;
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
