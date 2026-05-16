<?php
/**
 * WooCommerce admin: promotion usage diagnostics and manual repair.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Service\UsageDiagnostics;

final class DiagnosticsPage {

	private const NONCE_ACTION = 'mp_cp_repair_usage_counters';

	private const NONCE_FIELD = 'mp_cp_repair_usage_nonce';

	private const REPAIR_SUBMIT = 'mp_cp_repair_usage_submit';

	private UsageDiagnostics $diagnostics;

	public function __construct( UsageDiagnostics $diagnostics ) {
		$this->diagnostics = $diagnostics;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->handle_post_repair();

		$report = $this->diagnostics->analyze();

		echo '<div class="wrap">';
		$this->render_notices();
		echo '<h1>' . esc_html__( 'Promotion Diagnostics', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_DIAGNOSTICS );
		echo '<p>' . esc_html__( 'Compare stored usage_count values against redemption and order-meta records. Use the repair action to recalculate mismatched counters from recorded redemptions.', 'mp-commerce-promotions' ) . '</p>';

		$this->render_repair_form();
		$this->render_integrity_notes();
		$this->render_promotions_table( $report['promotions'] );
		$this->render_codes_table( $report['codes'] );

		echo '</div>';
	}

	private function render_integrity_notes(): void {
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Promotion integrity notes', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:1.5em;max-width:720px;">';
		echo '<li>' . esc_html__( 'Checkout recording is idempotent per order and promotion (unique redemption rows; duplicate checkout hooks do not double usage).', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Order cancellation, failure, refund, and trash/delete reverse recorded redemptions once per promotion; repeated reversal hooks are ignored.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Orders that return to processing or completed after reversal restore reversed redemption rows when applicable.', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Free gift cart lines marked mp_cp_free_gift=yes are synchronized on each totals pass (stale gifts removed, quantities normalized).', 'mp-commerce-promotions' ) . '</li>';
		echo '<li>' . esc_html__( 'Stacked promotions record separate redemption rows and applied-promotion meta entries.', 'mp-commerce-promotions' ) . '</li>';
		echo '</ul>';
	}

	private function render_repair_form(): void {
		$confirm = esc_js(
			__( 'Repair usage counters for all mismatches shown below?', 'mp-commerce-promotions' )
		);

		echo '<form method="post" action="" style="margin:1em 0;">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<p class="description">';
		echo esc_html__(
			'This recalculates stored usage counters from recorded redemptions. No rows are deleted.',
			'mp-commerce-promotions'
		);
		echo '</p>';
		echo '<p class="submit">';
		printf(
			'<button type="submit" name="%1$s" value="1" class="button button-secondary" onclick="return confirm(\'%2$s\');">%3$s</button>',
			esc_attr( self::REPAIR_SUBMIT ),
			$confirm,
			esc_html__( 'Repair Usage Counters', 'mp-commerce-promotions' )
		);
		echo '</p>';
		echo '</form>';
	}

	private function handle_post_repair(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST[ self::REPAIR_SUBMIT ] ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			$this->redirect_with_notice( 'error', 'missing_nonce' );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->redirect_with_notice( 'error', 'invalid_nonce' );
		}

		$result = $this->diagnostics->repair();

		if ( count( $result['errors'] ) > 0 ) {
			$this->redirect_with_notice(
				'error',
				'repair_partial',
				array(
					'promotions' => (int) $result['promotions_repaired'],
					'codes'      => (int) $result['codes_repaired'],
				)
			);
		}

		if ( $result['promotions_repaired'] === 0 && $result['codes_repaired'] === 0 ) {
			$this->redirect_with_notice( 'success', 'repair_none' );
		}

		$this->redirect_with_notice(
			'success',
			'repair_done',
			array(
				'promotions' => (int) $result['promotions_repaired'],
				'codes'      => (int) $result['codes_repaired'],
			)
		);
	}

	private function render_notices(): void {
		if ( ! isset( $_GET['mp_cp_diag_notice'] ) || ! isset( $_GET['mp_cp_diag_code'] ) ) {
			return;
		}

		$type = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_diag_notice'] ) );
		$code = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_diag_code'] ) );

		$promotions = isset( $_GET['mp_cp_diag_promotions'] )
			? (int) $_GET['mp_cp_diag_promotions']
			: 0;
		$codes      = isset( $_GET['mp_cp_diag_codes'] )
			? (int) $_GET['mp_cp_diag_codes']
			: 0;

		$message = $this->notice_message_for_code( $code, $promotions, $codes );
		if ( $message === '' ) {
			return;
		}

		if ( $type === 'success' ) {
			AdminNotice::success( $message );
			return;
		}

		AdminNotice::error( $message );
	}

	private function notice_message_for_code( string $code, int $promotions, int $codes ): string {
		switch ( $code ) {
			case 'repair_done':
				return sprintf(
					/* translators: 1: promotions repaired count, 2: codes repaired count */
					__( 'Usage counters repaired: %1$d promotion(s), %2$d code(s).', 'mp-commerce-promotions' ),
					$promotions,
					$codes
				);
			case 'repair_none':
				return __( 'No usage counter mismatches were found to repair.', 'mp-commerce-promotions' );
			case 'repair_partial':
				return sprintf(
					/* translators: 1: promotions repaired count, 2: codes repaired count */
					__( 'Repair completed with errors. Repaired: %1$d promotion(s), %2$d code(s). Check logs for details.', 'mp-commerce-promotions' ),
					$promotions,
					$codes
				);
			case 'missing_nonce':
			case 'invalid_nonce':
				return __( 'Security check failed. Please try again.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	/**
	 * @param array{promotions?: int, codes?: int} $counts
	 */
	private function redirect_with_notice( string $type, string $code, array $counts = array() ): void {
		$args = array(
			'mp_cp_diag_notice' => $type,
			'mp_cp_diag_code'   => $code,
		);

		if ( isset( $counts['promotions'] ) ) {
			$args['mp_cp_diag_promotions'] = (int) $counts['promotions'];
		}
		if ( isset( $counts['codes'] ) ) {
			$args['mp_cp_diag_codes'] = (int) $counts['codes'];
		}

		wp_safe_redirect( AdminUrl::diagnostics( $args ) );
		exit;
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
