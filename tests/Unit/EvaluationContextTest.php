<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use PHPUnit\Framework\TestCase;

final class EvaluationContextTest extends TestCase {

	public function test_negative_cart_subtotal_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new EvaluationContext( null, -0.01, 'USD', array(), array() );
	}

	public function test_to_array_from_array_roundtrip(): void {
		$original = new EvaluationContext(
			42,
			99.5,
			'SEK',
			array( array( 'product_id' => 1, 'quantity' => 2 ) ),
			array( 'source' => 'unit-test' )
		);

		$restored = EvaluationContext::from_array( $original->to_array() );

		$this->assertSame( $original->get_customer_id(), $restored->get_customer_id() );
		$this->assertSame( $original->get_cart_subtotal(), $restored->get_cart_subtotal() );
		$this->assertSame( $original->get_currency(), $restored->get_currency() );
		$this->assertSame( $original->get_items(), $restored->get_items() );
		$this->assertSame( $original->get_metadata(), $restored->get_metadata() );
	}

	public function test_null_cart_subtotal_is_allowed(): void {
		$context = new EvaluationContext( null, null, null, array(), array() );
		$this->assertNull( $context->get_cart_subtotal() );
	}
}
