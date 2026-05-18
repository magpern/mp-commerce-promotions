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
	 *   expired_count: int,
	 *   liability_by_currency: list<array{currency: string, gift_card_liability: float, store_credit_liability: float, combined_liability: float}>,
	 *   gift_cards_sold_from_products: int,
	 *   product_generated_liability: float,
	 *   product_generated_issued_total: float,
	 *   manually_issued_total: float,
	 *   gift_cards_delivery_sent: int,
	 *   gift_cards_delivery_failed: int,
	 *   gift_cards_delivery_disabled: int,
	 *   gift_cards_delivery_unknown: int,
	 *   scheduled_pending: int,
	 *   scheduled_sent: int,
	 *   scheduled_failed: int,
	 *   scheduled_cancelled: int
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

		$product_sold_count = (int) DbQuery::get_var(
			$this->wpdb,
			"SELECT COUNT(*) FROM {$cards_table} WHERE source_type = %s AND created_order_id IS NOT NULL",
			array( GiftCard::SOURCE_GIFT_CARD )
		);

		$product_generated_liability = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(balance), 0) FROM {$cards_table}
			WHERE status = %s AND source_type = %s AND created_order_id IS NOT NULL",
			array( GiftCard::STATUS_ACTIVE, GiftCard::SOURCE_GIFT_CARD )
		);

		$product_issued_total = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(t.amount), 0) FROM {$tx_table} t
			INNER JOIN {$cards_table} c ON c.id = t.gift_card_id
			WHERE t.transaction_type = %s AND c.source_type = %s AND c.created_order_id IS NOT NULL",
			array( GiftCardTransaction::TYPE_ISSUED, GiftCard::SOURCE_GIFT_CARD )
		);

		$manual_issued_total = (float) DbQuery::get_var(
			$this->wpdb,
			"SELECT COALESCE(SUM(t.amount), 0) FROM {$tx_table} t
			INNER JOIN {$cards_table} c ON c.id = t.gift_card_id
			WHERE t.transaction_type = %s AND c.source_type = %s AND c.created_order_id IS NULL",
			array( GiftCardTransaction::TYPE_ISSUED, GiftCard::SOURCE_GIFT_CARD )
		);

		$delivery = GiftCardDeliverySummary::from_wpdb( $this->wpdb, 500 );

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
			'liability_by_currency'             => $this->liability_by_currency(),
			'gift_cards_sold_from_products'     => max( 0, $product_sold_count ),
			'product_generated_liability'       => GiftCard::money( $product_generated_liability ),
			'product_generated_issued_total'    => GiftCard::money( $product_issued_total ),
			'manually_issued_total'             => GiftCard::money( $manual_issued_total ),
			'gift_cards_delivery_sent'          => max( 0, (int) ( $delivery['generated_sent'] ?? 0 ) ),
			'gift_cards_delivery_failed'        => max( 0, (int) ( $delivery['delivery_failed'] ?? 0 ) ),
			'gift_cards_delivery_disabled'      => max( 0, (int) ( $delivery['delivery_disabled'] ?? 0 ) ),
			'gift_cards_delivery_unknown'         => max( 0, (int) ( $delivery['delivery_unknown'] ?? 0 ) ),
			'scheduled_pending'                   => max( 0, (int) ( $delivery['scheduled_pending'] ?? 0 ) ),
			'scheduled_sent'                      => max( 0, (int) ( $delivery['scheduled_sent'] ?? 0 ) ),
			'scheduled_failed'                    => max( 0, (int) ( $delivery['scheduled_failed'] ?? 0 ) ),
			'scheduled_cancelled'                 => max( 0, (int) ( $delivery['scheduled_cancelled'] ?? 0 ) ),
		);
	}

	/**
	 * Active outstanding liability grouped by currency (do not sum across currencies).
	 *
	 * @return list<array{currency: string, gift_card_liability: float, store_credit_liability: float, combined_liability: float}>
	 */
	public function liability_by_currency(): array {
		$table = $this->cards_table();

		$rows = DbQuery::get_results(
			$this->wpdb,
			"SELECT currency, source_type, COALESCE(SUM(balance), 0) AS liability
			FROM {$table}
			WHERE status = %s
			GROUP BY currency, source_type
			ORDER BY currency ASC",
			array( GiftCard::STATUS_ACTIVE )
		);

		/** @var array<string, array{currency: string, gift_card_liability: float, store_credit_liability: float, combined_liability: float}> $by_currency */
		$by_currency = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$currency = GiftCardCurrency::normalize( (string) ( $row['currency'] ?? '' ) );
			if ( $currency === '' ) {
				continue;
			}

			if ( ! isset( $by_currency[ $currency ] ) ) {
				$by_currency[ $currency ] = array(
					'currency'                => $currency,
					'gift_card_liability'     => 0.0,
					'store_credit_liability'  => 0.0,
					'combined_liability'      => 0.0,
				);
			}

			$amount = GiftCard::money( (float) ( $row['liability'] ?? 0 ) );
			$source = (string) ( $row['source_type'] ?? GiftCard::SOURCE_GIFT_CARD );

			if ( $source === GiftCard::SOURCE_STORE_CREDIT ) {
				$by_currency[ $currency ]['store_credit_liability'] = GiftCard::money(
					$by_currency[ $currency ]['store_credit_liability'] + $amount
				);
			} else {
				$by_currency[ $currency ]['gift_card_liability'] = GiftCard::money(
					$by_currency[ $currency ]['gift_card_liability'] + $amount
				);
			}

			$by_currency[ $currency ]['combined_liability'] = GiftCard::money(
				$by_currency[ $currency ]['gift_card_liability'] + $by_currency[ $currency ]['store_credit_liability']
			);
		}

		return array_values( $by_currency );
	}

	private function cards_table(): string {
		return TableName::assert_valid( Schema::gift_cards_table( $this->wpdb ) );
	}

	private function transactions_table(): string {
		return TableName::assert_valid( Schema::gift_card_transactions_table( $this->wpdb ) );
	}
}
