<?php
/**
 * WooCommerce admin menu: Promotions module (list, settings, future children).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Woo\WooCommerceBridge;

final class AdminMenu {

	/**
	 * Parent slug under WooCommerce (module root). Matches list page slug by design.
	 */
	public const PARENT_SLUG = 'mp-commerce-promotions';

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

		if ( $this->promotions_page === null && $this->settings_page === null ) {
			return;
		}

		$this->register_promotions_parent_under_woocommerce();

		if ( $this->promotions_page !== null ) {
			$this->register_all_promotions_submenu();
		}

		if ( $this->settings_page !== null ) {
			$this->register_settings_submenu();
		}
	}

	/**
	 * Top-level WooCommerce item: Promotions (module root).
	 */
	private function register_promotions_parent_under_woocommerce(): void {
		$callback = $this->resolve_parent_menu_callback();

		add_submenu_page(
			'woocommerce',
			__( 'Promotions', 'mp-commerce-promotions' ),
			__( 'Promotions', 'mp-commerce-promotions' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			$callback
		);
	}

	/**
	 * Child: All Promotions (same slug as parent list screen; standard WP submenu pattern).
	 */
	private function register_all_promotions_submenu(): void {
		if ( $this->promotions_page === null ) {
			return;
		}

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'All Promotions', 'mp-commerce-promotions' ),
			__( 'All Promotions', 'mp-commerce-promotions' ),
			self::CAPABILITY,
			self::LIST_PAGE_SLUG,
			array( $this->promotions_page, 'render' )
		);
	}

	/**
	 * Child: Settings (`mp-commerce-promotions-settings`).
	 */
	private function register_settings_submenu(): void {
		if ( $this->settings_page === null ) {
			return;
		}

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings', 'mp-commerce-promotions' ),
			__( 'Settings', 'mp-commerce-promotions' ),
			self::CAPABILITY,
			SettingsPage::PAGE_SLUG,
			array( $this->settings_page, 'render' )
		);
	}

	/**
	 * Parent menu click targets the list when available; otherwise settings.
	 *
	 * @return callable-string|array{0: object, 1: string}
	 */
	private function resolve_parent_menu_callback() {
		if ( $this->promotions_page !== null ) {
			return array( $this->promotions_page, 'render' );
		}

		if ( $this->settings_page !== null ) {
			return array( $this->settings_page, 'render' );
		}

		return '__return_null';
	}
}
