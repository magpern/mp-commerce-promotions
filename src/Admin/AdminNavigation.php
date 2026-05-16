<?php
/**
 * In-page admin navigation (nav tabs) for Promotions module screens.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class AdminNavigation {

	public const TAB_ALL_PROMOTIONS = 'mp-commerce-promotions';

	public const TAB_SETTINGS = 'mp-commerce-promotions-settings';

	/**
	 * @param self::TAB_* $active_slug
	 */
	public static function render_tabs( string $active_slug ): void {
		$tabs = array(
			self::TAB_ALL_PROMOTIONS => array(
				'label' => __( 'All Promotions', 'mp-commerce-promotions' ),
				'url'   => admin_url( 'admin.php?page=' . self::TAB_ALL_PROMOTIONS ),
			),
			self::TAB_SETTINGS       => array(
				'label' => __( 'Settings', 'mp-commerce-promotions' ),
				'url'   => admin_url( 'admin.php?page=' . self::TAB_SETTINGS ),
			),
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Promotions navigation', 'mp-commerce-promotions' ) . '">';

		foreach ( $tabs as $slug => $tab ) {
			$class = 'nav-tab';
			if ( $slug === $active_slug ) {
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
