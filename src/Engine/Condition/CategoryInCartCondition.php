<?php
/**
 * Condition: at least one cart line belongs to a product category.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class CategoryInCartCondition implements ConditionInterface {

	/** @var list<int> */
	private array $category_ids;

	/**
	 * @param array<string, mixed> $config
	 */
	public static function from_config( array $config ): self {
		if ( ! isset( $config['category_ids'] ) || ! is_array( $config['category_ids'] ) ) {
			throw new InvalidArgumentException( 'category_in_cart category_ids must be a non-empty array.' );
		}

		$ids = CartItemSelector::normalize_positive_int_list( $config['category_ids'] );
		if ( $ids === array() ) {
			throw new InvalidArgumentException( 'category_in_cart category_ids must contain positive integers.' );
		}

		return new self( $ids );
	}

	/**
	 * @param list<int> $category_ids
	 */
	public function __construct( array $category_ids ) {
		$this->category_ids = CartItemSelector::normalize_positive_int_list( $category_ids );
		if ( $this->category_ids === array() ) {
			throw new InvalidArgumentException( 'category_in_cart category_ids must contain positive integers.' );
		}
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_CATEGORY_IN_CART;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$matched_categories = array();
		foreach ( CartItemSelector::items_matching_categories( $context, $this->category_ids ) as $item ) {
			$cats = isset( $item['categories'] ) && is_array( $item['categories'] )
				? CartItemSelector::normalize_positive_int_list( $item['categories'] )
				: array();
			foreach ( $cats as $cat_id ) {
				if ( in_array( $cat_id, $this->category_ids, true ) ) {
					$matched_categories[ $cat_id ] = $cat_id;
				}
			}
		}
		$matched_categories = array_values( $matched_categories );

		$observed = array(
			'required_category_ids' => $this->category_ids,
			'matched_category_ids'  => $matched_categories,
		);

		if ( $matched_categories !== array() ) {
			return ConditionResult::pass( null, ConditionTrace::REASON_PASSED, $observed );
		}

		return ConditionResult::fail(
			'No matching category found in the cart.',
			ConditionTrace::REASON_REQUIRED_CATEGORY_MISSING,
			$observed
		);
	}
}
