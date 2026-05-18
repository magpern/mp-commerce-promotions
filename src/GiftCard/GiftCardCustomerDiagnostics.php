<?php
/**
 * Diagnostics for customer-facing gift card UX.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\GiftCardMyAccount;
use wpdb;

final class GiftCardCustomerDiagnostics {

	private wpdb $wpdb;

	private Settings $settings;

	public function __construct( wpdb $wpdb, ?Settings $settings = null ) {
		$this->wpdb     = $wpdb;
		$this->settings = $settings ?? new Settings();
	}

	/**
	 * @return array{
	 *   missing_balance_page: bool,
	 *   balance_checker_disabled: bool,
	 *   my_account_disabled: bool,
	 *   cron_disabled_with_pending: bool,
	 *   invalid_template: bool,
	 *   invalid_accent: bool,
	 *   invalid_sender_email: bool
	 * }
	 */
	public function analyze(): array {
		$page_id = $this->settings->gift_card_balance_page_id();
		$missing_page = $page_id <= 0 || ! function_exists( 'get_post_status' ) || ! get_post_status( $page_id );

		$pending = 0;
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_query' => array(
						array(
							'key'     => GiftCardPendingDeliveryState::META_PENDING,
							'compare' => 'EXISTS',
						),
					),
				)
			);
			$pending = count( $orders ) > 0 ? 1 : 0;
		}

		$template = $this->settings->gift_card_email_template();
		$invalid_template = ! in_array( $template, Settings::gift_card_email_templates(), true );

		$accent = get_option( Settings::OPTION_GIFT_CARD_ACCENT_COLOR, '' );
		$invalid_accent = is_string( $accent ) && $accent !== '' && ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $accent ) );

		$sender = $this->settings->gift_card_sender_email();
		$invalid_sender = $sender !== '' && ! is_email( $sender );

		return array(
			'missing_balance_page'        => $missing_page,
			'balance_checker_disabled'    => ! $this->settings->gift_card_balance_checker_enabled(),
			'my_account_disabled'         => ! $this->settings->gift_card_my_account_enabled(),
			'cron_disabled_with_pending'  => ! $this->settings->gift_card_scheduled_cron_enabled() && $pending > 0,
			'invalid_template'            => $invalid_template,
			'invalid_accent'              => $invalid_accent,
			'invalid_sender_email'        => $invalid_sender,
		);
	}

	public function repair( bool $apply ): array {
		$result = array( 'page_created' => false );
		if ( ! $apply ) {
			return $result;
		}

		if ( class_exists( \MP\CommercePromotions\Infrastructure\GiftCardPageInstaller::class ) ) {
			\MP\CommercePromotions\Infrastructure\GiftCardPageInstaller::maybe_create_balance_page( $this->settings );
			$result['page_created'] = $this->settings->gift_card_balance_page_id() > 0;
		}

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}

		return $result;
	}
}
