<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Service\CampaignBuilderGoal;
use MP\CommercePromotions\Service\CampaignSummaryFormatter;
use PHPUnit\Framework\TestCase;

final class CampaignSummaryFormatterTest extends TestCase {

	public function test_category_discount_headline(): void {
		$ui = array(
			'discount_type' => 'percentage',
			'percentage'    => '20',
			'category_ids'  => array(),
		);

		$headline = CampaignSummaryFormatter::headline( CampaignBuilderGoal::CATEGORY_DISCOUNT, $ui );
		$this->assertStringContainsString( '20', $headline );
		$this->assertStringContainsString( 'Customers get', $headline );
	}

	public function test_free_shipping_headline(): void {
		$ui = array(
			'minimum_subtotal' => '100',
		);

		$benefit = CampaignSummaryFormatter::customer_benefit( CampaignBuilderGoal::FREE_SHIPPING, $ui );
		$this->assertStringContainsString( 'Free shipping', $benefit );
	}

	public function test_goal_teaser_not_empty(): void {
		foreach ( CampaignBuilderGoal::all() as $goal ) {
			$this->assertNotSame( '', CampaignSummaryFormatter::goal_teaser( $goal ) );
		}
	}

	public function test_first_order_headline_avoids_duplicate_scope(): void {
		$ui = array(
			'discount_type' => 'percentage',
			'percentage'    => '15',
		);

		$headline = CampaignSummaryFormatter::headline( CampaignBuilderGoal::FIRST_ORDER, $ui );
		$this->assertStringContainsString( 'First-time customers', $headline );
		$this->assertStringNotContainsString( ' on first-time buyers', strtolower( $headline ) );
	}

	public function test_bogo_headline_includes_scope_once(): void {
		$ui = array(
			'bogo_scope'            => 'category',
			'category_ids'          => array( 1 ),
			'required_quantity'     => 2,
			'discounted_quantity'   => 1,
			'discount_percentage'   => 100,
		);

		$headline = CampaignSummaryFormatter::headline( CampaignBuilderGoal::BUY_X_GET_Y, $ui );
		$this->assertStringContainsString( 'Buy 2', $headline );
		$this->assertStringNotContainsString( ' on ', $headline );
	}

	public function test_review_sections_for_every_goal(): void {
		foreach ( CampaignBuilderGoal::all() as $goal ) {
			$ui       = array( 'discount_type' => 'percentage', 'percentage' => '10' );
			$sections = CampaignSummaryFormatter::review_sections( $goal, $ui );
			$this->assertNotSame( '', $sections['headline'], 'headline for ' . $goal );
			$this->assertNotSame( '', $sections['benefit'], 'benefit for ' . $goal );
		}
	}
}
