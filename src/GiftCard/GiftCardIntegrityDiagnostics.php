<?php
/**
 * Gift card and store credit ledger integrity checks and safe repairs.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Infrastructure\Database\TableName;
use wpdb;

final class GiftCardIntegrityDiagnostics {

	private wpdb $wpdb;

	private GiftCardRepository $cards;

	private GiftCardLedger $ledger;

	public function __construct( wpdb $wpdb, GiftCardRepository $cards, GiftCardLedger $ledger ) {
		$this->wpdb   = $wpdb;
		$this->cards  = $cards;
		$this->ledger = $ledger;
	}

	/**
	 * @return array{
	 *   negative_balance: list<array<string, mixed>>,
	 *   active_zero_balance: list<array<string, mixed>>,
	 *   balance_mismatch: list<array<string, mixed>>,
	 *   expired_still_active: list<array<string, mixed>>,
	 *   store_credit_missing_owner: list<array<string, mixed>>,
	 *   store_credit_unexpected_code_hash: list<array<string, mixed>>
	 * }
	 */
	public function analyze(): array {
		$table = $this->cards_table();
		$now   = current_time( 'mysql' );

		$negative_rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT id, code_last4, balance, status, source_type FROM {$table} WHERE balance < 0 LIMIT 50"
		);

		$active_zero_rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT id, code_last4, balance, status, source_type FROM {$table} WHERE status = %s AND balance <= 0 LIMIT 50",
			array( GiftCard::STATUS_ACTIVE )
		);

		$expired_active_rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT id, code_last4, balance, status, expires_at, source_type FROM {$table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s LIMIT 50",
			array( GiftCard::STATUS_ACTIVE, $now )
		);

		$missing_owner = DbQuery::get_results(
			$this->wpdb,
			"SELECT id, code_last4, balance, status FROM {$table} WHERE source_type = %s AND (owner_customer_id IS NULL OR owner_customer_id = 0) LIMIT 50",
			array( GiftCard::SOURCE_STORE_CREDIT )
		);

		$unexpected_hash = array();
		$store_rows      = DbQuery::get_results(
			$this->wpdb,
			"SELECT id, owner_customer_id, currency, code_hash, code_last4 FROM {$table} WHERE source_type = %s LIMIT 100",
			array( GiftCard::SOURCE_STORE_CREDIT )
		);
		foreach ( $store_rows as $row ) {
			$owner = (int) ( $row['owner_customer_id'] ?? 0 );
			$cur   = (string) ( $row['currency'] ?? '' );
			if ( $owner <= 0 || $cur === '' ) {
				continue;
			}
			$expected = GiftCard::store_credit_wallet_hash( $owner, $cur );
			if ( (string) ( $row['code_hash'] ?? '' ) !== $expected ) {
				$unexpected_hash[] = $row;
			}
		}

		$mismatch = array();
		foreach ( $this->cards->list_recent( 100, 0 ) as $card ) {
			$id = $card->get_id();
			if ( $id === null ) {
				continue;
			}
			$txs = $this->ledger->transactions_for_card( $id );
			if ( $txs === array() ) {
				continue;
			}
			$last = $txs[ count( $txs ) - 1 ];
			if ( abs( $last->get_balance_after() - $card->get_balance() ) > 0.009 ) {
				$mismatch[] = array(
					'id'             => $id,
					'code_last4'     => $card->get_code_last4(),
					'source_type'    => $card->get_source_type(),
					'card_balance'   => $card->get_balance(),
					'ledger_balance' => $last->get_balance_after(),
				);
			}
		}

		return array(
			'negative_balance'                  => $negative_rows,
			'active_zero_balance'               => $active_zero_rows,
			'balance_mismatch'                  => $mismatch,
			'expired_still_active'              => $expired_active_rows,
			'store_credit_missing_owner'        => $missing_owner,
			'store_credit_unexpected_code_hash' => $unexpected_hash,
		);
	}

	/**
	 * @return array{depleted_marked: int, expired_marked: int}
	 */
	public function repair( bool $apply = false ): array {
		$preview = array(
			'depleted_marked' => 0,
			'expired_marked'  => 0,
		);

		$issues = $this->analyze();

		foreach ( $issues['active_zero_balance'] as $row ) {
			++$preview['depleted_marked'];
			if ( $apply ) {
				$id = (int) ( $row['id'] ?? 0 );
				if ( $id > 0 ) {
					$card = $this->cards->find( $id );
					if ( $card !== null && $card->get_status() === GiftCard::STATUS_ACTIVE ) {
						$updated = $card->with_balance_and_status( 0.0, GiftCard::STATUS_DEPLETED );
						$this->cards->update( $updated );
					}
				}
			}
		}

		foreach ( $issues['expired_still_active'] as $row ) {
			if ( ( $row['source_type'] ?? GiftCard::SOURCE_GIFT_CARD ) !== GiftCard::SOURCE_GIFT_CARD ) {
				continue;
			}
			++$preview['expired_marked'];
			if ( $apply ) {
				$id = (int) ( $row['id'] ?? 0 );
				if ( $id > 0 ) {
					$card = $this->cards->find( $id );
					if ( $card !== null && $card->get_status() === GiftCard::STATUS_ACTIVE ) {
						$updated = $card->with_balance_and_status( $card->get_balance(), GiftCard::STATUS_EXPIRED );
						$this->cards->update( $updated );
					}
				}
			}
		}

		return $preview;
	}

	private function cards_table(): string {
		return TableName::assert_valid( Schema::gift_cards_table( $this->wpdb ) );
	}
}
