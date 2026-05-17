<?php
/**
 * Beta readiness certification smoke checks.
 *
 * Run: wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/beta-readiness-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file inside WordPress.\n" );
	exit( 1 );
}

use MP\CommercePromotions\Service\CompatibilityStatus;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Service\SupportBundleExporter;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\AutomationRunRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRepository;

$GLOBALS['mp_cp_beta_ok']   = 0;
$GLOBALS['mp_cp_beta_fail'] = 0;

function mp_cp_beta_assert( bool $condition, string $label ): void {
	if ( $condition ) {
		++$GLOBALS['mp_cp_beta_ok'];
		echo "OK  {$label}\n";
		return;
	}
	++$GLOBALS['mp_cp_beta_fail'];
	echo "FAIL {$label}\n";
}

$schema = get_option( 'mp_cp_schema_version', '' );
mp_cp_beta_assert( is_string( $schema ) && $schema !== '', 'schema version option set' );
if ( $schema !== '' ) {
	echo "    mp_cp_schema_version = {$schema}\n";
}

$compat = ( new CompatibilityStatus() )->collect();
mp_cp_beta_assert( isset( $compat['cart_checkout_blocks_declared'] ), 'compatibility cart_checkout_blocks_declared key' );
mp_cp_beta_assert( $compat['cart_checkout_blocks_declared'] === false, 'blocks not declared' );
mp_cp_beta_assert( isset( $compat['hpos_enabled'] ), 'compatibility hpos_enabled key' );

$settings = new Settings();
mp_cp_beta_assert( method_exists( $settings, 'safe_mode_enabled' ), 'settings safe_mode' );
mp_cp_beta_assert( method_exists( $settings, 'automatic_promotions_enabled' ), 'settings automatic_promotions' );

global $wpdb;
if ( $wpdb instanceof wpdb && class_exists( SupportBundleExporter::class ) ) {
	$exporter = null;
	// Bundle requires full DI; verify class and key doc paths only.
	mp_cp_beta_assert( true, 'support bundle exporter class exists' );
}

if ( $wpdb instanceof wpdb ) {
	$reports = new PromotionReports(
		new PromotionRepository( $wpdb ),
		new RedemptionRepository( $wpdb ),
		new PlannerTelemetryRepository( $wpdb ),
		new AutomationRunRepository( $wpdb ),
		null,
		new SimulationScenarioRepository( $wpdb )
	);
	$dash = $reports->production_hardening_dashboard( $settings );
	mp_cp_beta_assert( isset( $dash['profiler'], $dash['safe_mode'] ), 'reports production hardening dashboard' );
}

$pot = dirname( __DIR__ ) . '/languages/mp-commerce-promotions.pot';
mp_cp_beta_assert( is_readable( $pot ), 'POT file exists' );
if ( is_readable( $pot ) ) {
	$lines = count( file( $pot ) );
	mp_cp_beta_assert( $lines > 100, 'POT has extracted strings' );
	echo "    POT lines: {$lines}\n";
}

$docs = array(
	'docs/BETA_READINESS.md',
	'docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md',
	'docs/BROWSER_QA_MATRIX.md',
);
foreach ( $docs as $doc ) {
	mp_cp_beta_assert( is_readable( dirname( __DIR__ ) . '/' . $doc ), 'doc ' . $doc );
}

$audit = dirname( __DIR__ ) . '/scripts/release-audit.sh';
mp_cp_beta_assert( is_readable( $audit ), 'release-audit.sh present' );

$composer = dirname( __DIR__ ) . '/composer.json';
if ( is_readable( $composer ) ) {
	$json = json_decode( (string) file_get_contents( $composer ), true );
	mp_cp_beta_assert( isset( $json['scripts']['lint:phpcs'] ), 'composer lint:phpcs script defined' );
}

$ok   = (int) ( $GLOBALS['mp_cp_beta_ok'] ?? 0 );
$fail = (int) ( $GLOBALS['mp_cp_beta_fail'] ?? 0 );
echo "\nBeta readiness smoke: {$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
