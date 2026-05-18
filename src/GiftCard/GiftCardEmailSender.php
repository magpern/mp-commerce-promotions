<?php
/**
 * Gift card email From/Reply-To resolution (SMTP-safe defaults).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;

final class GiftCardEmailSender {

	public const TRANSIENT_INVALID_CUSTOM_FALLBACK = 'mp_cp_gift_card_sender_invalid_fallback';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function configured_mode(): string {
		return $this->settings->gift_card_sender_mode();
	}

	/**
	 * Mode used when sending (custom only when configured and valid).
	 */
	public function effective_mode(): string {
		if ( $this->configured_mode() !== Settings::GIFT_CARD_SENDER_MODE_CUSTOM ) {
			return Settings::GIFT_CARD_SENDER_MODE_DEFAULT;
		}

		return $this->is_custom_sender_valid() ? Settings::GIFT_CARD_SENDER_MODE_CUSTOM : Settings::GIFT_CARD_SENDER_MODE_DEFAULT;
	}

	public function is_custom_sender_valid(): bool {
		$email = $this->settings->gift_card_sender_email();

		return $email !== '' && function_exists( 'is_email' ) && is_email( $email );
	}

	/**
	 * @return array{
	 *   mode: string,
	 *   headers: list<string>,
	 *   from_header_set: bool,
	 *   reply_to_set: bool,
	 *   used_custom_fallback: bool
	 * }
	 */
	public function resolve_for_send( string $site_name = '' ): array {
		$configured = $this->configured_mode();
		$mode       = $this->effective_mode();
		$headers    = array();
		$from_set   = false;
		$reply_set  = false;
		$fallback   = $configured === Settings::GIFT_CARD_SENDER_MODE_CUSTOM && $mode === Settings::GIFT_CARD_SENDER_MODE_DEFAULT;

		if ( $mode === Settings::GIFT_CARD_SENDER_MODE_CUSTOM ) {
			$from_email = $this->settings->gift_card_sender_email();
			$from_name  = $this->settings->gift_card_sender_name();
			if ( $from_name === '' ) {
				$from_name = $site_name !== '' ? $site_name : 'Store';
			}
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
			$from_set  = true;

			$reply_to = $this->settings->gift_card_reply_to_email();
			if ( $reply_to !== '' ) {
				$headers[] = 'Reply-To: ' . $reply_to;
				$reply_set = true;
			}
		}

		return array(
			'mode'                 => $mode,
			'headers'              => $headers,
			'from_header_set'      => $from_set,
			'reply_to_set'         => $reply_set,
			'used_custom_fallback' => $fallback,
		);
	}

	/**
	 * @return array{
	 *   sender_mode: string,
	 *   effective_sender_mode: string,
	 *   custom_sender_email: string,
	 *   custom_sender_valid: bool,
	 *   reply_to_email: string,
	 *   domain_mismatch: bool,
	 *   warnings: list<string>,
	 *   recommendation: string
	 * }
	 */
	public function analyze(): array {
		$configured = $this->configured_mode();
		$effective  = $this->effective_mode();
		$stored     = $this->settings->gift_card_sender_mode_stored();
		$email      = $this->settings->gift_card_sender_email();
		$valid      = $this->is_custom_sender_valid();
		$warnings   = array();

		if ( $stored === Settings::GIFT_CARD_SENDER_MODE_CUSTOM && ! $valid ) {
			$warnings[] = __( 'Custom sender email is invalid; delivery uses the default sender (WooCommerce / WP Mail SMTP / site mail).', 'mp-commerce-promotions' );
		}

		$domain_mismatch = false;
		if ( $valid && $email !== '' ) {
			$sender_domain = $this->email_domain( $email );
			$site_domain   = $this->site_email_domain();
			if ( $sender_domain !== '' && $site_domain !== '' && $sender_domain !== $site_domain ) {
				$domain_mismatch = true;
				$warnings[]      = sprintf(
					/* translators: 1: custom sender domain, 2: site/admin domain */
					__( 'Custom sender domain (%1$s) does not match the site/admin email domain (%2$s). Many SMTP providers reject unauthorized From addresses.', 'mp-commerce-promotions' ),
					$sender_domain,
					$site_domain
				);
			}
		}

		if ( function_exists( 'get_transient' ) && get_transient( GiftCardMailDiagnostics::TRANSIENT_SENDER_REJECTED_HINT ) ) {
			$warnings[] = __( 'A recent gift card email failure may be due to the sender address being rejected by your SMTP provider.', 'mp-commerce-promotions' );
		}

		$recommendation = __( 'Use default sender unless your SMTP provider authorizes the custom address.', 'mp-commerce-promotions' );

		return array(
			'sender_mode'            => $configured,
			'effective_sender_mode'  => $effective,
			'custom_sender_email'    => $email,
			'custom_sender_valid'    => $valid,
			'reply_to_email'         => $this->settings->gift_card_reply_to_email(),
			'domain_mismatch'        => $domain_mismatch,
			'warnings'               => $warnings,
			'recommendation'         => $recommendation,
		);
	}

	/**
	 * Call after settings save when custom email is invalid.
	 */
	public static function flag_invalid_custom_on_save(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::TRANSIENT_INVALID_CUSTOM_FALLBACK, '1', DAY_IN_SECONDS );
		}
	}

	public static function consume_invalid_custom_notice(): bool {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'delete_transient' ) ) {
			return false;
		}
		if ( ! get_transient( self::TRANSIENT_INVALID_CUSTOM_FALLBACK ) ) {
			return false;
		}
		delete_transient( self::TRANSIENT_INVALID_CUSTOM_FALLBACK );

		return true;
	}

	private function email_domain( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( $at === false ) {
			return '';
		}

		return strtolower( substr( $email, $at + 1 ) );
	}

	private function site_email_domain(): string {
		$candidates = array();
		if ( function_exists( 'get_option' ) ) {
			$admin = sanitize_email( (string) get_option( 'admin_email' ) );
			if ( $admin !== '' ) {
				$candidates[] = $admin;
			}
		}
		if ( function_exists( 'home_url' ) ) {
			$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			if ( is_string( $host ) && $host !== '' ) {
				$candidates[] = 'noreply@' . $host;
			}
		}
		foreach ( $candidates as $email ) {
			$domain = $this->email_domain( $email );
			if ( $domain !== '' ) {
				return $domain;
			}
		}

		return '';
	}
}
