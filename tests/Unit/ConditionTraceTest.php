<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConditionTraceTest extends TestCase {

	public function test_to_array_shape(): void {
		$trace = new ConditionTrace(
			'logged_in',
			true,
			null,
			ConditionTrace::REASON_PASSED,
			array( 'type' => 'logged_in' ),
			array( 'customer_id' => 1 )
		);

		$array = $trace->to_array();
		$this->assertSame( 'logged_in', $array['type'] );
		$this->assertTrue( $array['passed'] );
		$this->assertSame( ConditionTrace::REASON_PASSED, $array['reason_code'] );
	}

	public function test_empty_type_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new ConditionTrace( '', true, null, ConditionTrace::REASON_PASSED, array(), array() );
	}
}
