<?php
/**
 * WooCommerce admin: Commerce Promotion Settings.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Service\Settings;

final class SettingsPage {

	public const PAGE_SLUG = 'mp-commerce-promotions-settings';

	private const NONCE_ACTION = 'mp_cp_save_settings';

	private const NONCE_FIELD = 'mp_cp_settings_nonce';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->handle_post_save();

		$enabled = $this->settings->cart_discounts_enabled();

		echo '<div class="wrap">';
		$this->render_notices();
		echo '<h1>' . esc_html__( 'Commerce Promotion Settings', 'mp-commerce-promotions' ) . '</h1>';

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Cart discounts', 'mp-commerce-promotions' ) . '</th>';
		echo '<td>';
		echo '<label for="mp_cp_cart_discounts_enabled">';
		echo '<input type="checkbox" id="mp_cp_cart_discounts_enabled" name="mp_cp_cart_discounts_enabled" value="yes"';
		if ( $enabled ) {
			echo ' checked="checked"';
		}
		echo ' /> ';
		echo esc_html__( 'Enable cart discounts', 'mp-commerce-promotions' );
		echo '</label>';
		echo '<p class="description">';
		echo esc_html__(
			'When disabled, commerce promotions are not applied as negative cart fees and the applied-promotion session is cleared.',
			'mp-commerce-promotions'
		);
		echo '</p>';
		echo '</td>';
		echo '</tr>';
		echo '</tbody></table>';

		echo '<p class="submit">';
		echo '<button type="submit" name="mp_cp_save_settings_submit" value="1" class="button button-primary">';
		echo esc_html__( 'Save settings', 'mp-commerce-promotions' );
		echo '</button>';
		echo '</p>';
		echo '</form>';
		echo '</div>';
	}

	private function handle_post_save(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_save_settings_submit'] ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			$this->redirect_with_notice( 'error', 'missing_nonce' );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->redirect_with_notice( 'error', 'invalid_nonce' );
		}

		$enabled = isset( $_POST['mp_cp_cart_discounts_enabled'] )
			&& sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_cart_discounts_enabled'] ) ) === 'yes';

		$this->settings->set_cart_discounts_enabled( $enabled );
		$this->redirect_with_notice( 'success', 'saved' );
	}

	private function render_notices(): void {
		if ( ! isset( $_GET['mp_cp_settings_notice'] ) || ! isset( $_GET['mp_cp_settings_code'] ) ) {
			return;
		}

		$type = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_settings_notice'] ) );
		$code = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_settings_code'] ) );

		$message = $this->notice_message_for_code( $code );
		if ( $message === '' ) {
			return;
		}

		$class = $type === 'success' ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function notice_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'saved':
				return __( 'Settings saved.', 'mp-commerce-promotions' );
			case 'missing_nonce':
			case 'invalid_nonce':
				return __( 'Security check failed. Please try again.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function redirect_with_notice( string $type, string $code ): void {
		$url = add_query_arg(
			array(
				'page'                  => self::PAGE_SLUG,
				'mp_cp_settings_notice' => $type,
				'mp_cp_settings_code'   => $code,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
