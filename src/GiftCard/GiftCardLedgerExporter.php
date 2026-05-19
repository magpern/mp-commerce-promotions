<?php
/**
 * Read-only CSV exports for gift card ledger (no full codes or hashes).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\PromotionReports;

final class GiftCardLedgerExporter {

	public const EXPORT_ROW_LIMIT = 5000;

	/** @var list<string> */
	public const GIFT_CARD_HEADERS = array(
		'id',
		'gift_card_uuid',
		'code_last4',
		'masked_code',
		'source_type',
		'owner_customer_id',
		'initial_amount',
		'balance',
		'currency',
		'status',
		'expires_at',
		'created_order_id',
		'purchaser_customer_id',
		'recipient_email',
		'created_at',
		'updated_at',
	);

	/** @var list<string> */
	public const TRANSACTION_HEADERS = array(
		'id',
		'gift_card_id',
		'transaction_type',
		'amount',
		'balance_after',
		'order_id',
		'customer_id',
		'note',
		'created_at',
	);

	/** @var list<string> */
	public const LIABILITY_HEADERS = array(
		'currency',
		'gift_card_liability',
		'store_credit_liability',
		'combined_liability',
	);

	private GiftCardRepository $cards;

	private GiftCardTransactionRepository $transactions;

	private GiftCardReports $reports;

	public function __construct(
		GiftCardRepository $cards,
		GiftCardTransactionRepository $transactions,
		GiftCardReports $reports
	) {
		$this->cards        = $cards;
		$this->transactions = $transactions;
		$this->reports      = $reports;
	}

	public function gift_cards_csv(): string {
		$lines   = array();
		$lines[] = $this->header_line( self::GIFT_CARD_HEADERS );

		$offset = 0;
		$limit  = self::EXPORT_ROW_LIMIT;
		do {
			$batch = $this->cards->list_for_export( $limit, $offset );
			foreach ( $batch as $card ) {
				$lines[] = $this->gift_card_line( $card );
			}
			$offset += $limit;
		} while ( count( $batch ) === $limit && $offset < 50000 );

		return implode( "\n", $lines ) . "\n";
	}

	public function transactions_csv(): string {
		$lines   = array();
		$lines[] = $this->header_line( self::TRANSACTION_HEADERS );

		$offset = 0;
		$limit  = self::EXPORT_ROW_LIMIT;
		do {
			$batch = $this->transactions->list_for_export( $limit, $offset );
			foreach ( $batch as $tx ) {
				$lines[] = $this->transaction_line( $tx );
			}
			$offset += $limit;
		} while ( count( $batch ) === $limit && $offset < 50000 );

		return implode( "\n", $lines ) . "\n";
	}

	public function liability_summary_csv(): string {
		$summary = $this->reports->summary();
		$rows    = $summary['liability_by_currency'] ?? array();

		$lines   = array();
		$lines[] = $this->header_line( self::LIABILITY_HEADERS );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$lines[] = implode(
				',',
				array(
					PromotionReports::escape_csv_cell( (string) ( $row['currency'] ?? '' ) ),
					PromotionReports::escape_csv_cell( (string) ( $row['gift_card_liability'] ?? '' ) ),
					PromotionReports::escape_csv_cell( (string) ( $row['store_credit_liability'] ?? '' ) ),
					PromotionReports::escape_csv_cell( (string) ( $row['combined_liability'] ?? '' ) ),
				)
			);
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param list<string> $headers
	 */
	public function header_line( array $headers ): string {
		return implode(
			',',
			array_map(
				static function ( string $header ): string {
					return PromotionReports::escape_csv_cell( $header );
				},
				$headers
			)
		);
	}

	public function gift_card_line( GiftCard $card ): string {
		$last4 = $card->get_code_last4();

		return implode(
			',',
			array(
				PromotionReports::escape_csv_cell( (string) ( $card->get_id() ?? '' ) ),
				PromotionReports::escape_csv_cell( $card->get_gift_card_uuid() ),
				PromotionReports::escape_csv_cell( $last4 ),
				PromotionReports::escape_csv_cell( GiftCardGeneratedOrderState::masked_code( $last4 ) ),
				PromotionReports::escape_csv_cell( $card->get_source_type() ),
				PromotionReports::escape_csv_cell( $this->nullable_int( $card->get_owner_customer_id() ) ),
				PromotionReports::escape_csv_cell( (string) $card->get_initial_amount() ),
				PromotionReports::escape_csv_cell( (string) $card->get_balance() ),
				PromotionReports::escape_csv_cell( $card->get_currency() ),
				PromotionReports::escape_csv_cell( $card->get_status() ),
				PromotionReports::escape_csv_cell( $card->get_expires_at() ?? '' ),
				PromotionReports::escape_csv_cell( $this->nullable_int( $card->get_created_order_id() ) ),
				PromotionReports::escape_csv_cell( $this->nullable_int( $card->get_purchaser_customer_id() ) ),
				PromotionReports::escape_csv_cell( $card->get_recipient_email() ?? '' ),
				PromotionReports::escape_csv_cell( $card->get_created_at() ?? '' ),
				PromotionReports::escape_csv_cell( $card->get_updated_at() ?? '' ),
			)
		);
	}

	public function transaction_line( GiftCardTransaction $tx ): string {
		return implode(
			',',
			array(
				PromotionReports::escape_csv_cell( (string) ( $tx->get_id() ?? '' ) ),
				PromotionReports::escape_csv_cell( (string) $tx->get_gift_card_id() ),
				PromotionReports::escape_csv_cell( $tx->get_transaction_type() ),
				PromotionReports::escape_csv_cell( (string) $tx->get_amount() ),
				PromotionReports::escape_csv_cell( (string) $tx->get_balance_after() ),
				PromotionReports::escape_csv_cell( $this->nullable_int( $tx->get_order_id() ) ),
				PromotionReports::escape_csv_cell( $this->nullable_int( $tx->get_customer_id() ) ),
				PromotionReports::escape_csv_cell( $tx->get_note() ?? '' ),
				PromotionReports::escape_csv_cell( $tx->get_created_at() ?? '' ),
			)
		);
	}

	/**
	 * Reject values that look like full gift card codes or SHA-256 hashes in export cells.
	 */
	public static function is_forbidden_export_value( string $value ): bool {
		$trimmed = trim( $value );
		if ( $trimmed === '' ) {
			return false;
		}

		if ( preg_match( '/^[a-f0-9]{64}$/i', $trimmed ) === 1 ) {
			return true;
		}

		$normalized = strtoupper( preg_replace( '/\s+/', '', $trimmed ) ?? '' );
		if ( strlen( $normalized ) >= 16 && strpos( $normalized, '-' ) === false
			&& preg_match( '/^[A-Z0-9]+$/', $normalized ) === 1 ) {
			return true;
		}

		return false;
	}

	private function nullable_int( ?int $value ): string {
		return $value !== null && $value > 0 ? (string) $value : '';
	}
}
