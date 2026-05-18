<?php
/**
 * Shortcode [mp_cp_gift_card_balance] for public balance lookup.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCardBalanceChecker;
use MP\CommercePromotions\Service\Settings;

final class GiftCardBalanceCheckerShortcode {

	private GiftCardBalanceChecker $checker;

	private Settings $settings;

	public function __construct( GiftCardBalanceChecker $checker, Settings $settings ) {
		$this->checker  = $checker;
		$this->settings = $settings;
	}

	public function register(): void {
		add_shortcode( 'mp_cp_gift_card_balance', array( $this, 'render_shortcode' ) );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function render_shortcode( $atts = array() ): string {
		unset( $atts );
		if ( ! $this->settings->gift_card_balance_checker_enabled() ) {
			return '<p class="mp-cp-gc-notice">' . esc_html__( 'Gift card balance lookup is currently unavailable.', 'mp-commerce-promotions' ) . '</p>';
		}

		GiftCardCustomerAssets::enqueue();

		$result = null;
		if (
			isset( $_POST['mp_cp_balance_check'] )
			&& isset( $_POST['mp_cp_balance_nonce'] )
			&& wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_balance_nonce'] ) ),
				'mp_cp_balance_check'
			)
		) {
			$code   = isset( $_POST['mp_cp_balance_code'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_balance_code'] ) )
				: '';
			$result = $this->checker->lookup( $code );
		}

		ob_start();
		echo '<div class="mp-cp-gift-card-balance-checker">';
		echo '<h2 class="mp-cp-gc-title">' . esc_html__( 'Check gift card balance', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="mp-cp-gc-help">' . esc_html__( 'Enter your gift card code to see the current balance. We never display your full code after this check.', 'mp-commerce-promotions' ) . '</p>';

		if ( is_array( $result ) ) {
			if ( ! empty( $result['ok'] ) ) {
				echo '<div class="mp-cp-gc-result mp-cp-gc-result--ok">';
				echo '<p><strong>' . esc_html__( 'Card', 'mp-commerce-promotions' ) . ':</strong> ' . esc_html( (string) ( $result['masked_code'] ?? '' ) ) . '</p>';
				$balance_display = function_exists( 'wc_price' )
					? wp_strip_all_tags( wc_price( (float) ( $result['balance'] ?? 0 ), array( 'currency' => (string) ( $result['currency'] ?? '' ) ) ) )
					: number_format( (float) ( $result['balance'] ?? 0 ), 2 );
				echo '<p><strong>' . esc_html__( 'Balance', 'mp-commerce-promotions' ) . ':</strong> ' . esc_html( $balance_display ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Status', 'mp-commerce-promotions' ) . ':</strong> ' . esc_html( (string) ( $result['status'] ?? '' ) ) . '</p>';
				if ( ! empty( $result['expires_at'] ) ) {
					echo '<p><strong>' . esc_html__( 'Expires', 'mp-commerce-promotions' ) . ':</strong> ' . esc_html( (string) $result['expires_at'] ) . '</p>';
				}
				echo '</div>';
			} else {
				echo '<div class="mp-cp-gc-result mp-cp-gc-result--error"><p>' . esc_html( (string) ( $result['error'] ?? '' ) ) . '</p></div>';
			}
		}

		echo '<form method="post" class="mp-cp-gc-balance-form">';
		wp_nonce_field( 'mp_cp_balance_check', 'mp_cp_balance_nonce' );
		echo '<input type="hidden" name="mp_cp_balance_check" value="1" />';
		echo '<p><label for="mp_cp_balance_code">' . esc_html__( 'Gift card code', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="text" name="mp_cp_balance_code" id="mp_cp_balance_code" class="input-text" autocomplete="off" required /></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Check balance', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form></div>';

		return (string) ob_get_clean();
	}
}
