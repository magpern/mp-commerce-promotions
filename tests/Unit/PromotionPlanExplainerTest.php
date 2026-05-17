<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;
use MP\CommercePromotions\Engine\PromotionPlanExplainer;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionPlanExplainerTest extends TestCase {

	public function test_explain_includes_skipped_exclusion_reason(): void {
		$blocker = PromotionTestFixtures::active_promotion_with_id(
			12,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) )
		)
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null )
			->with_excluded_promotion_ids( array( 15 ) );

		$skipped = PromotionTestFixtures::active_promotion_with_id(
			15,
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) )
		)->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$plan = ( new PromotionPlanner() )->plan(
			array( $blocker, $skipped ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$explanation = PromotionPlanExplainer::explain( $plan );

		$this->assertNotEmpty( $explanation['summary_lines'] );
		$joined = implode( ' ', $explanation['summary_lines'] );
		$this->assertStringContainsString( '15', $joined );
		$this->assertStringContainsString( '12', $joined );

		$skipped_rows = $explanation['skipped'];
		$this->assertNotEmpty( $skipped_rows );
		$found = false;
		foreach ( $skipped_rows as $row ) {
			if ( ( $row['reason_code'] ?? '' ) === PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED ) {
				$found = true;
				$this->assertSame( 12, $row['excluded_by_promotion_id'] ?? null );
			}
		}
		$this->assertTrue( $found );
	}

	public function test_explain_max_applications_skip(): void {
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) );

		$promotions = array(
			PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act )
				->with_application_rules( PromotionApplicationMode::STACKABLE, false, 2 ),
			PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act )
				->with_application_rules( PromotionApplicationMode::STACKABLE, false, null ),
			PromotionTestFixtures::active_promotion_with_id( 3, $cond, $act )
				->with_application_rules( PromotionApplicationMode::STACKABLE, false, null ),
		);

		$plan = ( new PromotionPlanner() )->plan(
			$promotions,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$explanation = PromotionPlanExplainer::explain( $plan );
		$this->assertNotEmpty( $explanation['max_applications'] );

		$found_max = false;
		foreach ( $explanation['skipped'] as $row ) {
			if ( ( $row['reason_code'] ?? '' ) === PromotionEvaluationDecision::REASON_MAX_APPLICATIONS_REACHED ) {
				$found_max = true;
			}
		}
		$this->assertTrue( $found_max );
	}
}
