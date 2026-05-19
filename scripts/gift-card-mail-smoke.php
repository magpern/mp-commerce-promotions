<?php
/**
 * Smoke: manual gift card issue email + test email diagnostics.
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-mail-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run via WP-CLI eval-file inside WordPress.\n";
	exit( 1 );
}

use MP\CommercePromotions\Admin\GiftCardSettingsHandler;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryMailer;
use MP\CommercePromotions\GiftCard\GiftCardEmailCopy;
use MP\CommercePromotions\GiftCard\GiftCardEmailCopyDefaults;
use MP\CommercePromotions\GiftCard\GiftCardEmailTemplateReset;
use MP\CommercePromotions\GiftCard\GiftCardEmailPlaceholders;
use MP\CommercePromotions\GiftCard\GiftCardEmailPreview;
use MP\CommercePromotions\GiftCard\GiftCardEmailSender;
use MP\CommercePromotions\GiftCard\GiftCardEmailTemplate;
use MP\CommercePromotions\GiftCard\GiftCardWooEmailStyler;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardManualDeliveryStore;
use MP\CommercePromotions\GiftCard\GiftCardManualIssueDelivery;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\Service\Settings;

global $wpdb;

if ( ! $wpdb instanceof wpdb ) {
	echo "FAIL: wpdb unavailable\n";
	exit( 1 );
}

$recipient = function_exists( 'get_option' )
	? sanitize_email( (string) get_option( 'admin_email' ) )
	: '';
if ( $recipient === '' ) {
	$recipient = 'postmaster@biopentra.eu';
}
$settings  = new Settings();
$mailer    = new GiftCardDeliveryMailer( $settings );
$manual    = new GiftCardManualIssueDelivery( $mailer, new GiftCardManualDeliveryStore() );
$repo      = new GiftCardRepository( $wpdb );
$tx        = new GiftCardTransactionRepository( $wpdb );
$ledger    = new GiftCardLedger( $repo, $tx );

$failures = 0;
$pass     = static function ( string $label ) use ( &$failures ): void {
	echo "PASS: {$label}\n";
};
$fail = static function ( string $label, string $detail = '' ) use ( &$failures ): void {
	++$failures;
	echo 'FAIL: ' . $label . ( $detail !== '' ? " ({$detail})" : '' ) . "\n";
};

echo "=== Gift card mail smoke ===\n";

delete_option( Settings::OPTION_GIFT_CARD_SENDER_MODE );
if ( $settings->gift_card_sender_mode() !== Settings::GIFT_CARD_SENDER_MODE_DEFAULT ) {
	$fail( 'unset sender mode defaults to site mail' );
} else {
	$pass( 'unset sender mode defaults to site mail' );
}
$settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_DEFAULT );

$preview_html = GiftCardEmailPreview::render( $settings );
if ( strpos( $preview_html, GiftCardEmailPreview::SAMPLE_MASKED_CODE ) !== false
	&& strpos( $preview_html, 'REALGIFTCODE999' ) === false ) {
	$pass( 'email preview uses sample code only' );
} else {
	$fail( 'email preview uses sample code only' );
}

$amount_display = GiftCardEmailPlaceholders::format_amount_display( 25.0, 'EUR' );
if ( $amount_display !== '' && strpos( $amount_display, '&nbsp;' ) === false ) {
	$pass( 'amount display has no nbsp entities' );
} else {
	$fail( 'amount display has no nbsp entities', $amount_display );
}

$entity_preview = GiftCardEmailPreview::render( $settings, null, 25.0, 'EUR' );
if ( strpos( $entity_preview, '&nbsp;' ) === false && strpos( $entity_preview, '&#160;' ) === false ) {
	$pass( 'preview html has no escaped nbsp entities' );
} else {
	$fail( 'preview html has no escaped nbsp entities' );
}

if ( $amount_display !== '' && strpos( $entity_preview, $amount_display ) !== false ) {
	$pass( 'preview includes formatted amount' );
} else {
	$fail( 'preview includes formatted amount', $amount_display );
}

$ajax_preview_hook = 'wp_ajax_' . GiftCardSettingsHandler::AJAX_ACTION_PREVIEW;
$ajax_test_hook    = 'wp_ajax_' . GiftCardSettingsHandler::AJAX_ACTION_TEST;
if ( has_action( $ajax_preview_hook ) && has_action( $ajax_test_hook ) ) {
	$pass( 'gift card email ajax handlers registered' );
} else {
	$fail( 'gift card email ajax handlers registered' );
}

$overrides = GiftCardEmailCopy::sanitize_overrides_from_array(
	array(
		'mp_cp_gift_card_email_heading' => 'Smoke unsaved heading',
	)
);
$unsaved_preview = GiftCardEmailPreview::render( $settings, null, 25.0, 'EUR', $overrides );
if ( is_array( $overrides ) && strpos( $unsaved_preview, 'Smoke unsaved heading' ) !== false ) {
	$pass( 'preview supports unsaved copy overrides' );
} else {
	$fail( 'preview supports unsaved copy overrides' );
}

update_option( Settings::OPTION_GIFT_CARD_EMAIL_SUBJECT, 'Merchant QA subject line', false );
update_option( Settings::OPTION_GIFT_CARD_EMAIL_HEADING, 'Custom preview heading', false );
update_option( Settings::OPTION_GIFT_CARD_SUPPORT_TEXT, 'Merchant QA support', false );
$read_settings = new Settings();
if ( $read_settings->gift_card_email_subject() === GiftCardEmailPlaceholders::default_subject()
	&& $read_settings->gift_card_email_heading() === GiftCardEmailPlaceholders::default_heading()
	&& $read_settings->gift_card_support_email_text() === GiftCardEmailPlaceholders::default_support_text() ) {
	$pass( 'known QA strings cleaned to production defaults on read' );
} else {
	$fail( 'known QA strings cleaned to production defaults on read' );
}

$settings->set_gift_card_email_subject( 'Real merchant persist subject' );
$settings->set_gift_card_email_heading( 'Real merchant persist heading' );
if ( $settings->gift_card_email_subject() === 'Real merchant persist subject'
	&& $settings->gift_card_email_heading() === 'Real merchant persist heading' ) {
	$pass( 'custom merchant copy persists via Settings API' );
} else {
	$fail( 'custom merchant copy persists via Settings API' );
}

update_option( Settings::OPTION_GIFT_CARD_EMAIL_INTRO, 'Smoke body with sample only.', false );
$cleaned_intro = ( new Settings() )->gift_card_email_intro();
if ( $cleaned_intro === GiftCardEmailPlaceholders::default_intro() ) {
	$pass( 'smoke intro string migrated to production default' );
} else {
	$fail( 'smoke intro string migrated to production default', $cleaned_intro );
}

if ( GiftCardEmailCopyDefaults::is_known_smoke_string( 'Smoke persist subject' ) ) {
	$pass( 'smoke string registry includes persist subject' );
} else {
	$fail( 'smoke string registry includes persist subject' );
}

$handler_src = (string) file_get_contents(
	dirname( __DIR__ ) . '/src/Admin/GiftCardSettingsHandler.php'
);
if ( strpos( $handler_src, 'wp_enqueue_media' ) !== false
	&& strpos( $handler_src, 'wp-color-picker' ) !== false
	&& strpos( $handler_src, 'is_gift_card_settings_screen' ) !== false
	&& strpos( $handler_src, 'wp-color-picker regular-text' ) !== false ) {
	$pass( 'settings screen enqueues media and color picker assets' );
} else {
	$fail( 'settings screen enqueues media and color picker assets' );
}

if ( strpos( $handler_src, 'SUBMIT_RESET_TEMPLATE' ) !== false
	&& strpos( $handler_src, 'GiftCardEmailTemplateReset' ) !== false ) {
	$pass( 'reset gift card email template handler present' );
} else {
	$fail( 'reset gift card email template handler present' );
}

$settings->set_gift_card_email_subject( 'Merchant QA subject line' );
$settings->set_gift_card_sender_name( 'Smoke sender name preserved' );
( new GiftCardEmailTemplateReset() )->apply( $settings );
if ( $settings->gift_card_email_subject() === GiftCardEmailPlaceholders::default_subject()
	&& $settings->gift_card_sender_name() === 'Smoke sender name preserved' ) {
	$pass( 'reset restores email template without touching sender' );
} else {
	$fail( 'reset restores email template without touching sender' );
}

$settings->set_gift_card_accent_color( GiftCardEmailCopyDefaults::QA_ACCENT_COLOR );
$qa_accent_saved = $settings->gift_card_accent_color_saved();
if ( $qa_accent_saved === '' ) {
	$pass( 'QA accent #aa5500 falls back on read' );
} else {
	$fail( 'QA accent #aa5500 falls back on read', $qa_accent_saved );
}

$settings->set_gift_card_accent_color( 'not-valid' );
$accent_resolved = $settings->gift_card_accent_color();
if ( preg_match( '/^#[0-9a-f]{6}$/', $accent_resolved ) ) {
	$pass( 'invalid accent falls back to resolved default' );
} else {
	$fail( 'invalid accent falls back to resolved default', $accent_resolved );
}

$invalid_tpl = GiftCardEmailTemplate::normalize_slug( 'invalid-template-slug' );
if ( $invalid_tpl === Settings::GIFT_CARD_TEMPLATE_CLASSIC ) {
	$pass( 'invalid template falls back to classic' );
} else {
	$fail( 'invalid template falls back to classic' );
}

$settings->set_gift_card_email_template( Settings::GIFT_CARD_TEMPLATE_HOLIDAY );
if ( $settings->gift_card_email_template() === Settings::GIFT_CARD_TEMPLATE_CLASSIC ) {
	$pass( 'one-template mode (legacy holiday slug ignored)' );
} else {
	$fail( 'one-template mode (legacy holiday slug ignored)' );
}

$settings->set_gift_card_email_heading( 'Real custom preview heading' );
$settings->set_gift_card_email_intro( 'Real custom preview intro text.' );
$settings->set_gift_card_accent_color( '#112233' );
$custom_preview = GiftCardEmailPreview::render( $settings );
if ( strpos( $custom_preview, 'Real custom preview heading' ) !== false
	&& strpos( $custom_preview, 'Real custom preview intro' ) !== false
	&& strpos( $custom_preview, '#112233' ) !== false ) {
	$pass( 'preview renders custom heading/body/accent' );
} else {
	$fail( 'preview renders custom heading/body/accent' );
}

$defaults_preview = GiftCardEmailPreview::render( new Settings() );
if ( strpos( $defaults_preview, 'Merchant QA' ) === false
	&& strpos( $defaults_preview, 'Smoke persist' ) === false
	&& strpos( $defaults_preview, GiftCardEmailPreview::SAMPLE_MASKED_CODE ) !== false ) {
	$pass( 'preview uses production defaults without QA strings' );
} else {
	$fail( 'preview uses production defaults without QA strings' );
}

$settings->set_gift_card_email_style( Settings::GIFT_CARD_EMAIL_STYLE_WOOCOMMERCE );
$effective_style = $settings->effective_gift_card_email_style();
if ( GiftCardWooEmailStyler::is_available() ) {
	if ( $effective_style === Settings::GIFT_CARD_EMAIL_STYLE_WOOCOMMERCE ) {
		$pass( 'woocommerce email style when WC mailer available' );
	} else {
		$fail( 'woocommerce email style when WC mailer available' );
	}
} elseif ( $effective_style === Settings::GIFT_CARD_EMAIL_STYLE_COMMERCE_GROWTH ) {
	$pass( 'woocommerce email style falls back when unavailable' );
} else {
	$fail( 'woocommerce email style falls back when unavailable' );
}
$settings->set_gift_card_email_style( Settings::GIFT_CARD_EMAIL_STYLE_COMMERCE_GROWTH );

$issued = $ledger->issue(
	1.0,
	function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR',
	null,
	$recipient,
	'gift-card-mail-smoke'
);
$card_id = $issued->get_card()->get_id();
if ( $card_id === null || $card_id <= 0 ) {
	$fail( 'issue gift card' );
} else {
	$pass( 'issued gift card #' . $card_id );
}

$plain = $issued->get_plain_code();
$delivery = $manual->deliver_after_issue( $issued, $recipient );
$status   = (string) ( $delivery['delivery_status'] ?? '' );

if ( in_array( $status, array( GiftCardDeliveryStatus::SENT, GiftCardDeliveryStatus::DISABLED, GiftCardDeliveryStatus::FAILED ), true ) ) {
	$pass( 'delivery attempted (' . $status . ')' );
} else {
	$fail( 'delivery attempted', 'status=' . $status );
}

$stored = ( new GiftCardManualDeliveryStore() )->get( (int) $card_id );
if ( $stored !== null && isset( $stored['delivery_status'] ) ) {
	$pass( 'delivery status recorded' );
} else {
	$fail( 'delivery status recorded' );
}

$card_row = $repo->find( (int) $card_id );
if ( $card_row !== null ) {
	$row_json = wp_json_encode( $card_row );
		if ( is_string( $row_json ) && strpos( $row_json, $plain ) === false ) {
		$pass( 'full code not persisted on card row' );
	} else {
		$fail( 'full code not persisted on card row' );
	}
}

$option_raw = get_option( GiftCardManualDeliveryStore::OPTION_KEY, array() );
$option_json = is_array( $option_raw ) ? wp_json_encode( $option_raw ) : '';
if ( is_string( $option_json ) && strpos( $option_json, $plain ) === false ) {
	$pass( 'full code not in manual delivery option' );
} else {
	$fail( 'full code not in manual delivery option' );
}

$test         = $manual->send_test_email( $recipient );
$test_status  = (string) ( $test['delivery_status'] ?? '' );
$test_attempt = in_array(
	$test_status,
	array(
		GiftCardDeliveryStatus::SENT,
		GiftCardDeliveryStatus::FAILED,
		GiftCardDeliveryStatus::DISABLED,
	),
	true
);
if ( $test_attempt ) {
	$pass( 'test email helper ran (' . $test_status . ')' );
} else {
	$fail( 'test email helper', (string) ( $test['delivery_error'] ?? '' ) );
}

$last = $manual->get_last_test_result();
if ( is_array( $last ) && isset( $last['delivery_status'] ) ) {
	$pass( 'test email result recorded' );
} else {
	$fail( 'test email result recorded' );
}

$default_resolve = ( new GiftCardEmailSender( $settings ) )->resolve_for_send( 'Smoke' );
if ( ( $default_resolve['mode'] ?? '' ) === \MP\CommercePromotions\Service\Settings::GIFT_CARD_SENDER_MODE_DEFAULT
	&& empty( $default_resolve['from_header_set'] ) ) {
	$pass( 'default sender mode omits From header' );
} else {
	$fail( 'default sender mode omits From header' );
}

$settings->set_gift_card_sender_mode( \MP\CommercePromotions\Service\Settings::GIFT_CARD_SENDER_MODE_CUSTOM );
$settings->set_gift_card_sender_email( 'invalid-address' );
$invalid_resolve = ( new GiftCardEmailSender( $settings ) )->resolve_for_send( 'Smoke' );
if ( ( $invalid_resolve['mode'] ?? '' ) === \MP\CommercePromotions\Service\Settings::GIFT_CARD_SENDER_MODE_DEFAULT ) {
	$pass( 'invalid custom sender falls back to default' );
} else {
	$fail( 'invalid custom sender falls back to default' );
}

$diag = ( new GiftCardEmailSender( $settings ) )->analyze();
if ( isset( $diag['sender_mode'] ) && isset( $diag['effective_sender_mode'] ) ) {
	$pass( 'diagnostics sender keys present' );
} else {
	$fail( 'diagnostics sender keys present' );
}

$settings->set_gift_card_sender_mode( Settings::GIFT_CARD_SENDER_MODE_DEFAULT );
$settings->set_gift_card_sender_email( '' );
if ( $settings->gift_card_sender_mode() !== Settings::GIFT_CARD_SENDER_MODE_DEFAULT ) {
	$fail( 'restore default sender mode after smoke' );
} else {
	$pass( 'restore default sender mode after smoke' );
}

echo "=== Done; failures: {$failures} ===\n";
exit( $failures > 0 ? 1 : 0 );
