<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
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
