<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;
use PHPUnit\Framework\TestCase;

final class PercentageDiscountActionTest extends TestCase {

	public function test_valid_percentage_previews_correctly(): void {
		$action  = new PercentageDiscountAction( 15.0 );
		$context = new EvaluationContext( null, 100.0, 'USD', array(), array() );
		$result  = $action->preview( $context );

		$this->assertSame( RuleTypes::ACTION_PERCENTAGE_DISCOUNT, $result->get_type() );
		$this->assertSame( 15.0, $result->get_payload()['percentage'] );
	}

	/**
	 * @dataProvider invalid_percentage_provider
	 */
	public function test_invalid_percentage_throws( float $percentage ): void {
		$this->expectException( InvalidArgumentException::class );
		new PercentageDiscountAction( $percentage );
	}

	/**
	 * @return array<string, array{0: float}>
	 */
	public function invalid_percentage_provider(): array {
		return array(
			'zero'        => array( 0.0 ),
			'negative'    => array( -5.0 ),
			'over_hundred' => array( 100.1 ),
		);
	}

	public function test_boundary_percentage_one_hundred_is_valid(): void {
		$action = new PercentageDiscountAction( 100.0 );
		$this->assertSame( 100.0, $action->preview( new EvaluationContext( null, null, null, array(), array() ) )->get_payload()['percentage'] );
	}
}
