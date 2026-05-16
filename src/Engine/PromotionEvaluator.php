<?php
/**
 * Non-persistent promotion evaluation (supported condition/action types via RuleRegistry).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Action\ActionInterface;
use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\CategoryQuantityCondition;
use MP\CommercePromotions\Engine\Condition\ConditionInterface;
use MP\CommercePromotions\Engine\Condition\BillingCountryCondition;
use MP\CommercePromotions\Engine\Condition\CustomerEmailDomainCondition;
use MP\CommercePromotions\Engine\Condition\CustomerRoleCondition;
use MP\CommercePromotions\Engine\Condition\FirstOrderCondition;
use MP\CommercePromotions\Engine\Condition\LoggedInCondition;
use MP\CommercePromotions\Engine\Condition\MinimumSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\ProductQuantityCondition;
use MP\CommercePromotions\Engine\Condition\QuantityComparator;

final class PromotionEvaluator {

	/**
	 * Evaluate promotion rules against a cart/order context (no persistence).
	 */
	public function evaluate( Promotion $promotion, EvaluationContext $context ): EvaluationResult {
		if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
			return EvaluationResult::ineligible(
				array( 'Promotion is not active.' )
			);
		}

		$conditions = $promotion->get_conditions();
		if ( ! is_array( $conditions ) ) {
			return EvaluationResult::ineligible( array( 'Promotion conditions must be an array.' ) );
		}

		foreach ( $conditions as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				return EvaluationResult::ineligible(
					array( sprintf( 'Condition at index %s must be an object.', (string) $index ) )
				);
			}

			$type = isset( $raw['type'] ) ? (string) $raw['type'] : '';

			$cond = $this->resolve_condition( $type, $raw );
			if ( $cond instanceof EvaluationResult ) {
				return $cond;
			}
			if ( ! $cond instanceof ConditionInterface ) {
				return EvaluationResult::ineligible(
					array( sprintf( 'Unknown condition type: %s', $type !== '' ? $type : '(empty)' ) )
				);
			}

			$result = $cond->evaluate( $context );
			if ( ! $result->passed() ) {
				$msg = $result->get_message();
				return EvaluationResult::ineligible(
					array( $msg !== null && $msg !== '' ? $msg : 'A condition did not pass.' )
				);
			}
		}

		$actions = $promotion->get_actions();
		if ( ! is_array( $actions ) ) {
			return EvaluationResult::ineligible( array( 'Promotion actions must be an array.' ) );
		}

		$action_previews = array();

		foreach ( $actions as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				return EvaluationResult::ineligible(
					array( sprintf( 'Action at index %s must be an object.', (string) $index ) )
				);
			}

			$type = isset( $raw['type'] ) ? (string) $raw['type'] : '';

			$action = $this->resolve_action( $type, $raw );
			if ( $action instanceof EvaluationResult ) {
				return $action;
			}
			if ( ! $action instanceof ActionInterface ) {
				return EvaluationResult::ineligible(
					array( sprintf( 'Unknown action type: %s', $type !== '' ? $type : '(empty)' ) )
				);
			}

			$preview           = $action->preview( $context );
			$action_previews[] = $preview->to_array();
		}

		return EvaluationResult::eligible( $action_previews, array() );
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return ConditionInterface|EvaluationResult|null
	 */
	private function resolve_condition( string $type, array $raw ) {
		if ( $type === RuleTypes::CONDITION_MINIMUM_SUBTOTAL ) {
			if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
				return EvaluationResult::ineligible(
					array( 'Invalid minimum_subtotal condition configuration.' )
				);
			}
			try {
				return new MinimumSubtotalCondition( (float) $raw['amount'] );
			} catch ( \InvalidArgumentException $e ) {
				return EvaluationResult::ineligible(
					array( 'Invalid minimum_subtotal condition configuration.' )
				);
			}
		}

		if ( $type === RuleTypes::CONDITION_PRODUCT_QUANTITY ) {
			return $this->resolve_quantity_condition(
				$raw,
				RuleTypes::CONDITION_PRODUCT_QUANTITY,
				'product_id',
				static function ( array $config ): ProductQuantityCondition {
					return new ProductQuantityCondition(
						(int) $config['id'],
						(string) $config['operator'],
						(float) $config['quantity']
					);
				}
			);
		}

		if ( $type === RuleTypes::CONDITION_CATEGORY_QUANTITY ) {
			return $this->resolve_quantity_condition(
				$raw,
				RuleTypes::CONDITION_CATEGORY_QUANTITY,
				'category_id',
				static function ( array $config ): CategoryQuantityCondition {
					return new CategoryQuantityCondition(
						(int) $config['id'],
						(string) $config['operator'],
						(float) $config['quantity']
					);
				}
			);
		}

		if ( $type === RuleTypes::CONDITION_LOGGED_IN ) {
			return new LoggedInCondition();
		}

		if ( $type === RuleTypes::CONDITION_FIRST_ORDER ) {
			return new FirstOrderCondition();
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_ROLE ) {
			if ( ! isset( $raw['roles'] ) || ! is_array( $raw['roles'] ) ) {
				return EvaluationResult::ineligible(
					array( 'Invalid customer_role condition configuration.' )
				);
			}
			try {
				return new CustomerRoleCondition( $raw['roles'] );
			} catch ( \InvalidArgumentException $e ) {
				return EvaluationResult::ineligible(
					array( 'Invalid customer_role condition configuration.' )
				);
			}
		}

		if ( $type === RuleTypes::CONDITION_BILLING_COUNTRY ) {
			if ( ! isset( $raw['countries'] ) || ! is_array( $raw['countries'] ) ) {
				return EvaluationResult::ineligible(
					array( 'Invalid billing_country condition configuration.' )
				);
			}
			try {
				return new BillingCountryCondition( $raw['countries'] );
			} catch ( \InvalidArgumentException $e ) {
				return EvaluationResult::ineligible(
					array( 'Invalid billing_country condition configuration.' )
				);
			}
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN ) {
			if ( ! isset( $raw['domains'] ) || ! is_array( $raw['domains'] ) ) {
				return EvaluationResult::ineligible(
					array( 'Invalid customer_email_domain condition configuration.' )
				);
			}
			try {
				return new CustomerEmailDomainCondition( $raw['domains'] );
			} catch ( \InvalidArgumentException $e ) {
				return EvaluationResult::ineligible(
					array( 'Invalid customer_email_domain condition configuration.' )
				);
			}
		}

		if ( $type === '' ) {
			return null;
		}

		return null;
	}

	/**
	 * @param array<string, mixed>                                                       $raw
	 * @param string                                                                     $type_label
	 * @param string                                                                     $id_key
	 * @param callable(array{id:int,operator:string,quantity:float}): ConditionInterface $factory
	 * @return ConditionInterface|EvaluationResult|null
	 */
	private function resolve_quantity_condition( array $raw, string $type_label, string $id_key, callable $factory ) {
		if ( ! isset( $raw[ $id_key ] ) || ! is_numeric( $raw[ $id_key ] ) ) {
			return EvaluationResult::ineligible(
				array( sprintf( 'Invalid %s condition configuration.', $type_label ) )
			);
		}
		if ( ! isset( $raw['operator'] ) || ! is_string( $raw['operator'] ) ) {
			return EvaluationResult::ineligible(
				array( sprintf( 'Invalid %s condition configuration.', $type_label ) )
			);
		}
		$operator = trim( $raw['operator'] );
		if ( ! QuantityComparator::supports( $operator ) ) {
			return EvaluationResult::ineligible(
				array( sprintf( 'Invalid %s condition configuration.', $type_label ) )
			);
		}
		if ( ! isset( $raw['quantity'] ) || ! is_numeric( $raw['quantity'] ) ) {
			return EvaluationResult::ineligible(
				array( sprintf( 'Invalid %s condition configuration.', $type_label ) )
			);
		}

		$config = array(
			'id'       => (int) $raw[ $id_key ],
			'operator' => $operator,
			'quantity' => (float) $raw['quantity'],
		);

		try {
			return $factory( $config );
		} catch ( \InvalidArgumentException $e ) {
			return EvaluationResult::ineligible(
				array( sprintf( 'Invalid %s condition configuration.', $type_label ) )
			);
		}
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return ActionInterface|EvaluationResult|null
	 */
	private function resolve_action( string $type, array $raw ) {
		if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
			if ( ! isset( $raw['percentage'] ) || ! is_numeric( $raw['percentage'] ) ) {
				return EvaluationResult::ineligible(
					array( 'Invalid percentage_discount action configuration.' )
				);
			}
			try {
				return new PercentageDiscountAction( (float) $raw['percentage'] );
			} catch ( \InvalidArgumentException $e ) {
				return EvaluationResult::ineligible(
					array( 'Invalid percentage_discount action configuration.' )
				);
			}
		}

		if ( $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
			if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
				return EvaluationResult::ineligible(
					array( 'Invalid fixed_amount_discount action configuration.' )
				);
			}
			try {
				return new FixedAmountDiscountAction( (float) $raw['amount'] );
			} catch ( \InvalidArgumentException $e ) {
				return EvaluationResult::ineligible(
					array( 'Invalid fixed_amount_discount action configuration.' )
				);
			}
		}

		if ( $type === '' ) {
			return null;
		}

		return null;
	}
}
