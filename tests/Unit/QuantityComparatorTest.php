<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Condition\QuantityComparator;
use PHPUnit\Framework\TestCase;

final class QuantityComparatorTest extends TestCase {

	/**
	 * @dataProvider supported_operator_provider
	 */
	public function test_supported_operators_compare_correctly(
		string $operator,
		float $actual,
		float $expected,
		bool $result
	): void {
		$this->assertTrue( QuantityComparator::supports( $operator ) );
		$this->assertSame( $result, QuantityComparator::compare( $actual, $operator, $expected ) );
	}

	/**
	 * @return array<string, array{0: string, 1: float, 2: float, 3: bool}>
	 */
	public function supported_operator_provider(): array {
		return array(
			'gte pass' => array( '>=', 5.0, 5.0, true ),
			'gte fail' => array( '>=', 4.0, 5.0, false ),
			'gt pass'  => array( '>', 5.1, 5.0, true ),
			'gt fail'  => array( '>', 5.0, 5.0, false ),
			'eq pass'  => array( '=', 2.0, 2.0, true ),
			'eq fail'  => array( '=', 2.0, 3.0, false ),
			'lte pass' => array( '<=', 3.0, 5.0, true ),
			'lte fail' => array( '<=', 6.0, 5.0, false ),
			'lt pass'  => array( '<', 4.9, 5.0, true ),
			'lt fail'  => array( '<', 5.0, 5.0, false ),
		);
	}

	public function test_unsupported_operator_returns_false(): void {
		$this->assertFalse( QuantityComparator::supports( '!=' ) );
		$this->assertFalse( QuantityComparator::compare( 1.0, '!=', 1.0 ) );
	}
}
