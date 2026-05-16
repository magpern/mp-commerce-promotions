<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Action\FreeShippingAction;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;
use PHPUnit\Framework\TestCase;

final class FreeShippingActionTest extends TestCase {

	public function test_preview_returns_free_shipping_flag(): void {
		$action  = new FreeShippingAction();
		$context = new EvaluationContext( null, 100.0, 'USD', array(), array() );
		$result  = $action->preview( $context );

		$this->assertSame( RuleTypes::ACTION_FREE_SHIPPING, $result->get_type() );
		$this->assertTrue( $result->get_payload()['free_shipping'] );
	}
}
