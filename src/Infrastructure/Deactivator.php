<?php
/**
 * Plugin deactivation: no destructive operations.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure;

final class Deactivator {

	public static function deactivate(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			exit;
		}
	}
}
