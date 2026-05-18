<?php
/**
 * Gift Cards & Store Credit admin (issue, list, adjust, void).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use InvalidArgumentException;
use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use RuntimeException;

final class GiftCardsPage {

	private const NONCE_ISSUE = 'mp_cp_issue_gift_card';

	private const NONCE_ADJUST = 'mp_cp_adjust_gift_card';

	private const NONCE_VOID = 'mp_cp_void_gift_card';

	private GiftCardLedger $ledger;

	private GiftCardRepository $cards;

	/** @var array{plain_code?: string, card_id?: int}|null */
	private ?array $flash_issue = null;

	public function __construct( GiftCardLedger $ledger, GiftCardRepository $cards ) {
		$this->ledger = $ledger;
		$this->cards  = $cards;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->handle_post();

		$detail_id = isset( $_GET['gift_card_id'] ) ? (int) $_GET['gift_card_id'] : 0;

		echo '<div class="wrap mp-cg-gift-cards-wrap">';
		echo '<h1>' . esc_html__( 'Gift Cards & Store Credit', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_GIFT_CARDS );

		$this->render_notices();

		if ( $detail_id > 0 ) {
			$this->render_detail( $detail_id );
		} else {
			$this->render_issue_form();
			if ( $this->flash_issue !== null ) {
				$this->render_issue_success();
			}
			$this->render_list();
		}

		echo '</div>';
	}

	private function handle_post(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) !== 'POST' ) {
			return;
		}

		if ( isset( $_POST['mp_cp_gift_card_issue'] ) ) {
			$this->handle_issue();
			return;
		}

		if ( isset( $_POST['mp_cp_gift_card_adjust'] ) ) {
			$this->handle_adjust();
			return;
		}

		if ( isset( $_POST['mp_cp_gift_card_void'] ) ) {
			$this->handle_void();
		}
	}

	private function handle_issue(): void {
		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_ISSUE )
		) {
			AdminNotice::error( __( 'Security check failed.', 'mp-commerce-promotions' ) );
			return;
		}

		$amount = isset( $_POST['amount'] ) ? (float) wp_unslash( (string) $_POST['amount'] ) : 0.0;
		$currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['currency'] ) ) : '';
		if ( $currency === '' && function_exists( 'get_woocommerce_currency' ) ) {
			$currency = get_woocommerce_currency();
		}

		$expires = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['expires_at'] ) ) : '';
		$expires = $expires !== '' ? $expires . ' 23:59:59' : null;

		$email = isset( $_POST['recipient_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['recipient_email'] ) ) : '';
		$note  = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) ) : '';

		try {
			$result = $this->ledger->issue( $amount, $currency, $expires, $email !== '' ? $email : null, $note !== '' ? $note : null );
			$id     = $result->get_card()->get_id();
			$this->flash_issue = array(
				'plain_code' => $result->get_plain_code(),
				'card_id'    => $id ?? 0,
			);
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			AdminNotice::error( esc_html( $e->getMessage() ) );
		}
	}

	private function handle_adjust(): void {
		$card_id = isset( $_POST['gift_card_id'] ) ? (int) $_POST['gift_card_id'] : 0;
		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_ADJUST . '_' . $card_id )
		) {
			AdminNotice::error( __( 'Security check failed.', 'mp-commerce-promotions' ) );
			return;
		}

		$delta = isset( $_POST['adjust_amount'] ) ? (float) wp_unslash( (string) $_POST['adjust_amount'] ) : 0.0;
		$note  = isset( $_POST['adjust_note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['adjust_note'] ) ) : '';

		try {
			$this->ledger->adjust( $card_id, $delta, $note );
			AdminNotice::success( __( 'Gift card balance adjusted.', 'mp-commerce-promotions' ) );
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			AdminNotice::error( esc_html( $e->getMessage() ) );
		}
	}

	private function handle_void(): void {
		$card_id = isset( $_POST['gift_card_id'] ) ? (int) $_POST['gift_card_id'] : 0;
		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_VOID . '_' . $card_id )
		) {
			AdminNotice::error( __( 'Security check failed.', 'mp-commerce-promotions' ) );
			return;
		}

		$note = isset( $_POST['void_note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['void_note'] ) ) : '';

		try {
			$this->ledger->void_card( $card_id, $note );
			AdminNotice::success( __( 'Gift card voided.', 'mp-commerce-promotions' ) );
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			AdminNotice::error( esc_html( $e->getMessage() ) );
		}
	}

	private function render_notices(): void {
		// AdminNotice renders on next request for redirects; inline for same-request POST.
	}

	private function render_issue_form(): void {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';

		echo '<h2>' . esc_html__( 'Issue gift card', 'mp-commerce-promotions' ) . '</h2>';
		echo '<form method="post" class="mp-cg-issue-form" style="max-width:520px;">';
		wp_nonce_field( self::NONCE_ISSUE );
		echo '<input type="hidden" name="mp_cp_gift_card_issue" value="1" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="mp_cp_gc_amount">' . esc_html__( 'Amount', 'mp-commerce-promotions' ) . '</label></th>';
		echo '<td><input name="amount" id="mp_cp_gc_amount" type="number" step="0.01" min="0.01" class="regular-text" required /></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gc_currency">' . esc_html__( 'Currency', 'mp-commerce-promotions' ) . '</label></th>';
		echo '<td><input name="currency" id="mp_cp_gc_currency" type="text" class="regular-text" value="' . esc_attr( $currency ) . '" maxlength="10" /></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gc_expires">' . esc_html__( 'Expires (optional)', 'mp-commerce-promotions' ) . '</label></th>';
		echo '<td><input name="expires_at" id="mp_cp_gc_expires" type="date" class="regular-text" /></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gc_email">' . esc_html__( 'Recipient email (optional)', 'mp-commerce-promotions' ) . '</label></th>';
		echo '<td><input name="recipient_email" id="mp_cp_gc_email" type="email" class="regular-text" /></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gc_note">' . esc_html__( 'Note (optional)', 'mp-commerce-promotions' ) . '</label></th>';
		echo '<td><textarea name="note" id="mp_cp_gc_note" class="large-text" rows="2"></textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Issue gift card', 'mp-commerce-promotions' ) );
		echo '</form>';
	}

	private function render_issue_success(): void {
		if ( $this->flash_issue === null || ! isset( $this->flash_issue['plain_code'] ) ) {
			return;
		}

		$plain = (string) $this->flash_issue['plain_code'];
		$card  = isset( $this->flash_issue['card_id'] ) ? $this->cards->find( (int) $this->flash_issue['card_id'] ) : null;

		echo '<div class="notice notice-success" style="padding:12px;max-width:640px;"><p><strong>'
			. esc_html__( 'Gift card issued', 'mp-commerce-promotions' ) . '</strong></p>';
		echo '<p><code style="font-size:16px;user-select:all;">' . esc_html( $plain ) . '</code></p>';
		if ( $card !== null ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: balance, 2: currency */
					__( 'Balance: %1$s %2$s', 'mp-commerce-promotions' ),
					number_format_i18n( $card->get_balance(), 2 ),
					$card->get_currency()
				)
			) . '</p>';
		}
		echo '<p><strong>' . esc_html__( 'Copy now. The full code is not stored.', 'mp-commerce-promotions' ) . '</strong></p></div>';
	}

	private function render_list(): void {
		$list = $this->cards->list_recent( 50, 0 );

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Gift cards', 'mp-commerce-promotions' ) . '</h2>';
		if ( $list === array() ) {
			echo '<p>' . esc_html__( 'No gift cards issued yet.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Last 4', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Balance / initial', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Currency', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Expires', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Recipient', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $list as $card ) {
			$id = $card->get_id();
			if ( $id === null ) {
				continue;
			}
			$view_url = add_query_arg(
				array(
					'page'         => AdminNavigation::PAGE_SLUG,
					'tab'          => AdminNavigation::TAB_GIFT_CARDS,
					'gift_card_id' => $id,
				),
				admin_url( 'admin.php' )
			);
			echo '<tr>';
			echo '<td>' . esc_html( (string) $id ) . '</td>';
			echo '<td>****' . esc_html( $card->get_code_last4() ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $card->get_balance(), 2 ) ) . ' / ' . esc_html( number_format_i18n( $card->get_initial_amount(), 2 ) ) . '</td>';
			echo '<td>' . esc_html( $card->get_currency() ) . '</td>';
			echo '<td>' . esc_html( $card->get_status() ) . '</td>';
			echo '<td>' . esc_html( $card->get_expires_at() ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $card->get_recipient_email() ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $card->get_created_at() ?? '' ) . '</td>';
			echo '<td><a href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'mp-commerce-promotions' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_detail( int $gift_card_id ): void {
		$card = $this->cards->find( $gift_card_id );
		if ( $card === null ) {
			echo '<p>' . esc_html__( 'Gift card not found.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		$list_url = AdminUrl::tab( AdminNavigation::TAB_GIFT_CARDS );
		echo '<p><a href="' . esc_url( $list_url ) . '">' . esc_html__( '← Back to gift cards', 'mp-commerce-promotions' ) . '</a></p>';

		echo '<h2>' . esc_html( sprintf( __( 'Gift card #%d', 'mp-commerce-promotions' ), $gift_card_id ) ) . '</h2>';
		echo '<table class="widefat" style="max-width:640px;"><tbody>';
		$this->detail_row( __( 'Last 4', 'mp-commerce-promotions' ), '****' . $card->get_code_last4() );
		$this->detail_row( __( 'Balance', 'mp-commerce-promotions' ), number_format_i18n( $card->get_balance(), 2 ) . ' ' . $card->get_currency() );
		$this->detail_row( __( 'Initial amount', 'mp-commerce-promotions' ), number_format_i18n( $card->get_initial_amount(), 2 ) );
		$this->detail_row( __( 'Status', 'mp-commerce-promotions' ), $card->get_status() );
		$this->detail_row( __( 'Expires', 'mp-commerce-promotions' ), $card->get_expires_at() ?? '—' );
		$this->detail_row( __( 'Recipient email', 'mp-commerce-promotions' ), $card->get_recipient_email() ?? '—' );
		$this->detail_row( __( 'UUID', 'mp-commerce-promotions' ), $card->get_gift_card_uuid() );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Transaction ledger', 'mp-commerce-promotions' ) . '</h3>';
		$txs = $this->ledger->transactions_for_card( $gift_card_id );
		if ( $txs === array() ) {
			echo '<p>' . esc_html__( 'No transactions.', 'mp-commerce-promotions' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Date', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Amount', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Balance after', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Order', 'mp-commerce-promotions' ) . '</th>';
			echo '<th>' . esc_html__( 'Note', 'mp-commerce-promotions' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $txs as $tx ) {
				echo '<tr>';
				echo '<td>' . esc_html( $tx->get_created_at() ?? '' ) . '</td>';
				echo '<td>' . esc_html( $tx->get_transaction_type() ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $tx->get_amount(), 2 ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $tx->get_balance_after(), 2 ) ) . '</td>';
				echo '<td>' . esc_html( $tx->get_order_id() !== null ? (string) $tx->get_order_id() : '—' ) . '</td>';
				echo '<td>' . esc_html( $tx->get_note() ?? '' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		if ( $card->get_status() !== GiftCard::STATUS_VOIDED ) {
			echo '<h3>' . esc_html__( 'Adjust balance', 'mp-commerce-promotions' ) . '</h3>';
			echo '<form method="post" style="max-width:480px;">';
			wp_nonce_field( self::NONCE_ADJUST . '_' . $gift_card_id );
			echo '<input type="hidden" name="mp_cp_gift_card_adjust" value="1" />';
			echo '<input type="hidden" name="gift_card_id" value="' . esc_attr( (string) $gift_card_id ) . '" />';
			echo '<p><label>' . esc_html__( 'Amount (+/-)', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<input type="number" step="0.01" name="adjust_amount" class="regular-text" required /></p>';
			echo '<p><label>' . esc_html__( 'Note (required)', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<textarea name="adjust_note" class="large-text" rows="2" required></textarea></p>';
			submit_button( __( 'Adjust balance', 'mp-commerce-promotions' ), 'secondary' );
			echo '</form>';

			echo '<h3>' . esc_html__( 'Void gift card', 'mp-commerce-promotions' ) . '</h3>';
			echo '<form method="post" style="max-width:480px;">';
			wp_nonce_field( self::NONCE_VOID . '_' . $gift_card_id );
			echo '<input type="hidden" name="mp_cp_gift_card_void" value="1" />';
			echo '<input type="hidden" name="gift_card_id" value="' . esc_attr( (string) $gift_card_id ) . '" />';
			echo '<p><label>' . esc_html__( 'Note (required)', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<textarea name="void_note" class="large-text" rows="2" required></textarea></p>';
			submit_button( __( 'Void gift card', 'mp-commerce-promotions' ), 'delete' );
			echo '</form>';
		}
	}

	private function detail_row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
