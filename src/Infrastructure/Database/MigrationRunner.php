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

		$statements = array(
			Schema::promotions_create_sql( $this->wpdb ),
			Schema::redemptions_create_sql( $this->wpdb ),
			Schema::audit_log_create_sql( $this->wpdb ),
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

		$this->set_current_version( Schema::SCHEMA_VERSION );
	}

	private function tables_exist(): bool {
		$required = array(
			Schema::promotions_table( $this->wpdb ),
			Schema::redemptions_table( $this->wpdb ),
			Schema::audit_log_table( $this->wpdb ),
		);

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
