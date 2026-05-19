<?php
/**
 * WooCommerce admin: Promotions module settings.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\GiftCard\GiftCardEmailSender;
use MP\CommercePromotions\Service\Settings;

final class SettingsPage {

	private const NONCE_ACTION = 'mp_cp_save_settings';

	private const NONCE_FIELD = 'mp_cp_settings_nonce';

	private Settings $settings;

	private GiftCardSettingsHandler $gift_card_settings;

	public function __construct( Settings $settings ) {
		$this->settings           = $settings;
		$this->gift_card_settings = new GiftCardSettingsHandler( $settings );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->handle_post_save();

		echo '<div class="wrap">';
		$this->render_notices();
		echo '<h1>' . esc_html__( 'Settings', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_SETTINGS );

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<h2 class="title">' . esc_html__( 'Storefront', 'mp-commerce-promotions' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->checkbox_row(
			'mp_cp_cart_discounts_enabled',
			__( 'Enable cart discounts', 'mp-commerce-promotions' ),
			__( 'When disabled, promotions are not applied as cart fees and the applied-promotion session is cleared.', 'mp-commerce-promotions' ),
			$this->settings->cart_discounts_enabled()
		);
		$this->checkbox_row(
			'mp_cp_free_gift_enabled',
			__( 'Enable free gift actions', 'mp-commerce-promotions' ),
			__( 'When disabled, free_gift_product actions do not add cart lines (validator warns).', 'mp-commerce-promotions' ),
			$this->settings->free_gift_enabled()
		);
		$this->checkbox_row(
			'mp_cp_free_shipping_enabled',
			__( 'Enable free shipping action', 'mp-commerce-promotions' ),
			__( 'When disabled, free_shipping actions do not apply shipping fee offsets.', 'mp-commerce-promotions' ),
			$this->settings->free_shipping_enabled()
		);
		echo '</tbody></table>';

		echo '<h2 class="title">' . esc_html__( 'Admin & reporting', 'mp-commerce-promotions' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->checkbox_row(
			'mp_cp_planner_telemetry_enabled',
			__( 'Enable planner telemetry', 'mp-commerce-promotions' ),
			__( 'Records aggregate planner outcomes (no PII). Disable to stop telemetry writes.', 'mp-commerce-promotions' ),
			$this->settings->planner_telemetry_enabled()
		);
		$this->checkbox_row(
			'mp_cp_csv_export_enabled',
			__( 'Enable redemption CSV export', 'mp-commerce-promotions' ),
			__( 'When disabled, the Reports export form is hidden.', 'mp-commerce-promotions' ),
			$this->settings->csv_export_enabled()
		);
		$this->checkbox_row(
			'mp_cp_simulations_enabled',
			__( 'Enable simulation features', 'mp-commerce-promotions' ),
			__( 'When disabled, Reports simulation section is hidden.', 'mp-commerce-promotions' ),
			$this->settings->simulations_enabled()
		);
		$this->checkbox_row(
			'mp_cp_pricing_explainability_enabled',
			__( 'Enable advanced pricing explainability', 'mp-commerce-promotions' ),
			__( 'When disabled, allocation tables and summaries are hidden in cart preview.', 'mp-commerce-promotions' ),
			$this->settings->pricing_explainability_enabled()
		);
		echo '</tbody></table>';

		$this->render_gift_card_settings_moved_notice();

		echo '<h2 class="title">' . esc_html__( 'Automation', 'mp-commerce-promotions' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->checkbox_row(
			'mp_cp_automation_manual_only',
			__( 'Automation runner: manual only', 'mp-commerce-promotions' ),
			__( 'When enabled, WP-Cron hourly automation is skipped (manual Diagnostics runs still work).', 'mp-commerce-promotions' ),
			$this->settings->automation_manual_only()
		);
		$this->checkbox_row(
			'mp_cp_cron_automation_enabled',
			__( 'Enable WP-Cron automation', 'mp-commerce-promotions' ),
			__( 'Schedules hourly maintenance and daily cleanup hooks. Disabled by default.', 'mp-commerce-promotions' ),
			$this->settings->cron_automation_enabled()
		);
		$this->checkbox_row(
			'mp_cp_automation_emergency_stop',
			__( 'Automation emergency stop', 'mp-commerce-promotions' ),
			__( 'Blocks all automation runs until disabled.', 'mp-commerce-promotions' ),
			$this->settings->automation_emergency_stop()
		);
		echo '</tbody></table>';

		echo '<h2 class="title">' . esc_html__( 'Production safety', 'mp-commerce-promotions' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->checkbox_row(
			'mp_cp_safe_mode',
			__( 'Safe mode (disable automatic promotions)', 'mp-commerce-promotions' ),
			__( 'Skips automatic cart promotions. Promotion codes may still apply when allowed below.', 'mp-commerce-promotions' ),
			$this->settings->safe_mode_enabled()
		);
		$this->checkbox_row(
			'mp_cp_allow_codes_in_safe_mode',
			__( 'Allow promotion codes in safe mode', 'mp-commerce-promotions' ),
			__( 'When safe mode is on, coupon-field promotion codes can still apply.', 'mp-commerce-promotions' ),
			$this->settings->allow_codes_in_safe_mode()
		);
		$this->checkbox_row(
			'mp_cp_telemetry_paused',
			__( 'Pause planner telemetry writes', 'mp-commerce-promotions' ),
			__( 'Stops aggregate telemetry persistence while leaving other features enabled.', 'mp-commerce-promotions' ),
			$this->settings->telemetry_paused()
		);
		$this->checkbox_row(
			'mp_cp_simulation_paused',
			__( 'Pause simulations', 'mp-commerce-promotions' ),
			__( 'Blocks simulation runs until disabled.', 'mp-commerce-promotions' ),
			$this->settings->simulation_paused()
		);
		$this->checkbox_row(
			'mp_cp_promotion_dry_run',
			__( 'Promotion dry-run (global)', 'mp-commerce-promotions' ),
			__( 'Evaluates promotions but does not apply fees, gifts, or line mutations.', 'mp-commerce-promotions' ),
			$this->settings->promotion_dry_run_enabled()
		);
		echo '<tr><th scope="row"><label for="mp_cp_telemetry_retention_days">';
		echo esc_html__( 'Telemetry retention (days)', 'mp-commerce-promotions' );
		echo '</label></th><td>';
		printf(
			'<input type="number" min="7" max="3650" id="mp_cp_telemetry_retention_days" name="mp_cp_telemetry_retention_days" value="%d" class="small-text" />',
			(int) $this->settings->telemetry_retention_days()
		);
		echo '<p class="description">' . esc_html__( 'Used by daily cleanup for automation runs and archived scenarios.', 'mp-commerce-promotions' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<h2 class="title">' . esc_html__( 'Data retention', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'By default, uninstalling the plugin retains promotions, codes, redemptions, and settings. Full deletion is opt-in and irreversible.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->checkbox_row(
			'mp_cp_retain_data_on_uninstall',
			__( 'Retain data on uninstall', 'mp-commerce-promotions' ),
			__( 'Recommended. Keeps custom tables and mp_cp_* options when the plugin is removed.', 'mp-commerce-promotions' ),
			$this->settings->retain_data_on_uninstall()
		);
		$this->checkbox_row(
			'mp_cp_delete_data_on_uninstall',
			__( 'Delete all plugin data on uninstall', 'mp-commerce-promotions' ),
			__( 'Dangerous: drops custom tables and deletes all mp_cp_* options when uninstall runs. Requires explicit opt-in.', 'mp-commerce-promotions' ),
			$this->settings->delete_data_on_uninstall()
		);
		echo '</tbody></table>';

		echo '<p class="submit">';
		echo '<button type="submit" name="mp_cp_save_settings_submit" value="1" class="button button-primary">';
		echo esc_html__( 'Save settings', 'mp-commerce-promotions' );
		echo '</button>';
		echo '</p>';
		echo '</form>';
		echo '</div>';
	}

	private function checkbox_row( string $name, string $label, string $description, bool $checked ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label for="' . esc_attr( $name ) . '">';
		printf(
			'<input type="checkbox" id="%1$s" name="%1$s" value="yes"%2$s /> %3$s',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
		echo '</label>';
		if ( $description !== '' ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
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

		$this->settings->set_cart_discounts_enabled( $this->post_yes( 'mp_cp_cart_discounts_enabled' ) );
		$this->settings->set_free_gift_enabled( $this->post_yes( 'mp_cp_free_gift_enabled' ) );
		$this->settings->set_free_shipping_enabled( $this->post_yes( 'mp_cp_free_shipping_enabled' ) );
		$this->settings->set_planner_telemetry_enabled( $this->post_yes( 'mp_cp_planner_telemetry_enabled' ) );
		$this->settings->set_csv_export_enabled( $this->post_yes( 'mp_cp_csv_export_enabled' ) );
		$this->settings->set_simulations_enabled( $this->post_yes( 'mp_cp_simulations_enabled' ) );
		$this->settings->set_pricing_explainability_enabled( $this->post_yes( 'mp_cp_pricing_explainability_enabled' ) );

		$notice_code = 'saved';
		if ( GiftCardSettingsHandler::post_includes_gift_card_fields() ) {
			$gc_warning = $this->gift_card_settings->save_gift_card_options_from_post();
			if ( $gc_warning === 'sender_invalid_fallback' ) {
				$this->redirect_with_notice( 'warning', 'sender_invalid_fallback' );
			}
			$notice_code = 'saved_with_gift_card_moved';
		}

		$this->settings->set_automation_manual_only( $this->post_yes( 'mp_cp_automation_manual_only' ) );
		$this->settings->set_cron_automation_enabled( $this->post_yes( 'mp_cp_cron_automation_enabled' ) );
		$this->settings->set_automation_emergency_stop( $this->post_yes( 'mp_cp_automation_emergency_stop' ) );
		$this->settings->set_safe_mode_enabled( $this->post_yes( 'mp_cp_safe_mode' ) );
		$this->settings->set_allow_codes_in_safe_mode( $this->post_yes( 'mp_cp_allow_codes_in_safe_mode' ) );
		$this->settings->set_telemetry_paused( $this->post_yes( 'mp_cp_telemetry_paused' ) );
		$this->settings->set_simulation_paused( $this->post_yes( 'mp_cp_simulation_paused' ) );
		$this->settings->set_promotion_dry_run_enabled( $this->post_yes( 'mp_cp_promotion_dry_run' ) );
		if ( isset( $_POST['mp_cp_telemetry_retention_days'] ) ) {
			$this->settings->set_telemetry_retention_days(
				(int) sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_telemetry_retention_days'] ) )
			);
		}
		$this->settings->set_retain_data_on_uninstall( $this->post_yes( 'mp_cp_retain_data_on_uninstall' ) );
		$this->settings->set_delete_data_on_uninstall( $this->post_yes( 'mp_cp_delete_data_on_uninstall' ) );

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			\MP\CommercePromotions\Service\PromotionCronScheduler::clear_scheduled_events();
			if ( $this->settings->cron_automation_enabled() ) {
				( new \MP\CommercePromotions\Service\PromotionCronScheduler( $this->settings ) )->reschedule();
			}
		}

		$this->redirect_with_notice( 'success', $notice_code );
	}

	private function render_gift_card_settings_moved_notice(): void {
		$url = GiftCardModuleSections::section_url( GiftCardModuleSections::SECTION_SETTINGS );
		echo '<div class="notice notice-info inline" style="margin:1em 0;padding:12px 16px;max-width:720px;">';
		echo '<p style="margin:0;">' . esc_html__(
			'Gift card and store credit settings have moved.',
			'mp-commerce-promotions'
		);
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__(
			'Open Gift Cards & Store Credit → Settings',
			'mp-commerce-promotions'
		) . '</a></p>';
		echo '</div>';
	}

	private function post_yes( string $field ): bool {
		return isset( $_POST[ $field ] )
			&& sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ) === 'yes';
	}

	private function render_notices(): void {
		if ( GiftCardEmailSender::consume_invalid_custom_notice() ) {
			AdminNotice::warning(
				__( 'Custom sender email was invalid. Gift card emails will use the default sender until you save a valid custom address.', 'mp-commerce-promotions' )
			);
		}

		if ( ! isset( $_GET['mp_cp_settings_notice'] ) || ! isset( $_GET['mp_cp_settings_code'] ) ) {
			return;
		}

		$type = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_settings_notice'] ) );
		$code = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_settings_code'] ) );

		$message = $this->notice_message_for_code( $code );
		if ( $message === '' ) {
			return;
		}

		if ( $type === 'success' ) {
			AdminNotice::success( $message );
			return;
		}

		if ( $type === 'warning' ) {
			AdminNotice::warning( $message );
			return;
		}

		AdminNotice::error( $message );
	}

	private function notice_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'saved':
				return __( 'Settings saved.', 'mp-commerce-promotions' );
			case 'saved_with_gift_card_moved':
				return __( 'Settings saved. Gift card options were updated — configure gift cards under Gift Cards & Store Credit → Settings.', 'mp-commerce-promotions' );
			case 'missing_nonce':
			case 'invalid_nonce':
				return __( 'Security check failed. Please try again.', 'mp-commerce-promotions' );
			case 'sender_invalid_fallback':
				return __( 'Custom sender email was invalid. Saved with default sender mode.', 'mp-commerce-promotions' );
			case 'test_email_sent':
				return __( 'Test gift card email sent (sample code ****TEST only).', 'mp-commerce-promotions' );
			case 'test_email_failed':
				return __( 'Test gift card email could not be sent. Check SMTP and sender settings.', 'mp-commerce-promotions' );
			case 'test_email_invalid':
				return __( 'Enter a valid email address for the test message.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function redirect_with_notice( string $type, string $code ): void {
		wp_safe_redirect(
			AdminUrl::settings(
				array(
					'mp_cp_settings_notice' => $type,
					'mp_cp_settings_code'   => $code,
				)
			)
		);
		exit;
	}
}
