<?php
/**
 * Central plugin coordinator.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions;

use MP\CommercePromotions\Admin\AdminMenu;
use MP\CommercePromotions\Admin\DiagnosticsPage;
use MP\CommercePromotions\Admin\PromotionEditPage;
use MP\CommercePromotions\Admin\PromotionsPage;
use MP\CommercePromotions\Admin\SettingsPage;
use MP\CommercePromotions\Domain\AuditLogRepository;
use MP\CommercePromotions\Domain\PromotionCodeFactory;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionFactory;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Service\UsageDiagnostics;
use MP\CommercePromotions\Woo\CartContextBuilder;
use MP\CommercePromotions\Woo\CartPromotionApplier;
use MP\CommercePromotions\Woo\OrderPromotionRecorder;
use MP\CommercePromotions\Woo\PromotionCodeCouponBridge;
use MP\CommercePromotions\Woo\WooCommerceBridge;
use wpdb;

final class Plugin {

	private WooCommerceBridge $woo_bridge;

	private ?AdminMenu $admin_menu = null;

	private PromotionEvaluator $promotion_evaluator;

	private ?PromotionRepository $promotion_repository = null;

	private ?PromotionFactory $promotion_factory = null;

	private ?AuditLogRepository $audit_log_repository = null;

	private ?AuditLogger $audit_logger = null;

	private ?PromotionService $promotion_service = null;

	private ?RedemptionRepository $redemption_repository = null;

	private ?PromotionCodeRepository $promotion_code_repository = null;

	private Settings $settings;

	public function __construct() {
		$this->settings = new Settings();
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

			$this->redemption_repository   = new RedemptionRepository( $wpdb );
			$this->promotion_code_repository = new PromotionCodeRepository( $wpdb );
		}

		$this->woo_bridge           = new WooCommerceBridge();
		$this->promotion_evaluator = new PromotionEvaluator();
	}

	public function init(): void {
		$this->woo_bridge->init();

		$cart_builder = null;
		if ( $this->woo_bridge->is_available() && $this->promotion_repository !== null && $this->promotion_code_repository !== null ) {
			$cart_builder = new CartContextBuilder();
			$this->woo_bridge->set_cart_context_builder( $cart_builder );

			$coupon_bridge = new PromotionCodeCouponBridge( $this->promotion_code_repository );
			$this->woo_bridge->set_promotion_code_coupon_bridge( $coupon_bridge );

			$cart_applier = new CartPromotionApplier(
				$this->promotion_repository,
				$this->promotion_code_repository,
				$this->promotion_evaluator,
				$cart_builder,
				$this->settings
			);
			$this->woo_bridge->set_cart_promotion_applier( $cart_applier );

			if ( $this->redemption_repository !== null && $this->audit_logger !== null ) {
				$order_recorder = new OrderPromotionRecorder(
					$this->redemption_repository,
					$this->promotion_repository,
					$this->promotion_code_repository,
					$this->audit_logger
				);
				$this->woo_bridge->set_order_promotion_recorder( $order_recorder );
			}
		} elseif ( $this->woo_bridge->is_available() ) {
			$cart_builder = new CartContextBuilder();
			$this->woo_bridge->set_cart_context_builder( $cart_builder );
		}

		$promotions_page = null;
		if ( $this->promotion_repository !== null && $this->promotion_service !== null ) {
			$rule_validator = new PromotionRuleValidator();
			$code_factory     = new PromotionCodeFactory();
			$edit_page        = new PromotionEditPage(
				$this->promotion_repository,
				$this->promotion_service,
				$cart_builder,
				$this->promotion_evaluator,
				$this->redemption_repository,
				$this->audit_log_repository,
				$rule_validator,
				$this->promotion_code_repository,
				$code_factory
			);
			$promotions_page = new PromotionsPage( $this->promotion_repository, $this->promotion_service, $edit_page );
		}

		$settings_page = new SettingsPage( $this->settings );

		$diagnostics_page = null;
		if (
			$this->promotion_repository !== null
			&& $this->promotion_code_repository !== null
			&& $this->redemption_repository !== null
		) {
			$usage_diagnostics = new UsageDiagnostics(
				$this->promotion_repository,
				$this->promotion_code_repository,
				$this->redemption_repository,
				$this->audit_logger
			);
			$diagnostics_page = new DiagnosticsPage( $usage_diagnostics );
		}

		$this->admin_menu = new AdminMenu( $this->woo_bridge, $promotions_page, $settings_page, $diagnostics_page );

		if ( is_admin() && $this->admin_menu !== null ) {
			$this->admin_menu->register();
		}
	}
}
