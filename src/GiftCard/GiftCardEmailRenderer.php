<?php
/**
 * Builds gift card delivery HTML (Commerce Growth or WooCommerce email style).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;

final class GiftCardEmailRenderer {

	/**
	 * @param array{
	 *   template_slug?: string,
	 *   cards: list<array<string, mixed>>,
	 *   order_id?: int,
	 *   preview?: bool,
	 *   is_test?: bool,
	 *   manual_issue?: bool,
	 *   accent?: string,
	 *   logo_url?: string,
	 *   footer_text?: string,
	 *   support_text?: string,
	 *   email_style?: string,
	 *   copy_overrides?: array<string, string>|null,
	 *   site_name?: string,
	 *   store_url?: string
	 * } $params
	 */
	public static function render( Settings $settings, array $params ): string {
		$slug = GiftCardEmailTemplate::normalize_slug(
			isset( $params['template_slug'] ) ? (string) $params['template_slug'] : $settings->gift_card_email_template()
		);

		$appearance = $settings->resolve_gift_card_email_appearance( $slug );
		$overrides  = isset( $params['copy_overrides'] ) && is_array( $params['copy_overrides'] )
			? $params['copy_overrides']
			: null;

		if ( $overrides !== null ) {
			if ( isset( $overrides['logo_url'] ) && $overrides['logo_url'] !== '' ) {
				$appearance['logo_url'] = $overrides['logo_url'];
			}
			if ( isset( $overrides['accent_color'] ) && $overrides['accent_color'] !== '' ) {
				$appearance['accent_color'] = $overrides['accent_color'];
			}
			if ( isset( $overrides['footer_text'] ) ) {
				$appearance['footer_text'] = $overrides['footer_text'];
			}
			if ( isset( $overrides['support_text'] ) ) {
				$appearance['support_text'] = $overrides['support_text'];
			}
		}

		$site_name = isset( $params['site_name'] ) && (string) $params['site_name'] !== ''
			? (string) $params['site_name']
			: GiftCardEmailPlaceholders::site_title();

		$store_url = isset( $params['store_url'] )
			? (string) $params['store_url']
			: ( function_exists( 'home_url' ) ? home_url( '/' ) : '' );

		$cards    = (array) ( $params['cards'] ?? array() );
		$preview  = ! empty( $params['preview'] );
		$is_test  = ! empty( $params['is_test'] );
		$first    = $cards[0] ?? array();
		$variables = GiftCardEmailPlaceholders::variables_for_card(
			$settings,
			$first,
			$preview,
			$is_test
		);

		if ( $preview && empty( $first['recipient_name'] ) ) {
			$sample = GiftCardEmailPlaceholders::preview_variables(
				$settings,
				(float) ( $first['amount'] ?? GiftCardEmailPreview::DEFAULT_SAMPLE_AMOUNT ),
				(string) ( $first['currency'] ?? 'EUR' )
			);
			$variables = array_merge( $variables, $sample );
		}

		$copy = GiftCardEmailCopy::resolve( $settings, $variables, $overrides );

		$context = array(
			'site_name'            => $site_name,
			'store_url'            => $store_url,
			'order_id'             => (int) ( $params['order_id'] ?? 0 ),
			'email_heading'        => $copy['heading'],
			'intro_text'           => $copy['intro'],
			'redeem_instructions'  => $copy['redeem_instructions'],
			'accent'               => (string) ( $params['accent'] ?? $appearance['accent_color'] ),
			'logo_url'             => (string) ( $params['logo_url'] ?? $appearance['logo_url'] ),
			'footer_text'          => $copy['footer_text'],
			'support_text'         => $copy['support_text'],
			'cards'                => $cards,
			'preview'              => $preview,
			'is_test'              => $is_test,
			'manual_issue'         => ! empty( $params['manual_issue'] ),
		);

		$style = isset( $params['email_style'] ) && $params['email_style'] !== ''
			? sanitize_key( (string) $params['email_style'] )
			: $settings->effective_gift_card_email_style();

		if ( $style === Settings::GIFT_CARD_EMAIL_STYLE_WOOCOMMERCE && GiftCardWooEmailStyler::is_available() ) {
			$inner   = GiftCardEmailTemplate::render_inner_html( $slug, $context, true );
			$heading = GiftCardEmailTemplate::email_heading( $context );

			return GiftCardWooEmailStyler::apply( $inner, $heading );
		}

		return GiftCardEmailTemplate::render_html( $slug, $context );
	}

	/**
	 * Resolved subject line for delivery (placeholders applied).
	 *
	 * @param array<string, string>|null $copy_overrides
	 */
	public static function resolve_subject(
		Settings $settings,
		array $card,
		bool $preview = false,
		bool $is_test = false,
		?array $copy_overrides = null
	): string {
		$variables = GiftCardEmailPlaceholders::variables_for_card( $settings, $card, $preview, $is_test );
		$copy      = GiftCardEmailCopy::resolve( $settings, $variables, $copy_overrides );
		$subject   = $copy['subject'];

		if ( $is_test ) {
			return sprintf(
				/* translators: %s: email subject without [Test] prefix */
				\__( '[Test] %s', 'mp-commerce-promotions' ),
				$subject
			);
		}

		return $subject;
	}
}
