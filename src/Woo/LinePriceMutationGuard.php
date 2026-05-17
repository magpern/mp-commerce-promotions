<?php
/**
 * Prevents duplicate line price mutation within a WooCommerce totals cycle.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class LinePriceMutationGuard {

	private static int $cycle = 0;

	/** @var array<int, list<string>> */
	private static array $mutated_keys_by_cycle = array();

	public static function begin_cycle(): int {
		++self::$cycle;
		self::$mutated_keys_by_cycle[ self::$cycle ] = array();
		LineDiscountFallbackTelemetry::reset();

		return self::$cycle;
	}

	public static function get_cycle(): int {
		return self::$cycle;
	}

	public static function reset_cycle(): void {
		self::$mutated_keys_by_cycle = array();
	}

	public static function mark_mutated( string $cart_item_key ): void {
		$cycle = self::$cycle;
		if ( ! isset( self::$mutated_keys_by_cycle[ $cycle ] ) ) {
			self::$mutated_keys_by_cycle[ $cycle ] = array();
		}
		if ( ! in_array( $cart_item_key, self::$mutated_keys_by_cycle[ $cycle ], true ) ) {
			self::$mutated_keys_by_cycle[ $cycle ][] = $cart_item_key;
		}
	}

	public static function was_mutated_this_cycle( string $cart_item_key ): bool {
		$cycle = self::$cycle;
		if ( ! isset( self::$mutated_keys_by_cycle[ $cycle ] ) ) {
			return false;
		}

		return in_array( $cart_item_key, self::$mutated_keys_by_cycle[ $cycle ], true );
	}

	/**
	 * @param object $cart_item WooCommerce cart line.
	 */
	public static function is_supported_product_type( $cart_item ): bool {
		if ( ! is_array( $cart_item ) || ! isset( $cart_item['data'] ) ) {
			return false;
		}

		$product = $cart_item['data'];
		if ( ! is_object( $product ) || ! method_exists( $product, 'is_type' ) ) {
			return false;
		}

		if ( $product->is_type( 'subscription' ) || $product->is_type( 'subscription_variation' ) ) {
			return false;
		}

		if ( $product->is_type( 'bundle' ) || $product->is_type( 'composite' ) ) {
			return false;
		}

		return $product->is_type( 'simple' )
			|| $product->is_type( 'variation' )
			|| $product->is_type( 'virtual' );
	}

	/**
	 * @param object $cart WooCommerce cart.
	 */
	public static function restore_all_line_prices( $cart ): void {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$items = $cart->get_cart();
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $cart_item_key => $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			self::restore_line_price( $cart_item );
		}
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public static function restore_line_price( array $cart_item ): void {
		if ( ! isset( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
			return;
		}

		$product = $cart_item['data'];
		if ( ! method_exists( $product, 'set_price' ) ) {
			return;
		}

		$original = null;
		if ( isset( $cart_item[ AppliedLineDiscount::META_ORIGINAL_PRICE ] ) ) {
			$original = (float) $cart_item[ AppliedLineDiscount::META_ORIGINAL_PRICE ];
		}

		if ( $original !== null && $original >= 0 ) {
			$product->set_price( $original );
		}
	}
}
