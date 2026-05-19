<?php
/**
 * Gift card email delivery readiness signals (products + mail).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use wpdb;

final class GiftCardPilotReadiness {

	/**
	 * @return array{
	 *   gift_card_product_count: int,
	 *   has_gift_card_products: bool,
	 *   wp_mail_likely_failing: bool,
	 *   email_delivery_risk: bool
	 * }
	 */
	public static function analyze( wpdb $wpdb ): array {
		$count = GiftCardQaProductSetup::count_published_gift_card_products();
		$mail  = ( new GiftCardMailDiagnostics( $wpdb ) )->analyze();
		$fail  = ! empty( $mail['wp_mail_likely_failing'] );

		return array(
			'gift_card_product_count' => $count,
			'has_gift_card_products'  => $count > 0,
			'wp_mail_likely_failing'  => $fail,
			'email_delivery_risk'     => $count > 0 && $fail,
		);
	}

	public static function render_admin_email_delivery_warning( wpdb $wpdb ): void {
		$state = self::analyze( $wpdb );
		if ( empty( $state['email_delivery_risk'] ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'Gift card email delivery may fail. Configure SMTP and send a test gift card before selling gift cards.',
			'mp-commerce-promotions'
		);
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=mp-commerce-promotions&tab=diagnostics' ) ) . '">';
		echo esc_html__( 'View diagnostics', 'mp-commerce-promotions' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * @deprecated 0.3.0-pilot.3 Use render_admin_email_delivery_warning().
	 */
	public static function render_admin_pilot_email_warning( wpdb $wpdb ): void {
		self::render_admin_email_delivery_warning( $wpdb );
	}
}
