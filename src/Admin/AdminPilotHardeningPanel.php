<?php
/**
 * Production pilot: rollback, anomalies, profile presets (Diagnostics).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\AuditLogRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionSnapshotRepository;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\OperationalRollbackService;
use MP\CommercePromotions\Service\ProductionProfilePresets;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\PromotionSnapshotService;
use MP\CommercePromotions\Service\RuntimeAnomalyDetector;
use MP\CommercePromotions\Service\Settings;

final class AdminPilotHardeningPanel {

	public static function handle_post(
		Settings $settings,
		?PromotionSnapshotService $snapshots,
		?PromotionRepository $promotions,
		?PromotionSnapshotRepository $snapshot_repo,
		?AuditLogRepository $audit_logs,
		?AuditLogger $audit
	): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		self::handle_rollback_post( $settings, $snapshots, $promotions, $snapshot_repo, $audit_logs, $audit );
		self::handle_profile_post( $settings, $audit );
	}

	public static function render(
		Settings $settings,
		?PromotionPerformanceProfiler $profiler,
		?PromotionSnapshotService $snapshots,
		?PromotionRepository $promotions,
		?PromotionSnapshotRepository $snapshot_repo,
		?AuditLogRepository $audit_logs
	): void {
		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Rollback and performance profiles', 'mp-commerce-promotions' ) . '</h2>';

		self::render_anomaly_table( $profiler );
		self::render_profile_presets( $settings );
		self::render_rollback_tools( $settings, $snapshots, $promotions, $snapshot_repo, $audit_logs );
	}

	private static function render_anomaly_table( ?PromotionPerformanceProfiler $profiler ): void {
		$detector = new RuntimeAnomalyDetector();
		$rows     = $detector->active_anomalies( $profiler );
		$counters = $detector->counter_summary();

		echo '<h3>' . esc_html__( 'Runtime anomalies', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Rolling heuristics from planner samples, degraded mode, and line fallbacks (no external telemetry).', 'mp-commerce-promotions' ) . '</p>';

		if ( $rows === array() ) {
			echo '<p>' . esc_html__( 'No active anomaly indicators.', 'mp-commerce-promotions' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:960px;"><thead><tr>';
			echo '<th>' . esc_html__( 'Code', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Severity', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Message', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Metric', 'mp-commerce-promotions' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$severity = (string) ( $row['severity'] ?? 'low' );
				$style    = $severity === 'high' ? ' style="background:#fde8e8;"' : ( $severity === 'medium' ? ' style="background:#fff8e5;"' : '' );
				echo '<tr' . $style . '>';
				echo '<td><code>' . esc_html( (string) ( $row['code'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( $severity ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['message'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['metric'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<p class="description"><strong>' . esc_html__( 'Counters', 'mp-commerce-promotions' ) . ':</strong> ';
		echo esc_html( wp_json_encode( $counters ) ?: '{}' );
		echo '</p>';
	}

	private static function render_profile_presets( Settings $settings ): void {
		$presets = new ProductionProfilePresets();
		$active  = $presets->get_active_profile();

		echo '<h3>' . esc_html__( 'Production profile presets', 'mp-commerce-promotions' ) . '</h3>';
		if ( $active !== null ) {
			echo '<p>' . esc_html__( 'Active profile', 'mp-commerce-promotions' ) . ': <strong>' . esc_html( $active ) . '</strong></p>';
		}

		foreach ( ProductionProfilePresets::definitions() as $key => $def ) {
			$label = (string) ( $def['label'] ?? $key );
			echo '<form method="post" style="margin:0.5em 0;display:inline-block;">';
			wp_nonce_field( 'mp_cp_profile_' . $key, 'mp_cp_profile_nonce' );
			echo '<input type="hidden" name="mp_cp_profile_key" value="' . esc_attr( $key ) . '" />';
			echo '<button type="submit" name="mp_cp_profile_preview" value="1" class="button">' . esc_html__( 'Preview', 'mp-commerce-promotions' ) . ': ' . esc_html( $label ) . '</button> ';
			echo '<button type="submit" name="mp_cp_profile_apply" value="1" class="button button-secondary">' . esc_html__( 'Apply', 'mp-commerce-promotions' ) . '</button>';
			echo '</form> ';
		}
	}

	private static function render_rollback_tools(
		Settings $settings,
		?PromotionSnapshotService $snapshots,
		?PromotionRepository $promotions,
		?PromotionSnapshotRepository $snapshot_repo,
		?AuditLogRepository $audit_logs
	): void {
		if ( $snapshots === null || $promotions === null || $snapshot_repo === null || $audit_logs === null ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Operational rollback', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Preview before apply. All apply actions are audited.', 'mp-commerce-promotions' ) . '</p>';

		echo '<form method="post" style="margin:0.75em 0;">';
		wp_nonce_field( 'mp_cp_rollback_snapshot', 'mp_cp_rollback_nonce' );
		echo '<label>' . esc_html__( 'Rollback promotion to snapshot ID', 'mp-commerce-promotions' ) . '</label> ';
		echo '<input type="number" name="mp_cp_rollback_snapshot_id" min="1" class="small-text" /> ';
		echo '<button type="submit" name="mp_cp_rollback_preview" value="1" class="button">' . esc_html__( 'Preview', 'mp-commerce-promotions' ) . '</button> ';
		echo '<button type="submit" name="mp_cp_rollback_apply" value="1" class="button button-secondary">' . esc_html__( 'Apply', 'mp-commerce-promotions' ) . '</button>';
		echo '</form>';

		echo '<form method="post" style="margin:0.75em 0;">';
		wp_nonce_field( 'mp_cp_rollback_modified', 'mp_cp_rollback_nonce' );
		echo '<label>' . esc_html__( 'Rollback promotions modified in last (hours)', 'mp-commerce-promotions' ) . '</label> ';
		echo '<input type="number" name="mp_cp_rollback_hours" value="24" min="1" max="168" class="small-text" /> ';
		echo '<button type="submit" name="mp_cp_rollback_action" value="rollback_modified" class="button">' . esc_html__( 'Preview', 'mp-commerce-promotions' ) . '</button> ';
		echo '<button type="submit" name="mp_cp_rollback_action_apply" value="rollback_modified" class="button button-secondary">' . esc_html__( 'Apply', 'mp-commerce-promotions' ) . '</button>';
		echo '</form>';

		$actions = array(
			'rollback_dry_run'   => __( 'Rollback dry-run activations', 'mp-commerce-promotions' ),
			'rollback_emergency' => __( 'Rollback recent emergency disable actions', 'mp-commerce-promotions' ),
		);
		foreach ( $actions as $action => $label ) {
			echo '<form method="post" style="margin:0.5em 0;">';
			wp_nonce_field( 'mp_cp_rollback_' . $action, 'mp_cp_rollback_nonce' );
			echo '<input type="hidden" name="mp_cp_rollback_action" value="' . esc_attr( $action ) . '" />';
			echo '<button type="submit" name="mp_cp_rollback_preview" value="1" class="button">' . esc_html__( 'Preview', 'mp-commerce-promotions' ) . ': ' . esc_html( $label ) . '</button> ';
			echo '<button type="submit" name="mp_cp_rollback_apply" value="1" class="button button-secondary">' . esc_html__( 'Apply', 'mp-commerce-promotions' ) . '</button>';
			echo '</form>';
		}
	}

	private static function handle_rollback_post(
		Settings $settings,
		?PromotionSnapshotService $snapshots,
		?PromotionRepository $promotions,
		?PromotionSnapshotRepository $snapshot_repo,
		?AuditLogRepository $audit_logs,
		?AuditLogger $audit
	): void {
		if ( $snapshots === null || $promotions === null || $snapshot_repo === null || $audit_logs === null ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_rollback_nonce'] ) ) {
			return;
		}

		$dry_run = ! isset( $_POST['mp_cp_rollback_apply'] ) && ! isset( $_POST['mp_cp_rollback_action_apply'] );
		$service = new OperationalRollbackService( $snapshots, $promotions, $snapshot_repo, $audit_logs, $settings, $audit );

		if ( isset( $_POST['mp_cp_rollback_snapshot_id'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_rollback_nonce'] ) ), 'mp_cp_rollback_snapshot' ) ) {
				AdminNotice::error( __( 'Rollback security check failed.', 'mp-commerce-promotions' ) );
				return;
			}
			$snapshot_id = (int) $_POST['mp_cp_rollback_snapshot_id'];
			$result      = $service->rollback_promotion_to_snapshot( $snapshot_id, $dry_run );
			self::notice_result( $result, $dry_run );
			return;
		}

		$action = isset( $_POST['mp_cp_rollback_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['mp_cp_rollback_action'] ) ) : '';
		if ( $action === '' && isset( $_POST['mp_cp_rollback_action_apply'] ) ) {
			$action = sanitize_key( wp_unslash( (string) $_POST['mp_cp_rollback_action_apply'] ) );
			$dry_run = false;
		}

		if ( $action === 'rollback_modified' ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_rollback_nonce'] ) ), 'mp_cp_rollback_modified' ) ) {
				AdminNotice::error( __( 'Rollback security check failed.', 'mp-commerce-promotions' ) );
				return;
			}
			$hours  = isset( $_POST['mp_cp_rollback_hours'] ) ? (int) $_POST['mp_cp_rollback_hours'] : 24;
			$result = $service->rollback_modified_in_hours( $hours, $dry_run );
			self::notice_result( $result, $dry_run );
			return;
		}

		if ( $action === 'rollback_dry_run' || $action === 'rollback_emergency' ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_rollback_nonce'] ) ), 'mp_cp_rollback_' . $action ) ) {
				AdminNotice::error( __( 'Rollback security check failed.', 'mp-commerce-promotions' ) );
				return;
			}
			$result = $action === 'rollback_dry_run'
				? $service->rollback_dry_run_activations( $dry_run )
				: $service->rollback_emergency_disable_actions( $dry_run );
			self::notice_result( $result, $dry_run );
		}
	}

	private static function handle_profile_post( Settings $settings, ?AuditLogger $audit ): void {
		if ( ! isset( $_POST['mp_cp_profile_key'] ) ) {
			return;
		}

		$key = sanitize_key( wp_unslash( (string) $_POST['mp_cp_profile_key'] ) );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) ( $_POST['mp_cp_profile_nonce'] ?? '' ) ) ), 'mp_cp_profile_' . $key ) ) {
			AdminNotice::error( __( 'Profile preset security check failed.', 'mp-commerce-promotions' ) );
			return;
		}

		$presets = new ProductionProfilePresets();
		if ( isset( $_POST['mp_cp_profile_preview'] ) ) {
			$preview = $presets->preview_apply( $key, $settings );
			AdminNotice::success(
				__( 'Profile preview', 'mp-commerce-promotions' ) . ': ' . esc_html( wp_json_encode( $preview['changes'] ) ?: '{}' )
			);
			return;
		}

		if ( isset( $_POST['mp_cp_profile_apply'] ) ) {
			$before = $settings->to_feature_flags();
			$presets->apply( $key, $settings );
			if ( $audit !== null ) {
				$audit->log(
					'pilot.profile_preset_applied',
					null,
					array(
						'profile' => $key,
						'before'  => $before,
						'after'   => $settings->to_feature_flags(),
					),
					get_current_user_id()
				);
			}
			AdminNotice::success(
				sprintf(
					/* translators: %s: profile key */
					__( 'Applied production profile: %s', 'mp-commerce-promotions' ),
					$key
				)
			);
		}
	}

	/**
	 * @param array{dry_run: bool, action: string, summary: array<string, mixed>} $result
	 */
	private static function notice_result( array $result, bool $dry_run ): void {
		$label = $dry_run ? __( 'Preview', 'mp-commerce-promotions' ) : __( 'Applied', 'mp-commerce-promotions' );
		AdminNotice::success(
			$label . ': ' . esc_html( (string) ( $result['action'] ?? '' ) ) . ' — ' . esc_html( wp_json_encode( $result['summary'] ) ?: '' )
		);
	}
}
