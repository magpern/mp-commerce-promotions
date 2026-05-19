<?php
/**
 * Production gift card email copy defaults and smoke-string cleanup.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardEmailCopyDefaults {

	/**
	 * Exact smoke/test strings mapped to production defaults (conservative migration).
	 *
	 * @return array<string, string>
	 */
	public static function known_smoke_replacements(): array {
		return array(
			'Smoke persist subject'        => GiftCardEmailPlaceholders::default_subject(),
			'Smoke persist heading'        => GiftCardEmailPlaceholders::default_heading(),
			'Smoke heading {amount}'       => GiftCardEmailPlaceholders::default_heading(),
			'Smoke body with sample only.' => GiftCardEmailPlaceholders::default_intro(),
			'Smoke persist support'        => GiftCardEmailPlaceholders::default_support_text(),
		);
	}

	/**
	 * Replace only when the stored value exactly matches a known smoke string.
	 */
	public static function replace_known_smoke_string( string $value ): string {
		$map = self::known_smoke_replacements();

		return $map[ $value ] ?? $value;
	}

	public static function is_known_smoke_string( string $value ): bool {
		return array_key_exists( $value, self::known_smoke_replacements() );
	}
}
