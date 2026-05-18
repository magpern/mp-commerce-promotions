<?php
/**
 * Records store credit ledger debits on order checkout (idempotent).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\StoreCreditWallet;
use Throwable;

final class StoreCreditOrderRecorder {

	private StoreCreditWallet $wallet;

	public function __construct( StoreCreditWallet $wallet ) {
		$this->wallet = $wallet;
	}

	/**
	 * @param mixed $order WC_Order.
	 * @param mixed $data  Unused checkout data.
	 */
	public function stage_on_order_create( $order, $data = null ): void {
		unset( $data );
		try {
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				return;
			}

			$session = StoreCreditSession::get();
			if ( $session === null ) {
				return;
			}

			$amount = GiftCardAmount::money( (float) $session['applied_amount'] );
			if ( $amount <= 0 ) {
				return;
			}

			StoreCreditOrderState::set_redemptions(
				$order,
				array(
					array(
						'account_id' => (int) $session['account_id'],
						'amount'     => $amount,
						'currency'   => (string) $session['currency'],
					),
				)
			);
		} catch ( Throwable $e ) {
			$this->log( $e );
		}
	}

	/**
	 * @param mixed $order_id Order ID or WC_Order.
	 */
	public function record_on_checkout_processed( $order_id ): void {
		try {
			$order = $this->resolve_order( $order_id );
			if ( $order === null ) {
				return;
			}

			if ( StoreCreditOrderState::has_recorded( $order ) ) {
				return;
			}

			$rows = StoreCreditOrderState::get_redemptions( $order );
			if ( $rows === array() ) {
				$session = StoreCreditSession::get();
				if ( $session !== null && GiftCardAmount::money( (float) $session['applied_amount'] ) > 0 ) {
					$rows = array(
						array(
							'account_id' => (int) $session['account_id'],
							'amount'     => GiftCardAmount::money( (float) $session['applied_amount'] ),
							'currency'   => (string) $session['currency'],
						),
					);
					StoreCreditOrderState::set_redemptions( $order, $rows );
				}
			}

			if ( $rows === array() ) {
				return;
			}

			$order_id_int = (int) $order->get_id();
			$customer_id  = (int) $order->get_customer_id();

			foreach ( $rows as $row ) {
				$this->wallet->debit_for_checkout(
					(int) $row['account_id'],
					(float) $row['amount'],
					$order_id_int,
					$customer_id,
					'Checkout store credit'
				);
			}

			StoreCreditOrderState::mark_recorded( $order );
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}

			StoreCreditSession::clear();
		} catch ( Throwable $e ) {
			$this->log( $e );
		}
	}

	/**
	 * @param mixed $order_id Order ID or WC_Order.
	 */
	public function reverse_on_order_status( $order_id ): void {
		try {
			$order = $this->resolve_order( $order_id );
			if ( $order === null ) {
				return;
			}

			if ( ! StoreCreditOrderState::has_recorded( $order ) ) {
				return;
			}

			if ( StoreCreditOrderState::is_reversed( $order ) ) {
				return;
			}

			$order_id_int = (int) $order->get_id();
			foreach ( StoreCreditOrderState::get_redemptions( $order ) as $row ) {
				$this->wallet->restore_checkout_debit(
					(int) $row['account_id'],
					(float) $row['amount'],
					$order_id_int,
					'Order reversal'
				);
			}

			StoreCreditOrderState::mark_reversed( $order );
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
		} catch ( Throwable $e ) {
			$this->log( $e );
		}
	}

	/**
	 * @param mixed $order_id
	 * @return \WC_Order|null
	 */
	private function resolve_order( $order_id ) {
		if ( is_object( $order_id ) && is_a( $order_id, 'WC_Order', false ) ) {
			return $order_id;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return is_object( $order ) && is_a( $order, 'WC_Order', false ) ? $order : null;
	}

	private function log( Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[mp-commerce-promotions] StoreCreditOrderRecorder: ' . $e->getMessage() );
		}
	}
}
