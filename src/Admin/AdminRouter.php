<?php
/**
 * Central WooCommerce admin router for Promotions (tab query arg dispatch).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class AdminRouter {

	private ?PromotionsPage $promotions_page;

	private SettingsPage $settings_page;

	private GettingStartedPage $getting_started_page;

	private ?DiagnosticsPage $diagnostics_page;

	private ?ReportsPage $reports_page;

	private ?CampaignBuilderPage $campaign_builder_page;

	public function __construct(
		?PromotionsPage $promotions_page,
		SettingsPage $settings_page,
		GettingStartedPage $getting_started_page,
		?DiagnosticsPage $diagnostics_page = null,
		?ReportsPage $reports_page = null,
		?CampaignBuilderPage $campaign_builder_page = null
	) {
		$this->promotions_page         = $promotions_page;
		$this->settings_page           = $settings_page;
		$this->getting_started_page    = $getting_started_page;
		$this->diagnostics_page        = $diagnostics_page;
		$this->reports_page            = $reports_page;
		$this->campaign_builder_page   = $campaign_builder_page;
	}

	public function register_legacy_redirects(): void {
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_pages' ) );
	}

	public function maybe_redirect_legacy_pages(): void {
		if ( ! is_admin() || ! isset( $_GET['page'] ) ) {
			return;
		}

		$page = sanitize_text_field( wp_unslash( (string) $_GET['page'] ) );

		if ( $page === AdminNavigation::LEGACY_PAGE_SETTINGS ) {
			wp_safe_redirect( AdminNavigation::tab_url( AdminNavigation::TAB_SETTINGS ) );
			exit;
		}

		if ( $page === AdminNavigation::LEGACY_PAGE_DIAGNOSTICS ) {
			wp_safe_redirect( AdminNavigation::tab_url( AdminNavigation::TAB_DIAGNOSTICS ) );
			exit;
		}
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		if ( $this->has_promotion_edit_request() && $this->promotions_page !== null ) {
			$this->promotions_page->render();
			return;
		}

		$tab = AdminNavigation::get_current_tab();

		switch ( $tab ) {
			case AdminNavigation::TAB_GETTING_STARTED:
				$this->getting_started_page->render();
				return;

			case AdminNavigation::TAB_SETTINGS:
				$this->settings_page->render();
				return;

			case AdminNavigation::TAB_DIAGNOSTICS:
				if ( $this->diagnostics_page !== null ) {
					$this->diagnostics_page->render();
					return;
				}
				break;

			case AdminNavigation::TAB_REPORTS:
				if ( $this->reports_page !== null ) {
					$this->reports_page->render();
					return;
				}
				break;

			case AdminNavigation::TAB_CAMPAIGN_BUILDER:
				if ( $this->campaign_builder_page !== null ) {
					$this->campaign_builder_page->render();
					return;
				}
				break;

			case AdminNavigation::TAB_ALL:
			default:
				break;
		}

		if ( $this->promotions_page !== null ) {
			$this->promotions_page->render();
			return;
		}

		$this->settings_page->render();
	}

	private function has_promotion_edit_request(): bool {
		if ( ! isset( $_GET['promotion'] ) ) {
			return false;
		}

		$key = sanitize_text_field( wp_unslash( (string) $_GET['promotion'] ) );

		return $key !== '';
	}
}
