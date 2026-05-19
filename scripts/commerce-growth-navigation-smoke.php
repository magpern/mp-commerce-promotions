<?php
/**
 * WP-CLI smoke: Commerce Growth admin shell navigation and tab routing.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/commerce-growth-navigation-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Admin\AdminNavigation;
use MP\CommercePromotions\Admin\AdminRouter;
use MP\CommercePromotions\Admin\CampaignBuilderPage;
use MP\CommercePromotions\Admin\GiftCardsPage;

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
	'normalize_tab(all) → all (Advanced Promotions)'
);
nav_smoke_assert(
	AdminNavigation::normalize_tab( AdminNavigation::TAB_GIFT_CARDS ) === AdminNavigation::TAB_GIFT_CARDS,
	'normalize_tab(gift-cards) unchanged'
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
		AdminNavigation::TAB_GIFT_CARDS,
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
nav_smoke_assert( class_exists( GiftCardsPage::class ), 'GiftCardsPage class loaded' );
nav_smoke_assert( class_exists( AdminRouter::class ), 'AdminRouter class loaded' );

$menu_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminMenu.php' );
nav_smoke_assert(
	str_contains( $menu_source, "__( 'Commerce Growth', 'mp-commerce-promotions' )" ),
	'WooCommerce submenu label is Commerce Growth'
);

$nav_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminNavigation.php' );
nav_smoke_assert(
	str_contains( $nav_source, 'TAB_GIFT_CARDS' ) && str_contains( $nav_source, 'Gift Cards & Store Credit' ),
	'nav tabs include Gift Cards & Store Credit'
);
$tabs_block_start = strpos( $nav_source, '$tabs = array(' );
$tabs_block       = $tabs_block_start !== false ? substr( $nav_source, $tabs_block_start, 2500 ) : '';
$gift_in_tabs     = strpos( $tabs_block, 'TAB_GIFT_CARDS' );
$reports_in_tabs  = strpos( $tabs_block, 'TAB_REPORTS' );
nav_smoke_assert(
	$gift_in_tabs !== false && $reports_in_tabs !== false && $gift_in_tabs < $reports_in_tabs,
	'gift-cards tab appears before Reports in nav tab bar'
);

$router_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminRouter.php' );
nav_smoke_assert(
	str_contains( $router_source, 'case AdminNavigation::TAB_GIFT_CARDS:' ),
	'AdminRouter routes tab=gift-cards'
);

$campaign_builder_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/CampaignBuilderPage.php' );
nav_smoke_assert(
	str_contains( $campaign_builder_source, 'AdminNavigation::get_current_tab()' ),
	'Campaign Builder assets use get_current_tab() not raw $_GET[tab]'
);

$edit_url = add_query_arg(
	array(
		'page'      => AdminNavigation::PAGE_SLUG,
		'tab'       => AdminNavigation::TAB_ALL,
		'promotion' => '1',
	),
	admin_url( 'admin.php' )
);
nav_smoke_assert(
	str_contains( $edit_url, 'page=mp-commerce-promotions' ) && str_contains( $edit_url, 'promotion=1' ),
	'edit promotion URL uses mp-commerce-promotions slug and promotion param'
);

$_GET = $backup_get;

if ( $GLOBALS['nav_smoke_failures'] > 0 ) {
	WP_CLI::error(
		sprintf( 'Commerce Growth navigation smoke finished with %d failure(s).', (int) $GLOBALS['nav_smoke_failures'] )
	);
}

WP_CLI::success( 'Commerce Growth navigation smoke passed.' );
