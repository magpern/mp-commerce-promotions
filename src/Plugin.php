<?php
/**
 * Central plugin coordinator.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions;

use MP\CommercePromotions\Admin\AdminMenu;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Woo\WooCommerceBridge;
use wpdb;

final class Plugin {

	private WooCommerceBridge $woo_bridge;

	private AdminMenu $admin_menu;

	private ?PromotionRepository $promotion_repository = null;

	public function __construct() {
		global $wpdb;
		if ( $wpdb instanceof wpdb ) {
			$this->promotion_repository = new PromotionRepository( $wpdb );
		}

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
