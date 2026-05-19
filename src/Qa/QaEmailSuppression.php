<?php
/**
 * Suppress outbound email during QA scripts (does not affect plugin runtime).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Qa;

final class QaEmailSuppression {

	/** @var list<string> */
	private static array $log = array();

	private static bool $registered = false;

	private static bool $active = false;

	public static function enable(): void {
		if ( self::$registered ) {
			self::$active = true;
			return;
		}

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'pre_wp_mail', array( self::class, 'pre_wp_mail' ), 9999, 2 );
		}
		self::$registered = true;
		self::$active     = true;
	}

	public static function disable(): void {
		self::$active = false;
	}

	/**
	 * @param mixed $null
	 * @param mixed $atts
	 * @return bool|null
	 */
	public static function pre_wp_mail( $null, $atts ) {
		unset( $null );
		if ( ! self::$active ) {
			return null;
		}

		$to = '';
		if ( is_array( $atts ) && isset( $atts['to'] ) ) {
			$to = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
		}

		$subject = is_array( $atts ) && isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
		self::$log[] = sprintf( 'suppressed wp_mail to=%s subject=%s', $to, $subject );

		return true;
	}

	/**
	 * @return list<string>
	 */
	public static function get_log(): array {
		return self::$log;
	}

	public static function reset_log(): void {
		self::$log = array();
	}

	public static function suppressed_count(): int {
		return count( self::$log );
	}

	private function __construct() {
	}
}
