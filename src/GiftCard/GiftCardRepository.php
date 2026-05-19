<?php
/**
 * Gift card persistence (hashed codes only).
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

class GiftCardRepository {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public static function hash_plain_code( string $plain_code ): string {
		return hash( 'sha256', strtoupper( preg_replace( '/\s+/', '', $plain_code ) ?? '' ) );
	}

	public function insert( GiftCard $card ): int {
		$now = current_time( 'mysql' );

		$data = array(
			'gift_card_uuid'        => $card->get_gift_card_uuid(),
			'code_hash'             => $card->get_code_hash(),
			'code_last4'            => $card->get_code_last4(),
			'initial_amount'        => GiftCard::money( $card->get_initial_amount() ),
			'balance'               => GiftCard::money( $card->get_balance() ),
			'currency'              => $card->get_currency(),
			'status'                => $card->get_status(),
			'expires_at'            => $card->get_expires_at(),
			'created_order_id'      => $card->get_created_order_id(),
			'purchaser_customer_id' => $card->get_purchaser_customer_id(),
			'recipient_email'       => $card->get_recipient_email(),
			'source_type'           => $card->get_source_type(),
			'owner_customer_id'     => $card->get_owner_customer_id(),
			'label'                 => $card->get_label(),
			'created_at'            => $card->get_created_at() ?? $now,
			'updated_at'            => $card->get_updated_at() ?? $now,
		);

		$formats = array(
			'%s',
			'%s',
			'%s',
			'%f',
			'%f',
			'%s',
			'%s',
			'%s',
			'%d',
			'%d',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
			'%s',
		);

		if ( $data['expires_at'] === null ) {
			$data['expires_at'] = null;
			$formats[7]         = '%s';
		}
		if ( $data['created_order_id'] === null ) {
			$data['created_order_id'] = null;
			$formats[8]               = '%s';
		}
		if ( $data['purchaser_customer_id'] === null ) {
			$data['purchaser_customer_id'] = null;
			$formats[9]                    = '%s';
		}
		if ( $data['recipient_email'] === null || $data['recipient_email'] === '' ) {
			$data['recipient_email'] = null;
			$formats[10]             = '%s';
		}
		if ( $data['owner_customer_id'] === null || $data['owner_customer_id'] <= 0 ) {
			$data['owner_customer_id'] = null;
			$formats[12]               = '%s';
		}
		if ( $data['label'] === null || $data['label'] === '' ) {
			$data['label'] = null;
			$formats[13]   = '%s';
		}

		$inserted = $this->wpdb->insert( $this->table(), $data, $formats );
		if ( false === $inserted ) {
			return 0;
		}

		$new_id = (int) $this->wpdb->insert_id;

		return $new_id > 0 ? $new_id : 0;
	}

	public function update( GiftCard $card ): bool {
		$id = $card->get_id();
		if ( $id === null || $id <= 0 ) {
			return false;
		}

		$data = array(
			'balance'    => GiftCard::money( $card->get_balance() ),
			'status'     => $card->get_status(),
			'updated_at' => current_time( 'mysql' ),
		);

		$updated = $this->wpdb->update(
			$this->table(),
			$data,
			array( 'id' => $id ),
			array( '%f', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function find( int $id ): ?GiftCard {
		if ( $id <= 0 ) {
			return null;
		}

		$table = $this->table();
		$row   = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
			array( $id )
		);

		return $this->row_to_card( $row );
	}

	public function find_store_credit_wallet( int $customer_id, string $currency ): ?GiftCard {
		if ( $customer_id <= 0 ) {
			return null;
		}

		$currency = strtoupper( trim( $currency ) );
		if ( $currency === '' ) {
			return null;
		}

		$table = $this->table();
		$row   = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE source_type = %s AND owner_customer_id = %d AND currency = %s LIMIT 1",
			array( GiftCard::SOURCE_STORE_CREDIT, $customer_id, $currency )
		);

		return $this->row_to_card( $row );
	}

	public function find_by_plain_code( string $plain_code ): ?GiftCard {
		$hash = self::hash_plain_code( $plain_code );
		if ( strlen( $hash ) !== 64 ) {
			return null;
		}

		$table = $this->table();
		$row   = DbQuery::get_row(
			$this->wpdb,
			"SELECT * FROM {$table} WHERE code_hash = %s LIMIT 1",
			array( $hash )
		);

		return $this->row_to_card( $row );
	}

	/**
	 * @return list<GiftCard>
	 */
	public function list_recent( int $limit = 50, int $offset = 0, ?string $source_type = null ): array {
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		$table  = $this->table();

		if ( $source_type !== null && $source_type !== '' ) {
			$rows = DbQuery::get_results(
				$this->wpdb,
				"SELECT * FROM {$table} WHERE source_type = %s ORDER BY id DESC LIMIT %d OFFSET %d",
				array( $source_type, $limit, $offset )
			);
		} else {
			$rows = DbQuery::get_results(
				$this->wpdb,
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				array( $limit, $offset )
			);
		}

		$out = array();
		foreach ( $rows as $row ) {
			$card = $this->row_to_card( $row );
			if ( $card !== null ) {
				$out[] = $card;
			}
		}

		return $out;
	}

	public function count_all(): int {
		$table = $this->table();
		$count = DbQuery::get_var( $this->wpdb, "SELECT COUNT(*) FROM {$table}" );

		return max( 0, (int) $count );
	}

	/**
	 * @return list<GiftCard>
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
			$card = $this->row_to_card( $row );
			if ( $card !== null ) {
				$out[] = $card;
			}
		}

		return $out;
	}

	/**
	 * @param array{source_type?: ?string, created_order_id?: ?int, manual_only?: bool, product_order_only?: bool} $filters
	 * @return list<GiftCard>
	 */
	public function list_filtered( array $filters, int $limit = 50, int $offset = 0 ): array {
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		$table  = $this->table();

		$where  = array( '1=1' );
		$params = array();

		if ( isset( $filters['source_type'] ) && $filters['source_type'] !== null && $filters['source_type'] !== '' ) {
			$where[]  = 'source_type = %s';
			$params[] = $filters['source_type'];
		}

		if ( isset( $filters['created_order_id'] ) && $filters['created_order_id'] !== null && (int) $filters['created_order_id'] > 0 ) {
			$where[]  = 'created_order_id = %d';
			$params[] = (int) $filters['created_order_id'];
		}

		if ( ! empty( $filters['manual_only'] ) ) {
			$where[] = 'source_type = %s AND created_order_id IS NULL';
			$params[] = GiftCard::SOURCE_GIFT_CARD;
		}

		if ( ! empty( $filters['product_order_only'] ) ) {
			$where[] = 'source_type = %s AND created_order_id IS NOT NULL';
			$params[] = GiftCard::SOURCE_GIFT_CARD;
		}

		$params[] = $limit;
		$params[] = $offset;

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		$rows = DbQuery::get_results( $this->wpdb, $sql, $params );

		$out = array();
		foreach ( $rows as $row ) {
			$card = $this->row_to_card( $row );
			if ( $card !== null ) {
				$out[] = $card;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_card( ?array $row ): ?GiftCard {
		if ( $row === null ) {
			return null;
		}

		try {
			return new GiftCard(
				isset( $row['id'] ) ? (int) $row['id'] : null,
				(string) ( $row['gift_card_uuid'] ?? '' ),
				(string) ( $row['code_hash'] ?? '' ),
				(string) ( $row['code_last4'] ?? '' ),
				(float) ( $row['initial_amount'] ?? 0 ),
				(float) ( $row['balance'] ?? 0 ),
				(string) ( $row['currency'] ?? '' ),
				(string) ( $row['status'] ?? GiftCard::STATUS_ACTIVE ),
				isset( $row['expires_at'] ) && $row['expires_at'] !== '' ? (string) $row['expires_at'] : null,
				isset( $row['created_order_id'] ) && $row['created_order_id'] !== '' && $row['created_order_id'] !== null
					? (int) $row['created_order_id'] : null,
				isset( $row['purchaser_customer_id'] ) && $row['purchaser_customer_id'] !== '' && $row['purchaser_customer_id'] !== null
					? (int) $row['purchaser_customer_id'] : null,
				isset( $row['recipient_email'] ) && $row['recipient_email'] !== '' ? (string) $row['recipient_email'] : null,
				isset( $row['created_at'] ) ? (string) $row['created_at'] : null,
				isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null,
				isset( $row['source_type'] ) && $row['source_type'] !== ''
					? (string) $row['source_type']
					: GiftCard::SOURCE_GIFT_CARD,
				isset( $row['owner_customer_id'] ) && $row['owner_customer_id'] !== '' && $row['owner_customer_id'] !== null
					? (int) $row['owner_customer_id'] : null,
				isset( $row['label'] ) && $row['label'] !== '' ? (string) $row['label'] : null
			);
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	private function table(): string {
		$table = Schema::gift_cards_table( $this->wpdb );

		return TableName::assert_valid( $table );
	}
}
