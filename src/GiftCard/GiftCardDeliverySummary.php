<?php
/**
 * Aggregate gift card delivery stats from order meta (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use wpdb;

final class GiftCardDeliverySummary {

	/**
	 * @return array{
	 *   generated_sent: int,
	 *   delivery_failed: int,
	 *   delivery_disabled: int,
	 *   delivery_unknown: int,
	 *   orders_with_plain_code: list<array{order_id: int}>,
	 *   delivery_failed_rows: list<array{order_id: int, gift_card_id: int}>,
	 *   delivery_disabled_rows: list<array{order_id: int, gift_card_id: int}>,
	 *   missing_delivery_status_rows: list<array{order_id: int, gift_card_id: int}>
	 * }
	 */
	public static function from_wpdb( wpdb $wpdb, int $limit = 500 ): array {
		$limit = max( 1, min( 2000, $limit ) );

		$meta_rows = DbQuery::get_results(
			$wpdb,
			"SELECT post_id, meta_value FROM {$wpdb->postmeta}
			WHERE meta_key = %s
			ORDER BY meta_id DESC
			LIMIT %d",
			array( GiftCardGeneratedOrderState::META_GENERATED, $limit )
		);

		$counts = array(
			'generated_sent'      => 0,
			'delivery_failed'     => 0,
			'delivery_disabled'   => 0,
			'delivery_unknown'    => 0,
		);

		$orders_with_plain_code  = array();
		$delivery_failed_rows    = array();
		$delivery_disabled_rows  = array();
		$missing_delivery_rows   = array();
		$plain_code_orders_seen  = array();

		foreach ( $meta_rows as $meta_row ) {
			if ( ! is_array( $meta_row ) ) {
				continue;
			}
			$order_id = (int) ( $meta_row['post_id'] ?? 0 );
			$raw      = (string) ( $meta_row['meta_value'] ?? '' );
			if ( $order_id <= 0 || $raw === '' ) {
				continue;
			}

			if ( str_contains( $raw, '"plain_code"' ) && ! isset( $plain_code_orders_seen[ $order_id ] ) ) {
				$plain_code_orders_seen[ $order_id ] = true;
				$orders_with_plain_code[]            = array( 'order_id' => $order_id );
			}

			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			foreach ( $decoded as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$gift_card_id = (int) ( $row['gift_card_id'] ?? 0 );
				$status       = isset( $row['delivery_status'] ) ? (string) $row['delivery_status'] : '';

				if ( $status === '' ) {
					++$counts['delivery_unknown'];
					if ( count( $missing_delivery_rows ) < 50 ) {
						$missing_delivery_rows[] = array(
							'order_id'     => $order_id,
							'gift_card_id' => $gift_card_id,
						);
					}
					continue;
				}

				if ( $status === GiftCardDeliveryStatus::SENT ) {
					++$counts['generated_sent'];
				} elseif ( $status === GiftCardDeliveryStatus::FAILED ) {
					++$counts['delivery_failed'];
					if ( count( $delivery_failed_rows ) < 50 ) {
						$delivery_failed_rows[] = array(
							'order_id'     => $order_id,
							'gift_card_id' => $gift_card_id,
						);
					}
				} elseif ( $status === GiftCardDeliveryStatus::DISABLED ) {
					++$counts['delivery_disabled'];
					if ( count( $delivery_disabled_rows ) < 50 ) {
						$delivery_disabled_rows[] = array(
							'order_id'     => $order_id,
							'gift_card_id' => $gift_card_id,
						);
					}
				} elseif ( $status === GiftCardDeliveryStatus::UNKNOWN ) {
					++$counts['delivery_unknown'];
				}
			}
		}

		$scheduled = self::scan_pending_meta( $wpdb, $limit );

		return array_merge(
			array(
				'generated_sent'               => $counts['generated_sent'],
				'delivery_failed'              => $counts['delivery_failed'],
				'delivery_disabled'            => $counts['delivery_disabled'],
				'delivery_unknown'             => $counts['delivery_unknown'],
				'orders_with_plain_code'       => $orders_with_plain_code,
				'delivery_failed_rows'         => $delivery_failed_rows,
				'delivery_disabled_rows'       => $delivery_disabled_rows,
				'missing_delivery_status_rows' => $missing_delivery_rows,
			),
			$scheduled
		);
	}

	/**
	 * @return array{
	 *   scheduled_pending: int,
	 *   scheduled_sent: int,
	 *   scheduled_failed: int,
	 *   scheduled_cancelled: int
	 * }
	 */
	public static function scan_pending_meta( wpdb $wpdb, int $limit = 500 ): array {
		$limit = max( 1, min( 2000, $limit ) );

		$counts = array(
			'scheduled_pending'   => 0,
			'scheduled_sent'      => 0,
			'scheduled_failed'    => 0,
			'scheduled_cancelled' => 0,
		);

		$meta_rows = DbQuery::get_results(
			$wpdb,
			"SELECT post_id, meta_value FROM {$wpdb->postmeta}
			WHERE meta_key = %s
			ORDER BY meta_id DESC
			LIMIT %d",
			array( GiftCardPendingDeliveryState::META_PENDING, $limit )
		);

		foreach ( $meta_rows as $meta_row ) {
			if ( ! is_array( $meta_row ) ) {
				continue;
			}
			$raw = (string) ( $meta_row['meta_value'] ?? '' );
			if ( $raw === '' ) {
				continue;
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$status = (string) ( $row['delivery_status'] ?? '' );
				if ( $status === GiftCardDeliveryStatus::PENDING_SCHEDULED ) {
					++$counts['scheduled_pending'];
				} elseif ( $status === GiftCardDeliveryStatus::SENT ) {
					++$counts['scheduled_sent'];
				} elseif ( $status === GiftCardDeliveryStatus::FAILED ) {
					++$counts['scheduled_failed'];
				} elseif ( $status === GiftCardDeliveryStatus::CANCELLED ) {
					++$counts['scheduled_cancelled'];
				}
			}
		}

		return $counts;
	}
}
