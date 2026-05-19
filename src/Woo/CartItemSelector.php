<?php
/**
 * WooCommerce-aware cart line filtering (variations, categories, sale state, promotion exclusions).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Engine\EvaluationContext;

class CartItemSelector {

	/**
	 * @param array<string, mixed> $item
	 * @param list<int>            $product_ids
	 * @param list<int>            $variation_ids
	 */
	public static function item_matches_product_or_variation( array $item, array $product_ids, array $variation_ids = array() ): bool {
		$product_ids   = self::normalize_positive_int_list( $product_ids );
		$variation_ids = self::normalize_positive_int_list( $variation_ids );

		if ( $product_ids === array() && $variation_ids === array() ) {
			return false;
		}

		$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
		$variation  = isset( $item['variation_id'] ) && is_numeric( $item['variation_id'] )
			? (int) $item['variation_id']
			: 0;

		if ( $variation > 0 && $variation_ids !== array() && in_array( $variation, $variation_ids, true ) ) {
			return true;
		}

		if ( $product_id > 0 && $product_ids !== array() && in_array( $product_id, $product_ids, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param list<int> $product_ids Parent and/or simple product IDs.
	 * @return list<array<string, mixed>>
	 */
	public static function items_matching_products( EvaluationContext $context, array $product_ids ): array {
		$ids = self::normalize_positive_int_list( $product_ids );
		if ( $ids === array() ) {
			return array();
		}

		$matched = array();
		foreach ( $context->get_items() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( self::item_matches_product_or_variation( $item, $ids, array() ) ) {
				$matched[] = $item;
			}
		}

		return $matched;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @param list<int>                  $variation_ids
	 * @return list<array<string, mixed>>
	 */
	public static function items_matching_variations( array $items, array $variation_ids ): array {
		$ids = self::normalize_positive_int_list( $variation_ids );
		if ( $ids === array() ) {
			return array();
		}

		$matched = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$variation = isset( $item['variation_id'] ) && is_numeric( $item['variation_id'] )
				? (int) $item['variation_id']
				: 0;
			if ( $variation > 0 && in_array( $variation, $ids, true ) ) {
				$matched[] = $item;
			}
		}

		return $matched;
	}

	/**
	 * @param list<int> $category_ids
	 * @return list<array<string, mixed>>
	 */
	public static function items_matching_categories( EvaluationContext $context, array $category_ids ): array {
		$ids = self::normalize_positive_int_list( $category_ids );
		if ( $ids === array() ) {
			return array();
		}

		$matched = array();
		foreach ( $context->get_items() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item_categories = isset( $item['categories'] ) && is_array( $item['categories'] )
				? self::normalize_positive_int_list( $item['categories'] )
				: array();
			if ( $item_categories === array() ) {
				continue;
			}
			foreach ( $item_categories as $cat_id ) {
				if ( in_array( $cat_id, $ids, true ) ) {
					$matched[] = $item;
					break;
				}
			}
		}

		return $matched;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function expand_quantities( array $items ): array {
		$units = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$quantity = isset( $item['quantity'] ) && is_numeric( $item['quantity'] )
				? (float) $item['quantity']
				: 0.0;
			if ( $quantity <= 0 ) {
				continue;
			}

			$unit_price = self::resolve_unit_price( $item );
			if ( $unit_price < 0 ) {
				continue;
			}

			$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$variation_id = isset( $item['variation_id'] ) && is_numeric( $item['variation_id'] )
				? (int) $item['variation_id']
				: null;
			if ( $variation_id !== null && $variation_id <= 0 ) {
				$variation_id = null;
			}

			$categories = isset( $item['categories'] ) && is_array( $item['categories'] )
				? $item['categories']
				: array();

			$unit_count = (int) floor( $quantity );
			for ( $i = 0; $i < $unit_count; ++$i ) {
				$unit = array(
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'unit_price'   => $unit_price,
					'categories'   => $categories,
					'on_sale'      => self::item_is_on_sale( $item ),
					'source_item'  => $item,
				);
				if ( isset( $item['item_key'] ) && is_string( $item['item_key'] ) && $item['item_key'] !== '' ) {
					$unit['item_key'] = $item['item_key'];
				}
				$units[] = $unit;
			}
		}

		return $units;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function resolve_unit_price( array $item ): float {
		if ( isset( $item['unit_price'] ) && is_numeric( $item['unit_price'] ) ) {
			return max( 0.0, (float) $item['unit_price'] );
		}

		$quantity = isset( $item['quantity'] ) && is_numeric( $item['quantity'] )
			? (float) $item['quantity']
			: 0.0;
		if ( $quantity <= 0 ) {
			return 0.0;
		}

		if ( isset( $item['line_subtotal'] ) && is_numeric( $item['line_subtotal'] ) ) {
			return max( 0.0, (float) $item['line_subtotal'] / $quantity );
		}

		return 0.0;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function filter_out_sale_items( array $items ): array {
		$filtered = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! self::item_is_on_sale( $item ) ) {
				$filtered[] = $item;
			}
		}

		return $filtered;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	public static function count_sale_items( array $items ): int {
		$count = 0;
		foreach ( $items as $item ) {
			if ( is_array( $item ) && self::item_is_on_sale( $item ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param list<int> $product_ids
	 * @param list<int> $variation_ids
	 */
	public static function cart_has_product_or_variation( EvaluationContext $context, array $product_ids, array $variation_ids = array() ): bool {
		foreach ( $context->get_items() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( self::item_matches_product_or_variation( $item, $product_ids, $variation_ids ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<int> $category_ids
	 */
	public static function cart_has_category( EvaluationContext $context, array $category_ids ): bool {
		return self::items_matching_categories( $context, $category_ids ) !== array();
	}

	/**
	 * Remove lines excluded by promotion-level product/category targeting.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function filter_items_for_promotion( array $items, Promotion $promotion ): array {
		$excluded_products   = $promotion->get_excluded_product_ids();
		$excluded_categories = $promotion->get_excluded_category_ids();

		if ( $excluded_products === array() && $excluded_categories === array() ) {
			return $items;
		}

		$filtered = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( self::item_is_excluded_by_promotion( $item, $excluded_products, $excluded_categories ) ) {
				continue;
			}
			$filtered[] = $item;
		}

		return $filtered;
	}

	/**
	 * @param list<int> $product_ids
	 * @param list<int> $variation_ids
	 * @return list<array<string, mixed>>
	 */
	public static function items_matching_products_and_variations(
		EvaluationContext $context,
		array $product_ids,
		array $variation_ids
	): array {
		$product_ids   = self::normalize_positive_int_list( $product_ids );
		$variation_ids = self::normalize_positive_int_list( $variation_ids );

		if ( $product_ids === array() && $variation_ids === array() ) {
			return array();
		}

		$matched = array();
		foreach ( $context->get_items() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( self::item_matches_product_or_variation( $item, $product_ids, $variation_ids ) ) {
				$matched[] = $item;
			}
		}

		return $matched;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param list<int>            $excluded_products
	 * @param list<int>            $excluded_categories
	 */
	private static function item_is_excluded_by_promotion( array $item, array $excluded_products, array $excluded_categories ): bool {
		if ( $excluded_products !== array() && self::item_matches_product_or_variation( $item, $excluded_products, $excluded_products ) ) {
			return true;
		}

		if ( $excluded_categories === array() ) {
			return false;
		}

		$item_categories = isset( $item['categories'] ) && is_array( $item['categories'] )
			? self::normalize_positive_int_list( $item['categories'] )
			: array();

		foreach ( $item_categories as $cat_id ) {
			if ( in_array( $cat_id, $excluded_categories, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function item_is_on_sale( array $item ): bool {
		if ( isset( $item['on_sale'] ) ) {
			return (bool) $item['on_sale'];
		}

		return false;
	}

	/**
	 * @param array<mixed> $values
	 * @return list<int>
	 */
	public static function normalize_positive_int_list( array $values ): array {
		$ids = array();
		foreach ( $values as $value ) {
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$id = (int) $value;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids, SORT_NUMERIC ) );
	}
}
