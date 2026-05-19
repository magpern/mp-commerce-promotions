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
use MP\CommercePromotions\GiftCard\GiftCardCurrency;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardManualDeliveryStore;
use MP\CommercePromotions\GiftCard\GiftCardManualIssueDelivery;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardSourceLabel;
use MP\CommercePromotions\GiftCard\GiftCardPilotReadiness;
use MP\CommercePromotions\GiftCard\GiftCardQaProductSetup;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardMailDiagnostics;
use MP\CommercePromotions\GiftCard\GiftCardTransferService;
use MP\CommercePromotions\GiftCard\GiftCardTransferStore;
use MP\CommercePromotions\GiftCard\StoreCreditAccountService;
use MP\CommercePromotions\GiftCard\StoreCreditWallet;
use MP\CommercePromotions\Service\Settings;
use RuntimeException;

final class GiftCardsPage {

	private const NONCE_ISSUE = 'mp_cp_issue_gift_card';

	private const NONCE_ADJUST = 'mp_cp_adjust_gift_card';

	private const NONCE_VOID = 'mp_cp_void_gift_card';

	private const NONCE_TRANSFER = 'mp_cp_transfer_gift_card';

	private const NONCE_SC_GRANT = 'mp_cp_store_credit_grant';

	private const NONCE_SC_DEDUCT = 'mp_cp_store_credit_deduct';

	private const NONCE_SC_REFUND = 'mp_cp_store_credit_refund_order';

	private GiftCardLedger $ledger;

	private GiftCardRepository $cards;

	private StoreCreditWallet $store_credit;

	private StoreCreditAccountService $store_credit_accounts;

	private GiftCardManualIssueDelivery $manual_delivery;

	private GiftCardTransferService $transfers;

	private Settings $settings;

	private GiftCardSettingsHandler $gift_card_settings;

	private GiftCardExportHandler $gift_card_exports;

	/** @var array{plain_code?: string, card_id?: int, delivery?: array<string, string>}|null */
	private ?array $flash_issue = null;

	/** @var int|null */
	private ?int $selected_customer_id = null;

	public function __construct(
		GiftCardLedger $ledger,
		GiftCardRepository $cards,
		StoreCreditWallet $store_credit,
		StoreCreditAccountService $store_credit_accounts,
		?GiftCardManualIssueDelivery $manual_delivery = null,
		?GiftCardTransferService $transfers = null,
		?Settings $settings = null,
		?GiftCardSettingsHandler $gift_card_settings = null,
		?GiftCardExportHandler $gift_card_exports = null
	) {
		$this->ledger                 = $ledger;
		$this->cards                  = $cards;
		$this->store_credit           = $store_credit;
		$this->store_credit_accounts  = $store_credit_accounts;
		$this->settings               = $settings ?? new Settings();
		$this->gift_card_settings     = $gift_card_settings ?? new GiftCardSettingsHandler( $this->settings );
		$this->gift_card_exports      = $gift_card_exports ?? new GiftCardExportHandler();
		$this->manual_delivery        = $manual_delivery ?? new GiftCardManualIssueDelivery(
			new \MP\CommercePromotions\GiftCard\GiftCardDeliveryMailer( $this->settings ),
			new GiftCardManualDeliveryStore()
		);
		$this->transfers              = $transfers ?? new GiftCardTransferService( $ledger, $cards );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->gift_card_exports->maybe_send_export();
		$this->gift_card_settings->handle_template_reset();
		$this->gift_card_settings->handle_post_save();
		$this->gift_card_settings->handle_settings_test_email();
		$this->handle_post();

		$section   = GiftCardModuleSections::current_section();
		$detail_id = isset( $_GET['gift_card_id'] ) ? (int) $_GET['gift_card_id'] : 0;

		if ( isset( $_GET['customer_id'] ) ) {
			$this->selected_customer_id = max( 0, (int) $_GET['customer_id'] );
		}

		echo '<div class="wrap mp-cg-gift-cards-wrap">';
		echo '<h1>' . esc_html__( 'Gift Cards & Store Credit', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_GIFT_CARDS );

		global $wpdb;
		if ( $wpdb instanceof \wpdb ) {
			GiftCardPilotReadiness::render_admin_email_delivery_warning( $wpdb );
		}

		GiftCardModuleSections::render_sub_nav( $section );
		$this->render_notices();

		switch ( $section ) {
			case GiftCardModuleSections::SECTION_SETTINGS:
				$this->render_settings_section();
				break;
			case GiftCardModuleSections::SECTION_STORE_CREDIT:
				$this->render_store_credit_panel();
				break;
			case GiftCardModuleSections::SECTION_GIFT_CARDS:
				if ( $detail_id > 0 ) {
					$this->render_detail( $detail_id );
				} else {
					$this->render_issue_form();
					if ( $this->flash_issue !== null ) {
						$this->render_issue_success();
					}
					$this->render_list();
				}
				break;
			case GiftCardModuleSections::SECTION_DASHBOARD:
			default:
				$this->render_dashboard( $wpdb instanceof \wpdb ? $wpdb : null );
				break;
		}

		echo '</div>';
	}

