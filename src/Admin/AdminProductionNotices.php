<?php
/**
 * Admin warning banners for production safety toggles.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Service\EcosystemCompatibilityRegistry;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\Settings;

final class AdminProductionNotices {

	public static function register( Settings $settings, ?PromotionPerformanceProfiler $profiler = null ): void {
		add_action(
			'admin_notices',
			static function () use ( $settings, $profiler ): void {
				if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
					return;
				}

				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( $screen === null || strpos( (string) $screen->id, 'mp-commerce-promotions' ) === false ) {
					return;
				}

				if ( $settings->safe_mode_enabled() ) {
					AdminNotice::warning(
						__( 'Safe mode is ON: automatic promotions are disabled. Promotion codes may still apply when allowed in settings.', 'mp-commerce-promotions' )
					);
				}

				if ( $settings->promotion_dry_run_enabled() ) {
					AdminNotice::warning(
						__( 'Promotion dry-run is ON: the planner runs but cart fees are not applied (staging preview).', 'mp-commerce-promotions' )
					);
				}

				$eco = ( new EcosystemCompatibilityRegistry() )->summarize( true );
				if ( (int) ( $eco['score'] ?? 100 ) < 55 && (int) ( $eco['detected_count'] ?? 0 ) > 0 ) {
					AdminNotice::warning(
						__( 'Ecosystem compatibility score is low — review Diagnostics → Ecosystem compatibility before GA rollout.', 'mp-commerce-promotions' )
					);
				}

				if ( $settings->telemetry_paused() ) {
					AdminNotice::warning(
						__( 'Planner telemetry is paused. Aggregate telemetry writes are disabled.', 'mp-commerce-promotions' )
					);
				}

				if ( $settings->simulation_paused() ) {
					AdminNotice::warning(
						__( 'Simulation features are paused.', 'mp-commerce-promotions' )
					);
				}

				if ( $settings->automation_emergency_stop() ) {
					AdminNotice::error(
						__( 'Automation emergency stop is active. Scheduled and manual automation runs are blocked.', 'mp-commerce-promotions' )
					);
				}

				if ( $profiler !== null && $profiler->is_storefront_degraded() ) {
					$state = $profiler->get_degraded_state();
					$msg   = isset( $state['message'] ) ? (string) $state['message'] : '';
					AdminNotice::error(
						sprintf(
							/* translators: %s: short error summary */
							__( 'Storefront planner degraded mode was recorded: %s', 'mp-commerce-promotions' ),
							$msg !== '' ? $msg : __( 'unknown error', 'mp-commerce-promotions' )
						)
					);
				}
			}
		);
	}
}
