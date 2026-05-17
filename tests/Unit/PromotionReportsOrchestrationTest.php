<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Service\PromotionReports;
use PHPUnit\Framework\TestCase;

final class PromotionReportsOrchestrationTest extends TestCase {

	public function test_budget_utilization_csv_percent(): void {
		$pct = PromotionReports::format_budget_utilization_percent_for_csv(
			array(
				'budget_amount' => 200.0,
				'budget_spent'  => 50.0,
			)
		);

		$this->assertSame( '25.0', $pct );
	}

	public function test_budget_utilization_csv_empty_without_cap(): void {
		$this->assertSame( '', PromotionReports::format_budget_utilization_percent_for_csv( array() ) );
	}
}
