<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionScheduleAnalyzer;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionScheduleAnalyzerTest extends TestCase {

	public function test_overlapping_campaign_window_for_subject(): void {
		$subject = PromotionTestFixtures::active_promotion_with_id(
			1,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) )
		)->with_date_window( null, gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ) );

		$peer = PromotionTestFixtures::active_promotion_with_id(
			2,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) )
		)->with_date_window( null, gmdate( 'Y-m-d H:i:s', strtotime( '+14 days' ) ) );

		$analyzer = new PromotionScheduleAnalyzer();
		$rows     = $analyzer->analyze( array( $subject, $peer ), $subject );

		$codes = array_column( $rows, 'code' );
		$this->assertContains( PromotionScheduleAnalyzer::CODE_OVERLAPPING_CAMPAIGN_WINDOW, $codes );
	}

	public function test_no_issues_when_single_promotion(): void {
		$subject = PromotionTestFixtures::active_promotion_with_id(
			1,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) )
		);

		$analyzer = new PromotionScheduleAnalyzer();
		$this->assertSame( array(), $analyzer->analyze( array( $subject ), $subject ) );
	}
}
