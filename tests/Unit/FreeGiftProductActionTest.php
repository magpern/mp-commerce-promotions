<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Action\FreeGiftProductAction;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;
use PHPUnit\Framework\TestCase;

final class FreeGiftProductActionTest extends TestCase {

	public function test_valid_config_and_preview_payload(): void {
		$action = FreeGiftProductAction::from_config(
			array(
				'type'         => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
				'product_id'   => 123,
				'variation_id' => 456,
				'quantity'     => 2,
			)
		);

		$this->assertSame( 123, $action->get_product_id() );
		$this->assertSame( 456, $action->get_variation_id() );
		$this->assertSame( 2, $action->get_quantity() );

		$context = new EvaluationContext( null, 50.0, 'USD', array(), array() );
		$result  = $action->preview( $context );

		$this->assertSame( RuleTypes::ACTION_FREE_GIFT_PRODUCT, $result->get_type() );
		$this->assertSame(
			array(
				'product_id'   => 123,
				'variation_id' => 456,
				'quantity'     => 2,
			),
			$result->get_payload()
		);
	}

	public function test_preview_omits_variation_when_not_set(): void {
		$action = FreeGiftProductAction::from_config(
			array(
				'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
				'product_id' => 10,
				'quantity'   => 1,
			)
		);

		$result = $action->preview( new EvaluationContext( null, 1.0, 'USD', array(), array() ) );

		$this->assertSame(
			array(
				'product_id' => 10,
				'quantity'   => 1,
			),
			$result->get_payload()
		);
	}

	public function test_missing_product_id_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		FreeGiftProductAction::from_config(
			array(
				'type'     => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
				'quantity' => 1,
			)
		);
	}

	public function test_invalid_product_id_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		FreeGiftProductAction::from_config(
			array(
				'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
				'product_id' => 0,
				'quantity'   => 1,
			)
		);
	}

	public function test_invalid_quantity_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		FreeGiftProductAction::from_config(
			array(
				'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
				'product_id' => 5,
				'quantity'   => 0,
			)
		);
	}
}
