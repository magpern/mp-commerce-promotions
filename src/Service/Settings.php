<?php
/**
 * Plugin settings (options API).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

final class Settings {

	public const OPTION_CART_DISCOUNTS_ENABLED = 'mp_cp_cart_discounts_enabled';

	private const VALUE_YES = 'yes';

	private const VALUE_NO = 'no';

	public function cart_discounts_enabled(): bool {
		$raw = get_option( self::OPTION_CART_DISCOUNTS_ENABLED, self::VALUE_YES );

		if ( ! is_string( $raw ) ) {
			return true;
		}

		$raw = strtolower( trim( $raw ) );

		return $raw !== self::VALUE_NO;
	}

	public function set_cart_discounts_enabled( bool $enabled ): void {
		update_option(
			self::OPTION_CART_DISCOUNTS_ENABLED,
			$enabled ? self::VALUE_YES : self::VALUE_NO,
			false
		);
	}
}
