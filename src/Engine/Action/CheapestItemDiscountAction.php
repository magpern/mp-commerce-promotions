<?php
/**
 * BOGO groundwork: discount the cheapest eligible cart units (preview only; fee applied on storefront).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\EligibleCartScope;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class CheapestItemDiscountAction implements ActionInterface {

	public const SCOPE_CATEGORY  = 'category';

	public const SCOPE_PRODUCTS  = 'products';

	public const REASON_INSUFFICIENT = 'insufficient_eligible_quantity';

	private string $scope;

	/** @var list<int> */
	private array $category_ids;

	/** @var list<int> */
	private array $product_ids;

	/** @var list<int> */
	private array $variation_ids;

	private float $discount_percentage;

	private int $required_quantity;

	private int $discounted_quantity;

	private bool $exclude_sale_items;

	/**
	 * @param array<string, mixed> $config Promotion action JSON object.
	 */
	public static function from_config( array $config ): self {
		$scope = isset( $config['scope'] ) ? trim( (string) $config['scope'] ) : '';
		if ( $scope !== self::SCOPE_CATEGORY && $scope !== self::SCOPE_PRODUCTS ) {
			throw new InvalidArgumentException( 'cheapest_item_discount scope must be category or products.' );
		}

		if ( ! isset( $config['discount_percentage'] ) || ! is_numeric( $config['discount_percentage'] ) ) {
			throw new InvalidArgumentException( 'cheapest_item_discount discount_percentage is required.' );
		}
		$discount_percentage = (float) $config['discount_percentage'];
		if ( $discount_percentage <= 0 || $discount_percentage > 100 ) {
			throw new InvalidArgumentException( 'cheapest_item_discount discount_percentage must be > 0 and <= 100.' );
		}

		if ( ! isset( $config['required_quantity'] ) || ! is_numeric( $config['required_quantity'] ) ) {
			throw new InvalidArgumentException( 'cheapest_item_discount required_quantity is required.' );
		}
		$required_quantity = (int) $config['required_quantity'];
		if ( $required_quantity < 1 ) {
			throw new InvalidArgumentException( 'cheapest_item_discount required_quantity must be >= 1.' );
		}

		if ( ! isset( $config['discounted_quantity'] ) || ! is_numeric( $config['discounted_quantity'] ) ) {
			throw new InvalidArgumentException( 'cheapest_item_discount discounted_quantity is required.' );
		}
		$discounted_quantity = (int) $config['discounted_quantity'];
		if ( $discounted_quantity < 1 || $discounted_quantity > $required_quantity ) {
			throw new InvalidArgumentException( 'cheapest_item_discount discounted_quantity must be >= 1 and <= required_quantity.' );
		}

		$exclude_sale_items = ! empty( $config['exclude_sale_items'] );

		$category_ids  = array();
		$product_ids   = array();
		$variation_ids = array();

		if ( $scope === self::SCOPE_CATEGORY ) {
			if ( ! isset( $config['category_ids'] ) || ! is_array( $config['category_ids'] ) ) {
				throw new InvalidArgumentException( 'cheapest_item_discount category_ids must be a non-empty array.' );
			}
			$category_ids = CartItemSelector::normalize_positive_int_list( $config['category_ids'] );
			if ( $category_ids === array() ) {
				throw new InvalidArgumentException( 'cheapest_item_discount category_ids must contain positive integers.' );
			}
		} else {
			if ( ! isset( $config['product_ids'] ) || ! is_array( $config['product_ids'] ) ) {
				throw new InvalidArgumentException( 'cheapest_item_discount product_ids must be a non-empty array.' );
			}
			$product_ids = CartItemSelector::normalize_positive_int_list( $config['product_ids'] );
			if ( $product_ids === array() ) {
				throw new InvalidArgumentException( 'cheapest_item_discount product_ids must contain positive integers.' );
			}
			if ( isset( $config['variation_ids'] ) && is_array( $config['variation_ids'] ) ) {
				$variation_ids = CartItemSelector::normalize_positive_int_list( $config['variation_ids'] );
			}
		}

		return new self(
			$scope,
			$category_ids,
			$product_ids,
			$variation_ids,
			$discount_percentage,
			$required_quantity,
			$discounted_quantity,
			$exclude_sale_items
		);
	}

	/**
	 * @param list<int> $category_ids
	 * @param list<int> $product_ids
	 * @param list<int> $variation_ids
	 */
	private function __construct(
		string $scope,
		array $category_ids,
		array $product_ids,
		array $variation_ids,
		float $discount_percentage,
		int $required_quantity,
		int $discounted_quantity,
		bool $exclude_sale_items
	) {
		$this->scope               = $scope;
		$this->category_ids        = $category_ids;
		$this->product_ids         = $product_ids;
		$this->variation_ids       = $variation_ids;
		$this->discount_percentage = $discount_percentage;
		$this->required_quantity   = $required_quantity;
		$this->discounted_quantity = $discounted_quantity;
		$this->exclude_sale_items  = $exclude_sale_items;
	}

	public function get_type(): string {
		return RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT;
	}

	public function get_scope(): string {
		return $this->scope;
	}

	public function preview( EvaluationContext $context ): ActionResult {
		$product_ids   = $this->scope === self::SCOPE_PRODUCTS ? $this->product_ids : array();
		$variation_ids = $this->scope === self::SCOPE_PRODUCTS ? $this->variation_ids : array();
		$category_ids  = $this->scope === self::SCOPE_CATEGORY ? $this->category_ids : array();

		$items_before_sale = EligibleCartScope::filter_items(
			$context->get_items(),
			$product_ids,
			$variation_ids,
			$category_ids
		);
		$raw_count = count( CartItemSelector::expand_quantities( $items_before_sale ) );

		$sale_excluded_count = 0;
		if ( $this->exclude_sale_items ) {
			$sale_excluded_count = CartItemSelector::count_sale_items( $items_before_sale );
		}

		$eligible_items = EligibleCartScope::filter_items(
			$context->get_items(),
			$product_ids,
			$variation_ids,
			$category_ids,
			array(),
			array(),
			$this->exclude_sale_items
		);

		$eligible_units = EligibleCartScope::quantity( $eligible_items );

		if ( $eligible_units < $this->required_quantity ) {
			$payload = array(
				'discount_amount'     => 0.0,
				'discounted_units'    => 0,
				'scope'               => $this->scope,
				'not_applicable'      => true,
				'reason'              => self::REASON_INSUFFICIENT,
				'eligible_units'      => $eligible_units,
				'eligible_units_raw'  => $raw_count,
				'sale_items_excluded' => $this->exclude_sale_items,
				'required_quantity'   => $this->required_quantity,
				'matched_items_count' => count( $eligible_items ),
			);
			if ( $this->exclude_sale_items && $sale_excluded_count > 0 ) {
				$payload['sale_items_excluded_count'] = $sale_excluded_count;
			}

			return new ActionResult( $this->get_type(), $payload );
		}

		$cheapest_units = EligibleCartScope::cheapest_units( $eligible_items, $this->discounted_quantity );
		$discount_total = 0.0;
		foreach ( $cheapest_units as $unit ) {
			$unit_price = isset( $unit['unit_price'] ) ? (float) $unit['unit_price'] : 0.0;
			$discount_total += $unit_price * $this->discount_percentage / 100.0;
		}

		$to_discount = count( $cheapest_units );

		$payload = array(
			'discount_amount'       => round( $discount_total, 4 ),
			'discounted_units'      => $to_discount,
			'scope'                 => $this->scope,
			'eligible_units'        => $eligible_units,
			'sale_items_excluded'   => $this->exclude_sale_items,
			'matched_items_count'   => count( $eligible_items ),
			'eligible_subtotal'     => EligibleCartScope::subtotal( $eligible_items ),
		);
		if ( $this->exclude_sale_items && $raw_count !== $eligible_units ) {
			$payload['eligible_units_raw'] = $raw_count;
		}
		if ( $this->exclude_sale_items && $sale_excluded_count > 0 ) {
			$payload['sale_items_excluded_count'] = $sale_excluded_count;
		}

		return new ActionResult( $this->get_type(), $payload );
	}
}
