<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Service\PromotionService;
use PHPUnit\Framework\TestCase;

final class PromotionServiceTransitionTest extends TestCase {

	public function test_allowed_transitions(): void {
		$this->assertTrue(
			PromotionService::is_allowed_status_transition( PromotionStatus::DRAFT, PromotionStatus::ACTIVE )
		);
		$this->assertTrue(
			PromotionService::is_allowed_status_transition( PromotionStatus::ACTIVE, PromotionStatus::PAUSED )
		);
		$this->assertTrue(
			PromotionService::is_allowed_status_transition( PromotionStatus::PAUSED, PromotionStatus::ARCHIVED )
		);
	}

	public function test_disallowed_transitions(): void {
		$this->assertFalse(
			PromotionService::is_allowed_status_transition( PromotionStatus::ARCHIVED, PromotionStatus::ACTIVE )
		);
		$this->assertFalse(
			PromotionService::is_allowed_status_transition( PromotionStatus::ACTIVE, PromotionStatus::ACTIVE )
		);
		$this->assertFalse(
			PromotionService::is_allowed_status_transition( PromotionStatus::DRAFT, PromotionStatus::PAUSED )
		);
	}
}
