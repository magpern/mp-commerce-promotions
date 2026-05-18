<?php
/**
 * Beta release preparation smoke checks.
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/beta-release-prep-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file inside WordPress.\n" );
	exit( 1 );
}

use MP\CommercePromotions\Service\CompatibilityStatus;
use MP\CommercePromotions\Service\SupportBundleExporter;

$GLOBALS['mp_cp_beta_prep_ok']   = 0;
$GLOBALS['mp_cp_beta_prep_fail'] = 0;

function mp_cp_prep_assert( bool $condition, string $label ): void {
	if ( $condition ) {
		++$GLOBALS['mp_cp_beta_prep_ok'];
		echo "OK  {$label}\n";
		return;
	}
	++$GLOBALS['mp_cp_beta_prep_fail'];
	echo "FAIL {$label}\n";
}

$root = dirname( __DIR__ );

$schema = get_option( 'mp_cp_schema_version', '' );
mp_cp_prep_assert( $schema === '1.14.0', 'schema version 1.14.0' );

$version = defined( 'MP_COMMERCE_PROMOTIONS_VERSION' ) ? MP_COMMERCE_PROMOTIONS_VERSION : '';
mp_cp_prep_assert( $version === '0.2.0-beta.1', 'plugin version 0.2.0-beta.1' );

$pot = $root . '/languages/mp-commerce-promotions.pot';
mp_cp_prep_assert( is_readable( $pot ) && count( file( $pot ) ) > 100, 'POT file exists' );

$docs = array(
	'docs/BETA_READINESS.md',
	'docs/BROWSER_QA_RUNBOOK.md',
	'docs/CLASSIC_CHECKOUT_CERTIFICATION.md',
	'docs/BLOCK_CHECKOUT_INVESTIGATION.md',
	'docs/RELEASE_EVIDENCE_0.2.0_BETA1.md',
	'docs/VERSION_BUMP_PLAN_0.2.0_BETA1.md',
	'docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md',
);
foreach ( $docs as $doc ) {
	mp_cp_prep_assert( is_readable( $root . '/' . $doc ), $doc );
}

$compat = ( new CompatibilityStatus() )->collect();
mp_cp_prep_assert( ! empty( $compat['cart_checkout_blocks_declared'] ), 'cart_checkout_blocks declared' );
mp_cp_prep_assert( ! empty( $compat['hpos_enabled'] ) || class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ), 'HPOS compatibility path exists' );

mp_cp_prep_assert( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ), 'FeaturesUtil available for HPOS declaration' );

mp_cp_prep_assert( class_exists( SupportBundleExporter::class ), 'SupportBundleExporter class exists' );

mp_cp_prep_assert( is_readable( $root . '/scripts/release-audit.sh' ), 'release-audit.sh' );

$composer = $root . '/composer.json';
if ( is_readable( $composer ) ) {
	$json = json_decode( (string) file_get_contents( $composer ), true );
	mp_cp_prep_assert( isset( $json['scripts']['lint:phpcs'] ), 'composer lint:phpcs defined' );
}

$ok   = (int) ( $GLOBALS['mp_cp_beta_prep_ok'] ?? 0 );
$fail = (int) ( $GLOBALS['mp_cp_beta_prep_fail'] ?? 0 );
echo "\nBeta release prep smoke: {$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
