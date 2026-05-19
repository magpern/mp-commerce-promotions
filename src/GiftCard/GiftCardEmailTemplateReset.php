<?php
/**
 * Reset gift card email template fields to production defaults (not sender/delivery).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;

final class GiftCardEmailTemplateReset {

	/**
	 * @return array<string, string>
	 */
	public static function production_defaults(): array {
		return array(
			'subject'             => GiftCardEmailPlaceholders::default_subject(),
			'heading'             => GiftCardEmailPlaceholders::default_heading(),
			'intro'               => GiftCardEmailPlaceholders::default_intro(),
			'redeem_instructions' => GiftCardEmailPlaceholders::default_redeem_instructions(),
			'footer_text'         => GiftCardEmailPlaceholders::default_footer_text(),
			'support_text'        => GiftCardEmailPlaceholders::default_support_text(),
			'logo_url'            => '',
			'accent_color'        => Settings::resolve_default_gift_card_accent_color(),
			'email_style'         => Settings::GIFT_CARD_EMAIL_STYLE_COMMERCE_GROWTH,
		);
	}

	public function apply( Settings $settings ): void {
		$defaults = self::production_defaults();

		$settings->set_gift_card_email_subject( $defaults['subject'] );
		$settings->set_gift_card_email_heading( $defaults['heading'] );
		$settings->set_gift_card_email_intro( $defaults['intro'] );
		$settings->set_gift_card_email_redeem_instructions( $defaults['redeem_instructions'] );
		$settings->set_gift_card_email_footer_text( $defaults['footer_text'] );
		$settings->set_gift_card_support_email_text( $defaults['support_text'] );
		$settings->set_gift_card_logo_url( $defaults['logo_url'] );
		$settings->reset_gift_card_accent_color_to_default();
		$settings->set_gift_card_email_style( $defaults['email_style'] );
		$settings->set_gift_card_email_template( Settings::GIFT_CARD_TEMPLATE_CLASSIC );
	}
}
