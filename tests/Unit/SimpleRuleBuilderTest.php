<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\SimpleRuleBuilder;
use PHPUnit\Framework\TestCase;

final class SimpleRuleBuilderTest extends TestCase {

	public function test_builds_minimum_subtotal_and_percentage_discount(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'mp_cp_builder_amount'         => '50',
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'mp_cp_builder_percentage'     => '10',
			)
		);

		$this->assertSame( RuleTypes::CONDITION_MINIMUM_SUBTOTAL, $built['conditions'][0]['type'] );
		$this->assertSame( 50.0, $built['conditions'][0]['amount'] );
		$this->assertSame( RuleTypes::ACTION_PERCENTAGE_DISCOUNT, $built['actions'][0]['type'] );
		$this->assertSame( 10.0, $built['actions'][0]['percentage'] );
	}

	public function test_builds_product_quantity_and_fixed_amount_discount(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type'  => RuleTypes::CONDITION_PRODUCT_QUANTITY,
				'mp_cp_builder_product_id'      => '12',
				'mp_cp_builder_operator'        => '>=',
				'mp_cp_builder_quantity'        => '2',
				'mp_cp_builder_action_type'     => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
				'mp_cp_builder_fixed_amount'    => '15',
			)
		);

		$this->assertSame( RuleTypes::CONDITION_PRODUCT_QUANTITY, $built['conditions'][0]['type'] );
		$this->assertSame( 12, $built['conditions'][0]['product_id'] );
		$this->assertSame( RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, $built['actions'][0]['type'] );
		$this->assertSame( 15.0, $built['actions'][0]['amount'] );
	}

	public function test_builds_category_quantity(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_CATEGORY_QUANTITY,
				'mp_cp_builder_category_id'    => '8',
				'mp_cp_builder_operator'      => '>',
				'mp_cp_builder_quantity'      => '1',
				'mp_cp_builder_action_type'   => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'mp_cp_builder_percentage'    => '5',
			)
		);

		$this->assertSame( RuleTypes::CONDITION_CATEGORY_QUANTITY, $built['conditions'][0]['type'] );
		$this->assertSame( 8, $built['conditions'][0]['category_id'] );
	}

	public function test_rejects_invalid_condition_type(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_condition_type' );

		SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => 'not_a_supported_condition',
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'mp_cp_builder_percentage'     => '10',
			)
		);
	}

	public function test_rejects_invalid_action_type(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_action_type' );

		SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'mp_cp_builder_amount'         => '10',
				'mp_cp_builder_action_type'    => 'first_order',
			)
		);
	}

	public function test_rejects_invalid_operator(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_operator' );

		SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_PRODUCT_QUANTITY,
				'mp_cp_builder_product_id'     => '1',
				'mp_cp_builder_operator'       => '!=',
				'mp_cp_builder_quantity'       => '1',
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'mp_cp_builder_percentage'     => '10',
			)
		);
	}

	public function test_rejects_invalid_percentage(): void {
		$this->expectException( InvalidArgumentException::class );

		SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'mp_cp_builder_amount'         => '10',
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'mp_cp_builder_percentage'     => '',
			)
		);
	}

	public function test_rejects_percentage_above_one_hundred_via_action_validation(): void {
		$this->expectException( InvalidArgumentException::class );

		SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'mp_cp_builder_amount'         => '10',
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'mp_cp_builder_percentage'     => '150',
			)
		);
	}

	public function test_builds_logged_in_and_free_shipping(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_LOGGED_IN,
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_FREE_SHIPPING,
			)
		);

		$this->assertSame( RuleTypes::CONDITION_LOGGED_IN, $built['conditions'][0]['type'] );
		$this->assertSame( RuleTypes::ACTION_FREE_SHIPPING, $built['actions'][0]['type'] );
	}

	public function test_builds_first_order(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_FIRST_ORDER,
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'mp_cp_builder_percentage'     => '5',
			)
		);

		$this->assertSame( RuleTypes::CONDITION_FIRST_ORDER, $built['conditions'][0]['type'] );
	}

	public function test_builds_customer_role_from_comma_list(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_CUSTOMER_ROLE,
				'mp_cp_builder_roles'          => 'customer, vip',
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'mp_cp_builder_percentage'     => '10',
			)
		);

		$this->assertSame( array( 'customer', 'vip' ), $built['conditions'][0]['roles'] );
	}

	public function test_builds_billing_country_from_comma_list(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_BILLING_COUNTRY,
				'mp_cp_builder_countries'      => 'SE, NO',
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'mp_cp_builder_percentage'     => '10',
			)
		);

		$this->assertSame( array( 'SE', 'NO' ), $built['conditions'][0]['countries'] );
	}

	public function test_builds_customer_email_domain_from_comma_list(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN,
				'mp_cp_builder_domains'        => 'example.com, company.com',
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'mp_cp_builder_percentage'     => '10',
			)
		);

		$this->assertSame( array( 'example.com', 'company.com' ), $built['conditions'][0]['domains'] );
	}

	public function test_builds_customer_redemption_count(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type'   => RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT,
				'mp_cp_builder_operator'         => '<',
				'mp_cp_builder_redemption_count' => '1',
				'mp_cp_builder_action_type'      => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
				'mp_cp_builder_fixed_amount'     => '5',
			)
		);

		$this->assertSame( RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT, $built['conditions'][0]['type'] );
		$this->assertSame( '<', $built['conditions'][0]['operator'] );
		$this->assertSame( 1.0, $built['conditions'][0]['count'] );
	}

	public function test_builds_cheapest_item_discount_category_config(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type'              => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'mp_cp_builder_amount'                      => '1',
				'mp_cp_builder_action_type'                 => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'mp_cp_builder_cheapest_scope'              => CheapestItemDiscountAction::SCOPE_CATEGORY,
				'mp_cp_builder_cheapest_category_ids'       => '123, 124',
				'mp_cp_builder_cheapest_required_quantity'    => '3',
				'mp_cp_builder_cheapest_discounted_quantity' => '1',
				'mp_cp_builder_cheapest_discount_percentage'  => '100',
			)
		);

		$action = $built['actions'][0];
		$this->assertSame( RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT, $action['type'] );
		$this->assertSame( CheapestItemDiscountAction::SCOPE_CATEGORY, $action['scope'] );
		$this->assertSame( array( 123, 124 ), $action['category_ids'] );
		$this->assertSame( 3, $action['required_quantity'] );
		$this->assertSame( 1, $action['discounted_quantity'] );
		$this->assertSame( 100.0, $action['discount_percentage'] );
	}

	public function test_builds_cheapest_item_discount_products_config(): void {
		$built = SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type'              => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'mp_cp_builder_amount'                      => '1',
				'mp_cp_builder_action_type'                 => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'mp_cp_builder_cheapest_scope'              => CheapestItemDiscountAction::SCOPE_PRODUCTS,
				'mp_cp_builder_cheapest_product_ids'        => '100, 101',
				'mp_cp_builder_cheapest_required_quantity'    => '2',
				'mp_cp_builder_cheapest_discounted_quantity' => '1',
				'mp_cp_builder_cheapest_discount_percentage'  => '50',
			)
		);

		$action = $built['actions'][0];
		$this->assertSame( CheapestItemDiscountAction::SCOPE_PRODUCTS, $action['scope'] );
		$this->assertSame( array( 100, 101 ), $action['product_ids'] );
		$this->assertSame( 50.0, $action['discount_percentage'] );
	}

	public function test_rejects_invalid_cheapest_scope(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_cheapest_scope' );

		SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type'             => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'mp_cp_builder_amount'                     => '1',
				'mp_cp_builder_action_type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'mp_cp_builder_cheapest_scope'             => 'invalid',
				'mp_cp_builder_cheapest_category_ids'      => '10',
				'mp_cp_builder_cheapest_required_quantity' => '1',
				'mp_cp_builder_cheapest_discounted_quantity' => '1',
				'mp_cp_builder_cheapest_discount_percentage' => '100',
			)
		);
	}

	public function test_rejects_missing_cheapest_category_ids(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_cheapest_category_ids' );

		SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type'             => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'mp_cp_builder_amount'                     => '1',
				'mp_cp_builder_action_type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'mp_cp_builder_cheapest_scope'             => CheapestItemDiscountAction::SCOPE_CATEGORY,
				'mp_cp_builder_cheapest_category_ids'    => '',
				'mp_cp_builder_cheapest_required_quantity' => '3',
				'mp_cp_builder_cheapest_discounted_quantity' => '1',
				'mp_cp_builder_cheapest_discount_percentage' => '100',
			)
		);
	}

	public function test_rejects_invalid_fixed_amount(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_fixed_amount' );

		SimpleRuleBuilder::build_from_post(
			array(
				'mp_cp_builder_condition_type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'mp_cp_builder_amount'         => '10',
				'mp_cp_builder_action_type'    => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
				'mp_cp_builder_fixed_amount'   => '',
			)
		);
	}
}
