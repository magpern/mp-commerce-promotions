<?php
/**
 * Gift Cards & Store Credit in-tab sections (sub-navigation).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class GiftCardModuleSections {

	public const QUERY_ARG = 'gift_cards_section';

	public const SECTION_DASHBOARD = 'dashboard';

	public const SECTION_GIFT_CARDS = 'gift-cards';

	public const SECTION_STORE_CREDIT = 'store-credit';

	public const SECTION_SETTINGS = 'settings';

	/** @deprecated Use gift_cards_section=gift-cards */
	public const LEGACY_PANEL_ARG = 'mp_cp_gc_panel';

	/** @deprecated */
	public const LEGACY_PANEL_GIFT_CARDS = 'gift_cards';

	/** @deprecated */
	public const LEGACY_PANEL_STORE_CREDIT = 'store_credit';

	/**
	 * @return list<string>
	 */
	public static function allowed_sections(): array {
		return array(
			self::SECTION_DASHBOARD,
			self::SECTION_GIFT_CARDS,
			self::SECTION_STORE_CREDIT,
			self::SECTION_SETTINGS,
		);
	}

	public static function default_section(): string {
		return self::SECTION_DASHBOARD;
	}

	/**
	 * @param self::SECTION_*|string|null $section
	 */
	public static function normalize_section( ?string $section ): string {
		if ( $section === null || $section === '' ) {
			return self::default_section();
		}

		$section = sanitize_key( $section );
		if ( in_array( $section, self::allowed_sections(), true ) ) {
			return $section;
		}

		return self::default_section();
	}

	public static function current_section(): string {
		if ( isset( $_GET[ self::QUERY_ARG ] ) ) {
			return self::normalize_section( wp_unslash( (string) $_GET[ self::QUERY_ARG ] ) );
		}

		if ( isset( $_GET[ self::LEGACY_PANEL_ARG ] ) ) {
			$legacy = sanitize_key( wp_unslash( (string) $_GET[ self::LEGACY_PANEL_ARG ] ) );
			if ( $legacy === self::LEGACY_PANEL_STORE_CREDIT ) {
				return self::SECTION_STORE_CREDIT;
			}
			if ( $legacy === self::LEGACY_PANEL_GIFT_CARDS ) {
				return self::SECTION_GIFT_CARDS;
			}
		}

		if ( isset( $_GET['gift_card_id'] ) && (int) $_GET['gift_card_id'] > 0 ) {
			return self::SECTION_GIFT_CARDS;
		}

		return self::default_section();
	}

	/**
	 * @param self::SECTION_*|string $section
	 * @param array<string, string|int> $extra
	 */
	public static function section_url( string $section, array $extra = array() ): string {
		$query = array_merge(
			array(
				'page'                    => AdminNavigation::PAGE_SLUG,
				'tab'                     => AdminNavigation::TAB_GIFT_CARDS,
				self::QUERY_ARG           => self::normalize_section( $section ),
			),
			$extra
		);

		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/**
	 * @param self::SECTION_*|string $active
	 */
	public static function render_sub_nav( string $active ): void {
		$active = self::normalize_section( $active );
		$tabs   = array(
			self::SECTION_DASHBOARD    => __( 'Dashboard', 'mp-commerce-promotions' ),
			self::SECTION_GIFT_CARDS   => __( 'Gift cards', 'mp-commerce-promotions' ),
			self::SECTION_STORE_CREDIT => __( 'Store credit', 'mp-commerce-promotions' ),
			self::SECTION_SETTINGS   => __( 'Settings', 'mp-commerce-promotions' ),
		);

		echo '<nav class="nav-tab-wrapper" style="margin:1em 0;" aria-label="'
			. esc_attr__( 'Gift Cards & Store Credit sections', 'mp-commerce-promotions' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$class = 'nav-tab' . ( $active === $key ? ' nav-tab-active' : '' );
			printf(
				'<a href="%1$s" class="%2$s">%3$s</a>',
				esc_url( self::section_url( $key ) ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}
}
