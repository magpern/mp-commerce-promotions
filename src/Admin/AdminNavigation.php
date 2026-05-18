<?php
/**
 * In-page admin navigation (nav tabs) for the Commerce Growth admin shell.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class AdminNavigation {

	public const PAGE_SLUG = 'mp-commerce-promotions';

	public const TAB_ALL = 'all';

	public const TAB_GETTING_STARTED = 'getting-started';

	public const TAB_CAMPAIGN_BUILDER = 'campaign-builder';

	public const TAB_SETTINGS = 'settings';

	public const TAB_DIAGNOSTICS = 'diagnostics';

	public const TAB_REPORTS = 'reports';

	public const TAB_GIFT_CARDS = 'gift-cards';

	/** Default landing tab when `tab` is omitted (merchant entrypoint). */
	public const DEFAULT_TAB = self::TAB_CAMPAIGN_BUILDER;

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
			self::TAB_GETTING_STARTED,
			self::TAB_CAMPAIGN_BUILDER,
			self::TAB_SETTINGS,
			self::TAB_DIAGNOSTICS,
			self::TAB_REPORTS,
			self::TAB_GIFT_CARDS,
		);
	}

	/**
	 * Single source of truth for tab routing and nav highlighting.
	 *
	 * @param self::TAB_*|string|null $tab Raw tab from the request (may be missing, empty, or invalid).
	 */
	public static function normalize_tab( ?string $tab ): string {
		if ( $tab === null || $tab === '' ) {
			return self::DEFAULT_TAB;
		}

		$tab = sanitize_key( $tab );
		if ( in_array( $tab, self::allowed_tabs(), true ) ) {
			return $tab;
		}

		return self::DEFAULT_TAB;
	}

	/**
	 * @param self::TAB_*|string|null $tab
	 */
	public static function sanitize_tab( ?string $tab ): string {
		return self::normalize_tab( $tab );
	}

	public static function get_current_tab(): string {
		$raw = null;
		if ( isset( $_GET['tab'] ) ) {
			$raw = wp_unslash( (string) $_GET['tab'] );
		}

		return self::normalize_tab( $raw );
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
			self::TAB_CAMPAIGN_BUILDER => array(
				'label' => __( 'Campaign Builder', 'mp-commerce-promotions' ),
				'title' => __( 'Recommended — guided campaign creation', 'mp-commerce-promotions' ),
				'url'   => self::tab_url( self::TAB_CAMPAIGN_BUILDER ),
			),
			self::TAB_ALL              => array(
				'label' => __( 'Advanced Promotions', 'mp-commerce-promotions' ),
				'title' => __( 'Expert mode — raw rules, orchestration, and diagnostics', 'mp-commerce-promotions' ),
				'url'   => self::tab_url( self::TAB_ALL ),
			),
			self::TAB_GIFT_CARDS       => array(
				'label' => __( 'Gift Cards & Store Credit', 'mp-commerce-promotions' ),
				'title' => __( 'Coming soon — gift cards and store credit ledger', 'mp-commerce-promotions' ),
				'url'   => self::tab_url( self::TAB_GIFT_CARDS ),
			),
			self::TAB_REPORTS          => array(
				'label' => __( 'Reports', 'mp-commerce-promotions' ),
				'url'   => self::tab_url( self::TAB_REPORTS ),
			),
			self::TAB_DIAGNOSTICS      => array(
				'label' => __( 'Diagnostics', 'mp-commerce-promotions' ),
				'url'   => self::tab_url( self::TAB_DIAGNOSTICS ),
			),
			self::TAB_SETTINGS         => array(
				'label' => __( 'Settings', 'mp-commerce-promotions' ),
				'url'   => self::tab_url( self::TAB_SETTINGS ),
			),
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Commerce Growth navigation', 'mp-commerce-promotions' ) . '">';

		foreach ( $tabs as $tab_key => $tab ) {
			$class = 'nav-tab';
			if ( $tab_key === $active ) {
				$class .= ' nav-tab-active';
			}

			$title = isset( $tab['title'] ) ? (string) $tab['title'] : '';
			if ( $title !== '' ) {
				printf(
					'<a href="%1$s" class="%2$s" title="%3$s">%4$s</a>',
					esc_url( $tab['url'] ),
					esc_attr( $class ),
					esc_attr( $title ),
					esc_html( $tab['label'] )
				);
			} else {
				printf(
					'<a href="%1$s" class="%2$s">%3$s</a>',
					esc_url( $tab['url'] ),
					esc_attr( $class ),
					esc_html( $tab['label'] )
				);
			}
		}

		echo '</nav>';
	}

	/**
	 * Primary CTA linking to Campaign Builder (recommended merchant workflow).
	 *
	 * @param array{class?: string, label?: string} $args
	 */
	public static function render_create_campaign_button( array $args = array() ): void {
		$class = isset( $args['class'] ) ? (string) $args['class'] : 'button button-primary';
		$label = isset( $args['label'] ) ? (string) $args['label'] : __( 'Create campaign', 'mp-commerce-promotions' );

		printf(
			'<a class="%1$s" href="%2$s">%3$s</a>',
			esc_attr( $class ),
			esc_url( AdminUrl::create_campaign() ),
			esc_html( $label )
		);
	}
}
