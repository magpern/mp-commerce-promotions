<?php
/**
 * Placeholder: Gift Cards & Store Credit module (future release).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class GiftCardsPage {

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		echo '<div class="wrap mp-cg-gift-cards-wrap">';
		echo '<header class="mp-cg-gift-cards-header">';
		echo '<div class="mp-cg-gift-cards-header__title-row" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px;">';
		echo '<h1 style="margin:0;">' . esc_html__( 'Gift Cards & Store Credit', 'mp-commerce-promotions' ) . '</h1>';
		echo '<span class="mp-cg-coming-soon-badge" style="display:inline-block;padding:2px 10px;border-radius:4px;background:#f0f0f1;color:#50575e;font-size:12px;font-weight:600;">'
			. esc_html__( 'Coming soon', 'mp-commerce-promotions' ) . '</span>';
		echo '</div>';
		echo '</header>';

		AdminNavigation::render_tabs( AdminNavigation::TAB_GIFT_CARDS );

		echo '<p class="description" style="max-width:720px;">' . esc_html__(
			'Sell gift cards, issue store credit, accept partial payments, refund to credit, and manage balances in a secure ledger — all from Commerce Growth.',
			'mp-commerce-promotions'
		) . '</p>';

		echo '<ul style="max-width:720px;list-style:disc;margin-left:1.5em;">';
		$features = array(
			__( 'Sell gift cards to customers', 'mp-commerce-promotions' ),
			__( 'Issue store credit for service recovery or loyalty', 'mp-commerce-promotions' ),
			__( 'Apply credit as partial payment at checkout', 'mp-commerce-promotions' ),
			__( 'Refund orders to store credit instead of the original payment method', 'mp-commerce-promotions' ),
			__( 'Track balances in a secure, auditable ledger', 'mp-commerce-promotions' ),
		);
		foreach ( $features as $feature ) {
			echo '<li>' . esc_html( $feature ) . '</li>';
		}
		echo '</ul>';

		echo '<p class="notice notice-info inline" style="display:inline-block;margin:16px 0;"><strong>'
			. esc_html__( 'This module is planned for a future release.', 'mp-commerce-promotions' )
			. '</strong></p>';

		echo '<p style="margin-top:24px;"><strong>' . esc_html__( 'Continue with', 'mp-commerce-promotions' ) . '</strong></p>';
		echo '<p>';
		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a> ',
			esc_url( AdminUrl::tab( AdminNavigation::TAB_CAMPAIGN_BUILDER ) ),
			esc_html__( 'Campaign Builder', 'mp-commerce-promotions' )
		);
		printf(
			'<a class="button" href="%1$s">%2$s</a> ',
			esc_url( AdminUrl::tab( AdminNavigation::TAB_ALL ) ),
			esc_html__( 'Advanced Promotions', 'mp-commerce-promotions' )
		);
		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( AdminUrl::tab( AdminNavigation::TAB_REPORTS ) ),
			esc_html__( 'Reports', 'mp-commerce-promotions' )
		);
		echo '</p>';

		echo '</div>';
	}
}
