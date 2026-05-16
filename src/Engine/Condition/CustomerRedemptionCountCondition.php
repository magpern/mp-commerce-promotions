<?php
/**
 * Condition: compare recorded promotion redemptions for the customer (metadata).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class CustomerRedemptionCountCondition implements ConditionInterface {

	private string $operator;

	private float $count;

	public function __construct( string $operator, float $count ) {
		if ( $count < 0 ) {
			throw new InvalidArgumentException( 'customer_redemption_count count must be >= 0.' );
		}
		$operator = trim( $operator );
		if ( ! QuantityComparator::supports( $operator ) ) {
			throw new InvalidArgumentException( 'customer_redemption_count operator is not supported.' );
		}

		$this->operator = $operator;
		$this->count    = $count;
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$metadata = $context->get_metadata();

		if ( ! array_key_exists( 'customer_redemption_count', $metadata ) ) {
			return ConditionResult::fail(
				'Customer redemption count is not available (customer_redemption_count metadata missing).',
				ConditionTrace::REASON_METADATA_MISSING,
				array( 'customer_redemption_count' => null )
			);
		}

		if ( ! is_int( $metadata['customer_redemption_count'] ) && ! ( is_numeric( $metadata['customer_redemption_count'] ) && (float) $metadata['customer_redemption_count'] == (int) $metadata['customer_redemption_count'] ) ) {
			return ConditionResult::fail(
				'customer_redemption_count metadata must be an integer.',
				ConditionTrace::REASON_FAILED,
				array( 'customer_redemption_count' => $metadata['customer_redemption_count'] )
			);
		}

		$actual = (int) $metadata['customer_redemption_count'];
		if ( $actual < 0 ) {
			return ConditionResult::fail(
				'customer_redemption_count metadata must be >= 0.',
				ConditionTrace::REASON_FAILED,
				array( 'customer_redemption_count' => $actual )
			);
		}

		$observed = array(
			'customer_redemption_count' => $actual,
			'operator'                  => $this->operator,
			'required_count'            => $this->count,
		);

		if ( QuantityComparator::compare( (float) $actual, $this->operator, $this->count ) ) {
			return ConditionResult::pass( null, ConditionTrace::REASON_PASSED, $observed );
		}

		return ConditionResult::fail(
			sprintf(
				'Customer redemption count %d does not satisfy %s %.4f.',
				$actual,
				$this->operator,
				$this->count
			),
			ConditionTrace::REASON_REDEMPTION_COUNT_NOT_MET,
			$observed
		);
	}
}
