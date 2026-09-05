<?php
/**
 * ADR-0001: Universal Commerce Bundles component-child cart-line exclusion.
 *
 * Tier 1 (below): exercises the real CartContextBuilder::build_from_cart()
 * code path via the WC()/WC_Cart test stub added in tests/bootstrap.php —
 * proving the `_ucb_component` guard itself, not just downstream condition
 * logic against a hand-built EvaluationContext. No Universal Commerce
 * Bundles class, constant, or hook is loaded anywhere in this file; the
 * raw cart-item array key is the only thing exercised, matching the
 * data-contract-only design in ADR-0001 and
 * docs/UNIVERSAL_COMMERCE_BUNDLES_COMPONENT_EXCLUSION.md.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\CategoryInCartCondition;
use MP\CommercePromotions\Engine\Condition\CategoryQuantityCondition;
use MP\CommercePromotions\Engine\Condition\MinimumCartQuantityCondition;
use MP\CommercePromotions\Engine\Condition\ProductInCartCondition;
use MP\CommercePromotions\Engine\Condition\ProductQuantityCondition;
use MP\CommercePromotions\Engine\DiscountAllocationEngine;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use MP\CommercePromotions\Woo\CartContextBuilder;
use PHPUnit\Framework\TestCase;

final class UcbComponentExclusionTest extends TestCase {

	private const KIT_PARENT_PRODUCT_ID = 71001;
	private const COMPONENT_PRODUCT_ID  = 71002;
	private const OTHER_PRODUCT_ID      = 71003;
	private const COMPONENT_CATEGORY_ID = 8801;

	protected function setUp(): void {
		global $mp_cp_test_wc, $mp_cp_test_product_categories, $mp_cp_test_post_meta;
		$mp_cp_test_wc                 = null;
		$mp_cp_test_product_categories = array(
			self::COMPONENT_PRODUCT_ID => array( self::COMPONENT_CATEGORY_ID ),
		);
		$mp_cp_test_post_meta          = array();
	}

	/**
	 * A "kit only" cart: the priced parent plus its zero-priced, marked
	 * component child. No standalone purchase of the component exists.
	 */
	private function seed_kit_only_cart(): void {
		WC()->cart->set_test_cart(
			array(
				'parent_key' => array(
					'product_id'    => self::KIT_PARENT_PRODUCT_ID,
					'variation_id'  => 0,
					'quantity'      => 1,
					'line_subtotal' => 100.0,
				),
				'child_key'  => array(
					'product_id'          => self::COMPONENT_PRODUCT_ID,
					'variation_id'        => 0,
					'quantity'            => 3,
					'line_subtotal'       => 0.0,
					'_ucb_component'      => 1,
					'_ucb_parent_item_id' => 'parent_key',
				),
			),
			100.0
		);
	}

	/**
	 * Mixed cart: kit parent + marked component child + a standalone
	 * purchase of the same component product + one unrelated product.
	 */
	private function seed_mixed_cart(): void {
		WC()->cart->set_test_cart(
			array(
				'parent_key'     => array(
					'product_id'    => self::KIT_PARENT_PRODUCT_ID,
					'variation_id'  => 0,
					'quantity'      => 1,
					'line_subtotal' => 100.0,
				),
				'child_key'      => array(
					'product_id'          => self::COMPONENT_PRODUCT_ID,
					'variation_id'        => 0,
					'quantity'            => 3,
					'line_subtotal'       => 0.0,
					'_ucb_component'      => 1,
					'_ucb_parent_item_id' => 'parent_key',
				),
				'standalone_key' => array(
					'product_id'    => self::COMPONENT_PRODUCT_ID,
					'variation_id'  => 0,
					'quantity'      => 2,
					'line_subtotal' => 40.0,
				),
				'other_key'      => array(
					'product_id'    => self::OTHER_PRODUCT_ID,
					'variation_id'  => 0,
					'quantity'      => 1,
					'line_subtotal' => 25.0,
				),
			),
			165.0
		);
	}

	/**
	 * 1. The component child is absent from the built context.
	 * 2. The kit parent and standalone component remain present.
	 */
	public function test_component_child_absent_from_real_built_context(): void {
		$this->seed_mixed_cart();

		$context = ( new CartContextBuilder() )->build_from_cart();
		$keys    = array_column( $context->get_items(), 'item_key' );

		$this->assertNotContains( 'child_key', $keys, 'Component child must be absent from the built context.' );
		$this->assertContains( 'parent_key', $keys, 'Kit parent must remain present.' );
		$this->assertContains( 'standalone_key', $keys, 'Standalone component purchase must remain present.' );
		$this->assertContains( 'other_key', $keys, 'Unrelated product must remain present.' );
		$this->assertCount( 3, $context->get_items(), 'Only the three non-component rows should survive.' );
	}

	/**
	 * 3. Component children cannot satisfy product/category/quantity
	 * conditions — proven against the real, builder-produced context.
	 */
	public function test_product_in_cart_condition_ignores_kit_only_component(): void {
		$this->seed_kit_only_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$condition = new ProductInCartCondition( array( self::COMPONENT_PRODUCT_ID ) );
		$result    = $condition->evaluate( $context );

		$this->assertFalse(
			$result->passed(),
			'A component child alone must not satisfy product_in_cart for its own product ID.'
		);
	}

	public function test_product_in_cart_condition_satisfied_by_real_standalone_purchase(): void {
		$this->seed_mixed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$condition = new ProductInCartCondition( array( self::COMPONENT_PRODUCT_ID ) );
		$result    = $condition->evaluate( $context );

		$this->assertTrue(
			$result->passed(),
			'A genuine standalone purchase of the component product must still satisfy product_in_cart.'
		);
	}

	public function test_category_in_cart_condition_ignores_kit_only_component(): void {
		$this->seed_kit_only_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$condition = new CategoryInCartCondition( array( self::COMPONENT_CATEGORY_ID ) );
		$result    = $condition->evaluate( $context );

		$this->assertFalse(
			$result->passed(),
			'A component child alone must not satisfy category_in_cart for its own category.'
		);
	}

	public function test_category_in_cart_condition_satisfied_by_real_standalone_purchase(): void {
		$this->seed_mixed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$condition = new CategoryInCartCondition( array( self::COMPONENT_CATEGORY_ID ) );
		$result    = $condition->evaluate( $context );

		$this->assertTrue( $result->passed() );
	}

	public function test_product_quantity_condition_excludes_component_child_units(): void {
		$this->seed_mixed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		// Child carries qty 3, standalone carries qty 2. If the child leaked
		// in, total would be 5 and ">= 4" would incorrectly pass.
		$condition = new ProductQuantityCondition( self::COMPONENT_PRODUCT_ID, '>=', 4.0 );
		$result    = $condition->evaluate( $context );

		$this->assertFalse( $result->passed() );
		$this->assertSame( 2.0, $result->get_observed()['actual_quantity'] );
	}

	public function test_category_quantity_condition_excludes_component_child_units(): void {
		$this->seed_mixed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$condition = new CategoryQuantityCondition( self::COMPONENT_CATEGORY_ID, '>=', 4.0 );
		$result    = $condition->evaluate( $context );

		$this->assertFalse( $result->passed() );
		$this->assertSame( 2.0, $result->get_observed()['actual_quantity'] );
	}

	public function test_minimum_cart_quantity_condition_excludes_component_child_units(): void {
		$this->seed_mixed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		// Real quantity across surviving rows: parent 1 + standalone 2 +
		// other 1 = 4. If the child's 3 leaked in, total would be 7.
		$this->assertSame( 4.0, array_sum( array_column( $context->get_items(), 'quantity' ) ) );

		$condition = new MinimumCartQuantityCondition( 5 );
		$result    = $condition->evaluate( $context );

		$this->assertFalse( $result->passed() );
	}

	/**
	 * 4. Component children do not affect eligibility subtotal,
	 * cheapest-item selection, discount allocation, or discount amount.
	 */
	public function test_eligible_subtotal_excludes_component_child(): void {
		$this->seed_mixed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		// 100 (parent) + 40 (standalone) + 25 (other) = 165; the child's
		// zero-priced line contributes nothing either way, but it must not
		// even be counted as a matched line for subtotal-scoped actions.
		$this->assertSame( 165.0, $context->get_cart_subtotal() );
	}

	public function test_cheapest_item_discount_ignores_component_child_quantity(): void {
		$this->seed_mixed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		// Scope is the component product; child carries qty 3, standalone
		// qty 2. required_quantity=4 must fail on the real (non-child) 2
		// units, not pass on 3+2=5 if the child had leaked through.
		$action = CheapestItemDiscountAction::from_config(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => CheapestItemDiscountAction::SCOPE_PRODUCTS,
				'product_ids'         => array( self::COMPONENT_PRODUCT_ID ),
				'discount_percentage' => 100,
				'required_quantity'   => 4,
				'discounted_quantity' => 1,
			)
		);

		$payload = $action->preview( $context )->get_payload();

		$this->assertSame( 2, $payload['eligible_units'] );
		$this->assertTrue( $payload['not_applicable'] );
		$this->assertSame( 0.0, $payload['discount_amount'] );
	}

	public function test_discount_allocation_engine_never_allocates_to_component_child(): void {
		$this->seed_mixed_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$promotion = PromotionTestFixtures::active_promotion_with_id(
			9001,
			array(),
			array(
				array(
					'type'        => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage'  => 100,
					'product_ids' => array( self::COMPONENT_PRODUCT_ID ),
				),
			)
		);

		$evaluator = new PromotionEvaluator();
		$result    = $evaluator->evaluate( $promotion, $context );
		$this->assertTrue( $result->is_eligible() );

		$decision   = new PromotionEvaluationDecision( $promotion, $result, true, null );
		$allocation = ( new DiscountAllocationEngine() )->allocate( $context, array( $decision ), false );

		$line_keys = array();
		foreach ( $allocation->get_line_allocations() as $slice ) {
			$row         = $slice->to_array();
			$line_keys[] = $row['line_key'];
			// Scope is the component product; only the real standalone
			// line may receive an allocation, at its real 40.0 subtotal —
			// never the child's zero-priced, excluded line.
			$this->assertSame( 'standalone_key', $row['line_key'] );
			$this->assertSame( 40.0, round( $row['amount'], 4 ) );
		}

		$this->assertNotContains( 'child_key', $line_keys );
		$this->assertContains( 'standalone_key', $line_keys );
	}

	/**
	 * 5. The parent remains eligible under the same promotion as an
	 * ordinary eligible product.
	 */
	public function test_kit_parent_eligible_under_ordinary_product_in_cart_promotion(): void {
		$this->seed_kit_only_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$condition = new ProductInCartCondition( array( self::KIT_PARENT_PRODUCT_ID ) );
		$result    = $condition->evaluate( $context );

		$this->assertTrue( $result->passed(), 'The priced kit parent must be treated like any ordinary product.' );
	}

	// 6. The standalone component remains eligible. Covered directly by
	// test_product_in_cart_condition_satisfied_by_real_standalone_purchase()
	// and test_category_in_cart_condition_satisfied_by_real_standalone_purchase()
	// above.
	//
	// 7. Mixed carts behave correctly (kit parent + component children +
	// unrelated product — only valid non-component lines influence
	// eligibility and discount results). Covered by
	// test_component_child_absent_from_real_built_context() and the
	// quantity/subtotal/allocation tests above, all run against
	// seed_mixed_cart().

	/**
	 * 8. Session-style restore / recalculation preserves exclusion.
	 */
	public function test_exclusion_preserved_after_recalculation_style_mutation(): void {
		$this->seed_mixed_cart();
		$builder = new CartContextBuilder();

		$first_pass = $builder->build_from_cart();
		$this->assertNotContains( 'child_key', array_column( $first_pass->get_items(), 'item_key' ) );

		// Simulate what woocommerce_before_calculate_totals / a session
		// reload does: the child's synchronized quantity changes (e.g. the
		// parent's own quantity changed and UCB's own
		// CartConstruction::syncAndZeroChildren() re-derived the child's
		// quantity) while the marker itself is untouched.
		$cart_contents                                = WC()->cart->get_cart();
		$cart_contents['child_key']['quantity']       = 6;
		$cart_contents['parent_key']['quantity']      = 2;
		$cart_contents['parent_key']['line_subtotal'] = 200.0;
		WC()->cart->set_test_cart( $cart_contents, 265.0 );

		$second_pass = $builder->build_from_cart();
		$keys        = array_column( $second_pass->get_items(), 'item_key' );

		$this->assertNotContains( 'child_key', $keys, 'Exclusion must survive a recalculation-style cart mutation.' );
		$this->assertContains( 'parent_key', $keys );
		$this->assertContains( 'standalone_key', $keys );
	}

	/**
	 * 9. No UCB classes are loaded; the marker alone is sufficient.
	 * (Structural: this entire test file never references any
	 * UniversalCommerceBundles\* class, constant, or hook — the raw
	 * '_ucb_component' array key is the only thing used, seeded by
	 * hand above with no UCB code present or loaded.)
	 */
	public function test_marker_alone_is_sufficient_without_any_ucb_code_loaded(): void {
		$this->assertFalse(
			class_exists( 'UniversalCommerceBundles\\Domain\\MetaKeys', false ),
			'This test proves the guard works from the literal array key alone; no UCB class may be loaded.'
		);

		$this->seed_kit_only_cart();
		$context = ( new CartContextBuilder() )->build_from_cart();

		$this->assertNotContains( 'child_key', array_column( $context->get_items(), 'item_key' ) );
	}

	/**
	 * Stub-level entry-path parity (necessary but not sufficient — see
	 * ADR-0001 Decision §8 and the plan doc's mandatory live acceptance
	 * gate for the real Store API/Blocks proof).
	 */
	public function test_stub_level_parity_between_classic_and_store_api_shaped_rows(): void {
		// Classic add-to-cart shape: WC_Cart::add_to_cart()'s $cart_item_data
		// merged directly onto the row, as UniversalCommerceBundles\Woo\CartConstruction
		// produces it (no UCB code loaded here — this only mirrors the
		// documented array shape from docs/UNIVERSAL_COMMERCE_BUNDLES_COMPONENT_EXCLUSION.md).
		$classic_shaped_child = array(
			'product_id'          => self::COMPONENT_PRODUCT_ID,
			'variation_id'        => 0,
			'quantity'            => 3,
			'line_subtotal'       => 0.0,
			'_ucb_component'      => 1,
			'_ucb_parent_item_id' => 'parent_key',
			'unique_key'          => 'uuid-classic',
		);

		// Store API/Blocks shape: same underlying WC()->cart state (UCB's
		// CartConstruction hooks WooCommerce cart actions directly, not a
		// REST-only path — see the plan doc's evidence), so the row is
		// structurally identical; this test asserts the builder does not
		// key off any Store-API-only field that a classic row lacks.
		$store_api_shaped_child               = $classic_shaped_child;
		$store_api_shaped_child['unique_key'] = 'uuid-store-api';

		WC()->cart->set_test_cart(
			array(
				'parent_key' => array(
					'product_id'    => self::KIT_PARENT_PRODUCT_ID,
					'variation_id'  => 0,
					'quantity'      => 1,
					'line_subtotal' => 100.0,
				),
				'child_key'  => $classic_shaped_child,
			),
			100.0
		);
		$classic_context = ( new CartContextBuilder() )->build_from_cart();

		WC()->cart->set_test_cart(
			array(
				'parent_key' => array(
					'product_id'    => self::KIT_PARENT_PRODUCT_ID,
					'variation_id'  => 0,
					'quantity'      => 1,
					'line_subtotal' => 100.0,
				),
				'child_key'  => $store_api_shaped_child,
			),
			100.0
		);
		$store_api_context = ( new CartContextBuilder() )->build_from_cart();

		$classic_keys   = array_column( $classic_context->get_items(), 'item_key' );
		$store_api_keys = array_column( $store_api_context->get_items(), 'item_key' );

		$this->assertSame( $classic_keys, $store_api_keys );
		$this->assertNotContains( 'child_key', $classic_keys );
		$this->assertNotContains( 'child_key', $store_api_keys );
	}
}
