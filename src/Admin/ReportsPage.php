<?php
/**
 * WooCommerce admin: promotion performance reports and CSV export.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Service\PromotionReports;

final class ReportsPage {

	private const NONCE_ACTION = 'mp_cp_export_redemptions_csv';

	private const NONCE_FIELD = 'mp_cp_export_redemptions_nonce';

	private const EXPORT_SUBMIT = 'mp_cp_export_redemptions_csv';

	private PromotionReports $reports;

	public function __construct( PromotionReports $reports ) {
		$this->reports = $reports;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->maybe_send_csv_export();

		$filters = $this->filters_from_request();
		$summary = $this->reports->summary( $filters );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Promotion Reports', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_REPORTS );
		echo '<p class="description">' . esc_html__(
			'Read-only summaries from redemption records. CSV export includes up to 5,000 rows and does not expose raw promotion codes.',
			'mp-commerce-promotions'
		) . '</p>';

		$this->render_filter_form( $filters );
		$this->render_summary_cards( $summary );
		$this->render_top_promotions_table( $summary['top_promotions'] );
		$this->render_export_form( $filters );

		echo '</div>';
	}

	private function maybe_send_csv_export(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST[ self::EXPORT_SUBMIT ] ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mp-commerce-promotions' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mp-commerce-promotions' ) );
		}

		$filters = $this->filters_from_request( true );
		$csv     = $this->reports->redemptions_csv( $filters );

		$filename = 'mp-cp-redemptions-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV attachment body.
		echo $csv;
		exit;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function filters_from_request( bool $post = false ): array {
		$source = $post ? $_POST : $_GET;

		return PromotionReports::sanitize_filters(
			array(
				'date_from'    => isset( $source['date_from'] ) ? wp_unslash( (string) $source['date_from'] ) : null,
				'date_to'      => isset( $source['date_to'] ) ? wp_unslash( (string) $source['date_to'] ) : null,
				'promotion_id' => isset( $source['promotion_id'] ) ? wp_unslash( (string) $source['promotion_id'] ) : null,
				'status'       => isset( $source['status'] ) ? wp_unslash( (string) $source['status'] ) : null,
			)
		);
	}

	/**
	 * @param array{
	 *     date_from: string|null,
	 *     date_to: string|null,
	 *     promotion_id: int|null,
	 *     status: string|null
	 * } $filters
	 */
	private function render_filter_form( array $filters ): void {
		$base_url = AdminNavigation::tab_url( AdminNavigation::TAB_REPORTS );

		echo '<form method="get" action="' . esc_url( $base_url ) . '" class="mp-cp-reports-filters" style="margin:1em 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminNavigation::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( AdminNavigation::TAB_REPORTS ) . '" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="mp_cp_reports_date_from">' . esc_html__( 'Date from', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="date" id="mp_cp_reports_date_from" name="date_from" value="' . esc_attr( $filters['date_from'] ?? '' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Filters by redemption redeemed_at (inclusive).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_reports_date_to">' . esc_html__( 'Date to', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="date" id="mp_cp_reports_date_to" name="date_to" value="' . esc_attr( $filters['date_to'] ?? '' ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_reports_promotion_id">' . esc_html__( 'Promotion ID', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_reports_promotion_id" name="promotion_id" min="1" step="1" value="';
		echo $filters['promotion_id'] !== null ? esc_attr( (string) $filters['promotion_id'] ) : '';
		echo '" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_reports_status">' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<select id="mp_cp_reports_status" name="status">';
		echo '<option value="">' . esc_html__( 'All', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="' . esc_attr( Redemption::STATUS_RECORDED ) . '"';
		selected( $filters['status'] ?? '', Redemption::STATUS_RECORDED );
		echo '>' . esc_html__( 'Recorded', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="' . esc_attr( Redemption::STATUS_REVERSED ) . '"';
		selected( $filters['status'] ?? '', Redemption::STATUS_REVERSED );
		echo '>' . esc_html__( 'Reversed', 'mp-commerce-promotions' ) . '</option>';
		echo '</select></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Apply filters', 'mp-commerce-promotions' ), 'secondary', 'mp_cp_reports_filter', false );
		echo '</form>';
	}

	/**
	 * @param array{
	 *     total_promotions: int,
	 *     active_promotions: int,
	 *     recorded_redemptions: int,
	 *     reversed_redemptions: int,
	 *     recorded_discount_total: float,
	 *     top_promotions: list<array<string, mixed>>
	 * } $summary
	 */
	private function render_summary_cards( array $summary ): void {
		echo '<h2>' . esc_html__( 'Summary', 'mp-commerce-promotions' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px;"><tbody>';

		$rows = array(
			__( 'Total promotions', 'mp-commerce-promotions' )       => (string) $summary['total_promotions'],
			__( 'Active promotions', 'mp-commerce-promotions' )      => (string) $summary['active_promotions'],
			__( 'Recorded redemptions', 'mp-commerce-promotions' ) => (string) $summary['recorded_redemptions'],
			__( 'Reversed redemptions', 'mp-commerce-promotions' )   => (string) $summary['reversed_redemptions'],
			__( 'Recorded discount total', 'mp-commerce-promotions' ) => function_exists( 'wc_format_localized_price' )
				? wc_format_localized_price( $summary['recorded_discount_total'] )
				: number_format( $summary['recorded_discount_total'], 2, '.', '' ),
		);

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row" style="width:50%;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param list<array{
	 *     promotion_id: int,
	 *     name: string,
	 *     recorded_count: int,
	 *     reversed_count: int,
	 *     total_discount_amount: float
	 * }> $top
	 */
	private function render_top_promotions_table( array $top ): void {
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Top promotions (by recorded redemptions)', 'mp-commerce-promotions' ) . '</h2>';

		if ( $top === array() ) {
			echo '<p>' . esc_html__( 'No redemptions match the current filters.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Recorded', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Reversed', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Recorded discount', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $top as $row ) {
			$discount = function_exists( 'wc_format_localized_price' )
				? wc_format_localized_price( $row['total_discount_amount'] )
				: number_format( $row['total_discount_amount'], 2, '.', '' );

			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['promotion_id'] ) . '</td>';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['recorded_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['reversed_count'] ) . '</td>';
			echo '<td>' . esc_html( $discount ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array{
	 *     date_from: string|null,
	 *     date_to: string|null,
	 *     promotion_id: int|null,
	 *     status: string|null
	 * } $filters
	 */
	private function render_export_form( array $filters ): void {
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Export', 'mp-commerce-promotions' ) . '</h2>';
		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		if ( $filters['date_from'] !== null ) {
			echo '<input type="hidden" name="date_from" value="' . esc_attr( $filters['date_from'] ) . '" />';
		}
		if ( $filters['date_to'] !== null ) {
			echo '<input type="hidden" name="date_to" value="' . esc_attr( $filters['date_to'] ) . '" />';
		}
		if ( $filters['promotion_id'] !== null ) {
			echo '<input type="hidden" name="promotion_id" value="' . esc_attr( (string) $filters['promotion_id'] ) . '" />';
		}
		if ( $filters['status'] !== null ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $filters['status'] ) . '" />';
		}

		echo '<p class="description">';
		printf(
			/* translators: %d: maximum CSV rows */
			esc_html__( 'Download up to %d redemption rows matching the filters above. The code column is not populated with merchant-facing promotion codes.', 'mp-commerce-promotions' ),
			PromotionReports::EXPORT_ROW_LIMIT
		);
		echo '</p>';

		submit_button(
			__( 'Export redemptions CSV', 'mp-commerce-promotions' ),
			'primary',
			self::EXPORT_SUBMIT,
			false
		);
		echo '</form>';
	}
}
