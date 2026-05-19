<?php
/**
 * Load QA runtime guard for WP-CLI scripts.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

use MP\CommercePromotions\Qa\QaRuntimeGuard;
use MP\CommercePromotions\Qa\QaScriptBootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<string, mixed>
 */
function mp_cp_qa_manifest(): array {
	static $manifest = null;
	if ( $manifest === null ) {
		$path = __DIR__ . '/qa-script-manifest.php';
		$loaded = is_readable( $path ) ? require $path : array();
		$manifest = is_array( $loaded ) ? $loaded : array();
	}

	return $manifest;
}

function mp_cp_qa_bootstrap_script( string $script_file ): \MP\CommercePromotions\Qa\QaScriptContext {
	$script_name = basename( $script_file, '.php' );
	$manifest    = mp_cp_qa_manifest();
	$entry       = $manifest[ $script_name ] ?? array(
		'capabilities' => array(
			QaRuntimeGuard::CAP_PERSISTENT,
		),
	);

	$context = QaScriptBootstrap::bootstrap( $script_file, $entry );
	$GLOBALS['mp_cp_qa'] = $context;

	return $context;
}

function mp_cp_qa_context(): ?\MP\CommercePromotions\Qa\QaScriptContext {
	return QaScriptBootstrap::context();
}
