<?php
/**
 * Ecosystem certification, coupon/tax compatibility, emergency ops (Diagnostics).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\CertificationRun;
use MP\CommercePromotions\Domain\CertificationRunRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\CertificationTrackingService;
use MP\CommercePromotions\Service\CouponCompatibilityMatrix;
use MP\CommercePromotions\Service\EmergencyOperationsService;
use MP\CommercePromotions\Service\MultiCurrencyCompatibility;
use MP\CommercePromotions\Service\PromotionIntelligenceRecovery;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Service\TaxCompatibilityAnalyzer;

final class EcosystemCertificationPanel {

	public static function render_certification_table( CertificationTrackingService $tracking ): void {
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Checkout certification', 'mp-commerce-promotions' ) . '</h2>';
		$rows = $tracking->dashboard_rows( 30 );
		echo '<table class="widefat striped" style="max-width:960px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Area', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Last certified', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Stale', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$stale = ! empty( $row['stale'] );
			echo '<tr' . ( $stale ? ' style="background:#fff8e5;"' : '' ) . '>';
			echo '<td>' . esc_html( (string) ( $row['label'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['certified_at'] ?? '—' ) ) . '</td>';
			echo '<td>' . ( $stale ? esc_html__( 'Yes (>30d or missing)', 'mp-commerce-promotions' ) : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public static function render_coupon_matrix(): void {
		$matrix = new CouponCompatibilityMatrix();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Coupon coexistence matrix', 'mp-commerce-promotions' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:960px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Scenario', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Risk', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Certified', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Notes', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $matrix->build_scenarios() as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['label'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['risk'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['certified'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['notes'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		foreach ( $matrix->collect_diagnostics_warnings() as $warning ) {
			AdminNotice::warning( (string) ( $warning['message'] ?? '' ) );
		}
	}

	public static function render_tax_compatibility(): void {
		$analyzer = new TaxCompatibilityAnalyzer();
		$data     = $analyzer->analyze();
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Tax compatibility', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		echo '<li>' . esc_html__( 'Prices include tax', 'mp-commerce-promotions' ) . ': ' . esc_html( ! empty( $data['prices_include_tax'] ) ? __( 'Yes', 'mp-commerce-promotions' ) : __( 'No', 'mp-commerce-promotions' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Shop display', 'mp-commerce-promotions' ) . ': ' . esc_html( (string) ( $data['tax_display_shop'] ?? '' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Cart display', 'mp-commerce-promotions' ) . ': ' . esc_html( (string) ( $data['tax_display_cart'] ?? '' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Rounding risk', 'mp-commerce-promotions' ) . ': ' . esc_html( (string) ( $data['rounding_risk'] ?? '' ) ) . '</li>';
		echo '</ul>';
		foreach ( $data['warnings'] as $warning ) {
			$severity = (string) ( $warning['severity'] ?? 'info' );
			if ( $severity === 'warning' ) {
				AdminNotice::warning( (string) ( $warning['message'] ?? '' ) );
			} else {
				echo '<p class="description">' . esc_html( (string) ( $warning['message'] ?? '' ) ) . '</p>';
			}
		}
	}

	public static function render_currency_snapshot(): void {
		$snapshot = ( new MultiCurrencyCompatibility() )->snapshot();
		echo '<h3 style="margin-top:1em;">' . esc_html__( 'Multi-currency snapshot', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Confidence', 'mp-commerce-promotions' ) . ':</strong> ' . esc_html( (string) ( $snapshot['confidence'] ?? '' ) ) . '</p>';
		echo '<p>' . esc_html( (string) ( $snapshot['recommendation'] ?? '' ) ) . '</p>';
	}

	public static function render_coupon_telemetry( ?PromotionPerformanceProfiler $profiler ): void {
		if ( $profiler === null ) {
			return;
		}
		$summary = $profiler->get_report_summary();
		echo '<h3 style="margin-top:1em;">' . esc_html__( 'Coupon coexistence telemetry', 'mp-commerce-promotions' ) . '</h3>';
		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		printf(
			'<li>%1$s: %2$d</li>',
			esc_html__( 'Blocked by coupon (planner)', 'mp-commerce-promotions' ),
			(int) ( $summary['blocked_by_coupon_count'] ?? 0 )
		);
		printf(
			'<li>%1$s: %2$d</li>',
			esc_html__( 'Coexistence fallbacks', 'mp-commerce-promotions' ),
			(int) ( $summary['coexistence_fallback_count'] ?? 0 )
		);
		printf(
			'<li>%1$s: %2$d</li>',
			esc_html__( 'Coupon conflicts (line mode)', 'mp-commerce-promotions' ),
			(int) ( $summary['coupon_conflict_count'] ?? 0 )
		);
		echo '</ul>';
	}

	public static function render_emergency_operations( EmergencyOperationsService $emergency ): void {
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Emergency operations', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Dry-run previews show impact without applying. Apply actions are audited.', 'mp-commerce-promotions' ) . '</p>';
		$actions = array(
			'disable_automatic' => __( 'Disable all automatic promotions (safe mode)', 'mp-commerce-promotions' ),
			'disable_line_mode' => __( 'Disable line-item mode globally', 'mp-commerce-promotions' ),
			'pause_stackable'   => __( 'Pause all stackable promotions', 'mp-commerce-promotions' ),
			'rebuild_caches'    => __( 'Rebuild promotion caches', 'mp-commerce-promotions' ),
			'clear_telemetry'   => __( 'Clear planner telemetry table', 'mp-commerce-promotions' ),
			'reset_degraded'    => __( 'Reset storefront degraded mode', 'mp-commerce-promotions' ),
		);
		foreach ( $actions as $key => $label ) {
			echo '<form method="post" style="margin:0.5em 0;">';
			wp_nonce_field( 'mp_cp_emergency_' . $key, 'mp_cp_emergency_nonce' );
			echo '<input type="hidden" name="mp_cp_emergency_action" value="' . esc_attr( $key ) . '" />';
			echo '<button type="submit" name="mp_cp_emergency_preview" value="1" class="button">' . esc_html__( 'Preview', 'mp-commerce-promotions' ) . ': ' . esc_html( $label ) . '</button> ';
			echo '<button type="submit" name="mp_cp_emergency_apply" value="1" class="button button-secondary">' . esc_html__( 'Apply', 'mp-commerce-promotions' ) . '</button>';
			echo '</form>';
		}
	}

	public static function handle_emergency_post(
		Settings $settings,
		PromotionRepository $promotions,
		?PromotionPerformanceProfiler $profiler,
		?PromotionIntelligenceRecovery $intelligence_recovery,
		?AuditLogger $audit
	): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_emergency_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['mp_cp_emergency_action'] ) );
		$nonce  = isset( $_POST['mp_cp_emergency_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_emergency_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mp_cp_emergency_' . $action ) ) {
			AdminNotice::error( __( 'Emergency operation security check failed.', 'mp-commerce-promotions' ) );
			return;
		}

		$dry_run = ! isset( $_POST['mp_cp_emergency_apply'] );
		$service = new EmergencyOperationsService( $settings, $promotions, $profiler, $intelligence_recovery, $audit );

		$result = null;
		if ( $action === 'disable_automatic' ) {
			$result = $service->disable_automatic_promotions( $dry_run );
		} elseif ( $action === 'disable_line_mode' ) {
			$result = $service->disable_line_item_mode_globally( $dry_run );
		} elseif ( $action === 'pause_stackable' ) {
			$result = $service->pause_stackable_promotions( $dry_run );
		} elseif ( $action === 'rebuild_caches' ) {
			$result = $service->rebuild_promotion_caches( $dry_run );
		} elseif ( $action === 'clear_telemetry' ) {
			$result = $service->clear_planner_telemetry( $dry_run );
		} elseif ( $action === 'reset_degraded' ) {
			$result = $service->reset_degraded_mode( $dry_run );
		}

		if ( $result === null ) {
			return;
		}

		$label = $dry_run ? __( 'Preview', 'mp-commerce-promotions' ) : __( 'Applied', 'mp-commerce-promotions' );
		AdminNotice::success(
			$label . ': ' . esc_html( (string) ( $result['action'] ?? '' ) ) . ' — ' . esc_html( wp_json_encode( $result['summary'] ) ?: '' )
		);
	}
}
