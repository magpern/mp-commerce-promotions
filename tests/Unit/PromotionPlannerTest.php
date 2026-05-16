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

	public function test_selected_promotion_excludes_later_eligible_promotion(): void {
		$cond = array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1.0,
			),
		);
		$act  = array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 10.0,
			),
		);

		$a = PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null )
			->with_excluded_promotion_ids( array( 2 ) );
		$b = PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );
		$c = PromotionTestFixtures::active_promotion_with_id( 3, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$plan = $this->planner->plan(
			array( $a, $b, $c ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$selected_ids = array_map(
			static fn ( $d ) => $d->get_promotion_id(),
			$plan->get_selected_decisions()
		);
		$this->assertSame( array( 1, 3 ), $selected_ids );
		$this->assertFalse( $plan->get_decisions()[1]->is_selected() );
		$this->assertSame(
			PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED,
			$plan->get_decisions()[1]->get_skipped_reason()
		);
	}

	public function test_exclusion_does_not_block_promotion_evaluated_before_excluder(): void {
		$cond = array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1.0,
			),
		);
		$act  = array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 10.0,
			),
		);

		$b = PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );
		$a = PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null )
			->with_excluded_promotion_ids( array( 2 ) );

		$plan = $this->planner->plan(
			array( $b, $a ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$selected_ids = array_map(
			static fn ( $d ) => $d->get_promotion_id(),
			$plan->get_selected_decisions()
		);
		$this->assertSame( array( 2, 1 ), $selected_ids );
	}

	public function test_exclusive_still_blocks_after_exclusion_plan(): void {
		$cond = array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1.0,
			),
		);
		$act  = array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 10.0,
			),
		);

		$exclusive = PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act )
			->with_application_rules( PromotionApplicationMode::EXCLUSIVE, true, null );
		$second = PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act );

		$plan = $this->planner->plan(
			array( $exclusive, $second ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertCount( 1, $plan->get_selected_decisions() );
		$this->assertSame(
			PromotionEvaluationDecision::REASON_BLOCKED_EXCLUSIVE,
			$plan->get_decisions()[1]->get_skipped_reason()
		);
	}

	public function test_max_applications_one_selects_first_stackable_only(): void {
		$cond = array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1.0,
			),
		);
		$act  = array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 10.0,
			),
		);

		$first  = PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, 1 );
		$second = PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );
		$third  = PromotionTestFixtures::active_promotion_with_id( 3, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$plan = $this->planner->plan(
			array( $first, $second, $third ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertCount( 1, $plan->get_selected_decisions() );
		$this->assertSame(
			PromotionEvaluationDecision::REASON_MAX_APPLICATIONS_REACHED,
			$plan->get_decisions()[1]->get_skipped_reason()
		);
		$meta = $plan->get_decisions()[1]->get_metadata();
		$this->assertSame( 1, $meta['max_applications_limit'] ?? null );
		$this->assertSame( 1, $meta['selected_count'] ?? null );
	}

	public function test_max_applications_two_selects_first_two_skips_third(): void {
		$cond = array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1.0,
			),
		);
		$act  = array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 10.0,
			),
		);

		$first  = PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, 2 );
		$second = PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );
		$third  = PromotionTestFixtures::active_promotion_with_id( 3, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$plan = $this->planner->plan(
			array( $first, $second, $third ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertCount( 2, $plan->get_selected_decisions() );
		$this->assertSame(
			PromotionEvaluationDecision::REASON_MAX_APPLICATIONS_REACHED,
			$plan->get_decisions()[2]->get_skipped_reason()
		);
	}

	public function test_null_max_applications_selects_all_eligible_stackable(): void {
		$cond = array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1.0,
			),
		);
		$act  = array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 10.0,
			),
		);

		$promos = array();
		for ( $i = 1; $i <= 3; ++$i ) {
			$promos[] = PromotionTestFixtures::active_promotion_with_id( $i, $cond, $act )
				->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );
		}

		$plan = $this->planner->plan(
			$promos,
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertCount( 3, $plan->get_selected_decisions() );
	}

	public function test_exclusion_skips_before_max_applications_cap_fills_remaining_slot(): void {
		$cond = array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1.0,
			),
		);
		$act  = array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 10.0,
			),
		);

		$a = PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, 2 )
			->with_excluded_promotion_ids( array( 2 ) );
		$b = PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );
		$c = PromotionTestFixtures::active_promotion_with_id( 3, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$plan = $this->planner->plan(
			array( $a, $b, $c ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$selected_ids = array_map(
			static fn ( $d ) => $d->get_promotion_id(),
			$plan->get_selected_decisions()
		);
		$this->assertSame( array( 1, 3 ), $selected_ids );
		$this->assertSame(
			PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED,
			$plan->get_decisions()[1]->get_skipped_reason()
		);
	}

	public function test_exclusive_stops_despite_high_max_applications(): void {
		$cond = array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1.0,
			),
		);
		$act  = array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 10.0,
			),
		);

		$exclusive = PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act )
			->with_application_rules( PromotionApplicationMode::EXCLUSIVE, true, 5 );
		$second = PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act )
			->with_application_rules( PromotionApplicationMode::STACKABLE, false, null );

		$plan = $this->planner->plan(
			array( $exclusive, $second ),
			PromotionTestFixtures::cart_context( null, 100.0 )
		);

		$this->assertCount( 1, $plan->get_selected_decisions() );
		$this->assertSame(
			PromotionEvaluationDecision::REASON_BLOCKED_EXCLUSIVE,
			$plan->get_decisions()[1]->get_skipped_reason()
		);
	}
}