	private function render_settings_section(): void {
		$this->gift_card_exports->render_export_panel();
		$this->gift_card_settings->render();
	}

	/**
	 * @param \wpdb|null $wpdb
	 */
	private function render_dashboard( ?\wpdb $wpdb ): void {
		$gc_liability    = 0.0;
		$sc_liability    = 0.0;
		$pending_sched   = 0;
		$failed_emails   = 0;
		$active_products = 0;

		if ( $wpdb instanceof \wpdb ) {
			$summary       = ( new GiftCardReports( $wpdb ) )->summary();
			$gc_liability  = (float) ( $summary['gift_card_outstanding_liability'] ?? 0 );
			$sc_liability  = (float) ( $summary['store_credit_outstanding_liability'] ?? 0 );
			$pending_sched = (int) ( $summary['scheduled_pending'] ?? 0 );
			$failed_emails = (int) ( $summary['gift_cards_delivery_failed'] ?? 0 );
			$mail_diag     = ( new GiftCardMailDiagnostics( $wpdb, $this->settings ) )->analyze();
			$failed_emails = max( $failed_emails, (int) ( $mail_diag['recent_delivery_failed'] ?? 0 ) );
		}

		if ( function_exists( 'wc_get_orders' ) ) {
			$active_products = GiftCardQaProductSetup::count_published_gift_card_products();
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$format_amount = static function ( float $amount ) use ( $currency ): string {
			if ( function_exists( 'wc_price' ) ) {
				return (string) wc_price( $amount, array( 'currency' => $currency ) );
			}

			return number_format( $amount, 2 ) . ( $currency !== '' ? ' ' . $currency : '' );
		};

		echo '<div class="mp-cg-gc-dashboard" style="margin-top:1em;">';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:1.5em;">';
		$this->render_dashboard_stat_card( __( 'Gift card liability', 'mp-commerce-promotions' ), $format_amount( $gc_liability ) );
		$this->render_dashboard_stat_card( __( 'Store credit liability', 'mp-commerce-promotions' ), $format_amount( $sc_liability ) );
		$this->render_dashboard_stat_card( __( 'Pending scheduled deliveries', 'mp-commerce-promotions' ), (string) $pending_sched );
		$this->render_dashboard_stat_card( __( 'Failed gift card emails', 'mp-commerce-promotions' ), (string) $failed_emails );
		$this->render_dashboard_stat_card( __( 'Active gift card products', 'mp-commerce-promotions' ), (string) $active_products );
		echo '</div>';

		echo '<h2 class="title">' . esc_html__( 'Shortcuts', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="mp-cg-gc-shortcuts">';
		$shortcuts = array(
			array( GiftCardModuleSections::section_url( GiftCardModuleSections::SECTION_GIFT_CARDS ), __( 'Issue gift card', 'mp-commerce-promotions' ) ),
			array( GiftCardModuleSections::section_url( GiftCardModuleSections::SECTION_GIFT_CARDS ), __( 'Manage gift cards', 'mp-commerce-promotions' ) ),
			array( GiftCardModuleSections::section_url( GiftCardModuleSections::SECTION_STORE_CREDIT ), __( 'Manage store credit', 'mp-commerce-promotions' ) ),
			array( GiftCardModuleSections::section_url( GiftCardModuleSections::SECTION_SETTINGS ), __( 'Settings', 'mp-commerce-promotions' ) ),
			array( AdminNavigation::tab_url( AdminNavigation::TAB_REPORTS ), __( 'Reports', 'mp-commerce-promotions' ) ),
			array( AdminNavigation::tab_url( AdminNavigation::TAB_DIAGNOSTICS ), __( 'Diagnostics', 'mp-commerce-promotions' ) ),
		);
		foreach ( $shortcuts as $idx => $link ) {
			if ( $idx > 0 ) {
				echo ' · ';
			}
			echo '<a href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a>';
		}
		echo '</p>';
		$this->gift_card_exports->render_export_panel();
		echo '</div>';
	}

	private function render_dashboard_stat_card( string $label, string $value ): void {
		echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;border-radius:4px;">';
		echo '<p style="margin:0 0 4px;font-size:12px;color:#646970;">' . esc_html( $label ) . '</p>';
		echo '<p style="margin:0;font-size:20px;font-weight:600;">' . wp_kses_post( $value ) . '</p>';
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
			return;
		}

		if ( isset( $_POST['mp_cp_gift_card_transfer'] ) ) {
			$this->handle_transfer();
			return;
		}

		if ( isset( $_POST['mp_cp_store_credit_grant'] ) ) {
			$this->handle_store_credit_grant();
			return;
		}

		if ( isset( $_POST['mp_cp_store_credit_deduct'] ) ) {
			$this->handle_store_credit_deduct();
			return;
		}

		if ( isset( $_POST['mp_cp_store_credit_refund_order'] ) ) {
			$this->handle_store_credit_refund_order();
		}
	}

	private function handle_store_credit_grant(): void {
		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_SC_GRANT )
		) {
			AdminNotice::error( __( 'Security check failed.', 'mp-commerce-promotions' ) );
			return;
		}

