<?php
/**
 * Read-only validation of promotion conditions/actions against supported engine types.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\Action\FreeShippingAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\CategoryQuantityCondition;
use MP\CommercePromotions\Engine\Condition\BillingCountryCondition;
use MP\CommercePromotions\Engine\Condition\CustomerEmailDomainCondition;
use MP\CommercePromotions\Engine\Condition\CustomerRedemptionCountCondition;
use MP\CommercePromotions\Engine\Condition\CustomerRoleCondition;
use MP\CommercePromotions\Engine\Condition\MinimumSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\ProductQuantityCondition;
use MP\CommercePromotions\Engine\Condition\QuantityComparator;
use MP\CommercePromotions\Engine\RuleRegistry;
use MP\CommercePromotions\Engine\RuleTypes;

final class PromotionRuleValidator {

	/**
	 * @return list<array{level: string, message: string}>
	 */
	public function validate( Promotion $promotion ): array {
		$issues = array();

		$this->append_status_issues( $promotion, $issues );
		$this->append_application_rules_issues( $promotion, $issues );
		$this->append_condition_issues( $promotion->get_conditions(), $issues );
		$this->append_action_issues( $promotion->get_actions(), $issues );

		return $issues;
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_application_rules_issues( Promotion $promotion, array &$issues ): void {
		$mode = $promotion->get_application_mode();
		if ( ! PromotionApplicationMode::is_valid( $mode ) ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => __( 'Invalid application_mode. Allowed values: exclusive, stackable.', 'mp-commerce-promotions' ),
			);
			return;
		}

		$max = $promotion->get_max_applications();
		if ( $max !== null && $max < 1 ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => __( 'max_applications must be null or at least 1.', 'mp-commerce-promotions' ),
			);
		}

		if ( $max !== null ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __(
					'Max applications limits how many promotions may be selected in one cart evaluation plan (not per-customer usage). The plan cap is the minimum max_applications among selected promotions.',
					'mp-commerce-promotions'
				),
			);
		}

		if ( $mode === PromotionApplicationMode::EXCLUSIVE && $max !== null && $max > 1 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __(
					'Exclusive promotions stop further selections when stop processing is enabled; max_applications above 1 may have no effect unless stop processing is off.',
					'mp-commerce-promotions'
				),
			);
		}

		$excluded = $promotion->get_excluded_promotion_ids();
		$own_id   = $promotion->get_id();
		if ( $own_id !== null && $own_id > 0 && in_array( $own_id, $excluded, true ) ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => __( 'A promotion cannot exclude itself.', 'mp-commerce-promotions' ),
			);
		}

		if ( count( $excluded ) > 0 ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __(
					'When this promotion is selected, listed promotion IDs are skipped in the plan even if eligible. Exclusions apply only to promotions evaluated later (priority/order).',
					'mp-commerce-promotions'
				),
			);
		}
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_status_issues( Promotion $promotion, array &$issues ): void {
		$status = $promotion->get_status();

		if ( $status === PromotionStatus::ARCHIVED ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __( 'Archived promotions do not run.', 'mp-commerce-promotions' ),
			);
			return;
		}

		if ( $status === PromotionStatus::DRAFT || $status === PromotionStatus::PAUSED ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __( 'Promotion is not active and will not run.', 'mp-commerce-promotions' ),
			);
		}
	}

	/**
	 * @param array<mixed>                                $conditions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_condition_issues( array $conditions, array &$issues ): void {
		if ( count( $conditions ) === 0 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Promotion has no conditions and may apply broadly.', 'mp-commerce-promotions' ),
			);
			return;
		}

		foreach ( $conditions as $index => $raw ) {
			$this->validate_condition_entry( (int) $index, $raw, $issues );
		}
	}

	/**
	 * @param mixed                                       $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_condition_entry( int $index, $raw, array &$issues ): void {
		if ( ! is_array( $raw ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'Condition at index %s must be an object.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$type = isset( $raw['type'] ) ? trim( (string) $raw['type'] ) : '';
		if ( $type === '' ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'Condition at index %s has no type.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! RuleRegistry::is_supported_condition( $type ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: condition type string */
					__( 'Unknown condition type: %s', 'mp-commerce-promotions' ),
					$type
				)
			);
			return;
		}

		if ( $type === RuleTypes::CONDITION_MINIMUM_SUBTOTAL ) {
			$this->validate_minimum_subtotal( $index, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_PRODUCT_QUANTITY ) {
			$this->validate_quantity_condition( $index, $raw, RuleTypes::CONDITION_PRODUCT_QUANTITY, 'product_id', $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_CATEGORY_QUANTITY ) {
			$this->validate_quantity_condition( $index, $raw, RuleTypes::CONDITION_CATEGORY_QUANTITY, 'category_id', $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_LOGGED_IN || $type === RuleTypes::CONDITION_FIRST_ORDER ) {
			return;
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_ROLE ) {
			$this->validate_customer_role( $index, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_BILLING_COUNTRY ) {
			$this->validate_billing_country( $index, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN ) {
			$this->validate_customer_email_domain( $index, $raw, $issues );
			return;
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT ) {
			$this->validate_customer_redemption_count( $index, $raw, $issues );
			return;
		}

		$issues[] = $this->error(
			sprintf(
				/* translators: %s: condition type string */
				__( 'Unknown condition type: %s', 'mp-commerce-promotions' ),
				$type
			)
		);
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_billing_country( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['countries'] ) || ! is_array( $raw['countries'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'billing_country at index %s is missing a countries array.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new BillingCountryCondition( $raw['countries'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'billing_country at index %s has invalid countries.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	private function validate_customer_email_domain( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['domains'] ) || ! is_array( $raw['domains'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_email_domain at index %s is missing a domains array.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new CustomerEmailDomainCondition( $raw['domains'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_email_domain at index %s has invalid domains.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	private function validate_customer_role( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['roles'] ) || ! is_array( $raw['roles'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_role at index %s is missing a roles array.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new CustomerRoleCondition( $raw['roles'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_role at index %s has invalid roles.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_minimum_subtotal( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'minimum_subtotal at index %s is missing or has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new MinimumSubtotalCondition( (float) $raw['amount'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'minimum_subtotal at index %s has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_quantity_condition(
		int $index,
		array $raw,
		string $type_label,
		string $id_key,
		array &$issues
	): void {
		if ( ! isset( $raw[ $id_key ] ) || ! is_numeric( $raw[ $id_key ] ) || (int) $raw[ $id_key ] <= 0 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s is missing or has an invalid %3$s.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index,
					$id_key
				)
			);
			return;
		}

		if ( ! isset( $raw['operator'] ) || ! is_string( $raw['operator'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s is missing or has an invalid operator.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
			return;
		}

		$operator = trim( $raw['operator'] );
		if ( ! QuantityComparator::supports( $operator ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s has an unsupported operator.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['quantity'] ) || ! is_numeric( $raw['quantity'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s is missing or has an invalid quantity.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
			return;
		}

		try {
			if ( $type_label === RuleTypes::CONDITION_PRODUCT_QUANTITY ) {
				new ProductQuantityCondition( (int) $raw[ $id_key ], $operator, (float) $raw['quantity'] );
			} else {
				new CategoryQuantityCondition( (int) $raw[ $id_key ], $operator, (float) $raw['quantity'] );
			}
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s has invalid field values.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<mixed>                                $actions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_action_issues( array $actions, array &$issues ): void {
		if ( count( $actions ) === 0 ) {
			$issues[] = $this->error(
				__( 'Promotion has no actions.', 'mp-commerce-promotions' )
			);
			return;
		}

		$supported_count = 0;

		foreach ( $actions as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'Action at index %s must be an object.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				continue;
			}

			$type = isset( $raw['type'] ) ? trim( (string) $raw['type'] ) : '';
			if ( $type === '' ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'Action at index %s has no type.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				continue;
			}

			if ( ! RuleRegistry::is_supported_action( $type ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: action type string */
						__( 'Unknown action type: %s', 'mp-commerce-promotions' ),
						$type
					)
				);
				continue;
			}

			++$supported_count;
			$this->validate_supported_action( (int) $index, $type, $raw, $issues );
		}

		if ( $supported_count > 1 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Only the first supported action per promotion is applied on the storefront.', 'mp-commerce-promotions' ),
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_supported_action( int $index, string $type, array $raw, array &$issues ): void {
		if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
			if ( ! isset( $raw['percentage'] ) || ! is_numeric( $raw['percentage'] ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'percentage_discount at index %s is missing or has an invalid percentage.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				return;
			}

			try {
				new PercentageDiscountAction( (float) $raw['percentage'] );
			} catch ( InvalidArgumentException $e ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'percentage_discount at index %s has an invalid percentage.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
			}
			return;
		}

		if ( $type === RuleTypes::ACTION_FREE_SHIPPING ) {
			try {
				new FreeShippingAction();
			} catch ( InvalidArgumentException $e ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'free_shipping at index %s is invalid.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
			}
			return;
		}

		if ( $type === RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
			$this->validate_cheapest_item_discount( $index, $raw, $issues );
			return;
		}

		if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'fixed_amount_discount at index %s is missing or has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new FixedAmountDiscountAction( (float) $raw['amount'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'fixed_amount_discount at index %s has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_cheapest_item_discount( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['scope'] ) || ! is_string( $raw['scope'] ) || trim( $raw['scope'] ) === '' ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s is missing scope (use category or products).', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$scope = trim( $raw['scope'] );
		if ( $scope !== CheapestItemDiscountAction::SCOPE_CATEGORY && $scope !== CheapestItemDiscountAction::SCOPE_PRODUCTS ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s has invalid scope (must be category or products).', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( $scope === CheapestItemDiscountAction::SCOPE_CATEGORY ) {
			if ( ! isset( $raw['category_ids'] ) || ! is_array( $raw['category_ids'] ) || count( $raw['category_ids'] ) === 0 ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'cheapest_item_discount at index %s is missing category_ids.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				return;
			}
		} elseif ( ! isset( $raw['product_ids'] ) || ! is_array( $raw['product_ids'] ) || count( $raw['product_ids'] ) === 0 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s is missing product_ids.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['discount_percentage'] ) || ! is_numeric( $raw['discount_percentage'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s is missing or has an invalid discount_percentage.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$pct = (float) $raw['discount_percentage'];
		if ( $pct <= 0 || $pct > 100 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s discount_percentage must be > 0 and <= 100.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['required_quantity'] ) || ! is_numeric( $raw['required_quantity'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s is missing or has an invalid required_quantity.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$required = (int) $raw['required_quantity'];
		if ( $required < 1 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s required_quantity must be >= 1.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['discounted_quantity'] ) || ! is_numeric( $raw['discounted_quantity'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s is missing or has an invalid discounted_quantity.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$discounted = (int) $raw['discounted_quantity'];
		if ( $discounted < 1 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s discounted_quantity must be >= 1.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( $discounted > $required ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'cheapest_item_discount at index %s discounted_quantity must be <= required_quantity.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			CheapestItemDiscountAction::from_config( $raw );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: zero-based action index, 2: detail */
					__( 'cheapest_item_discount at index %1$s has invalid IDs or field values (%2$s).', 'mp-commerce-promotions' ),
					(string) $index,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                        $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_customer_redemption_count( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['operator'] ) || ! is_string( $raw['operator'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_redemption_count at index %s is missing or has an invalid operator.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$operator = trim( $raw['operator'] );
		if ( ! QuantityComparator::supports( $operator ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_redemption_count at index %s has an unsupported operator.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['count'] ) || ! is_numeric( $raw['count'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_redemption_count at index %s is missing or has an invalid count.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new CustomerRedemptionCountCondition( $operator, (float) $raw['count'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'customer_redemption_count at index %s has invalid field values.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	/**
	 * @return array{level: string, message: string}
	 */
	private function error( string $message ): array {
		return array(
			'level'   => 'error',
			'message' => $message,
		);
	}
}
