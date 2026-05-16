<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Condition\MaximumCartQuantityCondition;
use MP\CommercePromotions\Engine\Condition\MinimumCartQuantityCondition;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class CartQuantityConditionTest extends TestCase {

	public function test_minimum_cart_quantity_passes_and_fails(): void {
		$condition = new MinimumCartQuantityCondition( 3 );
		$context   = PromotionTestFixtures::cart_context(
			null,
			50.0,
			array(
				array(
					'product_id' => 1,
					'quantity'   => 2.0,
				),
				array(
					'product_id' => 2,
					'quantity'   => 2.0,
				),
			)
		);

		$this->assertTrue( $condition->evaluate( $context )->passed() );

		$low_context = PromotionTestFixtures::cart_context(
			null,
			50.0,
			array(
				array(
					'product_id' => 1,
					'quantity'   => 1.0,
				),
			)
		);
		$this->assertFalse( $condition->evaluate( $low_context )->passed() );
	}

	public function test_maximum_cart_quantity_passes_and_fails(): void {
		$condition = new MaximumCartQuantityCondition( 2 );
		$context   = PromotionTestFixtures::cart_context(
			null,
			50.0,
			array(
				array(
					'product_id' => 1,
					'quantity'   => 1.0,
				),
			)
		);

		$this->assertTrue( $condition->evaluate( $context )->passed() );

		$high_context = PromotionTestFixtures::cart_context(
			null,
			50.0,
			array(
				array(
					'product_id' => 1,
					'quantity'   => 3.0,
				),
			)
		);
		$this->assertFalse( $condition->evaluate( $high_context )->passed() );
	}

	public function test_types_match_rule_types(): void {
		$this->assertSame( RuleTypes::CONDITION_MINIMUM_CART_QUANTITY, ( new MinimumCartQuantityCondition( 1 ) )->get_type() );
		$this->assertSame( RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY, ( new MaximumCartQuantityCondition( 1 ) )->get_type() );
	}
}
