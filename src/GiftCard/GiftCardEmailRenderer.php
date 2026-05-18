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
	 *   site_name?: string,
	 *   store_url?: string
	 * } $params
	 */
	public static function render( Settings $settings, array $params ): string {
		$slug = isset( $params['template_slug'] )
			? GiftCardEmailTemplate::normalize_slug( (string) $params['template_slug'] )
			: $settings->gift_card_email_template();

		$appearance = $settings->resolve_gift_card_email_appearance( $slug );

		$site_name = isset( $params['site_name'] ) && (string) $params['site_name'] !== ''
			? (string) $params['site_name']
			: self::site_name();

		$store_url = isset( $params['store_url'] )
			? (string) $params['store_url']
			: ( function_exists( 'home_url' ) ? home_url( '/' ) : '' );

		$context = array(
			'site_name'    => $site_name,
			'store_url'    => $store_url,
			'order_id'     => (int) ( $params['order_id'] ?? 0 ),
			'accent'       => (string) ( $params['accent'] ?? $appearance['accent_color'] ),
			'logo_url'     => (string) ( $params['logo_url'] ?? $appearance['logo_url'] ),
			'footer_text'  => (string) ( $params['footer_text'] ?? $appearance['footer_text'] ),
			'support_text' => (string) ( $params['support_text'] ?? $appearance['support_text'] ),
			'cards'        => (array) ( $params['cards'] ?? array() ),
			'preview'      => ! empty( $params['preview'] ),
			'is_test'      => ! empty( $params['is_test'] ),
			'manual_issue' => ! empty( $params['manual_issue'] ),
		);

		$style = $settings->effective_gift_card_email_style();

		if ( $style === Settings::GIFT_CARD_EMAIL_STYLE_WOOCOMMERCE && GiftCardWooEmailStyler::is_available() ) {
			$inner = GiftCardEmailTemplate::render_inner_html( $slug, $context, true );
			$heading = GiftCardEmailTemplate::email_heading( $context );

			return GiftCardWooEmailStyler::apply( $inner, $heading );
		}

		return GiftCardEmailTemplate::render_html( $slug, $context );
	}

	private static function site_name(): string {
		if ( function_exists( 'wp_specialchars_decode' ) ) {
			$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			if ( $name !== '' ) {
				return $name;
			}
		}

		return 'Store';
	}
}
