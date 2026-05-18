<?php
/**
 * WooCommerce My Account: gift cards and store credit wallet.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCardCustomerService;
use MP\CommercePromotions\GiftCard\GiftCardTransaction;
use MP\CommercePromotions\GiftCard\StoreCreditWallet;
use MP\CommercePromotions\Service\Settings;
use WP_User;

final class GiftCardMyAccount {

	public const ENDPOINT_GIFT_CARDS = 'gift-cards';

	private const SESSION_REVEAL_KEY = 'mp_cp_gift_code_reveal_once';

	private GiftCardCustomerService $customer_cards;

	private StoreCreditWallet $wallet;

	private Settings $settings;

	public function __construct(
		GiftCardCustomerService $customer_cards,
		StoreCreditWallet $wallet,
		Settings $settings
	) {
		$this->customer_cards = $customer_cards;
		$this->wallet         = $wallet;
		$this->settings       = $settings;
	}

	public function register(): void {
		if ( ! $this->settings->gift_card_my_account_enabled() ) {
			return;
		}

		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT_GIFT_CARDS . '_endpoint', array( $this, 'render_endpoint' ) );
	}

	public function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT_GIFT_CARDS, EP_ROOT | EP_PAGES );
	}

	/**
	 * @param array<string, string> $items
	 * @return array<string, string>
	 */
	public function add_menu_item( array $items ): array {
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'orders' ) {
				$new[ self::ENDPOINT_GIFT_CARDS ] = __( 'Gift cards', 'mp-commerce-promotions' );
			}
		}
		if ( ! isset( $new[ self::ENDPOINT_GIFT_CARDS ] ) ) {
			$new[ self::ENDPOINT_GIFT_CARDS ] = __( 'Gift cards', 'mp-commerce-promotions' );
		}

		return $new;
	}

	public function render_endpoint(): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'Please sign in to view your gift cards.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		GiftCardCustomerAssets::enqueue();

		$user    = wp_get_current_user();
		$user_id = $user instanceof WP_User ? (int) $user->ID : 0;
		$email   = $user instanceof WP_User ? sanitize_email( (string) $user->user_email ) : '';

		$purchased = $this->customer_cards->list_purchased( $user_id );
		$received  = $this->customer_cards->list_received( $email );
		$reveal    = $this->consume_reveal_code();

		echo '<div class="mp-cp-gift-card-my-account">';
		echo '<h2>' . esc_html__( 'My gift cards', 'mp-commerce-promotions' ) . '</h2>';

		if ( $reveal !== '' ) {
			echo '<p class="mp-cp-gc-help">' . esc_html__( 'Code from your recent checkout (shown once):', 'mp-commerce-promotions' ) . '</p>';
			echo '<p><code class="mp-cp-gc-reveal-code" id="mp_cp_gc_reveal">' . esc_html( $reveal ) . '</code> ';
			echo '<button type="button" class="button button-small" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById(\'mp_cp_gc_reveal\').innerText)">'
				. esc_html__( 'Copy code', 'mp-commerce-promotions' ) . '</button></p>';
		}

		if ( $purchased === array() && $received === array() && $reveal === '' ) {
			echo '<p class="mp-cp-gc-help">' . esc_html__(
				'You have not purchased or received any gift cards yet. Gift cards you buy or receive by email will appear here with masked codes and balances.',
				'mp-commerce-promotions'
			) . '</p>';
			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$shop = wc_get_page_permalink( 'shop' );
				if ( is_string( $shop ) && $shop !== '' ) {
					echo '<p><a class="button" href="' . esc_url( $shop ) . '">' . esc_html__( 'Browse products', 'mp-commerce-promotions' ) . '</a></p>';
				}
			}
		}

		$this->render_card_table( __( 'Purchased', 'mp-commerce-promotions' ), $purchased );
		$this->render_card_table( __( 'Received', 'mp-commerce-promotions' ), $received );

		$this->render_store_credit_section( $user_id );
		echo '</div>';
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function render_card_table( string $title, array $rows ): void {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( $rows === array() ) {
			$hint = $title === __( 'Purchased', 'mp-commerce-promotions' )
				? __( 'Gift cards you buy for yourself or others will show here after checkout.', 'mp-commerce-promotions' )
				: __( 'Gift cards sent to your account email will appear here.', 'mp-commerce-promotions' );
			echo '<p class="mp-cp-gc-help">' . esc_html( $hint ) . '</p>';
			return;
		}

		echo '<table class="shop_table mp-cp-gc-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Code', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Balance', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Expires', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Delivery', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$balance = function_exists( 'wc_price' )
				? wp_strip_all_tags( wc_price( (float) ( $row['balance'] ?? 0 ), array( 'currency' => (string) ( $row['currency'] ?? '' ) ) ) )
				: number_format( (float) ( $row['balance'] ?? 0 ), 2 );
			$delivery = (string) ( $row['delivery_label'] ?? '' );
			if ( $delivery === '' ) {
				$delivery = GiftCardCustomerService::format_delivery_label(
					array(
						'delivery_status' => (string) ( $row['delivery_status'] ?? '' ),
						'delivered_at'    => (string) ( $row['delivered_at'] ?? '' ),
						'scheduled_for'   => (string) ( $row['scheduled_for'] ?? '' ),
					)
				);
			}

			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['masked_code'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $balance ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['status_label'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['expires_at'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( $delivery ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_store_credit_section( int $customer_id ): void {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
		$balance  = $this->wallet->get_balance( $customer_id, $currency );

		echo '<div class="mp-cp-gc-wallet-card" style="margin-top:2em;">';
		echo '<h3>' . esc_html__( 'Store credit wallet', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="mp-cp-gc-help">' . esc_html__( 'Use store credit at checkout on eligible carts. It can be combined with a gift card when both are available.', 'mp-commerce-promotions' ) . '</p>';
		echo '<p class="mp-cp-gc-wallet-balance">';
		echo esc_html(
			sprintf(
				/* translators: %s: formatted balance */
				__( 'Available: %s', 'mp-commerce-promotions' ),
				function_exists( 'wc_price' )
					? wp_strip_all_tags( wc_price( $balance, array( 'currency' => $currency ) ) )
					: number_format( $balance, 2 ) . ' ' . $currency
			)
		);
		echo '</p>';

		$txs = array_slice( $this->wallet->transactions_for_customer( $customer_id, $currency ), 0, 20 );
		if ( $balance <= 0 && $txs === array() ) {
			echo '<p class="mp-cp-gc-help">' . esc_html__( 'No store credit balance yet. Refunds or admin grants will appear here.', 'mp-commerce-promotions' ) . '</p>';
			echo '</div>';
			return;
		}
		if ( $txs === array() ) {
			echo '</div>';
			return;
		}

		echo '<table class="shop_table mp-cp-gc-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Note', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $txs as $tx ) {
			$type = (string) $tx->get_transaction_type();
			$label = $this->transaction_label( $type );
			$amount = function_exists( 'wc_price' )
				? wp_strip_all_tags( wc_price( abs( $tx->get_amount() ), array( 'currency' => $currency ) ) )
				: number_format( abs( $tx->get_amount() ), 2 );

			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $tx->get_created_at() ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( $amount ) . '</td>';
			echo '<td>' . esc_html( (string) ( $tx->get_note() ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function transaction_label( string $type ): string {
		switch ( $type ) {
			case GiftCardTransaction::TYPE_ISSUED:
				return __( 'Credit added', 'mp-commerce-promotions' );
			case GiftCardTransaction::TYPE_REDEEMED:
				return __( 'Redeemed at checkout', 'mp-commerce-promotions' );
			case GiftCardTransaction::TYPE_ADJUSTED:
				return __( 'Adjustment', 'mp-commerce-promotions' );
			case GiftCardTransaction::TYPE_VOIDED:
				return __( 'Voided', 'mp-commerce-promotions' );
			default:
				return ucfirst( str_replace( '_', ' ', $type ) );
		}
	}

	private function consume_reveal_code(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}

		$code = (string) WC()->session->get( self::SESSION_REVEAL_KEY, '' );
		if ( $code !== '' ) {
			WC()->session->set( self::SESSION_REVEAL_KEY, '' );
		}

		return $code;
	}

	public static function stash_reveal_code( string $plain_code ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session || $plain_code === '' ) {
			return;
		}

		WC()->session->set( self::SESSION_REVEAL_KEY, $plain_code );
	}
}
