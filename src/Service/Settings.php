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

	public const OPTION_PLANNER_TELEMETRY_ENABLED = 'mp_cp_planner_telemetry_enabled';

	public const OPTION_CSV_EXPORT_ENABLED = 'mp_cp_csv_export_enabled';

	public const OPTION_SIMULATIONS_ENABLED = 'mp_cp_simulations_enabled';

	public const OPTION_FREE_GIFT_ENABLED = 'mp_cp_free_gift_enabled';

	public const OPTION_FREE_SHIPPING_ENABLED = 'mp_cp_free_shipping_enabled';

	public const OPTION_PRICING_EXPLAINABILITY_ENABLED = 'mp_cp_pricing_explainability_enabled';

	public const OPTION_RETAIN_DATA_ON_UNINSTALL = 'mp_cp_retain_data_on_uninstall';

	public const OPTION_DELETE_DATA_ON_UNINSTALL = 'mp_cp_delete_data_on_uninstall';

	private const VALUE_YES = 'yes';

	private const VALUE_NO = 'no';

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

	public function planner_telemetry_enabled(): bool {
		return $this->is_enabled( self::OPTION_PLANNER_TELEMETRY_ENABLED, true );
	}

	public function set_planner_telemetry_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_PLANNER_TELEMETRY_ENABLED, $enabled );
	}

	public function csv_export_enabled(): bool {
		return $this->is_enabled( self::OPTION_CSV_EXPORT_ENABLED, true );
	}

	public function set_csv_export_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_CSV_EXPORT_ENABLED, $enabled );
	}

	public function simulations_enabled(): bool {
		return $this->is_enabled( self::OPTION_SIMULATIONS_ENABLED, true );
	}

	public function set_simulations_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_SIMULATIONS_ENABLED, $enabled );
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

	/**
	 * @return array<string, bool>
	 */
	public function to_feature_flags(): array {
		return array(
			'cart_discounts'           => $this->cart_discounts_enabled(),
			'automation_manual_only'   => $this->automation_manual_only(),
			'planner_telemetry'        => $this->planner_telemetry_enabled(),
			'csv_export'               => $this->csv_export_enabled(),
			'simulations'              => $this->simulations_enabled(),
			'free_gift'                => $this->free_gift_enabled(),
			'free_shipping'            => $this->free_shipping_enabled(),
			'pricing_explainability'   => $this->pricing_explainability_enabled(),
			'retain_data_on_uninstall' => $this->retain_data_on_uninstall(),
			'delete_data_on_uninstall' => $this->delete_data_on_uninstall(),
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
