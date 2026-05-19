<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardExportTracker;
use MP\CommercePromotions\GiftCard\GiftCardLedgerExporter;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransaction;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use PHPUnit\Framework\TestCase;

final class GiftCardLedgerExporterTest extends TestCase {

	public function test_gift_cards_csv_headers_and_masks_code(): void {
		$card     = $this->sample_card();
		$cards    = $this->createMock( GiftCardRepository::class );
		$cards->method( 'list_for_export' )->willReturn( array( $card ) );
		$tx       = $this->createMock( GiftCardTransactionRepository::class );
		$tx->method( 'list_for_export' )->willReturn( array() );
		$exporter = new GiftCardLedgerExporter( $cards, $tx, $this->reports() );

		$csv = $exporter->gift_cards_csv();
		$this->assertSame(
			$exporter->header_line( GiftCardLedgerExporter::GIFT_CARD_HEADERS ),
			strtok( $csv, "\n" ) ?: ''
		);
		$this->assertStringNotContainsString( 'code_hash', $csv );
		$this->assertStringContainsString( '****1234', $csv );
		$this->assertStringNotContainsString( $card->get_code_hash(), $csv );
	}

	public function test_transactions_csv_includes_ledger_row(): void {
		$tx_row = new GiftCardTransaction(
			1,
			10,
			GiftCardTransaction::TYPE_ISSUED,
			25.0,
			25.0,
			null,
			null,
			'smoke',
			'2026-01-01 00:00:00'
		);
		$cards    = $this->createMock( GiftCardRepository::class );
		$cards->method( 'list_for_export' )->willReturn( array() );
		$tx       = $this->createMock( GiftCardTransactionRepository::class );
		$tx->method( 'list_for_export' )->willReturn( array( $tx_row ) );
		$exporter = new GiftCardLedgerExporter( $cards, $tx, $this->reports() );

		$csv = $exporter->transactions_csv();
		$this->assertStringContainsString( 'transaction_type', $csv );
		$this->assertStringContainsString( 'issued', $csv );
		$this->assertStringContainsString( ',10,', $csv );
	}

	public function test_liability_summary_header_columns(): void {
		$cards    = $this->createMock( GiftCardRepository::class );
		$tx       = $this->createMock( GiftCardTransactionRepository::class );
		$exporter = new GiftCardLedgerExporter( $cards, $tx, $this->reports() );
		$line     = $exporter->header_line( GiftCardLedgerExporter::LIABILITY_HEADERS );
		$this->assertStringContainsString( 'currency', $line );
		$this->assertStringContainsString( 'combined_liability', $line );
	}

	public function test_export_tracker_records_timestamp(): void {
		delete_option( GiftCardExportTracker::OPTION_TIMESTAMPS );
		GiftCardExportTracker::record_export( GiftCardExportTracker::TYPE_TRANSACTIONS );
		$timestamps = GiftCardExportTracker::get_timestamps();
		$this->assertArrayHasKey( GiftCardExportTracker::TYPE_TRANSACTIONS, $timestamps );
		$this->assertNotSame( '', $timestamps[ GiftCardExportTracker::TYPE_TRANSACTIONS ] );
		delete_option( GiftCardExportTracker::OPTION_TIMESTAMPS );
	}

	public function test_forbidden_export_detects_hash(): void {
		$this->assertTrue( GiftCardLedgerExporter::is_forbidden_export_value( str_repeat( 'a', 64 ) ) );
		$this->assertFalse( GiftCardLedgerExporter::is_forbidden_export_value( '****1234' ) );
	}

	private function reports(): GiftCardReports {
		return new GiftCardReports( new \wpdb() );
	}

	private function sample_card(): GiftCard {
		return new GiftCard(
			1,
			'00000000-0000-4000-8000-000000000042',
			hash( 'sha256', 'SECRET-CODE-9999' ),
			'1234',
			50.0,
			50.0,
			'EUR',
			GiftCard::STATUS_ACTIVE,
			null,
			null,
			null,
			null,
			'2026-01-01 00:00:00',
			'2026-01-01 00:00:00',
			GiftCard::SOURCE_GIFT_CARD,
			null,
			null
		);
	}
}
