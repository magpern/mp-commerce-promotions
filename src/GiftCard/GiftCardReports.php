<?php
/**
 * Read-only gift card and store credit summary metrics for Reports.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Infrastructure\Database\TableName;
use wpdb;

final class GiftCardReports {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * @return array{
	 *   active_outstanding_liability: float,
	 *   gift_card_outstanding_liability: float,
	 *   store_credit_outstanding_liability: float,
	 *   combined_outstanding_liability: float,
	 *   total_issued: float,
	 *   total_redeemed: float,
	 *   total_adjusted: float,
	 *   total_voided: float,
	 *   store_credit_issued: float,
	 *   store_credit_redeemed: float,
	 *   refund_to_credit_total: float,
	 *   manual_adjustment_total: float,
	 *   depleted_count: int,
	 *   expired_count: int
	 * }
	 */
	public function summary(): array {
		$cards_table = $this->cards_table();
		$tx_table    = $this->transactions_table();

		$gift_liability = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(balance), 0) FROM {$cards_table} WHERE status = %s AND source_type = %s",
			array( GiftCard::STATUS_ACTIVE, GiftCard::SOURCE_GIFT_CARD )
		);

		$store_liability = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(balance), 0) FROM {$cards_table} WHERE status = %s AND source_type = %s",
			array( GiftCard::STATUS_ACTIVE, GiftCard::SOURCE_STORE_CREDIT )
		);

		$combined_liability = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(balance), 0) FROM {$cards_table} WHERE status = %s",
			array( GiftCard::STATUS_ACTIVE )
		);

		$depleted = (int) DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$cards_table} WHERE status = %s AND source_type = %s",
			array( GiftCard::STATUS_DEPLETED, GiftCard::SOURCE_GIFT_CARD )
		);

		$expired = (int) DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$cards_table} WHERE status = %s AND source_type = %s",
			array( GiftCard::STATUS_EXPIRED, GiftCard::SOURCE_GIFT_CARD )
		);

		$issued = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(t.amount), 0) FROM {$tx_table} t
			INNER JOIN {$cards_table} c ON c.id = t.gift_card_id
			WHERE t.transaction_type = %s AND c.source_type = %s",
			array( GiftCardTransaction::TYPE_ISSUED, GiftCard::SOURCE_GIFT_CARD )
		);

		$store_issued = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(t.amount), 0) FROM {$tx_table} t
			INNER JOIN {$cards_table} c ON c.id = t.gift_card_id
			WHERE t.transaction_type = %s AND c.source_type = %s AND t.amount > 0",
			array( GiftCardTransaction::TYPE_ISSUED, GiftCard::SOURCE_STORE_CREDIT )
		);

		$redeemed = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(ABS(t.amount)), 0) FROM {$tx_table} t
			INNER JOIN {$cards_table} c ON c.id = t.gift_card_id
			WHERE t.transaction_type = %s AND c.source_type = %s",
			array( GiftCardTransaction::TYPE_REDEEMED, GiftCard::SOURCE_GIFT_CARD )
		);

		$store_redeemed = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(ABS(t.amount)), 0) FROM {$tx_table} t
			INNER JOIN {$cards_table} c ON c.id = t.gift_card_id
			WHERE t.transaction_type = %s AND c.source_type = %s",
			array( GiftCardTransaction::TYPE_REDEEMED, GiftCard::SOURCE_STORE_CREDIT )
		);

		$adjusted = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(t.amount), 0) FROM {$tx_table} t
			INNER JOIN {$cards_table} c ON c.id = t.gift_card_id
			WHERE t.transaction_type = %s AND c.source_type = %s",
			array( GiftCardTransaction::TYPE_ADJUSTED, GiftCard::SOURCE_GIFT_CARD )
		);

		$manual_adjustment = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(t.amount), 0) FROM {$tx_table} t
			INNER JOIN {$cards_table} c ON c.id = t.gift_card_id
			WHERE t.transaction_type = %s AND c.source_type = %s",
			array( GiftCardTransaction::TYPE_ADJUSTED, GiftCard::SOURCE_STORE_CREDIT )
		);

		$refund_to_credit = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(t.amount), 0) FROM {$tx_table} t
			INNER JOIN {$cards_table} c ON c.id = t.gift_card_id
			WHERE t.transaction_type = %s AND c.source_type = %s",
			array( GiftCardTransaction::TYPE_REFUND_TO_CREDIT, GiftCard::SOURCE_STORE_CREDIT )
		);

		$voided = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(ABS(t.amount)), 0) FROM {$tx_table} t
			INNER JOIN {$cards_table} c ON c.id = t.gift_card_id
			WHERE t.transaction_type = %s AND c.source_type = %s",
			array( GiftCardTransaction::TYPE_VOIDED, GiftCard::SOURCE_GIFT_CARD )
		);

		return array(
			'active_outstanding_liability'      => GiftCard::money( $combined_liability ),
			'gift_card_outstanding_liability'   => GiftCard::money( $gift_liability ),
			'store_credit_outstanding_liability' => GiftCard::money( $store_liability ),
			'combined_outstanding_liability'    => GiftCard::money( $combined_liability ),
			'total_issued'                      => GiftCard::money( $issued ),
			'total_redeemed'                    => GiftCard::money( $redeemed ),
			'total_adjusted'                    => GiftCard::money( $adjusted ),
			'total_voided'                      => GiftCard::money( $voided ),
			'store_credit_issued'               => GiftCard::money( $store_issued ),
			'store_credit_redeemed'             => GiftCard::money( $store_redeemed ),
			'refund_to_credit_total'            => GiftCard::money( $refund_to_credit ),
			'manual_adjustment_total'           => GiftCard::money( $manual_adjustment ),
			'depleted_count'                    => max( 0, $depleted ),
			'expired_count'                     => max( 0, $expired ),
		);
	}

	private function cards_table(): string {
		return TableName::assert_valid( Schema::gift_cards_table( $this->wpdb ) );
	}

	private function transactions_table(): string {
		return TableName::assert_valid( Schema::gift_card_transactions_table( $this->wpdb ) );
	}
}
