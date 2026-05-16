<?php
/**
 * Validates plugin table names before interpolation into SQL.
 *
 * Table names are never passed through $wpdb->prepare() — only values use placeholders.
 * Names must come from Schema::*_table() (or other trusted core tables validated the same way).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure\Database;

use RuntimeException;

final class TableName {

	/**
	 * @throws RuntimeException When the name is empty or contains invalid characters.
	 */
	public static function assert_valid( string $table ): string {
		if ( $table === '' || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			throw new RuntimeException( 'Invalid database table name.' );
		}

		return $table;
	}
}
