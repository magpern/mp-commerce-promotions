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
use MP\CommercePromotions\Domain\SimulationScenarioRecord;
use MP\CommercePromotions\Domain\SimulationScenarioRepository;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\Service\PromotionLifecycle;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\PromotionSimulationEngine;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;
use MP\CommercePromotions\Service\SimulationScenario;

final class ReportsPage {

	private const NONCE_ACTION = 'mp_cp_export_redemptions_csv';

	private const NONCE_FIELD = 'mp_cp_export_redemptions_nonce';

	private const EXPORT_SUBMIT = 'mp_cp_export_redemptions_csv';

	private PromotionReports $reports;

	private PromotionPicker $picker;

	private PromotionRepository $promotions;

	private ?SimulationScenarioRepository $scenarios;

	private Settings $settings;

	private ?GiftCardReports $gift_card_reports;

	public function __construct(
		PromotionReports $reports,
		PromotionRepository $promotions,
		Settings $settings,
		?SimulationScenarioRepository $scenarios = null,
		?GiftCardReports $gift_card_reports = null
	) {
		$this->reports           = $reports;
		$this->promotions        = $promotions;
		$this->settings          = $settings;
		$this->scenarios         = $scenarios;
		$this->gift_card_reports = $gift_card_reports;
		$this->picker            = new PromotionPicker( $promotions );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->maybe_send_csv_export();
		$this->handle_post_simulation_scenarios();

		$filters = $this->filters_from_request();
		$summary = $this->reports->summary( $filters );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Reports', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_REPORTS );
		echo '<p class="description">' . esc_html__(
			'Read-only summaries from redemption records. CSV export includes up to 5,000 rows and does not expose raw promotion codes.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<p style="margin:8px 0 16px;">';
		AdminNavigation::render_create_campaign_button( array( 'class' => 'button' ) );
		echo '</p>';

		$this->render_filter_form( $filters );
		$this->render_summary_cards( $summary );
		$this->render_gift_card_summary_section();
		$this->render_telemetry_section();
		$this->render_automation_history_section();
		$this->render_health_summary_section();
		$this->render_production_hardening_section();
		$this->render_planner_performance_section();
		$this->render_forecasting_section();
		$this->render_promotion_calendar_section();
		$this->render_recommendations_section();
		$this->render_intelligence_analytics_section();
		$this->render_profitability_analytics_section();
		$this->render_pricing_analytics_section();
		$this->render_line_discount_mode_section();
		CompatibilityStatusPanel::render();
		if ( $this->settings->simulations_enabled() ) {
			$this->render_simulation_section();
		}
		$this->render_orchestration_section( $summary );
		$this->render_economics_sections( $filters );
		$this->render_top_promotions_table( $summary['top_promotions'] );
		if ( $this->settings->csv_export_enabled() ) {
			$this->render_export_form( $filters );
		} else {
			echo '<p class="description">' . esc_html__( 'CSV export is disabled in Promotion Settings.', 'mp-commerce-promotions' ) . '</p>';
		}

		echo '</div>';
	}

	private function maybe_send_csv_export(): void {
		if ( ! $this->settings->csv_export_enabled() ) {
			return;
		}

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
				'name'          => 'promotion_id',
				'id'            => 'mp_cp_reports_promotion_id',
				'selected'      => $filters['promotion_id'],
				'include_empty' => true,
				'empty_label'   => __( 'All promotions', 'mp-commerce-promotions' ),
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
			__( 'Active promotions with cooldown configured', 'mp-commerce-promotions' ) => (string) $summary['cooldown_active_promotions'],
			__( 'Promotions in dry-run mode', 'mp-commerce-promotions' ) => (string) ( $summary['dry_run_promotions'] ?? 0 ),
			__( 'Tax-sensitive promotions', 'mp-commerce-promotions' ) => (string) ( $summary['tax_sensitive_promotions'] ?? 0 ),
			__( 'Avg discount per recorded redemption', 'mp-commerce-promotions' ) => function_exists( 'wc_format_localized_price' )
				? wc_format_localized_price( $summary['avg_recorded_discount_per_redemption'] )
				: number_format( $summary['avg_recorded_discount_per_redemption'], 2, '.', '' ),
		);

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row" style="width:50%;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function render_gift_card_summary_section(): void {
		if ( $this->gift_card_reports === null ) {
			return;
		}

		$gc = $this->gift_card_reports->summary();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Gift cards & store credit', 'mp-commerce-promotions' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
		$rows = array(
			__( 'Combined outstanding liability', 'mp-commerce-promotions' )   => number_format_i18n( $gc['combined_outstanding_liability'], 2 ),
			__( 'Gift card outstanding liability', 'mp-commerce-promotions' ) => number_format_i18n( $gc['gift_card_outstanding_liability'], 2 ),
			__( 'Store credit outstanding liability', 'mp-commerce-promotions' ) => number_format_i18n( $gc['store_credit_outstanding_liability'], 2 ),
			__( 'Gift cards issued', 'mp-commerce-promotions' )             => number_format_i18n( $gc['total_issued'], 2 ),
			__( 'Gift cards redeemed', 'mp-commerce-promotions' )           => number_format_i18n( $gc['total_redeemed'], 2 ),
			__( 'Store credit issued', 'mp-commerce-promotions' )           => number_format_i18n( $gc['store_credit_issued'], 2 ),
			__( 'Store credit redeemed', 'mp-commerce-promotions' )           => number_format_i18n( $gc['store_credit_redeemed'], 2 ),
			__( 'Refund to store credit', 'mp-commerce-promotions' )        => number_format_i18n( $gc['refund_to_credit_total'], 2 ),
			__( 'Store credit manual adjustments (net)', 'mp-commerce-promotions' ) => number_format_i18n( $gc['manual_adjustment_total'], 2 ),
			__( 'Gift card adjusted (net)', 'mp-commerce-promotions' )       => number_format_i18n( $gc['total_adjusted'], 2 ),
			__( 'Gift cards voided (amount)', 'mp-commerce-promotions' )    => number_format_i18n( $gc['total_voided'], 2 ),
			__( 'Depleted gift cards', 'mp-commerce-promotions' )           => (string) $gc['depleted_count'],
			__( 'Expired gift cards', 'mp-commerce-promotions' )            => (string) $gc['expired_count'],
			__( 'Gift cards sold from products (count)', 'mp-commerce-promotions' ) => (string) ( $gc['gift_cards_sold_from_products'] ?? 0 ),
			__( 'Product-generated gift card liability', 'mp-commerce-promotions' ) => number_format_i18n( (float) ( $gc['product_generated_liability'] ?? 0 ), 2 ),
			__( 'Product-generated issued total', 'mp-commerce-promotions' ) => number_format_i18n( (float) ( $gc['product_generated_issued_total'] ?? 0 ), 2 ),
			__( 'Manually issued gift cards total', 'mp-commerce-promotions' ) => number_format_i18n( (float) ( $gc['manually_issued_total'] ?? 0 ), 2 ),
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row" style="width:50%;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$by_currency = $gc['liability_by_currency'] ?? array();
		if ( is_array( $by_currency ) && $by_currency !== array() ) {
			echo '<h3 style="margin-top:1.25em;">' . esc_html__( 'Outstanding liability by currency', 'mp-commerce-promotions' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Liabilities are not converted between currencies; totals above may combine multiple currencies.', 'mp-commerce-promotions' ) . '</p>';
			echo '<table class="widefat striped" style="max-width:720px;"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Currency', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Gift cards', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Store credit', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Combined', 'mp-commerce-promotions' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $by_currency as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$code = (string) ( $row['currency'] ?? '' );
				echo '<tr>';
				echo '<td><strong>' . esc_html( $code ) . '</strong></td>';
				echo '<td>' . esc_html( number_format_i18n( (float) ( $row['gift_card_liability'] ?? 0 ), 2 ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) ( $row['store_credit_liability'] ?? 0 ), 2 ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) ( $row['combined_liability'] ?? 0 ), 2 ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	private function render_orchestration_section( array $summary ): void {
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Orchestration', 'mp-commerce-promotions' ) . '</h2>';

		$groups = $summary['top_orchestration_groups'] ?? array();
		echo '<h3>' . esc_html__( 'Top orchestration groups (active)', 'mp-commerce-promotions' ) . '</h3>';
		if ( $groups === array() ) {
			echo '<p>' . esc_html__( 'No active promotions use orchestration groups.', 'mp-commerce-promotions' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:480px;"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Group', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Promotions', 'mp-commerce-promotions' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $groups as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				echo '<tr><td>' . esc_html( (string) ( $row['orchestration_group'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['promotion_count'] ?? '0' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$burn = $summary['highest_budget_burn'] ?? array();
		echo '<h3 style="margin-top:1.25em;">' . esc_html__( 'Highest budget burn (active)', 'mp-commerce-promotions' ) . '</h3>';
		if ( $burn === array() ) {
			echo '<p>' . esc_html__( 'No active budgeted promotions.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Spent', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Budget utilization', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $burn as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$spent = function_exists( 'wc_format_localized_price' )
				? wc_format_localized_price( (float) ( $row['budget_spent'] ?? 0 ) )
				: number_format( (float) ( $row['budget_spent'] ?? 0 ), 2, '.', '' );
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['promotion_id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $spent ) . '</td>';
			echo '<td>' . esc_html( $this->format_budget_utilization_cell( $row ) ) . '</td>';
			echo '</tr>';
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

	private function render_telemetry_section(): void {
		$telemetry = $this->reports->telemetry_summary( 10 );
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Planner telemetry', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Aggregate planner counters (no customer PII). Updated during cart totals calculation.', 'mp-commerce-promotions' ) . '</p>';

		$this->render_telemetry_table( __( 'Most selected promotions', 'mp-commerce-promotions' ), $telemetry['most_selected'], 'selected_count' );
		$this->render_telemetry_table( __( 'Most blocked (orchestration group)', 'mp-commerce-promotions' ), $telemetry['most_blocked'], 'metric_value' );
		echo '<h3>' . esc_html__( 'Most conflicted orchestration groups', 'mp-commerce-promotions' ) . '</h3>';
		if ( $telemetry['top_orchestration_conflicts'] === array() ) {
			echo '<p>' . esc_html__( 'No orchestration block data yet.', 'mp-commerce-promotions' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $telemetry['top_orchestration_conflicts'] as $row ) {
				echo '<li><code>' . esc_html( (string) ( $row['orchestration_group'] ?? '' ) ) . '</code> — ';
				echo esc_html( (string) (int) ( $row['total_blocked'] ?? 0 ) ) . ' ' . esc_html__( 'blocks', 'mp-commerce-promotions' );
				echo '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function render_telemetry_table( string $title, array $rows, string $metric_key ): void {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( $rows === array() ) {
			echo '<p>' . esc_html__( 'No data yet.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:640px;"><thead><tr><th>ID</th><th>' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</th><th>' . esc_html__( 'Count', 'mp-commerce-promotions' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$metric = isset( $row[ $metric_key ] ) ? (int) $row[ $metric_key ] : (int) ( $row['metric_value'] ?? 0 );
			echo '<tr><td>' . esc_html( (string) (int) ( $row['promotion_id'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $metric ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_automation_history_section(): void {
		$runs = $this->reports->latest_automation_runs( 10 );
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Automation history', 'mp-commerce-promotions' ) . '</h2>';
		if ( $runs === array() ) {
			echo '<p>' . esc_html__( 'No automation runs yet. Use Diagnostics → Run all automation.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:720px;"><thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Warnings</th><th>Errors</th><th>Started</th></tr></thead><tbody>';
		foreach ( $runs as $run ) {
			echo '<tr><td>' . esc_html( (string) ( $run->get_id() ?? 0 ) ) . '</td>';
			echo '<td><code>' . esc_html( $run->get_run_type() ) . '</code></td>';
			echo '<td>' . esc_html( $run->get_status() ) . '</td>';
			echo '<td>' . esc_html( (string) $run->get_warnings_count() ) . '</td>';
			echo '<td>' . esc_html( (string) $run->get_errors_count() ) . '</td>';
			echo '<td>' . esc_html( $run->get_created_at() ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_health_summary_section(): void {
		$health = $this->reports->health_summary( 500 );
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Promotion health summary', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>';
		printf(
			/* translators: 1: total, 2: critical, 3: warning, 4: info */
			esc_html__( 'Issues found: %1$d (critical: %2$d, warning: %3$d, info: %4$d). See Diagnostics for the full list.', 'mp-commerce-promotions' ),
			(int) $health['total'],
			(int) $health['critical'],
			(int) $health['warning'],
			(int) $health['info']
		);
		echo '</p>';
	}

	private function render_production_hardening_section(): void {
		$dash = $this->reports->production_hardening_dashboard( $this->settings );
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Production hardening', 'mp-commerce-promotions' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:960px;"><tbody>';

		$this->render_hardening_row(
			__( 'Safe mode', 'mp-commerce-promotions' ),
			! empty( $dash['safe_mode'] )
				? __( 'ON — automatic promotions disabled', 'mp-commerce-promotions' )
				: __( 'Off', 'mp-commerce-promotions' )
		);
		$this->render_hardening_row(
			__( 'Automatic promotions', 'mp-commerce-promotions' ),
			! empty( $dash['automatic_promotions'] ) ? __( 'Enabled', 'mp-commerce-promotions' ) : __( 'Disabled', 'mp-commerce-promotions' )
		);
		$this->render_hardening_row(
			__( 'Storefront degraded mode', 'mp-commerce-promotions' ),
			! empty( $dash['storefront_degraded'] )
				? __( 'Active — see Diagnostics to clear', 'mp-commerce-promotions' )
				: __( 'Not active', 'mp-commerce-promotions' )
		);
		$this->render_hardening_row(
			__( 'Planner telemetry', 'mp-commerce-promotions' ),
			! empty( $dash['telemetry_paused'] )
				? __( 'Paused', 'mp-commerce-promotions' )
				: __( 'Active', 'mp-commerce-promotions' )
		);
		$this->render_hardening_row(
			__( 'Simulations', 'mp-commerce-promotions' ),
			! empty( $dash['simulation_paused'] )
				? __( 'Paused', 'mp-commerce-promotions' )
				: __( 'Active', 'mp-commerce-promotions' )
		);
		$this->render_hardening_row(
			__( 'Automation emergency stop', 'mp-commerce-promotions' ),
			! empty( $dash['automation_emergency_stop'] ) ? __( 'ON', 'mp-commerce-promotions' ) : __( 'Off', 'mp-commerce-promotions' )
		);
		$this->render_hardening_row(
			__( 'WP-Cron automation', 'mp-commerce-promotions' ),
			! empty( $dash['cron_automation_enabled'] ) ? __( 'Enabled in settings', 'mp-commerce-promotions' ) : __( 'Disabled (default)', 'mp-commerce-promotions' )
		);
		$this->render_hardening_row(
			__( 'Cron hooks scheduled', 'mp-commerce-promotions' ),
			sprintf(
				/* translators: 1: hourly yes/no, 2: daily yes/no */
				__( 'Hourly: %1$s — Daily: %2$s', 'mp-commerce-promotions' ),
				! empty( $dash['cron_hourly_scheduled'] ) ? __( 'yes', 'mp-commerce-promotions' ) : __( 'no', 'mp-commerce-promotions' ),
				! empty( $dash['cron_daily_scheduled'] ) ? __( 'yes', 'mp-commerce-promotions' ) : __( 'no', 'mp-commerce-promotions' )
			)
		);
		$this->render_hardening_row(
			__( 'Telemetry retention', 'mp-commerce-promotions' ),
			sprintf(
				/* translators: %d: days */
				__( '%d days', 'mp-commerce-promotions' ),
				(int) ( $dash['telemetry_retention_days'] ?? 90 )
			)
		);

		$confidence = (string) ( $dash['compatibility_confidence'] ?? PricingCompatibilityAnalyzer::CONFIDENCE_UNKNOWN );
		$this->render_hardening_row( __( 'Compatibility confidence', 'mp-commerce-promotions' ), $confidence );

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $profiler
	 */
	private function render_planner_performance_section(): void {
		$perf     = $this->reports->planner_performance();
		$profiler = is_array( $perf['profiler'] ?? null ) ? $perf['profiler'] : array();

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Planner performance', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>' . esc_html__(
			'In-request cache counters, rolling profiler aggregates, and allocation cache metrics.',
			'mp-commerce-promotions'
		) . '</p>';

		echo '<h3>' . esc_html__( 'Request & persisted caches', 'mp-commerce-promotions' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
		$this->render_hardening_row(
			__( 'This request — simulated runs', 'mp-commerce-promotions' ),
			(string) (int) ( $perf['request']['simulated_runs'] ?? 0 )
		);
		$this->render_hardening_row(
			__( 'This request — planner cache hits / misses', 'mp-commerce-promotions' ),
			(int) ( $perf['request']['cache_hits'] ?? 0 ) . ' / ' . (int) ( $perf['request']['cache_misses'] ?? 0 )
		);
		$this->render_hardening_row(
			__( 'Allocation cache hits / misses (request)', 'mp-commerce-promotions' ),
			(int) ( $perf['request']['allocation_hits'] ?? 0 ) . ' / ' . (int) ( $perf['request']['allocation_misses'] ?? 0 )
		);
		$this->render_hardening_row(
			__( 'Persisted planner counters', 'mp-commerce-promotions' ),
			sprintf(
				'simulated %d — hits %d — misses %d',
				(int) ( $perf['persisted']['simulated_runs'] ?? 0 ),
				(int) ( $perf['persisted']['cache_hits'] ?? 0 ),
				(int) ( $perf['persisted']['cache_misses'] ?? 0 )
			)
		);
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Profiler aggregates', 'mp-commerce-promotions' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
		$this->render_hardening_row( __( 'Planner runs (rolling)', 'mp-commerce-promotions' ), (string) (int) ( $profiler['planner_runs'] ?? 0 ) );
		$this->render_hardening_row( __( 'Average planner runtime (ms)', 'mp-commerce-promotions' ), (string) ( $profiler['average_planner_ms'] ?? 0 ) );
		$this->render_hardening_row( __( 'Max planner runtime (ms)', 'mp-commerce-promotions' ), (string) ( $profiler['max_planner_ms'] ?? 0 ) );
		$this->render_hardening_row( __( 'Allocation cache hit rate', 'mp-commerce-promotions' ), (string) ( $profiler['allocation_cache_hit_rate'] ?? 0 ) . '%' );
		$this->render_hardening_row( __( 'Telemetry writes', 'mp-commerce-promotions' ), (string) (int) ( $profiler['telemetry_writes'] ?? 0 ) );
		$this->render_hardening_row( __( 'Planner failures', 'mp-commerce-promotions' ), (string) (int) ( $profiler['planner_failures'] ?? 0 ) );
		echo '</tbody></table>';

		$slow = is_array( $perf['slow_runs'] ?? null ) ? $perf['slow_runs'] : array();
		echo '<h3>' . esc_html__( 'Slow planner runs', 'mp-commerce-promotions' ) . '</h3>';
		if ( $slow === array() ) {
			echo '<p>' . esc_html__( 'No slow runs recorded yet.', 'mp-commerce-promotions' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Duration (ms)', 'mp-commerce-promotions' ) . '</th><th>' . esc_html__( 'Promotions', 'mp-commerce-promotions' ) . '</th><th>' . esc_html__( 'Selected', 'mp-commerce-promotions' ) . '</th><th>' . esc_html__( 'Recorded', 'mp-commerce-promotions' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $slow, 0, 10 ) as $run ) {
				if ( ! is_array( $run ) ) {
					continue;
				}
				echo '<tr><td>' . esc_html( (string) ( $run['duration_ms'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $run['promotions_considered'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $run['selected_count'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $run['recorded_at'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	private function render_hardening_row( string $label, string $value ): void {
		echo '<tr><th scope="row" style="width:240px;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	private function render_forecasting_section(): void {
		$forecast = $this->reports->forecast_summary();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Forecasting (heuristic)', 'mp-commerce-promotions' ) . '</h2>';
		if ( $forecast === array() ) {
			echo '<p>' . esc_html__( 'Telemetry required for forecasts.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}
		echo '<p>';
		printf(
			esc_html__( 'Estimated catalog discount exposure: %1$s — projected redemptions: %2$d (generated %3$s).', 'mp-commerce-promotions' ),
			esc_html( number_format( (float) ( $forecast['estimated_discount_exposure'] ?? 0 ), 2 ) ),
			(int) ( $forecast['projected_redemption_volume'] ?? 0 ),
			esc_html( (string) ( $forecast['generated_at'] ?? '' ) )
		);
		echo '</p>';
	}

	private function render_promotion_calendar_section(): void {
		$calendar = $this->reports->promotion_calendar();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Promotion calendar', 'mp-commerce-promotions' ) . '</h2>';
		foreach ( array( 'upcoming', 'active', 'ending_soon', 'exhausted', 'archived' ) as $bucket ) {
			$rows = $calendar[ $bucket ] ?? array();
			echo '<h3>' . esc_html( ucfirst( str_replace( '_', ' ', $bucket ) ) ) . '</h3>';
			if ( $rows === array() ) {
				echo '<p>' . esc_html__( 'None', 'mp-commerce-promotions' ) . '</p>';
				continue;
			}
			echo '<table class="widefat striped" style="max-width:100%;margin-bottom:1em;"><thead><tr>';
			echo '<th>ID</th><th>' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Campaign', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Orchestration', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Phase', 'mp-commerce-promotions' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 15 ) as $row ) {
				echo '<tr><td>' . esc_html( (string) ( $row['promotion_id'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['campaign_label'] ?? '—' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['orchestration_group'] ?? '—' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['lifecycle_phase'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	private function render_recommendations_section(): void {
		$recs = $this->reports->recommendations();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Intelligent recommendations', 'mp-commerce-promotions' ) . '</h2>';
		if ( $recs === array() ) {
			echo '<p>' . esc_html__( 'No recommendations.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Severity', 'mp-commerce-promotions' ) . '</th><th>' . esc_html__( 'Code', 'mp-commerce-promotions' ) . '</th><th>' . esc_html__( 'Message', 'mp-commerce-promotions' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $recs, 0, 25 ) as $rec ) {
			echo '<tr><td>' . esc_html( (string) ( $rec['severity'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $rec['code'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $rec['message'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_intelligence_analytics_section(): void {
		$analytics = $this->reports->intelligence_analytics();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Campaign intelligence analytics', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>' . esc_html__( 'Highest ROI (discount per redemption heuristic):', 'mp-commerce-promotions' ) . '</p>';
		if ( ( $analytics['highest_roi_campaigns'] ?? array() ) === array() ) {
			echo '<p>' . esc_html__( 'No ROI data yet.', 'mp-commerce-promotions' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $analytics['highest_roi_campaigns'] as $row ) {
				echo '<li>' . esc_html( (string) ( $row['name'] ?? '' ) ) . ' — ROI ' . esc_html( (string) ( $row['roi_score'] ?? 0 ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p>' . esc_html__( 'Scenario run count (saved scenarios):', 'mp-commerce-promotions' ) . ' ';
		echo esc_html( (string) (int) ( $analytics['most_simulated_scenarios_runs'] ?? 0 ) ) . '</p>';
	}

	private function render_profitability_analytics_section(): void {
		$data = $this->reports->profitability_analytics();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Profitability analytics (heuristic)', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>' . esc_html__(
			'Estimated margin impact and campaign cost signals. Not accounting-grade.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<ul>';
		printf( '<li>%s</li>', esc_html( sprintf( __( 'Estimated margin impact: %s', 'mp-commerce-promotions' ), (string) ( $data['estimated_margin_impact'] ?? 0 ) ) ) );
		printf( '<li>%s</li>', esc_html( sprintf( __( 'Average discount rate: %s', 'mp-commerce-promotions' ), (string) ( $data['average_discount_rate'] ?? 0 ) ) ) );
		printf( '<li>%s</li>', esc_html( sprintf( __( 'Shipping discount exposure: %s', 'mp-commerce-promotions' ), (string) ( $data['shipping_discount_exposure'] ?? 0 ) ) ) );
		echo '</ul>';
	}

	private function render_line_discount_mode_section(): void {
		$data = $this->reports->line_discount_mode_summary();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Line discount mode', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Experimental line-item and hybrid storefront modes. Fee-based remains the default. Counters use stored telemetry (not accounting-grade).',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<ul>';
		printf(
			'<li>%s</li>',
			esc_html(
				sprintf(
					/* translators: %d: count */
					__( 'Promotions using line_item: %d', 'mp-commerce-promotions' ),
					(int) ( $data['line_item_promotions'] ?? 0 )
				)
			)
		);
		printf(
			'<li>%s</li>',
			esc_html(
				sprintf(
					/* translators: %d: count */
					__( 'Promotions using hybrid: %d', 'mp-commerce-promotions' ),
					(int) ( $data['hybrid_promotions'] ?? 0 )
				)
			)
		);
		printf(
			'<li>%s</li>',
			esc_html(
				sprintf(
					/* translators: %d: count */
					__( 'Fee fallbacks recorded (store): %d', 'mp-commerce-promotions' ),
					(int) ( $data['fallback_total'] ?? 0 )
				)
			)
		);
		$last_reason = (string) ( $data['last_fallback_reason'] ?? '' );
		if ( $last_reason !== '' ) {
			printf(
				'<li>%s</li>',
				esc_html(
					sprintf(
						/* translators: 1: reason code, 2: timestamp */
						__( 'Last fallback: %1$s (%2$s)', 'mp-commerce-promotions' ),
						$last_reason,
						(string) ( $data['last_fallback_at'] ?? '' )
					)
				)
			);
		}
		printf(
			'<li>%s</li>',
			esc_html(
				sprintf(
					/* translators: %d: count */
					__( 'Line allocation cart applications: %d', 'mp-commerce-promotions' ),
					(int) ( $data['line_allocation_applications'] ?? 0 )
				)
			)
		);
		printf(
			'<li>%s</li>',
			esc_html(
				sprintf(
					/* translators: %s: amount */
					__( 'Average effective line savings (per application): %s', 'mp-commerce-promotions' ),
					(string) ( $data['average_effective_line_savings'] ?? 0 )
				)
			)
		);
		echo '</ul>';
	}

	private function render_pricing_analytics_section(): void {
		$data = $this->reports->pricing_analytics();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Pricing & allocation analytics', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Priority tiers', 'mp-commerce-promotions' ) . '</strong></p><ul>';
		foreach ( (array) ( $data['priority_tier_counts'] ?? array() ) as $tier => $count ) {
			echo '<li>' . esc_html( $tier ) . ': ' . esc_html( (string) $count ) . '</li>';
		}
		echo '</ul>';
		$coupon = $data['coupon_coexistence'] ?? array();
		if ( is_array( $coupon ) && (int) ( $coupon['native_coupon_count'] ?? 0 ) > 0 ) {
			echo '<p class="description">' . esc_html( (string) ( $coupon['message'] ?? '' ) ) . '</p>';
		}
	}

	private function render_simulation_section(): void {
		if ( $this->scenarios === null ) {
			return;
		}

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Promotion simulation', 'mp-commerce-promotions' ) . '</h2>';
		$engine = new PromotionSimulationEngine( $this->promotions );
		$result = $engine->simulate( SimulationScenario::from_preset( SimulationScenario::PRESET_WHOLE_CART ) );
		echo '<p class="description">' . esc_html__( 'Quick whole-cart simulation against active promotions (synthetic cart).', 'mp-commerce-promotions' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Estimated discount:', 'mp-commerce-promotions' ) . '</strong> ';
		echo esc_html( number_format( $result->get_total_discount(), 2 ) ) . '</p>';

		echo '<h3>' . esc_html__( 'Saved scenarios (latest 20)', 'mp-commerce-promotions' ) . '</h3>';
		$list = $this->scenarios->find_latest( 20 );
		if ( $list === array() ) {
			echo '<p>' . esc_html__( 'No saved scenarios yet.', 'mp-commerce-promotions' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Name</th><th>Runs</th><th>Last run</th></tr></thead><tbody>';
			foreach ( $list as $scenario ) {
				echo '<tr><td>' . esc_html( (string) ( $scenario->get_id() ?? 0 ) ) . '</td>';
				echo '<td>' . esc_html( $scenario->get_name() ) . '</td>';
				echo '<td>' . esc_html( (string) $scenario->get_run_count() ) . '</td>';
				echo '<td>' . esc_html( $scenario->get_last_run_at() ?? '—' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<form method="post" style="margin-top:1em;">';
		wp_nonce_field( 'mp_cp_save_simulation_scenario', 'mp_cp_save_simulation_scenario_nonce' );
		echo '<p><label>' . esc_html__( 'Save preset scenario', 'mp-commerce-promotions' ) . '</label> ';
		echo '<select name="mp_cp_simulation_preset">';
		foreach (
			array(
				SimulationScenario::PRESET_WHOLE_CART,
				SimulationScenario::PRESET_VIP_CUSTOMER,
				SimulationScenario::PRESET_GUEST_CUSTOMER,
			) as $preset
		) {
			echo '<option value="' . esc_attr( $preset ) . '">' . esc_html( $preset ) . '</option>';
		}
		echo '</select> ';
		echo '<input type="text" name="mp_cp_simulation_name" placeholder="' . esc_attr__( 'Scenario name', 'mp-commerce-promotions' ) . '" required /> ';
		echo '<button type="submit" name="mp_cp_save_simulation_scenario" value="1" class="button">' . esc_html__( 'Save scenario', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';
	}

	private function handle_post_simulation_scenarios(): void {
		if ( $this->scenarios === null || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_save_simulation_scenario'] ) ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_save_simulation_scenario_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_save_simulation_scenario_nonce'] ) ), 'mp_cp_save_simulation_scenario' ) ) {
			return;
		}

		$name   = isset( $_POST['mp_cp_simulation_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_simulation_name'] ) ) : '';
		$preset = isset( $_POST['mp_cp_simulation_preset'] ) ? sanitize_key( wp_unslash( (string) $_POST['mp_cp_simulation_preset'] ) ) : SimulationScenario::PRESET_WHOLE_CART;
		if ( $name === '' ) {
			return;
		}

		$record = new SimulationScenarioRecord(
			null,
			$name,
			SimulationScenario::from_preset( $preset )->to_array(),
			SimulationScenarioRecord::STATUS_ACTIVE,
			(int) get_current_user_id(),
			current_time( 'mysql' ),
			null,
			0
		);
		$this->scenarios->insert( $record );
	}
}
