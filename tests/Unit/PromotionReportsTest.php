<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Service\PromotionReports;
use PHPUnit\Framework\TestCase;

final class PromotionReportsTest extends TestCase {

	public function test_escape_csv_cell_quotes_commas(): void {
		$this->assertSame( 'plain', PromotionReports::escape_csv_cell( 'plain' ) );
		$this->assertSame( '"a,b"', PromotionReports::escape_csv_cell( 'a,b' ) );
		$this->assertSame( '"say ""hi"""', PromotionReports::escape_csv_cell( 'say "hi"' ) );
	}

	public function test_sanitize_date_rejects_invalid(): void {
		$this->assertNull( PromotionReports::sanitize_date( '' ) );
		$this->assertNull( PromotionReports::sanitize_date( '2026-13-01' ) );
		$this->assertNull( PromotionReports::sanitize_date( 'not-a-date' ) );
		$this->assertSame( '2026-05-16', PromotionReports::sanitize_date( '2026-05-16' ) );
	}

	public function test_sanitize_filters_swaps_inverted_dates(): void {
		$filters = PromotionReports::sanitize_filters(
			array(
				'date_from' => '2026-05-20',
				'date_to'   => '2026-05-10',
			)
		);

		$this->assertSame( '2026-05-10', $filters['date_from'] );
		$this->assertSame( '2026-05-20', $filters['date_to'] );
	}

	public function test_sanitize_filters_status(): void {
		$filters = PromotionReports::sanitize_filters(
			array(
				'status' => Redemption::STATUS_RECORDED,
			)
		);

		$this->assertSame( Redemption::STATUS_RECORDED, $filters['status'] );

		$invalid = PromotionReports::sanitize_filters( array( 'status' => 'invalid' ) );
		$this->assertNull( $invalid['status'] );
	}

}
