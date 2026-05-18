<?php
/**
 * Records gift card ledger redemptions on order checkout (idempotent).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCardLedger;
use Throwable;

final class GiftCardOrderRecorder {

	private GiftCardLedger $ledger;

	public function __construct( GiftCardLedger $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * Copy session redemption intent to order meta (no balance deduction).
	 *
	 * @param mixed $order WC_Order.
	 * @param mixed $data  Unused checkout data.
	 */
	public function stage_on_order_create( $order, $data = null ): void {
		unset( $data );
		try {
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				return;
			}

			$session = GiftCardSession::get();
			if ( $session === null ) {
				return;
			}

			$amount = GiftCardAmount::money( (float) $session['applied_amount'] );
			if ( $amount <= 0 ) {
				return;
			}

			GiftCardOrderState::set_redemptions(
				$order,
				array(
					array(
						'gift_card_id' => (int) $session['gift_card_id'],
						'amount'       => $amount,
						'code_last4'   => (string) $session['code_last4'],
					),
				)
			);
		} catch ( Throwable $e ) {
			$this->log( $e );
		}
	}

	/**
	 * Deduct ledger balance once when checkout completes.
	 *
	 * @param mixed $order_id Order ID or WC_Order.
	 */
	public function record_on_checkout_processed( $order_id ): void {
		try {
			$order = $this->resolve_order( $order_id );
			if ( $order === null ) {
				return;
			}

			if ( GiftCardOrderState::has_recorded( $order ) ) {
				return;
			}

			$rows = GiftCardOrderState::get_redemptions( $order );
			if ( $rows === array() ) {
				$session = GiftCardSession::get();
				if ( $session !== null && GiftCardAmount::money( (float) $session['applied_amount'] ) > 0 ) {
					$rows = array(
						array(
							'gift_card_id' => (int) $session['gift_card_id'],
							'amount'       => GiftCardAmount::money( (float) $session['applied_amount'] ),
							'code_last4'   => (string) $session['code_last4'],
						),
					);
					GiftCardOrderState::set_redemptions( $order, $rows );
				}
			}

			if ( $rows === array() ) {
				return;
			}

			$order_id_int = (int) $order->get_id();
			$customer_id  = (int) $order->get_customer_id();

			foreach ( $rows as $row ) {
				$this->ledger->redeem(
					(int) $row['gift_card_id'],
					(float) $row['amount'],
					$order_id_int > 0 ? $order_id_int : null,
					$customer_id > 0 ? $customer_id : null,
					'Checkout redemption'
				);
			}

			GiftCardOrderState::mark_recorded( $order );
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}

			GiftCardSession::clear();
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

			if ( ! GiftCardOrderState::has_recorded( $order ) ) {
				return;
			}

			if ( GiftCardOrderState::is_reversed( $order ) ) {
				return;
			}

			$order_id_int = (int) $order->get_id();
			foreach ( GiftCardOrderState::get_redemptions( $order ) as $row ) {
				$this->ledger->refund_redemption(
					(int) $row['gift_card_id'],
					(float) $row['amount'],
					$order_id_int > 0 ? $order_id_int : null,
					'Order reversal'
				);
			}

			GiftCardOrderState::mark_reversed( $order );
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
			error_log( '[mp-commerce-promotions] GiftCardOrderRecorder: ' . $e->getMessage() );
		}
	}
}
