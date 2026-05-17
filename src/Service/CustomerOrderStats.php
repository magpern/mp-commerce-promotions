<?php
/**
 * WooCommerce customer order aggregates for segmentation (per-request cache).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

final class CustomerOrderStats {

	/**
	 * @var list<string>
	 */
	public const COUNTED_ORDER_STATUSES = array(
		'completed',
		'processing',
		'on-hold',
	);

	/** @var array<int, array{lifetime_spend: float, order_count: int, average_order_value: float}> */
	private static array $cache = array();

	/**
	 * @return array{lifetime_spend: float, order_count: int, average_order_value: float}
	 */
	public static function for_customer( int $customer_id ): array {
		if ( $customer_id <= 0 ) {
			return self::empty_stats();
		}

		if ( isset( self::$cache[ $customer_id ] ) ) {
			return self::$cache[ $customer_id ];
		}

		$stats = self::compute_from_woocommerce( $customer_id );
		self::$cache[ $customer_id ] = $stats;

		return $stats;
	}

	public static function clear_request_cache(): void {
		self::$cache = array();
	}

	/**
	 * @return array{lifetime_spend: float, order_count: int, average_order_value: float}
	 */
	private static function compute_from_woocommerce( int $customer_id ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return self::empty_stats();
		}

		try {
			$order_ids = wc_get_orders(
				array(
					'customer_id' => $customer_id,
					'status'      => self::COUNTED_ORDER_STATUSES,
					'limit'       => -1,
					'return'      => 'ids',
				)
			);
		} catch ( \Throwable $e ) {
			return self::empty_stats();
		}

		if ( ! is_array( $order_ids ) ) {
			return self::empty_stats();
		}

		$count = count( $order_ids );
		if ( $count === 0 ) {
			return self::empty_stats();
		}

		$total = 0.0;
		foreach ( $order_ids as $order_id ) {
			$order_id = (int) $order_id;
			if ( $order_id <= 0 ) {
				continue;
			}
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) ) {
				continue;
			}
			$order_total = (float) $order->get_total();
			if ( $order_total > 0 ) {
				$total += $order_total;
			}
		}

		$aov = $count > 0 ? round( $total / $count, 2 ) : 0.0;

		return array(
			'lifetime_spend'        => round( $total, 2 ),
			'order_count'           => $count,
			'average_order_value'   => $aov,
		);
	}

	/**
	 * @return array{lifetime_spend: float, order_count: int, average_order_value: float}
	 */
	private static function empty_stats(): array {
		return array(
			'lifetime_spend'      => 0.0,
			'order_count'         => 0,
			'average_order_value' => 0.0,
		);
	}
}
