<?php
/**
 * Gift card email deliverability diagnostics (no secrets).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;
use wpdb;

final class GiftCardMailDiagnostics {

	public const TRANSIENT_LAST_MAIL_FAILURE = 'mp_cp_gift_card_mail_last_failed';

	private wpdb $wpdb;

	private Settings $settings;

	public function __construct( wpdb $wpdb, ?Settings $settings = null ) {
		$this->wpdb     = $wpdb;
		$this->settings = $settings ?? new Settings();
	}

	/**
	 * @return array{
	 *   delivery_email_enabled: bool,
	 *   recent_delivery_failed: int,
	 *   wp_mail_likely_failing: bool,
	 *   last_mail_failure_at: ?string,
	 *   smtp_plugin_hint: string,
	 *   settings_summary: array<string, string|bool>
	 * }
	 */
	public function analyze(): array {
		$delivery_diag = new GiftCardDeliveryDiagnostics( $this->wpdb );
		$issues        = $delivery_diag->analyze();
		$failed_count  = count( $issues['delivery_failed'] );

		$last_fail = function_exists( 'get_transient' )
			? get_transient( self::TRANSIENT_LAST_MAIL_FAILURE )
			: false;
		$last_fail_at = is_numeric( $last_fail ) ? gmdate( 'Y-m-d H:i:s', (int) $last_fail ) : null;

		$smtp_hint = $this->detect_smtp_plugin_hint();

		$likely_failing = $failed_count > 0
			|| ( $last_fail_at !== null && $this->settings->gift_card_delivery_email_enabled() );

		return array(
			'delivery_email_enabled'  => $this->settings->gift_card_delivery_email_enabled(),
			'recent_delivery_failed'  => $failed_count,
			'wp_mail_likely_failing'  => $likely_failing,
			'last_mail_failure_at'    => $last_fail_at,
			'smtp_plugin_hint'        => $smtp_hint,
			'settings_summary'        => $this->settings_summary(),
		);
	}

	/**
	 * Record that wp_mail failed for gift card delivery (no code logged).
	 */
	public static function record_mail_failure(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::TRANSIENT_LAST_MAIL_FAILURE, time(), DAY_IN_SECONDS );
		}
	}

	/**
	 * @return array<string, string|bool>
	 */
	public function settings_summary(): array {
		return array(
			'delivery_email_enabled' => $this->settings->gift_card_delivery_email_enabled(),
			'email_template'         => $this->settings->gift_card_email_template(),
			'has_logo_url'           => $this->settings->gift_card_logo_url() !== '',
			'accent_color'           => $this->settings->gift_card_accent_color(),
			'has_custom_sender'      => $this->settings->gift_card_sender_email() !== '',
			'sender_name_set'        => $this->settings->gift_card_sender_name() !== '',
			'has_support_text'       => $this->settings->gift_card_support_email_text() !== '',
		);
	}

	private function detect_smtp_plugin_hint(): string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			return '';
		}

		$candidates = array(
			'wp-mail-smtp/wp_mail_smtp.php',
			'easy-wp-smtp/easy-wp-smtp.php',
			'post-smtp/postman-smtp.php',
			'smtp-mailer/main.php',
		);

		foreach ( $candidates as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				return $plugin;
			}
		}

		return '';
	}
}
