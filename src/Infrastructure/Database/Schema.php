<?php
/**
 * Database table names and dbDelta-compatible DDL (no execution here).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure\Database;

use wpdb;

final class Schema {

	public const SCHEMA_VERSION = '1.7.0';

	/**
	 * Reserved slug prefix for table names (after $wpdb->prefix).
	 */
	public const TABLE_PREFIX_SLUG = 'mp_cp_';

	private function __construct() {
	}

	public static function promotions_table( wpdb $wpdb ): string {
		return $wpdb->prefix . 'mp_cp_promotions';
	}

	public static function redemptions_table( wpdb $wpdb ): string {
		return $wpdb->prefix . 'mp_cp_redemptions';
	}

	public static function audit_log_table( wpdb $wpdb ): string {
		return $wpdb->prefix . 'mp_cp_audit_log';
	}

	public static function promotion_codes_table( wpdb $wpdb ): string {
		return $wpdb->prefix . 'mp_cp_promotion_codes';
	}

	public static function code_batches_table( wpdb $wpdb ): string {
		return $wpdb->prefix . 'mp_cp_code_batches';
	}

	public static function promotions_create_sql( wpdb $wpdb ): string {
		$table   = self::promotions_table( $wpdb );
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL auto_increment,
uuid char(36) NOT NULL,
name varchar(191) NOT NULL,
description text NULL,
status varchar(32) NOT NULL default 'draft',
priority int NOT NULL default 100,
starts_at datetime NULL,
ends_at datetime NULL,
conditions longtext NULL,
actions longtext NULL,
restrictions longtext NULL,
usage_limit int(10) unsigned NULL,
customer_usage_limit int(10) unsigned NULL,
usage_count int(10) unsigned NOT NULL default 0,
application_mode varchar(32) NOT NULL default 'exclusive',
stop_processing tinyint(1) NOT NULL default 1,
max_applications int(10) unsigned NULL,
excluded_promotion_ids longtext NULL,
created_by bigint(20) unsigned NULL,
created_at datetime NOT NULL default CURRENT_TIMESTAMP,
updated_at datetime NOT NULL default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
UNIQUE KEY uuid (uuid),
KEY status (status),
KEY date_window (starts_at, ends_at),
KEY priority (priority)
) {$collate};";
	}

	public static function redemptions_create_sql( wpdb $wpdb ): string {
		$table   = self::redemptions_table( $wpdb );
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL auto_increment,
promotion_id bigint(20) unsigned NOT NULL,
order_id bigint(20) unsigned NULL,
customer_id bigint(20) unsigned NULL,
code varchar(191) NULL,
discount_amount decimal(18,6) NOT NULL default '0.000000',
currency varchar(10) NULL,
status varchar(32) NOT NULL default 'recorded',
redeemed_at datetime NOT NULL default CURRENT_TIMESTAMP,
created_at datetime NOT NULL default CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
KEY promotion_id (promotion_id),
KEY order_id (order_id),
KEY customer_id (customer_id),
KEY status (status),
UNIQUE KEY order_promotion_unique (order_id, promotion_id)
) {$collate};";
	}

	public static function audit_log_create_sql( wpdb $wpdb ): string {
		$table   = self::audit_log_table( $wpdb );
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL auto_increment,
promotion_id bigint(20) unsigned NULL,
actor_user_id bigint(20) unsigned NULL,
`action` varchar(64) NOT NULL,
context longtext NULL,
ip_hash char(64) NULL,
created_at datetime NOT NULL default CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
KEY promotion_id (promotion_id),
KEY actor_user_id (actor_user_id),
KEY action (action),
KEY created_at (created_at)
) {$collate};";
	}

	public static function promotion_codes_create_sql( wpdb $wpdb ): string {
		$table   = self::promotion_codes_table( $wpdb );
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL auto_increment,
promotion_id bigint(20) unsigned NOT NULL,
batch_id bigint(20) unsigned NULL,
code_hash char(64) NOT NULL,
code_last4 varchar(8) NOT NULL,
status varchar(32) NOT NULL default 'active',
usage_limit int(10) unsigned NULL,
usage_count int(10) unsigned NOT NULL default 0,
expires_at datetime NULL,
created_at datetime NOT NULL default CURRENT_TIMESTAMP,
updated_at datetime NOT NULL default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
UNIQUE KEY code_hash (code_hash),
KEY promotion_id (promotion_id),
KEY batch_id (batch_id),
KEY status (status),
KEY expires_at (expires_at)
) {$collate};";
	}

	public static function code_batches_create_sql( wpdb $wpdb ): string {
		$table   = self::code_batches_table( $wpdb );
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL auto_increment,
promotion_id bigint(20) unsigned NOT NULL,
batch_uuid char(36) NOT NULL,
name varchar(191) NOT NULL,
quantity int(10) unsigned NOT NULL,
code_prefix varchar(32) NULL,
usage_limit int(10) unsigned NULL,
expires_at datetime NULL,
created_by bigint(20) unsigned NULL,
created_at datetime NOT NULL default CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
UNIQUE KEY batch_uuid (batch_uuid),
KEY promotion_id (promotion_id),
KEY created_at (created_at)
) {$collate};";
	}
}
