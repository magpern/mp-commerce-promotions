<?php
/**
 * Fulfill pending scheduled gift card deliveries (generate + email at delivery time).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\Settings;
use Throwable;

final class GiftCardScheduledDeliveryService {

	public const CRON_HOOK = 'mp_cp_gift_card_scheduled_delivery';

	private GiftCardLedger $ledger;

	private GiftCardProductService $products;

	private GiftCardUnitFulfillment $fulfillment;

	private ?AuditLogger $audit_logger;

	private Settings $settings;

	public function __construct(
		GiftCardLedger $ledger,
		?GiftCardProductService $products = null,
		?Settings $settings = null,
		?AuditLogger $audit_logger = null
	) {
		$this->settings     = $settings ?? new Settings();
		$this->ledger       = $ledger;
		$this->products     = $products ?? new GiftCardProductService();
		$this->fulfillment  = new GiftCardUnitFulfillment( $ledger, $this->settings, $audit_logger );
		$this->audit_logger = $audit_logger;
	}

	public function register_hooks(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_due_deliveries' ) );
		add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
	}

	public function maybe_schedule_cron(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( ! $this->settings->gift_card_scheduled_cron_enabled() ) {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}

		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
	}

	/**
	 * @return array{fulfilled: int, failed: int, skipped: int}
	 */
	public function run_due_deliveries( ?int $order_id_limit = null ): array {
		$result = array(
			'fulfilled' => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $result;
		}

		$orders = wc_get_orders(
			array(
				'status'     => array( 'processing', 'completed' ),
				'limit'      => 50,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => array(
					array(
						'key'     => GiftCardPendingDeliveryState::META_PENDING,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				continue;
			}
			if ( $order_id_limit !== null && (int) $order->get_id() !== $order_id_limit ) {
				continue;
			}

			$order_result = $this->fulfill_order_pending( $order );
			$result['fulfilled'] += $order_result['fulfilled'];
			$result['failed']    += $order_result['failed'];
			$result['skipped']   += $order_result['skipped'];
		}

		return $result;
	}

	/**
	 * @return array{fulfilled: int, failed: int, skipped: int}
	 */
	public function fulfill_order_pending( $order, bool $force = false ): array {
		$result = array(
			'fulfilled' => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
			return $result;
		}

		if ( ! GiftCardGeneratedOrderState::is_generation_complete( $order ) ) {
			return $result;
		}

		$order_id     = (int) $order->get_id();
		$customer_id  = (int) $order->get_customer_id();
		$billing_name = $this->billing_display_name( $order );
		$pending   = GiftCardPendingDeliveryState::get_pending( $order );
		$remaining = array();

		foreach ( $pending as $row ) {
			$item_id    = (int) ( $row['order_item_id'] ?? 0 );
			$unit_index = (int) ( $row['unit_index'] ?? 0 );

			if ( GiftCardGeneratedOrderState::has_slot( $order, $item_id, $unit_index ) ) {
				++$result['skipped'];
				continue;
			}

			if ( (string) ( $row['delivery_status'] ?? '' ) !== GiftCardDeliveryStatus::PENDING_SCHEDULED ) {
				$remaining[] = $row;
				++$result['skipped'];
				continue;
			}

			if ( ! $force && ! GiftCardPendingDeliveryState::is_due( $row ) ) {
				$remaining[] = $row;
				++$result['skipped'];
				continue;
			}

			$to_email = sanitize_email( (string) ( $row['recipient_email'] ?? '' ) );
			if ( $to_email === '' || ! is_email( $to_email ) ) {
				$row['delivery_status'] = GiftCardDeliveryStatus::FAILED;
				$row['delivery_error']  = __( 'Invalid recipient email for scheduled delivery.', 'mp-commerce-promotions' );
				$remaining[]            = $row;
				++$result['failed'];
				continue;
			}

			try {
				$paid_at    = (string) ( $row['paid_at'] ?? '' );
				$expiry_days = isset( $row['expiry_days'] ) ? (int) $row['expiry_days'] : null;
				$expires_at = $this->products->resolve_expires_at(
					$expiry_days !== null && $expiry_days > 0 ? $expiry_days : null,
					$paid_at !== '' ? $paid_at : ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) )
				);

				$fulfilled = $this->fulfillment->issue_and_deliver(
					$order_id,
					$item_id,
					$unit_index,
					(float) ( $row['amount'] ?? 0 ),
					(string) ( $row['currency'] ?? '' ),
					$customer_id > 0 ? $customer_id : null,
					$expires_at,
					$to_email,
					array(
						'recipient_name'  => (string) ( $row['recipient_name'] ?? '' ),
						'message'         => (string) ( $row['message'] ?? '' ),
						'purchaser_name'  => $billing_name,
						'delivery_timing' => GiftCardLineItemMeta::TIMING_SEND_ON_DATE,
						'scheduled_for'   => (string) ( $row['scheduled_for'] ?? '' ),
					)
				);

				GiftCardGeneratedOrderState::append_generated( $order, $fulfilled['generated_row'] );
				++$result['fulfilled'];
			} catch ( Throwable $e ) {
				GiftCardPendingDeliveryState::update_row(
					$order,
					$item_id,
					$unit_index,
					array(
						'delivery_status' => GiftCardDeliveryStatus::FAILED,
						'delivery_error'  => sanitize_text_field( $e->getMessage() ),
					)
				);
				$row = array_merge( $row, array( 'delivery_status' => GiftCardDeliveryStatus::FAILED ) );
				$remaining[] = $row;
				++$result['failed'];
				$this->log( $e );
			}
		}

		GiftCardPendingDeliveryState::set_pending( $order, $remaining );

		if ( $result['fulfilled'] > 0 && method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %d: number of gift cards */
					__( '%d scheduled gift card(s) generated and emailed.', 'mp-commerce-promotions' ),
					$result['fulfilled']
				),
				false
			);
		}

		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		return $result;
	}

	/**
	 * @param \WC_Order $order
	 */
	private function billing_display_name( $order ): string {
		if ( ! method_exists( $order, 'get_billing_first_name' ) ) {
			return '';
		}

		return sanitize_text_field(
			trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() )
		);
	}

	private function log( Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[mp-commerce-promotions] GiftCardScheduledDeliveryService: ' . $e->getMessage() );
		}
	}
}
