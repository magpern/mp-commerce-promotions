<?php
/**
 * Qualifying cart subtotal for free-shipping thresholds (facade).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Engine\EvaluationContext;

final class CartShippingEligibilitySubtotal {

	public const TRACE_GIFT_COUNT_KEY = 'gift_card_products_excluded_from_shipping_count';

	public const TRACE_GIFT_SUBTOTAL_KEY = 'gift_card_products_excluded_from_shipping_subtotal';

	public const TRACE_QUALIFYING_KEY = ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING;

	/**
	 * @param list<array<string, mixed>> $items
	 */
	public static function qualifying_subtotal( array $items, ?EvaluationContext $context = null ): float {
		return self::stats( $items, $context )[ self::TRACE_QUALIFYING_KEY ];
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function qualifying_items( array $items ): array {
		$qualifying = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( self::line_counts_toward_shipping( $item ) ) {
				$qualifying[] = $item;
			}
		}

		return $qualifying;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function line_counts_toward_shipping( array $item ): bool {
		$stats = self::calculate( array( $item ) );

		return $stats['has_qualifying_shipping_items'];
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	public static function stats( array $items, ?EvaluationContext $context = null ): array {
		return self::calculate( $items, $context );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function stats_from_cart(): array {
		return ShippingQualifiedSubtotalCalculator::stats_from_cart();
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	public static function calculate( array $items, ?EvaluationContext $context = null ): array {
		return ShippingQualifiedSubtotalCalculator::calculate( $items, $context );
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
