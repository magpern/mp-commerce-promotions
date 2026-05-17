<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionConflictAnalyzer;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionConflictAnalyzerTest extends TestCase {

	private PromotionConflictAnalyzer $analyzer;

	protected function setUp(): void {
		$this->analyzer = new PromotionConflictAnalyzer();
	}

	public function test_mutual_exclusion_detected(): void {
		$a = PromotionTestFixtures::active_promotion_with_id(
			10,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) )
		)->with_excluded_promotion_ids( array( 20 ) );

		$b = PromotionTestFixtures::active_promotion_with_id(
			20,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) )
		)->with_excluded_promotion_ids( array( 10 ) );

		$conflicts = $this->analyzer->analyze( array( $a, $b ) );
		$types     = array_column( $conflicts, 'type' );

		$this->assertContains( PromotionConflictAnalyzer::TYPE_MUTUAL_EXCLUSION, $types );
	}

	public function test_free_shipping_overlap_detected(): void {
		$a = PromotionTestFixtures::active_promotion_with_id(
			1,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 10 ) ),
			array( array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ) )
		);
		$b = PromotionTestFixtures::active_promotion_with_id(
			2,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 20 ) ),
			array( array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ) )
		);

		$conflicts = $this->analyzer->analyze( array( $a, $b ) );
		$types     = array_column( $conflicts, 'type' );

		$this->assertContains( PromotionConflictAnalyzer::TYPE_FREE_SHIPPING_OVERLAP, $types );
	}

	public function test_gift_overlap_detected(): void {
		$a = PromotionTestFixtures::active_promotion_with_id(
			1,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_FREE_GIFT_PRODUCT, 'product_id' => 99, 'quantity' => 1 ) )
		);
		$b = PromotionTestFixtures::active_promotion_with_id(
			2,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_FREE_GIFT_PRODUCT, 'product_id' => 99, 'quantity' => 1 ) )
		);

		$conflicts = $this->analyzer->analyze( array( $a, $b ) );
		$types     = array_column( $conflicts, 'type' );

		$this->assertContains( PromotionConflictAnalyzer::TYPE_GIFT_OVERLAP, $types );
	}

	public function test_exclusive_vs_stackable_warning(): void {
		$exclusive = PromotionTestFixtures::active_promotion_with_id(
			1,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) )
		)->with_application_rules( PromotionApplicationMode::EXCLUSIVE, true, null );

		$stackable = PromotionTestFixtures::active_promotion_with_id(
			2,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) )
		)->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$conflicts = $this->analyzer->analyze( array( $exclusive, $stackable ) );
		$types     = array_column( $conflicts, 'type' );

		$this->assertContains( PromotionConflictAnalyzer::TYPE_EXCLUSIVE_VS_STACKABLE, $types );
	}
}
