<?php
/**
 * Central plugin coordinator.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions;

use MP\CommercePromotions\Admin\AdminMenu;
use MP\CommercePromotions\Woo\WooCommerceBridge;

final class Plugin {

	private WooCommerceBridge $woo_bridge;

	private AdminMenu $admin_menu;

	public function __construct() {
		$this->woo_bridge = new WooCommerceBridge();
		$this->admin_menu = new AdminMenu( $this->woo_bridge );
	}

	public function init(): void {
		$this->woo_bridge->init();

		if ( is_admin() ) {
			$this->admin_menu->register();
		}
	}
}
