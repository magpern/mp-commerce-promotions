<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Action\ActionTrace;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
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

	public function test_customer_role_condition(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'  => RuleTypes::CONDITION_CUSTOMER_ROLE,
					'roles' => array( 'customer' ),
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
				1,
				10.0,
				array(),
				array( 'customer_roles' => array( 'customer' ) )
			)
		);
		$this->assertTrue( $pass->is_eligible() );

		$fail = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( 1, 10.0 )
		);
		$this->assertFalse( $fail->is_eligible() );
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

	public function test_billing_country_and_email_domain_conditions(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'      => RuleTypes::CONDITION_BILLING_COUNTRY,
					'countries' => array( 'SE', 'NO' ),
				),
				array(
					'type'    => RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN,
					'domains' => array( 'example.com' ),
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		);

		$meta = array(
			'billing_country' => 'SE',
			'customer_email'  => 'test@example.com',
		);

		$this->assertTrue(
			$this->evaluator->evaluate(
				$promotion,
				PromotionTestFixtures::cart_context( null, 10.0, array(), $meta )
			)->is_eligible()
		);

		$bad_country = $meta;
		$bad_country['billing_country'] = 'US';
		$this->assertFalse(
			$this->evaluator->evaluate(
				$promotion,
				PromotionTestFixtures::cart_context( null, 10.0, array(), $bad_country )
			)->is_eligible()
		);

		$bad_email = $meta;
		$bad_email['customer_email'] = 'test@other.org';
		$this->assertFalse(
			$this->evaluator->evaluate(
				$promotion,
				PromotionTestFixtures::cart_context( null, 10.0, array(), $bad_email )
			)->is_eligible()
		);
	}

	public function test_failed_minimum_subtotal_includes_cart_value_trace(): void {
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
		$trace = $this->find_condition_trace( $result->get_condition_traces(), RuleTypes::CONDITION_MINIMUM_SUBTOTAL );
		$this->assertNotNull( $trace );
		$this->assertFalse( $trace['passed'] );
		$this->assertSame( ConditionTrace::REASON_CART_VALUE_TOO_LOW, $trace['reason_code'] );
		$this->assertSame( 50.0, $trace['observed']['cart_subtotal'] );
		$this->assertSame( ActionTrace::REASON_NOT_REACHED, $result->get_action_traces()[0]['reason_code'] );
	}

	public function test_unknown_condition_includes_condition_unknown_trace(): void {
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
		$trace = $this->find_condition_trace( $result->get_condition_traces(), 'unknown_condition_type' );
		$this->assertNotNull( $trace );
		$this->assertSame( ConditionTrace::REASON_UNKNOWN, $trace['reason_code'] );
	}

	public function test_unknown_action_includes_action_unknown_trace(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 0.0,
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
		$this->assertCount( 1, $result->get_action_traces() );
		$this->assertSame( ConditionTrace::REASON_PASSED, $result->get_condition_traces()[0]['reason_code'] );
		$this->assertSame( ActionTrace::REASON_UNKNOWN, $result->get_action_traces()[0]['reason_code'] );
	}

	public function test_successful_evaluation_includes_passed_condition_and_selected_action_trace(): void {
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

		$result = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertTrue( $result->is_eligible() );
		$this->assertSame( ConditionTrace::REASON_PASSED, $result->get_condition_traces()[0]['reason_code'] );
		$this->assertTrue( $result->get_condition_traces()[0]['passed'] );
		$this->assertSame( ActionTrace::REASON_SELECTED, $result->get_action_traces()[0]['reason_code'] );
		$this->assertTrue( $result->get_action_traces()[0]['selected'] );
	}

	public function test_customer_role_failure_includes_role_not_matched_trace(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'  => RuleTypes::CONDITION_CUSTOMER_ROLE,
					'roles' => array( 'customer' ),
				),
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
			PromotionTestFixtures::cart_context(
				1,
				10.0,
				array(),
				array( 'customer_roles' => array( 'subscriber' ) )
			)
		);

		$trace = $this->find_condition_trace( $result->get_condition_traces(), RuleTypes::CONDITION_CUSTOMER_ROLE );
		$this->assertNotNull( $trace );
		$this->assertSame( ConditionTrace::REASON_ROLE_NOT_MATCHED, $trace['reason_code'] );
	}

	public function test_billing_country_failure_includes_country_not_matched_trace(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'      => RuleTypes::CONDITION_BILLING_COUNTRY,
					'countries' => array( 'SE' ),
				),
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
			PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'billing_country' => 'NO' ) )
		);

		$trace = $this->find_condition_trace( $result->get_condition_traces(), RuleTypes::CONDITION_BILLING_COUNTRY );
		$this->assertNotNull( $trace );
		$this->assertSame( ConditionTrace::REASON_COUNTRY_NOT_MATCHED, $trace['reason_code'] );
	}

	public function test_free_shipping_action_preview_eligible(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ),
			)
		);

		$result = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( null, 50.0 )
		);

		$this->assertTrue( $result->is_eligible() );
		$this->assertSame( RuleTypes::ACTION_FREE_SHIPPING, $result->get_action_results()[0]['type'] );
		$this->assertTrue( $result->get_action_results()[0]['payload']['free_shipping'] );
	}

	public function test_customer_redemption_count_passes_and_fails(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'     => RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT,
					'operator' => '<',
					'count'    => 1,
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
			PromotionTestFixtures::cart_context( 1, 10.0, array(), array( 'customer_redemption_count' => 0 ) )
		);
		$this->assertTrue( $pass->is_eligible() );

		$fail = $this->evaluator->evaluate(
			$promotion,
			PromotionTestFixtures::cart_context( 1, 10.0, array(), array( 'customer_redemption_count' => 2 ) )
		);
		$this->assertFalse( $fail->is_eligible() );
		$trace = $this->find_condition_trace( $fail->get_condition_traces(), RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT );
		$this->assertNotNull( $trace );
		$this->assertSame( ConditionTrace::REASON_REDEMPTION_COUNT_NOT_MET, $trace['reason_code'] );
	}

	public function test_email_domain_failure_includes_email_domain_not_matched_trace(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'    => RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN,
					'domains' => array( 'example.com' ),
				),
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
			PromotionTestFixtures::cart_context( null, 10.0, array(), array( 'customer_email' => 'a@other.org' ) )
		);

		$trace = $this->find_condition_trace( $result->get_condition_traces(), RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN );
		$this->assertNotNull( $trace );
		$this->assertSame( ConditionTrace::REASON_EMAIL_DOMAIN, $trace['reason_code'] );
	}

	/**
	 * @param list<array<string, mixed>> $traces
	 * @return array<string, mixed>|null
	 */
	private function find_condition_trace( array $traces, string $type ): ?array {
		foreach ( $traces as $trace ) {
			if ( isset( $trace['type'] ) && $trace['type'] === $type ) {
				return $trace;
			}
		}

		return null;
	}
}
