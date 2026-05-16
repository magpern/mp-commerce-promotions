<?php
/**
 * Builds a single-condition / single-action rules payload for the admin rule builder.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\Action\FreeGiftProductAction;
use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\Action\FreeShippingAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\BillingCountryCondition;
use MP\CommercePromotions\Engine\Condition\CategoryQuantityCondition;
use MP\CommercePromotions\Engine\Condition\CustomerEmailDomainCondition;
use MP\CommercePromotions\Engine\Condition\CustomerRedemptionCountCondition;
use MP\CommercePromotions\Engine\Condition\CustomerRoleCondition;
use MP\CommercePromotions\Engine\Condition\MaximumCartQuantityCondition;
use MP\CommercePromotions\Engine\Condition\MinimumCartQuantityCondition;
use MP\CommercePromotions\Engine\Condition\MinimumSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\ProductQuantityCondition;
use MP\CommercePromotions\Engine\Condition\QuantityComparator;
use MP\CommercePromotions\Engine\RuleRegistry;
use MP\CommercePromotions\Engine\RuleTypes;

final class SimpleRuleBuilder {

	/**
	 * @param array<string, mixed> $post Unslashed POST values (builder fields only).
	 * @return array{conditions: array<int, array<string, mixed>>, actions: array<int, array<string, mixed>>}
	 */
	public static function build_from_post( array $post ): array {
		$condition_type = isset( $post['mp_cp_builder_condition_type'] )
			? sanitize_text_field( (string) $post['mp_cp_builder_condition_type'] )
			: '';

		if ( ! RuleRegistry::is_supported_condition( $condition_type ) ) {
			throw new InvalidArgumentException( 'invalid_condition_type' );
		}

		$action_type = isset( $post['mp_cp_builder_action_type'] )
			? sanitize_text_field( (string) $post['mp_cp_builder_action_type'] )
			: '';

		if ( ! RuleRegistry::is_supported_action( $action_type ) ) {
			throw new InvalidArgumentException( 'invalid_action_type' );
		}

		$conditions = array( self::build_condition( $condition_type, $post ) );
		$actions    = array( self::build_action( $action_type, $post ) );

		return array(
			'conditions' => $conditions,
			'actions'    => $actions,
		);
	}

	/**
	 * @param array<string, mixed> $post
	 * @return array<string, mixed>
	 */
	private static function build_condition( string $type, array $post ): array {
		if ( $type === RuleTypes::CONDITION_MINIMUM_SUBTOTAL ) {
			$amount = self::parse_required_float( $post, 'mp_cp_builder_amount', 'invalid_amount' );
			new MinimumSubtotalCondition( $amount );

			return array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => $amount,
			);
		}

		if ( $type === RuleTypes::CONDITION_LOGGED_IN ) {
			return array( 'type' => RuleTypes::CONDITION_LOGGED_IN );
		}

		if ( $type === RuleTypes::CONDITION_FIRST_ORDER ) {
			return array( 'type' => RuleTypes::CONDITION_FIRST_ORDER );
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_ROLE ) {
			$roles = self::parse_comma_list( $post, 'mp_cp_builder_roles', 'invalid_roles' );
			new CustomerRoleCondition( $roles );

			return array(
				'type'  => RuleTypes::CONDITION_CUSTOMER_ROLE,
				'roles' => $roles,
			);
		}

		if ( $type === RuleTypes::CONDITION_BILLING_COUNTRY ) {
			$countries = self::parse_comma_list( $post, 'mp_cp_builder_countries', 'invalid_countries' );
			new BillingCountryCondition( $countries );

			return array(
				'type'      => RuleTypes::CONDITION_BILLING_COUNTRY,
				'countries' => $countries,
			);
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN ) {
			$domains = self::parse_comma_list( $post, 'mp_cp_builder_domains', 'invalid_domains' );
			new CustomerEmailDomainCondition( $domains );

			return array(
				'type'    => RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN,
				'domains' => $domains,
			);
		}

		if ( $type === RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT ) {
			$operator = self::parse_operator( $post );
			$count    = self::parse_required_float( $post, 'mp_cp_builder_redemption_count', 'invalid_redemption_count' );
			new CustomerRedemptionCountCondition( $operator, $count );

			return array(
				'type'     => RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT,
				'operator' => $operator,
				'count'    => $count,
			);
		}

		if ( $type === RuleTypes::CONDITION_MINIMUM_CART_QUANTITY ) {
			$quantity = self::parse_required_positive_int( $post, 'mp_cp_builder_cart_quantity', 'invalid_cart_quantity' );
			new MinimumCartQuantityCondition( $quantity );

			return array(
				'type'     => RuleTypes::CONDITION_MINIMUM_CART_QUANTITY,
				'quantity' => $quantity,
			);
		}

		if ( $type === RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY ) {
			$quantity = self::parse_required_positive_int( $post, 'mp_cp_builder_cart_quantity', 'invalid_cart_quantity' );
			new MaximumCartQuantityCondition( $quantity );

			return array(
				'type'     => RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY,
				'quantity' => $quantity,
			);
		}

		$operator = self::parse_operator( $post );
		$quantity = self::parse_required_float( $post, 'mp_cp_builder_quantity', 'invalid_quantity' );

		if ( $type === RuleTypes::CONDITION_PRODUCT_QUANTITY ) {
			$product_id = self::parse_required_positive_int( $post, 'mp_cp_builder_product_id', 'invalid_product_id' );
			new ProductQuantityCondition( $product_id, $operator, $quantity );

			return array(
				'type'       => RuleTypes::CONDITION_PRODUCT_QUANTITY,
				'product_id' => $product_id,
				'operator'   => $operator,
				'quantity'   => $quantity,
			);
		}

		$category_id = self::parse_required_positive_int( $post, 'mp_cp_builder_category_id', 'invalid_category_id' );
		new CategoryQuantityCondition( $category_id, $operator, $quantity );

		return array(
			'type'        => RuleTypes::CONDITION_CATEGORY_QUANTITY,
			'category_id' => $category_id,
			'operator'    => $operator,
			'quantity'    => $quantity,
		);
	}

	/**
	 * @param array<string, mixed> $post
	 * @return array<string, mixed>
	 */
	private static function build_action( string $type, array $post ): array {
		if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
			$percentage = self::parse_required_float( $post, 'mp_cp_builder_percentage', 'invalid_percentage' );
			new PercentageDiscountAction( $percentage );

			return array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => $percentage,
			);
		}

		if ( $type === RuleTypes::ACTION_FREE_SHIPPING ) {
			new FreeShippingAction();

			return array( 'type' => RuleTypes::ACTION_FREE_SHIPPING );
		}

		if ( $type === RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
			return self::build_cheapest_item_discount_action( $post );
		}

		if ( $type === RuleTypes::ACTION_FREE_GIFT_PRODUCT ) {
			return self::build_free_gift_product_action( $post );
		}

		$amount = self::parse_required_float( $post, 'mp_cp_builder_fixed_amount', 'invalid_fixed_amount' );
		new FixedAmountDiscountAction( $amount );

		return array(
			'type'   => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
			'amount' => $amount,
		);
	}

	/**
	 * @param array<string, mixed> $post
	 * @return array<string, mixed>
	 */
	private static function build_cheapest_item_discount_action( array $post ): array {
		$scope = isset( $post['mp_cp_builder_cheapest_scope'] )
			? trim( (string) $post['mp_cp_builder_cheapest_scope'] )
			: '';

		if ( $scope !== CheapestItemDiscountAction::SCOPE_CATEGORY && $scope !== CheapestItemDiscountAction::SCOPE_PRODUCTS ) {
			throw new InvalidArgumentException( 'invalid_cheapest_scope' );
		}

		$required_quantity   = self::parse_required_positive_int( $post, 'mp_cp_builder_cheapest_required_quantity', 'invalid_cheapest_required_quantity' );
		$discounted_quantity = self::parse_required_positive_int( $post, 'mp_cp_builder_cheapest_discounted_quantity', 'invalid_cheapest_discounted_quantity' );
		if ( $discounted_quantity > $required_quantity ) {
			throw new InvalidArgumentException( 'invalid_cheapest_discounted_quantity' );
		}

		$discount_percentage = self::parse_required_float( $post, 'mp_cp_builder_cheapest_discount_percentage', 'invalid_cheapest_discount_percentage' );
		if ( $discount_percentage <= 0 || $discount_percentage > 100 ) {
			throw new InvalidArgumentException( 'invalid_cheapest_discount_percentage' );
		}

		$config = array(
			'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
			'scope'               => $scope,
			'discount_percentage' => $discount_percentage,
			'required_quantity'   => $required_quantity,
			'discounted_quantity' => $discounted_quantity,
		);

		if ( $scope === CheapestItemDiscountAction::SCOPE_CATEGORY ) {
			$config['category_ids'] = self::parse_comma_int_list( $post, 'mp_cp_builder_cheapest_category_ids', 'invalid_cheapest_category_ids' );
		} else {
			$config['product_ids'] = self::parse_comma_int_list( $post, 'mp_cp_builder_cheapest_product_ids', 'invalid_cheapest_product_ids' );
		}

		CheapestItemDiscountAction::from_config( $config );

		return $config;
	}

	/**
	 * @param array<string, mixed> $post
	 * @return array<string, mixed>
	 */
	private static function build_free_gift_product_action( array $post ): array {
		$product_id = self::parse_required_positive_int( $post, 'mp_cp_builder_gift_product_id', 'invalid_gift_product_id' );
		$quantity   = self::parse_required_positive_int( $post, 'mp_cp_builder_gift_quantity', 'invalid_gift_quantity' );

		$config = array(
			'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
			'product_id' => $product_id,
			'quantity'   => $quantity,
		);

		$variation_id = self::parse_optional_positive_int( $post, 'mp_cp_builder_gift_variation_id' );
		if ( $variation_id !== null ) {
			$config['variation_id'] = $variation_id;
		}

		FreeGiftProductAction::from_config( $config );

		return $config;
	}

	/**
	 * @param array<string, mixed> $post
	 * @return list<int>
	 */
	private static function parse_comma_int_list( array $post, string $key, string $error_code ): array {
		$strings = self::parse_comma_list( $post, $key, $error_code );
		$ids     = array();

		foreach ( $strings as $value ) {
			if ( ! is_numeric( $value ) ) {
				throw new InvalidArgumentException( $error_code );
			}
			$id = (int) $value;
			if ( $id <= 0 ) {
				throw new InvalidArgumentException( $error_code );
			}
			$ids[] = $id;
		}

		return array_values( array_unique( $ids, SORT_NUMERIC ) );
	}

	/**
	 * @param array<string, mixed> $post
	 * @return list<string>
	 */
	private static function parse_comma_list( array $post, string $key, string $error_code ): array {
		if ( ! isset( $post[ $key ] ) || ! is_string( $post[ $key ] ) ) {
			throw new InvalidArgumentException( $error_code );
		}

		$raw = trim( $post[ $key ] );
		if ( $raw === '' ) {
			throw new InvalidArgumentException( $error_code );
		}

		$parts  = preg_split( '/\s*,\s*/', $raw );
		$values = array();
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				if ( ! is_string( $part ) ) {
					continue;
				}
				$part = trim( $part );
				if ( $part !== '' ) {
					$values[] = $part;
				}
			}
		}

		if ( $values === array() ) {
			throw new InvalidArgumentException( $error_code );
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function parse_operator( array $post ): string {
		$operator = isset( $post['mp_cp_builder_operator'] )
			? trim( (string) $post['mp_cp_builder_operator'] )
			: '';

		if ( ! QuantityComparator::supports( $operator ) ) {
			throw new InvalidArgumentException( 'invalid_operator' );
		}

		return $operator;
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function parse_required_float( array $post, string $key, string $error_code ): float {
		if ( ! isset( $post[ $key ] ) || $post[ $key ] === '' || ! is_numeric( $post[ $key ] ) ) {
			throw new InvalidArgumentException( $error_code );
		}

		return (float) $post[ $key ];
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function parse_required_positive_int( array $post, string $key, string $error_code ): int {
		if ( ! isset( $post[ $key ] ) || $post[ $key ] === '' || ! is_numeric( $post[ $key ] ) ) {
			throw new InvalidArgumentException( $error_code );
		}

		$value = (int) $post[ $key ];
		if ( $value <= 0 ) {
			throw new InvalidArgumentException( $error_code );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function parse_optional_positive_int( array $post, string $key ): ?int {
		if ( ! isset( $post[ $key ] ) || $post[ $key ] === '' ) {
			return null;
		}

		if ( ! is_numeric( $post[ $key ] ) ) {
			throw new InvalidArgumentException( 'invalid_gift_variation_id' );
		}

		$value = (int) $post[ $key ];
		if ( $value <= 0 ) {
			throw new InvalidArgumentException( 'invalid_gift_variation_id' );
		}

		return $value;
	}
}
