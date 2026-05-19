<?php
/**
 * Qualifying cart subtotal for free-shipping thresholds and shipping promotions.
 *
 * Excludes gift card products and lines that do not need shipping (virtual-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Engine\EligibleCartScope;
use MP\CommercePromotions\GiftCard\GiftCardPromotionExclusion;

final class CartShippingEligibilitySubtotal {

	public const TRACE_GIFT_COUNT_KEY = 'gift_card_products_excluded_from_shipping_count';

	public const TRACE_GIFT_SUBTOTAL_KEY = 'gift_card_products_excluded_from_shipping_subtotal';

	public const TRACE_QUALIFYING_KEY = 'qualifying_shipping_subtotal';

	/**
	 * @param array<string, mixed> $item
	 */
	public static function line_counts_toward_shipping( array $item ): bool {
		if ( GiftCardPromotionExclusion::line_is_gift_card_product( $item ) ) {
			return false;
		}

		if ( array_key_exists( 'needs_shipping', $item ) ) {
			return (bool) $item['needs_shipping'];
		}

		return true;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function qualifying_items( array $items ): array {
		$qualifying = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! self::line_counts_toward_shipping( $item ) ) {
				continue;
			}
			$qualifying[] = $item;
		}

		return $qualifying;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	public static function qualifying_subtotal( array $items ): float {
		return max( 0.0, round( EligibleCartScope::subtotal( self::qualifying_items( $items ) ), 4 ) );
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array{
	 *   gift_card_products_excluded_from_shipping_count: int,
	 *   gift_card_products_excluded_from_shipping_subtotal: float,
	 *   qualifying_shipping_subtotal: float,
	 *   has_qualifying_shipping_items: bool
	 * }
	 */
	public static function stats( array $items ): array {
		$gift_stats = GiftCardPromotionExclusion::exclusion_stats( $items );
		$qualifying = self::qualifying_subtotal( $items );

		return array(
			self::TRACE_GIFT_COUNT_KEY    => $gift_stats['count'],
			self::TRACE_GIFT_SUBTOTAL_KEY => $gift_stats['subtotal'],
			self::TRACE_QUALIFYING_KEY    => $qualifying,
			'has_qualifying_shipping_items' => self::qualifying_items( $items ) !== array(),
		);
	}

	/**
	 * @return array{
	 *   gift_card_products_excluded_from_shipping_count: int,
	 *   gift_card_products_excluded_from_shipping_subtotal: float,
	 *   qualifying_shipping_subtotal: float,
	 *   has_qualifying_shipping_items: bool
	 * }
	 */
	public static function stats_from_cart(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! method_exists( WC()->cart, 'get_cart' ) ) {
			return self::stats( array() );
		}

		$items = array();
		$raw   = WC()->cart->get_cart();
		if ( ! is_array( $raw ) ) {
			return self::stats( array() );
		}

		foreach ( $raw as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$variation  = isset( $cart_item['variation_id'] ) && (int) $cart_item['variation_id'] > 0
				? (int) $cart_item['variation_id']
				: null;

			$quantity = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0.0;

			$line_subtotal = 0.0;
			if ( isset( $cart_item['line_subtotal'] ) && is_numeric( $cart_item['line_subtotal'] ) ) {
				$line_subtotal = (float) $cart_item['line_subtotal'];
			}

			$row = array(
				'product_id'      => $product_id,
				'variation_id'    => $variation,
				'quantity'        => $quantity,
				'line_subtotal'   => $line_subtotal,
				'needs_shipping'  => self::wc_cart_item_needs_shipping( $cart_item ),
				'is_gift_card_product' => GiftCardPromotionExclusion::wc_cart_item_is_gift_card( $cart_item ),
			);

			$items[] = $row;
		}

		return self::stats( $items );
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public static function wc_cart_item_needs_shipping( array $cart_item ): bool {
		if ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'needs_shipping' ) ) {
			return (bool) $cart_item['data']->needs_shipping();
		}

		$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$variation  = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return true;
		}

		$product = $variation > 0 ? wc_get_product( $variation ) : wc_get_product( $product_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'needs_shipping' ) ) {
			return true;
		}

		return (bool) $product->needs_shipping();
	}

	private function __construct() {
	}
}
