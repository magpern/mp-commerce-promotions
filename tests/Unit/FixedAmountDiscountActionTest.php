<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;
use PHPUnit\Framework\TestCase;

final class FixedAmountDiscountActionTest extends TestCase {

	public function test_valid_amount_previews_correctly(): void {
		$action  = new FixedAmountDiscountAction( 12.5 );
		$context = new EvaluationContext( null, 100.0, 'USD', array(), array() );
		$result  = $action->preview( $context );

		$this->assertSame( RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, $result->get_type() );
		$this->assertSame( 12.5, $result->get_payload()['amount'] );
	}

	/**
	 * @dataProvider invalid_amount_provider
	 */
	public function test_zero_or_negative_amount_throws( float $amount ): void {
		$this->expectException( InvalidArgumentException::class );
		new FixedAmountDiscountAction( $amount );
	}

	/**
	 * @return array<string, array{0: float}>
	 */
	public function invalid_amount_provider(): array {
		return array(
			'zero'     => array( 0.0 ),
			'negative' => array( -1.0 ),
		);
	}
}
