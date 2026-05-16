<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\EvaluationContext;
use PHPUnit\Framework\TestCase;

final class CartItemSelectorTest extends TestCase {

	public function test_items_matching_products(): void {
		$context = new EvaluationContext(
			null,
			100.0,
			'USD',
			array(
				array(
					'product_id'    => 100,
					'quantity'      => 1.0,
					'line_subtotal' => 50.0,
					'unit_price'    => 50.0,
					'categories'    => array( 10 ),
				),
				array(
					'product_id'    => 200,
					'quantity'      => 1.0,
					'line_subtotal' => 20.0,
					'unit_price'    => 20.0,
					'categories'    => array( 20 ),
				),
			),
			array()
		);

		$matched = CartItemSelector::items_matching_products( $context, array( 100, 101 ) );

		$this->assertCount( 1, $matched );
		$this->assertSame( 100, $matched[0]['product_id'] );
	}

	public function test_items_matching_categories(): void {
		$context = new EvaluationContext(
			null,
			100.0,
			'USD',
			array(
				array(
					'product_id' => 100,
					'quantity'   => 1.0,
					'categories' => array( 10, 11 ),
				),
				array(
					'product_id' => 200,
					'quantity'   => 1.0,
					'categories' => array( 20 ),
				),
			),
			array()
		);

		$matched = CartItemSelector::items_matching_categories( $context, array( 10 ) );

		$this->assertCount( 1, $matched );
		$this->assertSame( 100, $matched[0]['product_id'] );
	}

	public function test_expand_quantities_creates_unit_entries(): void {
		$items = array(
			array(
				'product_id'    => 101,
				'variation_id'  => null,
				'quantity'      => 2.0,
				'line_subtotal' => 60.0,
				'unit_price'    => 30.0,
				'categories'    => array( 10 ),
			),
		);

		$units = CartItemSelector::expand_quantities( $items );

		$this->assertCount( 2, $units );
		$this->assertSame( 30.0, $units[0]['unit_price'] );
		$this->assertSame( 30.0, $units[1]['unit_price'] );
		$this->assertSame( 101, $units[0]['product_id'] );
		$this->assertIsArray( $units[0]['source_item'] );
	}

	public function test_resolve_unit_price_from_line_subtotal(): void {
		$price = CartItemSelector::resolve_unit_price(
			array(
				'quantity'      => 2.0,
				'line_subtotal' => 60.0,
			)
		);

		$this->assertSame( 30.0, $price );
	}
}
