<?php
/**
 * Admin promotion presets that produce existing engine rule JSON (no new rule types).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\RuleTypes;

final class PromotionTemplate {

	public const TEMPLATE_PERCENT_OFF_CATEGORY       = 'percent_off_category';

	public const TEMPLATE_FIXED_OFF_PRODUCTS         = 'fixed_off_products';

	public const TEMPLATE_BUY_X_GET_Y_CHEAPEST_FREE  = 'buy_x_get_y_cheapest_free';

	public const TEMPLATE_FREE_SHIPPING_OVER_SUBTOTAL = 'free_shipping_over_subtotal';

	public const TEMPLATE_FREE_GIFT_OVER_SUBTOTAL    = 'free_gift_over_subtotal';

	public const TEMPLATE_FIRST_ORDER_DISCOUNT       = 'first_order_discount';

	public const TEMPLATE_CUSTOMER_ROLE_DISCOUNT   = 'customer_role_discount';

	public const TEMPLATE_VIP_CUSTOMER             = 'vip_customer';

	public const TEMPLATE_LOYAL_CUSTOMER           = 'loyal_customer';

	public const TEMPLATE_RETURNING_CUSTOMER       = 'returning_customer';

	/**
	 * @return array<string, array{label: string, description: string, example: string}>
	 */
	public static function templates(): array {
		return array(
			self::TEMPLATE_PERCENT_OFF_CATEGORY => array(
				'label'       => __( 'Percent off category', 'mp-commerce-promotions' ),
				'description' => __( 'Scoped percentage discount on cart lines in selected categories. Optional minimum eligible subtotal on those categories.', 'mp-commerce-promotions' ),
				'example'     => __( 'Example: 20% off category “Supplements” when eligible lines total at least €50.', 'mp-commerce-promotions' ),
			),
			self::TEMPLATE_FIXED_OFF_PRODUCTS => array(
				'label'       => __( 'Fixed amount off products', 'mp-commerce-promotions' ),
				'description' => __( 'Fixed cart fee discount capped to eligible subtotal of selected products. Optional minimum eligible subtotal.', 'mp-commerce-promotions' ),
				'example'     => __( 'Example: €10 off specific SKUs when those lines total at least €30.', 'mp-commerce-promotions' ),
			),
			self::TEMPLATE_BUY_X_GET_Y_CHEAPEST_FREE => array(
				'label'       => __( 'Buy X get Y cheapest free', 'mp-commerce-promotions' ),
				'description' => __( 'Cheapest-item discount (BOGO groundwork) on category or product scope. Default 100% off cheapest units.', 'mp-commerce-promotions' ),
				'example'     => __( 'Example: Buy 3 in category, get 1 cheapest unit free (required 3, discounted 1, 100%).', 'mp-commerce-promotions' ),
			),
			self::TEMPLATE_FREE_SHIPPING_OVER_SUBTOTAL => array(
				'label'       => __( 'Free shipping over subtotal', 'mp-commerce-promotions' ),
				'description' => __( 'Minimum cart subtotal condition plus free shipping fee offset.', 'mp-commerce-promotions' ),
				'example'     => __( 'Example: Free shipping when cart subtotal is at least €75.', 'mp-commerce-promotions' ),
			),
			self::TEMPLATE_FREE_GIFT_OVER_SUBTOTAL => array(
				'label'       => __( 'Free gift over subtotal', 'mp-commerce-promotions' ),
				'description' => __( 'Minimum cart subtotal condition plus free gift product added to cart at zero price.', 'mp-commerce-promotions' ),
				'example'     => __( 'Example: Free sample product when order subtotal reaches €100.', 'mp-commerce-promotions' ),
			),
			self::TEMPLATE_FIRST_ORDER_DISCOUNT => array(
				'label'       => __( 'First order discount', 'mp-commerce-promotions' ),
				'description' => __( 'First-order condition with whole-cart percentage or fixed amount discount.', 'mp-commerce-promotions' ),
				'example'     => __( 'Example: 10% off for customers with no previous orders.', 'mp-commerce-promotions' ),
			),
			self::TEMPLATE_CUSTOMER_ROLE_DISCOUNT => array(
				'label'       => __( 'Customer role discount', 'mp-commerce-promotions' ),
				'description' => __( 'Customer role condition with whole-cart percentage or fixed amount discount.', 'mp-commerce-promotions' ),
				'example'     => __( 'Example: 15% off for role “vip”.', 'mp-commerce-promotions' ),
			),
			self::TEMPLATE_VIP_CUSTOMER => array(
				'label'       => __( 'VIP customer', 'mp-commerce-promotions' ),
				'description' => __( 'Logged-in customers with lifetime spend at or above a threshold receive a whole-cart discount.', 'mp-commerce-promotions' ),
				'example'     => __( 'Example: 15% off when lifetime spend is at least €500.', 'mp-commerce-promotions' ),
			),
			self::TEMPLATE_LOYAL_CUSTOMER => array(
				'label'       => __( 'Loyal customer', 'mp-commerce-promotions' ),
				'description' => __( 'Logged-in customers with order count at or above a threshold receive a whole-cart discount.', 'mp-commerce-promotions' ),
				'example'     => __( 'Example: €20 off when the customer has placed at least 5 orders.', 'mp-commerce-promotions' ),
			),
			self::TEMPLATE_RETURNING_CUSTOMER => array(
				'label'       => __( 'Returning customer', 'mp-commerce-promotions' ),
				'description' => __( 'Logged-in customers with average order value at or above a threshold receive a whole-cart discount.', 'mp-commerce-promotions' ),
				'example'     => __( 'Example: 10% off when average order value is at least €75.', 'mp-commerce-promotions' ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $input Sanitized template inputs from admin POST.
	 * @return array{
	 *     conditions: list<array<string, mixed>>,
	 *     actions: list<array<string, mixed>>,
	 *     restrictions: list<array<string, mixed>>
	 * }
	 */
	public static function build( string $template_key, array $input ): array {
		$template_key = trim( $template_key );
		if ( ! array_key_exists( $template_key, self::templates() ) ) {
			throw new InvalidArgumentException( 'invalid_template_key' );
		}

		switch ( $template_key ) {
			case self::TEMPLATE_PERCENT_OFF_CATEGORY:
				return self::build_percent_off_category( $input );
			case self::TEMPLATE_FIXED_OFF_PRODUCTS:
				return self::build_fixed_off_products( $input );
			case self::TEMPLATE_BUY_X_GET_Y_CHEAPEST_FREE:
				return self::build_buy_x_get_y_cheapest_free( $input );
			case self::TEMPLATE_FREE_SHIPPING_OVER_SUBTOTAL:
				return self::build_free_shipping_over_subtotal( $input );
			case self::TEMPLATE_FREE_GIFT_OVER_SUBTOTAL:
				return self::build_free_gift_over_subtotal( $input );
			case self::TEMPLATE_FIRST_ORDER_DISCOUNT:
				return self::build_first_order_discount( $input );
			case self::TEMPLATE_CUSTOMER_ROLE_DISCOUNT:
				return self::build_customer_role_discount( $input );
			case self::TEMPLATE_VIP_CUSTOMER:
				return self::build_vip_customer( $input );
			case self::TEMPLATE_LOYAL_CUSTOMER:
				return self::build_loyal_customer( $input );
			case self::TEMPLATE_RETURNING_CUSTOMER:
				return self::build_returning_customer( $input );
			default:
				throw new InvalidArgumentException( 'invalid_template_key' );
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private static function build_percent_off_category( array $input ): array {
		$category_ids = self::require_positive_int_list( $input, 'category_ids' );
		$percentage   = self::require_percentage( $input, 'percentage' );

		$conditions = array();
		$min_eligible = self::optional_non_negative_float( $input, 'minimum_eligible_subtotal' );
		if ( $min_eligible !== null && $min_eligible > 0 ) {
			$conditions[] = array(
				'type'         => RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL,
				'amount'       => $min_eligible,
				'category_ids' => $category_ids,
			);
		}

		$actions = array(
			array(
				'type'         => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage'   => $percentage,
				'category_ids' => $category_ids,
			),
		);

		return self::rules_payload( $conditions, $actions );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private static function build_fixed_off_products( array $input ): array {
		$product_ids = self::require_positive_int_list( $input, 'product_ids' );
		$amount      = self::require_positive_float( $input, 'amount' );

		$conditions = array();
		$min_eligible = self::optional_non_negative_float( $input, 'minimum_eligible_subtotal' );
		if ( $min_eligible !== null && $min_eligible > 0 ) {
			$conditions[] = array(
				'type'        => RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL,
				'amount'      => $min_eligible,
				'product_ids' => $product_ids,
			);
		}

		$actions = array(
			array(
				'type'        => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
				'amount'      => $amount,
				'product_ids' => $product_ids,
			),
		);

		return self::rules_payload( $conditions, $actions );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private static function build_buy_x_get_y_cheapest_free( array $input ): array {
		$scope = isset( $input['scope'] ) ? trim( (string) $input['scope'] ) : '';
		if ( $scope !== CheapestItemDiscountAction::SCOPE_CATEGORY && $scope !== CheapestItemDiscountAction::SCOPE_PRODUCTS ) {
			throw new InvalidArgumentException( 'invalid_scope' );
		}

		$required_quantity   = self::require_positive_int( $input, 'required_quantity' );
		$discounted_quantity = self::require_positive_int( $input, 'discounted_quantity' );
		if ( $discounted_quantity > $required_quantity ) {
			throw new InvalidArgumentException( 'invalid_discounted_quantity' );
		}

		$discount_percentage = self::optional_percentage( $input, 'discount_percentage' );
		if ( $discount_percentage === null ) {
			$discount_percentage = 100.0;
		}

		$action = array(
			'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
			'scope'               => $scope,
			'discount_percentage' => $discount_percentage,
			'required_quantity'   => $required_quantity,
			'discounted_quantity' => $discounted_quantity,
		);

		if ( $scope === CheapestItemDiscountAction::SCOPE_CATEGORY ) {
			$action['category_ids'] = self::require_positive_int_list( $input, 'category_ids' );
		} else {
			$action['product_ids'] = self::require_positive_int_list( $input, 'product_ids' );
			$variation_ids         = self::optional_positive_int_list( $input, 'variation_ids' );
			if ( $variation_ids !== array() ) {
				$action['variation_ids'] = $variation_ids;
			}
		}

		CheapestItemDiscountAction::from_config( $action );

		return self::rules_payload( array(), array( $action ) );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private static function build_free_shipping_over_subtotal( array $input ): array {
		$amount = self::require_non_negative_float( $input, 'amount' );

		return self::rules_payload(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => $amount,
				),
			),
			array(
				array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private static function build_free_gift_over_subtotal( array $input ): array {
		$amount       = self::require_non_negative_float( $input, 'amount' );
		$product_id   = self::require_positive_int( $input, 'gift_product_id' );
		$variation_id = self::optional_positive_int( $input, 'gift_variation_id' );
		$quantity     = self::optional_positive_int( $input, 'gift_quantity' );
		if ( $quantity === null ) {
			$quantity = 1;
		}

		$action = array(
			'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
			'product_id' => $product_id,
			'quantity'   => $quantity,
		);
		if ( $variation_id !== null ) {
			$action['variation_id'] = $variation_id;
		}

		return self::rules_payload(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => $amount,
				),
			),
			array( $action )
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private static function build_first_order_discount( array $input ): array {
		$action = self::build_whole_cart_discount_action( $input );

		return self::rules_payload(
			array( array( 'type' => RuleTypes::CONDITION_FIRST_ORDER ) ),
			array( $action )
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	/**
	 * @param array<string, mixed> $input
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private static function build_vip_customer( array $input ): array {
		$threshold = self::require_non_negative_float( $input, 'lifetime_spend_threshold' );
		$action    = self::build_whole_cart_discount_action( $input );

		return self::rules_payload(
			array(
				array( 'type' => RuleTypes::CONDITION_LOGGED_IN ),
				array(
					'type'     => RuleTypes::CONDITION_CUSTOMER_LIFETIME_SPEND,
					'operator' => '>=',
					'amount'   => $threshold,
				),
			),
			array( $action )
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private static function build_loyal_customer( array $input ): array {
		$count  = self::require_positive_float( $input, 'order_count_threshold' );
		$action = self::build_whole_cart_discount_action( $input );

		return self::rules_payload(
			array(
				array( 'type' => RuleTypes::CONDITION_LOGGED_IN ),
				array(
					'type'     => RuleTypes::CONDITION_CUSTOMER_ORDER_COUNT,
					'operator' => '>=',
					'count'    => $count,
				),
			),
			array( $action )
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private static function build_returning_customer( array $input ): array {
		$threshold = self::require_non_negative_float( $input, 'average_order_value_threshold' );
		$action    = self::build_whole_cart_discount_action( $input );

		return self::rules_payload(
			array(
				array( 'type' => RuleTypes::CONDITION_LOGGED_IN ),
				array(
					'type'     => RuleTypes::CONDITION_CUSTOMER_AVERAGE_ORDER_VALUE,
					'operator' => '>=',
					'amount'   => $threshold,
				),
			),
			array( $action )
		);
	}

	private static function build_customer_role_discount( array $input ): array {
		$roles = self::require_role_list( $input, 'roles' );
		$action = self::build_whole_cart_discount_action( $input );

		return self::rules_payload(
			array(
				array(
					'type'  => RuleTypes::CONDITION_CUSTOMER_ROLE,
					'roles' => $roles,
				),
			),
			array( $action )
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function build_whole_cart_discount_action( array $input ): array {
		$discount_type = isset( $input['discount_type'] ) ? trim( (string) $input['discount_type'] ) : '';
		if ( $discount_type === 'percentage' ) {
			return array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => self::require_percentage( $input, 'percentage' ),
			);
		}
		if ( $discount_type === 'fixed' ) {
			return array(
				'type'   => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
				'amount' => self::require_positive_float( $input, 'amount' ),
			);
		}

		throw new InvalidArgumentException( 'invalid_discount_type' );
	}

	/**
	 * @param list<array<string, mixed>> $conditions
	 * @param list<array<string, mixed>> $actions
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private static function rules_payload( array $conditions, array $actions ): array {
		return array(
			'conditions'   => $conditions,
			'actions'      => $actions,
			'restrictions' => array(),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return list<int>
	 */
	private static function require_positive_int_list( array $input, string $key ): array {
		if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'missing_' . $key );
		}

		$ids = CartItemSelector::normalize_positive_int_list( $input[ $key ] );
		if ( $ids === array() ) {
			throw new InvalidArgumentException( 'invalid_' . $key );
		}

		return $ids;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return list<int>
	 */
	private static function optional_positive_int_list( array $input, string $key ): array {
		if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
			return array();
		}

		return CartItemSelector::normalize_positive_int_list( $input[ $key ] );
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private static function require_positive_int( array $input, string $key ): int {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'missing_' . $key );
		}

		$value = (int) $input[ $key ];
		if ( $value < 1 ) {
			throw new InvalidArgumentException( 'invalid_' . $key );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private static function optional_positive_int( array $input, string $key ): ?int {
		if ( ! isset( $input[ $key ] ) || $input[ $key ] === '' || $input[ $key ] === null ) {
			return null;
		}

		if ( ! is_numeric( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'invalid_' . $key );
		}

		$value = (int) $input[ $key ];
		if ( $value < 1 ) {
			throw new InvalidArgumentException( 'invalid_' . $key );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private static function require_percentage( array $input, string $key ): float {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'missing_' . $key );
		}

		$value = (float) $input[ $key ];
		if ( $value <= 0 || $value > 100 ) {
			throw new InvalidArgumentException( 'invalid_' . $key );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private static function optional_percentage( array $input, string $key ): ?float {
		if ( ! isset( $input[ $key ] ) || $input[ $key ] === '' || $input[ $key ] === null ) {
			return null;
		}

		return self::require_percentage( $input, $key );
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private static function require_positive_float( array $input, string $key ): float {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'missing_' . $key );
		}

		$value = (float) $input[ $key ];
		if ( $value <= 0 ) {
			throw new InvalidArgumentException( 'invalid_' . $key );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private static function require_non_negative_float( array $input, string $key ): float {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'missing_' . $key );
		}

		$value = (float) $input[ $key ];
		if ( $value < 0 ) {
			throw new InvalidArgumentException( 'invalid_' . $key );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private static function optional_non_negative_float( array $input, string $key ): ?float {
		if ( ! isset( $input[ $key ] ) || $input[ $key ] === '' || $input[ $key ] === null ) {
			return null;
		}

		return self::require_non_negative_float( $input, $key );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return list<string>
	 */
	private static function require_role_list( array $input, string $key ): array {
		if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'missing_' . $key );
		}

		$roles = array();
		foreach ( $input[ $key ] as $role ) {
			if ( ! is_string( $role ) ) {
				continue;
			}
			$role = sanitize_key( trim( $role ) );
			if ( $role !== '' ) {
				$roles[] = $role;
			}
		}

		$roles = array_values( array_unique( $roles ) );
		if ( $roles === array() ) {
			throw new InvalidArgumentException( 'invalid_' . $key );
		}

		return $roles;
	}

	private function __construct() {
	}
}
