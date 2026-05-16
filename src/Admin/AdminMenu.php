<?php
/**
 * WooCommerce admin submenu: Promotions list and Promotion Settings.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Woo\WooCommerceBridge;

final class AdminMenu {

	/**
	 * List / edit promotions screen (`admin.php?page=mp-commerce-promotions`).
	 */
	public const LIST_PAGE_SLUG = 'mp-commerce-promotions';

	private const MENU_PRIORITY = 99;

	private const CAPABILITY = 'manage_woocommerce';

	private WooCommerceBridge $woo_bridge;

	private ?PromotionsPage $promotions_page;

	private ?SettingsPage $settings_page;

	public function __construct(
		WooCommerceBridge $woo_bridge,
		?PromotionsPage $promotions_page = null,
		?SettingsPage $settings_page = null
	) {
		$this->woo_bridge      = $woo_bridge;
		$this->promotions_page = $promotions_page;
		$this->settings_page   = $settings_page;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), self::MENU_PRIORITY );
	}

	public function register_menu(): void {
		if ( ! $this->woo_bridge->is_available() ) {
			return;
		}

		if ( $this->promotions_page !== null ) {
			$this->register_promotions_submenu();
		}

		if ( $this->settings_page !== null ) {
			$this->register_promotion_settings_submenu();
		}
	}

	private function register_promotions_submenu(): void {
		if ( $this->promotions_page === null ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Promotions', 'mp-commerce-promotions' ),
			__( 'Promotions', 'mp-commerce-promotions' ),
			self::CAPABILITY,
			self::LIST_PAGE_SLUG,
			array( $this->promotions_page, 'render' )
		);
	}

	private function register_promotion_settings_submenu(): void {
		if ( $this->settings_page === null ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Promotion Settings', 'mp-commerce-promotions' ),
			__( 'Promotion Settings', 'mp-commerce-promotions' ),
			self::CAPABILITY,
			SettingsPage::PAGE_SLUG,
			array( $this->settings_page, 'render' )
		);
	}
}
