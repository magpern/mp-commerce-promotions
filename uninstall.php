<?php
/**
 * Uninstall handler for Commerce Promotions for WooCommerce.
 *
 * Data is retained on uninstall in the MVP to prevent accidental loss of
 * promotions, codes, redemptions, and audit history. Future versions may add
 * an explicit administrator setting to delete all plugin data on uninstall.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * MVP policy: no destructive cleanup.
 *
 * Intentionally NOT performed on uninstall:
 * - DROP TABLE for wp_mp_cp_* custom tables
 * - DELETE FROM custom tables
 * - delete_option() for mp_cp_* options (e.g. mp_cp_schema_version, mp_cp_cart_discounts_enabled)
 *
 * When a "delete all data on uninstall" setting is added, gate destructive
 * operations behind that option and document the behavior in readme.txt.
 */
