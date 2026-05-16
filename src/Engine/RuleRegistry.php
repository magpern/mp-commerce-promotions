<?php
/**
 * Static registry of supported condition and action types (MVP).
 *
 * Dynamic plugin-style registration is not implemented yet; extend RuleTypes and this
 * class together when adding new engine types.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class RuleRegistry {

	/**
	 * @return list<string>
	 */
	public static function supported_conditions(): array {
		return array(
			RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
			RuleTypes::CONDITION_PRODUCT_QUANTITY,
			RuleTypes::CONDITION_CATEGORY_QUANTITY,
			RuleTypes::CONDITION_LOGGED_IN,
			RuleTypes::CONDITION_FIRST_ORDER,
			RuleTypes::CONDITION_CUSTOMER_ROLE,
			RuleTypes::CONDITION_BILLING_COUNTRY,
			RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN,
			RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT,
			RuleTypes::CONDITION_MINIMUM_CART_QUANTITY,
			RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY,
		);
	}

	/**
	 * @return list<string>
	 */
	public static function supported_actions(): array {
		return array(
			RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
			RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
			RuleTypes::ACTION_FREE_SHIPPING,
			RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
			RuleTypes::ACTION_FREE_GIFT_PRODUCT,
		);
	}

	public static function is_supported_condition( string $type ): bool {
		$type = trim( $type );
		if ( $type === '' ) {
			return false;
		}

		return in_array( $type, self::supported_conditions(), true );
	}

	public static function is_supported_action( string $type ): bool {
		$type = trim( $type );
		if ( $type === '' ) {
			return false;
		}

		return in_array( $type, self::supported_actions(), true );
	}

	public static function condition_label( string $type ): string {
		$labels = array(
			RuleTypes::CONDITION_MINIMUM_SUBTOTAL       => 'Minimum subtotal',
			RuleTypes::CONDITION_PRODUCT_QUANTITY       => 'Product quantity',
			RuleTypes::CONDITION_CATEGORY_QUANTITY      => 'Category quantity',
			RuleTypes::CONDITION_LOGGED_IN              => 'Logged in',
			RuleTypes::CONDITION_FIRST_ORDER            => 'First order',
			RuleTypes::CONDITION_CUSTOMER_ROLE          => 'Customer role',
			RuleTypes::CONDITION_BILLING_COUNTRY        => 'Billing country',
			RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN  => 'Customer email domain',
			RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT => 'Customer redemption count',
			RuleTypes::CONDITION_MINIMUM_CART_QUANTITY   => 'Minimum cart quantity',
			RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY   => 'Maximum cart quantity',
		);

		return $labels[ $type ] ?? $type;
	}

	public static function action_label( string $type ): string {
		$labels = array(
			RuleTypes::ACTION_PERCENTAGE_DISCOUNT    => 'Percentage discount',
			RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT  => 'Fixed amount discount',
			RuleTypes::ACTION_FREE_SHIPPING          => 'Free shipping',
			RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT => 'Cheapest item discount',
			RuleTypes::ACTION_FREE_GIFT_PRODUCT     => 'Free gift product',
		);

		return $labels[ $type ] ?? $type;
	}

	private function __construct() {
	}
}
