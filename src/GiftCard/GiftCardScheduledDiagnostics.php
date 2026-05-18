<?php
/**
 * Diagnostics for scheduled gift card deliveries.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use wpdb;

final class GiftCardScheduledDiagnostics {

	private wpdb $wpdb;

	private ?GiftCardScheduledDeliveryService $scheduler;

	public function __construct( wpdb $wpdb, ?GiftCardScheduledDeliveryService $scheduler = null ) {
		$this->wpdb      = $wpdb;
		$this->scheduler = $scheduler;
	}

	/**
	 * @return array{
	 *   overdue: list<array<string, mixed>>,
	 *   unpaid_pending: list<array<string, mixed>>,
	 *   invalid_recipient: list<array<string, mixed>>,
	 *   failed_scheduled: list<array<string, mixed>>
	 * }
	 */
	public function analyze(): array {
		$overdue           = array();
		$unpaid_pending    = array();
		$invalid_recipient = array();
		$failed_scheduled  = array();

		if ( ! function_exists( 'wc_get_order' ) ) {
			return compact( 'overdue', 'unpaid_pending', 'invalid_recipient', 'failed_scheduled' );
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array(
					array(
						'key'     => GiftCardPendingDeliveryState::META_PENDING,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$today = new \DateTimeImmutable( function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : 'today' );

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				continue;
			}

			$order_id = (int) $order->get_id();
			$status   = $order->get_status();
			$paid_ok  = in_array( $status, array( 'processing', 'completed' ), true );

			foreach ( GiftCardPendingDeliveryState::get_pending( $order ) as $row ) {
				$delivery_status = (string) ( $row['delivery_status'] ?? '' );

				if ( $delivery_status === GiftCardDeliveryStatus::FAILED ) {
					if ( count( $failed_scheduled ) < 50 ) {
						$failed_scheduled[] = array(
							'order_id'     => $order_id,
							'order_item_id' => (int) ( $row['order_item_id'] ?? 0 ),
							'unit_index'   => (int) ( $row['unit_index'] ?? 0 ),
						);
					}
					continue;
				}

				if ( $delivery_status !== GiftCardDeliveryStatus::PENDING_SCHEDULED ) {
					continue;
				}

				if ( ! $paid_ok ) {
					if ( count( $unpaid_pending ) < 50 ) {
						$unpaid_pending[] = array(
							'order_id' => $order_id,
							'status'   => $status,
						);
					}
					continue;
				}

				$email = sanitize_email( (string) ( $row['recipient_email'] ?? '' ) );
				if ( $email === '' || ! is_email( $email ) ) {
					if ( count( $invalid_recipient ) < 50 ) {
						$invalid_recipient[] = array(
							'order_id'     => $order_id,
							'order_item_id' => (int) ( $row['order_item_id'] ?? 0 ),
						);
					}
					continue;
				}

				if ( GiftCardPendingDeliveryState::is_due( $row, $today ) && count( $overdue ) < 50 ) {
					$overdue[] = array(
						'order_id'      => $order_id,
						'scheduled_for' => (string) ( $row['scheduled_for'] ?? '' ),
					);
				}
			}
		}

		return array(
			'overdue'           => $overdue,
			'unpaid_pending'     => $unpaid_pending,
			'invalid_recipient'  => $invalid_recipient,
			'failed_scheduled'  => $failed_scheduled,
		);
	}

	/**
	 * @return array{fulfilled: int, cancelled: int}
	 */
	public function repair( bool $apply = false ): array {
		$result = array(
			'fulfilled'  => 0,
			'cancelled'  => 0,
		);

		$issues = $this->analyze();

		if ( $apply && $this->scheduler !== null ) {
			$run = $this->scheduler->run_due_deliveries();
			$result['fulfilled'] = $run['fulfilled'];
		}

		if ( $apply && function_exists( 'wc_get_order' ) ) {
			foreach ( $issues['unpaid_pending'] as $row ) {
				$order_id = (int) ( $row['order_id'] ?? 0 );
				$order    = wc_get_order( $order_id );
				if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
					continue;
				}
				$pending   = GiftCardPendingDeliveryState::get_pending( $order );
				$remaining = array();
				foreach ( $pending as $p ) {
					if ( (string) ( $p['delivery_status'] ?? '' ) === GiftCardDeliveryStatus::PENDING_SCHEDULED ) {
						$p['delivery_status'] = GiftCardDeliveryStatus::CANCELLED;
						$p['delivery_error']  = __( 'Order not paid; scheduled delivery cancelled.', 'mp-commerce-promotions' );
						++$result['cancelled'];
					}
					$remaining[] = $p;
				}
				GiftCardPendingDeliveryState::set_pending( $order, $remaining );
				$order->save();
			}
		}

		return $result;
	}
}
