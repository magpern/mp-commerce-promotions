<?php
/**
 * Reusable scoped subsets of cart line items for conditions and discount actions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class EligibleCartScope {

	/**
	 * @param list<array<string, mixed>> $items
	 * @param list<int>                  $product_ids
	 * @param list<int>                  $variation_ids
	 * @param list<int>                  $category_ids
	 * @param list<int>                  $exclude_product_ids
	 * @param list<int>                  $exclude_category_ids
	 * @return list<array<string, mixed>>
	 */
	public static function filter_items(
		array $items,
		array $product_ids = array(),
		array $variation_ids = array(),
		array $category_ids = array(),
		array $exclude_product_ids = array(),
		array $exclude_category_ids = array(),
		bool $exclude_sale_items = false
	): array {
		$product_ids           = CartItemSelector::normalize_positive_int_list( $product_ids );
		$variation_ids         = CartItemSelector::normalize_positive_int_list( $variation_ids );
		$category_ids          = CartItemSelector::normalize_positive_int_list( $category_ids );
		$exclude_product_ids   = CartItemSelector::normalize_positive_int_list( $exclude_product_ids );
		$exclude_category_ids  = CartItemSelector::normalize_positive_int_list( $exclude_category_ids );

		$has_include = $product_ids !== array() || $variation_ids !== array() || $category_ids !== array();

		$filtered = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( $has_include && ! self::item_matches_include_scope( $item, $product_ids, $variation_ids, $category_ids ) ) {
				continue;
			}

			if ( self::item_matches_exclude_scope( $item, $exclude_product_ids, $exclude_category_ids ) ) {
				continue;
			}

			if ( $exclude_sale_items && CartItemSelector::item_is_on_sale( $item ) ) {
				continue;
			}

			$filtered[] = $item;
		}

		return $filtered;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	public static function subtotal( array $items ): float {
		$total = 0.0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( isset( $item['line_subtotal'] ) && is_numeric( $item['line_subtotal'] ) ) {
				$total += max( 0.0, (float) $item['line_subtotal'] );
				continue;
			}

			$quantity = isset( $item['quantity'] ) && is_numeric( $item['quantity'] )
				? (float) $item['quantity']
				: 0.0;
			if ( $quantity <= 0 ) {
				continue;
			}

			$total += CartItemSelector::resolve_unit_price( $item ) * $quantity;
		}

		return round( $total, 4 );
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	public static function quantity( array $items ): int {
		$total = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['quantity'] ) || ! is_numeric( $item['quantity'] ) ) {
				continue;
			}
			$qty = (float) $item['quantity'];
			if ( $qty <= 0 ) {
				continue;
			}
			$total += (int) floor( $qty );
		}

		return $total;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function cheapest_units( array $items, int $count ): array {
		if ( $count < 1 ) {
			return array();
		}

		$units = CartItemSelector::expand_quantities( $items );
		if ( $units === array() ) {
			return array();
		}

		usort(
			$units,
			static function ( array $a, array $b ): int {
				$pa = isset( $a['unit_price'] ) ? (float) $a['unit_price'] : 0.0;
				$pb = isset( $b['unit_price'] ) ? (float) $b['unit_price'] : 0.0;
				if ( abs( $pa - $pb ) < 0.00001 ) {
					return 0;
				}

				return $pa <=> $pb;
			}
		);

		return array_slice( $units, 0, $count );
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @param list<int>                  $product_ids
	 * @param list<int>                  $variation_ids
	 * @return list<int>
	 */
	public static function matching_product_ids( array $items, array $product_ids, array $variation_ids = array() ): array {
		$product_ids   = CartItemSelector::normalize_positive_int_list( $product_ids );
		$variation_ids = CartItemSelector::normalize_positive_int_list( $variation_ids );

		if ( $product_ids === array() && $variation_ids === array() ) {
			return array();
		}

		$matched = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! CartItemSelector::item_matches_product_or_variation( $item, $product_ids, $variation_ids ) ) {
				continue;
			}

			$variation = isset( $item['variation_id'] ) && is_numeric( $item['variation_id'] )
				? (int) $item['variation_id']
				: 0;
			$product   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( $variation > 0 ) {
				$matched[] = $variation;
			} elseif ( $product > 0 ) {
				$matched[] = $product;
			}
		}

		return array_values( array_unique( $matched, SORT_NUMERIC ) );
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @param list<int>                  $category_ids
	 * @return list<int>
	 */
	public static function matching_category_ids( array $items, array $category_ids ): array {
		$category_ids = CartItemSelector::normalize_positive_int_list( $category_ids );
		if ( $category_ids === array() ) {
			return array();
		}

		$matched = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item_categories = isset( $item['categories'] ) && is_array( $item['categories'] )
				? CartItemSelector::normalize_positive_int_list( $item['categories'] )
				: array();
			foreach ( $item_categories as $cat_id ) {
				if ( in_array( $cat_id, $category_ids, true ) ) {
					$matched[] = $cat_id;
				}
			}
		}

		return array_values( array_unique( $matched, SORT_NUMERIC ) );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function has_include_or_sale_scope( array $config ): bool {
		$scope = self::parse_scope_lists( $config );

		return $scope['product_ids'] !== array()
			|| $scope['variation_ids'] !== array()
			|| $scope['category_ids'] !== array()
			|| $scope['exclude_sale_items'];
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array{
	 *     product_ids: list<int>,
	 *     variation_ids: list<int>,
	 *     category_ids: list<int>,
	 *     exclude_product_ids: list<int>,
	 *     exclude_category_ids: list<int>,
	 *     exclude_sale_items: bool
	 * }
	 */
	public static function parse_scope_lists( array $config ): array {
		$product_ids  = isset( $config['product_ids'] ) && is_array( $config['product_ids'] )
			? CartItemSelector::normalize_positive_int_list( $config['product_ids'] )
			: array();
		$variation_ids = isset( $config['variation_ids'] ) && is_array( $config['variation_ids'] )
			? CartItemSelector::normalize_positive_int_list( $config['variation_ids'] )
			: array();
		$category_ids = isset( $config['category_ids'] ) && is_array( $config['category_ids'] )
			? CartItemSelector::normalize_positive_int_list( $config['category_ids'] )
			: array();
		$exclude_product_ids = isset( $config['exclude_product_ids'] ) && is_array( $config['exclude_product_ids'] )
			? CartItemSelector::normalize_positive_int_list( $config['exclude_product_ids'] )
			: array();
		$exclude_category_ids = isset( $config['exclude_category_ids'] ) && is_array( $config['exclude_category_ids'] )
			? CartItemSelector::normalize_positive_int_list( $config['exclude_category_ids'] )
			: array();
		$exclude_sale_items = ! empty( $config['exclude_sale_items'] );

		return array(
			'product_ids'            => $product_ids,
			'variation_ids'          => $variation_ids,
			'category_ids'           => $category_ids,
			'exclude_product_ids'    => $exclude_product_ids,
			'exclude_category_ids'   => $exclude_category_ids,
			'exclude_sale_items'     => $exclude_sale_items,
		);
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @param array<string, mixed>       $config
	 * @return list<array<string, mixed>>
	 */
	public static function filter_items_from_config( array $items, array $config ): array {
		$scope = self::parse_scope_lists( $config );

		return self::filter_items(
			$items,
			$scope['product_ids'],
			$scope['variation_ids'],
			$scope['category_ids'],
			$scope['exclude_product_ids'],
			$scope['exclude_category_ids'],
			$scope['exclude_sale_items']
		);
	}

	/**
	 * @param array<string, mixed> $item
	 * @param list<int>            $product_ids
	 * @param list<int>            $variation_ids
	 * @param list<int>            $category_ids
	 */
	private static function item_matches_include_scope(
		array $item,
		array $product_ids,
		array $variation_ids,
		array $category_ids
	): bool {
		if ( CartItemSelector::item_matches_product_or_variation( $item, $product_ids, $variation_ids ) ) {
			return true;
		}

		if ( $category_ids === array() ) {
			return false;
		}

		$item_categories = isset( $item['categories'] ) && is_array( $item['categories'] )
			? CartItemSelector::normalize_positive_int_list( $item['categories'] )
			: array();

		foreach ( $item_categories as $cat_id ) {
			if ( in_array( $cat_id, $category_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param list<int>            $exclude_product_ids
	 * @param list<int>            $exclude_category_ids
	 */
	private static function item_matches_exclude_scope(
		array $item,
		array $exclude_product_ids,
		array $exclude_category_ids
	): bool {
		if ( $exclude_product_ids !== array()
			&& CartItemSelector::item_matches_product_or_variation( $item, $exclude_product_ids, $exclude_product_ids ) ) {
			return true;
		}

		if ( $exclude_category_ids === array() ) {
			return false;
		}

		$item_categories = isset( $item['categories'] ) && is_array( $item['categories'] )
			? CartItemSelector::normalize_positive_int_list( $item['categories'] )
			: array();

		foreach ( $item_categories as $cat_id ) {
			if ( in_array( $cat_id, $exclude_category_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function __construct() {
	}
}
