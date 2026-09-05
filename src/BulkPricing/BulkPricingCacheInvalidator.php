<?php
/**
 * Targeted cache invalidation for bulk pricing contracts.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

use MP\CommercePromotions\Service\Settings;

final class BulkPricingCacheInvalidator {

	public const OPTION_CACHE_EPOCH = 'mp_cp_bulk_pricing_cache_epoch';

	public const TRANSIENT_PREFIX = 'mp_cp_bp_v1_';

	private Settings $settings;

	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ?? new Settings();
	}

	public function get_epoch(): int {
		return max( 0, (int) get_option( self::OPTION_CACHE_EPOCH, 0 ) );
	}

	public function bump_epoch(): void {
		update_option( self::OPTION_CACHE_EPOCH, $this->get_epoch() + 1, false );
		$this->delete_all_bulk_transients();
	}

	public function invalidate_product( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		clean_post_cache( $product_id );
		$this->delete_product_transients( $product_id );
	}

	public function contract_cache_version( LinePriceSnapshot $snapshot, BulkPricingConfig $config ): string {
		return md5(
			$this->get_epoch()
			. '|' . $snapshot->get_catalog_price_hash()
			. '|' . md5( wp_json_encode( $config->get_tiers() ) )
			. '|' . $snapshot->get_display_currency()
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_cached_contract( int $product_id, LinePriceSnapshot $snapshot, BulkPricingConfig $config ): ?array {
		$key = $this->transient_key( $product_id, $snapshot, $config );
		$cached = get_transient( $key );
		if ( ! is_array( $cached ) ) {
			return null;
		}

		return $cached;
	}

	/**
	 * @param array<string, mixed> $contract
	 */
	public function set_cached_contract( int $product_id, LinePriceSnapshot $snapshot, BulkPricingConfig $config, array $contract ): void {
		$key = $this->transient_key( $product_id, $snapshot, $config );
		set_transient( $key, $contract, HOUR_IN_SECONDS );
	}

	private function transient_key( int $product_id, LinePriceSnapshot $snapshot, BulkPricingConfig $config ): string {
		return self::TRANSIENT_PREFIX
			. $product_id . '_'
			. $this->get_epoch() . '_'
			. $snapshot->get_display_currency() . '_'
			. $snapshot->get_catalog_price_hash() . '_'
			. md5( wp_json_encode( $config->get_tiers() ) );
	}

	private function delete_product_transients( int $product_id ): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$like = $wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX . $product_id . '_' ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like,
				str_replace( '_transient_', '_transient_timeout_', $like )
			)
		);
	}

	private function delete_all_bulk_transients(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$like = $wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like,
				str_replace( '_transient_', '_transient_timeout_', $like )
			)
		);
	}
}
