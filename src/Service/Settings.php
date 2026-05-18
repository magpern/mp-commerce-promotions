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

	public const OPTION_LINE_ITEM_MODE_DISABLED = 'mp_cp_line_item_mode_disabled';

	public const OPTION_PLANNER_TRACE_VERBOSE = 'mp_cp_planner_trace_verbose';

	public const OPTION_GIFT_CARD_DELIVERY_EMAIL = 'mp_cp_gift_card_delivery_email_enabled';

	public const OPTION_GIFT_CARD_BALANCE_CHECKER = 'mp_cp_gift_card_balance_checker_enabled';

	public const OPTION_GIFT_CARD_MY_ACCOUNT = 'mp_cp_gift_card_my_account_enabled';

	public const OPTION_GIFT_CARD_EMAIL_TEMPLATE = 'mp_cp_gift_card_email_template';

	public const OPTION_GIFT_CARD_LOGO_URL = 'mp_cp_gift_card_logo_url';

	public const OPTION_GIFT_CARD_ACCENT_COLOR = 'mp_cp_gift_card_accent_color';

	public const OPTION_GIFT_CARD_SENDER_NAME = 'mp_cp_gift_card_sender_name';

	public const OPTION_GIFT_CARD_SENDER_EMAIL = 'mp_cp_gift_card_sender_email';

	public const OPTION_GIFT_CARD_SENDER_MODE = 'mp_cp_gift_card_sender_mode';

	public const OPTION_GIFT_CARD_REPLY_TO_EMAIL = 'mp_cp_gift_card_reply_to_email';

	public const GIFT_CARD_SENDER_MODE_DEFAULT = 'default';

	public const GIFT_CARD_SENDER_MODE_CUSTOM = 'custom';

	public const OPTION_GIFT_CARD_SCHEDULED_CRON = 'mp_cp_gift_card_scheduled_cron_enabled';

	public const OPTION_GIFT_CARD_SUPPORT_TEXT = 'mp_cp_gift_card_support_email_text';

	public const OPTION_GIFT_CARD_BALANCE_PAGE_ID = 'mp_cp_gift_card_balance_page_id';

	public const GIFT_CARD_TEMPLATE_CLASSIC = 'classic';

	public const GIFT_CARD_TEMPLATE_BIRTHDAY = 'birthday';

	public const GIFT_CARD_TEMPLATE_HOLIDAY = 'holiday';

	public const GIFT_CARD_TEMPLATE_MINIMAL = 'minimal';

	private const DEFAULT_GIFT_CARD_ACCENT = '#2271b1';

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

	public function line_item_mode_disabled(): bool {
		return $this->is_enabled( self::OPTION_LINE_ITEM_MODE_DISABLED, false );
	}

	public function set_line_item_mode_disabled( bool $disabled ): void {
		$this->set_enabled( self::OPTION_LINE_ITEM_MODE_DISABLED, $disabled );
	}

	public function planner_trace_verbose(): bool {
		return $this->is_enabled( self::OPTION_PLANNER_TRACE_VERBOSE, false );
	}

	public function set_planner_trace_verbose( bool $enabled ): void {
		$this->set_enabled( self::OPTION_PLANNER_TRACE_VERBOSE, $enabled );
	}

	public function gift_card_delivery_email_enabled(): bool {
		return $this->is_enabled( self::OPTION_GIFT_CARD_DELIVERY_EMAIL, true );
	}

	public function set_gift_card_delivery_email_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_GIFT_CARD_DELIVERY_EMAIL, $enabled );
	}

	public function gift_card_balance_checker_enabled(): bool {
		return $this->is_enabled( self::OPTION_GIFT_CARD_BALANCE_CHECKER, true );
	}

	public function set_gift_card_balance_checker_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_GIFT_CARD_BALANCE_CHECKER, $enabled );
	}

	public function gift_card_my_account_enabled(): bool {
		return $this->is_enabled( self::OPTION_GIFT_CARD_MY_ACCOUNT, true );
	}

	public function set_gift_card_my_account_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_GIFT_CARD_MY_ACCOUNT, $enabled );
	}

	public function gift_card_scheduled_cron_enabled(): bool {
		return $this->is_enabled( self::OPTION_GIFT_CARD_SCHEDULED_CRON, true );
	}

	public function set_gift_card_scheduled_cron_enabled( bool $enabled ): void {
		$this->set_enabled( self::OPTION_GIFT_CARD_SCHEDULED_CRON, $enabled );
	}

	public function gift_card_email_template(): string {
		$raw = get_option( self::OPTION_GIFT_CARD_EMAIL_TEMPLATE, self::GIFT_CARD_TEMPLATE_CLASSIC );
		$slug = is_string( $raw ) ? sanitize_key( $raw ) : self::GIFT_CARD_TEMPLATE_CLASSIC;
		if ( ! in_array( $slug, self::gift_card_email_templates(), true ) ) {
			return self::GIFT_CARD_TEMPLATE_CLASSIC;
		}

		return $slug;
	}

	public function set_gift_card_email_template( string $template ): void {
		$template = sanitize_key( $template );
		if ( ! in_array( $template, self::gift_card_email_templates(), true ) ) {
			$template = self::GIFT_CARD_TEMPLATE_CLASSIC;
		}
		update_option( self::OPTION_GIFT_CARD_EMAIL_TEMPLATE, $template, false );
	}

	/**
	 * @return list<string>
	 */
	public static function gift_card_email_templates(): array {
		return array(
			self::GIFT_CARD_TEMPLATE_CLASSIC,
			self::GIFT_CARD_TEMPLATE_BIRTHDAY,
			self::GIFT_CARD_TEMPLATE_HOLIDAY,
			self::GIFT_CARD_TEMPLATE_MINIMAL,
		);
	}

	public function gift_card_logo_url(): string {
		$raw = get_option( self::OPTION_GIFT_CARD_LOGO_URL, '' );
		$url = is_string( $raw ) ? esc_url_raw( trim( $raw ) ) : '';

		return $url;
	}

	public function set_gift_card_logo_url( string $url ): void {
		update_option( self::OPTION_GIFT_CARD_LOGO_URL, esc_url_raw( trim( $url ) ), false );
	}

	public function gift_card_accent_color(): string {
		$raw = get_option( self::OPTION_GIFT_CARD_ACCENT_COLOR, self::DEFAULT_GIFT_CARD_ACCENT );
		$color = is_string( $raw ) ? trim( $raw ) : self::DEFAULT_GIFT_CARD_ACCENT;
		if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ) {
			return self::DEFAULT_GIFT_CARD_ACCENT;
		}

		return $color;
	}

	public function set_gift_card_accent_color( string $color ): void {
		$color = trim( $color );
		if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ) {
			$color = self::DEFAULT_GIFT_CARD_ACCENT;
		}
		update_option( self::OPTION_GIFT_CARD_ACCENT_COLOR, $color, false );
	}

	public function gift_card_sender_name(): string {
		$raw = get_option( self::OPTION_GIFT_CARD_SENDER_NAME, '' );

		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
	}

	public function set_gift_card_sender_name( string $name ): void {
		update_option( self::OPTION_GIFT_CARD_SENDER_NAME, sanitize_text_field( $name ), false );
	}

	public function gift_card_sender_email(): string {
		$raw = get_option( self::OPTION_GIFT_CARD_SENDER_EMAIL, '' );
		$email = is_string( $raw ) ? sanitize_email( trim( $raw ) ) : '';

		return is_email( $email ) ? $email : '';
	}

	public function set_gift_card_sender_email( string $email ): void {
		update_option( self::OPTION_GIFT_CARD_SENDER_EMAIL, sanitize_email( trim( $email ) ), false );
	}

	/**
	 * Stored sender mode (valid default/custom only; does not require a custom email).
	 */
	public function gift_card_sender_mode_stored(): string {
		return self::normalize_gift_card_sender_mode(
			get_option( self::OPTION_GIFT_CARD_SENDER_MODE, false ),
			'',
			false
		);
	}

	/**
	 * Effective sender mode for UI and delivery (custom only when stored and email is valid).
	 */
	public function gift_card_sender_mode(): string {
		return self::normalize_gift_card_sender_mode(
			get_option( self::OPTION_GIFT_CARD_SENDER_MODE, false ),
			$this->gift_card_sender_email(),
			true
		);
	}

	/**
	 * @param mixed $raw Option value from the database.
	 */
	public static function normalize_gift_card_sender_mode(
		$raw,
		string $custom_email = '',
		bool $require_valid_email_for_custom = true
	): string {
		if ( $raw === false || $raw === null ) {
			return self::GIFT_CARD_SENDER_MODE_DEFAULT;
		}
		if ( ! is_string( $raw ) ) {
			return self::GIFT_CARD_SENDER_MODE_DEFAULT;
		}
		$trimmed = trim( $raw );
		if ( $trimmed === '' ) {
			return self::GIFT_CARD_SENDER_MODE_DEFAULT;
		}
		$mode = sanitize_key( $trimmed );
		if ( ! in_array( $mode, self::gift_card_sender_modes(), true ) ) {
			return self::GIFT_CARD_SENDER_MODE_DEFAULT;
		}
		if ( $mode === self::GIFT_CARD_SENDER_MODE_CUSTOM && $require_valid_email_for_custom ) {
			if ( $custom_email === '' || ! function_exists( 'is_email' ) || ! is_email( $custom_email ) ) {
				return self::GIFT_CARD_SENDER_MODE_DEFAULT;
			}
		}

		return $mode;
	}

	public function set_gift_card_sender_mode( string $mode ): void {
		$mode = sanitize_key( $mode );
		if ( ! in_array( $mode, self::gift_card_sender_modes(), true ) ) {
			$mode = self::GIFT_CARD_SENDER_MODE_DEFAULT;
		}
		update_option( self::OPTION_GIFT_CARD_SENDER_MODE, $mode, false );
	}

	/**
	 * @return list<string>
	 */
	public static function gift_card_sender_modes(): array {
		return array(
			self::GIFT_CARD_SENDER_MODE_DEFAULT,
			self::GIFT_CARD_SENDER_MODE_CUSTOM,
		);
	}

	public function gift_card_reply_to_email(): string {
		$raw = get_option( self::OPTION_GIFT_CARD_REPLY_TO_EMAIL, '' );
		$email = is_string( $raw ) ? sanitize_email( trim( $raw ) ) : '';

		return is_email( $email ) ? $email : '';
	}

	public function set_gift_card_reply_to_email( string $email ): void {
		update_option( self::OPTION_GIFT_CARD_REPLY_TO_EMAIL, sanitize_email( trim( $email ) ), false );
	}

	public function gift_card_support_email_text(): string {
		$raw = get_option( self::OPTION_GIFT_CARD_SUPPORT_TEXT, '' );

		return is_string( $raw ) ? sanitize_textarea_field( $raw ) : '';
	}

	public function set_gift_card_support_email_text( string $text ): void {
		update_option( self::OPTION_GIFT_CARD_SUPPORT_TEXT, sanitize_textarea_field( $text ), false );
	}

	public function gift_card_balance_page_id(): int {
		$raw = get_option( self::OPTION_GIFT_CARD_BALANCE_PAGE_ID, 0 );

		return max( 0, (int) $raw );
	}

	public function set_gift_card_balance_page_id( int $page_id ): void {
		update_option( self::OPTION_GIFT_CARD_BALANCE_PAGE_ID, max( 0, $page_id ), false );
	}

	/**
	 * @return array<string, bool|int|string>
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
			'line_item_mode_disabled'   => $this->line_item_mode_disabled(),
			'planner_trace_verbose'     => $this->planner_trace_verbose(),
			'gift_card_delivery_email'      => $this->gift_card_delivery_email_enabled(),
			'gift_card_balance_checker'     => $this->gift_card_balance_checker_enabled(),
			'gift_card_my_account'          => $this->gift_card_my_account_enabled(),
			'gift_card_scheduled_cron'      => $this->gift_card_scheduled_cron_enabled(),
			'gift_card_email_template'      => $this->gift_card_email_template(),
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
