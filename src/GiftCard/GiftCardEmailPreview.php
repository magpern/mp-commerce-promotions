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
	 */
	public static function render( Settings $settings, ?string $template_slug = null, ?float $amount = null, ?string $currency = null ): string {
		$slug = $template_slug !== null && $template_slug !== ''
			? GiftCardEmailTemplate::normalize_slug( $template_slug )
			: $settings->gift_card_email_template();

		$currency = $currency !== null && $currency !== ''
			? sanitize_text_field( $currency )
			: ( function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR' );

		$amount = $amount !== null && $amount > 0 ? (float) $amount : self::DEFAULT_SAMPLE_AMOUNT;

		$appearance = $settings->resolve_gift_card_email_appearance( $slug );

		return GiftCardEmailRenderer::render(
			$settings,
			array(
				'template_slug' => $slug,
				'cards'         => array(
					array(
						'masked_code' => self::SAMPLE_MASKED_CODE,
						'amount'      => $amount,
						'currency'    => $currency,
						'expires_at'  => null,
					),
				),
				'order_id'      => 0,
				'preview'       => true,
				'is_test'       => false,
				'manual_issue'  => false,
				'accent'        => $appearance['accent_color'],
				'logo_url'      => $appearance['logo_url'],
				'footer_text'   => $appearance['footer_text'],
				'support_text'  => $appearance['support_text'],
			)
		);
	}
}
