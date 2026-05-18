<?php
/**
 * Centralized WooCommerce session access for applied promotion payload.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class CartSessionHelper {

	/**
	 * Whether WooCommerce and a session handler are available.
	 */
	public static function has_wc_session(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Read a session value.
	 *
	 * @return mixed|null
	 */
	public static function get( string $key ) {
		if ( ! self::has_wc_session() ) {
			return null;
		}

		return WC()->session->get( $key );
	}

	/**
	 * Write a session value.
	 *
	 * @param mixed $value Session payload.
	 */
	public static function set( string $key, $value ): void {
		if ( ! self::has_wc_session() ) {
			return;
		}

		WC()->session->set( $key, $value );
	}

	/**
	 * Clear a session key (ArrayAccess unset + set null).
	 */
	public static function clear( string $key ): void {
		if ( ! self::has_wc_session() ) {
			return;
		}

		$session = WC()->session;
		if ( $session instanceof \ArrayAccess ) {
			unset( $session[ $key ] );
		}
		if ( method_exists( $session, 'set' ) ) {
			$session->set( $key, null );
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_applied_promotion(): ?array {
		$raw = self::get( CartPromotionApplier::SESSION_KEY );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		return $raw;
	}

	/**
	 * @param array<string, mixed> $payload Applied promotion session payload.
	 */
	public static function set_applied_promotion( array $payload ): void {
		self::set( CartPromotionApplier::SESSION_KEY, $payload );
	}

	public static function clear_applied_promotion(): void {
		self::clear( CartPromotionApplier::SESSION_KEY );
	}

	public const LINE_ALLOCATIONS_SESSION_KEY = 'mp_cp_line_allocations';

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function set_line_allocations( array $payload ): void {
		self::set( self::LINE_ALLOCATIONS_SESSION_KEY, $payload );

		$total = isset( $payload['total_allocated'] ) && is_numeric( $payload['total_allocated'] )
			? (float) $payload['total_allocated']
			: 0.0;
		if ( $total > 0 ) {
			self::record_line_allocation_usage( $total );
		}
	}

	private static function record_line_allocation_usage( float $amount ): void {
		$stats = get_option( 'mp_cp_line_discount_usage_stats', array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}
		$stats['applications'] = (int) ( $stats['applications'] ?? 0 ) + 1;
		$stats['total_savings'] = (float) ( $stats['total_savings'] ?? 0.0 ) + $amount;
		$stats['last_recorded_at'] = gmdate( 'c' );
		update_option( 'mp_cp_line_discount_usage_stats', $stats, false );
	}

	/** @return array<string, mixed> */
	public static function get_line_usage_stats(): array {
		$stats = get_option( 'mp_cp_line_discount_usage_stats', array() );

		return is_array( $stats ) ? $stats : array();
	}

	public static function clear_line_usage_stats(): void {
		delete_option( 'mp_cp_line_discount_usage_stats' );
	}

	public static function clear_line_allocations(): void {
		self::clear( self::LINE_ALLOCATIONS_SESSION_KEY );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_line_allocations(): ?array {
		$raw = self::get( self::LINE_ALLOCATIONS_SESSION_KEY );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		return $raw;
	}
}
