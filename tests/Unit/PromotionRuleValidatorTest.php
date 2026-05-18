<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use ReflectionClass;
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

	public function test_cheapest_item_discount_validates_and_rejects_invalid(): void {
		$valid = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
					'scope'               => 'category',
					'category_ids'        => array( 10 ),
					'discount_percentage' => 100,
					'required_quantity'   => 3,
					'discounted_quantity' => 1,
				),
			)
		);
		$this->assertSame( array(), $this->validator->validate( $valid ) );

		$invalid = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
					'scope'               => 'category',
					'category_ids'        => array( 10 ),
					'discount_percentage' => 100,
					'required_quantity'   => 2,
					'discounted_quantity' => 5,
				),
			)
		);
		$this->assertTrue(
			$this->has_error_containing(
				$this->messages( $this->validator->validate( $invalid ) ),
				'cheapest_item_discount'
			)
		);
	}

	public function test_cheapest_item_validator_reports_missing_scope(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
					'category_ids'        => array( 10 ),
					'discount_percentage' => 100,
					'required_quantity'   => 3,
					'discounted_quantity' => 1,
				),
			)
		);

		$this->assertTrue(
			$this->has_error_containing(
				$this->messages( $this->validator->validate( $promotion ) ),
				'missing scope'
			)
		);
	}

	public function test_cheapest_item_validator_reports_missing_category_ids(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
					'scope'               => 'category',
					'discount_percentage' => 100,
					'required_quantity'   => 3,
					'discounted_quantity' => 1,
				),
			)
		);

		$this->assertTrue(
			$this->has_error_containing(
				$this->messages( $this->validator->validate( $promotion ) ),
				'missing category_ids'
			)
		);
	}

	public function test_cheapest_item_validator_reports_invalid_discount_percentage(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
					'scope'               => 'category',
					'category_ids'        => array( 10 ),
					'discount_percentage' => 150,
					'required_quantity'   => 3,
					'discounted_quantity' => 1,
				),
			)
		);

		$this->assertTrue(
			$this->has_error_containing(
				$this->messages( $this->validator->validate( $promotion ) ),
				'discount_percentage must be'
			)
		);
	}

	public function test_cheapest_item_validator_reports_discounted_exceeds_required(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
					'scope'               => 'category',
					'category_ids'        => array( 10 ),
					'discount_percentage' => 100,
					'required_quantity'   => 2,
					'discounted_quantity' => 5,
				),
			)
		);

		$this->assertTrue(
			$this->has_error_containing(
				$this->messages( $this->validator->validate( $promotion ) ),
				'discounted_quantity must be <= required_quantity'
			)
		);
	}

	public function test_free_gift_product_action_validates(): void {
		$valid = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
					'product_id' => 99,
					'quantity'   => 2,
				),
			)
		);

		$this->assertSame( array(), $this->validator->validate( $valid ) );
	}

	public function test_free_gift_product_rejects_invalid_product_id(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
					'product_id' => 0,
					'quantity'   => 1,
				),
			)
		);

		$this->assertTrue(
			$this->has_error_containing(
				$this->messages( $this->validator->validate( $promotion ) ),
				'product_id must be a positive integer'
			)
		);
	}

	public function test_free_shipping_action_validates_without_extra_fields(): void {
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

		$issues = $this->validator->validate( $promotion );
		$critical = array_filter(
			$issues,
			static fn ( array $issue ): bool => ( $issue['level'] ?? '' ) === 'critical'
		);
		$this->assertSame( array(), $critical );
	}

	public function test_customer_redemption_count_validates_operator_and_count(): void {
		$valid = PromotionTestFixtures::active_promotion(
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
					'percentage' => 10.0,
				),
			)
		);
		$this->assertSame( array(), $this->validator->validate( $valid ) );

		$invalid = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'     => RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT,
					'operator' => '!=',
					'count'    => 1,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);
		$this->assertTrue(
			$this->has_error_containing(
				$this->messages( $this->validator->validate( $invalid ) ),
				'customer_redemption_count'
			)
		);
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

	public function test_validates_product_in_cart_condition(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'       => '11111111-1111-4111-8111-111111111111',
				'name'       => 'Product cart',
				'status'     => PromotionStatus::ACTIVE,
				'conditions' => array(
					array(
						'type'        => RuleTypes::CONDITION_PRODUCT_IN_CART,
						'product_ids' => array( 1, 2 ),
					),
				),
				'actions'    => array(
					array(
						'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
						'percentage' => 10,
					),
				),
			)
		);

		$issues = $this->validator->validate( $promotion );
		$this->assertNotContains( 'error', $this->levels( $issues ) );
	}

	public function test_rejects_product_in_cart_without_ids(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'       => '11111111-1111-4111-8111-111111111111',
				'name'       => 'Bad product cart',
				'status'     => PromotionStatus::ACTIVE,
				'conditions' => array(
					array(
						'type'        => RuleTypes::CONDITION_PRODUCT_IN_CART,
						'product_ids' => array(),
					),
				),
				'actions'    => array(
					array(
						'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
						'percentage' => 10,
					),
				),
			)
		);

		$issues = $this->validator->validate( $promotion );
		$this->assertContains( 'error', $this->levels( $issues ) );
	}

	public function test_exclusion_list_emits_info(): void {
		$promotion = Promotion::from_array(
			array(
				'id'                     => 10,
				'uuid'                   => '11111111-1111-4111-8111-111111111111',
				'name'                   => 'Excluder',
				'status'                 => PromotionStatus::ACTIVE,
				'excluded_promotion_ids' => array( 12, 15 ),
			)
		);

		$issues = $this->validator->validate( $promotion );
		$this->assertContains( 'info', $this->levels( $issues ) );
		$this->assertTrue(
			$this->has_error_containing( $this->messages( $issues ), 'evaluated later' )
		);
	}

	public function test_validator_reports_self_exclusion_error(): void {
		$promotion = Promotion::from_array(
			array(
				'id'                     => 10,
				'uuid'                   => '11111111-1111-4111-8111-111111111111',
				'name'                   => 'Self',
				'status'                 => PromotionStatus::ACTIVE,
				'excluded_promotion_ids' => array( 12 ),
			)
		);

		$ref  = new ReflectionClass( $promotion );
		$prop = $ref->getProperty( 'excluded_promotion_ids' );
		$prop->setAccessible( true );
		$prop->setValue( $promotion, array( 10, 12 ) );

		$issues = $this->validator->validate( $promotion );
		$this->assertContains( 'error', $this->levels( $issues ) );
		$this->assertTrue(
			$this->has_error_containing( $this->messages( $issues ), 'cannot exclude itself' )
		);
	}

	public function test_exclusive_with_exclusions_emits_conflict_warning(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) )
		)->with_application_rules( PromotionApplicationMode::EXCLUSIVE, true, null )
			->with_excluded_promotion_ids( array( 99 ) );

		$messages = $this->messages( $this->validator->validate( $promotion ) );
		$this->assertTrue( $this->has_error_containing( $messages, 'Exclusive promotion with excluded_promotion_ids' ) );
	}

	public function test_duplicate_free_gift_actions_emit_warning(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array(
				array( 'type' => RuleTypes::ACTION_FREE_GIFT_PRODUCT, 'product_id' => 42, 'quantity' => 1 ),
				array( 'type' => RuleTypes::ACTION_FREE_GIFT_PRODUCT, 'product_id' => 42, 'quantity' => 1 ),
			)
		);

		$messages = $this->messages( $this->validator->validate( $promotion ) );
		$this->assertTrue( $this->has_error_containing( $messages, 'Duplicate free_gift_product' ) );
	}

	public function test_multiple_free_shipping_actions_emit_warning(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array(
				array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ),
				array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ),
			)
		);

		$messages = $this->messages( $this->validator->validate( $promotion ) );
		$this->assertTrue( $this->has_error_containing( $messages, 'Multiple free_shipping actions' ) );
	}

	public function test_scoped_discount_overlap_emits_info(): void {
		$promotion = PromotionTestFixtures::active_promotion(
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array(
				array(
					'type'          => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage'    => 10,
					'category_ids'  => array( 5 ),
				),
			)
		);

		$messages = $this->messages( $this->validator->validate( $promotion ) );
		$this->assertTrue( $this->has_error_containing( $messages, 'Scoped percentage/fixed discounts' ) );
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
