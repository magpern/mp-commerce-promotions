<?php
/**
 * WooCommerce admin: promotion performance reports and CSV export.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Service\PromotionLifecycle;
use MP\CommercePromotions\Service\PromotionReports;

final class ReportsPage {

	private const NONCE_ACTION = 'mp_cp_export_redemptions_csv';

	private const NONCE_FIELD = 'mp_cp_export_redemptions_nonce';

	private const EXPORT_SUBMIT = 'mp_cp_export_redemptions_csv';

	private PromotionReports $reports;

	private PromotionPicker $picker;

	private PromotionRepository $promotions;

	public function __construct( PromotionReports $reports, PromotionRepository $promotions ) {
		$this->reports    = $reports;
		$this->promotions = $promotions;
		$this->picker     = new PromotionPicker( $promotions );
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
		$this->render_economics_sections( $filters );
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
				'date_from'        => isset( $source['date_from'] ) ? wp_unslash( (string) $source['date_from'] ) : null,
				'date_to'          => isset( $source['date_to'] ) ? wp_unslash( (string) $source['date_to'] ) : null,
				'date_preset'      => isset( $source['date_preset'] ) ? wp_unslash( (string) $source['date_preset'] ) : null,
				'promotion_id'     => isset( $source['promotion_id'] ) ? wp_unslash( (string) $source['promotion_id'] ) : null,
				'status'           => isset( $source['status'] ) ? wp_unslash( (string) $source['status'] ) : null,
				'campaign_label'   => isset( $source['campaign_label'] ) ? wp_unslash( (string) $source['campaign_label'] ) : null,
				'budget_exhausted' => isset( $source['budget_exhausted'] ) ? wp_unslash( (string) $source['budget_exhausted'] ) : null,
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

		echo '<tr><th scope="row"><label for="mp_cp_reports_date_preset">' . esc_html__( 'Date preset', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<select id="mp_cp_reports_date_preset" name="date_preset">';
		echo '<option value="">' . esc_html__( 'Custom range', 'mp-commerce-promotions' ) . '</option>';
		$presets = array(
			PromotionReports::DATE_PRESET_TODAY      => __( 'Today', 'mp-commerce-promotions' ),
			PromotionReports::DATE_PRESET_7D         => __( 'Last 7 days', 'mp-commerce-promotions' ),
			PromotionReports::DATE_PRESET_30D        => __( 'Last 30 days', 'mp-commerce-promotions' ),
			PromotionReports::DATE_PRESET_THIS_MONTH => __( 'This month', 'mp-commerce-promotions' ),
		);
		foreach ( $presets as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $filters['date_preset'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Presets override manual date fields when selected.', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_reports_date_from">' . esc_html__( 'Date from', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="date" id="mp_cp_reports_date_from" name="date_from" value="' . esc_attr( $filters['date_from'] ?? '' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Filters by redemption redeemed_at (inclusive).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_reports_date_to">' . esc_html__( 'Date to', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="date" id="mp_cp_reports_date_to" name="date_to" value="' . esc_attr( $filters['date_to'] ?? '' ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_reports_promotion_id">' . esc_html__( 'Promotion', 'mp-commerce-promotions' ) . '</label></th><td>';
		$this->picker->render_select(
			array(
				'name'         => 'promotion_id',
				'id'           => 'mp_cp_reports_promotion_id',
				'selected'     => $filters['promotion_id'],
				'include_empty' => true,
				'empty_label'  => __( 'All promotions', 'mp-commerce-promotions' ),
			)
		);
		echo '</td></tr>';

		$labels = $this->promotions->find_distinct_campaign_labels( 50 );
		if ( $labels !== array() ) {
			echo '<tr><th scope="row"><label for="mp_cp_reports_campaign_label">' . esc_html__( 'Campaign label', 'mp-commerce-promotions' ) . '</label></th><td>';
			echo '<select id="mp_cp_reports_campaign_label" name="campaign_label">';
			echo '<option value="">' . esc_html__( 'All campaigns', 'mp-commerce-promotions' ) . '</option>';
			foreach ( $labels as $label ) {
				echo '<option value="' . esc_attr( $label ) . '"';
				selected( $filters['campaign_label'] ?? '', $label );
				echo '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}

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

		echo '<tr><th scope="row"><label for="mp_cp_reports_budget_exhausted">' . esc_html__( 'Budget exhausted', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<select id="mp_cp_reports_budget_exhausted" name="budget_exhausted">';
		echo '<option value="">' . esc_html__( 'All promotions', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="yes"' . selected( $filters['budget_exhausted'] ?? '', 'yes', false ) . '>' . esc_html__( 'Exhausted only', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="no"' . selected( $filters['budget_exhausted'] ?? '', 'no', false ) . '>' . esc_html__( 'Not exhausted', 'mp-commerce-promotions' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Filters promotion economics tables and top promotions by budget state.', 'mp-commerce-promotions' ) . '</p></td></tr>';

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
			__( 'Total budget spent (budgeted promos)', 'mp-commerce-promotions' ) => function_exists( 'wc_format_localized_price' )
				? wc_format_localized_price( $summary['total_budget_spent'] )
				: number_format( $summary['total_budget_spent'], 2, '.', '' ),
			__( 'Active promotions with budget cap', 'mp-commerce-promotions' ) => (string) $summary['active_budgeted_promotions'],
			__( 'Active promotions with exhausted budget', 'mp-commerce-promotions' ) => (string) $summary['exhausted_promotions'],
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
		echo '<th scope="col">' . esc_html__( 'Campaign', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Recorded', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Reversed', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Recorded discount', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Budget utilization', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $top as $row ) {
			$discount = function_exists( 'wc_format_localized_price' )
				? wc_format_localized_price( $row['total_discount_amount'] )
				: number_format( $row['total_discount_amount'], 2, '.', '' );

			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['promotion_id'] ) . '</td>';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			$campaign = isset( $row['campaign_label'] ) ? (string) $row['campaign_label'] : '';
			echo '<td>' . esc_html( $campaign !== '' ? $campaign : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) $row['recorded_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['reversed_count'] ) . '</td>';
			echo '<td>' . esc_html( $discount ) . '</td>';
			echo '<td>' . esc_html( $this->format_budget_utilization_cell( $row ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private function render_economics_sections( array $filters ): void {
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Campaign economics', 'mp-commerce-promotions' ) . '</h2>';

		$this->render_economics_promotion_table(
			__( 'Upcoming (scheduled)', 'mp-commerce-promotions' ),
			$this->reports->promotions_by_lifecycle_phase( PromotionLifecycle::PHASE_UPCOMING, $filters, 15 )
		);
		$this->render_economics_promotion_table(
			__( 'Ending soon', 'mp-commerce-promotions' ),
			$this->reports->promotions_by_lifecycle_phase( PromotionLifecycle::PHASE_ENDING_SOON, $filters, 15 )
		);
		$this->render_economics_promotion_table(
			__( 'Budget exhausted (active)', 'mp-commerce-promotions' ),
			$this->reports->promotions_by_lifecycle_phase( PromotionLifecycle::PHASE_BUDGET_EXHAUSTED, $filters, 15 )
		);
	}

	/**
	 * @param list<Promotion> $promotions
	 */
	private function render_economics_promotion_table( string $title, array $promotions ): void {
		echo '<h3 style="margin-top:1em;">' . esc_html( $title ) . '</h3>';
		if ( $promotions === array() ) {
			echo '<p class="description">' . esc_html__( 'None match the current filters.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:100%;"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Campaign', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Budget', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Utilization', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Ends', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $promotions as $promotion ) {
			if ( ! $promotion instanceof Promotion ) {
				continue;
			}
			$id = $promotion->get_id();
			echo '<tr>';
			echo '<td>' . esc_html( $id !== null ? (string) $id : '' ) . '</td>';
			echo '<td>' . esc_html( $promotion->get_name() ) . '</td>';
			$label = $promotion->get_campaign_label();
			echo '<td>' . esc_html( $label !== null && $label !== '' ? $label : '—' ) . '</td>';
			if ( $promotion->has_budget_cap() ) {
				echo '<td>' . esc_html(
					number_format( $promotion->get_budget_spent(), 2, '.', '' ) . ' / ' . number_format( (float) $promotion->get_budget_amount(), 2, '.', '' )
				) . '</td>';
				$pct = $promotion->get_budget_utilization_percent();
				echo '<td>' . esc_html( $pct !== null ? number_format( $pct, 1 ) . '%' : '—' ) . '</td>';
			} else {
				echo '<td>—</td><td>—</td>';
			}
			echo '<td>' . esc_html( $promotion->get_ends_at() ?? '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function format_budget_utilization_cell( array $row ): string {
		if ( ! isset( $row['budget_utilization_percent'] ) || $row['budget_utilization_percent'] === null ) {
			return '—';
		}

		return number_format( (float) $row['budget_utilization_percent'], 1 ) . '%';
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
		if ( ! empty( $filters['campaign_label'] ) ) {
			echo '<input type="hidden" name="campaign_label" value="' . esc_attr( (string) $filters['campaign_label'] ) . '" />';
		}
		if ( ! empty( $filters['date_preset'] ) ) {
			echo '<input type="hidden" name="date_preset" value="' . esc_attr( (string) $filters['date_preset'] ) . '" />';
		}
		if ( ! empty( $filters['budget_exhausted'] ) ) {
			echo '<input type="hidden" name="budget_exhausted" value="' . esc_attr( (string) $filters['budget_exhausted'] ) . '" />';
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
