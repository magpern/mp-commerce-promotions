<?php
/**
 * GA stabilization closure: dry-run, schedule preview, schema.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Service\PromotionDryRunGuard;
use MP\CommercePromotions\Service\ScheduleConflictPreviewService;
use MP\CommercePromotions\Service\Settings;
use PHPUnit\Framework\TestCase;

final class GaClosureTest extends TestCase {

	public function test_schema_version_1_17_0(): void {
		$this->assertSame( '1.18.0', Schema::SCHEMA_VERSION );
	}

	public function test_promotion_dry_run_from_array(): void {
		$row = array(
			'id'           => 1,
			'uuid'         => '00000000-0000-4000-8000-000000000001',
			'name'         => 'Dry',
			'status'       => PromotionStatus::ACTIVE,
			'dry_run'      => 1,
			'conditions'   => array(),
			'actions'      => array(),
			'restrictions' => array(),
		);
		$p = Promotion::from_array( $row );
		$this->assertTrue( $p->is_dry_run() );
	}

	public function test_global_dry_run_overrides_promotion_flag(): void {
		$settings = new Settings();
		$settings->set_promotion_dry_run_enabled( true );
		$guard = new PromotionDryRunGuard( $settings );

		$p = $this->minimal_promotion( false );
		$this->assertTrue( $guard->is_promotion_dry_run( $p ) );
		$this->assertFalse( $guard->should_apply_storefront( $p ) );
		$settings->set_promotion_dry_run_enabled( false );
	}

	public function test_per_promotion_dry_run_only(): void {
		$settings = new Settings();
		$settings->set_promotion_dry_run_enabled( false );
		$guard = new PromotionDryRunGuard( $settings );

		$p = $this->minimal_promotion( true );
		$this->assertTrue( $guard->is_promotion_dry_run( $p ) );
	}

	public function test_schedule_conflict_preview_empty_catalog(): void {
		$preview = new ScheduleConflictPreviewService();
		$this->assertSame( array(), $preview->preview_for_promotion( $this->minimal_promotion( false ), array() ) );
	}

	private function minimal_promotion( bool $dry_run ): Promotion {
		return new Promotion(
			1,
			'00000000-0000-4000-8000-000000000099',
			'Test',
			null,
			PromotionStatus::ACTIVE,
			10,
			null,
			null,
			array(),
			array(),
			array(),
			null,
			null,
			0,
			PromotionApplicationMode::EXCLUSIVE,
			true,
			null,
			array(),
			array(),
			array(),
			null,
			null,
			null,
			null,
			0.0,
			null,
			null,
			null,
			'storefront',
			'coexist',
			'proportional',
			'fee_based',
			$dry_run
		);
	}
}
