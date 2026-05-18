<?php
/**
 * WP-CLI smoke: default Promotions route matches explicit Campaign Builder tab.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/commerce-growth-navigation-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Admin\AdminNavigation;
use MP\CommercePromotions\Admin\AdminRouter;
use MP\CommercePromotions\Admin\CampaignBuilderPage;

$GLOBALS['nav_smoke_failures'] = 0;

function nav_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['nav_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

$backup_get = $_GET;

nav_smoke_assert(
	AdminNavigation::normalize_tab( null ) === AdminNavigation::TAB_CAMPAIGN_BUILDER,
	'normalize_tab(null) → campaign-builder'
);
nav_smoke_assert(
	AdminNavigation::normalize_tab( '' ) === AdminNavigation::TAB_CAMPAIGN_BUILDER,
	'normalize_tab(empty) → campaign-builder'
);
nav_smoke_assert(
	AdminNavigation::normalize_tab( 'invalid-tab' ) === AdminNavigation::TAB_CAMPAIGN_BUILDER,
	'normalize_tab(invalid) → campaign-builder'
);
nav_smoke_assert(
	AdminNavigation::normalize_tab( AdminNavigation::TAB_CAMPAIGN_BUILDER ) === AdminNavigation::TAB_CAMPAIGN_BUILDER,
	'normalize_tab(campaign-builder) unchanged'
);
nav_smoke_assert(
	AdminNavigation::normalize_tab( AdminNavigation::TAB_ALL ) === AdminNavigation::TAB_ALL,
	'normalize_tab(all) → all'
);

$_GET = array( 'page' => AdminNavigation::PAGE_SLUG );
nav_smoke_assert(
	AdminNavigation::get_current_tab() === AdminNavigation::TAB_CAMPAIGN_BUILDER,
	'get_current_tab() without tab query arg → campaign-builder'
);

$_GET = array(
	'page' => AdminNavigation::PAGE_SLUG,
	'tab'  => AdminNavigation::TAB_CAMPAIGN_BUILDER,
);
nav_smoke_assert(
	AdminNavigation::get_current_tab() === AdminNavigation::TAB_CAMPAIGN_BUILDER,
	'get_current_tab() with tab=campaign-builder → campaign-builder'
);

nav_smoke_assert(
	AdminNavigation::get_current_tab() === AdminNavigation::normalize_tab( AdminNavigation::TAB_CAMPAIGN_BUILDER ),
	'default route and explicit campaign-builder tab normalize equally'
);

foreach (
	array(
		AdminNavigation::TAB_ALL,
		AdminNavigation::TAB_REPORTS,
		AdminNavigation::TAB_DIAGNOSTICS,
		AdminNavigation::TAB_SETTINGS,
		AdminNavigation::TAB_GETTING_STARTED,
	) as $tab
) {
	$_GET = array(
		'page' => AdminNavigation::PAGE_SLUG,
		'tab'  => $tab,
	);
	nav_smoke_assert(
		AdminNavigation::get_current_tab() === $tab,
		'get_current_tab() preserves tab=' . $tab
	);
}

$_GET = array(
	'page'      => AdminNavigation::PAGE_SLUG,
	'tab'       => AdminNavigation::TAB_ALL,
	'promotion' => '1',
);
nav_smoke_assert(
	AdminNavigation::get_current_tab() === AdminNavigation::TAB_ALL,
	'edit URL keeps tab=all when promotion param present'
);

nav_smoke_assert( class_exists( CampaignBuilderPage::class ), 'CampaignBuilderPage class loaded' );
nav_smoke_assert( class_exists( AdminRouter::class ), 'AdminRouter class loaded' );

$source = (string) file_get_contents(
	dirname( __DIR__ ) . '/src/Admin/CampaignBuilderPage.php'
);
nav_smoke_assert(
	str_contains( $source, 'AdminNavigation::get_current_tab()' ),
	'Campaign Builder assets use get_current_tab() not raw $_GET[tab]'
);

$_GET = $backup_get;

if ( $GLOBALS['nav_smoke_failures'] > 0 ) {
	WP_CLI::error(
		sprintf( 'Commerce Growth navigation smoke finished with %d failure(s).', (int) $GLOBALS['nav_smoke_failures'] )
	);
}

WP_CLI::success( 'Commerce Growth navigation smoke passed.' );
