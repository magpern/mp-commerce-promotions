<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionTemplate;
use PHPUnit\Framework\TestCase;

final class PromotionTemplateTest extends TestCase {

	public function test_templates_returns_seven_presets(): void {
		$this->assertCount( 10, PromotionTemplate::templates() );
		$this->assertArrayHasKey( PromotionTemplate::TEMPLATE_PERCENT_OFF_CATEGORY, PromotionTemplate::templates() );
	}

	public function test_percent_off_category_builds_scoped_rules(): void {
		$built = PromotionTemplate::build(
			PromotionTemplate::TEMPLATE_PERCENT_OFF_CATEGORY,
			array(
				'category_ids'              => array( 10, 12 ),
				'percentage'                => 20,
				'minimum_eligible_subtotal' => 50,
			)
		);

		$this->assertCount( 1, $built['conditions'] );
		$this->assertSame( RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL, $built['conditions'][0]['type'] );
		$this->assertSame( 50.0, $built['conditions'][0]['amount'] );
		$this->assertSame( array( 10, 12 ), $built['actions'][0]['category_ids'] );
		$this->assertSame( 20.0, $built['actions'][0]['percentage'] );
		$this->assertSame( array(), $built['restrictions'] );
	}

	public function test_fixed_off_products_without_optional_condition(): void {
		$built = PromotionTemplate::build(
			PromotionTemplate::TEMPLATE_FIXED_OFF_PRODUCTS,
			array(
				'product_ids' => array( 100 ),
				'amount'      => 15,
			)
		);

		$this->assertSame( array(), $built['conditions'] );
		$this->assertSame( RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, $built['actions'][0]['type'] );
		$this->assertSame( array( 100 ), $built['actions'][0]['product_ids'] );
	}

	public function test_buy_x_get_y_cheapest_free_category_scope(): void {
		$built = PromotionTemplate::build(
			PromotionTemplate::TEMPLATE_BUY_X_GET_Y_CHEAPEST_FREE,
			array(
				'scope'               => CheapestItemDiscountAction::SCOPE_CATEGORY,
				'category_ids'        => array( 10 ),
				'required_quantity'   => 3,
				'discounted_quantity' => 1,
			)
		);

		$this->assertSame( CheapestItemDiscountAction::SCOPE_CATEGORY, $built['actions'][0]['scope'] );
		$this->assertSame( 100.0, $built['actions'][0]['discount_percentage'] );
	}

	public function test_free_shipping_over_subtotal(): void {
		$built = PromotionTemplate::build(
			PromotionTemplate::TEMPLATE_FREE_SHIPPING_OVER_SUBTOTAL,
			array( 'amount' => 75 )
		);

		$this->assertSame( RuleTypes::CONDITION_MINIMUM_SUBTOTAL, $built['conditions'][0]['type'] );
		$this->assertSame( RuleTypes::ACTION_FREE_SHIPPING, $built['actions'][0]['type'] );
	}

	public function test_free_gift_over_subtotal(): void {
		$built = PromotionTemplate::build(
			PromotionTemplate::TEMPLATE_FREE_GIFT_OVER_SUBTOTAL,
			array(
				'amount'           => 100,
				'gift_product_id'  => 55,
				'gift_variation_id' => 56,
				'gift_quantity'    => 2,
			)
		);

		$this->assertSame( 55, $built['actions'][0]['product_id'] );
		$this->assertSame( 56, $built['actions'][0]['variation_id'] );
		$this->assertSame( 2, $built['actions'][0]['quantity'] );
	}

	public function test_first_order_percentage_discount(): void {
		$built = PromotionTemplate::build(
			PromotionTemplate::TEMPLATE_FIRST_ORDER_DISCOUNT,
			array(
				'discount_type' => 'percentage',
				'percentage'    => 10,
			)
		);

		$this->assertSame( RuleTypes::CONDITION_FIRST_ORDER, $built['conditions'][0]['type'] );
		$this->assertSame( 10.0, $built['actions'][0]['percentage'] );
	}

	public function test_customer_role_fixed_discount(): void {
		$built = PromotionTemplate::build(
			PromotionTemplate::TEMPLATE_CUSTOMER_ROLE_DISCOUNT,
			array(
				'roles'         => array( 'customer', 'vip' ),
				'discount_type' => 'fixed',
				'amount'        => 25,
			)
		);

		$this->assertSame( array( 'customer', 'vip' ), $built['conditions'][0]['roles'] );
		$this->assertSame( 25.0, $built['actions'][0]['amount'] );
	}

	public function test_invalid_template_key_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_template_key' );

		PromotionTemplate::build( 'not_a_template', array() );
	}

	public function test_missing_category_ids_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing_category_ids' );

		PromotionTemplate::build(
			PromotionTemplate::TEMPLATE_PERCENT_OFF_CATEGORY,
			array( 'percentage' => 10 )
		);
	}
}
