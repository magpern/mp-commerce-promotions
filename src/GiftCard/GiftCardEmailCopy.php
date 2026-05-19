<?php
/**
 * Resolves merchant gift card email copy (with placeholders).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;

final class GiftCardEmailCopy {

	/**
	 * @param array<string, string> $variables
	 * @param array<string, string>|null $overrides Raw strings from settings form (unsaved).
	 * @return array{
	 *   subject: string,
	 *   heading: string,
	 *   intro: string,
	 *   redeem_instructions: string,
	 *   footer_text: string,
	 *   support_text: string
	 * }
	 */
	public static function resolve( Settings $settings, array $variables, ?array $overrides = null ): array {
		$subject = self::raw_or_setting(
			$overrides,
			'subject',
			$settings->gift_card_email_subject(),
			GiftCardEmailPlaceholders::default_subject()
		);
		$heading = self::raw_or_setting(
			$overrides,
			'heading',
			$settings->gift_card_email_heading(),
			GiftCardEmailPlaceholders::default_heading()
		);
		$intro = self::raw_or_setting(
			$overrides,
			'intro',
			$settings->gift_card_email_intro(),
			GiftCardEmailPlaceholders::default_intro()
		);
		$redeem = self::raw_or_setting(
			$overrides,
			'redeem_instructions',
			$settings->gift_card_email_redeem_instructions(),
			GiftCardEmailPlaceholders::default_redeem_instructions()
		);
		$footer = self::raw_or_setting(
			$overrides,
			'footer_text',
			$settings->gift_card_email_footer_text(),
			GiftCardEmailPlaceholders::default_footer_text()
		);
		$support = self::raw_or_setting(
			$overrides,
			'support_text',
			$settings->gift_card_support_email_text(),
			''
		);

		return array(
			'subject'             => GiftCardEmailPlaceholders::replace( $subject, $variables ),
			'heading'             => GiftCardEmailPlaceholders::replace( $heading, $variables ),
			'intro'               => GiftCardEmailPlaceholders::replace( $intro, $variables ),
			'redeem_instructions' => GiftCardEmailPlaceholders::replace( $redeem, $variables ),
			'footer_text'         => GiftCardEmailPlaceholders::replace( $footer, $variables ),
			'support_text'        => GiftCardEmailPlaceholders::replace( $support, $variables ),
		);
	}

	/**
	 * Build overrides array from admin POST (unsaved settings form).
	 *
	 * @return array<string, string>|null
	 */
	public static function overrides_from_post(): ?array {
		$map = array(
			'subject'             => 'mp_cp_gift_card_email_subject',
			'heading'             => 'mp_cp_gift_card_email_heading',
			'intro'               => 'mp_cp_gift_card_email_intro',
			'redeem_instructions' => 'mp_cp_gift_card_email_redeem_instructions',
			'footer_text'         => 'mp_cp_gift_card_email_footer_text',
			'support_text'        => 'mp_cp_gift_card_support_email_text',
			'logo_url'            => 'mp_cp_gift_card_logo_url',
			'accent_color'        => 'mp_cp_gift_card_accent_color',
			'email_style'         => 'mp_cp_gift_card_email_style',
		);

		$out = array();
		foreach ( $map as $key => $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$raw = wp_unslash( (string) $_POST[ $field ] );
			if ( $key === 'logo_url' ) {
				$out[ $key ] = esc_url_raw( $raw );
			} elseif ( $key === 'accent_color' || $key === 'email_style' ) {
				$out[ $key ] = sanitize_text_field( $raw );
			} else {
				$out[ $key ] = sanitize_textarea_field( $raw );
			}
		}

		return $out === array() ? null : $out;
	}

	/**
	 * @param array<string, string>|null $overrides
	 */
	private static function raw_or_setting( ?array $overrides, string $key, string $from_settings, string $default ): string {
		if ( $overrides !== null && array_key_exists( $key, $overrides ) ) {
			$raw = trim( $overrides[ $key ] );
			return $raw !== '' ? $raw : $default;
		}

		$stored = trim( $from_settings );
		return $stored !== '' ? $stored : $default;
	}
}
