<?php
/**
 * Uninstall handler for Commerce Promotions for WooCommerce.
 *
 * Data is retained by default. Full deletion runs only when the administrator
 * explicitly enabled "Delete all plugin data on uninstall" before removal.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$autoload = __DIR__ . '/src/autoload.php';
if ( ! is_readable( $autoload ) ) {
	return;
}

require_once $autoload;

use MP\CommercePromotions\Infrastructure\UninstallDataCleaner;
use MP\CommercePromotions\Service\Settings;

$settings = new Settings();

if ( ! $settings->delete_data_on_uninstall() ) {
	return;
}

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	return;
}

UninstallDataCleaner::run( $wpdb );
