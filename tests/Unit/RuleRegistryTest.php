<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\RuleRegistry;
use MP\CommercePromotions\Engine\RuleTypes;
use PHPUnit\Framework\TestCase;

final class RuleRegistryTest extends TestCase {

	public function test_supported_conditions_includes_mvp_types(): void {
		$conditions = RuleRegistry::supported_conditions();

		$this->assertContains( RuleTypes::CONDITION_MINIMUM_SUBTOTAL, $conditions );
		$this->assertContains( RuleTypes::CONDITION_PRODUCT_QUANTITY, $conditions );
		$this->assertContains( RuleTypes::CONDITION_CATEGORY_QUANTITY, $conditions );
		$this->assertContains( RuleTypes::CONDITION_LOGGED_IN, $conditions );
		$this->assertContains( RuleTypes::CONDITION_FIRST_ORDER, $conditions );
		$this->assertContains( RuleTypes::CONDITION_CUSTOMER_ROLE, $conditions );
		$this->assertContains( RuleTypes::CONDITION_BILLING_COUNTRY, $conditions );
		$this->assertContains( RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN, $conditions );
		$this->assertContains( RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT, $conditions );
		$this->assertContains( RuleTypes::CONDITION_MINIMUM_CART_QUANTITY, $conditions );
		$this->assertContains( RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY, $conditions );
	}

	public function test_supported_actions_includes_mvp_types(): void {
		$actions = RuleRegistry::supported_actions();

		$this->assertContains( RuleTypes::ACTION_PERCENTAGE_DISCOUNT, $actions );
		$this->assertContains( RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, $actions );
		$this->assertContains( RuleTypes::ACTION_FREE_SHIPPING, $actions );
		$this->assertContains( RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT, $actions );
		$this->assertContains( RuleTypes::ACTION_FREE_GIFT_PRODUCT, $actions );
	}

	public function test_unknown_condition_and_action_return_false(): void {
		$this->assertFalse( RuleRegistry::is_supported_condition( 'unknown_condition' ) );
		$this->assertFalse( RuleRegistry::is_supported_action( 'unknown_action' ) );
		$this->assertFalse( RuleRegistry::is_supported_condition( '' ) );
		$this->assertFalse( RuleRegistry::is_supported_action( '  ' ) );
	}

	public function test_known_types_are_supported(): void {
		$this->assertTrue( RuleRegistry::is_supported_condition( RuleTypes::CONDITION_MINIMUM_SUBTOTAL ) );
		$this->assertTrue( RuleRegistry::is_supported_action( RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) );
	}
}
