<?php
/**
 * Production pilot hardening unit tests.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionSnapshot;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Service\ProductionProfilePresets;
use MP\CommercePromotions\Service\PromotionSnapshotDiffService;
use MP\CommercePromotions\Service\RuntimeAnomalyDetector;
use MP\CommercePromotions\Service\Settings;
use PHPUnit\Framework\TestCase;

final class PilotHardeningTest extends TestCase {

	public function test_snapshot_diff_detects_pricing_change(): void {
		$current = $this->sample_promotion( PromotionApplicationMode::EXCLUSIVE, 'fee_based', 100.0 );
		$data    = $current->to_array();
		$data['discount_application_mode'] = 'line_item';
		$snapshot = new PromotionSnapshot(
			1,
			1,
			PromotionSnapshot::TYPE_TEMPLATE_APPLY,
			$data,
			null,
			null,
			null
		);

		$diff = ( new PromotionSnapshotDiffService() )->diff_against_snapshot( $current, $snapshot );
		$this->assertNotEmpty( $diff['changed_fields'] );
		$codes = array_column( $diff['risk_indicators'], 'code' );
		$this->assertContains( 'pricing_mode_changed', $codes );
	}

	public function test_anomaly_detector_slow_planner(): void {
		$detector = new RuntimeAnomalyDetector();
		$detector->reset_counters();
		for ( $i = 0; $i < 4; ++$i ) {
			$detector->record_planner_sample(
				array(
					'duration_ms'           => 600,
					'promotions_considered' => 10,
					'selected_count'        => 1,
				)
			);
		}
		$rows = $detector->active_anomalies();
		$codes = array_column( $rows, 'code' );
		$this->assertContains( 'excessive_planner_runtime', $codes );
	}

	public function test_production_profile_definitions(): void {
		$defs = ProductionProfilePresets::definitions();
		$this->assertArrayHasKey( ProductionProfilePresets::PROFILE_CONSERVATIVE, $defs );
		$this->assertArrayHasKey( ProductionProfilePresets::PROFILE_BALANCED, $defs );
		$this->assertArrayHasKey( ProductionProfilePresets::PROFILE_AGGRESSIVE, $defs );
		$this->assertTrue( (bool) $defs[ ProductionProfilePresets::PROFILE_CONSERVATIVE ]['line_item_mode_disabled'] );
	}

	public function test_profile_preview_returns_changes(): void {
		$settings = new Settings();
		$preview  = ( new ProductionProfilePresets() )->preview_apply(
			ProductionProfilePresets::PROFILE_AGGRESSIVE,
			$settings
		);
		$this->assertSame( ProductionProfilePresets::PROFILE_AGGRESSIVE, $preview['profile'] );
		$this->assertIsArray( $preview['changes'] );
	}

	private function sample_promotion( string $mode, string $discount_mode, float $budget ): Promotion {
		return Promotion::from_array(
			array(
				'id'                          => 1,
				'uuid'                        => '00000000-0000-4000-8000-000000000099',
				'name'                        => 'Test',
				'status'                      => PromotionStatus::ACTIVE,
				'application_mode'            => $mode,
				'stop_processing'             => false,
				'max_applications_per_cart'   => null,
				'priority'                    => 10,
				'conditions'                  => array(),
				'actions'                     => array(),
				'restrictions'                => array(),
				'discount_application_mode'   => $discount_mode,
				'dry_run'                     => false,
				'budget_amount'               => $budget,
				'budget_spent'                => 0,
			)
		);
	}
}
