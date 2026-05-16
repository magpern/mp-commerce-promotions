<?php
/**
 * WooCommerce admin: read-only promotion usage diagnostics.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Service\UsageDiagnostics;

final class DiagnosticsPage {

	public const PAGE_SLUG = 'mp-commerce-promotions-diagnostics';

	private UsageDiagnostics $diagnostics;

	public function __construct( UsageDiagnostics $diagnostics ) {
		$this->diagnostics = $diagnostics;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$report = $this->diagnostics->analyze();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Promotion Diagnostics', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_DIAGNOSTICS );
		echo '<p>' . esc_html__( 'Read-only comparison of stored usage_count values against redemption and order-meta records. No automatic repair is available.', 'mp-commerce-promotions' ) . '</p>';

		$this->render_promotions_table( $report['promotions'] );
		$this->render_codes_table( $report['codes'] );

		echo '</div>';
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function render_promotions_table( array $rows ): void {
		echo '<h2>' . esc_html__( 'Promotion usage', 'mp-commerce-promotions' ) . '</h2>';

		if ( count( $rows ) === 0 ) {
			echo '<p>' . esc_html__( 'No diagnostics available.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		$headers = array(
			__( 'ID', 'mp-commerce-promotions' ),
			__( 'Name', 'mp-commerce-promotions' ),
			__( 'Stored usage', 'mp-commerce-promotions' ),
			__( 'Recorded redemptions', 'mp-commerce-promotions' ),
			__( 'Reversed redemptions', 'mp-commerce-promotions' ),
			__( 'Expected usage', 'mp-commerce-promotions' ),
			__( 'Status', 'mp-commerce-promotions' ),
		);
		foreach ( $headers as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$matches = ! empty( $row['matches'] );
			echo '<tr>';
			echo '<td>' . esc_html( (string) (int) $row['promotion_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['name'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['stored_usage_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['computed_recorded_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['computed_reversed_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['expected_usage_count'] ) . '</td>';
			echo '<td>';
			if ( $matches ) {
				echo esc_html__( 'OK', 'mp-commerce-promotions' );
			} else {
				echo '<strong>' . esc_html__( 'Mismatch', 'mp-commerce-promotions' ) . '</strong>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function render_codes_table( array $rows ): void {
		echo '<h2>' . esc_html__( 'Promotion code usage', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Expected usage counts recorded redemptions whose order meta _mp_cp_promotion_code_id matches the code id.', 'mp-commerce-promotions' ) . '</p>';

		if ( count( $rows ) === 0 ) {
			echo '<p>' . esc_html__( 'No diagnostics available.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		$headers = array(
			__( 'Code ID', 'mp-commerce-promotions' ),
			__( 'Promotion ID', 'mp-commerce-promotions' ),
			__( 'Last 4', 'mp-commerce-promotions' ),
			__( 'Stored usage', 'mp-commerce-promotions' ),
			__( 'Expected usage', 'mp-commerce-promotions' ),
			__( 'Status', 'mp-commerce-promotions' ),
		);
		foreach ( $headers as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$matches = ! empty( $row['matches'] );
			echo '<tr>';
			echo '<td>' . esc_html( (string) (int) $row['code_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['promotion_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['last4'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['stored_usage_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['expected_usage_count'] ) . '</td>';
			echo '<td>';
			if ( $matches ) {
				echo esc_html__( 'OK', 'mp-commerce-promotions' );
			} else {
				echo '<strong>' . esc_html__( 'Mismatch', 'mp-commerce-promotions' ) . '</strong>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
