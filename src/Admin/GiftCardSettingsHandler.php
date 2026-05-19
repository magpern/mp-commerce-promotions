<?php
/**
 * Gift card module settings UI and save handling (shared with global Settings backward compat).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\GiftCard\GiftCardDeliveryMailer;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardEmailCopy;
use MP\CommercePromotions\GiftCard\GiftCardEmailPlaceholders;
use MP\CommercePromotions\GiftCard\GiftCardEmailPreview;
use MP\CommercePromotions\GiftCard\GiftCardEmailSender;
use MP\CommercePromotions\GiftCard\GiftCardManualIssueDelivery;
use MP\CommercePromotions\GiftCard\GiftCardManualDeliveryStore;
use MP\CommercePromotions\GiftCard\GiftCardWooEmailStyler;
use MP\CommercePromotions\Service\Settings;

final class GiftCardSettingsHandler {

	public const NONCE_ACTION = 'mp_cp_save_gift_card_settings';

	public const NONCE_FIELD = 'mp_cp_gift_card_settings_nonce';

	public const SUBMIT_FIELD = 'mp_cp_save_gift_card_settings_submit';

	public const TEST_EMAIL_NONCE_ACTION = 'mp_cp_gift_card_settings_test_email';

	/** Query args for admin notices after redirect. */
	public const NOTICE_TYPE_QUERY = 'mp_cp_settings_notice';

	public const NOTICE_CODE_QUERY = 'mp_cp_settings_code';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_preview_script' ) );
	}

	public function enqueue_preview_script( string $hook ): void {
		unset( $hook );
		if ( ! isset( $_GET['page'], $_GET['tab'], $_GET['gift_cards_section'] ) ) {
			return;
		}
		if ( (string) $_GET['page'] !== 'mp-commerce-promotions'
			|| (string) $_GET['tab'] !== 'gift-cards'
			|| (string) $_GET['gift_cards_section'] !== GiftCardModuleSections::SECTION_SETTINGS ) {
			return;
		}

		$preview_cur = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR';
		$sample      = GiftCardEmailPlaceholders::preview_variables(
			$this->settings,
			GiftCardEmailPreview::DEFAULT_SAMPLE_AMOUNT,
			$preview_cur
		);

		if ( ! defined( 'MP_COMMERCE_PROMOTIONS_URL' ) || ! defined( 'MP_COMMERCE_PROMOTIONS_VERSION' ) ) {
			return;
		}

		wp_enqueue_script(
			'mp-cp-gift-card-email-preview',
			MP_COMMERCE_PROMOTIONS_URL . 'assets/js/gift-card-email-preview.js',
			array(),
			MP_COMMERCE_PROMOTIONS_VERSION,
			true
		);
		wp_localize_script(
			'mp-cp-gift-card-email-preview',
			'mpCpGiftCardEmailPreview',
			array(
				'sample'       => $sample,
				'placeholders' => GiftCardEmailPlaceholders::supported_keys(),
			)
		);
	}

	public function render(): void {
		$this->render_notices();

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<h2 class="title">' . esc_html__( 'General', 'mp-commerce-promotions' ) . '</h2>';
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
		echo '</tbody></table>';

		$this->render_email_sender_section();
		$this->render_email_templates_section();
		$this->render_gift_card_test_email_form();

		echo '<p class="submit">';
		echo '<button type="submit" name="' . esc_attr( self::SUBMIT_FIELD ) . '" value="1" class="button button-primary">';
		echo esc_html__( 'Save gift card settings', 'mp-commerce-promotions' );
		echo '</button>';
		echo '</p>';
		echo '</form>';
	}

	private function render_email_sender_section(): void {
		$sender_mode = $this->settings->gift_card_sender_mode();

		echo '<h2 class="title">' . esc_html__( 'Email sender', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Default mode lets WooCommerce, WP Mail SMTP, or your site mail settings choose the From address (recommended). Custom mode sets From/Reply-To only when the address is valid and authorized by your SMTP provider.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
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
	}

	private function render_email_templates_section(): void {
		$appearance  = $this->settings->resolve_gift_card_email_appearance();
		$email_style = $this->settings->gift_card_email_style();
		$preview_amt = GiftCardEmailPreview::DEFAULT_SAMPLE_AMOUNT;
		$preview_cur = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR';
		$sample_vars = GiftCardEmailPlaceholders::preview_variables( $this->settings, $preview_amt, $preview_cur );
		$copy        = GiftCardEmailCopy::resolve( $this->settings, $sample_vars );

		echo '<h2 class="title">' . esc_html__( 'Gift card email', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'One configurable gift card email for your store. Visual design themes for purchasers are planned as a future customer-facing option.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->textarea_row(
			'mp_cp_gift_card_email_subject',
			__( 'Email subject', 'mp-commerce-promotions' ),
			$this->settings->gift_card_email_subject(),
			__( 'Placeholders: {site_title}, {amount}, {currency}, {code}, {expiry}, {recipient_name}, {purchaser_name}, {message}, {store_url}', 'mp-commerce-promotions' )
		);
		$this->textarea_row(
			'mp_cp_gift_card_email_heading',
			__( 'Email heading', 'mp-commerce-promotions' ),
			$this->settings->gift_card_email_heading(),
			''
		);
		$this->textarea_row(
			'mp_cp_gift_card_email_intro',
			__( 'Intro / body text', 'mp-commerce-promotions' ),
			$this->settings->gift_card_email_intro(),
			''
		);
		$this->textarea_row(
			'mp_cp_gift_card_email_redeem_instructions',
			__( 'Redeem instructions', 'mp-commerce-promotions' ),
			$this->settings->gift_card_email_redeem_instructions(),
			''
		);
		$this->textarea_row(
			'mp_cp_gift_card_email_footer_text',
			__( 'Footer / support text', 'mp-commerce-promotions' ),
			$this->settings->gift_card_email_footer_text(),
			''
		);
		$this->textarea_row(
			'mp_cp_gift_card_support_email_text',
			__( 'Support contact text', 'mp-commerce-promotions' ),
			$this->settings->gift_card_support_email_text(),
			''
		);

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
		echo '<tr><th scope="row">' . esc_html__( 'Live preview', 'mp-commerce-promotions' ) . '</th><td>';
		echo '<p class="description">' . esc_html__(
			'Updates as you edit (sample code ****SAMPLE only — never a real gift card code).',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<p><strong>' . esc_html__( 'Subject preview:', 'mp-commerce-promotions' ) . '</strong> ';
		echo '<span id="mp-cp-gc-email-subject-preview">' . esc_html( $copy['subject'] ) . '</span></p>';
		echo '<div id="mp-cp-gc-email-preview-wrap" style="display:block;max-width:640px;border:1px solid #c3c4c7;background:#f6f7f7;padding:8px;overflow:auto;">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-only HTML from plugin templates.
		echo GiftCardEmailPreview::render( $this->settings, null, $preview_amt, $preview_cur );
		echo '</div></td></tr>';
		echo '</tbody></table>';
	}

	private function render_gift_card_test_email_form(): void {
		$default_amount   = GiftCardEmailPreview::DEFAULT_SAMPLE_AMOUNT;
		$default_currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR';
		$default_to       = function_exists( 'get_option' )
			? sanitize_email( (string) get_option( 'admin_email' ) )
			: '';

		echo '<h2 class="title">' . esc_html__( 'Test email', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Sends a sample email with code ****TEST only. No gift card is created. Uses current form values when sent from this page.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<form method="post" action="" id="mp-cp-gc-test-email-form" style="max-width:520px;margin-bottom:1.5em;">';
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

	/**
	 * Whether the current POST includes any gift card setting fields.
	 */
	public static function post_includes_gift_card_fields(): bool {
		$keys = array(
			'mp_cp_gift_card_delivery_email_enabled',
			'mp_cp_gift_card_balance_checker_enabled',
			'mp_cp_gift_card_my_account_enabled',
			'mp_cp_gift_card_scheduled_cron_enabled',
			'mp_cp_gift_card_email_subject',
			'mp_cp_gift_card_email_heading',
			'mp_cp_gift_card_email_intro',
			'mp_cp_gift_card_email_redeem_instructions',
			'mp_cp_gift_card_email_style',
			'mp_cp_gift_card_logo_url',
			'mp_cp_gift_card_accent_color',
			'mp_cp_gift_card_email_footer_text',
			'mp_cp_gift_card_support_email_text',
			'mp_cp_gift_card_sender_mode',
			'mp_cp_gift_card_sender_name',
			'mp_cp_gift_card_sender_email',
			'mp_cp_gift_card_reply_to_email',
		);

		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Persist gift card options from $_POST. Returns notice code when sender fallback applied.
	 *
	 * @return string|null Notice code for redirect, or null when no sender warning.
	 */
	public function save_gift_card_options_from_post(): ?string {
		$this->settings->set_gift_card_delivery_email_enabled( $this->post_yes( 'mp_cp_gift_card_delivery_email_enabled' ) );
		$this->settings->set_gift_card_balance_checker_enabled( $this->post_yes( 'mp_cp_gift_card_balance_checker_enabled' ) );
		$this->settings->set_gift_card_my_account_enabled( $this->post_yes( 'mp_cp_gift_card_my_account_enabled' ) );
		$this->settings->set_gift_card_scheduled_cron_enabled( $this->post_yes( 'mp_cp_gift_card_scheduled_cron_enabled' ) );

		if ( isset( $_POST['mp_cp_gift_card_email_template'] ) ) {
			$this->settings->set_gift_card_email_template( Settings::GIFT_CARD_TEMPLATE_CLASSIC );
		}
		if ( isset( $_POST['mp_cp_gift_card_email_subject'] ) ) {
			$this->settings->set_gift_card_email_subject(
				sanitize_textarea_field( wp_unslash( (string) $_POST['mp_cp_gift_card_email_subject'] ) )
			);
		}
		if ( isset( $_POST['mp_cp_gift_card_email_heading'] ) ) {
			$this->settings->set_gift_card_email_heading(
				sanitize_textarea_field( wp_unslash( (string) $_POST['mp_cp_gift_card_email_heading'] ) )
			);
		}
		if ( isset( $_POST['mp_cp_gift_card_email_intro'] ) ) {
			$this->settings->set_gift_card_email_intro(
				sanitize_textarea_field( wp_unslash( (string) $_POST['mp_cp_gift_card_email_intro'] ) )
			);
		}
		if ( isset( $_POST['mp_cp_gift_card_email_redeem_instructions'] ) ) {
			$this->settings->set_gift_card_email_redeem_instructions(
				sanitize_textarea_field( wp_unslash( (string) $_POST['mp_cp_gift_card_email_redeem_instructions'] ) )
			);
		}
		if ( isset( $_POST['mp_cp_gift_card_email_style'] ) ) {
			$this->settings->set_gift_card_email_style(
				sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_email_style'] ) )
			);
		}

		$logo   = isset( $_POST['mp_cp_gift_card_logo_url'] )
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

		$warning_code = null;
		if ( $requested_mode === Settings::GIFT_CARD_SENDER_MODE_CUSTOM ) {
			if ( $sender_email_raw === '' || ! is_email( $sender_email_raw ) ) {
				$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_DEFAULT );
				GiftCardEmailSender::flag_invalid_custom_on_save();
				$warning_code = 'sender_invalid_fallback';
			} else {
				$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_CUSTOM );
			}
		} else {
			$this->settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_DEFAULT );
		}
		if ( isset( $_POST['mp_cp_gift_card_support_email_text'] ) ) {
			$this->settings->set_gift_card_support_email_text( $support );
		}

		return $warning_code;
	}

	public function handle_post_save(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST[ self::SUBMIT_FIELD ] ) ) {
			if (
				! isset( $_POST['mp_cp_save_settings_submit'] )
				|| ! self::post_includes_gift_card_fields()
			) {
				return;
			}
		}

		$nonce_field = isset( $_POST[ self::NONCE_FIELD ] )
			? self::NONCE_FIELD
			: 'mp_cp_settings_nonce';
		$nonce_action = $nonce_field === self::NONCE_FIELD
			? self::NONCE_ACTION
			: 'mp_cp_save_settings';

		if ( ! isset( $_POST[ $nonce_field ] ) ) {
			$this->redirect_with_notice( 'error', 'missing_nonce' );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ $nonce_field ] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->redirect_with_notice( 'error', 'invalid_nonce' );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'mp-commerce-promotions' ) );
		}

		$warning = $this->save_gift_card_options_from_post();
		if ( $warning !== null ) {
			$this->redirect_with_notice( 'warning', $warning );
		}

		$this->redirect_with_notice( 'success', 'saved' );
	}

	public function handle_settings_test_email(): void {
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

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to send test emails.', 'mp-commerce-promotions' ) );
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
			new GiftCardManualDeliveryStore()
		);
		$overrides = GiftCardEmailCopy::overrides_from_post();
		$result    = $manual->send_test_email(
			$to,
			$amount > 0 ? $amount : null,
			$currency !== '' ? $currency : null,
			$overrides
		);

		if ( ! empty( $result['ok'] ) || (string) ( $result['delivery_status'] ?? '' ) === GiftCardDeliveryStatus::SENT ) {
			$this->redirect_with_notice( 'success', 'test_email_sent' );
		}

		$this->redirect_with_notice( 'error', 'test_email_failed' );
	}

	public function render_notices(): void {
		if ( GiftCardEmailSender::consume_invalid_custom_notice() ) {
			AdminNotice::warning(
				__( 'Custom sender email was invalid. Gift card emails will use the default sender until you save a valid custom address.', 'mp-commerce-promotions' )
			);
		}

		if ( ! isset( $_GET[ self::NOTICE_TYPE_QUERY ] ) || ! isset( $_GET[ self::NOTICE_CODE_QUERY ] ) ) {
			return;
		}

		$type = sanitize_text_field( wp_unslash( (string) $_GET[ self::NOTICE_TYPE_QUERY ] ) );
		$code = sanitize_text_field( wp_unslash( (string) $_GET[ self::NOTICE_CODE_QUERY ] ) );
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

	public function notice_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'saved':
				return __( 'Gift card settings saved.', 'mp-commerce-promotions' );
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

	public function redirect_with_notice( string $type, string $code ): void {
		wp_safe_redirect(
			GiftCardModuleSections::section_url(
				GiftCardModuleSections::SECTION_SETTINGS,
				array(
					self::NOTICE_TYPE_QUERY => $type,
					self::NOTICE_CODE_QUERY => $code,
				)
			)
		);
		exit;
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

	private function post_yes( string $field ): bool {
		return isset( $_POST[ $field ] )
			&& sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ) === 'yes';
	}

	private function textarea_row( string $id, string $label, string $value, string $description ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea name="' . esc_attr( $id ) . '" id="' . esc_attr( $id ) . '" class="large-text" rows="2">'
			. esc_textarea( $value ) . '</textarea>';
		if ( $description !== '' ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

}
