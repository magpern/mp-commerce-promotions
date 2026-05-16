<?php
/**
 * Condition: total cart quantity for items in a product category.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;

final class CategoryQuantityCondition implements ConditionInterface {

	private int $category_id;

	private string $operator;

	private float $quantity;

	public function __construct( int $category_id, string $operator, float $quantity ) {
		if ( $category_id <= 0 ) {
			throw new InvalidArgumentException( 'category_quantity category_id must be > 0.' );
		}
		if ( $quantity < 0 ) {
			throw new InvalidArgumentException( 'category_quantity quantity must be >= 0.' );
		}
		$operator = trim( $operator );
		if ( ! QuantityComparator::supports( $operator ) ) {
			throw new InvalidArgumentException( 'category_quantity operator is not supported.' );
		}

		$this->category_id = $category_id;
		$this->operator    = $operator;
		$this->quantity    = $quantity;
	}

	public function get_type(): string {
		return 'category_quantity';
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$actual = $this->sum_quantity_for_category( $context );

		if ( QuantityComparator::compare( $actual, $this->operator, $this->quantity ) ) {
			return ConditionResult::pass();
		}

		return ConditionResult::fail(
			sprintf(
				'Category %d quantity %.4f does not satisfy %s %.4f.',
				$this->category_id,
				$actual,
				$this->operator,
				$this->quantity
			)
		);
	}

	private function sum_quantity_for_category( EvaluationContext $context ): float {
		$sum = 0.0;
		foreach ( $context->get_items() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! $this->item_has_category( $item, $this->category_id ) ) {
				continue;
			}
			if ( isset( $item['quantity'] ) && is_numeric( $item['quantity'] ) ) {
				$sum += (float) $item['quantity'];
			}
		}

		return $sum;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function item_has_category( array $item, int $category_id ): bool {
		if ( ! isset( $item['categories'] ) || ! is_array( $item['categories'] ) ) {
			return false;
		}

		foreach ( $item['categories'] as $cat ) {
			if ( (int) $cat === $category_id ) {
				return true;
			}
		}

		return false;
	}
}
