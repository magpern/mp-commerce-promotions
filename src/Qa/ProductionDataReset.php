<?php
/**
 * Audit, backup, and remove Commerce Growth test/demo data.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Qa;

use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardTransferStore;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use wpdb;

final class ProductionDataReset {

	private wpdb $wpdb;

	private string $backup_dir;

	private bool $apply;

	/** @var array<string, mixed> */
	private array $report = array();

	public function __construct( wpdb $wpdb, string $backup_dir, bool $apply = false ) {
		$this->wpdb       = $wpdb;
		$this->backup_dir = rtrim( $backup_dir, '/' );
		$this->apply      = $apply;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$this->report = array(
			'generated_at' => gmdate( 'c' ),
			'apply'        => $this->apply,
			'backup_dir'   => $this->backup_dir,
			'audit'        => array(),
			'planned'      => array(),
			'deleted'      => array(),
			'preserved'    => array(),
			'errors'       => array(),
		);

		$this->ensure_backup_dir();
		$plan = $this->build_cleanup_plan();
		$this->report['audit']   = $plan['audit'];
		$this->report['planned'] = $plan['counts'];

		$this->export_tables( $plan['gift_card_ids'] );

		if ( $this->apply ) {
			$this->execute_cleanup( $plan );
		}

		$this->write_report_files();

		return $this->report;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_cleanup_plan(): array {
		$gc_table = Schema::gift_cards_table( $this->wpdb );
		$tx_table = Schema::gift_card_transactions_table( $this->wpdb );
		$pt_table = Schema::promotions_table( $this->wpdb );

		$gift_card_ids = $this->find_test_gift_card_ids( $gc_table );
		$promotion_ids = $this->find_test_promotion_ids( $pt_table );
		$order_ids     = $this->find_test_order_ids( $gift_card_ids );
		$product_ids   = $this->find_test_product_ids();

		$tx_count = 0;
		if ( $gift_card_ids !== array() ) {
			$placeholders = implode( ',', array_fill( 0, count( $gift_card_ids ), '%d' ) );
			$tx_count     = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$tx_table} WHERE gift_card_id IN ({$placeholders})",
					...$gift_card_ids
				)
			);
		}

		$audit = array(
			'tables' => $this->table_row_counts(),
			'gift_cards_total' => (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$gc_table}" ),
			'gift_cards_test'  => count( $gift_card_ids ),
			'transactions_test'=> $tx_count,
			'promotions_total' => (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$pt_table}" ),
			'promotions_test'  => count( $promotion_ids ),
			'orders_to_trash'  => count( $order_ids ),
			'products_to_trash'=> count( $product_ids ),
			'store_credit_wallets_test' => $this->count_test_store_credit( $gc_table, $gift_card_ids ),
		);

		return array(
			'audit'          => $audit,
			'counts'         => array(
				'gift_cards'              => count( $gift_card_ids ),
				'gift_card_transactions'  => $tx_count,
				'promotions'              => count( $promotion_ids ),
				'woocommerce_orders'      => count( $order_ids ),
				'woocommerce_products'    => count( $product_ids ),
			),
			'gift_card_ids'  => $gift_card_ids,
			'promotion_ids'  => $promotion_ids,
			'order_ids'      => $order_ids,
			'product_ids'    => $product_ids,
		);
	}

	/**
	 * @return array<string, int>
	 */
	private function table_row_counts(): array {
		$tables = array(
			'gift_cards',
			'gift_card_transactions',
			'promotions',
			'redemptions',
			'audit_log',
			'promotion_codes',
			'code_batches',
			'promotion_snapshots',
			'automation_runs',
			'planner_telemetry',
			'simulation_scenarios',
			'certification_runs',
		);
		$out = array();
		foreach ( $tables as $slug ) {
			$method = $slug . '_table';
			if ( ! method_exists( Schema::class, $method ) ) {
				continue;
			}
			$table = Schema::$method( $this->wpdb );
			if ( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			$out[ $slug ] = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return $out;
	}

	/**
	 * @return list<int>
	 */
	private function find_test_gift_card_ids( string $gc_table ): array {
		$ids  = array();
		$rows = $this->wpdb->get_results(
			"SELECT id, recipient_email, label, source_type, owner_customer_id, created_order_id FROM {$gc_table}",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$tx_table = Schema::gift_card_transactions_table( $this->wpdb );

		$referenced_from_notes = $this->gift_card_ids_referenced_in_transfer_notes( $tx_table );

		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$reasons = array();
			if ( in_array( $id, $referenced_from_notes, true ) ) {
				$reasons[] = 'transfer_replacement_target';
			}
			if ( ProductionDataClassifier::is_qa_recipient_email( isset( $row['recipient_email'] ) ? (string) $row['recipient_email'] : null ) ) {
				$reasons[] = 'recipient_email';
			}
			if ( ProductionDataClassifier::gift_card_label_is_test( isset( $row['label'] ) ? (string) $row['label'] : null ) ) {
				$reasons[] = 'label';
			}
			$owner = isset( $row['owner_customer_id'] ) ? (int) $row['owner_customer_id'] : 0;
			if ( in_array( $owner, ProductionDataClassifier::QA_CUSTOMER_IDS, true ) ) {
				$reasons[] = 'owner_customer_id';
			}
			$order_id = isset( $row['created_order_id'] ) ? (int) $row['created_order_id'] : 0;
			if ( $order_id > 0 && $this->order_is_test( $order_id ) ) {
				$reasons[] = 'created_order';
			}
			$notes = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT note FROM {$tx_table} WHERE gift_card_id = %d",
					$id
				)
			);
			foreach ( $notes as $note ) {
				if ( ProductionDataClassifier::transaction_note_is_test( is_string( $note ) ? $note : null ) ) {
					$reasons[] = 'transaction_note';
					break;
				}
			}
			if ( $reasons !== array() ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Gift cards voided/replaced during transfer smoke runs (note references prior card id).
	 *
	 * @return list<int>
	 */
	private function gift_card_ids_referenced_in_transfer_notes( string $tx_table ): array {
		$notes = $this->wpdb->get_col(
			"SELECT note FROM {$tx_table} WHERE note IS NOT NULL AND note <> ''"
		);
		if ( ! is_array( $notes ) ) {
			return array();
		}
		$ids = array();
		foreach ( $notes as $note ) {
			if ( ! is_string( $note ) ) {
				continue;
			}
			if ( preg_match_all( '/transfer replacement for gift card #(\d+)/i', $note, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$ids[] = (int) $id;
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private function order_is_test( int $order_id ): bool {
		if ( in_array( $order_id, ProductionDataClassifier::QA_ORDER_IDS, true ) ) {
			return true;
		}
		if ( QaDataTagger::is_tagged_post( $order_id ) ) {
			return true;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ProductionDataClassifier::order_line_item_is_test( (string) $item->get_name() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<int>
	 */
	private function find_test_promotion_ids( string $pt_table ): array {
		$ids   = array();
		$rows  = $this->wpdb->get_results(
			"SELECT id, name, status, internal_notes, campaign_label, dry_run FROM {$pt_table}",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$name   = (string) ( $row['name'] ?? '' );
			$notes  = isset( $row['internal_notes'] ) ? (string) $row['internal_notes'] : null;
			$label  = isset( $row['campaign_label'] ) ? (string) $row['campaign_label'] : null;
			$status = (string) ( $row['status'] ?? '' );
			$dry    = ! empty( $row['dry_run'] );

			$is_test = $dry
				|| ProductionDataClassifier::promotion_name_is_test( $name, $notes )
				|| ProductionDataClassifier::promotion_campaign_label_is_test( $label );

			if ( ! $is_test ) {
				continue;
			}
			if ( $status === 'active' ) {
				continue;
			}
			$ids[] = $id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param list<int> $gift_card_ids
	 * @return list<int>
	 */
	private function find_test_order_ids( array $gift_card_ids ): array {
		$gc_table = Schema::gift_cards_table( $this->wpdb );
		$ids      = array();

		if ( $gift_card_ids !== array() ) {
			$placeholders = implode( ',', array_fill( 0, count( $gift_card_ids ), '%d' ) );
			$from_cards   = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT DISTINCT created_order_id FROM {$gc_table} WHERE id IN ({$placeholders}) AND created_order_id IS NOT NULL AND created_order_id > 0",
					...$gift_card_ids
				)
			);
			foreach ( $from_cards as $oid ) {
				$ids[] = (int) $oid;
			}
		}

		$smoke_order_ids = $this->wpdb->get_col(
			"SELECT DISTINCT oi.order_id FROM {$this->wpdb->prefix}woocommerce_order_items oi
			WHERE oi.order_item_type = 'line_item'
			AND (oi.order_item_name LIKE '%Smoke%' OR oi.order_item_name LIKE '%Commerce Growth Gift Card QA%' OR oi.order_item_name LIKE '%MP CP Blocks QA%')"
		);
		foreach ( $smoke_order_ids as $oid ) {
			$ids[] = (int) $oid;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * @return list<int>
	 */
	private function find_test_product_ids(): array {
		$ids = array();
		foreach ( ProductionDataClassifier::QA_PRODUCT_SKUS as $sku ) {
			if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
				$pid = (int) wc_get_product_id_by_sku( $sku );
				if ( $pid > 0 ) {
					$ids[] = $pid;
				}
			}
		}

		$tagged = $this->wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$this->wpdb->postmeta} WHERE meta_key = '" . esc_sql( QaDataTagger::META_CREATED ) . "' AND meta_value = 'yes'"
		);
		foreach ( $tagged as $pid ) {
			$ids[] = (int) $pid;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * @param list<int> $gift_card_ids
	 */
	private function count_test_store_credit( string $gc_table, array $gift_card_ids ): int {
		if ( $gift_card_ids === array() ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $gift_card_ids ), '%d' ) );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$gc_table} WHERE source_type = 'store_credit' AND id IN ({$placeholders})",
				...$gift_card_ids
			)
		);
	}

	private function ensure_backup_dir(): void {
		if ( ! $this->apply && ! is_dir( $this->backup_dir ) ) {
			wp_mkdir_p( $this->backup_dir );
		}
		if ( $this->apply && ! is_dir( $this->backup_dir ) ) {
			wp_mkdir_p( $this->backup_dir );
		}
	}

	/**
	 * @param list<int> $gift_card_ids
	 */
	private function export_tables( array $gift_card_ids ): void {
		$this->export_full_table( 'gift_cards', Schema::gift_cards_table( $this->wpdb ) );
		$this->export_full_table( 'gift_card_transactions', Schema::gift_card_transactions_table( $this->wpdb ) );

		if ( $gift_card_ids !== array() ) {
			$gc_table = Schema::gift_cards_table( $this->wpdb );
			$placeholders = implode( ',', array_fill( 0, count( $gift_card_ids ), '%d' ) );
			$rows         = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT id, gift_card_uuid, code_last4, initial_amount, balance, currency, status, recipient_email, source_type, owner_customer_id, created_order_id, label, created_at FROM {$gc_table} WHERE id IN ({$placeholders})",
					...$gift_card_ids
				),
				ARRAY_A
			);
			$this->write_json( 'gift_cards_test_subset.json', $rows );
		}

		$options = $this->wpdb->get_results(
			"SELECT option_name, option_value FROM {$this->wpdb->options} WHERE option_name LIKE 'mp_cp_%' OR option_name LIKE '_transient_mp_cp_%' OR option_name LIKE '_transient_timeout_mp_cp_%'",
			ARRAY_A
		);
		$safe_options = array();
		foreach ( $options as $row ) {
			$name = (string) ( $row['option_name'] ?? '' );
			if ( str_contains( $name, 'secret' ) || str_contains( $name, 'password' ) ) {
				continue;
			}
			$safe_options[] = array(
				'option_name'  => $name,
				'option_value' => $row['option_value'] ?? '',
			);
		}
		$this->write_json( 'mp_cp_options_snapshot.json', $safe_options );
	}

	private function export_full_table( string $slug, string $table ): void {
		if ( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$rows = $this->wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		foreach ( $rows as &$row ) {
			if ( is_array( $row ) && isset( $row['code_hash'] ) ) {
				$row['code_hash'] = '[redacted]';
			}
		}
		unset( $row );
		$this->write_json( $slug . '.json', $rows );
		$this->export_csv( $slug . '.csv', $rows );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function export_csv( string $filename, array $rows ): void {
		$path = $this->backup_dir . '/' . $filename;
		$fp   = fopen( $path, 'w' );
		if ( $fp === false ) {
			return;
		}
		if ( $rows !== array() ) {
			fputcsv( $fp, array_keys( $rows[0] ) );
			foreach ( $rows as $row ) {
				fputcsv( $fp, array_values( $row ) );
			}
		}
		fclose( $fp );
	}

	/**
	 * @param mixed $data
	 */
	private function write_json( string $filename, $data ): void {
		$path = $this->backup_dir . '/' . $filename;
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data, JSON_PRETTY_PRINT ) : json_encode( $data, JSON_PRETTY_PRINT );
		if ( is_string( $json ) ) {
			file_put_contents( $path, $json );
		}
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function execute_cleanup( array $plan ): void {
		$deleted = array(
			'gift_card_transactions' => 0,
			'gift_cards'             => 0,
			'promotions'             => 0,
			'redemptions'            => 0,
			'audit_log'              => 0,
			'woocommerce_orders'     => 0,
			'woocommerce_products'   => 0,
			'options'                => 0,
			'transients'             => 0,
			'telemetry_rows'         => 0,
		);

		$gc_ids = $plan['gift_card_ids'];
		$tx_table = Schema::gift_card_transactions_table( $this->wpdb );
		$gc_table = Schema::gift_cards_table( $this->wpdb );

		if ( $gc_ids !== array() ) {
			$placeholders = implode( ',', array_fill( 0, count( $gc_ids ), '%d' ) );
			$deleted['gift_card_transactions'] = (int) $this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM {$tx_table} WHERE gift_card_id IN ({$placeholders})",
					...$gc_ids
				)
			);
			$deleted['gift_cards'] = (int) $this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM {$gc_table} WHERE id IN ({$placeholders})",
					...$gc_ids
				)
			);
		}

		$this->cleanup_transfer_links( $gc_ids );
		$this->cleanup_order_gift_meta( $plan['order_ids'] );

		$promo_ids = $plan['promotion_ids'];
		if ( $promo_ids !== array() ) {
			$deleted = array_merge( $deleted, $this->delete_promotion_graph( $promo_ids ) );
		}

		foreach ( $plan['order_ids'] as $order_id ) {
			if ( $this->trash_order( $order_id ) ) {
				++$deleted['woocommerce_orders'];
			}
		}

		foreach ( $plan['product_ids'] as $product_id ) {
			if ( $this->trash_product( $product_id ) ) {
				++$deleted['woocommerce_products'];
			}
		}

		$deleted['options']    = $this->delete_test_options();
		$deleted['transients'] = $this->delete_report_transients();
		$deleted['telemetry_rows'] = $this->truncate_qa_telemetry_tables();

		$this->report['deleted'] = $deleted;
		$this->report['preserved'] = array(
			'gift_cards_remaining' => (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$gc_table}" ),
			'promotions_active'    => (int) $this->wpdb->get_var(
				'SELECT COUNT(*) FROM ' . Schema::promotions_table( $this->wpdb ) . " WHERE status = 'active'"
			),
			'schema_version'       => get_option( 'mp_cp_schema_version', '' ),
		);
	}

	/**
	 * @param list<int> $gift_card_ids
	 */
	private function cleanup_transfer_links( array $gift_card_ids ): void {
		$raw = get_option( GiftCardTransferStore::OPTION_KEY, array() );
		if ( ! is_array( $raw ) || $raw === array() || $gift_card_ids === array() ) {
			return;
		}
		$id_set = array_fill_keys( $gift_card_ids, true );
		$changed = false;
		foreach ( array( 'by_old', 'by_new' ) as $bucket ) {
			if ( ! isset( $raw[ $bucket ] ) || ! is_array( $raw[ $bucket ] ) ) {
				continue;
			}
			foreach ( $raw[ $bucket ] as $key => $value ) {
				$int_key = (int) $key;
				if ( isset( $id_set[ $int_key ] ) ) {
					unset( $raw[ $bucket ][ $key ] );
					$changed = true;
				}
			}
		}
		if ( $changed ) {
			update_option( GiftCardTransferStore::OPTION_KEY, $raw, false );
		}
	}

	/**
	 * @param list<int> $order_ids
	 */
	private function cleanup_order_gift_meta( array $order_ids ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$order->delete_meta_data( GiftCardGeneratedOrderState::META_GENERATED );
			$order->delete_meta_data( GiftCardGeneratedOrderState::META_GENERATION_COMPLETE );
			$order->delete_meta_data( GiftCardGeneratedOrderState::META_REVERSAL_HANDLED );
			$order->save();
		}
	}

	/**
	 * @param list<int> $promotion_ids
	 * @return array<string, int>
	 */
	private function delete_promotion_graph( array $promotion_ids ): array {
		$counts = array(
			'promotion_codes'  => 0,
			'code_batches'     => 0,
			'promotion_snapshots' => 0,
			'redemptions'      => 0,
			'audit_log'        => 0,
			'planner_telemetry'=> 0,
			'promotions'       => 0,
		);
		if ( $promotion_ids === array() ) {
			return $counts;
		}
		$placeholders = implode( ',', array_fill( 0, count( $promotion_ids ), '%d' ) );

		$tables = array(
			'promotion_codes'     => Schema::promotion_codes_table( $this->wpdb ),
			'code_batches'        => Schema::code_batches_table( $this->wpdb ),
			'promotion_snapshots' => Schema::promotion_snapshots_table( $this->wpdb ),
			'redemptions'         => Schema::redemptions_table( $this->wpdb ),
			'planner_telemetry'   => Schema::planner_telemetry_table( $this->wpdb ),
		);
		foreach ( $tables as $key => $table ) {
			if ( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			$col = $key === 'planner_telemetry' ? 'promotion_id' : 'promotion_id';
			$counts[ $key ] = (int) $this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM {$table} WHERE {$col} IN ({$placeholders})",
					...$promotion_ids
				)
			);
		}

		$audit = Schema::audit_log_table( $this->wpdb );
		$counts['audit_log'] = (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$audit} WHERE promotion_id IN ({$placeholders})",
				...$promotion_ids
			)
		);

		$pt = Schema::promotions_table( $this->wpdb );
		$counts['promotions'] = (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$pt} WHERE id IN ({$placeholders})",
				...$promotion_ids
			)
		);

		return $counts;
	}

	private function trash_order( int $order_id ): bool {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		if ( in_array( $order->get_status(), array( 'trash', 'auto-draft' ), true ) ) {
			return false;
		}
		$order->delete( false );

		return true;
	}

	private function trash_product( int $product_id ): bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}
		if ( $product->get_status() === 'trash' ) {
			return false;
		}
		return (bool) wp_trash_post( $product_id );
	}

	private function delete_test_options(): int {
		$count = 0;
		$names = $this->wpdb->get_col(
			"SELECT option_name FROM {$this->wpdb->options} WHERE option_name LIKE 'mp_cp_%'"
		);
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( ProductionDataClassifier::is_test_only_option( $name ) ) {
				if ( delete_option( $name ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	private function delete_report_transients(): int {
		$count = 0;
		$names = $this->wpdb->get_col(
			"SELECT option_name FROM {$this->wpdb->options} WHERE option_name LIKE '_transient_mp_cp_%' OR option_name LIKE '_transient_timeout_mp_cp_%'"
		);
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( ProductionDataClassifier::is_reports_transient_option( $name ) ) {
				if ( delete_option( $name ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	private function truncate_qa_telemetry_tables(): int {
		$total = 0;
		foreach ( array( 'automation_runs', 'simulation_scenarios' ) as $slug ) {
			$method = $slug . '_table';
			$table  = Schema::$method( $this->wpdb );
			if ( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			$total += (int) $this->wpdb->query( "TRUNCATE TABLE {$table}" );
		}

		return $total;
	}

	private function write_report_files(): void {
		$this->write_json( 'cleanup-report.json', $this->report );
		$md = $this->build_markdown_report();
		file_put_contents( $this->backup_dir . '/cleanup-report.md', $md );
	}

	private function build_markdown_report(): string {
		$lines   = array();
		$lines[] = '# Commerce Growth data cleanup report';
		$lines[] = '';
		$lines[] = '- Generated: ' . (string) ( $this->report['generated_at'] ?? '' );
		$lines[] = '- Mode: ' . ( $this->apply ? 'APPLY (destructive)' : 'AUDIT ONLY (dry-run)' );
		$lines[] = '- Backup: `' . $this->backup_dir . '`';
		$lines[] = '';
		$lines[] = '## Audit';
		foreach ( (array) ( $this->report['audit'] ?? array() ) as $key => $value ) {
			if ( is_array( $value ) ) {
				$lines[] = '### ' . $key;
				foreach ( $value as $k => $v ) {
					$lines[] = '- ' . $k . ': ' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
				}
			} else {
				$lines[] = '- ' . $key . ': ' . (string) $value;
			}
		}
		$lines[] = '';
		$lines[] = '## Planned deletions';
		foreach ( (array) ( $this->report['planned'] ?? array() ) as $key => $value ) {
			$lines[] = '- ' . $key . ': ' . (string) $value;
		}
		if ( ! empty( $this->report['deleted'] ) ) {
			$lines[] = '';
			$lines[] = '## Deleted';
			foreach ( (array) $this->report['deleted'] as $key => $value ) {
				$lines[] = '- ' . $key . ': ' . (string) $value;
			}
		}
		if ( ! empty( $this->report['preserved'] ) ) {
			$lines[] = '';
			$lines[] = '## Preserved after cleanup';
			foreach ( (array) $this->report['preserved'] as $key => $value ) {
				$lines[] = '- ' . $key . ': ' . (string) $value;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}
}
