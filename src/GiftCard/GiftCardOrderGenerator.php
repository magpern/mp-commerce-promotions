<?php
/**
 * Generate gift cards when gift-card products are purchased and paid.
 *
 * Scheduled deliveries defer card generation until the scheduled runner fires.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\Settings;
use Throwable;

final class GiftCardOrderGenerator {

	private GiftCardLedger $ledger;

	private GiftCardProductService $products;

	private GiftCardUnitFulfillment $fulfillment;

	private ?AuditLogger $audit_logger;

	public function __construct(
		GiftCardLedger $ledger,
		?GiftCardProductService $products = null,
		?Settings $settings = null,
		?AuditLogger $audit_logger = null
	) {
		$this->ledger        = $ledger;
		$this->products      = $products ?? new GiftCardProductService();
		$this->fulfillment   = new GiftCardUnitFulfillment( $ledger, $settings, $audit_logger );
		$this->audit_logger  = $audit_logger;
	}

	public function register_hooks(): void {
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_generate_for_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_generate_for_order' ), 20, 1 );
	}

	/**
	 * @param mixed $order_id
	 */
	public function maybe_generate_for_order( $order_id ): void {
		try {
			$order = $this->resolve_order( $order_id );
			if ( $order === null ) {
				return;
			}

			$this->generate_for_order( $order );
		} catch ( Throwable $e ) {
			$this->log( $e );
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function generate_for_order( $order ): array {
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
			return array();
		}

		if ( GiftCardGeneratedOrderState::is_generation_complete( $order ) ) {
			return GiftCardGeneratedOrderState::get_generated( $order );
		}

		$order_id        = (int) $order->get_id();
		$currency        = GiftCardCurrency::validate( method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '' );
		$customer_id     = (int) $order->get_customer_id();
		$billing_mail    = method_exists( $order, 'get_billing_email' ) ? sanitize_email( (string) $order->get_billing_email() ) : '';
		$billing_name    = $this->billing_display_name( $order );
		$paid_at         = $this->resolve_paid_at( $order );
		$immediate_count = 0;
		$scheduled_count = 0;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! is_object( $item ) || ! is_a( $item, 'WC_Order_Item_Product', false ) ) {
				continue;
			}

			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$config       = $this->products->get_line_config( $product_id, $variation_id );
			if ( $config === null ) {
				continue;
			}

			$qty = (int) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}

			$line_total  = (float) $item->get_total();
			$chosen      = GiftCardProductCustomerAmount::read_amount_from_order_item( $item );
			$unit_amount = $this->products->resolve_unit_amount( $config, $line_total, $qty, $chosen );
			$delivery    = $this->resolve_line_delivery( $config, $item, $billing_mail );

			for ( $unit_index = 0; $unit_index < $qty; ++$unit_index ) {
				$item_id_int = (int) $item_id;
				if ( GiftCardPendingDeliveryState::slot_fulfilled( $order, $item_id_int, $unit_index ) ) {
					continue;
				}

				if ( $delivery['delivery_timing'] === GiftCardLineItemMeta::TIMING_SEND_ON_DATE ) {
					$pending = GiftCardPendingDeliveryState::build_row(
						$item_id_int,
						$unit_index,
						$unit_amount,
						$currency,
						$delivery,
						$paid_at,
						$config['expiry_days']
					);
					GiftCardPendingDeliveryState::add_pending( $order, $pending );
					++$scheduled_count;
					continue;
				}

				$expires_at = $this->products->resolve_expires_at( $config['expiry_days'], $paid_at );
				$to_email   = $delivery['recipient_email'] !== '' ? $delivery['recipient_email'] : $billing_mail;

				$result = $this->fulfillment->issue_and_deliver(
					$order_id,
					$item_id_int,
					$unit_index,
					$unit_amount,
					$currency,
					$customer_id > 0 ? $customer_id : null,
					$expires_at,
					$to_email,
					array(
						'recipient_name'  => $delivery['recipient_name'],
						'message'         => $delivery['message'],
						'purchaser_name'  => $billing_name,
						'delivery_timing' => GiftCardLineItemMeta::TIMING_SEND_NOW,
					)
				);

				GiftCardGeneratedOrderState::append_generated( $order, $result['generated_row'] );
				++$immediate_count;
			}
		}

		GiftCardGeneratedOrderState::mark_generation_complete( $order );
		$this->add_processing_order_note( $order, $immediate_count, $scheduled_count );

		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		return GiftCardGeneratedOrderState::get_generated( $order );
	}

	/**
	 * @param array{sells: bool, amount_mode: string, fixed_amount: float, expiry_days: ?int, recipient_mode: string} $config
	 * @return array{recipient_email: string, recipient_name: string, message: string, delivery_timing: string, scheduled_for: string}
	 */
	private function resolve_line_delivery( array $config, $item, string $billing_email ): array {
		$from_item = GiftCardLineItemMeta::read_from_order_item( $item );
		$mode      = (string) $config['recipient_mode'];

		if ( ! GiftCardProductMeta::allows_recipient_fields( $mode ) ) {
			return array(
				'recipient_email'  => $billing_email,
				'recipient_name'   => '',
				'message'          => '',
				'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_NOW,
				'scheduled_for'    => '',
			);
		}

		if ( $from_item['recipient_email'] === '' ) {
			$from_item['recipient_email'] = $billing_email;
		}

		try {
			return GiftCardRecipientValidator::validate_for_product( $config, $from_item, $billing_email );
		} catch ( \InvalidArgumentException $e ) {
			return array(
				'recipient_email'  => $billing_email,
				'recipient_name'   => '',
				'message'          => '',
				'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_NOW,
				'scheduled_for'    => '',
			);
		}
	}

	private function add_processing_order_note( $order, int $immediate, int $scheduled ): void {
		if ( ! method_exists( $order, 'add_order_note' ) ) {
			return;
		}

		if ( $immediate > 0 && $scheduled === 0 ) {
			$order->add_order_note(
				__( 'Gift card code(s) delivered by email. Full codes are not stored.', 'mp-commerce-promotions' ),
				false
			);
		} elseif ( $scheduled > 0 && $immediate === 0 ) {
			$order->add_order_note(
				__( 'Gift card(s) scheduled for future delivery. Cards will be generated and emailed on the chosen date.', 'mp-commerce-promotions' ),
				false
			);
		} elseif ( $immediate > 0 && $scheduled > 0 ) {
			$order->add_order_note(
				__( 'Some gift cards were sent immediately; others are scheduled for future delivery.', 'mp-commerce-promotions' ),
				false
			);
		}
	}

	/**
	 * @param \WC_Order $order
	 */
	private function billing_display_name( $order ): string {
		if ( ! method_exists( $order, 'get_billing_first_name' ) ) {
			return '';
		}

		$name = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
		return sanitize_text_field( $name );
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

	/**
	 * @param \WC_Order $order
	 */
	private function resolve_paid_at( $order ): string {
		if ( method_exists( $order, 'get_date_paid' ) ) {
			$paid = $order->get_date_paid();
			if ( $paid !== null && method_exists( $paid, 'date' ) ) {
				return $paid->date( 'Y-m-d H:i:s' );
			}
		}

		if ( method_exists( $order, 'get_date_created' ) ) {
			$created = $order->get_date_created();
			if ( $created !== null && method_exists( $created, 'date' ) ) {
				return $created->date( 'Y-m-d H:i:s' );
			}
		}

		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function log( Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[mp-commerce-promotions] GiftCardOrderGenerator: ' . $e->getMessage() );
		}
	}
}
