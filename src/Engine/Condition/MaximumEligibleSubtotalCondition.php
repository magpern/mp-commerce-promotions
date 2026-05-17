<?php
/**
 * Condition: scoped eligible line subtotal must not exceed a maximum.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EligibleCartScope;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class MaximumEligibleSubtotalCondition implements ConditionInterface {

	private float $amount;

	/** @var list<int> */
	private array $product_ids;

	/** @var list<int> */
	private array $variation_ids;

	/** @var list<int> */
	private array $category_ids;

	/**
	 * @param array<string, mixed> $config
	 */
	public static function from_config( array $config ): self {
		if ( ! isset( $config['amount'] ) || ! is_numeric( $config['amount'] ) ) {
			throw new InvalidArgumentException( 'maximum_eligible_subtotal amount is required.' );
		}

		$scope = EligibleCartScope::parse_scope_lists( $config );

		return new self(
			(float) $config['amount'],
			$scope['product_ids'],
			$scope['variation_ids'],
			$scope['category_ids']
		);
	}

	/**
	 * @param list<int> $product_ids
	 * @param list<int> $variation_ids
	 * @param list<int> $category_ids
	 */
	public function __construct(
		float $amount,
		array $product_ids = array(),
		array $variation_ids = array(),
		array $category_ids = array()
	) {
		if ( $amount < 0 ) {
			throw new InvalidArgumentException( 'maximum_eligible_subtotal amount must be >= 0.' );
		}

		$this->amount        = $amount;
		$this->product_ids   = $product_ids;
		$this->variation_ids = $variation_ids;
		$this->category_ids  = $category_ids;
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_MAXIMUM_ELIGIBLE_SUBTOTAL;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$items             = $context->get_items();
		$eligible_items    = EligibleCartScope::filter_items(
			$items,
			$this->product_ids,
			$this->variation_ids,
			$this->category_ids
		);
		$eligible_subtotal = EligibleCartScope::subtotal( $eligible_items );
		$matched_count     = count( $eligible_items );

		$observed = array(
			'eligible_subtotal'   => $eligible_subtotal,
			'matched_items_count' => $matched_count,
			'required_maximum'    => $this->amount,
		);

		if ( $eligible_subtotal > $this->amount ) {
			return ConditionResult::fail(
				sprintf(
					'Eligible subtotal %.4f exceeds maximum %.4f.',
					$eligible_subtotal,
					$this->amount
				),
				ConditionTrace::REASON_ELIGIBLE_SUBTOTAL_TOO_HIGH,
				$observed
			);
		}

		return ConditionResult::pass(
			null,
			ConditionTrace::REASON_PASSED,
			$observed
		);
	}
}
