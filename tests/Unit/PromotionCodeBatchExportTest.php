<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\PromotionCodeBatch;
use PHPUnit\Framework\TestCase;

final class PromotionCodeBatchExportTest extends TestCase {

	public function test_from_array_includes_export_fields(): void {
		$batch = PromotionCodeBatch::from_array(
			array(
				'id'            => 3,
				'promotion_id'  => 9,
				'batch_uuid'    => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
				'name'          => 'Batch A',
				'quantity'      => 5,
				'batch_notes'   => 'VIP list',
				'exported_at'   => '2026-05-01 12:00:00',
				'exported_by'   => 1,
				'export_count'  => 2,
				'created_at'    => '2026-05-01 10:00:00',
			)
		);

		$this->assertSame( 'VIP list', $batch->get_batch_notes() );
		$this->assertSame( 2, $batch->get_export_count() );
		$this->assertSame( '2026-05-01 12:00:00', $batch->get_exported_at() );
		$this->assertSame( 1, $batch->get_exported_by() );
	}

	public function test_constructor_defaults_export_fields(): void {
		$batch = new PromotionCodeBatch(
			null,
			10,
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			'Batch B',
			2,
			null,
			null,
			null,
			null,
			null,
			null,
			0,
			null,
			null
		);

		$this->assertSame( 0, $batch->get_export_count() );
		$this->assertNull( $batch->get_exported_at() );
	}
}
