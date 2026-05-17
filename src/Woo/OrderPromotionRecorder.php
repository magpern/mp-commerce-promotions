<?php
/**
 * Persists applied cart promotion(s) to order meta, redemptions table, and audit on checkout.
 *
 * Supported order transitions (MVP):
 * - Checkout create order: record redemptions once per (order_id, promotion_id).
 * - cancelled / failed / refunded / trash / delete: reverse recorded redemptions once per promotion.
 * - processing / completed after reversal: restore reversed rows (re-enter paid flow).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\PromotionBudgetLedger;
use Throwable;

final class OrderPromotionRecorder {

	private const META_PROMOTION_CODE_ID = '_mp_cp_promotion_code_id';

	private const META_PROMOTION_CODE_LAST4 = '_mp_cp_promotion_code_last4';

	private RedemptionRepository $redemptions;

	private PromotionRepository $promotions;

	private PromotionCodeRepository $promotion_codes;

	private AuditLogger $audit;

	private PromotionBudgetLedger $budget_ledger;

	/**
	 * @param RedemptionRepository    $redemptions     Redemption persistence.
	 * @param PromotionRepository     $promotions      Promotion persistence.
	 * @param PromotionCodeRepository $promotion_codes Code persistence.
	 * @param AuditLogger             $audit           Audit trail writer.
	 * @param PromotionBudgetLedger|null $budget_ledger Budget spent adjustments.
	 */
	public function __construct(
		RedemptionRepository $redemptions,
		PromotionRepository $promotions,
		PromotionCodeRepository $promotion_codes,
		AuditLogger $audit,
		?PromotionBudgetLedger $budget_ledger = null
	) {
		$this->redemptions     = $redemptions;
		$this->promotions      = $promotions;
		$this->promotion_codes = $promotion_codes;
		$this->audit           = $audit;
		$this->budget_ledger   = $budget_ledger ?? new PromotionBudgetLedger( $promotions );
	}

	/**
	 * @param mixed $order WooCommerce order object.
	 * @param mixed $data  Checkout posted data (unused).
	 */
	public function record_on_order_create( $order, $data = null ): void {
		try {
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				return;
			}

			$order_id = (int) $order->get_id();
			if ( $order_id <= 0 ) {
				return;
			}

			$entries = AppliedPromotionSession::entries_from_session(
				CartSessionHelper::get_applied_promotion()
			);
			if ( $entries === array() ) {
				return;
			}

			if ( OrderPromotionState::has_recorded_promotions( $order ) ) {
				$all_exist = true;
				foreach ( $entries as $entry ) {
					if ( ! AppliedPromotionSession::is_valid_entry( $entry ) ) {
						continue;
					}
					$pid = (int) $entry['promotion_id'];
					if ( ! $this->redemptions->exists_for_order_and_promotion( $order_id, $pid ) ) {
						$all_exist = false;
						break;
					}
				}
				if ( $all_exist ) {
					return;
				}
			}

			$cid         = (int) $order->get_customer_id();
			$customer_id = $cid > 0 ? $cid : null;
			$currency    = $order->get_currency();
			$currency    = is_string( $currency ) && $currency !== '' ? $currency : null;
			$now         = current_time( 'mysql' );

			$recorded_meta = array();

			foreach ( $entries as $entry ) {
				$recorded = $this->record_single_entry(
					$order,
					$order_id,
					$entry,
					$customer_id,
					$currency,
					$now
				);
				if ( $recorded !== null ) {
					$recorded_meta[] = $recorded;
				}
			}

			if ( $recorded_meta === array() ) {
				return;
			}

			OrderPromotionState::save_applied_promotions( $order, $recorded_meta );

			$first = $entries[0];
			$this->apply_legacy_primary_meta_from_entry( $order, $first );

			OrderPromotionState::mark_recorded( $order );
			$order->save();
			$this->clear_applied_promotion_session();
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[mp-commerce-promotions] OrderPromotionRecorder: %s',
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Restore reversed redemptions when an order re-enters a paid/completed flow.
	 *
	 * @param int|\WC_Order $order_id Order ID or order object.
	 * @param mixed         $order    Optional WC_Order.
	 */
	public function restore_on_order_paid_status( $order_id, $order = null ): void {
		try {
			$id = $this->resolve_order_id_from_hook_args( $order_id, $order );
			if ( $id <= 0 || ! function_exists( 'wc_get_order' ) ) {
				return;
			}

			$order = wc_get_order( $id );
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				return;
			}

			if ( ! OrderPromotionState::has_recorded_promotions( $order ) ) {
				return;
			}

			if ( ! OrderPromotionState::is_reversed( $order ) ) {
				return;
			}

			$promotion_ids = OrderPromotionState::promotion_ids_from_order( $order );
			if ( $promotion_ids === array() ) {
				return;
			}

			$restored_any = false;

			foreach ( $promotion_ids as $promotion_id ) {
				if ( $this->restore_single_promotion( $order, $id, $promotion_id ) ) {
					$restored_any = true;
				}
			}

			if ( $restored_any ) {
				OrderPromotionState::mark_recorded( $order );
				$order->save();
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[mp-commerce-promotions] OrderPromotionRecorder::restore_on_order_paid_status: %s',
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Reverse recorded redemption(s) when the order is cancelled, failed, refunded, trashed, or deleted.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function reverse_for_order( int $order_id ): void {
		try {
			if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
				return;
			}

			$order = wc_get_order( $order_id );
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
				return;
			}

			if ( OrderPromotionState::is_reversed( $order ) ) {
				return;
			}

			if ( ! OrderPromotionState::has_recorded_promotions( $order ) ) {
				return;
			}

			$promotion_ids = OrderPromotionState::promotion_ids_from_order( $order );
			if ( $promotion_ids === array() ) {
				return;
			}

			$reversed_any = false;

			foreach ( $promotion_ids as $promotion_id ) {
				if ( $this->reverse_single_promotion( $order, $order_id, $promotion_id ) ) {
					$reversed_any = true;
				}
			}

			if ( $reversed_any ) {
				OrderPromotionState::mark_reversed( $order );
				$order->save();
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[mp-commerce-promotions] OrderPromotionRecorder::reverse_for_order: %s',
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * @param \WC_Order              $order
	 * @param array<string, mixed>   $entry
	 * @return array<string, mixed>|null Summary for applied promotions meta.
	 */
	private function record_single_entry(
		$order,
		int $order_id,
		array $entry,
		?int $customer_id,
		?string $currency,
		string $now
	): ?array {
		if ( ! AppliedPromotionSession::is_valid_entry( $entry ) ) {
			return null;
		}

		$promotion_id = (int) $entry['promotion_id'];
		$uuid         = isset( $entry['promotion_uuid'] ) ? (string) $entry['promotion_uuid'] : '';
		$name         = isset( $entry['promotion_name'] ) ? (string) $entry['promotion_name'] : '';
		$discount     = (float) $entry['discount_amount'];
		$action_type  = (string) $entry['action_type'];

		$percentage   = null;
		$fixed_amount = null;

		if ( $action_type === CartPromotionApplier::ACTION_PERCENTAGE_DISCOUNT ) {
			$percentage = isset( $entry['percentage'] ) && is_numeric( $entry['percentage'] )
				? (float) $entry['percentage']
				: null;
			if ( $percentage === null || $percentage <= 0 ) {
				return null;
			}
		} elseif ( $action_type === CartPromotionApplier::ACTION_FIXED_AMOUNT_DISCOUNT ) {
			$fixed_amount = isset( $entry['fixed_amount'] ) && is_numeric( $entry['fixed_amount'] )
				? (float) $entry['fixed_amount']
				: null;
			if ( $fixed_amount === null || $fixed_amount <= 0 ) {
				return null;
			}
		} elseif (
			$action_type === CartPromotionApplier::ACTION_FREE_SHIPPING
			|| $action_type === CartPromotionApplier::ACTION_CHEAPEST_ITEM_DISCOUNT
			|| $action_type === CartPromotionApplier::ACTION_FREE_GIFT_PRODUCT
		) {
			$percentage   = null;
			$fixed_amount = null;
		} else {
			return null;
		}

		$code_meta = $this->extract_promotion_code_meta_from_entry( $entry );
		$newly     = false;

		if ( $this->redemptions->exists_for_order_and_promotion( $order_id, $promotion_id ) ) {
			$this->apply_promotion_meta_to_order(
				$order,
				$promotion_id,
				$uuid,
				$name,
				$discount,
				$action_type,
				$percentage,
				$fixed_amount,
				$code_meta
			);
		} else {
			$redemption = new Redemption(
				null,
				$promotion_id,
				$order_id,
				$customer_id,
				null,
				$discount,
				$currency,
				Redemption::STATUS_RECORDED,
				$now,
				$now
			);

			$new_id = $this->redemptions->insert( $redemption );
			if ( $new_id <= 0 ) {
				return null;
			}

			$promotion = $this->promotions->find( $promotion_id );
			if ( $promotion !== null ) {
				$this->promotions->update(
					$promotion->with_usage_count( $promotion->get_usage_count() + 1 )
				);
				$this->budget_ledger->record_redemption_discount( $promotion, $discount );
			}

			if ( $code_meta['promotion_code_id'] > 0 ) {
				$this->promotion_codes->increment_usage( $code_meta['promotion_code_id'] );
			}

			$this->audit->log(
				'promotion.redeemed',
				$promotion_id,
				array(
					'promotion_id'         => $promotion_id,
					'order_id'             => $order_id,
					'discount_amount'      => $discount,
					'action_type'          => $action_type,
					'promotion_code_id'    => $code_meta['promotion_code_id'] > 0 ? $code_meta['promotion_code_id'] : null,
					'promotion_code_last4' => $code_meta['promotion_code_last4'] !== '' ? $code_meta['promotion_code_last4'] : null,
				),
				null
			);

			$this->audit->log(
				'promotion.recorded_on_order',
				$promotion_id,
				array(
					'promotion_id'    => $promotion_id,
					'order_id'        => $order_id,
					'discount_amount' => $discount,
					'action_type'     => $action_type,
				),
				null
			);

			$newly = true;
		}

		$summary = array(
			'promotion_id'         => $promotion_id,
			'promotion_uuid'       => $uuid,
			'promotion_name'       => $name,
			'discount_amount'      => $discount,
			'action_type'          => $action_type,
			'promotion_code_id'    => $code_meta['promotion_code_id'] > 0 ? $code_meta['promotion_code_id'] : null,
			'promotion_code_last4' => $code_meta['promotion_code_last4'] !== '' ? $code_meta['promotion_code_last4'] : null,
			'newly_recorded'       => $newly,
		);

		if ( $action_type === CartPromotionApplier::ACTION_FREE_GIFT_PRODUCT ) {
			if ( isset( $entry['product_id'] ) ) {
				$summary['product_id'] = (int) $entry['product_id'];
			}
			if ( array_key_exists( 'variation_id', $entry ) ) {
				$summary['variation_id'] = $entry['variation_id'] !== null ? (int) $entry['variation_id'] : null;
			}
			if ( isset( $entry['quantity'] ) ) {
				$summary['quantity'] = (int) $entry['quantity'];
			}
		}

		return $summary;
	}

	private function restore_single_promotion( $order, int $order_id, int $promotion_id ): bool {
		$redemption = $this->redemptions->find_reversed_for_order_and_promotion( $order_id, $promotion_id );
		if ( $redemption === null ) {
			return false;
		}

		$recorded = $redemption->with_status( Redemption::STATUS_RECORDED );
		if ( ! $this->redemptions->update( $recorded ) ) {
			return false;
		}

		$promotion = $this->promotions->find( $promotion_id );
		if ( $promotion !== null ) {
			$this->promotions->update(
				$promotion->with_usage_count( $promotion->get_usage_count() + 1 )
			);
			$this->budget_ledger->record_redemption_discount( $promotion, $redemption->get_discount_amount() );
		}

		$code_id = $this->code_id_for_promotion_from_applied_meta( $order, $promotion_id );
		if ( $code_id > 0 ) {
			$this->promotion_codes->increment_usage( $code_id );
		}

		$this->audit->log(
			'promotion.recorded_on_order',
			$promotion_id,
			array(
				'promotion_id' => $promotion_id,
				'order_id'     => $order_id,
				'restored'     => true,
			),
			null
		);

		return true;
	}

	private function reverse_single_promotion( $order, int $order_id, int $promotion_id ): bool {
		$redemption = $this->redemptions->find_recorded_for_order_and_promotion( $order_id, $promotion_id );
		if ( $redemption === null ) {
			return false;
		}

		$reversed = $redemption->with_status( Redemption::STATUS_REVERSED );
		if ( ! $this->redemptions->update( $reversed ) ) {
			return false;
		}

		$promotion = $this->promotions->find( $promotion_id );
		if ( $promotion !== null ) {
			$new_usage = max( 0, $promotion->get_usage_count() - 1 );
			$this->promotions->update( $promotion->with_usage_count( $new_usage ) );
			$this->budget_ledger->reverse_redemption_discount( $promotion, $redemption->get_discount_amount() );
		}

		$promotion_code_id = (int) $order->get_meta( self::META_PROMOTION_CODE_ID, true );
		if ( $promotion_id === (int) $order->get_meta( '_mp_cp_promotion_id', true ) && $promotion_code_id > 0 ) {
			$promotion_code = $this->promotion_codes->find( $promotion_code_id );
			if ( $promotion_code !== null ) {
				$new_code_usage = max( 0, $promotion_code->get_usage_count() - 1 );
				$this->promotion_codes->update( $promotion_code->with_usage_count( $new_code_usage ) );
			}
		}

		$code_id_from_meta = $this->code_id_for_promotion_from_applied_meta( $order, $promotion_id );
		if ( $code_id_from_meta > 0 && $code_id_from_meta !== $promotion_code_id ) {
			$promotion_code = $this->promotion_codes->find( $code_id_from_meta );
			if ( $promotion_code !== null ) {
				$new_code_usage = max( 0, $promotion_code->get_usage_count() - 1 );
				$this->promotion_codes->update( $promotion_code->with_usage_count( $new_code_usage ) );
			}
		}

		$this->audit->log(
			'promotion.redemption_reversed',
			$promotion_id,
			array(
				'promotion_id' => $promotion_id,
				'order_id'     => $order_id,
			),
			null
		);

		$this->audit->log(
			'promotion.reversed_on_order',
			$promotion_id,
			array(
				'promotion_id' => $promotion_id,
				'order_id'     => $order_id,
			),
			null
		);

		return true;
	}

	/**
	 * @param \WC_Order $order
	 */
	private function code_id_for_promotion_from_applied_meta( $order, int $promotion_id ): int {
		foreach ( OrderPromotionState::get_applied_promotions( $order ) as $row ) {
			if ( (int) ( $row['promotion_id'] ?? 0 ) !== $promotion_id ) {
				continue;
			}
			$code_id = isset( $row['promotion_code_id'] ) ? (int) $row['promotion_code_id'] : 0;

			return $code_id > 0 ? $code_id : 0;
		}

		return 0;
	}

	/**
	 * @param \WC_Order            $order
	 * @param array<string, mixed> $entry
	 */
	private function apply_legacy_primary_meta_from_entry( $order, array $entry ): void {
		$promotion_id = (int) $entry['promotion_id'];
		$uuid         = isset( $entry['promotion_uuid'] ) ? (string) $entry['promotion_uuid'] : '';
		$name         = isset( $entry['promotion_name'] ) ? (string) $entry['promotion_name'] : '';
		$discount     = (float) $entry['discount_amount'];
		$action_type  = (string) $entry['action_type'];

		$percentage   = isset( $entry['percentage'] ) && is_numeric( $entry['percentage'] )
			? (float) $entry['percentage']
			: null;
		$fixed_amount = isset( $entry['fixed_amount'] ) && is_numeric( $entry['fixed_amount'] )
			? (float) $entry['fixed_amount']
			: null;

		$this->apply_promotion_meta_to_order(
			$order,
			$promotion_id,
			$uuid,
			$name,
			$discount,
			$action_type,
			$percentage,
			$fixed_amount,
			$this->extract_promotion_code_meta_from_entry( $entry )
		);
	}

	/**
	 * @param int|\WC_Order $order_id Order ID or order object (WooCommerce passes both shapes across hooks).
	 * @param mixed         $order    Optional WC_Order (second argument on status / trash / delete hooks).
	 */
	public function on_order_status_reversal( $order_id, $order = null ): void {
		$id = $this->resolve_order_id_from_hook_args( $order_id, $order );
		if ( $id > 0 ) {
			$this->reverse_for_order( $id );
		}
	}

	/**
	 * @param int|\WC_Order $order_id Order ID or order object.
	 * @param mixed         $order    Optional WC_Order.
	 */
	public function on_woocommerce_before_trash_order( $order_id, $order = null ): void {
		$id = $this->resolve_order_id_from_hook_args( $order_id, $order );
		if ( $id > 0 ) {
			$this->reverse_for_order( $id );
		}
	}

	/**
	 * @param int|\WC_Order $order_id Order ID or order object.
	 * @param mixed         $order    Optional WC_Order.
	 */
	public function on_woocommerce_before_delete_order( $order_id, $order = null ): void {
		$id = $this->resolve_order_id_from_hook_args( $order_id, $order );
		if ( $id > 0 ) {
			$this->reverse_for_order( $id );
		}
	}

	/**
	 * CPT fallback when the order is a `shop_order` post (legacy storage).
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_before_trash_post_for_reversal( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'shop_order' ) {
			return;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $post_id );
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
			return;
		}

		$this->reverse_for_order( $post_id );
	}

	/**
	 * CPT fallback when the order post is permanently deleted.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post   Post object (optional).
	 */
	public function on_before_delete_post_for_reversal( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		if ( $post instanceof \WP_Post ) {
			if ( $post->post_type !== 'shop_order' ) {
				return;
			}
		} else {
			$p = get_post( $post_id );
			if ( ! $p instanceof \WP_Post || $p->post_type !== 'shop_order' ) {
				return;
			}
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $post_id );
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
			return;
		}

		$this->reverse_for_order( $post_id );
	}

	/**
	 * @param mixed $first  Typically order ID (int) or WC_Order.
	 * @param mixed $second Typically WC_Order or null.
	 */
	private function resolve_order_id_from_hook_args( $first, $second ): int {
		if ( is_object( $first ) && is_a( $first, 'WC_Order', false ) ) {
			return (int) $first->get_id();
		}
		if ( is_object( $second ) && is_a( $second, 'WC_Order', false ) ) {
			return (int) $second->get_id();
		}
		if ( is_numeric( $first ) ) {
			return (int) $first;
		}

		return 0;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{promotion_code_id: int, promotion_code_last4: string}
	 */
	private function extract_promotion_code_meta_from_entry( array $entry ): array {
		$code_id = isset( $entry['promotion_code_id'] ) ? (int) $entry['promotion_code_id'] : 0;
		$last4   = isset( $entry['promotion_code_last4'] ) ? sanitize_text_field( (string) $entry['promotion_code_last4'] ) : '';

		return array(
			'promotion_code_id'    => $code_id > 0 ? $code_id : 0,
			'promotion_code_last4' => $last4,
		);
	}

	/**
	 * @param \WC_Order                                                   $order
	 * @param array{promotion_code_id: int, promotion_code_last4: string} $code_meta
	 */
	private function apply_promotion_meta_to_order(
		$order,
		int $promotion_id,
		string $uuid,
		string $name,
		float $discount,
		string $action_type,
		?float $percentage = null,
		?float $fixed_amount = null,
		array $code_meta = array()
	): void {
		$order->update_meta_data( '_mp_cp_promotion_id', (string) $promotion_id );
		$order->update_meta_data( '_mp_cp_promotion_uuid', sanitize_text_field( $uuid ) );
		$order->update_meta_data( '_mp_cp_promotion_name', sanitize_text_field( $name ) );
		$discount_meta = function_exists( 'wc_format_decimal' )
			? wc_format_decimal( $discount )
			: (string) $discount;
		$order->update_meta_data( '_mp_cp_discount_amount', $discount_meta );
		$order->update_meta_data( '_mp_cp_action_type', sanitize_text_field( $action_type ) );

		if ( $percentage !== null && $percentage > 0 ) {
			$order->update_meta_data(
				'_mp_cp_percentage',
				function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $percentage ) : (string) $percentage
			);
		}

		if ( $fixed_amount !== null && $fixed_amount > 0 ) {
			$order->update_meta_data(
				'_mp_cp_fixed_amount',
				function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $fixed_amount ) : (string) $fixed_amount
			);
		}

		$code_id = isset( $code_meta['promotion_code_id'] ) ? (int) $code_meta['promotion_code_id'] : 0;
		if ( $code_id > 0 ) {
			$order->update_meta_data( self::META_PROMOTION_CODE_ID, (string) $code_id );
		}

		$last4 = isset( $code_meta['promotion_code_last4'] ) ? (string) $code_meta['promotion_code_last4'] : '';
		if ( $last4 !== '' ) {
			$order->update_meta_data( self::META_PROMOTION_CODE_LAST4, sanitize_text_field( $last4 ) );
		}
	}

	private function clear_applied_promotion_session(): void {
		CartSessionHelper::clear_applied_promotion();
	}
}
