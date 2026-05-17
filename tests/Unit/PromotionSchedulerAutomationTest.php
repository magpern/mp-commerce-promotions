<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Service\PromotionLifecycle;
use PHPUnit\Framework\TestCase;

final class PromotionSchedulerAutomationTest extends TestCase {

	public function test_scheduled_draft_ready_phase(): void {
		$starts = gmdate( 'Y-m-d H:i:s', time() - 86400 );
		$promotion = Promotion::from_array(
			array(
				'uuid'      => '11111111-1111-4111-8111-111111111111',
				'name'      => 'Scheduled',
				'status'    => PromotionStatus::DRAFT,
				'starts_at' => $starts,
			)
		);

		$this->assertTrue( PromotionLifecycle::is_scheduled_draft_ready( $promotion ) );
		$this->assertSame( PromotionLifecycle::PHASE_SCHEDULED_DRAFT, PromotionLifecycle::primary_phase( $promotion ) );
	}

	public function test_expired_paused_phase(): void {
		$ends = gmdate( 'Y-m-d H:i:s', time() - 86400 );
		$promotion = Promotion::from_array(
			array(
				'uuid'    => '11111111-1111-4111-8111-111111111111',
				'name'    => 'Expired paused',
				'status'  => PromotionStatus::PAUSED,
				'ends_at' => $ends,
			)
		);

		$this->assertTrue( PromotionLifecycle::is_expired_paused( $promotion ) );
		$this->assertSame( PromotionLifecycle::PHASE_EXPIRED_PAUSED, PromotionLifecycle::primary_phase( $promotion ) );
	}
}
