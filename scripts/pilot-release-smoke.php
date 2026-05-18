<?php
/**
 * WP-CLI smoke: pilot release packaging and Campaign Builder entrypoint.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/pilot-release-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Admin\AdminNavigation;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Infrastructure\Database\MigrationRunner;
use MP\CommercePromotions\Service\CampaignBuilderGoal;

$GLOBALS['pilot_smoke_failures'] = 0;

function pilot_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['pilot_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

$expected_version = '0.3.0-pilot.1';

pilot_smoke_assert(
	defined( 'MP_COMMERCE_PROMOTIONS_VERSION' )
	&& MP_COMMERCE_PROMOTIONS_VERSION === $expected_version,
	'plugin version is ' . $expected_version
);

pilot_smoke_assert( Schema::SCHEMA_VERSION === '1.17.0', 'schema version 1.17.0' );

$stored_schema = get_option( MigrationRunner::OPTION_SCHEMA_VERSION, '' );
pilot_smoke_assert(
	$stored_schema === '' || version_compare( (string) $stored_schema, Schema::SCHEMA_VERSION, '>=' ),
	'database schema at or above target (' . ( $stored_schema === '' ? 'not installed' : $stored_schema ) . ')'
);

pilot_smoke_assert(
	AdminNavigation::DEFAULT_TAB === AdminNavigation::TAB_CAMPAIGN_BUILDER,
	'Campaign Builder is default tab'
);
pilot_smoke_assert(
	in_array( AdminNavigation::TAB_CAMPAIGN_BUILDER, AdminNavigation::allowed_tabs(), true ),
	'Campaign Builder tab registered'
);
pilot_smoke_assert( count( CampaignBuilderGoal::all() ) === 10, 'ten campaign goals' );

$repo_root = dirname( __DIR__ );
$docs      = array(
	'docs/PILOT_RELEASE_0.3.0_PILOT1.md',
	'docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT1.md',
	'docs/CAMPAIGN_BUILDER_QA_EVIDENCE.md',
	'docs/manual-campaign-builder-test.md',
);
foreach ( $docs as $rel ) {
	pilot_smoke_assert( is_readable( $repo_root . '/' . $rel ), 'doc exists: ' . $rel );
}

$build_root = dirname( $repo_root ) . '/build';
$zip_path   = $build_root . '/mp-commerce-promotions-' . $expected_version . '.zip';
if ( is_readable( $zip_path ) ) {
	pilot_smoke_assert( true, 'release zip present: ' . basename( $zip_path ) );
	$zip = new ZipArchive();
	if ( $zip->open( $zip_path ) === true ) {
		$main = 'mp-commerce-promotions/mp-commerce-promotions.php';
		pilot_smoke_assert( $zip->locateName( $main ) !== false, 'zip contains plugin root folder' );
		$bad = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( str_contains( $name, '/vendor/' ) || str_contains( $name, '/.git/' ) ) {
				$bad[] = $name;
			}
		}
		pilot_smoke_assert( $bad === array(), 'zip excludes vendor and .git' );
		$zip->close();
	} else {
		pilot_smoke_assert( false, 'could not open zip for inspection' );
	}
} else {
	WP_CLI::log( 'Note: run bash scripts/build-zip.sh before smoke to verify zip artifact.' );
}

$campaign_smoke = $repo_root . '/scripts/campaign-builder-smoke.php';
pilot_smoke_assert( is_readable( $campaign_smoke ), 'campaign-builder-smoke.php present' );

if ( $GLOBALS['pilot_smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( 'Pilot release smoke finished with %d failure(s).', (int) $GLOBALS['pilot_smoke_failures'] ) );
}

WP_CLI::success( 'Pilot release smoke passed.' );
