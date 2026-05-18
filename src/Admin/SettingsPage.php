<?php
/**
 * WooCommerce admin: Promotions module settings.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\GiftCard\GiftCardDeliveryMailer;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardEmailPreview;
use MP\CommercePromotions\GiftCard\GiftCardEmailSender;
use MP\CommercePromotions\GiftCard\GiftCardManualIssueDelivery;
use MP\CommercePromotions\GiftCard\GiftCardWooEmailStyler;
use MP\CommercePromotions\Service\Settings;

final class SettingsPage {

	private const NONCE_ACTION = 'mp_cp_save_settings';

	private const NONCE_FIELD = 'mp_cp_settings_nonce';

	private const TEST_EMAIL_NONCE_ACTION = 'mp_cp_gift_card_settings_test_email';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->handle_post_save();
		$this->handle_settings_test_email();

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

		echo '<h2 class="title">' . esc_html__( 'Gift cards & store credit', 'mp-commerce-promotions' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->checkbox_row(
			'mp_cp_gift_card_delivery_email_enabled',
			__( 'Send gift card codes by email when sold via products', 'mp-commerce-promotions' ),
			__( 'HTML email to the recipient when gift cards are generated or scheduled delivery runs.', 'mp-commerce-promotions' ),
			$this->settings->gift_card_delivery_email_enabled()
		);
		$this->checkbox_row(
			'mp_cp_gift_card_balance_checker_enabled',
			__( 'Enable public balance checker', 'mp-commerce-promotions' ),
			__( 'Shows the [mp_cp_gift_card_balance] shortcode and balance lookup page.', 'mp-commerce-promotions' ),
			$this->settings->gift_card_balance_checker_enabled()
		);
		$this->checkbox_row(
			'mp_cp_gift_card_my_account_enabled',
			__( 'Enable My Account gift cards endpoint', 'mp-commerce-promotions' ),
			__( 'Adds a Gift cards tab under WooCommerce My Account.', 'mp-commerce-promotions' ),
			$this->settings->gift_card_my_account_enabled()
		);
		$this->checkbox_row(
			'mp_cp_gift_card_scheduled_cron_enabled',
			__( 'Enable scheduled gift card delivery cron', 'mp-commerce-promotions' ),
			__( 'Hourly job to fulfill send-on-date gift cards. Disable to run deliveries manually from Diagnostics only.', 'mp-commerce-promotions' ),
			$this->settings->gift_card_scheduled_cron_enabled()
		);
		$current_tpl  = $this->settings->gift_card_email_template();
		$appearance   = $this->settings->resolve_gift_card_email_appearance( $current_tpl );
		$email_style  = $this->settings->gift_card_email_style();
		$preview_amt  = GiftCardEmailPreview::DEFAULT_SAMPLE_AMOUNT;
		$preview_cur  = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR';

		echo '<tr><th scope="row"><label for="mp_cp_gift_card_email_template">' . esc_html__( 'Email template style', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<select name="mp_cp_gift_card_email_template" id="mp_cp_gift_card_email_template">';
		foreach ( Settings::gift_card_email_templates() as $slug ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $current_tpl, $slug, false ) . '>' . esc_html( ucfirst( $slug ) ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__(
			'Appearance fields below apply to the selected template. Save settings to store overrides per template.',
			'mp-commerce-promotions'
		) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Email style', 'mp-commerce-promotions' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="mp_cp_gift_card_email_style" value="' . esc_attr( Settings::GIFT_CARD_EMAIL_STYLE_COMMERCE_GROWTH ) . '"'
			. checked( $email_style, Settings::GIFT_CARD_EMAIL_STYLE_COMMERCE_GROWTH, false ) . ' /> '
			. esc_html__( 'Commerce Growth template', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<label><input type="radio" name="mp_cp_gift_card_email_style" value="' . esc_attr( Settings::GIFT_CARD_EMAIL_STYLE_WOOCOMMERCE ) . '"'
			. checked( $email_style, Settings::GIFT_CARD_EMAIL_STYLE_WOOCOMMERCE, false ) . ' /> '
			. esc_html__( 'WooCommerce email style', 'mp-commerce-promotions' ) . '</label>';
		if ( ! GiftCardWooEmailStyler::is_available() ) {
			echo '<p class="description">' . esc_html__(
				'WooCommerce email wrapper is not available; delivery falls back to the Commerce Growth template.',
				'mp-commerce-promotions'
			) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__(
				'Uses WooCommerce email header, footer, and inline styles (Woo → Settings → Emails colors where configured).',
				'mp-commerce-promotions'
			) . '</p>';
		}
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_gift_card_logo_url">' . esc_html__( 'Logo URL', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="url" class="regular-text" name="mp_cp_gift_card_logo_url" id="mp_cp_gift_card_logo_url" value="' . esc_attr( $appearance['logo_url'] ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gift_card_accent_color">' . esc_html__( 'Accent color', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="mp_cp_gift_card_accent_color" id="mp_cp_gift_card_accent_color" value="' . esc_attr( $appearance['accent_color'] ) . '" placeholder="#2271b1" /></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gift_card_email_footer_text">' . esc_html__( 'Footer text', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<textarea name="mp_cp_gift_card_email_footer_text" id="mp_cp_gift_card_email_footer_text" class="large-text" rows="2">'
			. esc_textarea( $appearance['footer_text'] ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gift_card_support_email_text">' . esc_html__( 'Support contact text', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<textarea name="mp_cp_gift_card_support_email_text" id="mp_cp_gift_card_support_email_text" class="large-text" rows="3">'
			. esc_textarea( $appearance['support_text'] ) . '</textarea></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Template preview', 'mp-commerce-promotions' ) . '</th><td>';
		echo '<p class="description">' . esc_html__(
			'Preview uses sample code ****SAMPLE only — never a real gift card code.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<div style="max-width:640px;border:1px solid #c3c4c7;background:#f6f7f7;padding:8px;overflow:auto;">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-only HTML from plugin templates.
		echo GiftCardEmailPreview::render( $this->settings, $current_tpl, $preview_amt, $preview_cur );
		echo '</div></td></tr>';
		echo '</tbody></table>';

		echo '<h3 class="title">' . esc_html__( 'Gift card email sender', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Default mode lets WooCommerce, WP Mail SMTP, or your site mail settings choose the From address (recommended). Custom mode sets From/Reply-To only when the address is valid and authorized by your SMTP provider.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$sender_mode = $this->settings->gift_card_sender_mode();
		echo '<tr><th scope="row">' . esc_html__( 'Sender mode', 'mp-commerce-promotions' ) . '</th><td>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Sender mode', 'mp-commerce-promotions' ) . '</legend>';
		echo '<label><input type="radio" name="mp_cp_gift_card_sender_mode" value="' . esc_attr( Settings::GIFT_CARD_SENDER_MODE_DEFAULT ) . '"'
			. checked( $sender_mode, Settings::GIFT_CARD_SENDER_MODE_DEFAULT, false ) . ' /> '
			. esc_html__( 'Default (WooCommerce / site / WP Mail SMTP)', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<label><input type="radio" name="mp_cp_gift_card_sender_mode" value="' . esc_attr( Settings::GIFT_CARD_SENDER_MODE_CUSTOM ) . '"'
			. checked( $sender_mode, Settings::GIFT_CARD_SENDER_MODE_CUSTOM, false ) . ' /> '
			. esc_html__( 'Custom sender', 'mp-commerce-promotions' ) . '</label>';
		echo '</fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gift_card_sender_name">' . esc_html__( 'Custom sender name', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="mp_cp_gift_card_sender_name" id="mp_cp_gift_card_sender_name" value="' . esc_attr( $this->settings->gift_card_sender_name() ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gift_card_sender_email">' . esc_html__( 'Custom sender email', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="email" class="regular-text" name="mp_cp_gift_card_sender_email" id="mp_cp_gift_card_sender_email" value="' . esc_attr( $this->settings->gift_card_sender_email() ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gift_card_reply_to_email">' . esc_html__( 'Reply-To email (optional)', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="email" class="regular-text" name="mp_cp_gift_card_reply_to_email" id="mp_cp_gift_card_reply_to_email" value="' . esc_attr( $this->settings->gift_card_reply_to_email() ) . '" /></td></tr>';
		echo '</tbody></table>';

		$this->render_gift_card_test_email_form( $preview_amt, $preview_cur );

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
		$this->settings->set_gift_card_delivery_email_enabled( $this->post_yes( 'mp_cp_gift_card_delivery_email_enabled' ) );
		$this->settings->set_gift_card_balance_checker_enabled( $this->post_yes( 'mp_cp_gift_card_balance_checker_enabled' ) );
		$this->settings->set_gift_card_my_account_enabled( $this->post_yes( 'mp_cp_gift_card_my_account_enabled' ) );
		$this->settings->set_gift_card_scheduled_cron_enabled( $this->post_yes( 'mp_cp_gift_card_scheduled_cron_enabled' ) );
		$template_slug = isset( $_POST['mp_cp_gift_card_email_template'] )
			? sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_email_template'] ) )
			: $this->settings->gift_card_email_template();
		if ( isset( $_POST['mp_cp_gift_card_email_template'] ) ) {
			$this->settings->set_gift_card_email_template( $template_slug );
		}
		if ( isset( $_POST['mp_cp_gift_card_email_style'] ) ) {
			$this->settings->set_gift_card_email_style(
				sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_email_style'] ) )
			);
		}
		$logo = isset( $_POST['mp_cp_gift_card_logo_url'] )
			? esc_url_raw( wp_unslash( (string) $_POST['mp_cp_gift_card_logo_url'] ) )
			: '';
		$accent = isset( $_POST['mp_cp_gift_card_accent_color'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_gift_card_accent_color'] ) )
			: '';
		$footer = isset( $_POST['mp_cp_gift_card_email_footer_text'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['mp_cp_gift_card_email_footer_text'] ) )
			: '';
		if ( isset( $_POST['mp_cp_gift_card_logo_url'] ) ) {
			$this->settings->set_gift_card_logo_url( $logo );
		}
		if ( isset( $_POST['mp_cp_gift_card_accent_color'] ) ) {
			$this->settings->set_gift_card_accent_color( $accent );
		}
		if ( isset( $_POST['mp_cp_gift_card_email_footer_text'] ) ) {
			$this->settings->set_gift_card_email_footer_text( $footer );
		}
		$support = isset( $_POST['mp_cp_gift_card_support_email_text'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['mp_cp_gift_card_support_email_text'] ) )
			: '';
		$this->settings->set_gift_card_email_template_settings(
			$template_slug,
			array(
				'logo_url'     => $logo,
				'accent_color' => $accent,
				'footer_text'  => $footer,
				'support_text' => $support,
			)
		);
		$requested_mode = isset( $_POST['mp_cp_gift_card_sender_mode'] )
			? sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_sender_mode'] ) )
			: Settings::GIFT_CARD_SENDER_MODE_DEFAULT;
		if ( isset( $_POST['mp_cp_gift_card_sender_name'] ) ) {
			$this->settings->set_gift_card_sender_name(
				sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_gift_card_sender_name'] ) )
			);
		}
		$sender_email_raw = isset( $_POST['mp_cp_gift_card_sender_email'] )
			? sanitize_email( wp_unslash( (string) $_POST['mp_cp_gift_card_sender_email'] ) )
			: '';
		$this->settings->set_gift_card_sender_email( $sender_email_raw );
		if ( isset( $_POST['mp_cp_gift_card_reply_to_email'] ) ) {
			$this->settings->set_gift_card_reply_to_email(
				sanitize_email( wp_unslash( (string) $_POST['mp_cp_gift_card_reply_to_email'] ) )
			);
		}
		if ( $requested_mode === Settings::GIFT_CARD_SENDER_MODE_CUSTOM ) {
			if ( $sender_email_raw === '' || ! is_email( $sender_email_raw ) ) {
				$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_DEFAULT );
				GiftCardEmailSender::flag_invalid_custom_on_save();
				$this->redirect_with_notice( 'warning', 'sender_invalid_fallback' );
			}
			$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_CUSTOM );
		} else {
			$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_DEFAULT );
		}
		if ( isset( $_POST['mp_cp_gift_card_support_email_text'] ) ) {
			$this->settings->set_gift_card_support_email_text( $support );
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

		$this->redirect_with_notice( 'success', 'saved' );
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

	private function render_gift_card_test_email_form( float $default_amount, string $default_currency ): void {
		$default_to = function_exists( 'get_option' )
			? sanitize_email( (string) get_option( 'admin_email' ) )
			: '';

		echo '<h3 class="title">' . esc_html__( 'Send test gift card email', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Sends a sample email with code ****TEST only. No gift card is created. Save settings first to apply template changes.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<form method="post" action="" style="max-width:520px;margin-bottom:1.5em;">';
		wp_nonce_field( self::TEST_EMAIL_NONCE_ACTION );
		echo '<input type="hidden" name="mp_cp_gift_card_settings_test_email" value="1" />';
		echo '<p><label for="mp_cp_gc_settings_test_to">' . esc_html__( 'Recipient', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="email" class="regular-text" id="mp_cp_gc_settings_test_to" name="mp_cp_gc_settings_test_to" value="'
			. esc_attr( $default_to ) . '" required /></p>';
		echo '<p><label for="mp_cp_gc_settings_test_amount">' . esc_html__( 'Sample amount', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="number" step="0.01" min="0.01" class="small-text" id="mp_cp_gc_settings_test_amount" name="mp_cp_gc_settings_test_amount" value="'
			. esc_attr( (string) $default_amount ) . '" /></p>';
		echo '<p><label for="mp_cp_gc_settings_test_currency">' . esc_html__( 'Currency', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="text" class="small-text" id="mp_cp_gc_settings_test_currency" name="mp_cp_gc_settings_test_currency" value="'
			. esc_attr( $default_currency ) . '" maxlength="8" /></p>';
		submit_button( __( 'Send test gift card email', 'mp-commerce-promotions' ), 'secondary', 'mp_cp_gift_card_settings_test_send', false );
		echo '</form>';
	}

	private function handle_settings_test_email(): void {
		if ( ! isset( $_POST['mp_cp_gift_card_settings_test_email'] ) ) {
			return;
		}

		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ),
				self::TEST_EMAIL_NONCE_ACTION
			)
		) {
			$this->redirect_with_notice( 'error', 'invalid_nonce' );
		}

		$to = isset( $_POST['mp_cp_gc_settings_test_to'] )
			? sanitize_email( wp_unslash( (string) $_POST['mp_cp_gc_settings_test_to'] ) )
			: '';
		if ( $to === '' || ! is_email( $to ) ) {
			$this->redirect_with_notice( 'error', 'test_email_invalid' );
		}

		$amount = isset( $_POST['mp_cp_gc_settings_test_amount'] )
			? (float) sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_gc_settings_test_amount'] ) )
			: GiftCardEmailPreview::DEFAULT_SAMPLE_AMOUNT;
		$currency = isset( $_POST['mp_cp_gc_settings_test_currency'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_gc_settings_test_currency'] ) )
			: '';

		$manual = new GiftCardManualIssueDelivery(
			new GiftCardDeliveryMailer( $this->settings ),
			new \MP\CommercePromotions\GiftCard\GiftCardManualDeliveryStore()
		);
		$result = $manual->send_test_email( $to, $amount > 0 ? $amount : null, $currency !== '' ? $currency : null );

		if ( ! empty( $result['ok'] ) || (string) ( $result['delivery_status'] ?? '' ) === GiftCardDeliveryStatus::SENT ) {
			$this->redirect_with_notice( 'success', 'test_email_sent' );
		}

		$this->redirect_with_notice( 'error', 'test_email_failed' );
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
