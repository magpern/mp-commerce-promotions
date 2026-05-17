<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\EligibleCartScope;
use PHPUnit\Framework\TestCase;

final class EligibleCartScopeTest extends TestCase {

	private function sample_items(): array {
		return array(
			array(
				'product_id'    => 100,
				'variation_id'  => 101,
				'quantity'      => 2.0,
				'line_subtotal' => 60.0,
				'categories'    => array( 10 ),
				'on_sale'       => false,
			),
			array(
				'product_id'    => 200,
				'quantity'      => 1.0,
				'line_subtotal' => 40.0,
				'categories'    => array( 20 ),
				'on_sale'       => true,
			),
			array(
				'product_id'    => 300,
				'quantity'      => 1.0,
				'line_subtotal' => 15.0,
				'categories'    => array( 10 ),
				'on_sale'       => false,
			),
		);
	}

	public function test_filter_items_by_category_and_sale_exclusion(): void {
		$filtered = EligibleCartScope::filter_items(
			$this->sample_items(),
			array(),
			array(),
			array( 10 ),
			array(),
			array(),
			true
		);

		$this->assertCount( 2, $filtered );
		$this->assertSame( 75.0, EligibleCartScope::subtotal( $filtered ) );
	}

	public function test_subtotal_falls_back_to_unit_price_times_quantity(): void {
		$items = array(
			array(
				'product_id' => 1,
				'quantity'   => 2.0,
				'unit_price' => 12.5,
			),
		);

		$this->assertSame( 25.0, EligibleCartScope::subtotal( $items ) );
	}

	public function test_cheapest_units_returns_lowest_prices(): void {
		$items = array(
			array( 'product_id' => 1, 'quantity' => 1.0, 'unit_price' => 30.0 ),
			array( 'product_id' => 2, 'quantity' => 1.0, 'unit_price' => 10.0 ),
			array( 'product_id' => 3, 'quantity' => 1.0, 'unit_price' => 20.0 ),
		);

		$cheapest = EligibleCartScope::cheapest_units( $items, 2 );
		$this->assertCount( 2, $cheapest );
		$this->assertSame( 10.0, $cheapest[0]['unit_price'] );
		$this->assertSame( 20.0, $cheapest[1]['unit_price'] );
	}

	public function test_matching_product_and_category_ids(): void {
		$items = $this->sample_items();

		$this->assertContains( 101, EligibleCartScope::matching_product_ids( $items, array( 100 ), array( 101 ) ) );
		$this->assertContains( 10, EligibleCartScope::matching_category_ids( $items, array( 10 ) ) );
	}
}
