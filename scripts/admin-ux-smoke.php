<?php
/**
 * WP-CLI smoke: promotion status transitions for bulk admin workflows.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/admin-ux-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\AuditLogRepository;
use MP\CommercePromotions\Domain\PromotionFactory;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\PromotionService;

$GLOBALS['smoke_failures'] = 0;

function smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

global $wpdb;

if ( ! class_exists( 'WP_CLI' ) || ! $wpdb instanceof wpdb ) {
	fwrite( STDERR, "WP-CLI and wpdb required.\n" );
	exit( 1 );
}

$repo    = new PromotionRepository( $wpdb );
$audit   = new AuditLogger( new AuditLogRepository( $wpdb ) );
$service = new PromotionService( $repo, new PromotionFactory(), $audit );

$draft = $service->create_draft( 'Admin UX smoke ' . wp_generate_password( 5, false ) );
$pid   = (int) $draft->get_id();
smoke_assert( $pid > 0, 'created draft promotion' );

$active = $service->change_status( $draft, PromotionStatus::ACTIVE );
smoke_assert( $active->get_status() === PromotionStatus::ACTIVE, 'draft -> active' );

$paused = $service->change_status( $active, PromotionStatus::PAUSED );
smoke_assert( $paused->get_status() === PromotionStatus::PAUSED, 'active -> paused' );

$draft2 = $service->create_draft( 'Admin UX invalid ' . wp_generate_password( 4, false ) );
$draft_pause_blocked = false;
try {
	$service->change_status( $draft2, PromotionStatus::PAUSED );
} catch ( RuntimeException $e ) {
	$draft_pause_blocked = true;
}
smoke_assert( $draft_pause_blocked, 'draft -> paused transition rejected' );

$archived = $service->change_status( $paused, PromotionStatus::ARCHIVED );
smoke_assert( $archived->get_status() === PromotionStatus::ARCHIVED, 'paused -> archived' );

$reactivate_blocked = false;
try {
	$service->change_status( $archived, PromotionStatus::ACTIVE );
} catch ( RuntimeException $e ) {
	$reactivate_blocked = true;
}
smoke_assert( $reactivate_blocked, 'archived cannot reactivate' );

smoke_assert(
	PromotionService::is_allowed_status_transition( PromotionStatus::DRAFT, PromotionStatus::ACTIVE ),
	'is_allowed_status_transition public'
);

$same_status_blocked = false;
try {
	$service->change_status( $archived, PromotionStatus::ARCHIVED );
} catch ( RuntimeException $e ) {
	$same_status_blocked = true;
}
smoke_assert( $same_status_blocked, 'same-status transition rejected' );

if ( $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( 'admin-ux-smoke finished with %d failure(s).', (int) $GLOBALS['smoke_failures'] ) );
}

WP_CLI::success( 'admin-ux-smoke completed.' );
