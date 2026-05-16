<?php
/**
 * Schema migrations via dbDelta (additive, no destructive DDL).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure\Database;

use wpdb;

final class MigrationRunner {

	public const OPTION_SCHEMA_VERSION = 'mp_cp_schema_version';

	private const REDEMPTIONS_UNIQUE_KEY_NAME = 'order_promotion_unique';

	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function get_current_version(): string {
		$raw = get_option( self::OPTION_SCHEMA_VERSION, '0' );

		if ( ! is_string( $raw ) || $raw === '' ) {
			return '0';
		}

		return $raw;
	}

	public function set_current_version( string $version ): void {
		$sanitized = sanitize_text_field( $version );
		if ( $sanitized === '' ) {
			return;
		}

		update_option( self::OPTION_SCHEMA_VERSION, $sanitized, false );
	}

	public function needs_migration(): bool {
		return version_compare( $this->get_current_version(), Schema::SCHEMA_VERSION, '<' );
	}

	/**
	 * Runs pending migrations: dbDelta for all tables, then bumps option.
	 */
	public function run(): void {
		if ( ! $this->needs_migration() ) {
			return;
		}

		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! is_readable( $upgrade_file ) ) {
			return;
		}

		require_once $upgrade_file;

		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		if ( $this->must_abort_for_redemptions_unique_preflight() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					'[mp-commerce-promotions] Migration blocked: duplicate (order_id, promotion_id) rows exist in mp_cp_redemptions with non-null order_id. Remove duplicates before upgrading to schema 1.1.0.'
				);
			}
			return;
		}

		$statements = array(
			Schema::promotions_create_sql( $this->wpdb ),
			Schema::redemptions_create_sql( $this->wpdb ),
			Schema::audit_log_create_sql( $this->wpdb ),
			Schema::promotion_codes_create_sql( $this->wpdb ),
			Schema::code_batches_create_sql( $this->wpdb ),
		);

		foreach ( $statements as $sql ) {
			if ( ! is_string( $sql ) || $sql === '' ) {
				return;
			}
		}

		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}

		if ( ! $this->tables_exist() ) {
			return;
		}

		if ( ! $this->verify_post_migration_schema() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					'[mp-commerce-promotions] Migration incomplete: expected redemptions unique index "' . self::REDEMPTIONS_UNIQUE_KEY_NAME . '" not found; schema version not updated.'
				);
			}
			return;
		}

		$this->set_current_version( Schema::SCHEMA_VERSION );
	}

	/**
	 * Block dbDelta when upgrading to >= 1.1.0 if duplicate non-null (order_id, promotion_id) pairs exist.
	 */
	private function must_abort_for_redemptions_unique_preflight(): bool {
		if ( version_compare( Schema::SCHEMA_VERSION, '1.1.0', '<' ) ) {
			return false;
		}

		if ( version_compare( $this->get_current_version(), '1.1.0', '>=' ) ) {
			return false;
		}

		if ( ! $this->redemptions_table_exists() ) {
			return false;
		}

		return $this->redemptions_has_duplicate_non_null_order_promotion_pairs();
	}

	private function redemptions_table_exists(): bool {
		$table = Schema::redemptions_table( $this->wpdb );
		if ( ! is_string( $table ) || $table === '' ) {
			return false;
		}

		$sql   = $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table );
		$found = $this->wpdb->get_var( $sql );
		return $found === $table;
	}

	private function redemptions_has_duplicate_non_null_order_promotion_pairs(): bool {
		$table = Schema::redemptions_table( $this->wpdb );
		if ( ! is_string( $table ) || $table === '' || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return false;
		}

		$sql = "SELECT 1 FROM `{$table}` WHERE order_id IS NOT NULL GROUP BY order_id, promotion_id HAVING COUNT(*) > 1 LIMIT 1";

		$found = $this->wpdb->get_var( $sql );
		return $found !== null && $found !== '';
	}

	/**
	 * After dbDelta, ensure invariants for the declared SCHEMA_VERSION hold before bumping the option.
	 */
	private function verify_post_migration_schema(): bool {
		if ( version_compare( Schema::SCHEMA_VERSION, '1.1.0', '>=' ) ) {
			if ( ! $this->redemptions_unique_order_promotion_index_exists() ) {
				return false;
			}
		}

		if ( version_compare( Schema::SCHEMA_VERSION, '1.2.0', '>=' ) ) {
			if ( ! $this->promotion_codes_code_hash_unique_index_exists() ) {
				return false;
			}
		}

		if ( version_compare( Schema::SCHEMA_VERSION, '1.3.0', '>=' ) ) {
			return $this->code_batches_table_exists();
		}

		return true;
	}

	private function code_batches_table_exists(): bool {
		$table = Schema::code_batches_table( $this->wpdb );
		if ( ! is_string( $table ) || $table === '' ) {
			return false;
		}

		$sql   = $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table );
		$found = $this->wpdb->get_var( $sql );
		return $found === $table;
	}

	private function promotion_codes_code_hash_unique_index_exists(): bool {
		$table = Schema::promotion_codes_table( $this->wpdb );
		if ( ! is_string( $table ) || $table === '' || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return false;
		}

		$sql  = "SHOW INDEX FROM `{$table}` WHERE Key_name = 'code_hash'";
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) && count( $rows ) > 0;
	}

	private function redemptions_unique_order_promotion_index_exists(): bool {
		$table = Schema::redemptions_table( $this->wpdb );
		if ( ! is_string( $table ) || $table === '' || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return false;
		}

		$key = self::REDEMPTIONS_UNIQUE_KEY_NAME;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $key ) ) {
			return false;
		}

		$sql  = "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$key}'";
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) && count( $rows ) > 0;
	}

	private function tables_exist(): bool {
		$required = array(
			Schema::promotions_table( $this->wpdb ),
			Schema::redemptions_table( $this->wpdb ),
			Schema::audit_log_table( $this->wpdb ),
		);

		if ( version_compare( Schema::SCHEMA_VERSION, '1.2.0', '>=' ) ) {
			$required[] = Schema::promotion_codes_table( $this->wpdb );
		}

		if ( version_compare( Schema::SCHEMA_VERSION, '1.3.0', '>=' ) ) {
			$required[] = Schema::code_batches_table( $this->wpdb );
		}

		foreach ( $required as $table ) {
			if ( ! is_string( $table ) || $table === '' ) {
				return false;
			}

			$sql   = $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table );
			$found = $this->wpdb->get_var( $sql );
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}
}
