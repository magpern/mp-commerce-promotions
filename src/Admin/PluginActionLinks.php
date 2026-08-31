<?php
/**
 * Plugins screen action links.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class PluginActionLinks {

	public const CAPABILITY = 'manage_woocommerce';

	public function register(): void {
		add_filter(
			'plugin_action_links_' . plugin_basename( MP_COMMERCE_PROMOTIONS_FILE ),
			array( $this, 'links' )
		);
	}

	/**
	 * @param array<string, string> $links Existing links.
	 * @return array<string, string>
	 */
	public function links( $links ): array {
		if ( ! is_array( $links ) ) {
			$links = array();
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return $links;
		}

		$extra = array(
			'promotions' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( AdminUrl::list_promotions() ),
				esc_html__( 'Promotions', 'mp-commerce-promotions' )
			),
			'settings'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( AdminUrl::settings() ),
				esc_html__( 'Settings', 'mp-commerce-promotions' )
			),
		);

		return array_merge( $extra, $links );
	}
}
