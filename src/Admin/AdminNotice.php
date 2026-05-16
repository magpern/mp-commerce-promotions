<?php
/**
 * Reusable WordPress admin notice rendering.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class AdminNotice {

	public const TYPE_SUCCESS = 'success';

	public const TYPE_WARNING = 'warning';

	public const TYPE_ERROR = 'error';

	public const TYPE_INFO = 'info';

	/**
	 * Render an admin notice.
	 *
	 * @param string               $type    Notice type (success, warning, error, info).
	 * @param string               $message Notice message.
	 * @param array<string, mixed> $args    Optional: dismissible, inline, class.
	 */
	public static function render( string $type, string $message, array $args = array() ): void {
		if ( $message === '' ) {
			return;
		}

		$dismissible = $args['dismissible'] ?? true;
		$inline      = $args['inline'] ?? false;
		$extra_class = isset( $args['class'] ) ? (string) $args['class'] : '';

		$classes = array( 'notice', self::notice_class_for_type( $type ) );
		if ( $dismissible ) {
			$classes[] = 'is-dismissible';
		}
		if ( $inline ) {
			$classes[] = 'inline';
		}
		if ( $extra_class !== '' ) {
			$classes[] = $extra_class;
		}

		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_html( $message )
		);
	}

	/**
	 * @param string               $message Notice message.
	 * @param array<string, mixed> $args    Optional notice arguments.
	 */
	public static function success( string $message, array $args = array() ): void {
		self::render( self::TYPE_SUCCESS, $message, $args );
	}

	/**
	 * @param string               $message Notice message.
	 * @param array<string, mixed> $args    Optional notice arguments.
	 */
	public static function warning( string $message, array $args = array() ): void {
		self::render( self::TYPE_WARNING, $message, $args );
	}

	/**
	 * @param string               $message Notice message.
	 * @param array<string, mixed> $args    Optional notice arguments.
	 */
	public static function error( string $message, array $args = array() ): void {
		self::render( self::TYPE_ERROR, $message, $args );
	}

	/**
	 * @param string               $message Notice message.
	 * @param array<string, mixed> $args    Optional notice arguments.
	 */
	public static function info( string $message, array $args = array() ): void {
		self::render( self::TYPE_INFO, $message, $args );
	}

	private static function notice_class_for_type( string $type ): string {
		switch ( $type ) {
			case self::TYPE_SUCCESS:
				return 'notice-success';
			case self::TYPE_WARNING:
				return 'notice-warning';
			case self::TYPE_INFO:
				return 'notice-info';
			case self::TYPE_ERROR:
			default:
				return 'notice-error';
		}
	}
}
