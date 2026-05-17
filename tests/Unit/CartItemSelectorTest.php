<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\EvaluationContext;
use PHPUnit\Framework\TestCase;

final class CartItemSelectorTest extends TestCase {

	private function context_with_items( array $items ): EvaluationContext {
		return new EvaluationContext( null, 100.0, 'EUR', $items, array() );
	}

	public function test_item_matches_parent_and_variation(): void {
		$item = array(
			'product_id'   => 100,
			'variation_id' => 101,
			'quantity'     => 1.0,
		);

		$this->assertTrue( CartItemSelector::item_matches_product_or_variation( $item, array( 100 ), array() ) );
		$this->assertTrue( CartItemSelector::item_matches_product_or_variation( $item, array(), array( 101 ) ) );
		$this->assertFalse( CartItemSelector::item_matches_product_or_variation( $item, array( 200 ), array( 202 ) ) );
	}

	public function test_items_matching_variations(): void {
		$items = array(
			array( 'product_id' => 100, 'variation_id' => 101, 'quantity' => 1.0 ),
			array( 'product_id' => 100, 'variation_id' => 0, 'quantity' => 1.0 ),
		);

		$matched = CartItemSelector::items_matching_variations( $items, array( 101 ) );
		$this->assertCount( 1, $matched );
		$this->assertSame( 101, (int) $matched[0]['variation_id'] );
	}

	public function test_filter_out_sale_items(): void {
		$items = array(
			array( 'product_id' => 1, 'quantity' => 1.0, 'on_sale' => true ),
			array( 'product_id' => 2, 'quantity' => 1.0, 'on_sale' => false ),
		);

		$filtered = CartItemSelector::filter_out_sale_items( $items );
		$this->assertCount( 1, $filtered );
		$this->assertSame( 2, (int) $filtered[0]['product_id'] );
	}

	public function test_filter_items_for_promotion_excludes_product_and_category(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'                   => '11111111-1111-4111-8111-111111111111',
				'name'                   => 'Exclude test',
				'status'                 => PromotionStatus::ACTIVE,
				'excluded_product_ids'   => array( 100 ),
				'excluded_category_ids'  => array( 20 ),
			)
		);

		$items = array(
			array( 'product_id' => 100, 'quantity' => 1.0, 'categories' => array( 10 ) ),
			array( 'product_id' => 200, 'quantity' => 1.0, 'categories' => array( 20 ) ),
			array( 'product_id' => 300, 'quantity' => 1.0, 'categories' => array( 10 ) ),
		);

		$filtered = CartItemSelector::filter_items_for_promotion( $items, $promotion );
		$this->assertCount( 1, $filtered );
		$this->assertSame( 300, (int) $filtered[0]['product_id'] );
	}
}
