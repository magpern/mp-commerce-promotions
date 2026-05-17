<?php
/**
 * Base for customer segmentation conditions comparing metadata numerics.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Condition\QuantityComparator;
use MP\CommercePromotions\Engine\EvaluationContext;

abstract class AbstractCustomerNumericCondition implements ConditionInterface {

	private string $operator;

	private float $threshold;

	private string $metadata_key;

	private string $fail_reason_not_met;

	public function __construct(
		string $operator,
		float $threshold,
		string $metadata_key,
		string $fail_reason_not_met
	) {
		if ( $threshold < 0 ) {
			throw new InvalidArgumentException( 'Threshold must be >= 0.' );
		}
		$operator = trim( $operator );
		if ( ! QuantityComparator::supports( $operator ) ) {
			throw new InvalidArgumentException( 'Operator is not supported.' );
		}

		$this->operator            = $operator;
		$this->threshold           = $threshold;
		$this->metadata_key        = $metadata_key;
		$this->fail_reason_not_met = $fail_reason_not_met;
	}

	abstract public function get_type(): string;

	public function evaluate( EvaluationContext $context ): ConditionResult {
		if ( $context->get_customer_id() === null || $context->get_customer_id() <= 0 ) {
			return ConditionResult::fail(
				'Customer account is required for this condition.',
				ConditionTrace::REASON_CUSTOMER_REQUIRED,
				array( $this->metadata_key => null )
			);
		}

		$metadata = $context->get_metadata();
		if ( ! array_key_exists( $this->metadata_key, $metadata ) ) {
			return ConditionResult::fail(
				sprintf( '%s metadata is not available.', $this->metadata_key ),
				ConditionTrace::REASON_METADATA_MISSING,
				array( $this->metadata_key => null )
			);
		}

		if ( ! is_numeric( $metadata[ $this->metadata_key ] ) ) {
			return ConditionResult::fail(
				sprintf( '%s metadata must be numeric.', $this->metadata_key ),
				ConditionTrace::REASON_FAILED,
				array( $this->metadata_key => $metadata[ $this->metadata_key ] )
			);
		}

		$actual = (float) $metadata[ $this->metadata_key ];
		$observed = array(
			$this->metadata_key => $actual,
			'operator'         => $this->operator,
			'threshold'        => $this->threshold,
		);

		if ( QuantityComparator::compare( $actual, $this->operator, $this->threshold ) ) {
			return ConditionResult::pass( null, ConditionTrace::REASON_PASSED, $observed );
		}

		$reason = $this->resolve_fail_reason( $actual );

		return ConditionResult::fail(
			sprintf(
				'%s %.2f does not satisfy %s %.2f.',
				$this->metadata_key,
				$actual,
				$this->operator,
				$this->threshold
			),
			$reason,
			$observed
		);
	}

	private function resolve_fail_reason( float $actual ): string {
		if ( $this->metadata_key === 'lifetime_spend' ) {
			if ( in_array( $this->operator, array( '>=', '>' ), true ) && $actual < $this->threshold ) {
				return ConditionTrace::REASON_LIFETIME_SPEND_TOO_LOW;
			}
			if ( in_array( $this->operator, array( '<=', '<' ), true ) && $actual > $this->threshold ) {
				return ConditionTrace::REASON_LIFETIME_SPEND_TOO_HIGH;
			}
		}

		return $this->fail_reason_not_met !== '' ? $this->fail_reason_not_met : ConditionTrace::REASON_FAILED;
	}
}
