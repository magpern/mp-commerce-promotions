<?php
/**
 * Thin $wpdb query helpers for repositories (prepared values, validated table names in SQL).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure\Database;

use wpdb;

final class DbQuery {

	/**
	 * Prepare SQL when placeholders are present.
	 *
	 * Interpolated table/column identifiers in $sql must be validated (see TableName) before building $sql.
	 *
	 * @param mixed ...$args Placeholder values for $wpdb->prepare().
	 */
	public static function prepare( wpdb $wpdb, string $sql, ...$args ): ?string {
		if ( $args === array() ) {
			return $sql;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values use placeholders; identifiers are validated before interpolation.
		$prepared = $wpdb->prepare( $sql, ...$args );

		return is_string( $prepared ) ? $prepared : null;
	}

	/**
	 * @param array<int, mixed> $args
	 * @return array<string, mixed>|null
	 */
	public static function get_row( wpdb $wpdb, string $sql, array $args = array() ): ?array {
		$prepared = self::prepare( $wpdb, $sql, ...$args );
		if ( $prepared === null ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Statement prepared via prepare() above.
		$row = $wpdb->get_row( $prepared, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<int, mixed> $args
	 * @return mixed Scalar result or null.
	 */
	public static function get_var( wpdb $wpdb, string $sql, array $args = array() ) {
		$prepared = self::prepare( $wpdb, $sql, ...$args );
		if ( $prepared === null ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Statement prepared via prepare() above.
		return $wpdb->get_var( $prepared );
	}

	/**
	 * @param array<int, mixed> $args
	 * @return list<array<string, mixed>>
	 */
	public static function get_results( wpdb $wpdb, string $sql, array $args = array() ): array {
		$prepared = self::prepare( $wpdb, $sql, ...$args );
		if ( $prepared === null ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Statement prepared via prepare() above.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * @param array<int, mixed> $args
	 * @return int|false Rows affected, or false on failure.
	 */
	public static function query( wpdb $wpdb, string $sql, array $args = array() ) {
		$prepared = self::prepare( $wpdb, $sql, ...$args );
		if ( $prepared === null ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Statement prepared via prepare() above.
		return $wpdb->query( $prepared );
	}
}
