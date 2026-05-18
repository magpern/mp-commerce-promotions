<?php
/**
 * Optional WooCommerce email wrapper for gift card HTML.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardWooEmailStyler {

	/**
	 * Whether WooCommerce email styling (wrap_message / style_inline) is available.
	 */
	public static function is_available(): bool {
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Email' ) ) {
			return false;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->mailer ) ) {
			return false;
		}

		$mailer = $wc->mailer();

		return is_object( $mailer )
			&& method_exists( $mailer, 'wrap_message' )
			&& method_exists( $mailer, 'style_inline' );
	}

	/**
	 * Wrap inner HTML with WooCommerce email header/footer and inline styles.
	 * Falls back to the inner HTML unchanged when WooCommerce is unavailable.
	 */
	public static function apply( string $inner_html, string $email_heading ): string {
		if ( $inner_html === '' ) {
			return '';
		}

		if ( ! self::is_available() ) {
			return $inner_html;
		}

		$mailer = WC()->mailer();
		$wrapped = $mailer->wrap_message( $email_heading, $inner_html );
		$styled  = $mailer->style_inline( $wrapped );

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'woocommerce_mail_content', $styled );
			if ( is_string( $filtered ) && $filtered !== '' ) {
				return $filtered;
			}
		}

		return $styled;
	}

	/**
	 * WooCommerce base colors for gift card inner blocks when using Woo email style.
	 *
	 * @return array{base: string, background: string, body: string, text: string}
	 */
	public static function woo_colors(): array {
		$base       = '#96588a';
		$background = '#f7f7f7';
		$body       = '#ffffff';
		$text       = '#3c3c3c';

		if ( function_exists( 'get_option' ) ) {
			$base_opt = (string) get_option( 'woocommerce_email_base_color', '' );
			if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $base_opt ) ) ) {
				$base = trim( $base_opt );
			}
			$bg_opt = (string) get_option( 'woocommerce_email_background_color', '' );
			if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $bg_opt ) ) ) {
				$background = trim( $bg_opt );
			}
			$body_opt = (string) get_option( 'woocommerce_email_body_color', '' );
			if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $body_opt ) ) ) {
				$body = trim( $body_opt );
			}
			$text_opt = (string) get_option( 'woocommerce_email_text_color', '' );
			if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $text_opt ) ) ) {
				$text = trim( $text_opt );
			}
		}

		return array(
			'base'       => $base,
			'background' => $background,
			'body'       => $body,
			'text'       => $text,
		);
	}
}
