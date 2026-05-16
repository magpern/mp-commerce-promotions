<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Action\ActionTrace;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\EvaluationResult;
use PHPUnit\Framework\TestCase;

final class EvaluationResultTest extends TestCase {

	public function test_eligible_factory_without_traces(): void {
		$result = EvaluationResult::eligible(
			array( array( 'type' => 'percentage_discount' ) ),
			array( 'ok' )
		);

		$this->assertTrue( $result->is_eligible() );
		$this->assertSame( array( 'ok' ), $result->get_messages() );
		$this->assertCount( 1, $result->get_action_results() );
		$this->assertSame( array(), $result->get_condition_traces() );
		$this->assertSame( array(), $result->get_action_traces() );
	}

	public function test_ineligible_factory_without_traces(): void {
		$result = EvaluationResult::ineligible( array( 'failed' ) );

		$this->assertFalse( $result->is_eligible() );
		$this->assertSame( array( 'failed' ), $result->get_messages() );
		$this->assertSame( array(), $result->get_action_results() );
	}

	public function test_to_array_includes_traces(): void {
		$condition_trace = new ConditionTrace(
			'minimum_subtotal',
			false,
			'too low',
			ConditionTrace::REASON_CART_VALUE_TOO_LOW,
			array( 'amount' => 100 ),
			array( 'cart_subtotal' => 50.0 )
		);
		$action_trace = new ActionTrace(
			'percentage_discount',
			false,
			'not reached',
			ActionTrace::REASON_NOT_REACHED,
			array( 'percentage' => 10 ),
			array()
		);

		$result = EvaluationResult::ineligible(
			array( 'ineligible' ),
			array( $condition_trace ),
			array( $action_trace )
		);

		$array = $result->to_array();
		$this->assertFalse( $array['eligible'] );
		$this->assertArrayHasKey( 'condition_traces', $array );
		$this->assertArrayHasKey( 'action_traces', $array );
		$this->assertSame( 'minimum_subtotal', $array['condition_traces'][0]['type'] );
		$this->assertSame( ConditionTrace::REASON_CART_VALUE_TOO_LOW, $array['condition_traces'][0]['reason_code'] );
		$this->assertSame( ActionTrace::REASON_NOT_REACHED, $array['action_traces'][0]['reason_code'] );
	}
}
