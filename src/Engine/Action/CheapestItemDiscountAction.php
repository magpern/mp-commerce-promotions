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

	private float $discount_percentage;

	private int $required_quantity;

	private int $discounted_quantity;

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

		$category_ids = array();
		$product_ids  = array();

		if ( $scope === self::SCOPE_CATEGORY ) {
			if ( ! isset( $config['category_ids'] ) || ! is_array( $config['category_ids'] ) ) {
				throw new InvalidArgumentException( 'cheapest_item_discount category_ids must be a non-empty array.' );
			}
			foreach ( $config['category_ids'] as $raw_id ) {
				if ( is_numeric( $raw_id ) && (int) $raw_id > 0 ) {
					$category_ids[] = (int) $raw_id;
				}
			}
			$category_ids = array_values( array_unique( $category_ids, SORT_NUMERIC ) );
			if ( $category_ids === array() ) {
				throw new InvalidArgumentException( 'cheapest_item_discount category_ids must contain positive integers.' );
			}
		} else {
			if ( ! isset( $config['product_ids'] ) || ! is_array( $config['product_ids'] ) ) {
				throw new InvalidArgumentException( 'cheapest_item_discount product_ids must be a non-empty array.' );
			}
			foreach ( $config['product_ids'] as $raw_id ) {
				if ( is_numeric( $raw_id ) && (int) $raw_id > 0 ) {
					$product_ids[] = (int) $raw_id;
				}
			}
			$product_ids = array_values( array_unique( $product_ids, SORT_NUMERIC ) );
			if ( $product_ids === array() ) {
				throw new InvalidArgumentException( 'cheapest_item_discount product_ids must contain positive integers.' );
			}
		}

		return new self(
			$scope,
			$category_ids,
			$product_ids,
			$discount_percentage,
			$required_quantity,
			$discounted_quantity
		);
	}

	/**
	 * @param list<int> $category_ids
	 * @param list<int> $product_ids
	 */
	private function __construct(
		string $scope,
		array $category_ids,
		array $product_ids,
		float $discount_percentage,
		int $required_quantity,
		int $discounted_quantity
	) {
		$this->scope               = $scope;
		$this->category_ids        = $category_ids;
		$this->product_ids         = $product_ids;
		$this->discount_percentage = $discount_percentage;
		$this->required_quantity   = $required_quantity;
		$this->discounted_quantity = $discounted_quantity;
	}

	public function get_type(): string {
		return RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT;
	}

	public function get_scope(): string {
		return $this->scope;
	}

	public function preview( EvaluationContext $context ): ActionResult {
		$eligible_items = $this->scope === self::SCOPE_CATEGORY
			? CartItemSelector::items_matching_categories( $context, $this->category_ids )
			: CartItemSelector::items_matching_products( $context, $this->product_ids );

		$units = CartItemSelector::expand_quantities( $eligible_items );

		if ( count( $units ) < $this->required_quantity ) {
			return new ActionResult(
				$this->get_type(),
				array(
					'discount_amount'   => 0.0,
					'discounted_units'  => 0,
					'scope'             => $this->scope,
					'not_applicable'    => true,
					'reason'            => self::REASON_INSUFFICIENT,
					'eligible_units'    => count( $units ),
					'required_quantity' => $this->required_quantity,
				)
			);
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

		$discount_total = 0.0;
		$to_discount    = min( $this->discounted_quantity, count( $units ) );

		for ( $i = 0; $i < $to_discount; ++$i ) {
			$unit_price = isset( $units[ $i ]['unit_price'] ) ? (float) $units[ $i ]['unit_price'] : 0.0;
			$discount_total += $unit_price * $this->discount_percentage / 100.0;
		}

		return new ActionResult(
			$this->get_type(),
			array(
				'discount_amount'  => round( $discount_total, 4 ),
				'discounted_units' => $to_discount,
				'scope'            => $this->scope,
			)
		);
	}
}
