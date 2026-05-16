<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionEvaluatorTest extends TestCase {

	private PromotionEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new PromotionEvaluator();
	}

	public function test_active_minimum_subtotal_and_percentage_discount_eligible(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 50.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);

		$context = PromotionTestFixtures::cart_context( null, 100.0 );
		$result  = $this->evaluator->evaluate( $promotion, $context );

		$this->assertTrue( $result->is_eligible() );
		$this->assertCount( 1, $result->get_action_results() );
		$this->assertSame( RuleTypes::ACTION_PERCENTAGE_DISCOUNT, $result->get_action_results()[0]['type'] );
	}

	public function test_minimum_subtotal_below_threshold_ineligible(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 100.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);

		$result = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( null, 50.0 )
		);

		$this->assertFalse( $result->is_eligible() );
	}

	public function test_draft_promotion_ineligible(): void {
		$promotion = PromotionTestFixtures::promotion(
			PromotionStatus::DRAFT,
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);

		$result = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( null, 1000.0 )
		);

		$this->assertFalse( $result->is_eligible() );
		$this->assertStringContainsString( 'not active', $result->get_messages()[0] );
	}

	public function test_unknown_condition_ineligible(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array( 'type' => 'unknown_condition_type' ),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		);

		$result = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertFalse( $result->is_eligible() );
		$this->assertStringContainsString( 'Unknown condition', $result->get_messages()[0] );
	}

	public function test_unknown_action_ineligible(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array( 'type' => 'unknown_action_type' ),
			)
		);

		$result = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertFalse( $result->is_eligible() );
		$this->assertStringContainsString( 'Unknown action', $result->get_messages()[0] );
	}

	public function test_fixed_amount_discount_preview(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 0.0,
				),
			),
			array(
				array(
					'type'   => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
					'amount' => 25.0,
				),
			)
		);

		$result = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertTrue( $result->is_eligible() );
		$this->assertSame( 25.0, $result->get_action_results()[0]['payload']['amount'] );
	}

	public function test_product_quantity_pass_and_fail(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'       => RuleTypes::CONDITION_PRODUCT_QUANTITY,
					'product_id' => 10,
					'operator'   => '>=',
					'quantity'   => 2.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		);

		$pass = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context(
				null,
				50.0,
				array(
					array(
						'product_id' => 10,
						'quantity'   => 3,
					),
				)
			)
		);
		$this->assertTrue( $pass->is_eligible() );

		$fail = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context(
				null,
				50.0,
				array(
					array(
						'product_id' => 10,
						'quantity'   => 1,
					),
				)
			)
		);
		$this->assertFalse( $fail->is_eligible() );
	}

	public function test_category_quantity_pass_and_fail(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'        => RuleTypes::CONDITION_CATEGORY_QUANTITY,
					'category_id' => 5,
					'operator'    => '>=',
					'quantity'    => 2.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		);

		$pass = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context(
				null,
				50.0,
				array(
					array(
						'product_id' => 99,
						'quantity'   => 2,
						'categories' => array( 5 ),
					),
				)
			)
		);
		$this->assertTrue( $pass->is_eligible() );

		$fail = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( null, 50.0, array() )
		);
		$this->assertFalse( $fail->is_eligible() );
	}

	public function test_multiple_conditions_must_all_pass(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 50.0,
				),
				array( 'type' => RuleTypes::CONDITION_LOGGED_IN ),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);

		$eligible = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( 42, 100.0 )
		);
		$this->assertTrue( $eligible->is_eligible() );

		$guest = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);
		$this->assertFalse( $guest->is_eligible() );
	}

	public function test_logged_in_and_first_order_conditions(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array( 'type' => RuleTypes::CONDITION_LOGGED_IN ),
				array( 'type' => RuleTypes::CONDITION_FIRST_ORDER ),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		);

		$pass = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( 7, 10.0, array(), array( 'has_previous_orders' => false ) )
		);
		$this->assertTrue( $pass->is_eligible() );

		$missing_meta = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( 7, 10.0 )
		);
		$this->assertFalse( $missing_meta->is_eligible() );
	}
}
