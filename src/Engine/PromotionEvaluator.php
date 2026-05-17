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
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\Action\ActionInterface;
use MP\CommercePromotions\Engine\Action\ActionTrace;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\Action\FreeGiftProductAction;
use MP\CommercePromotions\Engine\Action\FreeShippingAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\Condition\BillingCountryCondition;
use MP\CommercePromotions\Engine\Condition\CategoryQuantityCondition;
use MP\CommercePromotions\Engine\Condition\ConditionInterface;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\Condition\CustomerEmailDomainCondition;
use MP\CommercePromotions\Engine\Condition\CustomerRedemptionCountCondition;
use MP\CommercePromotions\Engine\Condition\CustomerRoleCondition;
use MP\CommercePromotions\Engine\Condition\FirstOrderCondition;
use MP\CommercePromotions\Engine\Condition\LoggedInCondition;
use MP\CommercePromotions\Engine\Condition\MaximumCartQuantityCondition;
use MP\CommercePromotions\Engine\Condition\MaximumEligibleSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\MinimumCartQuantityCondition;
use MP\CommercePromotions\Engine\Condition\MinimumEligibleSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\CategoryInCartCondition;
use MP\CommercePromotions\Engine\Condition\ExcludeSaleItemsCondition;
use MP\CommercePromotions\Engine\Condition\MinimumSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\ProductInCartCondition;
use MP\CommercePromotions\Engine\Condition\ProductQuantityCondition;
use MP\CommercePromotions\Engine\Condition\QuantityComparator;

final class PromotionEvaluator {

	private ?RedemptionRepository $redemptions;

	private PromotionRestrictionEvaluator $restriction_evaluator;

	public function __construct( ?RedemptionRepository $redemptions = null ) {
		$this->redemptions           = $redemptions;
		$this->restriction_evaluator = new PromotionRestrictionEvaluator( $redemptions );
	}

	/**
	 * Evaluate promotion rules against a cart/order context (no persistence).
	 */
	public function evaluate( Promotion $promotion, EvaluationContext $context ): EvaluationResult {
		if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
			return EvaluationResult::ineligible(
				array( 'Promotion is not active.' )
			);
		}

		$context = $this->enrich_context_for_promotion( $context, $promotion );

		$restriction_trace = $this->restriction_evaluator->evaluate_restrictions( $promotion, $context );
		if ( $restriction_trace !== null ) {
			$message = $restriction_trace->get_message();

			return EvaluationResult::ineligible(
				array( $message !== null && $message !== '' ? $message : 'Promotion restrictions not satisfied.' ),
				array( $restriction_trace->to_array() ),
				$this->build_action_not_reached_traces( is_array( $promotion->get_actions() ) ? $promotion->get_actions() : array() )
			);
		}

		$conditions = $promotion->get_conditions();
		if ( ! is_array( $conditions ) ) {
			return EvaluationResult::ineligible( array( 'Promotion conditions must be an array.' ) );
		}

		$actions = $promotion->get_actions();
		if ( ! is_array( $actions ) ) {
			$actions = array();
		}

		/** @var list<ConditionTrace> $condition_traces */
		$condition_traces = array();

		foreach ( $conditions as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				return EvaluationResult::ineligible(
					array( sprintf( 'Condition at index %s must be an object.', (string) $index ) ),
					$condition_traces,
					$this->build_action_not_reached_traces( $actions )
				);
			}

			$type   = isset( $raw['type'] ) ? trim( (string) $raw['type'] ) : '';
			$config = $this->trace_config_from_raw( $raw );

			$resolved = $this->resolve_condition( $type, $raw );
			if ( $resolved['error'] === 'invalid' ) {
				$condition_traces[] = new ConditionTrace(
					$type !== '' ? $type : '(empty)',
					false,
					'Invalid condition configuration.',
					ConditionTrace::REASON_INVALID,
					$config,
					array()
				);

				return EvaluationResult::ineligible(
					array( $this->invalid_condition_message( $type ) ),
					$condition_traces,
					$this->build_action_not_reached_traces( $actions )
				);
			}

			if ( $resolved['error'] === 'unknown' ) {
				$condition_traces[] = new ConditionTrace(
					$type,
					false,
					sprintf( 'Unknown condition type: %s', $type ),
					ConditionTrace::REASON_UNKNOWN,
					$config,
					array()
				);

				return EvaluationResult::ineligible(
					array( sprintf( 'Unknown condition type: %s', $type ) ),
					$condition_traces,
					$this->build_action_not_reached_traces( $actions )
				);
			}

			$cond = $resolved['condition'];
			if ( ! $cond instanceof ConditionInterface ) {
				continue;
			}

