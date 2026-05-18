<?php
/**
 * Diagnostics for gift cards sold via WooCommerce products.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Infrastructure\Database\TableName;
use wpdb;

final class GiftCardProductDiagnostics {

	private wpdb $wpdb;

	private GiftCardRepository $cards;

	private GiftCardLedger $ledger;

	private ?GiftCardOrderGenerator $generator;

	private ?GiftCardOrderReversal $reversal;

	public function __construct(
		wpdb $wpdb,
		GiftCardRepository $cards,
		GiftCardLedger $ledger,
		?GiftCardOrderGenerator $generator = null,
		?GiftCardOrderReversal $reversal = null
	) {
		$this->wpdb      = $wpdb;
		$this->cards     = $cards;
		$this->ledger    = $ledger;
		$this->generator = $generator;
		$this->reversal  = $reversal;
	}

	/**
	 * @return array{
	 *   paid_orders_missing_generation: list<array<string, mixed>>,
	 *   product_cards_missing_order_id: list<array<string, mixed>>,
	 *   cancelled_orders_active_unused_cards: list<array<string, mixed>>
	 * }
	 */
	public function analyze(): array {
		return array(
			'paid_orders_missing_generation'       => $this->find_paid_orders_missing_generation(),
			'product_cards_missing_order_id'       => $this->find_product_cards_missing_order_id(),
			'cancelled_orders_active_unused_cards'  => $this->find_cancelled_orders_active_unused(),
		);
	}

	/**
	 * @return array{generated: int, voided: int}
	 */
	public function repair( bool $apply = false ): array {
		$result = array(
			'generated' => 0,
			'voided'    => 0,
		);

		$issues = $this->analyze();

		if ( $apply && $this->generator !== null ) {
			foreach ( $issues['paid_orders_missing_generation'] as $row ) {
				$order_id = (int) ( $row['order_id'] ?? 0 );
				if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
					continue;
				}
				$order = wc_get_order( $order_id );
				if ( is_object( $order ) && is_a( $order, 'WC_Order', false ) ) {
					$this->generator->generate_for_order( $order );
					++$result['generated'];
				}
			}
		}

		if ( $apply && $this->reversal !== null ) {
			foreach ( $issues['cancelled_orders_active_unused_cards'] as $row ) {
				$order_id = (int) ( $row['order_id'] ?? 0 );
				if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
					continue;
				}
				$order = wc_get_order( $order_id );
				if ( is_object( $order ) && is_a( $order, 'WC_Order', false ) ) {
					$this->reversal->handle_order_reversal( $order );
					++$result['voided'];
				}
			}
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function find_paid_orders_missing_generation(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'status'       => array( 'processing', 'completed' ),
				'limit'        => 50,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'meta_query'   => array(
					array(
						'key'     => GiftCardGeneratedOrderState::META_GENERATION_COMPLETE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$products = new GiftCardProductService();
		$missing  = array();

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				continue;
			}
			$has_gift_product = false;
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! is_object( $item ) || ! is_a( $item, 'WC_Order_Item_Product', false ) ) {
					continue;
				}
				$config = $products->get_line_config( (int) $item->get_product_id(), (int) $item->get_variation_id() );
				if ( $config !== null ) {
					$has_gift_product = true;
					break;
				}
			}
			if ( $has_gift_product ) {
				$missing[] = array(
					'order_id' => (int) $order->get_id(),
					'status'   => $order->get_status(),
				);
			}
		}

		return $missing;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function find_product_cards_missing_order_id(): array {
		$table = $this->cards_table();

		return DbQuery::get_results(
			$this->wpdb,
			"SELECT id, code_last4, balance FROM {$table}
			WHERE source_type = %s AND created_order_id IS NULL AND code_last4 != %s
			LIMIT 50",
			array( GiftCard::SOURCE_GIFT_CARD, GiftCard::WALLET_CODE_LAST4 )
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function find_cancelled_orders_active_unused(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'status' => array( 'cancelled', 'refunded', 'failed' ),
				'limit'  => 50,
				'meta_query' => array(
					array(
						'key'   => GiftCardGeneratedOrderState::META_GENERATION_COMPLETE,
						'value' => GiftCardGeneratedOrderState::META_VALUE_YES,
					),
					array(
						'key'     => GiftCardGeneratedOrderState::META_REVERSAL_HANDLED,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$out = array();
		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				continue;
			}
			foreach ( GiftCardGeneratedOrderState::get_generated( $order ) as $row ) {
				$card = $this->cards->find( (int) $row['gift_card_id'] );
				if ( $card === null ) {
					continue;
				}
				if (
					$card->get_status() === GiftCard::STATUS_ACTIVE
					&& abs( $card->get_balance() - $card->get_initial_amount() ) < 0.009
					&& $card->get_balance() > 0
				) {
					$out[] = array(
						'order_id'     => (int) $order->get_id(),
						'gift_card_id' => (int) $row['gift_card_id'],
					);
				}
			}
		}

		return $out;
	}

	private function cards_table(): string {
		return TableName::assert_valid( Schema::gift_cards_table( $this->wpdb ) );
	}
}
