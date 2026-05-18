<?php
/**
 * Diagnostics for gift card delivery security and legacy order meta.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Infrastructure\Database\DbQuery;
use wpdb;

final class GiftCardDeliveryDiagnostics {

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * @return array{
	 *   orders_with_plain_code: list<array{order_id: int}>,
	 *   delivery_failed: list<array{order_id: int, gift_card_id: int}>,
	 *   delivery_disabled: list<array{order_id: int, gift_card_id: int}>,
	 *   missing_delivery_status: list<array{order_id: int, gift_card_id: int}>
	 * }
	 */
	public function analyze(): array {
		$summary = GiftCardDeliverySummary::from_wpdb( $this->wpdb, 200 );

		return array(
			'orders_with_plain_code'  => $summary['orders_with_plain_code'],
			'delivery_failed'         => $summary['delivery_failed_rows'],
			'delivery_disabled'       => $summary['delivery_disabled_rows'],
			'missing_delivery_status' => $summary['missing_delivery_status_rows'],
		);
	}

	/**
	 * @return array{plain_code_removed: int, legacy_status_marked: int}
	 */
	public function repair( bool $apply = false ): array {
		$result = array(
			'plain_code_removed'  => 0,
			'legacy_status_marked' => 0,
		);

		$meta_rows = $this->fetch_meta_rows( 500 );
		foreach ( $meta_rows as $meta_row ) {
			if ( ! is_array( $meta_row ) ) {
				continue;
			}
			$order_id = (int) ( $meta_row['post_id'] ?? 0 );
			$raw      = (string) ( $meta_row['meta_value'] ?? '' );
			if ( $order_id <= 0 || $raw === '' ) {
				continue;
			}

			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$changed = false;
			$cleaned = array();
			foreach ( $decoded as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( GiftCardGeneratedOrderState::row_contains_plain_code( $row ) ) {
					$changed = true;
					++$result['plain_code_removed'];
				}
				if ( ! isset( $row['delivery_status'] ) || (string) $row['delivery_status'] === '' ) {
					$row['delivery_status'] = GiftCardDeliveryStatus::UNKNOWN;
					$changed                = true;
					++$result['legacy_status_marked'];
				}
				$sanitized = GiftCardGeneratedOrderState::sanitize_row_for_storage( $row );
				if ( $sanitized !== null ) {
					$cleaned[] = $sanitized;
				}
			}

			if ( $changed && $apply ) {
				$this->update_order_meta( $order_id, $cleaned );
			}
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fetch_meta_rows( int $limit ): array {
		$limit = max( 1, min( 1000, $limit ) );

		return DbQuery::get_results(
			$this->wpdb,
			"SELECT post_id, meta_value FROM {$this->wpdb->postmeta}
			WHERE meta_key = %s
			ORDER BY meta_id DESC
			LIMIT %d",
			array( GiftCardGeneratedOrderState::META_GENERATED, $limit )
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function update_order_meta( int $order_id, array $rows ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( is_object( $order ) && is_a( $order, 'WC_Order', false ) ) {
			GiftCardGeneratedOrderState::set_generated( $order, $rows );
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
			return;
		}

		update_post_meta( $order_id, GiftCardGeneratedOrderState::META_GENERATED, wp_json_encode( array_values( $rows ) ) );
	}
}
