<?php
/**
 * WP-CLI smoke: campaign metadata and archive hygiene helpers.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/campaign-ops-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Service\PromotionService;

$GLOBALS['smoke_failures'] = 0;

function campaign_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "WP-CLI required.\n";
	exit( 1 );
}

global $wpdb;

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$repo    = new PromotionRepository( $wpdb );
$audit   = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_l = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory = new \MP\CommercePromotions\Domain\PromotionFactory();
$service = new PromotionService( $repo, $factory, $audit_l );

$schema = get_option( 'mp_cp_schema_version', '' );
campaign_smoke_assert( $schema === '1.9.0', 'schema version 1.9.0 (got ' . $schema . ')' );

$created_ids = array();

try {
	$draft = $service->create_draft( 'Smoke Campaign Meta ' . gmdate( 'Y-m-d H:i:s' ) );
	$pid   = (int) $draft->get_id();
	$created_ids[] = $pid;

	$updated = $draft->with_campaign_metadata( 'SmokeCampaign', 'Ops smoke note', '#336699' );
	$service->update_promotion( $updated );

	$reload = $repo->find( $pid );
	campaign_smoke_assert( $reload !== null, 'promotion reloaded after metadata save' );
	campaign_smoke_assert(
		$reload !== null && $reload->get_campaign_label() === 'SmokeCampaign',
		'campaign_label persisted'
	);
	campaign_smoke_assert(
		$reload !== null && $reload->get_admin_color() === '#336699',
		'admin_color persisted'
	);
	campaign_smoke_assert(
		$reload !== null && $reload->get_internal_notes() === 'Ops smoke note',
		'internal_notes persisted'
	);

	$expired = $service->create_draft( 'Smoke Expired Active ' . gmdate( 'Y-m-d H:i:s' ) );
	$exp_id  = (int) $expired->get_id();
	$created_ids[] = $exp_id;

	$past = gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) );
	$exp_active = $expired
		->with_date_window( null, $past )
		->with_rules(
			array( array( 'type' => 'minimum_subtotal', 'amount' => 1 ) ),
			array( array( 'type' => 'percentage_discount', 'percentage' => 5 ) ),
			array()
		);
	$service->update_promotion( $exp_active );
	$service->change_status( $exp_active, PromotionStatus::ACTIVE );

	$result = $service->archive_expired_active_promotions();
	campaign_smoke_assert( count( $result['changed'] ) >= 1, 'archive_expired_active_promotions changed at least one' );

	$exp_reload = $repo->find( $exp_id );
	campaign_smoke_assert(
		$exp_reload !== null && $exp_reload->get_status() === PromotionStatus::ARCHIVED,
		'expired active promotion archived'
	);

	$old_draft = $service->create_draft( 'Smoke Old Draft ' . gmdate( 'Y-m-d H:i:s' ) );
	$old_id    = (int) $old_draft->get_id();
	$created_ids[] = $old_id;

	$draft_result = $service->archive_old_drafts( 0 );
	campaign_smoke_assert( is_array( $draft_result['changed'] ), 'archive_old_drafts returns changed array' );

	$filtered = $repo->find_filtered(
		array(
			'campaign_label' => 'SmokeCampaign',
			'limit'          => 10,
		)
	);
	$found = false;
	foreach ( $filtered as $p ) {
		if ( (int) $p->get_id() === $pid ) {
			$found = true;
			break;
		}
	}
	campaign_smoke_assert( $found, 'find_filtered by campaign_label finds promotion' );

} finally {
	foreach ( $created_ids as $id ) {
		$p = $repo->find( $id );
		if ( $p === null ) {
			continue;
		}
		if ( $p->get_status() !== PromotionStatus::ARCHIVED ) {
			try {
				$service->change_status( $p, PromotionStatus::ARCHIVED );
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}
	}
}

if ( (int) $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( 'Campaign ops smoke finished with ' . (int) $GLOBALS['smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Campaign ops smoke passed.' );
