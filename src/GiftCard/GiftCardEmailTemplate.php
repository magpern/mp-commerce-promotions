<?php
/**
 * Gift card email visual themes (slug only; no generated images stored).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;

final class GiftCardEmailTemplate {

	/**
	 * @param array{
	 *   site_name: string,
	 *   store_url: string,
	 *   order_id?: int,
	 *   cards: list<array{
	 *     plain_code?: string,
	 *     masked_code?: string,
	 *     amount: float,
	 *     currency: string,
	 *     expires_at?: ?string,
	 *     recipient_name?: string,
	 *     purchaser_name?: string,
	 *     message?: string
	 *   }>,
	 *   accent: string,
	 *   logo_url: string,
	 *   support_text: string,
	 *   footer_text?: string,
	 *   preview?: bool
	 * } $context
	 */
	public static function render_html( string $template_slug, array $context ): string {
		$template = self::normalize_slug( $template_slug );
		$accent   = self::sanitize_color( (string) ( $context['accent'] ?? '#2271b1' ) );
		$site     = \esc_html( (string) ( $context['site_name'] ?? 'Store' ) );
		$store    = \esc_url( (string) ( $context['store_url'] ?? '' ) );
		$logo     = \esc_url( (string) ( $context['logo_url'] ?? '' ) );

		$header_bg = $template === Settings::GIFT_CARD_TEMPLATE_MINIMAL ? '#ffffff' : $accent;
		$header_fg = $template === Settings::GIFT_CARD_TEMPLATE_MINIMAL ? $accent : '#ffffff';
		$border    = $template === Settings::GIFT_CARD_TEMPLATE_HOLIDAY ? '#1a472a' : '#dcdcde';

		$logo_html = $logo !== ''
			? '<img src="' . $logo . '" alt="" style="max-height:48px;margin-bottom:12px;" />'
			: '';

		$inner = self::render_body_content( $template, $context, $accent, false );

		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f6f7f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f7;padding:24px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid ' . \esc_attr( $border ) . ';border-radius:8px;overflow:hidden;">'
			. '<tr><td style="background:' . \esc_attr( $header_bg ) . ';color:' . \esc_attr( $header_fg ) . ';padding:24px;text-align:center;">'
			. $logo_html
			. '<h1 style="margin:0;font-size:22px;font-weight:600;">' . $site . '</h1>'
			. '<p style="margin:8px 0 0;font-size:14px;opacity:0.95;">' . \esc_html( self::template_label( $template ) ) . '</p>'
			. '</td></tr>'
			. '<tr><td style="padding:24px;">'
			. $inner
			. ( $store !== '' ? '<p style="margin:12px 0 0;"><a href="' . $store . '" style="color:' . \esc_attr( $accent ) . ';">' . \esc_html__( 'Visit store', 'mp-commerce-promotions' ) . '</a></p>' : '' )
			. '</td></tr></table></td></tr></table></body></html>';
	}

	/**
	 * Inner content for WooCommerce email wrapper (no outer document).
	 *
	 * @param array<string, mixed> $context
	 */
	public static function render_inner_html( string $template_slug, array $context, bool $use_woo_colors = false ): string {
		$template = self::normalize_slug( $template_slug );
		$accent   = self::sanitize_color( (string) ( $context['accent'] ?? '#2271b1' ) );
		if ( $use_woo_colors ) {
			$woo    = GiftCardWooEmailStyler::woo_colors();
			$accent = $woo['base'];
		}

		return self::render_body_content( $template, $context, $accent, $use_woo_colors );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function email_heading( array $context ): string {
		$site = (string) ( $context['site_name'] ?? 'Store' );
		if ( ! empty( $context['preview'] ) ) {
			return sprintf(
				/* translators: %s: store name */
				\__( 'Gift card email preview — %s', 'mp-commerce-promotions' ),
				$site
			);
		}
		if ( ! empty( $context['is_test'] ) ) {
			return sprintf(
				/* translators: %s: store name */
				\__( '[Test] Your gift card from %s', 'mp-commerce-promotions' ),
				$site
			);
		}

		return sprintf(
			/* translators: %s: store name */
			\__( 'Your gift card from %s', 'mp-commerce-promotions' ),
			$site
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function render_body_content( string $template, array $context, string $accent, bool $use_woo_colors ): string {
		$preview  = ! empty( $context['preview'] );
		$text     = $use_woo_colors ? GiftCardWooEmailStyler::woo_colors()['text'] : '#1d2327';
		$muted    = $use_woo_colors ? '#646970' : '#646970';
		$support  = \esc_html( (string) ( $context['support_text'] ?? '' ) );
		$footer   = \esc_html( (string) ( $context['footer_text'] ?? '' ) );

		$cards_html = '';
		foreach ( (array) ( $context['cards'] ?? array() ) as $card ) {
			$cards_html .= self::card_block_html( $card, $accent, $preview );
		}

		$intro = self::intro_text( $context );

		$redeem = \esc_html__( 'Redeem at checkout: enter your gift card code in the “Gift card or store credit” section before placing your order.', 'mp-commerce-promotions' );

		$footer_html = '';
		if ( $footer !== '' ) {
			$footer_html .= '<p style="margin:16px 0 0;font-size:13px;color:' . \esc_attr( $muted ) . ';">' . nl2br( $footer, false ) . '</p>';
		}
		if ( $support !== '' ) {
			$footer_html .= '<p style="margin:8px 0 0;font-size:13px;color:' . \esc_attr( $muted ) . ';">' . nl2br( $support, false ) . '</p>';
		}

		return '<p style="margin:0 0 16px;font-size:15px;line-height:1.5;color:' . \esc_attr( $text ) . ';">' . $intro . '</p>'
			. $cards_html
			. '<p style="margin:16px 0 0;font-size:14px;line-height:1.5;color:' . \esc_attr( $text ) . ';">' . $redeem . '</p>'
			. $footer_html
			. '<p style="margin:20px 0 0;font-size:12px;color:' . \esc_attr( $muted ) . ';">'
			. \esc_html__( 'Keep this email safe. The full code is required at checkout and is not stored in our system after delivery.', 'mp-commerce-promotions' )
			. '</p>';
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function intro_text( array $context ): string {
		$preview      = ! empty( $context['preview'] );
		$is_test      = ! empty( $context['is_test'] );
		$manual_issue = ! empty( $context['manual_issue'] );

		$intro = $preview
			? \esc_html__( 'Preview of your gift card email.', 'mp-commerce-promotions' )
			: ( $is_test
				? \esc_html__( 'This is a test gift card email. No real gift card was created. Sample details are below.', 'mp-commerce-promotions' )
				: ( $manual_issue
					? \esc_html__( 'You have received a gift card. Your gift card details are below.', 'mp-commerce-promotions' )
					: \esc_html__( 'Thank you for your purchase. Your gift card details are below.', 'mp-commerce-promotions' ) ) );

		$order_id = isset( $context['order_id'] ) ? (int) $context['order_id'] : 0;
		if ( ! $preview && ! $is_test && ! $manual_issue && $order_id > 0 ) {
			$intro .= ' ' . \esc_html(
				sprintf(
					/* translators: %d: order ID */
					\__( '(Order #%d)', 'mp-commerce-promotions' ),
					$order_id
				)
			);
		}

		return $intro;
	}

	/**
	 * @param array<string, mixed> $card
	 */
	private static function card_block_html( array $card, string $accent, bool $preview ): string {
		$code = $preview
			? (string) ( $card['masked_code'] ?? '****SAMPLE' )
			: (string) ( $card['plain_code'] ?? '' );
		if ( $preview && $code === '' ) {
			$code = '****SAMPLE';
		}

		$amount_str = function_exists( 'wc_price' )
			? wp_strip_all_tags( wc_price( (float) ( $card['amount'] ?? 0 ), array( 'currency' => (string) ( $card['currency'] ?? '' ) ) ) )
			: number_format( (float) ( $card['amount'] ?? 0 ), 2 ) . ' ' . \esc_html( (string) ( $card['currency'] ?? '' ) );

		$bits = array();
		$recipient = trim( (string) ( $card['recipient_name'] ?? '' ) );
		if ( $recipient !== '' ) {
			$bits[] = '<strong>' . \esc_html__( 'To', 'mp-commerce-promotions' ) . ':</strong> ' . \esc_html( $recipient );
		}
		$purchaser = trim( (string) ( $card['purchaser_name'] ?? '' ) );
		if ( $purchaser !== '' ) {
			$bits[] = '<strong>' . \esc_html__( 'From', 'mp-commerce-promotions' ) . ':</strong> ' . \esc_html( $purchaser );
		}
		$message = trim( (string) ( $card['message'] ?? '' ) );
		if ( $message !== '' ) {
			$bits[] = '<em style="display:block;margin-top:8px;">' . nl2br( \esc_html( $message ), false ) . '</em>';
		}

		$meta = '<p style="margin:8px 0 0;font-size:14px;color:#50575e;">'
			. \esc_html__( 'Amount', 'mp-commerce-promotions' ) . ': <strong>' . \esc_html( $amount_str ) . '</strong>';
		if ( ! empty( $card['expires_at'] ) ) {
			$meta .= '<br />' . \esc_html__( 'Expires', 'mp-commerce-promotions' ) . ': ' . \esc_html( (string) $card['expires_at'] );
		}
		$meta .= '</p>';

		return '<div style="border:1px solid #dcdcde;border-left:4px solid ' . \esc_attr( $accent ) . ';border-radius:4px;padding:16px;margin:0 0 16px;">'
			. implode( '<br />', $bits )
			. '<p style="margin:12px 0 0;font-size:18px;font-family:monospace;letter-spacing:1px;color:#1d2327;"><strong>' . \esc_html( $code ) . '</strong></p>'
			. $meta
			. '</div>';
	}

	public static function normalize_slug( string $slug ): string {
		$slug = sanitize_key( $slug );
		if ( ! in_array( $slug, Settings::gift_card_email_templates(), true ) ) {
			return Settings::GIFT_CARD_TEMPLATE_CLASSIC;
		}

		return $slug;
	}

	public static function template_label( string $slug ): string {
		switch ( self::normalize_slug( $slug ) ) {
			case Settings::GIFT_CARD_TEMPLATE_BIRTHDAY:
				return \__( 'Birthday gift', 'mp-commerce-promotions' );
			case Settings::GIFT_CARD_TEMPLATE_HOLIDAY:
				return \__( 'Holiday gift', 'mp-commerce-promotions' );
			case Settings::GIFT_CARD_TEMPLATE_MINIMAL:
				return \__( 'Gift card', 'mp-commerce-promotions' );
			default:
				return \__( 'Your gift card', 'mp-commerce-promotions' );
		}
	}

	private static function sanitize_color( string $color ): string {
		$color = trim( $color );
		if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ) {
			return '#2271b1';
		}

		return $color;
	}
}
