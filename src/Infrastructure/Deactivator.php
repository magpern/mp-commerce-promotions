<?php
/**
 * Plugin deactivation: no destructive operations.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure;

use MP\CommercePromotions\Service\PromotionCronScheduler;

final class Deactivator {

	public static function deactivate(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			exit;
		}

		PromotionCronScheduler::clear_scheduled_events();
	}
}
