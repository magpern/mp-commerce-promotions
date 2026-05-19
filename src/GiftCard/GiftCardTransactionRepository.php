<?php
/**
 * Append-only gift card ledger transactions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;
use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Infrastructure\Database\TableName;
use wpdb;

class GiftCardTransactionRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function insert( GiftCardTransaction $transaction ): int {
		$now = current_time( 'mysql' );

		$data = array(
			'gift_card_id'     => $transaction->get_gift_card_id(),
			'transaction_type' => $transaction->get_transaction_type(),
			'amount'           => GiftCard::money( $transaction->get_amount() ),
			'balance_after'    => GiftCard::money( $transaction->get_balance_after() ),
			'order_id'         => $transaction->get_order_id(),
			'customer_id'      => $transaction->get_customer_id(),
			'note'             => $transaction->get_note(),
			'created_at'       => $transaction->get_created_at() ?? $now,
		);

		$order_format    = $data['order_id'] === null ? '%s' : '%d';
		$customer_format = $data['customer_id'] === null ? '%s' : '%d';
		$note_format     = $data['note'] === null || $data['note'] === '' ? '%s' : '%s';

		$formats = array(
			'%d',
			'%s',
			'%f',
			'%f',
			$order_format,
			$customer_format,
			$note_format,
			'%s',
		);

		$inserted = $this->wpdb->insert( $this->table(), $data, $formats );
		if ( false === $inserted ) {
			return 0;
		}

		$new_id = (int) $this->wpdb->insert_id;

		return $new_id > 0 ? $new_id : 0;
	}

	/**
	 * @return list<GiftCardTransaction>
	 */
	public function list_for_card( int $gift_card_id, int $limit = 100 ): array {
		if ( $gift_card_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 500, $limit ) );
		$table = $this->table();

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE gift_card_id = %d ORDER BY id ASC LIMIT %d",
			array( $gift_card_id, $limit )
		);

		$out = array();
		foreach ( $rows as $row ) {
			$tx = $this->row_to_transaction( $row );
			if ( $tx !== null ) {
				$out[] = $tx;
			}
		}

		return $out;
	}

	/**
	 * @return list<GiftCardTransaction>
	 */
	public function list_for_export( int $limit = 5000, int $offset = 0 ): array {
		$limit  = max( 1, min( 5000, $limit ) );
		$offset = max( 0, $offset );
		$table  = $this->table();

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
			array( $limit, $offset )
		);

		$out = array();
		foreach ( $rows as $row ) {
			$tx = $this->row_to_transaction( $row );
			if ( $tx !== null ) {
				$out[] = $tx;
			}
		}

		return $out;
	}

	public function has_redeemed_for_order( int $gift_card_id, int $order_id ): bool {
		if ( $gift_card_id <= 0 || $order_id <= 0 ) {
			return false;
		}

		$table = $this->table();
		$found = DbQuery::get_var(
			$this->wpdb,
			"SELECT 1 FROM {$table} WHERE gift_card_id = %d AND order_id = %d AND transaction_type = %s LIMIT 1",
			array( $gift_card_id, $order_id, GiftCardTransaction::TYPE_REDEEMED )
		);

		return $found !== null && $found !== '';
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_transaction( ?array $row ): ?GiftCardTransaction {
		if ( $row === null ) {
			return null;
		}

		try {
			return new GiftCardTransaction(
				isset( $row['id'] ) ? (int) $row['id'] : null,
				(int) ( $row['gift_card_id'] ?? 0 ),
				(string) ( $row['transaction_type'] ?? '' ),
				(float) ( $row['amount'] ?? 0 ),
				(float) ( $row['balance_after'] ?? 0 ),
				isset( $row['order_id'] ) && $row['order_id'] !== '' && $row['order_id'] !== null
					? (int) $row['order_id'] : null,
				isset( $row['customer_id'] ) && $row['customer_id'] !== '' && $row['customer_id'] !== null
					? (int) $row['customer_id'] : null,
				isset( $row['note'] ) && $row['note'] !== '' ? (string) $row['note'] : null,
				isset( $row['created_at'] ) ? (string) $row['created_at'] : null
			);
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	private function table(): string {
		$table = Schema::gift_card_transactions_table( $this->wpdb );

		return TableName::assert_valid( $table );
	}
}
