<?php
/**
 * Plugin settings (options API).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

final class Settings {

	public const OPTION_CART_DISCOUNTS_ENABLED = 'mp_cp_cart_discounts_enabled';

	public const OPTION_AUTOMATION_MANUAL_ONLY = 'mp_cp_automation_manual_only';

	public const OPTION_CRON_AUTOMATION_ENABLED = 'mp_cp_cron_automation_enabled';

	public const OPTION_PLANNER_TELEMETRY_ENABLED = 'mp_cp_planner_telemetry_enabled';

	public const OPTION_CSV_EXPORT_ENABLED = 'mp_cp_csv_export_enabled';

	public const OPTION_SIMULATIONS_ENABLED = 'mp_cp_simulations_enabled';

	public const OPTION_FREE_GIFT_ENABLED = 'mp_cp_free_gift_enabled';

	public const OPTION_FREE_SHIPPING_ENABLED = 'mp_cp_free_shipping_enabled';

	public const OPTION_PRICING_EXPLAINABILITY_ENABLED = 'mp_cp_pricing_explainability_enabled';

	public const OPTION_RETAIN_DATA_ON_UNINSTALL = 'mp_cp_retain_data_on_uninstall';

	public const OPTION_DELETE_DATA_ON_UNINSTALL = 'mp_cp_delete_data_on_uninstall';

	public const OPTION_SAFE_MODE = 'mp_cp_safe_mode';

	public const OPTION_ALLOW_CODES_IN_SAFE_MODE = 'mp_cp_allow_codes_in_safe_mode';

	public const OPTION_TELEMETRY_PAUSED = 'mp_cp_telemetry_paused';

	public const OPTION_SIMULATION_PAUSED = 'mp_cp_simulation_paused';

	public const OPTION_AUTOMATION_EMERGENCY_STOP = 'mp_cp_automation_emergency_stop';

	public const OPTION_TELEMETRY_RETENTION_DAYS = 'mp_cp_telemetry_retention_days';

	public const OPTION_BLOCKS_HOOK_DEBUG = 'mp_cp_blocks_hook_debug';

	public const OPTION_PROMOTION_DRY_RUN = 'mp_cp_promotion_dry_run';

	private const VALUE_YES = 'yes';

	private const VALUE_NO = 'no';

	private const DEFAULT_RETENTION_DAYS = 90;

	public function cart_discounts_enabled(): bool {
		return $this->is_enabled( self::OPTION_CART_DISCOUNTS_ENABLED, true );
	}

	public function set_cart_discounts_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_CART_DISCOUNTS_ENABLED, $enabled );
	}

	public function automation_manual_only(): bool {
		return $this->is_enabled( self::OPTION_AUTOMATION_MANUAL_ONLY, true );
	}

	public function set_automation_manual_only( bool $enabled ): void {
		$this->set_enabled( self::OPTION_AUTOMATION_MANUAL_ONLY, $enabled );
	}

	public function cron_automation_enabled(): bool {
		return $this->is_enabled( self::OPTION_CRON_AUTOMATION_ENABLED, false );
	}

	public function set_cron_automation_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_CRON_AUTOMATION_ENABLED, $enabled );
	}

	public function planner_telemetry_enabled(): bool {
		return $this->is_enabled( self::OPTION_PLANNER_TELEMETRY_ENABLED, true )
			&& ! $this->telemetry_paused();
	}

	public function set_planner_telemetry_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_PLANNER_TELEMETRY_ENABLED, $enabled );
	}

	public function telemetry_paused(): bool {
		return $this->is_enabled( self::OPTION_TELEMETRY_PAUSED, false );
	}

	public function set_telemetry_paused( bool $paused ): void {
		$this->set_enabled( self::OPTION_TELEMETRY_PAUSED, $paused );
	}

	public function csv_export_enabled(): bool {
		return $this->is_enabled( self::OPTION_CSV_EXPORT_ENABLED, true );
	}

	public function set_csv_export_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_CSV_EXPORT_ENABLED, $enabled );
	}

	public function simulations_enabled(): bool {
		return $this->is_enabled( self::OPTION_SIMULATIONS_ENABLED, true )
			&& ! $this->simulation_paused();
	}

	public function set_simulations_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_SIMULATIONS_ENABLED, $enabled );
	}

	public function simulation_paused(): bool {
		return $this->is_enabled( self::OPTION_SIMULATION_PAUSED, false );
	}

	public function set_simulation_paused( bool $paused ): void {
		$this->set_enabled( self::OPTION_SIMULATION_PAUSED, $paused );
	}

	public function free_gift_enabled(): bool {
		return $this->is_enabled( self::OPTION_FREE_GIFT_ENABLED, true );
	}

	public function set_free_gift_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_FREE_GIFT_ENABLED, $enabled );
	}

	public function free_shipping_enabled(): bool {
		return $this->is_enabled( self::OPTION_FREE_SHIPPING_ENABLED, true );
	}

	public function set_free_shipping_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_FREE_SHIPPING_ENABLED, $enabled );
	}

	public function pricing_explainability_enabled(): bool {
		return $this->is_enabled( self::OPTION_PRICING_EXPLAINABILITY_ENABLED, true );
	}

	public function set_pricing_explainability_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_PRICING_EXPLAINABILITY_ENABLED, $enabled );
	}

	public function retain_data_on_uninstall(): bool {
		return $this->is_enabled( self::OPTION_RETAIN_DATA_ON_UNINSTALL, true );
	}

	public function set_retain_data_on_uninstall( bool $enabled ): void {
		$this->set_enabled( self::OPTION_RETAIN_DATA_ON_UNINSTALL, $enabled );
	}

	public function delete_data_on_uninstall(): bool {
		return $this->is_enabled( self::OPTION_DELETE_DATA_ON_UNINSTALL, false );
	}

	public function set_delete_data_on_uninstall( bool $enabled ): void {
		$this->set_enabled( self::OPTION_DELETE_DATA_ON_UNINSTALL, $enabled );
		if ( $enabled ) {
			$this->set_retain_data_on_uninstall( false );
		}
	}

	public function safe_mode_enabled(): bool {
		return $this->is_enabled( self::OPTION_SAFE_MODE, false );
	}

	public function set_safe_mode_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_SAFE_MODE, $enabled );
	}

	public function allow_codes_in_safe_mode(): bool {
		return $this->is_enabled( self::OPTION_ALLOW_CODES_IN_SAFE_MODE, true );
	}

	public function set_allow_codes_in_safe_mode( bool $enabled ): void {
		$this->set_enabled( self::OPTION_ALLOW_CODES_IN_SAFE_MODE, $enabled );
	}

	public function automatic_promotions_enabled(): bool {
		return $this->cart_discounts_enabled() && ! $this->safe_mode_enabled();
	}

	public function automation_emergency_stop(): bool {
		return $this->is_enabled( self::OPTION_AUTOMATION_EMERGENCY_STOP, false );
	}

	public function set_automation_emergency_stop( bool $stopped ): void {
		$this->set_enabled( self::OPTION_AUTOMATION_EMERGENCY_STOP, $stopped );
	}

	public function telemetry_retention_days(): int {
		$raw  = get_option( self::OPTION_TELEMETRY_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS );
		$days = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_RETENTION_DAYS;

		return max( 7, min( 3650, $days ) );
	}

	public function set_telemetry_retention_days( int $days ): void {
		update_option( self::OPTION_TELEMETRY_RETENTION_DAYS, max( 7, min( 3650, $days ) ), false );
	}

	public function blocks_hook_debug_enabled(): bool {
		return $this->is_enabled( self::OPTION_BLOCKS_HOOK_DEBUG, false );
	}

	public function set_blocks_hook_debug_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_BLOCKS_HOOK_DEBUG, $enabled );
	}

	public function promotion_dry_run_enabled(): bool {
		return $this->is_enabled( self::OPTION_PROMOTION_DRY_RUN, false );
	}

	public function set_promotion_dry_run_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_PROMOTION_DRY_RUN, $enabled );
	}

	/**
	 * @return array<string, bool|int>
	 */
	public function to_feature_flags(): array {
		return array(
			'cart_discounts'            => $this->cart_discounts_enabled(),
			'automatic_promotions'      => $this->automatic_promotions_enabled(),
			'safe_mode'                 => $this->safe_mode_enabled(),
			'automation_manual_only'    => $this->automation_manual_only(),
			'cron_automation'           => $this->cron_automation_enabled(),
			'automation_emergency_stop' => $this->automation_emergency_stop(),
			'planner_telemetry'         => $this->planner_telemetry_enabled(),
			'telemetry_paused'          => $this->telemetry_paused(),
			'csv_export'                => $this->csv_export_enabled(),
			'simulations'               => $this->simulations_enabled(),
			'simulation_paused'         => $this->simulation_paused(),
			'free_gift'                 => $this->free_gift_enabled(),
			'free_shipping'             => $this->free_shipping_enabled(),
			'pricing_explainability'    => $this->pricing_explainability_enabled(),
			'retain_data_on_uninstall'  => $this->retain_data_on_uninstall(),
			'delete_data_on_uninstall'  => $this->delete_data_on_uninstall(),
			'telemetry_retention_days'  => $this->telemetry_retention_days(),
			'blocks_hook_debug'         => $this->blocks_hook_debug_enabled(),
			'promotion_dry_run'         => $this->promotion_dry_run_enabled(),
		);
	}

	private function is_enabled( string $option, bool $default_enabled ): bool {
		$default = $default_enabled ? self::VALUE_YES : self::VALUE_NO;
		$raw     = get_option( $option, $default );

		if ( ! is_string( $raw ) ) {
			return $default_enabled;
		}

		$raw = strtolower( trim( $raw ) );

		return $raw !== self::VALUE_NO;
	}

	private function set_enabled( string $option, bool $enabled ): void {
		update_option( $option, $enabled ? self::VALUE_YES : self::VALUE_NO, false );
	}
}
