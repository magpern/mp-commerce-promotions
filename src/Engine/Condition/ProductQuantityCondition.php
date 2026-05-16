<?php
/**
 * Condition: total cart quantity for a specific product ID.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class ProductQuantityCondition implements ConditionInterface {

	private int $product_id;

	private string $operator;

	private float $quantity;

	public function __construct( int $product_id, string $operator, float $quantity ) {
		if ( $product_id <= 0 ) {
			throw new InvalidArgumentException( 'product_quantity product_id must be > 0.' );
		}
		if ( $quantity < 0 ) {
			throw new InvalidArgumentException( 'product_quantity quantity must be >= 0.' );
		}
		$operator = trim( $operator );
		if ( ! QuantityComparator::supports( $operator ) ) {
			throw new InvalidArgumentException( 'product_quantity operator is not supported.' );
		}

		$this->product_id = $product_id;
		$this->operator   = $operator;
		$this->quantity   = $quantity;
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_PRODUCT_QUANTITY;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$actual = $this->sum_quantity_for_product( $context );

		$observed = array(
			'product_id'        => $this->product_id,
			'actual_quantity'   => $actual,
			'operator'          => $this->operator,
			'required_quantity' => $this->quantity,
		);

		if ( QuantityComparator::compare( $actual, $this->operator, $this->quantity ) ) {
			return ConditionResult::pass( null, ConditionTrace::REASON_PASSED, $observed );
		}

		return ConditionResult::fail(
			sprintf(
				'Product %d quantity %.4f does not satisfy %s %.4f.',
				$this->product_id,
				$actual,
				$this->operator,
				$this->quantity
			),
			ConditionTrace::REASON_QUANTITY_NOT_MET,
			$observed
		);
	}

	private function sum_quantity_for_product( EvaluationContext $context ): float {
		$sum = 0.0;
		foreach ( $context->get_items() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( $pid !== $this->product_id ) {
				continue;
			}
			if ( isset( $item['quantity'] ) && is_numeric( $item['quantity'] ) ) {
				$sum += (float) $item['quantity'];
			}
		}

		return $sum;
	}
}
