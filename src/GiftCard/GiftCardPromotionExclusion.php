<?php
/**
 * Exclude gift card products from Commerce promotion discounts.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Engine\EligibleCartScope;

final class GiftCardPromotionExclusion {

	public const TRACE_COUNT_KEY    = 'gift_card_products_excluded_count';

	public const TRACE_SUBTOTAL_KEY = 'gift_card_products_excluded_subtotal';

	private static ?GiftCardProductService $products = null;

	public static function products(): GiftCardProductService {
		if ( self::$products === null ) {
			self::$products = new GiftCardProductService();
		}

		return self::$products;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function line_is_gift_card_product( array $item ): bool {
		if ( ! empty( $item['is_gift_card_product'] ) ) {
			return true;
		}

		$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
		if ( $product_id <= 0 ) {
			return false;
		}

		$variation_id = isset( $item['variation_id'] ) && is_numeric( $item['variation_id'] )
			? (int) $item['variation_id']
			: 0;

		return self::products()->product_sells_gift_card( $product_id, $variation_id );
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function without_gift_card_products( array $items ): array {
		$filtered = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || self::line_is_gift_card_product( $item ) ) {
				continue;
			}
			$filtered[] = $item;
		}

		return $filtered;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array{count: int, subtotal: float, eligible_subtotal: float}
	 */
	public static function exclusion_stats( array $items ): array {
		$excluded_count    = 0;
		$excluded_subtotal = 0.0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! self::line_is_gift_card_product( $item ) ) {
				continue;
			}

			++$excluded_count;
			if ( isset( $item['line_subtotal'] ) && is_numeric( $item['line_subtotal'] ) ) {
				$excluded_subtotal += max( 0.0, (float) $item['line_subtotal'] );
			}
		}

		$full_subtotal     = EligibleCartScope::subtotal( $items );
		$eligible_subtotal = max( 0.0, round( $full_subtotal - $excluded_subtotal, 4 ) );

		return array(
			'count'             => $excluded_count,
			'subtotal'          => round( $excluded_subtotal, 4 ),
			'eligible_subtotal' => $eligible_subtotal,
		);
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function mark_gift_card_lines( array $items ): array {
		$marked = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item['is_gift_card_product'] = self::line_is_gift_card_product( $item );
			$marked[]                     = $item;
		}

		return $marked;
	}

	/**
	 * @param array<string, mixed> $cart_item WooCommerce cart row.
	 */
	public static function wc_cart_item_is_gift_card( array $cart_item ): bool {
		$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$variation  = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;

		return self::products()->product_sells_gift_card( $product_id, $variation );
	}

	private function __construct() {
	}
}