		$customer_id = $this->resolve_post_customer_id();
		$amount      = isset( $_POST['sc_amount'] ) ? (float) wp_unslash( (string) $_POST['sc_amount'] ) : 0.0;
		$currency    = $this->resolve_post_currency();
		$note        = isset( $_POST['sc_note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['sc_note'] ) ) : '';

		try {
			$this->store_credit->grant_credit( $customer_id, $amount, $currency, $note );
			AdminNotice::success( __( 'Store credit granted.', 'mp-commerce-promotions' ) );
			$this->selected_customer_id = $customer_id;
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			AdminNotice::error( esc_html( $e->getMessage() ) );
		}
	}

	private function handle_store_credit_deduct(): void {
		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_SC_DEDUCT )
		) {
			AdminNotice::error( __( 'Security check failed.', 'mp-commerce-promotions' ) );
			return;
		}

		$customer_id = $this->resolve_post_customer_id();
		$amount      = isset( $_POST['sc_amount'] ) ? (float) wp_unslash( (string) $_POST['sc_amount'] ) : 0.0;
		$currency    = $this->resolve_post_currency();
		$note        = isset( $_POST['sc_note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['sc_note'] ) ) : '';

		try {
			$this->store_credit->deduct_credit( $customer_id, $amount, $currency, $note );
			AdminNotice::success( __( 'Store credit deducted.', 'mp-commerce-promotions' ) );
			$this->selected_customer_id = $customer_id;
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			AdminNotice::error( esc_html( $e->getMessage() ) );
		}
	}

	private function handle_store_credit_refund_order(): void {
		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_SC_REFUND )
		) {
			AdminNotice::error( __( 'Security check failed.', 'mp-commerce-promotions' ) );
			return;
		}

