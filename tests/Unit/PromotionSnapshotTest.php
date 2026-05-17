<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionSnapshot;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;
use PHPUnit\Framework\TestCase;

final class PromotionSnapshotTest extends TestCase {

	public function test_snapshot_round_trip_payload(): void {
		$promotion = Promotion::from_array(
			array(
				'id'                  => 10,
				'uuid'                => '11111111-1111-4111-8111-111111111111',
				'name'                => 'Snapshot source',
				'status'              => PromotionStatus::ACTIVE,
				'cooldown_hours'      => 12,
				'orchestration_group' => 'vip-lane',
				'conditions'          => array( array( 'type' => RuleTypes::CONDITION_LOGGED_IN ) ),
				'actions'             => array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) ),
			)
		);

		$snapshot = new PromotionSnapshot(
			null,
			10,
			PromotionSnapshot::TYPE_TEMPLATE_APPLY,
			$promotion->to_array(),
			null,
			1,
			'2026-05-17 12:00:00'
		);

		$restored = Promotion::from_array( $snapshot->get_snapshot_data() );
		$this->assertSame( 10, $restored->get_id() );
		$this->assertSame( 12, $restored->get_cooldown_hours() );
		$this->assertSame( 'vip-lane', $restored->get_orchestration_group() );
	}

	public function test_with_orchestration_normalizes_group(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'   => '11111111-1111-4111-8111-111111111111',
				'name'   => 'Orch',
				'status' => PromotionStatus::DRAFT,
			)
		);

		$updated = $promotion->with_orchestration( 6, '  checkout-upsell  ' );
		$this->assertSame( 6, $updated->get_cooldown_hours() );
		$this->assertSame( 'checkout-upsell', $updated->get_orchestration_group() );
	}
}
