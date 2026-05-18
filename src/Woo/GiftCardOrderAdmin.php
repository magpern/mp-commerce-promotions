<?php
/**
 * WooCommerce order admin: generated gift cards.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Admin\AdminNavigation;
use MP\CommercePromotions\Admin\AdminUrl;
use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardRepository;

final class GiftCardOrderAdmin {

	private GiftCardRepository $cards;

	public function __construct( GiftCardRepository $cards ) {
		$this->cards = $cards;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
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

		$rows = GiftCardGeneratedOrderState::get_generated( $order );
		if ( $rows === array() ) {
			echo '<p>' . esc_html__( 'No gift cards generated for this order.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat" style="margin:0;"><tbody>';
		foreach ( $rows as $row ) {
			$card_id = (int) $row['gift_card_id'];
			$card    = $this->cards->find( $card_id );
			if ( $card === null ) {
				continue;
			}

			$detail_url = add_query_arg(
				array(
					'page'         => AdminNavigation::PAGE_SLUG,
					'tab'          => AdminNavigation::TAB_GIFT_CARDS,
					'gift_card_id' => $card_id,
				),
				admin_url( 'admin.php' )
			);

			echo '<tr><td style="padding:8px;">';
			echo '<strong>****' . esc_html( $card->get_code_last4() ) . '</strong><br />';
			echo esc_html(
				number_format_i18n( $card->get_balance(), 2 ) . ' / '
				. number_format_i18n( $card->get_initial_amount(), 2 ) . ' ' . $card->get_currency()
			);
			echo '<br /><span class="description">' . esc_html( $card->get_status() ) . '</span>';

			if ( isset( $row['plain_code'] ) && is_string( $row['plain_code'] ) && $row['plain_code'] !== '' ) {
				echo '<br /><code style="user-select:all;">' . esc_html( $row['plain_code'] ) . '</code>';
				echo '<br /><em class="description">' . esc_html__( 'Code shown once here after generation.', 'mp-commerce-promotions' ) . '</em>';
			}

			echo '<br /><a href="' . esc_url( $detail_url ) . '">' . esc_html__( 'View gift card', 'mp-commerce-promotions' ) . '</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
