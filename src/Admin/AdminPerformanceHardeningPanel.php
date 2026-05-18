<?php
/**
 * Diagnostics sections for performance, concurrency, cron, and cleanup.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Service\PromotionConcurrencyGuard;
use MP\CommercePromotions\Service\PromotionCronScheduler;
use MP\CommercePromotions\Service\PromotionDataRetentionService;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\PromotionSubsystemRecovery;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;

final class AdminPerformanceHardeningPanel {

	private const CLEANUP_NONCE_ACTION = 'mp_cp_run_retention_cleanup';

	private const CLEANUP_NONCE_FIELD = 'mp_cp_run_retention_cleanup_nonce';

	private const CLEANUP_SUBMIT = 'mp_cp_run_retention_cleanup_submit';

	private const CLEAR_DEGRADED_SUBMIT = 'mp_cp_clear_degraded_submit';

	private const CLEAR_DEGRADED_NONCE_ACTION = 'mp_cp_clear_degraded';

	private const CLEAR_DEGRADED_NONCE_FIELD = 'mp_cp_clear_degraded_nonce';

	public static function handle_post(
		Settings $settings,
		?PromotionDataRetentionService $retention,
		?PromotionPerformanceProfiler $profiler,
		?PromotionCronScheduler $cron
	): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( isset( $_POST[ self::CLEANUP_SUBMIT ] ) && $retention !== null ) {
			self::handle_cleanup_post( $retention );
		}

		if ( isset( $_POST[ self::CLEAR_DEGRADED_SUBMIT ] ) && $profiler !== null ) {
			self::handle_clear_degraded_post( $profiler );
		}

		if ( isset( $_POST['mp_cp_reschedule_cron_submit'] ) && $cron !== null ) {
			check_admin_referer( 'mp_cp_reschedule_cron', 'mp_cp_reschedule_cron_nonce' );
			$cron->reschedule();
			wp_safe_redirect( AdminUrl::diagnostics( array( 'mp_cp_notice' => 'cron_rescheduled' ) ) );
			exit;
		}
	}

	public static function render(
		Settings $settings,
		?PromotionPerformanceProfiler $profiler,
		?PromotionConcurrencyGuard $concurrency,
		?PromotionCronScheduler $cron,
		?PromotionDataRetentionService $retention,
		?PromotionSubsystemRecovery $subsystem_recovery
	): void {
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Performance & hardening', 'mp-commerce-promotions' ) . '</h2>';

		if ( $profiler !== null ) {
			$summary = $profiler->get_report_summary();
			echo '<h3>' . esc_html__( 'Planner profiler', 'mp-commerce-promotions' ) . '</h3>';
			echo '<table class="widefat striped"><tbody>';
			printf(
				'<tr><th>%s</th><td>%d</td></tr>',
				esc_html__( 'Planner runs', 'mp-commerce-promotions' ),
				(int) ( $summary['planner_runs'] ?? 0 )
			);
			printf(
				'<tr><th>%s</th><td>%s ms</td></tr>',
				esc_html__( 'Average planner runtime', 'mp-commerce-promotions' ),
				esc_html( (string) ( $summary['average_planner_ms'] ?? 0 ) )
			);
			printf(
				'<tr><th>%s</th><td>%s%%</td></tr>',
				esc_html__( 'Allocation cache hit rate', 'mp-commerce-promotions' ),
				esc_html( (string) ( $summary['allocation_cache_hit_rate'] ?? 0 ) )
			);
			printf(
				'<tr><th>%s</th><td>%d</td></tr>',
				esc_html__( 'Planner failures', 'mp-commerce-promotions' ),
				(int) ( $summary['planner_failures'] ?? 0 )
			);
			$buckets = is_array( $summary['timing_buckets'] ?? null ) ? $summary['timing_buckets'] : array();
			if ( $buckets !== array() ) {
				printf(
					'<tr><th>%s</th><td>%s</td></tr>',
					esc_html__( 'Planner timing buckets', 'mp-commerce-promotions' ),
					esc_html( wp_json_encode( $buckets ) )
				);
			}
			echo '</tbody></table>';

			if ( $profiler->is_storefront_degraded() ) {
				echo '<p><strong>' . esc_html__( 'Storefront degraded mode is active.', 'mp-commerce-promotions' ) . '</strong></p>';
				echo '<form method="post" style="margin:0.5em 0;">';
				wp_nonce_field( self::CLEAR_DEGRADED_NONCE_ACTION, self::CLEAR_DEGRADED_NONCE_FIELD );
				echo '<button type="submit" name="' . esc_attr( self::CLEAR_DEGRADED_SUBMIT ) . '" value="1" class="button">';
				echo esc_html__( 'Clear degraded state', 'mp-commerce-promotions' );
				echo '</button></form>';
			}
		}

		if ( $concurrency !== null ) {
			$warnings = $concurrency->get_warnings();
			echo '<h3>' . esc_html__( 'Concurrency warnings', 'mp-commerce-promotions' ) . '</h3>';
			if ( $warnings === array() ) {
				echo '<p>' . esc_html__( 'No recent concurrency warnings.', 'mp-commerce-promotions' ) . '</p>';
			} else {
				echo '<ul>';
				foreach ( array_slice( $warnings, 0, 10 ) as $warning ) {
					echo '<li><code>' . esc_html( (string) ( $warning['code'] ?? '' ) ) . '</code> — ';
					echo esc_html( (string) ( $warning['message'] ?? '' ) );
					echo ' <em>(' . esc_html( (string) ( $warning['recorded_at'] ?? '' ) ) . ')</em></li>';
				}
				echo '</ul>';
			}
		}

		echo '<h3>' . esc_html__( 'WP-Cron automation', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p>';
		echo esc_html(
			$settings->cron_automation_enabled()
				? __( 'Cron automation is enabled.', 'mp-commerce-promotions' )
				: __( 'Cron automation is disabled (default).', 'mp-commerce-promotions' )
		);
		echo '</p>';
		if ( $cron !== null ) {
			printf(
				'<p>%s: %s<br/>%s: %s</p>',
				esc_html__( 'Hourly hook scheduled', 'mp-commerce-promotions' ),
				wp_next_scheduled( PromotionCronScheduler::HOOK_HOURLY ) ? esc_html__( 'yes', 'mp-commerce-promotions' ) : esc_html__( 'no', 'mp-commerce-promotions' ),
				esc_html__( 'Daily hook scheduled', 'mp-commerce-promotions' ),
				wp_next_scheduled( PromotionCronScheduler::HOOK_DAILY ) ? esc_html__( 'yes', 'mp-commerce-promotions' ) : esc_html__( 'no', 'mp-commerce-promotions' )
			);
			echo '<form method="post" style="margin:0.5em 0;">';
			wp_nonce_field( 'mp_cp_reschedule_cron', 'mp_cp_reschedule_cron_nonce' );
			echo '<button type="submit" name="mp_cp_reschedule_cron_submit" value="1" class="button">';
			echo esc_html__( 'Reschedule cron hooks', 'mp-commerce-promotions' );
			echo '</button></form>';
		}

		if ( $retention !== null ) {
			$estimates = $retention->storage_estimates();
			$preview   = $retention->run_daily_cleanup( true );
			echo '<h3>' . esc_html__( 'Storage & cleanup', 'mp-commerce-promotions' ) . '</h3>';
			echo '<table class="widefat striped"><tbody>';
			foreach ( $estimates as $key => $value ) {
				$display = is_int( $value ) ? (string) $value : ( is_string( $value ) ? $value : wp_json_encode( $value ) );
				printf(
					'<tr><th>%s</th><td>%s</td></tr>',
					esc_html( (string) $key ),
					esc_html( (string) $display )
				);
			}
			echo '</tbody></table>';
			echo '<p class="description">';
			printf(
				/* translators: 1: retention days, 2: automation, 3: telemetry, 4: scenarios, 5: certification, 6: snapshots */
				esc_html__( 'Retention: %1$d days. Dry-run cleanup: automation %2$d, telemetry %3$d, scenarios %4$d, certification %5$d, snapshots %6$d; profiler/anomaly counters reset on apply.', 'mp-commerce-promotions' ),
				$settings->telemetry_retention_days(),
				(int) ( $preview['automation_runs'] ?? 0 ),
				(int) ( $preview['planner_telemetry_rows'] ?? 0 ),
				(int) ( $preview['scenarios_archived'] ?? 0 ),
				(int) ( $preview['certification_runs'] ?? 0 ),
				(int) ( $preview['snapshots_pruned'] ?? 0 )
			);
			echo '</p>';
			echo '<form method="post" style="margin:0.5em 0;" onsubmit="return confirm(\'' . esc_js( __( 'Run retention cleanup now?', 'mp-commerce-promotions' ) ) . '\');">';
			wp_nonce_field( self::CLEANUP_NONCE_ACTION, self::CLEANUP_NONCE_FIELD );
			echo '<button type="submit" name="' . esc_attr( self::CLEANUP_SUBMIT ) . '" value="1" class="button">';
			echo esc_html__( 'Run cleanup now', 'mp-commerce-promotions' );
			echo '</button></form>';
		}

		if ( $subsystem_recovery !== null ) {
			$compat = $subsystem_recovery->safe_compatibility_audit();
			echo '<h3>' . esc_html__( 'Compatibility confidence', 'mp-commerce-promotions' ) . '</h3>';
			printf(
				'<p><strong>%s</strong>: %s</p>',
				esc_html__( 'Confidence', 'mp-commerce-promotions' ),
				esc_html( (string) ( $compat['confidence'] ?? PricingCompatibilityAnalyzer::CONFIDENCE_UNKNOWN ) )
			);
			$analyzer = new PricingCompatibilityAnalyzer();
			$audit    = $analyzer->audit_with_confidence();
			if ( ! empty( $audit['recommendations'] ) && is_array( $audit['recommendations'] ) ) {
				echo '<ul>';
				foreach ( $audit['recommendations'] as $rec ) {
					echo '<li>' . esc_html( (string) $rec ) . '</li>';
				}
				echo '</ul>';
			}
		}
	}

	private static function handle_cleanup_post( PromotionDataRetentionService $retention ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::CLEANUP_NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::CLEANUP_NONCE_FIELD ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::CLEANUP_NONCE_ACTION ) ) {
			return;
		}

		$retention->run_daily_cleanup( false );
		wp_safe_redirect( AdminUrl::diagnostics( array( 'mp_cp_notice' => 'cleanup_done' ) ) );
		exit;
	}

	private static function handle_clear_degraded_post( PromotionPerformanceProfiler $profiler ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::CLEAR_DEGRADED_NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::CLEAR_DEGRADED_NONCE_FIELD ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::CLEAR_DEGRADED_NONCE_ACTION ) ) {
			return;
		}

		$profiler->clear_degraded_state();
		wp_safe_redirect( AdminUrl::diagnostics( array( 'mp_cp_notice' => 'degraded_cleared' ) ) );
		exit;
	}
}
