<?php
/**
 * Read-only WooCommerce submenu: promotions list.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;

final class PromotionsPage {

	private PromotionRepository $promotions;

	public function __construct( PromotionRepository $promotions ) {
		$this->promotions = $promotions;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$list = $this->promotions->find_all( 100, 0 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Commerce Promotions', 'mp-commerce-promotions' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only list of promotions from the database. Create, edit, and delete are not available in this version.', 'mp-commerce-promotions' ) . '</p>';

		if ( count( $list ) === 0 ) {
			echo '<p>' . esc_html__( 'No promotions found.', 'mp-commerce-promotions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		$headers = array(
			__( 'ID', 'mp-commerce-promotions' ),
			__( 'Name', 'mp-commerce-promotions' ),
			__( 'Status', 'mp-commerce-promotions' ),
			__( 'Priority', 'mp-commerce-promotions' ),
			__( 'Usage', 'mp-commerce-promotions' ),
			__( 'Starts', 'mp-commerce-promotions' ),
			__( 'Ends', 'mp-commerce-promotions' ),
			__( 'Created', 'mp-commerce-promotions' ),
		);
		foreach ( $headers as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $list as $promo ) {
			if ( ! $promo instanceof Promotion ) {
				continue;
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $promo->get_id() ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $promo->get_name() ) . '</td>';
			echo '<td>' . esc_html( $promo->get_status() ) . '</td>';
			echo '<td>' . esc_html( (string) $promo->get_priority() ) . '</td>';
			echo '<td>' . esc_html( $this->format_usage( $promo ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $promo->get_starts_at() ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $promo->get_ends_at() ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $promo->get_created_at() ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function format_usage( Promotion $promo ): string {
		$used = $promo->get_usage_count();
		$lim  = $promo->get_usage_limit();

		if ( $lim === null ) {
			return sprintf(
				/* translators: 1: usage count */
				__( '%1$s / —', 'mp-commerce-promotions' ),
				(string) $used
			);
		}

		return sprintf(
			/* translators: 1: usage count, 2: usage limit */
			__( '%1$s / %2$s', 'mp-commerce-promotions' ),
			(string) $used,
			(string) $lim
		);
	}

	private function format_datetime( ?string $value ): string {
		if ( $value === null || $value === '' ) {
			return '—';
		}

		return $value;
	}
}
