<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\CampaignBuilderDraftCreator;
use MP\CommercePromotions\Service\CampaignBuilderGoal;
use PHPUnit\Framework\TestCase;

final class CampaignBuilderDraftCreatorTest extends TestCase {

	private CampaignBuilderDraftCreator $creator;

	protected function setUp(): void {
		parent::setUp();
		$this->creator = new CampaignBuilderDraftCreator();
	}

	public function test_category_discount_maps_to_percent_template(): void {
		$rules = $this->creator->build_rules(
			CampaignBuilderGoal::CATEGORY_DISCOUNT,
			array(
				'category_ids'  => array( 10, 12 ),
				'discount_type'   => 'percentage',
				'percentage'      => 20,
			)
		);

		$this->assertSame( RuleTypes::ACTION_PERCENTAGE_DISCOUNT, $rules['actions'][0]['type'] );
		$this->assertSame( array( 10, 12 ), $rules['actions'][0]['category_ids'] );
	}

	public function test_product_discount_percentage_builds_scoped_action(): void {
		$rules = $this->creator->build_rules(
			CampaignBuilderGoal::PRODUCT_DISCOUNT,
			array(
				'product_ids'   => array( 100 ),
				'discount_type' => 'percentage',
				'percentage'    => 15,
			)
		);

		$this->assertSame( RuleTypes::ACTION_PERCENTAGE_DISCOUNT, $rules['actions'][0]['type'] );
		$this->assertSame( array( 100 ), $rules['actions'][0]['product_ids'] );
	}

	public function test_stackable_mapping(): void {
		$promotion = \MP\CommercePromotions\Domain\Promotion::from_array(
			array(
				'uuid'             => '11111111-1111-4111-8111-111111111111',
				'name'             => 'Test',
				'status'           => PromotionStatus::DRAFT,
				'application_mode' => PromotionApplicationMode::EXCLUSIVE,
				'stop_processing'  => true,
			)
		);

		$stackable = CampaignBuilderDraftCreator::apply_stackable_rules( $promotion, true );
		$this->assertSame( PromotionApplicationMode::STACKABLE, $stackable->get_application_mode() );
		$this->assertFalse( $stackable->should_stop_processing() );

		$exclusive = CampaignBuilderDraftCreator::apply_stackable_rules( $promotion, false );
		$this->assertSame( PromotionApplicationMode::EXCLUSIVE, $exclusive->get_application_mode() );
		$this->assertTrue( $exclusive->should_stop_processing() );
	}

	public function test_coupon_campaign_builds_whole_cart_percentage(): void {
		$rules = $this->creator->build_rules(
			CampaignBuilderGoal::COUPON_CODE,
			array(
				'discount_type' => 'percentage',
				'percentage'    => 10,
			)
		);

		$this->assertSame( array(), $rules['conditions'] );
		$this->assertSame( RuleTypes::ACTION_PERCENTAGE_DISCOUNT, $rules['actions'][0]['type'] );
		$this->assertSame( 10.0, $rules['actions'][0]['percentage'] );
	}

	public function test_invalid_goal_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->creator->build_rules( 'not_a_goal', array() );
	}

	public function test_missing_category_ids_for_percent_category(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->creator->build_rules(
			CampaignBuilderGoal::CATEGORY_DISCOUNT,
			array(
				'category_ids'  => array(),
				'discount_type' => 'percentage',
				'percentage'    => 10,
			)
		);
	}

	public function test_goal_notes_encoding_roundtrip(): void {
		$notes = CampaignBuilderGoal::encode_internal_notes( CampaignBuilderGoal::FREE_SHIPPING );
		$this->assertSame( CampaignBuilderGoal::FREE_SHIPPING, CampaignBuilderGoal::parse_goal_from_notes( $notes ) );
	}
}
