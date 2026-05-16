<?php
/**
 * Filter and expand EvaluationContext cart line items for item-targeted actions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class CartItemSelector {

	/**
	 * @param list<int> $product_ids
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
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( $product_id > 0 && in_array( $product_id, $ids, true ) ) {
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
	 * Expand line items into per-quantity unit entries for cheapest-unit selection.
	 *
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
				$units[] = array(
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'unit_price'   => $unit_price,
					'categories'   => $categories,
					'source_item'  => $item,
				);
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
	 * @param array<mixed> $values
	 * @return list<int>
	 */
	private static function normalize_positive_int_list( array $values ): array {
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
