<?php
/**
 * Read-only gift card summary metrics for Reports.
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
	 *   total_issued: float,
	 *   total_redeemed: float,
	 *   total_adjusted: float,
	 *   total_voided: float,
	 *   depleted_count: int,
	 *   expired_count: int
	 * }
	 */
	public function summary(): array {
		$cards_table = $this->cards_table();
		$tx_table    = $this->transactions_table();

		$liability = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(balance), 0) FROM {$cards_table} WHERE status = %s",
			array( GiftCard::STATUS_ACTIVE )
		);

		$depleted = (int) DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$cards_table} WHERE status = %s",
			array( GiftCard::STATUS_DEPLETED )
		);

		$expired = (int) DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$cards_table} WHERE status = %s",
			array( GiftCard::STATUS_EXPIRED )
		);

		$issued = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(amount), 0) FROM {$tx_table} WHERE transaction_type = %s",
			array( GiftCardTransaction::TYPE_ISSUED )
		);

		$redeemed = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(ABS(amount)), 0) FROM {$tx_table} WHERE transaction_type = %s",
			array( GiftCardTransaction::TYPE_REDEEMED )
		);

		$adjusted = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(amount), 0) FROM {$tx_table} WHERE transaction_type = %s",
			array( GiftCardTransaction::TYPE_ADJUSTED )
		);

		$voided = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(ABS(amount)), 0) FROM {$tx_table} WHERE transaction_type = %s",
			array( GiftCardTransaction::TYPE_VOIDED )
		);

		return array(
			'active_outstanding_liability' => GiftCard::money( $liability ),
			'total_issued'                 => GiftCard::money( $issued ),
			'total_redeemed'               => GiftCard::money( $redeemed ),
			'total_adjusted'               => GiftCard::money( $adjusted ),
			'total_voided'                 => GiftCard::money( $voided ),
			'depleted_count'               => max( 0, $depleted ),
			'expired_count'                => max( 0, $expired ),
		);
	}

	private function cards_table(): string {
		return TableName::assert_valid( Schema::gift_cards_table( $this->wpdb ) );
	}

	private function transactions_table(): string {
		return TableName::assert_valid( Schema::gift_card_transactions_table( $this->wpdb ) );
	}
}
