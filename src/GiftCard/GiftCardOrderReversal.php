<?php
/**
 * Void unused product-generated gift cards when orders are cancelled/refunded.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use Throwable;

final class GiftCardOrderReversal {

	private GiftCardLedger $ledger;

	private GiftCardRepository $cards;

	public function __construct( GiftCardLedger $ledger, GiftCardRepository $cards ) {
		$this->ledger = $ledger;
		$this->cards  = $cards;
	}

	public function register_hooks(): void {
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_order_reversal' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_order_reversal' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'handle_order_reversal' ), 20, 1 );
	}

	/**
	 * @param mixed $order_id
	 */
	public function handle_order_reversal( $order_id ): void {
		try {
			$order = $this->resolve_order( $order_id );
			if ( $order === null ) {
				return;
			}

			$has_generated = GiftCardGeneratedOrderState::is_generation_complete( $order );
			$has_pending   = GiftCardPendingDeliveryState::get_pending( $order ) !== array();

			if ( ! $has_generated && ! $has_pending ) {
				return;
			}

			if ( GiftCardGeneratedOrderState::is_reversal_handled( $order ) ) {
				return;
			}

			$order_id_int = (int) $order->get_id();
			$warnings     = array();

			$this->cancel_pending_deliveries( $order );

			foreach ( GiftCardGeneratedOrderState::get_generated( $order ) as $row ) {
				$card_id = (int) $row['gift_card_id'];
				$card    = $this->cards->find( $card_id );
				if ( $card === null || $card->is_store_credit_wallet() ) {
					continue;
				}

				if ( $card->get_status() === GiftCard::STATUS_VOIDED ) {
					continue;
				}

				$unused = abs( $card->get_balance() - $card->get_initial_amount() ) < 0.009
					&& $card->get_balance() > 0;

				if ( $unused ) {
					$this->ledger->void_card(
						$card_id,
						sprintf( 'Order #%d cancelled or refunded', $order_id_int )
					);
					continue;
				}

				if ( $card->get_balance() < $card->get_initial_amount() - 0.009 ) {
					$warnings[] = sprintf(
						/* translators: 1: gift card id, 2: order id */
						__( 'Gift card #%1$d already partially used; manual review required (order #%2$d).', 'mp-commerce-promotions' ),
						$card_id,
						$order_id_int
					);
				}
			}

			if ( $warnings !== array() && method_exists( $order, 'add_order_note' ) ) {
				$order->add_order_note( implode( ' ', $warnings ), false );
			}

			GiftCardGeneratedOrderState::mark_reversal_handled( $order );
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[mp-commerce-promotions] GiftCardOrderReversal: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * @param mixed $order_id
	 * @return \WC_Order|null
	 */
	/**
	 * @param \WC_Order $order
	 */
	private function cancel_pending_deliveries( $order ): void {
		$pending   = GiftCardPendingDeliveryState::get_pending( $order );
		$remaining = array();

		foreach ( $pending as $row ) {
			if ( (string) ( $row['delivery_status'] ?? '' ) === GiftCardDeliveryStatus::PENDING_SCHEDULED ) {
				$row['delivery_status'] = GiftCardDeliveryStatus::CANCELLED;
				$row['delivery_error']  = __( 'Order cancelled or refunded before scheduled delivery.', 'mp-commerce-promotions' );
			}
			$remaining[] = $row;
		}

		GiftCardPendingDeliveryState::set_pending( $order, $remaining );
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
}
