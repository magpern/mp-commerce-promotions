<?php
/**
 * Ecosystem certification tooling tests.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\CertificationRun;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Service\CouponCompatibilityMatrix;
use MP\CommercePromotions\Service\MultiCurrencyCompatibility;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\TaxCompatibilityAnalyzer;
use PHPUnit\Framework\TestCase;

final class EcosystemCertificationTest extends TestCase {

	public function test_schema_1_17_0(): void {
		$this->assertSame( '1.17.0', Schema::SCHEMA_VERSION );
	}

	public function test_coupon_matrix_has_six_scenarios(): void {
		$this->assertCount( 6, ( new CouponCompatibilityMatrix() )->build_scenarios() );
	}

	public function test_tax_analyzer_returns_structure(): void {
		$data = ( new TaxCompatibilityAnalyzer() )->analyze();
		$this->assertArrayHasKey( 'warnings', $data );
		$this->assertArrayHasKey( 'rounding_risk', $data );
	}

	public function test_multi_currency_snapshot(): void {
		$snapshot = ( new MultiCurrencyCompatibility() )->snapshot();
		$this->assertArrayHasKey( 'confidence', $snapshot );
		$this->assertArrayHasKey( 'recommendation', $snapshot );
	}

	public function test_profiler_coupon_counters(): void {
		$profiler = new PromotionPerformanceProfiler();
		$profiler->reset_aggregates();
		$profiler->increment_coupon_conflict();
		$summary = $profiler->get_report_summary();
		$this->assertSame( 1, $summary['coupon_conflict_count'] );
		$profiler->reset_aggregates();
	}

	public function test_certification_allowed_types(): void {
		$this->assertContains( CertificationRun::TYPE_COUPON_COEXISTENCE, CertificationRun::allowed_types() );
	}
}
