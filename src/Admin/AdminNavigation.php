<?php
/**
 * In-page admin navigation (nav tabs) for Promotions module screens.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class AdminNavigation {

	public const PAGE_SLUG = 'mp-commerce-promotions';

	public const TAB_ALL = 'all';

	public const TAB_SETTINGS = 'settings';

	public const TAB_DIAGNOSTICS = 'diagnostics';

	public const TAB_REPORTS = 'reports';

	/** @deprecated Legacy submenu slug; use tab=settings. */
	public const LEGACY_PAGE_SETTINGS = 'mp-commerce-promotions-settings';

	/** @deprecated Legacy submenu slug; use tab=diagnostics. */
	public const LEGACY_PAGE_DIAGNOSTICS = 'mp-commerce-promotions-diagnostics';

	/**
	 * @return list<string>
	 */
	public static function allowed_tabs(): array {
		return array(
			self::TAB_ALL,
			self::TAB_SETTINGS,
			self::TAB_DIAGNOSTICS,
			self::TAB_REPORTS,
		);
	}

	public static function sanitize_tab( ?string $tab ): string {
		if ( $tab === null || $tab === '' ) {
			return self::TAB_ALL;
		}

		$tab = sanitize_key( $tab );
		if ( in_array( $tab, self::allowed_tabs(), true ) ) {
			return $tab;
		}

		return self::TAB_ALL;
	}

	public static function get_current_tab(): string {
		if ( isset( $_GET['tab'] ) ) {
			$raw = wp_unslash( (string) $_GET['tab'] );
			return self::sanitize_tab( $raw );
		}

		return self::TAB_ALL;
	}

	public static function tab_url( string $tab ): string {
		return AdminUrl::tab( $tab );
	}

	/**
	 * @param self::TAB_*|null $active_tab
	 */
	public static function render_tabs( ?string $active_tab = null ): void {
		$active = $active_tab ?? self::get_current_tab();

		$tabs = array(
			self::TAB_ALL         => array(
				'label' => __( 'All Promotions', 'mp-commerce-promotions' ),
				'url'   => self::tab_url( self::TAB_ALL ),
			),
			self::TAB_SETTINGS    => array(
				'label' => __( 'Settings', 'mp-commerce-promotions' ),
				'url'   => self::tab_url( self::TAB_SETTINGS ),
			),
			self::TAB_DIAGNOSTICS => array(
				'label' => __( 'Diagnostics', 'mp-commerce-promotions' ),
				'url'   => self::tab_url( self::TAB_DIAGNOSTICS ),
			),
			self::TAB_REPORTS     => array(
				'label' => __( 'Reports', 'mp-commerce-promotions' ),
				'url'   => self::tab_url( self::TAB_REPORTS ),
			),
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Promotions navigation', 'mp-commerce-promotions' ) . '">';

		foreach ( $tabs as $tab_key => $tab ) {
			$class = 'nav-tab';
			if ( $tab_key === $active ) {
				$class .= ' nav-tab-active';
			}

			printf(
				'<a href="%1$s" class="%2$s">%3$s</a>',
				esc_url( $tab['url'] ),
				esc_attr( $class ),
				esc_html( $tab['label'] )
			);
		}

		echo '</nav>';
	}
}
