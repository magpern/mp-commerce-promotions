<?php
/**
 * WooCommerce admin submenu: Commerce Growth entry (tab routing via AdminRouter).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Woo\WooCommerceBridge;

final class AdminMenu {

	public const PAGE_SLUG = AdminNavigation::PAGE_SLUG;

	private const MENU_PRIORITY = 99;

	private const CAPABILITY = 'manage_woocommerce';

	private WooCommerceBridge $woo_bridge;

	private AdminRouter $router;

	public function __construct( WooCommerceBridge $woo_bridge, AdminRouter $router ) {
		$this->woo_bridge = $woo_bridge;
		$this->router     = $router;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), self::MENU_PRIORITY );
	}

	public function register_menu(): void {
		if ( ! $this->woo_bridge->is_available() ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Commerce Growth', 'mp-commerce-promotions' ),
			__( 'Commerce Growth', 'mp-commerce-promotions' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this->router, 'render' )
		);
	}
}
