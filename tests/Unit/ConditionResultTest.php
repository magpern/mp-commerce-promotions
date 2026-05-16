<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Condition\ConditionResult;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use PHPUnit\Framework\TestCase;

final class ConditionResultTest extends TestCase {

	public function test_pass_and_fail_defaults_preserve_backward_compatibility(): void {
		$pass = ConditionResult::pass();
		$this->assertTrue( $pass->passed() );
		$this->assertNull( $pass->get_message() );
		$this->assertSame( ConditionTrace::REASON_PASSED, $pass->get_reason_code() );
		$this->assertSame( array(), $pass->get_observed() );

		$fail = ConditionResult::fail( 'nope' );
		$this->assertFalse( $fail->passed() );
		$this->assertSame( 'nope', $fail->get_message() );
		$this->assertSame( ConditionTrace::REASON_FAILED, $fail->get_reason_code() );
	}

	public function test_reason_code_and_observed_when_provided(): void {
		$result = ConditionResult::fail(
			'too low',
			ConditionTrace::REASON_CART_VALUE_TOO_LOW,
			array( 'cart_subtotal' => 10.0 )
		);

		$this->assertSame( ConditionTrace::REASON_CART_VALUE_TOO_LOW, $result->get_reason_code() );
		$this->assertSame( 10.0, $result->get_observed()['cart_subtotal'] );
	}
}
