<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionConflictAnalyzer;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use PHPUnit\Framework\TestCase;

final class PromotionConflictAnalyzerOrchestrationTest extends TestCase {

	public function test_orchestration_congestion_when_overlapping_schedules(): void {
		$cond = array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) );
		$act  = array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) );

		$a = PromotionTestFixtures::active_promotion_with_id( 1, $cond, $act );
		$b = PromotionTestFixtures::active_promotion_with_id( 2, $cond, $act );

		$data_a = $a->to_array();
		$data_a['orchestration_group'] = 'flash-sale';
		$data_a['starts_at']           = gmdate( 'Y-m-d H:i:s', time() - 604800 );
		$data_a['ends_at']             = gmdate( 'Y-m-d H:i:s', time() + 604800 );

		$data_b = $b->to_array();
		$data_b['orchestration_group'] = 'flash-sale';
		$data_b['starts_at']           = gmdate( 'Y-m-d H:i:s', time() - 86400 );
		$data_b['ends_at']             = gmdate( 'Y-m-d H:i:s', time() + 86400 );

		$conflicts = ( new PromotionConflictAnalyzer() )->analyze(
			array(
				Promotion::from_array( $data_a ),
				Promotion::from_array( $data_b ),
			)
		);

		$types = array_column( $conflicts, 'type' );
		$this->assertContains( PromotionConflictAnalyzer::TYPE_ORCHESTRATION_CONGESTION, $types );
	}
}
