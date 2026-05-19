<?php
/**
 * QA capability manifest for smoke/QA scripts.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

use MP\CommercePromotions\Qa\QaRuntimeGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Production data hygiene (audit default; --apply needs MP_CP_PRODUCTION_DATA_RESET).
	'production-data-reset'               => array( 'capabilities' => array( QaRuntimeGuard::CAP_READONLY ) ),

	// Read-only evaluator / diagnostics (allowed on production).
	'cheapest-item-smoke'                 => array( 'capabilities' => array( QaRuntimeGuard::CAP_READONLY ) ),
	'commerce-growth-navigation-smoke'    => array( 'capabilities' => array( QaRuntimeGuard::CAP_READONLY ) ),
	'gift-card-storefront-qa-evidence'    => array( 'capabilities' => array( QaRuntimeGuard::CAP_READONLY ) ),
	'gift-card-export-smoke'              => array( 'capabilities' => array( QaRuntimeGuard::CAP_READONLY ) ),
	'blocks-rendering-diagnostic'         => array( 'capabilities' => array( QaRuntimeGuard::CAP_READONLY ) ),
	'qa-runtime-guard-smoke'              => array( 'capabilities' => array( QaRuntimeGuard::CAP_READONLY ) ),

	// Persistent setup (explicit flag on production).
	'gift-card-product-setup'             => array( 'capabilities' => array( QaRuntimeGuard::CAP_SETUP ) ),
	'classic-browser-qa-setup'            => array( 'capabilities' => array( QaRuntimeGuard::CAP_SETUP ) ),

	// Harness / regression load.
	'load-harness'                        => array( 'capabilities' => array( QaRuntimeGuard::CAP_HARNESS, QaRuntimeGuard::CAP_PERSISTENT ) ),
	'regression-suite'                    => array( 'capabilities' => array( QaRuntimeGuard::CAP_HARNESS, QaRuntimeGuard::CAP_PERSISTENT ) ),
	'blocks-qa-runner'                    => array( 'capabilities' => array( QaRuntimeGuard::CAP_HARNESS, QaRuntimeGuard::CAP_PERSISTENT ) ),
	'blocks-browser-cert'                 => array( 'capabilities' => array( QaRuntimeGuard::CAP_HARNESS, QaRuntimeGuard::CAP_PERSISTENT ) ),

	// Email-heavy smokes.
	'gift-card-mail-smoke'                => array( 'capabilities' => array( QaRuntimeGuard::CAP_PERSISTENT, QaRuntimeGuard::CAP_EMAIL ) ),
	'gift-card-scheduled-delivery-smoke'  => array( 'capabilities' => array( QaRuntimeGuard::CAP_PERSISTENT, QaRuntimeGuard::CAP_EMAIL ) ),
	'gift-card-module-smoke'              => array( 'capabilities' => array( QaRuntimeGuard::CAP_PERSISTENT, QaRuntimeGuard::CAP_EMAIL ) ),

	// Default for unlisted *smoke* / *qa* scripts: persistent + cleanup, no email.
);