		$order_id = isset( $_POST['sc_order_id'] ) ? (int) $_POST['sc_order_id'] : 0;
		$amount   = isset( $_POST['sc_refund_amount'] ) ? (float) wp_unslash( (string) $_POST['sc_refund_amount'] ) : 0.0;
		$note     = isset( $_POST['sc_refund_note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['sc_refund_note'] ) ) : '';

		try {
			$card = $this->store_credit->refund_order_to_credit( $order_id, $amount, $note );
			AdminNotice::success( __( 'Order amount credited to customer store credit.', 'mp-commerce-promotions' ) );
			$owner = $card->get_owner_customer_id();
			if ( $owner !== null && $owner > 0 ) {
				$this->selected_customer_id = $owner;
			}
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			AdminNotice::error( esc_html( $e->getMessage() ) );
		}
	}

	private function resolve_post_customer_id(): int {
		$query = isset( $_POST['sc_customer'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['sc_customer'] ) ) : '';
		$id    = $this->store_credit_accounts->resolve_customer_id( $query );
		if ( $id === null || $id <= 0 ) {
			throw new InvalidArgumentException( __( 'Customer not found. Use ID, email, or login.', 'mp-commerce-promotions' ) );
		}

		return $id;
	}

	private function resolve_post_currency(): string {
		$raw = isset( $_POST['sc_currency'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['sc_currency'] ) ) : '';

		return GiftCardCurrency::validate( $raw );
	}

	private function resolve_panel_currency(): string {
		if ( isset( $_GET['sc_currency'] ) ) {
			$raw = sanitize_text_field( wp_unslash( (string) $_GET['sc_currency'] ) );
			if ( GiftCardCurrency::is_allowed( $raw ) ) {
				return GiftCardCurrency::normalize( $raw );
			}
		}

		return GiftCardCurrency::store_currency();
	}

	/**
	 * @param array<string, string> $currencies
	 */
	private function render_currency_select( string $field_name, string $field_id, string $selected, array $currencies ): void {
		echo '<select name="' . esc_attr( $field_name ) . '" id="' . esc_attr( $field_id ) . '" class="regular-text">';
		foreach ( $currencies as $code => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s (%1$s)</option>',
				esc_attr( $code ),
				selected( $selected, $code, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	private function render_store_credit_panel(): void {
		$currency    = $this->resolve_panel_currency();
		$currencies  = GiftCardCurrency::allowed_currencies();
		$customer = $this->selected_customer_id;
		$balance  = 0.0;
		$wallet   = null;

		if ( $customer !== null && $customer > 0 ) {
			$balance = $this->store_credit->get_balance( $customer, $currency );
			$wallet  = $this->store_credit_accounts->find_wallet( $customer, $currency );
		}

		echo '<h2>' . esc_html__( 'Customer store credit', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p>' . esc_html__( 'Store credit is tied to a customer account (no code at checkout when logged in). Gift cards remain code-based.', 'mp-commerce-promotions' ) . '</p>';

		$search_url = GiftCardModuleSections::section_url( GiftCardModuleSections::SECTION_STORE_CREDIT );
		echo '<form method="get" style="max-width:520px;margin-bottom:1.5em;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminNavigation::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( AdminNavigation::TAB_GIFT_CARDS ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( GiftCardModuleSections::QUERY_ARG ) . '" value="'
			. esc_attr( GiftCardModuleSections::SECTION_STORE_CREDIT ) . '" />';
		echo '<p><label for="sc_currency_lookup">' . esc_html__( 'Currency', 'mp-commerce-promotions' ) . '</label><br />';
		$this->render_currency_select( 'sc_currency', 'sc_currency_lookup', $currency, $currencies );
		echo '</p>';
		echo '<p><label for="sc_customer_lookup">' . esc_html__( 'Customer (ID, email, or login)', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="sc_customer_lookup" name="sc_customer_lookup" value="';
		if ( $customer !== null && $customer > 0 ) {
			echo esc_attr( (string) $customer );
		}
		echo '" /> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Look up', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';

		if ( isset( $_GET['sc_customer_lookup'] ) && ( $customer === null || $customer <= 0 ) ) {
			$lookup = sanitize_text_field( wp_unslash( (string) $_GET['sc_customer_lookup'] ) );
			$found  = $this->store_credit_accounts->resolve_customer_id( $lookup );
			if ( $found !== null && $found > 0 ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'customer_id' => $found,
							'sc_currency' => $currency,
						),
						$search_url
					)
				);
				exit;
			}
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Customer not found.', 'mp-commerce-promotions' ) . '</p></div>';
		}

		if ( $customer === null || $customer <= 0 ) {
			return;
		}

		$user = get_user_by( 'id', $customer );
		$label = $user instanceof \WP_User
			? sprintf( '#%d %s (%s)', $customer, $user->display_name, $user->user_email )
			: '#' . $customer;

		echo '<h3>' . esc_html( $label ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Balance', 'mp-commerce-promotions' ) . ':</strong> '
			. esc_html( number_format_i18n( $balance, 2 ) . ' ' . $currency ) . '</p>';

		echo '<h3>' . esc_html__( 'Grant credit', 'mp-commerce-promotions' ) . '</h3>';
		$this->render_store_credit_amount_form( self::NONCE_SC_GRANT, 'mp_cp_store_credit_grant', __( 'Grant credit', 'mp-commerce-promotions' ), $customer, $currency );

		echo '<h3>' . esc_html__( 'Deduct credit', 'mp-commerce-promotions' ) . '</h3>';
		$this->render_store_credit_amount_form( self::NONCE_SC_DEDUCT, 'mp_cp_store_credit_deduct', __( 'Deduct credit', 'mp-commerce-promotions' ), $customer, $currency );

		echo '<h3>' . esc_html__( 'Refund order to store credit', 'mp-commerce-promotions' ) . '</h3>';
		echo '<form method="post" style="max-width:480px;">';
		wp_nonce_field( self::NONCE_SC_REFUND );
		echo '<input type="hidden" name="mp_cp_store_credit_refund_order" value="1" />';
		echo '<input type="hidden" name="sc_customer" value="' . esc_attr( (string) $customer ) . '" />';
		echo '<p><label>' . esc_html__( 'Order ID', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="number" name="sc_order_id" class="regular-text" min="1" required /></p>';
		echo '<p><label>' . esc_html__( 'Amount', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="number" step="0.01" min="0.01" name="sc_refund_amount" class="regular-text" required /></p>';
		echo '<p><label>' . esc_html__( 'Note (required)', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<textarea name="sc_refund_note" class="large-text" rows="2" required></textarea></p>';
		submit_button( __( 'Refund to store credit', 'mp-commerce-promotions' ), 'secondary' );
		echo '</form>';

		echo '<h3>' . esc_html__( 'Ledger', 'mp-commerce-promotions' ) . '</h3>';
		$txs = $this->store_credit->transactions_for_customer( $customer, $currency );
		if ( $txs === array() ) {
			echo '<p>' . esc_html__( 'No transactions yet.', 'mp-commerce-promotions' ) . '</p>';
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

		if ( $wallet !== null && $wallet->get_id() !== null ) {
			echo '<p style="margin-top:1em;"><a href="'
				. esc_url(
					add_query_arg(
						array(
							'page'         => AdminNavigation::PAGE_SLUG,
							'tab'          => AdminNavigation::TAB_GIFT_CARDS,
							'gift_card_id' => $wallet->get_id(),
						),
						admin_url( 'admin.php' )
					)
				)
				. '">' . esc_html__( 'View wallet record', 'mp-commerce-promotions' ) . '</a></p>';
		}
	}

	private function render_store_credit_amount_form(
		string $nonce_action,
		string $submit_name,
		string $submit_label,
		int $customer_id,
		string $currency
	): void {
		$currencies = GiftCardCurrency::allowed_currencies();
		echo '<form method="post" style="max-width:480px;margin-bottom:1.5em;">';
		wp_nonce_field( $nonce_action );
		echo '<input type="hidden" name="' . esc_attr( $submit_name ) . '" value="1" />';
		echo '<input type="hidden" name="sc_customer" value="' . esc_attr( (string) $customer_id ) . '" />';
		echo '<p><label for="' . esc_attr( $submit_name ) . '_currency">' . esc_html__( 'Currency', 'mp-commerce-promotions' ) . '</label><br />';
		$this->render_currency_select( 'sc_currency', $submit_name . '_currency', $currency, $currencies );
		echo '</p>';
		echo '<p><label>' . esc_html__( 'Amount', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="number" step="0.01" min="0.01" name="sc_amount" class="regular-text" required /></p>';
		echo '<p><label>' . esc_html__( 'Note (required)', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<textarea name="sc_note" class="large-text" rows="2" required></textarea></p>';
		submit_button( $submit_label, 'primary', '', false );
		echo '</form>';
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

		$expires = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['expires_at'] ) ) : '';
		$expires = $expires !== '' ? $expires . ' 23:59:59' : null;

		$email = isset( $_POST['recipient_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['recipient_email'] ) ) : '';
		$note  = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) ) : '';

		try {
			$currency = GiftCardCurrency::validate(
				isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['currency'] ) ) : ''
			);
			$result   = $this->ledger->issue( $amount, $currency, $expires, $email !== '' ? $email : null, $note !== '' ? $note : null );
			$id       = $result->get_card()->get_id();
			$delivery = $this->manual_delivery->deliver_after_issue( $result, $email !== '' ? $email : null );
			$this->flash_issue = array(
				'plain_code' => $result->get_plain_code(),
				'card_id'    => $id ?? 0,
				'delivery'   => $delivery,
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

	private function handle_transfer(): void {
		$card_id = isset( $_POST['gift_card_id'] ) ? (int) $_POST['gift_card_id'] : 0;
		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_TRANSFER . '_' . $card_id )
		) {
			AdminNotice::error( __( 'Security check failed.', 'mp-commerce-promotions' ) );
			return;
		}

		$email   = isset( $_POST['transfer_recipient_email'] )
			? sanitize_email( wp_unslash( (string) $_POST['transfer_recipient_email'] ) )
			: '';
		$name    = isset( $_POST['transfer_recipient_name'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['transfer_recipient_name'] ) )
			: '';
		$message = isset( $_POST['transfer_message'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['transfer_message'] ) )
			: '';
		$note    = isset( $_POST['transfer_note'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['transfer_note'] ) )
			: '';

		$result = $this->transfers->transfer_to_new_recipient(
			$card_id,
			$email,
			$note,
			GiftCardTransferService::INITIATED_BY_ADMIN,
			null,
			$name,
			$message
		);

		if ( ! empty( $result['success'] ) ) {
			AdminNotice::success( (string) ( $result['message'] ?? '' ) );
		} else {
			AdminNotice::error( (string) ( $result['message'] ?? __( 'Transfer failed.', 'mp-commerce-promotions' ) ) );
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
		$currency   = GiftCardCurrency::store_currency();
		$currencies = GiftCardCurrency::allowed_currencies();

		echo '<h2>' . esc_html__( 'Issue gift card', 'mp-commerce-promotions' ) . '</h2>';
		echo '<form method="post" class="mp-cg-issue-form" style="max-width:520px;">';
		wp_nonce_field( self::NONCE_ISSUE );
		echo '<input type="hidden" name="mp_cp_gift_card_issue" value="1" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="mp_cp_gc_amount">' . esc_html__( 'Amount', 'mp-commerce-promotions' ) . '</label></th>';
		echo '<td><input name="amount" id="mp_cp_gc_amount" type="number" step="0.01" min="0.01" class="regular-text" required /></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_gc_currency">' . esc_html__( 'Currency', 'mp-commerce-promotions' ) . '</label></th>';
		echo '<td>';
		$this->render_currency_select( 'currency', 'mp_cp_gc_currency', $currency, $currencies );
		echo '</td></tr>';
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
		$this->render_issue_delivery_status( $this->flash_issue['delivery'] ?? array() );
		echo '<p><strong>' . esc_html__( 'Copy now. The full code is not stored.', 'mp-commerce-promotions' ) . '</strong></p></div>';
	}

	/**
	 * @param array<string, string> $delivery
	 */
	private function render_issue_delivery_status( array $delivery ): void {
		$status = (string) ( $delivery['delivery_status'] ?? '' );
		$email  = (string) ( $delivery['recipient_email'] ?? '' );

		if ( $status === GiftCardDeliveryStatus::SENT && $email !== '' ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: recipient email */
					__( 'Email sent to %s', 'mp-commerce-promotions' ),
					$email
				)
			) . '</p>';
			return;
		}

		if ( $status === GiftCardDeliveryStatus::FAILED ) {
			$reason = (string) ( $delivery['delivery_error'] ?? __( 'Unknown error', 'mp-commerce-promotions' ) );
			echo '<p><strong>' . esc_html__(
				'Email failed',
				'mp-commerce-promotions'
			) . ':</strong> ' . esc_html( $reason ) . '</p>';
			return;
		}

		if ( $status === GiftCardDeliveryStatus::DISABLED ) {
			$reason = (string) ( $delivery['delivery_error'] ?? '' );
			echo '<p>' . esc_html__( 'Email not sent: gift card delivery email is disabled in settings.', 'mp-commerce-promotions' );
			if ( $reason !== '' ) {
				echo ' ' . esc_html( $reason );
			}
			echo '</p>';
			return;
		}

		if ( $status === GiftCardDeliveryStatus::NOT_REQUESTED || $email === '' ) {
			echo '<p>' . esc_html__( 'Email not sent: no recipient email', 'mp-commerce-promotions' ) . '</p>';
		}
	}

	private function render_list(): void {
		$filters = $this->list_filters_from_request();
		$list    = $this->cards->list_filtered( $filters, 50, 0 );

		$this->render_list_filters();

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
		echo '<th>' . esc_html__( 'Source', 'mp-commerce-promotions' ) . '</th>';
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
			echo '<td>' . esc_html( GiftCardSourceLabel::for_card( $card ) ) . '</td>';
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

		$title = $card->is_store_credit_wallet()
			? sprintf( __( 'Store credit wallet #%d', 'mp-commerce-promotions' ), $gift_card_id )
			: sprintf( __( 'Gift card #%d', 'mp-commerce-promotions' ), $gift_card_id );
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<table class="widefat" style="max-width:640px;"><tbody>';
		$this->detail_row( __( 'Last 4', 'mp-commerce-promotions' ), '****' . $card->get_code_last4() );
		$this->detail_row( __( 'Balance', 'mp-commerce-promotions' ), number_format_i18n( $card->get_balance(), 2 ) . ' ' . $card->get_currency() );
		$this->detail_row( __( 'Initial amount', 'mp-commerce-promotions' ), number_format_i18n( $card->get_initial_amount(), 2 ) );
		$this->detail_row( __( 'Status', 'mp-commerce-promotions' ), $card->get_status() );
		$this->detail_row( __( 'Expires', 'mp-commerce-promotions' ), $card->get_expires_at() ?? '—' );
		$this->detail_row( __( 'Recipient email', 'mp-commerce-promotions' ), $card->get_recipient_email() ?? '—' );
		$delivery_meta = ( new GiftCardManualDeliveryStore() )->get( $gift_card_id );
		if ( $delivery_meta !== null ) {
			$this->detail_row( __( 'Email delivery', 'mp-commerce-promotions' ), $delivery_meta['delivery_status'] );
			if ( isset( $delivery_meta['delivered_to'] ) ) {
				$this->detail_row( __( 'Delivered to', 'mp-commerce-promotions' ), $delivery_meta['delivered_to'] );
			}
			if ( isset( $delivery_meta['delivered_at'] ) ) {
				$this->detail_row( __( 'Delivered at', 'mp-commerce-promotions' ), $delivery_meta['delivered_at'] );
			}
			if ( isset( $delivery_meta['delivery_error'] ) ) {
				$this->detail_row( __( 'Delivery error', 'mp-commerce-promotions' ), $delivery_meta['delivery_error'] );
			}
		}
		$this->detail_row( __( 'UUID', 'mp-commerce-promotions' ), $card->get_gift_card_uuid() );
		$this->detail_row( __( 'Source', 'mp-commerce-promotions' ), GiftCardSourceLabel::for_card( $card ) );
		$transfer_store = new GiftCardTransferStore();
		$replacement_id = $transfer_store->get_replacement_id( $gift_card_id );
		if ( $replacement_id !== null ) {
			$this->detail_row( __( 'Transferred to card', 'mp-commerce-promotions' ), '#' . (string) $replacement_id );
		}
		$from_id = $transfer_store->get_source_id( $gift_card_id );
		if ( $from_id !== null ) {
			$this->detail_row( __( 'Transfer replacement for', 'mp-commerce-promotions' ), '#' . (string) $from_id );
		}
		echo '</tbody></table>';

		if ( $card->get_status() !== GiftCard::STATUS_VOIDED && $this->transfers->can_transfer( $card ) ) {
			echo '<h3>' . esc_html__( 'Reissue to new recipient', 'mp-commerce-promotions' ) . '</h3>';
			echo '<p class="description">' . esc_html__(
				'Voids this card and emails a new code to the recipient. The old code cannot be recovered. Only fully unused cards qualify.',
				'mp-commerce-promotions'
			) . '</p>';
			echo '<form method="post" style="max-width:480px;">';
			wp_nonce_field( self::NONCE_TRANSFER . '_' . $gift_card_id );
			echo '<input type="hidden" name="mp_cp_gift_card_transfer" value="1" />';
			echo '<input type="hidden" name="gift_card_id" value="' . esc_attr( (string) $gift_card_id ) . '" />';
			echo '<p><label>' . esc_html__( 'New recipient email', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<input type="email" name="transfer_recipient_email" class="regular-text" required /></p>';
			echo '<p><label>' . esc_html__( 'Recipient name (optional)', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<input type="text" name="transfer_recipient_name" class="regular-text" /></p>';
			echo '<p><label>' . esc_html__( 'Message (optional)', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<textarea name="transfer_message" class="large-text" rows="2"></textarea></p>';
			echo '<p><label>' . esc_html__( 'Note (required)', 'mp-commerce-promotions' ) . '</label><br />';
			echo '<textarea name="transfer_note" class="large-text" rows="2" required></textarea></p>';
			submit_button( __( 'Reissue to new recipient', 'mp-commerce-promotions' ), 'secondary' );
			echo '</form>';
		}

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

	private function render_list_filters(): void {
		$base = GiftCardModuleSections::section_url( GiftCardModuleSections::SECTION_GIFT_CARDS );

		$origin = isset( $_GET['mp_cp_gc_origin'] ) ? sanitize_key( (string) $_GET['mp_cp_gc_origin'] ) : '';
		$order  = isset( $_GET['mp_cp_gc_order_id'] ) ? (int) $_GET['mp_cp_gc_order_id'] : 0;

		echo '<form method="get" style="margin:1em 0;max-width:720px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminNavigation::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( AdminNavigation::TAB_GIFT_CARDS ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( GiftCardModuleSections::QUERY_ARG ) . '" value="'
			. esc_attr( GiftCardModuleSections::SECTION_GIFT_CARDS ) . '" />';
		echo '<label>' . esc_html__( 'Source', 'mp-commerce-promotions' ) . ' ';
		echo '<select name="mp_cp_gc_origin">';
		echo '<option value="">' . esc_html__( 'All gift cards', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="manual"' . selected( $origin, 'manual', false ) . '>' . esc_html__( 'Manual', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="product_order"' . selected( $origin, 'product_order', false ) . '>' . esc_html__( 'Product order', 'mp-commerce-promotions' ) . '</option>';
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Order ID', 'mp-commerce-promotions' ) . ' ';
		echo '<input type="number" name="mp_cp_gc_order_id" value="' . esc_attr( $order > 0 ? (string) $order : '' ) . '" min="0" class="small-text" /></label> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'mp-commerce-promotions' ) . '</button>';
		echo ' <a class="button" href="' . esc_url( $base ) . '">' . esc_html__( 'Reset', 'mp-commerce-promotions' ) . '</a>';
		echo '</form>';
	}

	/**
	 * @return array{source_type?: ?string, created_order_id?: ?int, manual_only?: bool, product_order_only?: bool}
	 */
	private function list_filters_from_request(): array {
		$filters = array(
			'source_type' => GiftCard::SOURCE_GIFT_CARD,
		);

		$origin = isset( $_GET['mp_cp_gc_origin'] ) ? sanitize_key( (string) $_GET['mp_cp_gc_origin'] ) : '';
		if ( $origin === 'manual' ) {
			$filters['manual_only'] = true;
		} elseif ( $origin === 'product_order' ) {
			$filters['product_order_only'] = true;
		}

		$order_id = isset( $_GET['mp_cp_gc_order_id'] ) ? (int) $_GET['mp_cp_gc_order_id'] : 0;
		if ( $order_id > 0 ) {
			$filters['created_order_id'] = $order_id;
		}

		return $filters;
	}
}
