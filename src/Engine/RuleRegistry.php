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
		);
	}

	/**
	 * @return list<string>
	 */
	public static function supported_actions(): array {
		return array(
			RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
			RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
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

	private function __construct() {
	}
}
