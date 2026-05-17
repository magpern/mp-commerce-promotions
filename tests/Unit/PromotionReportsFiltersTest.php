<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Service\PromotionReports;
use PHPUnit\Framework\TestCase;

final class PromotionReportsFiltersTest extends TestCase {

	public function test_resolve_date_preset_30d(): void {
		$range = PromotionReports::resolve_date_preset( PromotionReports::DATE_PRESET_30D );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $range['date_from'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $range['date_to'] );
		$this->assertLessThanOrEqual( 0, strcmp( $range['date_from'], $range['date_to'] ) );
	}

	public function test_sanitize_filters_applies_date_preset(): void {
		$filters = PromotionReports::sanitize_filters(
			array(
				'date_preset' => PromotionReports::DATE_PRESET_TODAY,
				'date_from'   => '2020-01-01',
				'date_to'     => '2020-01-31',
			)
		);

		$expected = PromotionReports::resolve_date_preset( PromotionReports::DATE_PRESET_TODAY );
		$this->assertSame( PromotionReports::DATE_PRESET_TODAY, $filters['date_preset'] );
		$this->assertSame( $expected['date_from'], $filters['date_from'] );
		$this->assertSame( $expected['date_to'], $filters['date_to'] );
	}

	public function test_budget_exhausted_filter_matching(): void {
		$exhausted = Promotion::from_array(
			array(
				'uuid'          => '11111111-1111-4111-8111-111111111111',
				'name'          => 'Exhausted',
				'status'        => PromotionStatus::ACTIVE,
				'budget_amount' => 10.0,
				'budget_spent'  => 10.0,
			)
		);
		$open = Promotion::from_array(
			array(
				'uuid'          => '22222222-2222-4222-8222-222222222222',
				'name'          => 'Open',
				'status'        => PromotionStatus::ACTIVE,
				'budget_amount' => 10.0,
				'budget_spent'  => 2.0,
			)
		);

		$this->assertTrue( PromotionReports::promotion_matches_budget_exhausted_filter( $exhausted, 'yes' ) );
		$this->assertFalse( PromotionReports::promotion_matches_budget_exhausted_filter( $open, 'yes' ) );
		$this->assertTrue( PromotionReports::promotion_matches_budget_exhausted_filter( $open, 'no' ) );
	}
}
