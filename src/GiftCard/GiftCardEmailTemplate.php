<?php
/**
 * Single classic gift card email layout (merchant copy from settings).
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
	 *   email_heading?: string,
	 *   intro_text?: string,
	 *   redeem_instructions?: string,
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
		unset( $template_slug );
		$accent = self::sanitize_color( (string) ( $context['accent'] ?? '#2271b1' ) );
		$store  = \esc_url( (string) ( $context['store_url'] ?? '' ) );
		$logo   = \esc_url( (string) ( $context['logo_url'] ?? '' ) );
		$heading = \esc_html( self::heading_text( $context ) );

		$logo_html = $logo !== ''
			? '<img src="' . $logo . '" alt="" data-mp-cp-email="logo" style="max-height:48px;margin-bottom:12px;" />'
			: '<img src="" alt="" data-mp-cp-email="logo" style="max-height:48px;margin-bottom:12px;display:none;" />';

		$inner = self::render_body_content( $context, $accent, false );

		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f6f7f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f7;padding:24px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden;">'
			. '<tr><td data-mp-cp-email-accent="header" style="background:' . \esc_attr( $accent ) . ';color:#ffffff;padding:24px;text-align:center;">'
			. $logo_html
			. '<h1 data-mp-cp-email="heading" style="margin:0;font-size:22px;font-weight:600;">' . $heading . '</h1>'
			. '</td></tr>'
			. '<tr><td style="padding:24px;">'
			. $inner
			. ( $store !== '' ? '<p style="margin:12px 0 0;"><a href="' . $store . '" style="color:' . \esc_attr( $accent ) . ';">' . \esc_html__( 'Visit store', 'mp-commerce-promotions' ) . '</a></p>' : '' )
			. '</td></tr></table></td></tr></table></body></html>';
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function render_inner_html( string $template_slug, array $context, bool $use_woo_colors = false ): string {
		unset( $template_slug );
		$accent = self::sanitize_color( (string) ( $context['accent'] ?? '#2271b1' ) );
		if ( $use_woo_colors ) {
			$woo    = GiftCardWooEmailStyler::woo_colors();
			$accent = $woo['base'];
		}

		return self::render_body_content( $context, $accent, $use_woo_colors );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function email_heading( array $context ): string {
		return self::heading_text( $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function heading_text( array $context ): string {
		$heading = trim( (string) ( $context['email_heading'] ?? '' ) );
		if ( $heading !== '' ) {
			return $heading;
		}

		return \__( 'You received a gift card', 'mp-commerce-promotions' );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function render_body_content( array $context, string $accent, bool $use_woo_colors ): string {
		$text    = $use_woo_colors ? GiftCardWooEmailStyler::woo_colors()['text'] : '#1d2327';
		$muted   = '#646970';
		$cards_html = '';
		foreach ( (array) ( $context['cards'] ?? array() ) as $card ) {
			$cards_html .= self::card_block_html( $card, $accent, ! empty( $context['preview'] ) );
		}

		$intro  = self::paragraph_html( self::intro_text( $context ), 'intro', $text );
		$redeem = self::paragraph_html( self::redeem_text( $context ), 'redeem', $text, '14px' );

		$footer_html  = self::paragraph_html( (string) ( $context['footer_text'] ?? '' ), 'footer', $muted, '13px', true );
		$footer_html .= self::paragraph_html( (string) ( $context['support_text'] ?? '' ), 'support', $muted, '13px', true, '8px 0 0' );

		return $intro
			. $cards_html
			. $redeem
			. $footer_html;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function intro_text( array $context ): string {
		$intro = trim( (string) ( $context['intro_text'] ?? '' ) );
		if ( $intro === '' ) {
			$intro = GiftCardEmailPlaceholders::default_intro();
		}

		$order_id = isset( $context['order_id'] ) ? (int) $context['order_id'] : 0;
		if ( $order_id > 0 && empty( $context['preview'] ) && empty( $context['is_test'] ) ) {
			$intro .= ' ' . sprintf(
				/* translators: %d: order ID */
				\__( '(Order #%d)', 'mp-commerce-promotions' ),
				$order_id
			);
		}

		return $intro;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function redeem_text( array $context ): string {
		$redeem = trim( (string) ( $context['redeem_instructions'] ?? '' ) );

		return $redeem !== '' ? $redeem : GiftCardEmailPlaceholders::default_redeem_instructions();
	}

	/**
	 * @param array<string, mixed> $card
	 */
	private static function card_block_html( array $card, string $accent, bool $preview ): string {
		$code = $preview
			? (string) ( $card['masked_code'] ?? GiftCardEmailPreview::SAMPLE_MASKED_CODE )
			: (string) ( $card['plain_code'] ?? '' );
		if ( $preview && $code === '' ) {
			$code = GiftCardEmailPreview::SAMPLE_MASKED_CODE;
		}

		$amount_str = GiftCardEmailPlaceholders::format_amount_display(
			(float) ( $card['amount'] ?? 0 ),
			(string) ( $card['currency'] ?? '' )
		);

		$bits = array();
		$recipient = trim( (string) ( $card['recipient_name'] ?? '' ) );
		if ( $recipient !== '' ) {
			$bits[] = '<strong>' . \esc_html__( 'To', 'mp-commerce-promotions' ) . ':</strong> <span data-mp-cp-email="recipient">' . \esc_html( $recipient ) . '</span>';
		}
		$purchaser = trim( (string) ( $card['purchaser_name'] ?? '' ) );
		if ( $purchaser !== '' ) {
			$bits[] = '<strong>' . \esc_html__( 'From', 'mp-commerce-promotions' ) . ':</strong> <span data-mp-cp-email="purchaser">' . \esc_html( $purchaser ) . '</span>';
		}
		$message = trim( (string) ( $card['message'] ?? '' ) );
		if ( $message !== '' ) {
			$bits[] = '<em data-mp-cp-email="message" style="display:block;margin-top:8px;">' . nl2br( \esc_html( $message ), false ) . '</em>';
		}

		$meta = '<p style="margin:8px 0 0;font-size:14px;color:#50575e;">'
			. \esc_html__( 'Amount', 'mp-commerce-promotions' ) . ': <strong data-mp-cp-email="amount">' . \esc_html( $amount_str ) . '</strong>';
		if ( ! empty( $card['expires_at'] ) ) {
			$meta .= '<br />' . \esc_html__( 'Expires', 'mp-commerce-promotions' ) . ': <span data-mp-cp-email="expiry">' . \esc_html( (string) $card['expires_at'] ) . '</span>';
		}
		$meta .= '</p>';

		return '<div data-mp-cp-email="card" style="border:1px solid #dcdcde;border-left:4px solid ' . \esc_attr( $accent ) . ';border-radius:4px;padding:16px;margin:0 0 16px;">'
			. implode( '<br />', $bits )
			. '<p style="margin:12px 0 0;font-size:18px;font-family:monospace;letter-spacing:1px;color:#1d2327;"><strong data-mp-cp-email="code">' . \esc_html( $code ) . '</strong></p>'
			. $meta
			. '</div>';
	}

	public static function normalize_slug( string $slug ): string {
		return Settings::normalize_gift_card_email_template_slug( $slug );
	}

	private static function paragraph_html(
		string $text,
		string $data_attr,
		string $color,
		string $font_size = '15px',
		bool $allow_empty_hidden = false,
		string $margin = '16px 0 0'
	): string {
		$trimmed = trim( $text );
		if ( $trimmed === '' ) {
			if ( ! $allow_empty_hidden ) {
				return '';
			}

			return '<p data-mp-cp-email="' . \esc_attr( $data_attr ) . '" style="margin:' . \esc_attr( $margin )
				. ';font-size:' . \esc_attr( $font_size ) . ';line-height:1.5;color:' . \esc_attr( $color ) . ';display:none;"></p>';
		}

		$margin_attr = $data_attr === 'intro' ? 'margin:0 0 16px' : 'margin:' . $margin;

		return '<p data-mp-cp-email="' . \esc_attr( $data_attr ) . '" style="' . \esc_attr( $margin_attr )
			. ';font-size:' . \esc_attr( $font_size ) . ';line-height:1.5;color:' . \esc_attr( $color ) . ';">'
			. nl2br( \esc_html( $trimmed ), false ) . '</p>';
	}

	private static function sanitize_color( string $color ): string {
		$color = trim( $color );
		if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ) {
			return '#2271b1';
		}

		return $color;
	}
}
