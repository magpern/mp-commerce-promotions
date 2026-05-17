<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\PromotionPlanExplainer;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionOrchestrationPlannerTest extends TestCase {

	public function test_orchestration_group_blocks_second_promotion(): void {
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) );

		$first = PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );
		$data  = $first->to_array();
		$data['orchestration_group'] = 'welcome';
		$first = Promotion::from_array( $data );

		$second = PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );
		$data   = $second->to_array();
		$data['orchestration_group'] = 'welcome';
		$second = Promotion::from_array( $data );

		$plan = ( new PromotionPlanner() )->plan(
			array( $first, $second ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$metrics = $plan->get_metrics();
		$this->assertSame( 1, $metrics['selected_count'] );
		$this->assertSame( 1, $metrics['blocked_by_group_count'] );

		$explanation = PromotionPlanExplainer::explain( $plan );
		$this->assertNotEmpty( $explanation['orchestration_group_blocked'] );

		$found = false;
		foreach ( $explanation['skipped'] as $row ) {
			if ( ( $row['reason_code'] ?? '' ) === PromotionEvaluationDecision::REASON_ORCHESTRATION_GROUP_BLOCKED ) {
				$found = true;
				$this->assertSame( 1, $row['winning_promotion_id'] ?? null );
			}
		}
		$this->assertTrue( $found );
	}
}
