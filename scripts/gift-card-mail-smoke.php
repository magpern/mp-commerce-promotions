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

$settings->set_gift_card_email_subject( 'Merchant QA subject line' );
$settings->set_gift_card_email_heading( 'Merchant QA heading' );
$settings->set_gift_card_support_email_text( 'Merchant QA support' );
if ( $settings->gift_card_email_subject() === 'Merchant QA subject line'
	&& $settings->gift_card_email_heading() === 'Merchant QA heading'
	&& $settings->gift_card_support_email_text() === 'Merchant QA support' ) {
	$pass( 'email settings persist via Settings API' );
} else {
	$fail( 'email settings persist via Settings API' );
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
	&& strpos( $handler_src, 'media-editor' ) !== false ) {
	$pass( 'settings screen enqueues media and color picker assets' );
} else {
	$fail( 'settings screen enqueues media and color picker assets' );
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

$settings->set_gift_card_email_heading( 'Custom preview heading' );
$settings->set_gift_card_email_intro( 'Custom preview intro text.' );
$settings->set_gift_card_accent_color( '#aa5500' );
$custom_preview = GiftCardEmailPreview::render( $settings );
if ( strpos( $custom_preview, 'Custom preview heading' ) !== false
	&& strpos( $custom_preview, 'Custom preview intro' ) !== false
	&& strpos( $custom_preview, '#aa5500' ) !== false ) {
	$pass( 'preview renders custom heading/body/accent' );
} else {
	$fail( 'preview renders custom heading/body/accent' );
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
