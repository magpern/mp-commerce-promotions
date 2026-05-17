<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionRuleValidatorEconomicsTest extends TestCase {

	private PromotionRuleValidator $validator;

	protected function setUp(): void {
		$this->validator = new PromotionRuleValidator();
	}

	public function test_budget_without_currency_warning(): void {
		$promotion = PromotionTestFixtures::active_promotion_with_id(
			1,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) )
		)->with_budget( 100.0, 0.0, null );

		$issues = $this->validator->validate_with_catalog( $promotion, array( $promotion ) );
		$this->assertTrue( $this->has_message_containing( $issues, 'Budget amount is set without a budget currency' ) );
	}

	public function test_active_past_end_date_warning(): void {
		$past = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$promotion = PromotionTestFixtures::active_promotion_with_id(
			2,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) )
		)->with_date_window( null, $past );

		$issues = $this->validator->validate_with_catalog( $promotion, array( $promotion ) );
		$this->assertTrue( $this->has_message_containing( $issues, 'end date is in the past' ) );
	}

	public function test_no_end_date_info(): void {
		$promotion = PromotionTestFixtures::active_promotion_with_id(
			3,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) )
		)->with_date_window( null, null );

		$issues = $this->validator->validate_with_catalog( $promotion, array( $promotion ) );
		$this->assertTrue( $this->has_message_containing( $issues, 'No end date is configured' ) );
	}

	public function test_stackable_overlap_info(): void {
		$subject = PromotionTestFixtures::active_promotion_with_id(
			10,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) )
		)->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$peer = PromotionTestFixtures::active_promotion_with_id(
			11,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) )
		)->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$issues = $this->validator->validate_with_catalog( $subject, array( $subject, $peer ) );
		$this->assertTrue( $this->has_message_containing( $issues, 'overlaps' ) );
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function has_message_containing( array $issues, string $needle ): bool {
		foreach ( $issues as $issue ) {
			if ( isset( $issue['message'] ) && str_contains( (string) $issue['message'], $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
