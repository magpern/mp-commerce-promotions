<?php
/**
 * Onboarding: Getting Started tab.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Service\Settings;

final class GettingStartedPage {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Getting Started', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_GETTING_STARTED );

		echo '<div class="card" style="max-width:900px;padding:16px 20px;margin:16px 0;">';
		echo '<h2>' . esc_html__( 'What this plugin does', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>' . esc_html__(
			'Commerce Promotions is a rule-driven promotion engine for WooCommerce. Define conditions, actions, and restrictions, then evaluate eligible promotions on each cart totals pass. Promotion codes use the standard coupon field with virtual coupons (discounts come from this plugin).',
			'mp-commerce-promotions'
		) . '</p>';
		echo '</div>';

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Quick setup checklist', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ol style="max-width:720px;">';
		echo '<li>' . esc_html__( 'Confirm WooCommerce is active and cart discounts are enabled in Settings.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Create a draft promotion or apply a template on the edit screen.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Use cart preview to validate eligibility and planner output.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Activate when ready; monitor Reports and Diagnostics.', 'mp-commerce-promotions' ) . '</li>';
		echo '</ol>';

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Recommended first templates', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		echo '<li>' . esc_html__( 'Percentage off whole cart (minimum subtotal)', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Free shipping over threshold', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'First-order discount for new customers', 'mp-commerce-promotions' ) . '</li>';
		echo '</ul>';

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Admin shortcuts', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>';
		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a> ',
			esc_url( AdminUrl::tab( AdminNavigation::TAB_CAMPAIGN_BUILDER ) ),
			esc_html__( 'Campaign Builder', 'mp-commerce-promotions' )
		);
		printf(
			'<a class="button" href="%1$s">%2$s</a> ',
			esc_url( AdminUrl::list_promotions() ),
			esc_html__( 'All Promotions', 'mp-commerce-promotions' )
		);
		printf(
			'<a class="button" href="%1$s">%2$s</a> ',
			esc_url( AdminUrl::tab( AdminNavigation::TAB_REPORTS ) ),
			esc_html__( 'Reports', 'mp-commerce-promotions' )
		);
		printf(
			'<a class="button" href="%1$s">%2$s</a> ',
			esc_url( AdminUrl::diagnostics() ),
			esc_html__( 'Diagnostics', 'mp-commerce-promotions' )
		);
		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( AdminUrl::settings() ),
			esc_html__( 'Settings', 'mp-commerce-promotions' )
		);
		echo '</p>';

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Current capabilities', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		echo '<li>' . esc_html__( 'Stackable and exclusive promotions with planner orchestration', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Promotion codes (hashed storage), batches, redemptions', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Reports, diagnostics, simulation, and allocation explainability', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'HPOS compatibility declared', 'mp-commerce-promotions' ) . '</li>';
		echo '</ul>';

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Known limitations (beta)', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		echo '<li>' . esc_html__( 'Discounts apply as cart fees — not per-line catalog price changes.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Cart/Checkout Blocks compatibility is not declared yet.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Allocation engine is reporting metadata; storefront fees remain authoritative.', 'mp-commerce-promotions' ) . '</li>';
		echo '</ul>';

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Suggested workflow', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ol style="max-width:720px;">';
		echo '<li>' . esc_html__( 'Create draft', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Apply template', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Preview cart', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Activate', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Monitor reports and diagnostics', 'mp-commerce-promotions' ) . '</li>';
		echo '</ol>';

		$flags = $this->settings->to_feature_flags();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Feature gates (current)', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		foreach ( $flags as $key => $enabled ) {
			printf(
				'<li><code>%1$s</code>: %2$s</li>',
				esc_html( (string) $key ),
				esc_html( $enabled ? __( 'enabled', 'mp-commerce-promotions' ) : __( 'disabled', 'mp-commerce-promotions' ) )
			);
		}
		echo '</ul>';

		echo '</div>';
	}
}
