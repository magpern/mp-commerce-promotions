<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionPlannerTest extends TestCase {

	private PromotionPlanner $planner;

	protected function setUp(): void {
		$this->planner = new PromotionPlanner();
	}

	public function test_exclusive_first_eligible_selected_later_skipped(): void {
		$first = PromotionTestFixtures::active_promotion(
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
		)->with_application_rules( PromotionApplicationMode::EXCLUSIVE, true, null );

		$second = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		);

		$plan = $this->planner->plan(
			array( $first, $second ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$selected = $plan->get_selected_decisions();
		$this->assertCount( 1, $selected );
		$this->assertSame( PromotionApplicationMode::EXCLUSIVE, $selected[0]->get_promotion()->get_application_mode() );

		$decisions = $plan->get_decisions();
		$this->assertCount( 2, $decisions );
		$this->assertFalse( $decisions[1]->is_selected() );
		$this->assertSame(
			PromotionEvaluationDecision::REASON_BLOCKED_EXCLUSIVE,
			$decisions[1]->get_skipped_reason()
		);
	}

	public function test_ineligible_first_eligible_second_selected(): void {
		$first = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 500.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 10.0,
				),
			)
		);

		$second = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		);

		$plan = $this->planner->plan(
			array( $first, $second ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertCount( 1, $plan->get_selected_decisions() );
		$this->assertSame(
			PromotionEvaluationDecision::REASON_NOT_ELIGIBLE,
			$plan->get_decisions()[0]->get_skipped_reason()
		);
		$this->assertTrue( $plan->get_decisions()[1]->is_selected() );
	}

	public function test_stackable_without_stop_selects_both_in_plan(): void {
		$first = PromotionTestFixtures::active_promotion(
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
		)->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$second = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		)->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$plan = $this->planner->plan(
			array( $first, $second ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertCount( 2, $plan->get_selected_decisions() );
	}

	public function test_exclusive_stops_after_selection_with_stopped_processing_reason(): void {
		$first = PromotionTestFixtures::active_promotion(
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
		)->with_application_rules( PromotionApplicationMode::STACKABLE, true, null );

		$second = PromotionTestFixtures::active_promotion(
			array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1.0,
				),
			),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			)
		);

		$plan = $this->planner->plan(
			array( $first, $second ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertCount( 1, $plan->get_selected_decisions() );
		$this->assertSame(
			PromotionEvaluationDecision::REASON_STOPPED_PROCESSING,
			$plan->get_decisions()[1]->get_skipped_reason()
		);
	}
}
