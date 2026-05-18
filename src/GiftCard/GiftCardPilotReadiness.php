<?php
/**
 * Pilot readiness signals for gift cards (products + email).
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
	 *   pilot_email_risk: bool
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
			'pilot_email_risk'        => $count > 0 && $fail,
		);
	}

	public static function render_admin_pilot_email_warning( wpdb $wpdb ): void {
		$state = self::analyze( $wpdb );
		if ( empty( $state['pilot_email_risk'] ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Pilot warning:', 'mp-commerce-promotions' ) . '</strong> ';
		echo esc_html__(
			'Gift card products are active but email delivery may fail. Configure SMTP and send a test gift card before pilot sales.',
			'mp-commerce-promotions'
		);
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=mp-commerce-promotions&tab=diagnostics' ) ) . '">'
			. esc_html__( 'View diagnostics', 'mp-commerce-promotions' ) . '</a>';
		if ( defined( 'MP_COMMERCE_PROMOTIONS_PATH' ) && is_readable( MP_COMMERCE_PROMOTIONS_PATH . 'docs/GIFT_CARD_PILOT_CHECKLIST.md' ) ) {
			echo ' · <a href="' . esc_url( plugins_url( 'docs/GIFT_CARD_PILOT_CHECKLIST.md', MP_COMMERCE_PROMOTIONS_PATH . 'mp-commerce-promotions.php' ) ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'Pilot checklist', 'mp-commerce-promotions' ) . '</a>';
		}
		echo '</p></div>';
	}
}
