<?php
/**
 * Secure POST CSV exports for gift card ledger backup / reconciliation.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\GiftCard\GiftCardExportTracker;
use MP\CommercePromotions\GiftCard\GiftCardLedgerExporter;
use MP\CommercePromotions\GiftCard\GiftCardReports;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;
use MP\CommercePromotions\Service\AuditLogger;
use wpdb;

final class GiftCardExportHandler {

	public const SUBMIT_GIFT_CARDS = 'mp_cp_export_gift_cards_csv';

	public const SUBMIT_TRANSACTIONS = 'mp_cp_export_gift_card_transactions_csv';

	public const SUBMIT_LIABILITY = 'mp_cp_export_gift_card_liability_csv';

	public const NONCE_GIFT_CARDS = 'mp_cp_export_gift_cards_csv';

	public const NONCE_TRANSACTIONS = 'mp_cp_export_gift_card_transactions_csv';

	public const NONCE_LIABILITY = 'mp_cp_export_gift_card_liability_csv';

	private ?AuditLogger $audit_logger;

	public function __construct( ?AuditLogger $audit_logger = null ) {
		$this->audit_logger = $audit_logger;
	}

	public function maybe_send_export(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST[ self::SUBMIT_GIFT_CARDS ] ) ) {
			$this->send_export(
				self::NONCE_GIFT_CARDS,
				GiftCardExportTracker::TYPE_GIFT_CARDS,
				'mp-cp-gift-cards',
				static function ( GiftCardLedgerExporter $exporter ): string {
					return $exporter->gift_cards_csv();
				}
			);

			return;
		}

		if ( isset( $_POST[ self::SUBMIT_TRANSACTIONS ] ) ) {
			$this->send_export(
				self::NONCE_TRANSACTIONS,
				GiftCardExportTracker::TYPE_TRANSACTIONS,
				'mp-cp-gift-card-transactions',
				static function ( GiftCardLedgerExporter $exporter ): string {
					return $exporter->transactions_csv();
				}
			);

			return;
		}

		if ( isset( $_POST[ self::SUBMIT_LIABILITY ] ) ) {
			$this->send_export(
				self::NONCE_LIABILITY,
				GiftCardExportTracker::TYPE_LIABILITY,
				'mp-cp-gift-card-liability',
				static function ( GiftCardLedgerExporter $exporter ): string {
					return $exporter->liability_summary_csv();
				}
			);
		}
	}

	public function render_export_panel(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$backup_doc = defined( 'MP_COMMERCE_PROMOTIONS_URL' )
			? MP_COMMERCE_PROMOTIONS_URL . 'docs/GIFT_CARD_BACKUP_EXPORT.md'
			: '';

		echo '<div class="mp-cg-gc-export-panel" style="margin:1.5em 0;padding:1em 1.25em;background:#fff;border:1px solid #c3c4c7;border-radius:4px;">';
		echo '<h2 class="title" style="margin-top:0;">' . esc_html__( 'Ledger export (CSV)', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">';
		echo esc_html__(
			'Download stored-value data for audit and reconciliation before pilot sales. Exports never include full gift card codes or code hashes. For disaster recovery, use your normal site and database backups.',
			'mp-commerce-promotions'
		);
		if ( $backup_doc !== '' ) {
			echo ' <a href="' . esc_url( $backup_doc ) . '" target="_blank" rel="noopener noreferrer">';
			echo esc_html__( 'Backup & export guide', 'mp-commerce-promotions' );
			echo '</a>';
		}
		echo '</p>';

		$last = GiftCardExportTracker::last_export_at();
		if ( $last !== null ) {
			echo '<p class="description"><strong>' . esc_html__( 'Last export:', 'mp-commerce-promotions' ) . '</strong> ';
			echo esc_html( $last ) . ' UTC</p>';
		}

		echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;">';
		$this->render_export_button(
			self::SUBMIT_GIFT_CARDS,
			self::NONCE_GIFT_CARDS,
			__( 'Export gift cards', 'mp-commerce-promotions' )
		);
		$this->render_export_button(
			self::SUBMIT_TRANSACTIONS,
			self::NONCE_TRANSACTIONS,
			__( 'Export transactions', 'mp-commerce-promotions' )
		);
		$this->render_export_button(
			self::SUBMIT_LIABILITY,
			self::NONCE_LIABILITY,
			__( 'Export outstanding liability summary', 'mp-commerce-promotions' )
		);
		echo '</div></div>';
	}

	private function render_export_button( string $submit_name, string $nonce_action, string $label ): void {
		echo '<form method="post" style="margin:0;">';
		wp_nonce_field( $nonce_action );
		echo '<button type="submit" class="button" name="' . esc_attr( $submit_name ) . '" value="1">';
		echo esc_html( $label );
		echo '</button></form>';
	}

	/**
	 * @param callable(GiftCardLedgerExporter): string $build_csv
	 */
	private function send_export( string $nonce_action, string $export_type, string $filename_prefix, callable $build_csv ): void {
		if ( ! isset( $_POST['_wpnonce'] ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mp-commerce-promotions' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mp-commerce-promotions' ) );
		}

		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			wp_die( esc_html__( 'Database unavailable.', 'mp-commerce-promotions' ) );
		}

		$exporter = new GiftCardLedgerExporter(
			new GiftCardRepository( $wpdb ),
			new GiftCardTransactionRepository( $wpdb ),
			new GiftCardReports( $wpdb )
		);

		$csv = $build_csv( $exporter );
		self::assert_csv_has_no_secrets( $csv );

		$row_count = max( 0, substr_count( $csv, "\n" ) - 1 );

		GiftCardExportTracker::record_export( $export_type );

		if ( $this->audit_logger !== null ) {
			$this->audit_logger->log(
				'gift_card.export_csv',
				null,
				array(
					'export_type' => $export_type,
					'row_count'   => $row_count,
				)
			);
		}

		$filename = sanitize_file_name( $filename_prefix . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV attachment body.
		echo $csv;
		exit;
	}

	public static function assert_csv_has_no_secrets( string $csv ): void {
		if ( preg_match( '/\bcode_hash\b/i', $csv ) === 1 ) {
			wp_die( esc_html__( 'Export blocked: unexpected sensitive column.', 'mp-commerce-promotions' ) );
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $csv ) ?: array() as $line ) {
			if ( $line === '' || strpos( $line, 'masked_code' ) === 0 || strpos( $line, 'id,' ) === 0 ) {
				continue;
			}
			foreach ( str_getcsv( $line ) as $cell ) {
				if ( GiftCardLedgerExporter::is_forbidden_export_value( (string) $cell ) ) {
					wp_die( esc_html__( 'Export blocked: sensitive value detected.', 'mp-commerce-promotions' ) );
				}
			}
		}
	}
}
