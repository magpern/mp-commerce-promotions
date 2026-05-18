<?php
/**
 * Production pilot profile presets (conservative / balanced / aggressive).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

final class ProductionProfilePresets {

	public const PROFILE_CONSERVATIVE = 'conservative';

	public const PROFILE_BALANCED = 'balanced';

	public const PROFILE_AGGRESSIVE = 'aggressive';

	public const OPTION_ACTIVE_PROFILE = 'mp_cp_production_profile';

	/**
	 * @return list<string>
	 */
	public static function allowed_profiles(): array {
		return array(
			self::PROFILE_CONSERVATIVE,
			self::PROFILE_BALANCED,
			self::PROFILE_AGGRESSIVE,
		);
	}

	/**
	 * @return array<string, array<string, bool|int|string>>
	 */
	public static function definitions(): array {
		return array(
			self::PROFILE_CONSERVATIVE => array(
				'label'                       => __( 'Conservative', 'mp-commerce-promotions' ),
				'line_item_mode_disabled'     => true,
				'promotion_dry_run'           => false,
				'planner_telemetry_enabled'   => true,
				'telemetry_paused'            => false,
				'planner_trace_verbose'       => false,
				'cron_automation_enabled'     => false,
				'automation_manual_only'      => true,
				'telemetry_retention_days'    => 30,
				'blocks_hook_debug'           => false,
			),
			self::PROFILE_BALANCED => array(
				'label'                       => __( 'Balanced', 'mp-commerce-promotions' ),
				'line_item_mode_disabled'     => false,
				'promotion_dry_run'           => false,
				'planner_telemetry_enabled'   => true,
				'telemetry_paused'            => false,
				'planner_trace_verbose'       => false,
				'cron_automation_enabled'     => false,
				'automation_manual_only'      => true,
				'telemetry_retention_days'    => 90,
				'blocks_hook_debug'           => false,
			),
			self::PROFILE_AGGRESSIVE => array(
				'label'                       => __( 'Aggressive', 'mp-commerce-promotions' ),
				'line_item_mode_disabled'     => false,
				'promotion_dry_run'           => false,
				'planner_telemetry_enabled'   => true,
				'telemetry_paused'            => false,
				'planner_trace_verbose'       => true,
				'cron_automation_enabled'     => true,
				'automation_manual_only'      => false,
				'telemetry_retention_days'    => 180,
				'blocks_hook_debug'           => true,
			),
		);
	}

	public function get_active_profile(): ?string {
		$raw = get_option( self::OPTION_ACTIVE_PROFILE, '' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}

		return in_array( $raw, self::allowed_profiles(), true ) ? $raw : null;
	}

	/**
	 * @return array{profile: string, dry_run: bool, changes: array<string, array{before: mixed, after: mixed}>}
	 */
	public function preview_apply( string $profile, Settings $settings ): array {
		$def = self::definitions()[ $profile ] ?? null;
		if ( $def === null ) {
			return array(
				'profile'  => $profile,
				'dry_run'  => true,
				'changes'  => array(),
			);
		}

		return array(
			'profile' => $profile,
			'dry_run' => true,
			'changes' => $this->diff_settings( $settings, $def ),
		);
	}

	public function apply( string $profile, Settings $settings ): void {
		$def = self::definitions()[ $profile ] ?? null;
		if ( $def === null ) {
			return;
		}

		$settings->set_line_item_mode_disabled( (bool) $def['line_item_mode_disabled'] );
		$settings->set_promotion_dry_run_enabled( (bool) $def['promotion_dry_run'] );
		$settings->set_planner_telemetry_enabled( (bool) $def['planner_telemetry_enabled'] );
		$settings->set_telemetry_paused( (bool) $def['telemetry_paused'] );
		$settings->set_planner_trace_verbose( (bool) $def['planner_trace_verbose'] );
		$settings->set_cron_automation_enabled( (bool) $def['cron_automation_enabled'] );
		$settings->set_automation_manual_only( (bool) $def['automation_manual_only'] );
		$settings->set_telemetry_retention_days( (int) $def['telemetry_retention_days'] );
		$settings->set_blocks_hook_debug_enabled( (bool) $def['blocks_hook_debug'] );
		update_option( self::OPTION_ACTIVE_PROFILE, $profile, false );
	}

	/**
	 * @param array<string, bool|int|string> $target
	 * @return array<string, array{before: mixed, after: mixed}>
	 */
	private function diff_settings( Settings $settings, array $target ): array {
		$map = array(
			'line_item_mode_disabled'   => $settings->line_item_mode_disabled(),
			'promotion_dry_run'         => $settings->promotion_dry_run_enabled(),
			'planner_telemetry_enabled' => $settings->planner_telemetry_enabled(),
			'telemetry_paused'          => $settings->telemetry_paused(),
			'planner_trace_verbose'     => $settings->planner_trace_verbose(),
			'cron_automation_enabled'   => $settings->cron_automation_enabled(),
			'automation_manual_only'    => $settings->automation_manual_only(),
			'telemetry_retention_days'  => $settings->telemetry_retention_days(),
			'blocks_hook_debug'         => $settings->blocks_hook_debug_enabled(),
		);

		$changes = array();
		foreach ( $target as $key => $after ) {
			if ( $key === 'label' ) {
				continue;
			}
			$before = $map[ $key ] ?? null;
			if ( $before !== $after ) {
				$changes[ $key ] = array(
					'before' => $before,
					'after'  => $after,
				);
			}
		}

		return $changes;
	}
}
