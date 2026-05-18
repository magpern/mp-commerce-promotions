<?php
/**
 * WooCommerce order admin: generated gift cards (masked codes only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Admin\AdminNavigation;
use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardOrderReissueService;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\Settings;

final class GiftCardOrderAdmin {

	private const NONCE_REISSUE = 'mp_cp_reissue_gift_card';

	private GiftCardRepository $cards;

	private GiftCardOrderReissueService $reissue;

	public function __construct(
		GiftCardRepository $cards,
		GiftCardLedger $ledger,
		?Settings $settings = null,
		?AuditLogger $audit_logger = null
	) {
		$this->cards   = $cards;
		$this->reissue = new GiftCardOrderReissueService( $ledger, $cards, $settings, $audit_logger );
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_mp_cp_reissue_gift_card', array( $this, 'handle_reissue_post' ) );
	}

	public function add_meta_box(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop_order' ) : 'shop_order';
		add_meta_box(
			'mp_cp_generated_gift_cards',
			__( 'Gift cards', 'mp-commerce-promotions' ),
			array( $this, 'render_meta_box' ),
			$screen,
			'side',
			'default'
		);
	}

	public function handle_reissue_post(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mp-commerce-promotions' ) );
		}

		$order_id     = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$gift_card_id = isset( $_POST['gift_card_id'] ) ? (int) $_POST['gift_card_id'] : 0;

		$redirect = admin_url( 'edit.php?post_type=shop_order' );
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order_for_url = wc_get_order( $order_id );
			if ( is_object( $order_for_url ) && method_exists( $order_for_url, 'get_edit_order_url' ) ) {
				$redirect = (string) $order_for_url->get_edit_order_url();
			}
		}

		if (
			$order_id <= 0
			|| $gift_card_id <= 0
			|| ! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_REISSUE . '_' . $gift_card_id )
		) {
			wp_safe_redirect( add_query_arg( 'mp_cp_reissue', 'error', $redirect ) );
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_reissue', 'error', $redirect ) );
			exit;
		}

		$result = $this->reissue->reissue_for_order_card( $order, $gift_card_id );
		$flag   = $result['success'] ? 'success' : 'failed';
		wp_safe_redirect( add_query_arg( 'mp_cp_reissue', $flag, $redirect ) );
		exit;
	}

	/**
	 * @param \WP_Post|\WC_Order $post_or_order
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: ( function_exists( 'wc_get_order' ) && $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null );

		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order', false ) ) {
			echo '<p>' . esc_html__( 'Order not available.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		$this->render_reissue_notice();

		$rows = GiftCardGeneratedOrderState::get_generated( $order );
		if ( $rows === array() ) {
			echo '<p>' . esc_html__( 'No gift cards generated for this order.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__(
			'Full codes are delivered by email and are not stored. Only the last four digits are shown here.',
			'mp-commerce-promotions'
		) . '</p>';

		echo '<table class="widefat" style="margin:0;"><tbody>';
		foreach ( $rows as $row ) {
			$card_id = (int) ( $row['gift_card_id'] ?? 0 );
			$card    = $this->cards->find( $card_id );
			if ( $card === null ) {
				continue;
			}

			$masked = (string) ( $row['masked_code'] ?? GiftCardGeneratedOrderState::masked_code( $card->get_code_last4() ) );
			$delivery_status = (string) ( $row['delivery_status'] ?? GiftCardDeliveryStatus::UNKNOWN );

			$detail_url = add_query_arg(
				array(
					'page'         => AdminNavigation::PAGE_SLUG,
					'tab'          => AdminNavigation::TAB_GIFT_CARDS,
					'gift_card_id' => $card_id,
				),
				admin_url( 'admin.php' )
			);

			echo '<tr><td style="padding:8px;">';
			echo '<strong>' . esc_html( $masked ) . '</strong><br />';
			echo esc_html(
				number_format_i18n( $card->get_balance(), 2 ) . ' / '
				. number_format_i18n( $card->get_initial_amount(), 2 ) . ' ' . $card->get_currency()
			);
			echo '<br /><span class="description">' . esc_html( $card->get_status() ) . '</span>';
			echo '<br /><span class="description">' . esc_html__( 'Delivery:', 'mp-commerce-promotions' ) . ' ' . esc_html( $delivery_status ) . '</span>';

			if ( isset( $row['delivered_to'] ) && (string) $row['delivered_to'] !== '' ) {
				echo '<br /><span class="description">' . esc_html__( 'To:', 'mp-commerce-promotions' ) . ' ' . esc_html( (string) $row['delivered_to'] ) . '</span>';
			}
			if ( isset( $row['delivery_error'] ) && (string) $row['delivery_error'] !== '' ) {
				echo '<br /><span class="description" style="color:#b32d2e;">' . esc_html( (string) $row['delivery_error'] ) . '</span>';
			}
			if ( isset( $row['reissued_from_gift_card_id'] ) && (int) $row['reissued_from_gift_card_id'] > 0 ) {
				echo '<br /><span class="description">' . esc_html(
					sprintf(
						/* translators: %d: prior gift card id */
						__( 'Reissued from #%d', 'mp-commerce-promotions' ),
						(int) $row['reissued_from_gift_card_id']
					)
				) . '</span>';
			}

			echo '<br /><em class="description">' . esc_html__(
				'Full code was delivered by email and is not stored.',
				'mp-commerce-promotions'
			) . '</em>';

			echo '<br /><a href="' . esc_url( $detail_url ) . '">' . esc_html__( 'View gift card', 'mp-commerce-promotions' ) . '</a>';

			if ( $this->can_reissue( $card ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:6px;">';
				wp_nonce_field( self::NONCE_REISSUE . '_' . $card_id );
				echo '<input type="hidden" name="action" value="mp_cp_reissue_gift_card" />';
				echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
				echo '<input type="hidden" name="gift_card_id" value="' . esc_attr( (string) $card_id ) . '" />';
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Reissue delivery', 'mp-commerce-promotions' ) . '</button>';
				echo '</form>';
			} elseif ( $card->get_status() !== GiftCard::STATUS_VOIDED ) {
				echo '<p class="description" style="margin-top:6px;">' . esc_html__(
					'Reissue unavailable: card was partially used or is not eligible.',
					'mp-commerce-promotions'
				) . '</p>';
			}

			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function can_reissue( GiftCard $card ): bool {
		if ( $card->is_store_credit_wallet() || $card->get_status() === GiftCard::STATUS_VOIDED ) {
			return false;
		}

		return abs( $card->get_balance() - $card->get_initial_amount() ) < 0.009 && $card->get_balance() > 0;
	}

	private function render_reissue_notice(): void {
		if ( ! isset( $_GET['mp_cp_reissue'] ) ) {
			return;
		}

		$flag = sanitize_key( (string) $_GET['mp_cp_reissue'] );
		if ( $flag === 'success' ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Gift card delivery reissued.', 'mp-commerce-promotions' ) . '</p></div>';
			return;
		}
		if ( $flag === 'failed' || $flag === 'error' ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Gift card reissue could not be completed.', 'mp-commerce-promotions' ) . '</p></div>';
		}
	}
}
