<?php
/**
 * Generate gift cards when gift-card products are purchased and paid.
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

	private GiftCardDeliveryMailer $mailer;

	private ?AuditLogger $audit_logger;

	public function __construct(
		GiftCardLedger $ledger,
		?GiftCardProductService $products = null,
		?Settings $settings = null,
		?AuditLogger $audit_logger = null
	) {
		$this->ledger        = $ledger;
		$this->products      = $products ?? new GiftCardProductService();
		$this->mailer        = new GiftCardDeliveryMailer( $settings ?? new Settings() );
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

		$order_id     = (int) $order->get_id();
		$currency     = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '';
		$currency     = GiftCardCurrency::validate( $currency );
		$customer_id  = (int) $order->get_customer_id();
		$billing_mail = method_exists( $order, 'get_billing_email' ) ? sanitize_email( (string) $order->get_billing_email() ) : '';
		$paid_at      = $this->resolve_paid_at( $order );

		$generated    = GiftCardGeneratedOrderState::get_generated( $order );
		$email_batch  = array();

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
			$unit_amount = $this->products->resolve_unit_amount( $config, $line_total, $qty );
			$expires_at  = $this->products->resolve_expires_at( $config['expiry_days'], $paid_at );

			for ( $unit_index = 0; $unit_index < $qty; ++$unit_index ) {
				$item_id_int = (int) $item_id;
				if ( GiftCardGeneratedOrderState::has_slot( $order, $item_id_int, $unit_index ) ) {
					continue;
				}

				$result = $this->ledger->issue_from_order(
					$unit_amount,
					$currency,
					$order_id,
					$customer_id > 0 ? $customer_id : null,
					$billing_mail !== '' ? $billing_mail : null,
					$expires_at,
					'Product order line ' . $item_id_int . ' unit ' . $unit_index
				);

				$card    = $result->get_card();
				$card_id = $card->get_id();
				if ( $card_id === null || $card_id <= 0 ) {
					continue;
				}

				$generated[] = GiftCardGeneratedOrderState::row_from_card(
					$card,
					$item_id_int,
					$unit_index,
					GiftCardDeliveryStatus::PENDING
				);

				$email_batch[] = array(
					'plain_code'   => $result->get_plain_code(),
					'amount'       => $unit_amount,
					'currency'     => $currency,
					'expires_at'   => $expires_at,
					'gift_card_id' => $card_id,
				);

				$this->audit( $order_id, $card_id, $unit_amount );
			}
		}

		GiftCardGeneratedOrderState::set_generated( $order, $generated );

		if ( $email_batch !== array() ) {
			$delivery = $this->mailer->deliver_batch( $billing_mail, $order_id, $email_batch );
			$this->apply_delivery_results( $order, $delivery['results'] );
			$this->add_delivery_order_note( $order, $delivery );
		}

		GiftCardGeneratedOrderState::mark_generation_complete( $order );

		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		return GiftCardGeneratedOrderState::get_generated( $order );
	}

	/**
	 * @param list<array<string, mixed>> $delivery_results
	 */
	private function apply_delivery_results( $order, array $delivery_results ): void {
		foreach ( $delivery_results as $result ) {
			$gift_card_id = (int) ( $result['gift_card_id'] ?? 0 );
			if ( $gift_card_id <= 0 ) {
				continue;
			}
			$patch = array(
				'delivery_status' => (string) ( $result['delivery_status'] ?? GiftCardDeliveryStatus::PENDING ),
			);
			if ( isset( $result['delivered_to'] ) ) {
				$patch['delivered_to'] = (string) $result['delivered_to'];
			}
			if ( isset( $result['delivered_at'] ) ) {
				$patch['delivered_at'] = (string) $result['delivered_at'];
			}
			if ( isset( $result['delivery_error'] ) ) {
				$patch['delivery_error'] = (string) $result['delivery_error'];
			}
			GiftCardGeneratedOrderState::update_row( $order, $gift_card_id, $patch );
		}
	}

	/**
	 * @param array{enabled: bool, recipient_valid: bool, results: list<array<string, mixed>>} $delivery
	 */
	private function add_delivery_order_note( $order, array $delivery ): void {
		if ( ! method_exists( $order, 'add_order_note' ) ) {
			return;
		}

		$sent   = 0;
		$failed = 0;
		foreach ( $delivery['results'] as $row ) {
			$status = (string) ( $row['delivery_status'] ?? '' );
			if ( $status === GiftCardDeliveryStatus::SENT ) {
				++$sent;
			} elseif ( $status === GiftCardDeliveryStatus::FAILED ) {
				++$failed;
			}
		}

		if ( $sent > 0 && $failed === 0 ) {
			$order->add_order_note(
				__( 'Gift card code(s) emailed to billing address. Full codes are not stored; use Reissue if delivery failed.', 'mp-commerce-promotions' ),
				false
			);
			return;
		}

		if ( $failed > 0 ) {
			$order->add_order_note(
				__( 'Gift card email delivery failed for one or more cards. Full codes are not stored — use Reissue delivery on this order.', 'mp-commerce-promotions' ),
				false
			);
		} elseif ( ! $delivery['enabled'] ) {
			$order->add_order_note(
				__( 'Gift cards generated. Email delivery is disabled in settings; codes are not stored.', 'mp-commerce-promotions' ),
				false
			);
		} elseif ( ! $delivery['recipient_valid'] ) {
			$order->add_order_note(
				__( 'Gift cards generated but billing email was invalid. Use Reissue delivery after fixing the email.', 'mp-commerce-promotions' ),
				false
			);
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

	private function audit( int $order_id, int $gift_card_id, float $amount ): void {
		if ( $this->audit_logger === null ) {
			return;
		}

		$this->audit_logger->log(
			'gift_card.generated_from_order',
			null,
			array(
				'order_id'     => $order_id,
				'gift_card_id' => $gift_card_id,
				'amount'       => $amount,
			)
		);
	}

	private function log( Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[mp-commerce-promotions] GiftCardOrderGenerator: ' . $e->getMessage() );
		}
	}
}
