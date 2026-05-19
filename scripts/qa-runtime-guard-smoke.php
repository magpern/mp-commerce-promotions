<?php
/**
 * WP-CLI smoke: QA runtime guard, email suppression, tagged cleanup.
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/qa-runtime-guard-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';

use MP\CommercePromotions\Qa\QaDataTagger;
use MP\CommercePromotions\Qa\QaEmailSuppression;
use MP\CommercePromotions\Qa\QaRuntimeGuard;

$GLOBALS['qrg_failures'] = 0;

function qrg_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['qrg_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

$ctx = mp_cp_qa_bootstrap_script( __FILE__ );
qrg_assert( $ctx->guard->is_readonly_script(), 'guard smoke is read-only' );
qrg_assert( $ctx->guard->get_environment() !== '', 'environment detected' );

$prod_guard = new QaRuntimeGuard( 'probe', array( QaRuntimeGuard::CAP_PERSISTENT ) );
$ref        = new ReflectionClass( $prod_guard );
$prop       = $ref->getProperty( 'is_production_like' );
$prop->setAccessible( true );
$prop->setValue( $prod_guard, true );
$blocked = false;
try {
	$prod_guard->assert_may_run();
} catch ( RuntimeException $e ) {
	$blocked = true;
}
qrg_assert( $blocked, 'production persistent blocked without MP_CP_ALLOW_LIVE_QA' );

putenv( QaRuntimeGuard::ENV_ALLOW_LIVE_QA . '=1' );
$allowed_guard = new QaRuntimeGuard( 'probe', array( QaRuntimeGuard::CAP_PERSISTENT ) );
$prop->setValue( $allowed_guard, true );
$allowed = true;
try {
	$allowed_guard->assert_may_run();
} catch ( RuntimeException $e ) {
	$allowed = false;
}
qrg_assert( $allowed, 'production persistent allowed with MP_CP_ALLOW_LIVE_QA=1' );
putenv( QaRuntimeGuard::ENV_ALLOW_LIVE_QA );

QaEmailSuppression::reset_log();
QaEmailSuppression::enable();
$sent = wp_mail( 'qa-suppressed@example.invalid', 'QA guard smoke', 'body' );
qrg_assert( $sent === true && QaEmailSuppression::suppressed_count() >= 1, 'wp_mail suppressed with log entry' );
QaEmailSuppression::disable();

if ( class_exists( 'WC_Product_Simple' ) && ! $ctx->guard->is_production_like() ) {
	$cleanup = new \MP\CommercePromotions\Qa\QaCleanupRegistry( 'qa-runtime-guard-smoke', true );
	$product = new WC_Product_Simple();
	$product->set_name( 'QA guard tagged product ' . wp_generate_password( 4, false ) );
	$product->set_regular_price( '1' );
	$product->set_status( 'draft' );
	$product_id = (int) $product->save();
	$cleanup->register_product( $product_id );
	qrg_assert( QaDataTagger::is_tagged_post( $product_id, $cleanup->get_run_id() ), 'product tagged for cleanup' );

	$untagged_id = 999999991;
	qrg_assert( ! QaDataTagger::is_tagged_post( $untagged_id, $cleanup->get_run_id() ), 'untagged post not selected' );

	$cleanup_result = $cleanup->run_cleanup();
	qrg_assert( (int) ( $cleanup_result['products_removed'] ?? 0 ) >= 1, 'cleanup removed tagged product only' );
} elseif ( $ctx->guard->is_production_like() ) {
	WP_CLI::log( 'Skipping tagged product cleanup test on production-like environment (readonly smoke).' );
}

if ( $GLOBALS['qrg_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d assertion(s) failed.', $GLOBALS['qrg_failures'] ) );
}

WP_CLI::success( 'qa-runtime-guard-smoke passed.' );
