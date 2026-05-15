<?php
/**
 * WooCommerce admin submenu: Promotions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Woo\WooCommerceBridge;

final class AdminMenu {

	private const MENU_PRIORITY = 99;

	private WooCommerceBridge $woo_bridge;

	private ?PromotionsPage $promotions_page;

	public function __construct( WooCommerceBridge $woo_bridge, ?PromotionsPage $promotions_page = null ) {
		$this->woo_bridge       = $woo_bridge;
		$this->promotions_page = $promotions_page;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), self::MENU_PRIORITY );
	}

	public function register_menu(): void {
		if ( ! $this->woo_bridge->is_available() ) {
			return;
		}

		if ( $this->promotions_page === null ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Commerce Promotions', 'mp-commerce-promotions' ),
			__( 'Promotions', 'mp-commerce-promotions' ),
			'manage_woocommerce',
			'mp-commerce-promotions',
			array( $this->promotions_page, 'render' )
		);
	}
}
