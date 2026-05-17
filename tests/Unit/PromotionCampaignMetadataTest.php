<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use PHPUnit\Framework\TestCase;

final class PromotionCampaignMetadataTest extends TestCase {

	public function test_normalize_campaign_label_truncates_and_sanitizes(): void {
		$label = Promotion::normalize_campaign_label( '  Summer Sale  ');
		$this->assertSame( 'Summer Sale', $label );

		$this->assertNull( Promotion::normalize_campaign_label( '' ) );
		$this->assertNull( Promotion::normalize_campaign_label( null ) );
	}

	public function test_normalize_admin_color_accepts_hex_or_empty(): void {
		$this->assertSame( '#336699', Promotion::normalize_admin_color( '#336699' ) );
		$this->assertSame( '#aabbcc', Promotion::normalize_admin_color( '#AABBCC' ) );
		$this->assertNull( Promotion::normalize_admin_color( null ) );
		$this->assertNull( Promotion::normalize_admin_color( '' ) );
	}

	public function test_normalize_admin_color_rejects_invalid(): void {
		$this->expectException( InvalidArgumentException::class );
		Promotion::normalize_admin_color( 'blue' );
	}

	public function test_with_campaign_metadata_round_trip(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'   => '11111111-1111-4111-8111-111111111111',
				'name'   => 'Campaign promo',
				'status' => PromotionStatus::DRAFT,
			)
		);

		$updated = $promotion->with_campaign_metadata( 'VIP', 'Internal note', '#ff00aa' );

		$this->assertSame( 'VIP', $updated->get_campaign_label() );
		$this->assertSame( 'Internal note', $updated->get_internal_notes() );
		$this->assertSame( '#ff00aa', $updated->get_admin_color() );
	}

	public function test_to_array_includes_campaign_fields(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'           => '11111111-1111-4111-8111-111111111111',
				'name'           => 'Tagged',
				'status'         => PromotionStatus::DRAFT,
				'campaign_label' => 'Holiday',
				'admin_color'    => '#112233',
				'internal_notes' => 'Team note',
			)
		);

		$data = $promotion->to_array();
		$this->assertSame( 'Holiday', $data['campaign_label'] );
		$this->assertSame( '#112233', $data['admin_color'] );
		$this->assertSame( 'Team note', $data['internal_notes'] );
	}
}
