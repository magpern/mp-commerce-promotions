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

	public function __construct( WooCommerceBridge $woo_bridge ) {
		$this->woo_bridge = $woo_bridge;
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
			__( 'Commerce Promotions', 'mp-commerce-promotions' ),
			__( 'Promotions', 'mp-commerce-promotions' ),
			'manage_woocommerce',
			'mp-commerce-promotions',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Commerce Promotions', 'mp-commerce-promotions' ) . '</h1>';
		echo '<p>' . esc_html__( 'Commerce Promotions engine loaded.', 'mp-commerce-promotions' ) . '</p>';
		echo '</div>';
	}
}
