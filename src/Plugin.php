<?php
/**
 * Central plugin coordinator.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions;

use MP\CommercePromotions\Admin\AdminMenu;
use MP\CommercePromotions\Admin\PromotionsPage;
use MP\CommercePromotions\Domain\AuditLogRepository;
use MP\CommercePromotions\Domain\PromotionFactory;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Woo\WooCommerceBridge;
use wpdb;

final class Plugin {

	private WooCommerceBridge $woo_bridge;

	private AdminMenu $admin_menu;

	private ?PromotionRepository $promotion_repository = null;

	private ?PromotionFactory $promotion_factory = null;

	private ?AuditLogRepository $audit_log_repository = null;

	private ?AuditLogger $audit_logger = null;

	private ?PromotionService $promotion_service = null;

	public function __construct() {
		global $wpdb;
		if ( $wpdb instanceof wpdb ) {
			$this->promotion_repository = new PromotionRepository( $wpdb );
			$this->audit_log_repository   = new AuditLogRepository( $wpdb );
			$this->audit_logger           = new AuditLogger( $this->audit_log_repository );
			$this->promotion_factory      = new PromotionFactory();

			$this->promotion_service = new PromotionService(
				$this->promotion_repository,
				$this->promotion_factory,
				$this->audit_logger
			);
		}

		$promotions_page = null;
		if ( $this->promotion_repository !== null ) {
			$promotions_page = new PromotionsPage( $this->promotion_repository );
		}

		$this->woo_bridge = new WooCommerceBridge();
		$this->admin_menu = new AdminMenu( $this->woo_bridge, $promotions_page );
	}

	public function init(): void {
		$this->woo_bridge->init();

		if ( is_admin() ) {
			$this->admin_menu->register();
		}
	}
}