			$result = $cond->evaluate( $context );
			$condition_traces[] = new ConditionTrace(
				$cond->get_type(),
				$result->passed(),
				$result->get_message(),
				$result->get_reason_code(),
				$config,
				$result->get_observed()
			);

			if ( ! $result->passed() ) {
				$msg = $result->get_message();

				return EvaluationResult::ineligible(
					array( $msg !== null && $msg !== '' ? $msg : 'A condition did not pass.' ),
					$condition_traces,
					$this->build_action_not_reached_traces( $actions )
				);
			}
		}

		if ( ! is_array( $promotion->get_actions() ) ) {
			return EvaluationResult::ineligible(
				array( 'Promotion actions must be an array.' ),
				$condition_traces,
				$this->build_action_not_reached_traces( $actions )
			);
		}

		$action_previews = array();
		/** @var list<ActionTrace> $action_traces */
		$action_traces     = array();
		$first_selected    = false;

		foreach ( $actions as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				return EvaluationResult::ineligible(
					array( sprintf( 'Action at index %s must be an object.', (string) $index ) ),
					$condition_traces,
					$action_traces
				);
			}

			$type   = isset( $raw['type'] ) ? trim( (string) $raw['type'] ) : '';
			$config = $this->trace_config_from_raw( $raw );

			$resolved = $this->resolve_action( $type, $raw );
			if ( $resolved['error'] === 'invalid' ) {
				$action_traces[] = new ActionTrace(
					$type !== '' ? $type : '(empty)',
					false,
					'Invalid action configuration.',
					ActionTrace::REASON_INVALID,
					$config,
					array()
				);

				return EvaluationResult::ineligible(
					array( $this->invalid_action_message( $type ) ),
					$condition_traces,
					$action_traces
				);
			}

			if ( $resolved['error'] === 'unknown' ) {
				$action_traces[] = new ActionTrace(
					$type,
					false,
					sprintf( 'Unknown action type: %s', $type ),
					ActionTrace::REASON_UNKNOWN,
					$config,
					array()
				);

				return EvaluationResult::ineligible(
					array( sprintf( 'Unknown action type: %s', $type ) ),
					$condition_traces,
					$action_traces
				);
			}

			$action = $resolved['action'];
			if ( ! $action instanceof ActionInterface ) {
				continue;
			}

			$preview           = $action->preview( $context );
			$preview_array       = $preview->to_array();
			$action_previews[]   = $preview_array;
			$selected            = ! $first_selected;
			$action_traces[]     = new ActionTrace(
				$action->get_type(),
				$selected,
				$selected ? null : 'Only the first supported action per promotion is applied on the storefront.',
				$selected ? ActionTrace::REASON_SELECTED : ActionTrace::REASON_NOT_REACHED,
				$config,
				$preview_array
			);

			if ( $selected ) {
				$first_selected = true;
			}
		}

		return EvaluationResult::eligible( $action_previews, array(), $condition_traces, $action_traces );
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	private function trace_config_from_raw( array $raw ): array {
		return $raw;
	}

	/**
	 * @param array<mixed> $actions
	 * @return list<ActionTrace>
	 */
	private function build_action_not_reached_traces( array $actions ): array {
		$traces = array();
		foreach ( $actions as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$type = isset( $raw['type'] ) ? trim( (string) $raw['type'] ) : '';
			if ( $type === '' ) {
				$type = '(empty)';
			}
			$traces[] = new ActionTrace(
				$type,
				false,
				'Conditions did not pass; action was not evaluated.',
				ActionTrace::REASON_NOT_REACHED,
				$this->trace_config_from_raw( $raw ),
				array()
			);
		}

		return $traces;
	}

	private function invalid_condition_message( string $type ): string {
		if ( $type === RuleTypes::CONDITION_MINIMUM_SUBTOTAL ) {
			return 'Invalid minimum_subtotal condition configuration.';
		}
		if ( $type === RuleTypes::CONDITION_PRODUCT_QUANTITY || $type === RuleTypes::CONDITION_CATEGORY_QUANTITY ) {
			return sprintf( 'Invalid %s condition configuration.', $type );
		}
		if ( $type === RuleTypes::CONDITION_CUSTOMER_ROLE ) {
			return 'Invalid customer_role condition configuration.';
		}
		if ( $type === RuleTypes::CONDITION_BILLING_COUNTRY ) {
			return 'Invalid billing_country condition configuration.';
		}
		if ( $type === RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN ) {
			return 'Invalid customer_email_domain condition configuration.';
		}
		if ( $type === RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT ) {
			return 'Invalid customer_redemption_count condition configuration.';
		}
		if ( $type === RuleTypes::CONDITION_MINIMUM_CART_QUANTITY || $type === RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY ) {
			return sprintf( 'Invalid %s condition configuration.', $type );
		}

		return 'Invalid condition configuration.';
	}

	private function invalid_action_message( string $type ): string {
		if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
			return 'Invalid percentage_discount action configuration.';
		}
		if ( $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
			return 'Invalid fixed_amount_discount action configuration.';
		}
		if ( $type === RuleTypes::ACTION_FREE_SHIPPING ) {
			return 'Invalid free_shipping action configuration.';
		}
		if ( $type === RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
			return 'Invalid cheapest_item_discount action configuration.';
		}
		if ( $type === RuleTypes::ACTION_FREE_GIFT_PRODUCT ) {
			return 'Invalid free_gift_product action configuration.';
		}

		return 'Invalid action configuration.';
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array{condition: ?ConditionInterface, error: ?string}
	 */
	private function resolve_condition( string $type, array $raw ): array {
		if ( $type === RuleTypes::CONDITION_MINIMUM_SUBTOTAL ) {
			if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
			try {
				return array(
					'condition' => new MinimumSubtotalCondition( (float) $raw['amount'] ),
					'error'     => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::CONDITION_PRODUCT_QUANTITY ) {
			return $this->resolve_quantity_condition(
				$raw,
				static function ( array $config ): ProductQuantityCondition {
					return new ProductQuantityCondition(
						(int) $config['id'],
						(string) $config['operator'],
						(float) $config['quantity']
					);
				},
				'product_id'
			);
		}

		if ( $type === RuleTypes::CONDITION_CATEGORY_QUANTITY ) {
			return $this->resolve_quantity_condition(
				$raw,
				static function ( array $config ): CategoryQuantityCondition {
					return new CategoryQuantityCondition(
						(int) $config['id'],
						(string) $config['operator'],
						(float) $config['quantity']
					);
				},
				'category_id'
			);
		}

		if ( $type === RuleTypes::CONDITION_LOGGED_IN ) {
			return array(
				'condition' => new LoggedInCondition(),
				'error'     => null,
			);
		}

		if ( $type === RuleTypes::CONDITION_FIRST_ORDER ) {
			return array(
				'condition' => new FirstOrderCondition(),
				'error'     => null,
			);
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_ROLE ) {
			if ( ! isset( $raw['roles'] ) || ! is_array( $raw['roles'] ) ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
			try {
				return array(
					'condition' => new CustomerRoleCondition( $raw['roles'] ),
					'error'     => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::CONDITION_BILLING_COUNTRY ) {
			if ( ! isset( $raw['countries'] ) || ! is_array( $raw['countries'] ) ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
			try {
				return array(
					'condition' => new BillingCountryCondition( $raw['countries'] ),
					'error'     => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN ) {
			if ( ! isset( $raw['domains'] ) || ! is_array( $raw['domains'] ) ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
			try {
				return array(
					'condition' => new CustomerEmailDomainCondition( $raw['domains'] ),
					'error'     => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT ) {
			return $this->resolve_customer_redemption_count_condition( $raw );
		}

		if ( $type === RuleTypes::CONDITION_MINIMUM_CART_QUANTITY ) {
			if ( ! isset( $raw['quantity'] ) || ! is_numeric( $raw['quantity'] ) ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
			try {
				return array(
					'condition' => new MinimumCartQuantityCondition( (int) $raw['quantity'] ),
					'error'     => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY ) {
			if ( ! isset( $raw['quantity'] ) || ! is_numeric( $raw['quantity'] ) ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
			try {
				return array(
					'condition' => new MaximumCartQuantityCondition( (int) $raw['quantity'] ),
					'error'     => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::CONDITION_PRODUCT_IN_CART ) {
			try {
				return array(
					'condition' => ProductInCartCondition::from_config( $raw ),
					'error'     => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::CONDITION_CATEGORY_IN_CART ) {
			try {
				return array(
					'condition' => CategoryInCartCondition::from_config( $raw ),
					'error'     => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::CONDITION_EXCLUDE_SALE_ITEMS ) {
			return array(
				'condition' => new ExcludeSaleItemsCondition(),
				'error'     => null,
			);
		}

		if ( $type === RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL ) {
			try {
				return array(
					'condition' => MinimumEligibleSubtotalCondition::from_config( $raw ),
					'error'     => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::CONDITION_MAXIMUM_ELIGIBLE_SUBTOTAL ) {
			try {
				return array(
					'condition' => MaximumEligibleSubtotalCondition::from_config( $raw ),
					'error'     => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'condition' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === '' ) {
			return array( 'condition' => null, 'error' => 'unknown' );
		}

		return array( 'condition' => null, 'error' => 'unknown' );
	}

	private function enrich_context_for_promotion( EvaluationContext $context, Promotion $promotion ): EvaluationContext {
		$items = CartItemSelector::filter_items_for_promotion( $context->get_items(), $promotion );

		$metadata                        = $context->get_metadata();
		$metadata['cart_total_quantity'] = CartQuantityHelper::total_quantity_from_items( $items );

		$customer_id  = $context->get_customer_id();
		$promotion_id = $promotion->get_id();
		if (
			$this->redemptions !== null
			&& $customer_id !== null
			&& $customer_id > 0
			&& $promotion_id !== null
			&& $promotion_id > 0
		) {
			$metadata['customer_promotion_redemption_count'] = $this->redemptions->count_recorded_for_customer_and_promotion(
				$customer_id,
				$promotion_id
			);
		}

		return new EvaluationContext(
			$context->get_customer_id(),
			$context->get_cart_subtotal(),
			$context->get_currency(),
			$items,
			$metadata
		);
	}

	/**
	 * @param array<string, mixed>                                                       $raw
	 * @param callable(array{id:int,operator:string,quantity:float}): ConditionInterface $factory
	 * @return array{condition: ?ConditionInterface, error: ?string}
	 */
	private function resolve_quantity_condition( array $raw, callable $factory, string $id_key ): array {
		if ( ! isset( $raw[ $id_key ] ) || ! is_numeric( $raw[ $id_key ] ) ) {
			return array( 'condition' => null, 'error' => 'invalid' );
		}
		if ( ! isset( $raw['operator'] ) || ! is_string( $raw['operator'] ) ) {
			return array( 'condition' => null, 'error' => 'invalid' );
		}
		$operator = trim( $raw['operator'] );
		if ( ! QuantityComparator::supports( $operator ) ) {
			return array( 'condition' => null, 'error' => 'invalid' );
		}
		if ( ! isset( $raw['quantity'] ) || ! is_numeric( $raw['quantity'] ) ) {
			return array( 'condition' => null, 'error' => 'invalid' );
		}

		$config = array(
			'id'       => (int) $raw[ $id_key ],
			'operator' => $operator,
			'quantity' => (float) $raw['quantity'],
		);

		try {
			return array(
				'condition' => $factory( $config ),
				'error'     => null,
			);
		} catch ( \InvalidArgumentException $e ) {
			return array( 'condition' => null, 'error' => 'invalid' );
		}
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array{action: ?ActionInterface, error: ?string}
	 */
	private function resolve_action( string $type, array $raw ): array {
		if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
			if ( ! isset( $raw['percentage'] ) || ! is_numeric( $raw['percentage'] ) ) {
				return array( 'action' => null, 'error' => 'invalid' );
			}
			try {
				return array(
					'action' => PercentageDiscountAction::from_config( $raw ),
					'error'  => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'action' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
			if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
				return array( 'action' => null, 'error' => 'invalid' );
			}
			try {
				return array(
					'action' => FixedAmountDiscountAction::from_config( $raw ),
					'error'  => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'action' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::ACTION_FREE_SHIPPING ) {
			return array(
				'action' => new FreeShippingAction(),
				'error'  => null,
			);
		}

		if ( $type === RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
			try {
				return array(
					'action' => CheapestItemDiscountAction::from_config( $raw ),
					'error'  => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'action' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === RuleTypes::ACTION_FREE_GIFT_PRODUCT ) {
			try {
				return array(
					'action' => FreeGiftProductAction::from_config( $raw ),
					'error'  => null,
				);
			} catch ( \InvalidArgumentException $e ) {
				return array( 'action' => null, 'error' => 'invalid' );
			}
		}

		if ( $type === '' ) {
			return array( 'action' => null, 'error' => 'unknown' );
		}

		return array( 'action' => null, 'error' => 'unknown' );
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array{condition: ?ConditionInterface, error: ?string}
	 */
	private function resolve_customer_redemption_count_condition( array $raw ): array {
		if ( ! isset( $raw['operator'] ) || ! is_string( $raw['operator'] ) ) {
			return array( 'condition' => null, 'error' => 'invalid' );
		}
		$operator = trim( $raw['operator'] );
		if ( ! QuantityComparator::supports( $operator ) ) {
			return array( 'condition' => null, 'error' => 'invalid' );
		}
		if ( ! isset( $raw['count'] ) || ! is_numeric( $raw['count'] ) ) {
			return array( 'condition' => null, 'error' => 'invalid' );
		}

		try {
			return array(
				'condition' => new CustomerRedemptionCountCondition( $operator, (float) $raw['count'] ),
				'error'     => null,
			);
		} catch ( \InvalidArgumentException $e ) {
			return array( 'condition' => null, 'error' => 'invalid' );
		}
	}
}
