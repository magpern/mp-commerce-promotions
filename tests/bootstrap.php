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

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'MP_COMMERCE_PROMOTIONS_PATH' ) ) {
	define( 'MP_COMMERCE_PROMOTIONS_PATH', $plugin_root );
}

if ( ! defined( 'MP_COMMERCE_PROMOTIONS_FILE' ) ) {
	define( 'MP_COMMERCE_PROMOTIONS_FILE', $plugin_root . 'mp-commerce-promotions.php' );
}

require_once $plugin_root . 'src/php74-compat.php';

if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class, false ) ) {
	require_once $plugin_root . 'tests/Stubs/FeaturesUtilStub.php';
	class_alias(
		\MP\CommercePromotions\Tests\Stubs\FeaturesUtilStub::class,
		'Automattic\\WooCommerce\\Utilities\\FeaturesUtil'
	);
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

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param string   $hook
	 * @param callable $callback
	 * @param int      $priority
	 * @param int      $accepted_args
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * @param string   $hook
	 * @param callable $callback
	 * @param int      $priority
	 */
	function remove_action( $hook, $callback, $priority = 10 ) {
		unset( $hook, $callback, $priority );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook
	 * @param mixed  $value
	 * @param mixed  ...$args
	 * @return mixed
	 */
	function apply_filters( $hook, $value, ...$args ) {
		unset( $hook, $args );
		return $value;
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

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * @param string $email
	 * @return bool
	 */
	function is_email( $email ) {
		return filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) !== false;
	}
}

/** @var bool $mp_cp_test_wp_mail_result */
$GLOBALS['mp_cp_test_wp_mail_result'] = true;

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * @param string       $to
	 * @param string       $subject
	 * @param string       $message
	 * @param string|array $headers
	 * @return bool
	 */
	function wp_mail( $to, $subject, $message, $headers = '' ) {
		unset( $to, $subject, $headers );
		if ( ! isset( $GLOBALS['mp_cp_test_wp_mail_bodies'] ) || ! is_array( $GLOBALS['mp_cp_test_wp_mail_bodies'] ) ) {
			$GLOBALS['mp_cp_test_wp_mail_bodies'] = array();
		}
		$GLOBALS['mp_cp_test_wp_mail_bodies'][] = (string) $message;
		return (bool) ( $GLOBALS['mp_cp_test_wp_mail_result'] ?? true );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * @param string $text
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * @param string $url
	 * @return string
	 */
	function esc_url( $url ) {
		return esc_url_raw( $url );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return is_string( $url ) ? trim( $url ) : '';
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	/**
	 * @param string $text
	 * @return string
	 */
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * @param string $email
	 * @return string
	 */
	function sanitize_email( $email ) {
		$filtered = filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
		return is_string( $filtered ) ? $filtered : '';
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * @param string $path
	 */
	function admin_url( $path = '' ) {
		return 'https://example.org/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * @param array<string, scalar|null>|string $key
	 * @param scalar|null|string              $value
	 * @param string                          $url
	 */
	function add_query_arg( $key, $value = null, $url = null ) {
		if ( is_array( $key ) ) {
			$args = $key;
			$url  = is_string( $value ) ? $value : '';
		} else {
			$args = array( (string) $key => $value );
			$url  = is_string( $url ) ? $url : '';
		}

		$parts = array();
		foreach ( $args as $k => $v ) {
			if ( $v === null || $v === '' ) {
				continue;
			}
			$parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
		}

		if ( $parts === array() ) {
			return $url;
		}

		$sep = strpos( $url, '?' ) === false ? '?' : '&';

		return $url . $sep . implode( '&', $parts );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param string|array<mixed> $value
	 * @return string|array<mixed>
	 */
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}

		return stripslashes( (string) $value );
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
		if ( $type === 'Y-m-d' ) {
			return gmdate( 'Y-m-d' );
		}

		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	class WC_Order {
	}
}

if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
	class WC_Order_Item_Product {
	}
}

/** @var array<int, array<string, mixed>> $mp_cp_test_post_meta */
$GLOBALS['mp_cp_test_post_meta'] = array();

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $post_id
	 * @param string $key
	 * @param bool   $single
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		global $mp_cp_test_post_meta;
		$id = (int) $post_id;
		if ( ! isset( $mp_cp_test_post_meta[ $id ] ) ) {
			return $single ? '' : array();
		}
		if ( $key === '' ) {
			return $mp_cp_test_post_meta[ $id ];
		}
		$value = $mp_cp_test_post_meta[ $id ][ $key ] ?? '';
		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * @param int    $post_id
	 * @param string $key
	 * @param mixed  $value
	 * @return bool
	 */
	function update_post_meta( $post_id, $key, $value ) {
		global $mp_cp_test_post_meta;
		$id = (int) $post_id;
		if ( ! isset( $mp_cp_test_post_meta[ $id ] ) ) {
			$mp_cp_test_post_meta[ $id ] = array();
		}
		$mp_cp_test_post_meta[ $id ][ $key ] = $value;
		return true;
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

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * @return string
	 */
	function wp_generate_uuid4() {
		$seed = $GLOBALS['mp_cp_test_uuid'] ?? null;
		if ( is_string( $seed ) && $seed !== '' ) {
			return $seed;
		}

		return '00000000-0000-4000-8000-000000000001';
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

if ( ! class_exists( 'wpdb', false ) ) {
	/**
	 * Minimal wpdb stub for repository unit tests.
	 */
	class wpdb {
		/** @var string */
		public $prefix = 'wp_';

		/** @param string $query */
		public function prepare( $query, ...$args ) {
			return $query;
		}

		/** @param string $query */
		public function get_var( $query ) {
			unset( $query );
			return '0';
		}

		/** @return list<array<string, mixed>> */
		public function get_results( $query, $output = OBJECT ) {
			unset( $query, $output );
			return array();
		}
	}
}

require $plugin_root . 'src/autoload.php';
require $plugin_root . 'tests/Support/PromotionTestFixtures.php';
require $plugin_root . 'tests/Support/InMemoryGiftCardStore.php';
require $plugin_root . 'tests/Support/MemoryGiftCardRepository.php';
require $plugin_root . 'tests/Support/MemoryGiftCardTransactionRepository.php';
