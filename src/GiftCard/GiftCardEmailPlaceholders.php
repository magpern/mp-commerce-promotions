<?php
/**
 * Placeholder replacement for gift card email copy.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;

final class GiftCardEmailPlaceholders {

	/**
	 * @return list<string>
	 */
	public static function supported_keys(): array {
		return array(
			'site_title',
			'amount',
			'currency',
			'code',
			'expiry',
			'recipient_name',
			'purchaser_name',
			'message',
			'store_url',
		);
	}

	public static function default_subject(): string {
		return __( 'Your gift card from {site_title}', 'mp-commerce-promotions' );
	}

	public static function default_heading(): string {
		return __( 'You received a gift card', 'mp-commerce-promotions' );
	}

	public static function default_intro(): string {
		return __( 'A gift card has been sent to you and can be used on any eligible products in our store.', 'mp-commerce-promotions' );
	}

	public static function default_redeem_instructions(): string {
		return __( 'Enter your gift card code during checkout in the “Gift card or store credit” section.', 'mp-commerce-promotions' );
	}

	public static function default_footer_text(): string {
		return __( 'Keep this email safe. The full code is required at checkout and is not stored after delivery.', 'mp-commerce-promotions' );
	}

	public static function default_support_text(): string {
		return __( 'Questions? Contact our support team.', 'mp-commerce-promotions' );
	}

	/**
	 * Plain-text price for emails and placeholders (no HTML entities).
	 */
	public static function format_amount_display( float $amount, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			$html  = wc_price( $amount, array( 'currency' => $currency ) );
			$plain = wp_strip_all_tags( (string) $html );

			return html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$formatted = number_format( $amount, 2, ',', ' ' );

		return $currency !== '' ? $formatted . ' ' . $currency : $formatted;
	}

	/**
	 * @param array<string, string> $variables
	 */
	public static function replace( string $text, array $variables ): string {
		if ( $text === '' ) {
			return '';
		}

		$search  = array();
		$replace = array();
		foreach ( self::supported_keys() as $key ) {
			$search[]  = '{' . $key . '}';
			$replace[] = (string) ( $variables[ $key ] ?? '' );
		}

		return str_replace( $search, $replace, $text );
	}

	/**
	 * @param array<string, mixed> $card
	 * @return array<string, string>
	 */
	public static function variables_for_card(
		Settings $settings,
		array $card,
		bool $preview = false,
		bool $is_test = false
	): array {
		$site_title = self::site_title();
		$store_url  = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';

		$amount   = (float) ( $card['amount'] ?? 0 );
		$currency = (string) ( $card['currency'] ?? '' );
		$code     = $preview
			? (string) ( $card['masked_code'] ?? GiftCardEmailPreview::SAMPLE_MASKED_CODE )
			: ( $is_test ? GiftCardManualIssueDelivery::TEST_SAMPLE_CODE : (string) ( $card['plain_code'] ?? '' ) );

		$amount_display = self::format_amount_display( $amount, $currency );

		return array(
			'site_title'      => $site_title,
			'amount'          => $amount_display,
			'currency'        => $currency,
			'code'            => $code,
			'expiry'          => (string) ( $card['expires_at'] ?? '' ),
			'recipient_name'  => trim( (string) ( $card['recipient_name'] ?? '' ) ),
			'purchaser_name'  => trim( (string) ( $card['purchaser_name'] ?? '' ) ),
			'message'         => trim( (string) ( $card['message'] ?? '' ) ),
			'store_url'       => $store_url,
		);
	}

	/**
	 * Sample variables for admin preview (never a real code).
	 *
	 * @return array<string, string>
	 */
	public static function preview_variables( Settings $settings, float $amount, string $currency ): array {
		return self::variables_for_card(
			$settings,
			array(
				'masked_code'    => GiftCardEmailPreview::SAMPLE_MASKED_CODE,
				'amount'         => $amount,
				'currency'       => $currency,
				'expires_at'     => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
				'recipient_name' => __( 'Sample Recipient', 'mp-commerce-promotions' ),
				'purchaser_name' => __( 'Sample Purchaser', 'mp-commerce-promotions' ),
				'message'        => __( 'Enjoy your gift!', 'mp-commerce-promotions' ),
			),
			true,
			false
		);
	}

	public static function site_title(): string {
		if ( function_exists( 'wp_specialchars_decode' ) ) {
			$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			if ( $name !== '' ) {
				return $name;
			}
		}

		return 'Store';
	}
}
