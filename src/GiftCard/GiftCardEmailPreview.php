<?php
/**
 * Admin/product preview for gift card emails (sample code only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\Settings;

final class GiftCardEmailPreview {

	public const SAMPLE_MASKED_CODE = '****SAMPLE';

	public const DEFAULT_SAMPLE_AMOUNT = 25.0;

	/**
	 * Render HTML preview for settings or product page (never uses a real gift card code).
	 *
	 * @param array<string, string>|null $copy_overrides Unsaved admin form values.
	 */
	public static function render(
		Settings $settings,
		?string $template_slug = null,
		?float $amount = null,
		?string $currency = null,
		?array $copy_overrides = null
	): string {
		unset( $template_slug );

		$currency = $currency !== null && $currency !== ''
			? sanitize_text_field( $currency )
			: ( function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR' );

		$amount = $amount !== null && $amount > 0 ? (float) $amount : self::DEFAULT_SAMPLE_AMOUNT;

		$slug       = Settings::GIFT_CARD_TEMPLATE_CLASSIC;
		$appearance = $settings->resolve_gift_card_email_appearance( $slug );

		$sample = GiftCardEmailPlaceholders::preview_variables( $settings, $amount, $currency );

		return GiftCardEmailRenderer::render(
			$settings,
			array(
				'template_slug'  => $slug,
				'cards'          => array(
					array(
						'masked_code'    => self::SAMPLE_MASKED_CODE,
						'amount'         => $amount,
						'currency'       => $currency,
						'expires_at'     => $sample['expiry'],
						'recipient_name' => $sample['recipient_name'],
						'purchaser_name' => $sample['purchaser_name'],
						'message'        => $sample['message'],
					),
				),
				'order_id'       => 0,
				'preview'        => true,
				'is_test'        => false,
				'manual_issue'   => false,
				'accent'         => $appearance['accent_color'],
				'logo_url'       => $appearance['logo_url'],
				'copy_overrides' => $copy_overrides,
				'email_style'    => isset( $copy_overrides['email_style'] )
					? (string) $copy_overrides['email_style']
					: $settings->effective_gift_card_email_style(),
			)
		);
	}
}
