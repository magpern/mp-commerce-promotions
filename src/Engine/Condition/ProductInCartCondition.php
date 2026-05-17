<?php
/**
 * Condition: at least one cart line matches a product or variation ID.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class ProductInCartCondition implements ConditionInterface {

	/** @var list<int> */
	private array $product_ids;

	/**
	 * @param array<string, mixed> $config
	 */
	public static function from_config( array $config ): self {
		if ( ! isset( $config['product_ids'] ) || ! is_array( $config['product_ids'] ) ) {
			throw new InvalidArgumentException( 'product_in_cart product_ids must be a non-empty array.' );
		}

		$ids = CartItemSelector::normalize_positive_int_list( $config['product_ids'] );
		if ( $ids === array() ) {
			throw new InvalidArgumentException( 'product_in_cart product_ids must contain positive integers.' );
		}

		return new self( $ids );
	}

	/**
	 * @param list<int> $product_ids
	 */
	public function __construct( array $product_ids ) {
		$this->product_ids = CartItemSelector::normalize_positive_int_list( $product_ids );
		if ( $this->product_ids === array() ) {
			throw new InvalidArgumentException( 'product_in_cart product_ids must contain positive integers.' );
		}
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_PRODUCT_IN_CART;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$matched = array();
		foreach ( $context->get_items() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( CartItemSelector::item_matches_product_or_variation( $item, $this->product_ids, $this->product_ids ) ) {
				$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
				$vid = isset( $item['variation_id'] ) && is_numeric( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
				if ( $vid > 0 ) {
					$matched[] = $vid;
				} elseif ( $pid > 0 ) {
					$matched[] = $pid;
				}
			}
		}
		$matched = array_values( array_unique( $matched, SORT_NUMERIC ) );

		$observed = array(
			'required_product_ids' => $this->product_ids,
			'matched_ids'          => $matched,
		);

		if ( $matched !== array() ) {
			return ConditionResult::pass( null, ConditionTrace::REASON_PASSED, $observed );
		}

		return ConditionResult::fail(
			'No matching product or variation found in the cart.',
			ConditionTrace::REASON_REQUIRED_PRODUCT_MISSING,
			$observed
		);
	}
}
