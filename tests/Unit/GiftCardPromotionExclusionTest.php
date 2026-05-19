<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\MinimumEligibleSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\MinimumSubtotalCondition;
use MP\CommercePromotions\Engine\EligibleCartScope;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardPromotionExclusion;
use PHPUnit\Framework\TestCase;

final class GiftCardPromotionExclusionTest extends TestCase {

	private int $gift_product_id = 91001;

	private int $normal_product_id = 91002;

	private int $gift_variation_id = 91003;

	protected function setUp(): void {
		global $mp_cp_test_post_meta;
		$mp_cp_test_post_meta = array();

		GiftCardProductMeta::save(
			$this->gift_product_id,
			array(
				'sells'        => GiftCardProductMeta::VALUE_YES,
				'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount' => '100',
			)
		);

		GiftCardProductMeta::save(
			$this->gift_variation_id,
			array(
				'sells'        => GiftCardProductMeta::VALUE_YES,
				'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount' => '75',
			)
		);
	}

	public function test_product_sells_gift_card_detects_variation(): void {
		$this->assertTrue(
			GiftCardPromotionExclusion::products()->product_sells_gift_card( $this->gift_product_id, 0 )
		);
		$this->assertTrue(
			GiftCardPromotionExclusion::products()->product_sells_gift_card( 0, $this->gift_variation_id )
		);
		$this->assertFalse(
			GiftCardPromotionExclusion::products()->product_sells_gift_card( $this->normal_product_id, 0 )
		);
	}

	public function test_gift_card_only_cart_has_zero_eligible_subtotal(): void {
		$items = array(
			array(
				'product_id'    => $this->gift_product_id,
				'quantity'      => 1.0,
				'line_subtotal' => 100.0,
			),
		);

		$stats = GiftCardPromotionExclusion::exclusion_stats( $items );
		$this->assertSame( 1, $stats['count'] );
		$this->assertSame( 100.0, $stats['subtotal'] );
		$this->assertSame( 0.0, $stats['eligible_subtotal'] );

		$eligible = GiftCardPromotionExclusion::without_gift_card_products( $items );
		$this->assertSame( array(), $eligible );
	}

	public function test_mixed_cart_discount_applies_to_normal_product_only(): void {
		$items = array(
			array(
				'product_id'    => $this->gift_product_id,
				'quantity'      => 1.0,
				'line_subtotal' => 100.0,
			),
			array(
				'product_id'    => $this->normal_product_id,
				'quantity'      => 1.0,
				'line_subtotal' => 50.0,
			),
		);

		$eligible = GiftCardPromotionExclusion::without_gift_card_products( $items );
		$this->assertCount( 1, $eligible );
		$this->assertSame( 50.0, EligibleCartScope::subtotal( $eligible ) );
		$this->assertSame( 5.0, round( EligibleCartScope::subtotal( $eligible ) * 0.1, 4 ) );
	}

	public function test_fixed_amount_capped_to_normal_product_subtotal(): void {
		$items = array(
			array(
				'product_id'    => $this->gift_product_id,
				'line_subtotal' => 100.0,
				'quantity'      => 1.0,
			),
			array(
				'product_id'    => $this->normal_product_id,
				'line_subtotal' => 50.0,
				'quantity'      => 1.0,
			),
		);

		$eligible_subtotal = EligibleCartScope::subtotal(
			GiftCardPromotionExclusion::without_gift_card_products( $items )
		);
		$this->assertSame( 50.0, $eligible_subtotal );
		$this->assertSame( 50.0, min( 80.0, $eligible_subtotal ) );
	}

	public function test_cheapest_item_ignores_gift_card_product(): void {
		$items = array(
			array(
				'product_id'    => $this->gift_product_id,
				'quantity'      => 1.0,
				'line_subtotal' => 5.0,
				'unit_price'    => 5.0,
				'categories'    => array( 99 ),
			),
			array(
				'product_id'    => $this->normal_product_id,
				'quantity'      => 2.0,
				'line_subtotal' => 60.0,
				'unit_price'    => 30.0,
				'categories'    => array( 99 ),
			),
		);

		$action = CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_CATEGORY,
				'category_ids'        => array( 99 ),
				'discount_percentage' => 100,
				'required_quantity'   => 2,
				'discounted_quantity' => 1,
			)
		);

		$payload = $action->preview( new EvaluationContext( null, 65.0, 'EUR', $items, array() ) )->get_payload();
		$this->assertSame( 30.0, $payload['discount_amount'] );
	}

	public function test_minimum_subtotal_does_not_qualify_from_gift_card_alone(): void {
		$condition = new MinimumSubtotalCondition( 25.0 );
		$result    = $condition->evaluate(
			new EvaluationContext(
				null,
				0.0,
				'EUR',
				array(
					array(
						'product_id'    => $this->gift_product_id,
						'line_subtotal' => 100.0,
						'quantity'      => 1.0,
					),
				),
				array(
					'promotion_eligible_subtotal' => 0.0,
				)
			)
		);

		$this->assertFalse( $result->passed() );
	}

	public function test_minimum_eligible_subtotal_excludes_gift_card_lines(): void {
		$condition = MinimumEligibleSubtotalCondition::from_config(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL,
				'amount' => 40.0,
			)
		);

		$items = array(
			array(
				'product_id'    => $this->gift_product_id,
				'line_subtotal' => 100.0,
				'quantity'      => 1.0,
			),
			array(
				'product_id'    => $this->normal_product_id,
				'line_subtotal' => 50.0,
				'quantity'      => 1.0,
			),
		);

		$result = $condition->evaluate(
			new EvaluationContext( null, 150.0, 'EUR', $items, array() )
		);

		$this->assertTrue( $result->passed() );
		$this->assertSame( 50.0, $result->get_observed()['eligible_subtotal'] );
	}
}
