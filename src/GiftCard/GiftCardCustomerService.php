<?php
/**
 * Customer-facing gift card listings (masked codes only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Infrastructure\Database\TableName;
use wpdb;

final class GiftCardCustomerService {

	private GiftCardRepository $cards;

	private wpdb $wpdb;

	public function __construct( GiftCardRepository $cards, wpdb $wpdb ) {
		$this->cards = $cards;
		$this->wpdb  = $wpdb;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list_purchased( int $customer_id, int $limit = 50 ): array {
		if ( $customer_id <= 0 ) {
			return array();
		}

		return $this->map_cards(
			$this->query_cards(
				'purchaser_customer_id = %d AND source_type = %s',
				array( $customer_id, GiftCard::SOURCE_GIFT_CARD ),
				$limit
			),
			'purchased'
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list_received( string $email, int $limit = 50 ): array {
		$email = sanitize_email( $email );
		if ( $email === '' || ! is_email( $email ) ) {
			return array();
		}

		return $this->map_cards(
			$this->query_cards(
				'recipient_email = %s AND source_type = %s',
				array( $email, GiftCard::SOURCE_GIFT_CARD ),
				$limit
			),
			'received'
		);
	}

	/**
	 * @param list<GiftCard> $cards
	 * @return list<array<string, mixed>>
	 */
	private function map_cards( array $cards, string $role ): array {
		$out = array();
		foreach ( $cards as $card ) {
			$delivery = $this->delivery_meta_for_card( $card );
			$out[]    = array(
				'role'           => $role,
				'gift_card_id'   => $card->get_id(),
				'masked_code'    => '****' . $card->get_code_last4(),
				'balance'        => GiftCard::money( $card->get_balance() ),
				'initial_amount' => GiftCard::money( $card->get_initial_amount() ),
				'currency'       => $card->get_currency(),
				'status'         => $card->get_status(),
				'status_label'   => self::status_label( $card ),
				'expires_at'     => $card->get_expires_at(),
				'created_at'     => $card->get_created_at(),
				'order_id'       => $card->get_created_order_id(),
				'delivery_status'=> (string) ( $delivery['delivery_status'] ?? '' ),
				'delivery_label' => self::format_delivery_label( $delivery ),
				'delivered_at'   => (string) ( $delivery['delivered_at'] ?? '' ),
				'scheduled_for'  => (string) ( $delivery['scheduled_for'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * @return list<GiftCard>
	 */
	private function query_cards( string $where_sql, array $params, int $limit ): array {
		$limit  = max( 1, min( 100, $limit ) );
		$table  = TableName::assert_valid( Schema::gift_cards_table( $this->wpdb ) );
		$params[] = $limit;
		$sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d";
		$rows   = DbQuery::get_results( $this->wpdb, $sql, $params );

		$out = array();
		foreach ( $rows as $row ) {
			$card = $this->cards->find( (int) ( $row['id'] ?? 0 ) );
			if ( $card !== null && ! $card->is_store_credit_wallet() ) {
				$out[] = $card;
			}
		}

		return $out;
	}

	/**
	 * @return array{delivery_status?: string, delivered_at?: string, scheduled_for?: string}
	 */
	private function delivery_meta_for_card( GiftCard $card ): array {
		$order_id = $card->get_created_order_id();
		$card_id  = $card->get_id();
		if ( $order_id === null || $order_id <= 0 || $card_id === null ) {
			return array();
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) ) {
			return array();
		}

		foreach ( GiftCardGeneratedOrderState::get_generated( $order ) as $row ) {
			if ( (int) ( $row['gift_card_id'] ?? 0 ) === $card_id ) {
				return array(
					'delivery_status' => (string) ( $row['delivery_status'] ?? '' ),
					'delivered_at'    => (string) ( $row['delivered_at'] ?? '' ),
				);
			}
		}

		foreach ( GiftCardPendingDeliveryState::get_pending( $order ) as $row ) {
			if ( (int) ( $row['gift_card_id'] ?? 0 ) === $card_id ) {
				return array(
					'delivery_status' => (string) ( $row['delivery_status'] ?? '' ),
					'scheduled_for'   => (string) ( $row['scheduled_for'] ?? '' ),
				);
			}
		}

		return array();
	}

	/**
	 * @param array{delivery_status?: string, delivered_at?: string, scheduled_for?: string} $delivery
	 */
	public static function format_delivery_label( array $delivery ): string {
		$status = (string) ( $delivery['delivery_status'] ?? '' );
		$at     = (string) ( $delivery['delivered_at'] ?? '' );
		$when   = (string) ( $delivery['scheduled_for'] ?? '' );

		switch ( $status ) {
			case GiftCardDeliveryStatus::SENT:
				return $at !== ''
					? sprintf(
						/* translators: %s: delivery datetime */
						__( 'Email sent %s', 'mp-commerce-promotions' ),
						$at
					)
					: __( 'Email sent', 'mp-commerce-promotions' );
			case GiftCardDeliveryStatus::FAILED:
				return __( 'Email delivery failed', 'mp-commerce-promotions' );
			case GiftCardDeliveryStatus::DISABLED:
				return __( 'Email delivery disabled', 'mp-commerce-promotions' );
			case GiftCardDeliveryStatus::PENDING_SCHEDULED:
				return $when !== ''
					? sprintf(
						/* translators: %s: scheduled date */
						__( 'Scheduled for %s', 'mp-commerce-promotions' ),
						$when
					)
					: __( 'Scheduled delivery pending', 'mp-commerce-promotions' );
			case GiftCardDeliveryStatus::PENDING:
				return __( 'Sending email…', 'mp-commerce-promotions' );
			case GiftCardDeliveryStatus::CANCELLED:
				return __( 'Delivery cancelled', 'mp-commerce-promotions' );
			default:
				if ( $when !== '' ) {
					return sprintf(
						/* translators: %s: scheduled date */
						__( 'Scheduled for %s', 'mp-commerce-promotions' ),
						$when
					);
				}
				return $status !== '' ? ucfirst( str_replace( '_', ' ', $status ) ) : '—';
		}
	}

	public static function status_label( GiftCard $card ): string {
		$status = $card->get_status();
		switch ( $status ) {
			case GiftCard::STATUS_ACTIVE:
				return $card->can_redeem( 0.01, function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) )
					? __( 'Active', 'mp-commerce-promotions' )
					: __( 'Not redeemable', 'mp-commerce-promotions' );
			case GiftCard::STATUS_DEPLETED:
				return __( 'Fully used', 'mp-commerce-promotions' );
			case GiftCard::STATUS_EXPIRED:
				return __( 'Expired', 'mp-commerce-promotions' );
			case GiftCard::STATUS_VOIDED:
				return __( 'Voided', 'mp-commerce-promotions' );
			default:
				return ucfirst( $status );
		}
	}
}
