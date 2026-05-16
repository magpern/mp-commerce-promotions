<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionRuleValidatorTest extends TestCase {

	private PromotionRuleValidator $validator;

	protected function setUp(): void {
		$this->validator = new PromotionRuleValidator();
	}

	public function test_valid_active_promotion_has_no_errors_or_warnings(): void {
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

		$issues = $this->validator->validate( $promotion );
		$this->assertSame( array(), $issues );
	}

	public function test_no_conditions_emits_warning(): void {
		$promotion = PromotionTestFixtures::active_promotion( array(), array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 10.0,
			),
		) );

		$levels = $this->levels( $this->validator->validate( $promotion ) );
		$this->assertContains( 'warning', $levels );
	}

	public function test_no_actions_emits_error(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array()
		);

		$levels = $this->levels( $this->validator->validate( $promotion ) );
		$this->assertContains( 'error', $levels );
	}

	public function test_unknown_condition_emits_error(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array( array( 'type' => 'bogus_condition' ) ),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);

		$messages = $this->messages( $this->validator->validate( $promotion ) );
		$this->assertTrue( $this->has_error_containing( $messages, 'Unknown condition type' ) );
	}

	public function test_unknown_action_emits_error(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array( array( 'type' => 'bogus_action' ) )
		);

		$messages = $this->messages( $this->validator->validate( $promotion ) );
		$this->assertTrue( $this->has_error_containing( $messages, 'Unknown action type' ) );
	}

	public function test_invalid_minimum_subtotal_emits_error(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => -5.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);

		$messages = $this->messages( $this->validator->validate( $promotion ) );
		$this->assertTrue( $this->has_error_containing( $messages, 'minimum_subtotal' ) );
	}

	public function test_invalid_product_quantity_operator_emits_error(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'       => RuleTypes::CONDITION_PRODUCT_QUANTITY,
					'product_id' => 1,
					'operator'   => '!=',
					'quantity'   => 1,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);

		$messages = $this->messages( $this->validator->validate( $promotion ) );
		$this->assertTrue( $this->has_error_containing( $messages, 'unsupported operator' ) );
	}

	public function test_invalid_fixed_amount_discount_emits_error(): void {
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
					'amount' => 0.0,
				),
			)
		);

		$messages = $this->messages( $this->validator->validate( $promotion ) );
		$this->assertTrue( $this->has_error_containing( $messages, 'fixed_amount_discount' ) );
	}

	public function test_multiple_actions_emits_warning(): void {
		$promotion = PromotionTestFixtures::active_promotion(
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
				array(
					'type'   => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
					'amount' => 5.0,
				),
			)
		);

		$levels = $this->levels( $this->validator->validate( $promotion ) );
		$this->assertContains( 'warning', $levels );
	}

	public function test_draft_promotion_emits_info(): void {
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

		$levels = $this->levels( $this->validator->validate( $promotion ) );
		$this->assertContains( 'info', $levels );
	}

	public function test_customer_role_valid_and_invalid(): void {
		$valid = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'  => RuleTypes::CONDITION_CUSTOMER_ROLE,
					'roles' => array( 'customer', 'vip' ),
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);
		$this->assertSame( array(), $this->validator->validate( $valid ) );

		$invalid = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'  => RuleTypes::CONDITION_CUSTOMER_ROLE,
					'roles' => array( '' ),
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);
		$this->assertTrue( $this->has_error_containing( $this->messages( $this->validator->validate( $invalid ) ), 'customer_role' ) );
	}

	public function test_billing_country_and_email_domain_validate(): void {
		$valid = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'      => RuleTypes::CONDITION_BILLING_COUNTRY,
					'countries' => array( 'SE' ),
				),
				array(
					'type'    => RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN,
					'domains' => array( 'example.com' ),
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);
		$this->assertSame( array(), $this->validator->validate( $valid ) );

		$invalid = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'      => RuleTypes::CONDITION_BILLING_COUNTRY,
					'countries' => array(),
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);
		$this->assertTrue( $this->has_error_containing( $this->messages( $this->validator->validate( $invalid ) ), 'billing_country' ) );
	}

	public function test_logged_in_and_first_order_types_validate_without_extra_fields(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array( 'type' => RuleTypes::CONDITION_LOGGED_IN ),
				array( 'type' => RuleTypes::CONDITION_FIRST_ORDER ),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);

		$issues = $this->validator->validate( $promotion );
		$this->assertSame( array(), $issues );
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 * @return list<string>
	 */
	private function levels( array $issues ): array {
		return array_column( $issues, 'level' );
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 * @return list<string>
	 */
	private function messages( array $issues ): array {
		return array_column( $issues, 'message' );
	}

	/**
	 * @param list<string> $messages
	 */
	private function has_error_containing( array $messages, string $needle ): bool {
		foreach ( $messages as $message ) {
			if ( strpos( $message, $needle ) !== false ) {
				return true;
			}
		}

		return false;
	}

	public function test_stackable_mode_emits_info(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 0.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		)->with_application_rules( PromotionApplicationMode::STACKABLE, true, null );

		$issues = $this->validator->validate( $promotion );
		$this->assertContains( 'info', $this->levels( $issues ) );
		$this->assertTrue(
			$this->has_error_containing( $this->messages( $issues ), 'Stackable mode is groundwork' )
		);
	}

	public function test_invalid_application_mode_in_domain_prevents_validator_path(): void {
		$this->expectException( \InvalidArgumentException::class );
		Promotion::from_array(
			array(
				'uuid'             => '11111111-1111-4111-8111-111111111111',
				'name'             => 'Bad mode',
				'status'           => PromotionStatus::DRAFT,
				'application_mode' => 'combined',
			)
		);
	}
}
