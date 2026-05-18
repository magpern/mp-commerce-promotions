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
}
